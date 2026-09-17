<?php
declare(strict_types=1);

// ============================================================
// UTILITÁRIOS DE HISTÓRICO DE TURMA — SEM EFEITOS COLATERAIS
// Funções puras pra interpretar turmas_historico.csv (uma lista de
// associações aluno/turma/ano ao longo do tempo — um aluno pode ter várias
// linhas, uma por troca de turma, inclusive mais de uma no mesmo ano).
// Compartilhado entre o site público (api/_bootstrap.php) e o painel admin
// isolado (admin/api/_bootstrap.php) pelo mesmo motivo que csv_utils.php:
// os dados são compartilhados, então a lógica de "o que conta como a turma
// atual" não pode divergir entre os dois lados. Depende só de
// EXIGIR_APROVACAO_TROCA_TURMA (config_compartilhada.php) — nada de
// sessão, nada de cache, nada específico de um site só.
//
// Formato de cada linha (turmas_historico.csv):
//   id, matricula, turma, ano, data_solicitacao, data_efetiva, retroativo_definido, aprovacao_forcada, aprovado
// - id: identificador curto e opaco da linha (só referência — não é
//   composto de turma+ano: turma+ano NÃO é único por linha, já que toda
//   uma turma inteira de alunos compartilha o mesmo par turma/ano, e um
//   aluno pode até acabar com duas linhas pro mesmo par depois de uma
//   solicitação recusada e uma nova pedida em seguida; turma e ano ficam
//   em colunas próprias — não por não poderem ser combinados num id, mas
//   porque toda função deste arquivo lê turma/ano isoladamente o tempo
//   todo, e reconstruir os dois a partir de um id toda vez custaria mais
//   código do que duas colunas simples custam espaço).
// - turma / ano: o valor da turma e o ano letivo a que ela se refere.
// - data_solicitacao: data e hora completas (ISO 8601) de quando esta
//   linha foi de fato criada — puramente informativo/auditoria daqui pra
//   frente (mostrado como "solicitada em"). Quem manda na ordem
//   cronológica (qual linha é "a mais recente") é data_efetiva, não esta.
// - data_efetiva: data e hora (ISO 8601) usada pra tudo que importa
//   cronologicamente — decidir qual é "a turma atual" e reconstruir "qual
//   turma valia numa data X" (veja turma_atual_do_aluno()/turma_na_data()).
//   Normalmente igual a data_solicitacao, exceto quando esta troca é a
//   PRIMEIRA turma do aluno no ano (veja eh_primeira_turma_do_ano()) — nesse
//   caso pode ser retroagida pra 1º de janeiro daquele ano, representando
//   que o aluno "já estava" naquela turma desde o início do ano letivo,
//   só demorou pra atualizar o cadastro. Ver retroativo_definido.
// - retroativo_definido: "1" quando não há mais nada a decidir sobre a
//   data_efetiva desta linha (é a grande maioria dos casos — só fica "0"
//   temporariamente numa situação bem específica, ver mais abaixo).
// - aprovacao_forcada: "1" quando esta linha específica exige aprovação de
//   um admin mesmo com EXIGIR_APROVACAO_TROCA_TURMA desligada — decidido
//   uma vez só, na hora da solicitação (api/turma.php), e nunca muda
//   depois. Ver "Forçando aprovação em casos específicos" mais abaixo.
// - aprovado: sempre nasce "0", independente de EXIGIR_APROVACAO_TROCA_TURMA
//   (ou aprovacao_forcada) exigirem aprovação ou não — assim dá pra saber
//   quais trocas ainda não foram conferidas manualmente por um admin,
//   mesmo as que já entraram em vigor sozinhas. A config (e/ou
//   aprovacao_forcada) só decidem se esse campo chega a ser EXIGIDO pra a
//   troca valer (ver turma_registro_esta_aplicado()) — o mesmo modelo do
//   "ativo" em usuarios.csv/EXIGIR_APROVACAO_CONTA. "-1" quer dizer
//   recusada por um admin (nunca conta como aplicada, independente da config).
//
// Retroatividade da primeira turma do ano (data_efetiva vs data_solicitacao):
// quando um aluno pede uma troca que é a PRIMEIRA turma dele no ano corrente
// (veja eh_primeira_turma_do_ano()), comparamos o primeiro dígito da turma
// antiga com o da nova (nesta escola, o primeiro dígito é a série):
//   - Dígito diferente (ex.: 2005 -> 3010): avanço de série normal do ano
//     novo — sempre retroativo a 1º de janeiro, decidido automaticamente,
//     sem precisar de admin nenhum (retroativo_definido já nasce "1").
//   - Dígito igual (ex.: 2005 -> 2007): ambíguo — pode ser repetência de
//     ano (série igual, turma nova) ou uma realocação de verdade no meio
//     do ano. Fica retroativo_definido="0" (data_efetiva = data_solicitacao
//     por padrão, ou seja, NÃO retroativa até decidido) esperando um admin
//     decidir pela revisão no painel — veja admin/api/turmas.php,
//     ação "definir_retroativo".
// Uma troca que NÃO é a primeira do ano (ex.: aluno já trocou de turma uma
// vez este ano e troca de novo) nunca é candidata a retroatividade —
// data_efetiva = data_solicitacao sempre, retroativo_definido="1" de cara.
//
// Forçando aprovação em casos específicos (aprovacao_forcada), mesmo com
// EXIGIR_APROVACAO_TROCA_TURMA desligada — decidido na criação da linha,
// em api/turma.php, nesta ordem (a primeira que bater vale):
//   1. NÃO é a primeira troca do aluno no ano (TROCA_TURMA_UMA_LIVRE_POR_ANO,
//      padrão ligada): só a primeira troca do ano pode auto-aplicar sozinha
//      — qualquer troca adicional no mesmo ano sempre exige aprovação. Sem
//      isso, um aluno poderia pedir uma turma qualquer primeiro (auto-
//      aplicada) e, em seguida, pedir de volta a turma que queria o tempo
//      todo — como a segunda pedida não seria mais "a primeira do ano",
//      passaria batido pela regra 3 abaixo.
//   2. É a primeira do ano E o valor é EXATAMENTE igual ao da turma anterior
//      (mesmo "1010" pro "1010" do ano passado): SEMPRE exige aprovação —
//      regra fixa, não é opção. É o caso mais sujeito a engano de todos.
//   3. É a primeira do ano, valor diferente, mas o primeiro dígito (série)
//      é igual ao anterior (EXIGIR_APROVACAO_TROCA_SERIE_REPETIDA, padrão
//      ligada): mesma situação ambígua descrita acima pra retroatividade —
//      aqui, além de deixar a retroatividade represada, a TROCA em si
//      também fica represada, em vez de auto-aplicar mesmo sem confirmação
//      humana de que é realmente repetência de ano.
// Fora desses três casos (turma nova de verdade, primeiro dígito mudou —
// avanço de série normal), aprovacao_forcada fica "0": a troca segue a
// regra geral de EXIGIR_APROVACAO_TROCA_TURMA como qualquer outra.
// ============================================================

if (!defined('EXIGIR_APROVACAO_TROCA_TURMA')) {
    define('EXIGIR_APROVACAO_TROCA_TURMA', false);
}

/**
 * Um registro conta como aplicado (isto é, elegível pra ser "a turma
 * atual" de alguém) quando: não foi recusado (aprovado=-1 nunca aplica,
 * independente da config) E (nem EXIGIR_APROVACAO_TROCA_TURMA nem o
 * aprovacao_forcada desta linha específica exigem aprovação — toda
 * solicitação já vale na hora, mesmo guardada com aprovado=0 — OU já foi
 * aprovado explicitamente, aprovado=1). aprovacao_forcada é decidido uma
 * vez só, na hora da solicitação (api/turma.php), com base nas regras de
 * EXIGIR_APROVACAO_TROCA_SERIE_REPETIDA / TROCA_TURMA_UMA_LIVRE_POR_ANO /
 * turma idêntica ao ano anterior — sempre as mesmas pra aquela linha,
 * mesmo que a config global mude depois. Com qualquer um dos dois
 * exigindo, um registro aprovado=0 fica represado: a turma anterior
 * continua sendo "a atual" até alguém aprovar ou recusar.
 */
function turma_registro_esta_aplicado(array $registro): bool {
    if (trim((string)($registro['aprovado'] ?? '')) === '-1') return false;
    $exigeAprovacao = EXIGIR_APROVACAO_TROCA_TURMA || trim((string)($registro['aprovacao_forcada'] ?? '')) === '1';
    if (!$exigeAprovacao) return true;
    return trim((string)($registro['aprovado'] ?? '')) === '1';
}

/** data_efetiva de uma linha, com fallback pra data_solicitacao se por algum motivo estiver vazia (não deveria acontecer — defensivo). */
function data_efetiva_de(array $registro): string {
    $efetiva = trim((string)($registro['data_efetiva'] ?? ''));
    return $efetiva !== '' ? $efetiva : (string)($registro['data_solicitacao'] ?? '');
}

/**
 * Agrupa as linhas de turmas_historico.csv por matrícula, cada lista já
 * ordenada da mais antiga pra mais nova (por data_efetiva, não
 * data_solicitacao — veja o comentário no topo do arquivo). Uso: leia
 * o CSV uma vez por requisição e reaproveite este mapa pras funções
 * abaixo, em vez de rechamar csv_assoc() pra cada aluno.
 */
function agrupar_historico_turmas(array $linhasHistorico): array {
    $porAluno = [];
    foreach ($linhasHistorico as $linha) {
        $matricula = trim((string)($linha['matricula'] ?? ''));
        if ($matricula === '') continue;
        $porAluno[$matricula][] = $linha;
    }
    foreach ($porAluno as &$lista) {
        usort($lista, fn($a, $b) => strcmp(data_efetiva_de($a), data_efetiva_de($b)));
    }
    unset($lista);
    return $porAluno;
}

/**
 * A turma "atual" de um aluno: entre os registros já aplicados (veja
 * turma_registro_esta_aplicado()), o de data_efetiva mais recente.
 * Devolve null se o aluno não tem nenhum registro aplicado ainda (nunca
 * deveria acontecer pra uma conta que passou pelo cadastro normal, mas o
 * código trata como "sem turma" em vez de quebrar).
 * $historicoDoAluno já deve vir ordenado (veja agrupar_historico_turmas()).
 */
function turma_atual_do_aluno(array $historicoDoAluno): ?array {
    $aplicados = array_values(array_filter($historicoDoAluno, 'turma_registro_esta_aplicado'));
    if (!$aplicados) return null;
    return $aplicados[count($aplicados) - 1];
}

/**
 * A turma que estava valendo numa data/hora específica (ex.: quando um
 * pedido da loja foi feito ou uma atividade foi lançada) — entre os
 * registros aplicados com data_efetiva <= a data informada, o mais
 * recente. Uma linha retroagida a 1º de janeiro (veja o comentário no topo
 * do arquivo) já entra nessa comparação normalmente — é assim que uma
 * compra feita em fevereiro, antes do aluno ter de fato atualizado o
 * cadastro em abril, ainda mostra a turma nova, se a troca foi marcada
 * como retroativa. Se não houver nenhum registro aplicado antes dessa
 * data (ex.: um pedido feito antes da primeira linha do histórico
 * existir, caso raro de dado incompleto), cai pro registro aplicado mais
 * antigo que existir, como aproximação melhor que não mostrar turma nenhuma.
 */
function turma_na_data(array $historicoDoAluno, string $dataAlvo): ?array {
    $aplicados = array_values(array_filter($historicoDoAluno, 'turma_registro_esta_aplicado'));
    if (!$aplicados) return null;

    $anterior = null;
    foreach ($aplicados as $registro) {
        if (strcmp(data_efetiva_de($registro), $dataAlvo) <= 0) {
            $anterior = $registro;
        } else {
            break;
        }
    }
    return $anterior ?? $aplicados[0];
}

/**
 * O registro mais recente deste aluno que ainda não foi decidido
 * (aprovado="0") — o que perfil.html oferece a opção de cancelar.
 * Diferente de existe_troca_turma_pendente(): aquela ignora linhas já
 * aplicadas (com EXIGIR_APROVACAO_TROCA_TURMA desligada, uma linha
 * aprovado=0 já conta como aplicada e não bloqueia uma nova solicitação);
 * esta aqui olha só pro aprovado bruto, porque o aluno pode querer
 * cancelar uma troca que já está em vigor mas que nenhum admin ainda
 * revisou. Devolve null se não houver nenhuma.
 */
function troca_turma_cancelavel(array $historicoDoAluno): ?array {
    $naoDecididas = array_values(array_filter(
        $historicoDoAluno,
        fn($r) => trim((string)($r['aprovado'] ?? '')) === '0'
    ));
    if (!$naoDecididas) return null;
    usort($naoDecididas, fn($a, $b) => strcmp((string)($a['data_solicitacao'] ?? ''), (string)($b['data_solicitacao'] ?? '')));
    return $naoDecididas[count($naoDecididas) - 1];
}

/**
 * Existe alguma troca de turma deste aluno ainda represada (não aplicada
 * nem recusada)? Com EXIGIR_APROVACAO_TROCA_TURMA desligado isso nunca é
 * verdade (toda linha já nasce aplicada) — só faz sentido com a config
 * ligada. Uma linha recusada (aprovado=-1) já foi decidida — não conta
 * como pendente, mesmo que também não conte como aplicada.
 */
function existe_troca_turma_pendente(array $historicoDoAluno): bool {
    foreach ($historicoDoAluno as $registro) {
        if (trim((string)($registro['aprovado'] ?? '')) === '-1') continue;
        if (!turma_registro_esta_aplicado($registro)) return true;
    }
    return false;
}

/** Ano letivo "atual" — usado pra filtrar o ranking por turma (só turmas do ano corrente contam). */
function ano_letivo_atual(): int {
    return (int)date('Y');
}

/**
 * Primeiro caractere do valor da turma — nesta convenção da escola, é a
 * série do aluno (o resto do valor identifica a turma dentro da série).
 * Usado só pra decidir retroatividade (veja o comentário no topo do
 * arquivo) — não pra validação, que continua em validar_turma().
 */
function primeiro_digito_turma(string $turma): string {
    return substr($turma, 0, 1);
}

/**
 * Esta seria a primeira turma aplicada do aluno no ano informado? Ou seja:
 * antes desta linha, a turma "atual" dele já era de um ano anterior (ou
 * ele não tinha nenhuma turma aplicada ainda — só deveria acontecer pra
 * quem nunca passou pelo cadastro normal). Uma troca posterior no MESMO
 * ano (ex.: aluno já trocou uma vez este ano e troca de novo) nunca é
 * "primeira do ano" — só é candidata a retroatividade a primeira mesmo.
 */
function eh_primeira_turma_do_ano(?array $turmaAtualAntes, int $ano): bool {
    return $turmaAtualAntes === null || (int)($turmaAtualAntes['ano'] ?? 0) < $ano;
}

/**
 * A turma atual do aluno, mas só se ela for do ano informado — senão null.
 * Usado especificamente na visão do PRÓPRIO aluno (perfil.html/api/profile.php):
 * se a turma mais recente dele é de um ano anterior (ele nunca foi
 * realocado pra uma turma deste ano), ele deve ver "sem turma definida
 * este ano" em vez da turma velha, pra ser incentivado a corrigir. Uso
 * administrativo (busca de aluno, turma numa compra/atividade antiga)
 * continua usando turma_atual_do_aluno() puro, sem esse filtro — ali "a
 * última turma conhecida, mesmo desatualizada" ainda é informação útil.
 */
function turma_atual_deste_ano(?array $turmaAtual, int $ano): ?array {
    return ($turmaAtual !== null && (int)($turmaAtual['ano'] ?? 0) === $ano) ? $turmaAtual : null;
}

/** Formata um registro de histórico pro formato devolvido nas APIs (nunca inclui campos internos desnecessários). */
function formatar_registro_turma(?array $registro): ?array {
    if ($registro === null) return null;
    $aprovado = trim((string)($registro['aprovado'] ?? ''));
    $status = match ($aprovado) {
        '1' => 'aprovado',
        '-1' => 'recusado',
        default => 'pendente',
    };
    $dataSolicitacao = (string)($registro['data_solicitacao'] ?? '');
    $dataEfetiva = data_efetiva_de($registro);
    return [
        'id' => (string)($registro['id'] ?? ''),
        'turma' => (string)($registro['turma'] ?? ''),
        'ano' => (int)($registro['ano'] ?? 0),
        'data' => $dataSolicitacao,
        'dataEfetiva' => $dataEfetiva,
        // Só faz sentido diferente de "data" quando foi retroagida a 1º de
        // janeiro — veja o comentário no topo do arquivo.
        'retroativo' => $dataEfetiva !== $dataSolicitacao,
        'retroativoPendente' => trim((string)($registro['retroativo_definido'] ?? '1')) !== '1',
        'aprovacaoForcada' => trim((string)($registro['aprovacao_forcada'] ?? '')) === '1',
        'status' => $status,
    ];
}
