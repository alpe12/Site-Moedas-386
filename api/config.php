<?php
declare(strict_types=1);

// ============================================================
// CONFIGURAÇÃO CENTRAL DO SITE
// Único lugar para ajustar estas regras. O PHP usa estas constantes
// diretamente; o navegador aprende os mesmos valores por meio de
// api/config_publica.php, então nunca ficam dessincronizados.
// ============================================================

// --- Regras de senha (0 = sem exigência mínima nessa categoria) ---
const SENHA_MIN_TAMANHO = 8;
const SENHA_MIN_LETRAS = 0;
const SENHA_MIN_NUMEROS = 0;
const SENHA_MIN_ESPECIAIS = 0;

// --- Formato da matrícula (ex.: 202518001323310 — começa com o ano) ---
const MATRICULA_TAMANHO = 15;
const MATRICULA_ANO_MIN = 2000;
// Teto manual para o ano da matrícula. O limite efetivo (MATRICULA_ANO_MAX)
// é o maior entre este valor e (ano atual + 1), calculado em runtime, para
// que matrículas de exercícios futuros sejam aceitas mesmo que o calendário
// mude. Não pode ser `const` puro porque depende de date('Y'), que só existe
// em tempo de execução.
const MATRICULA_ANO_MAX_MANUAL = 2026;
define('MATRICULA_ANO_MAX', max(MATRICULA_ANO_MAX_MANUAL, (int)date('Y') + 1));

// --- Formato da turma (ex.: 0901) ---
const TURMA_TAMANHO = 4;

// --- Contato (usado em mensagens de erro/ajuda no site inteiro) ---
const WHATSAPP_LINK = 'https://wa.me/SEUNUMERO?text=Ol%C3%A1%2C%20preciso%20de%20ajuda%20com%20o%20EcoCoin';

// --- Aprovação manual de contas novas ---
// Toda conta nova sempre nasce com ativo=0 em usuarios.csv, independente
// desta config — assim dá pra saber quais foram confirmadas manualmente.
// Esta config só decide se esse campo chega a ser EXIGIDO:
// true  -> contas com ativo=0 ficam de fora do ranking e não conseguem
//          comprar até alguém trocar para ativo=1 no CSV.
// false -> (padrão) o valor de ativo é ignorado; toda conta se comporta
//          como se estivesse aprovada — igual ao comportamento anterior.
// Fica em config_compartilhada.php (não aqui) porque o painel admin
// isolado também precisa saber este valor — veja aquele arquivo.
require __DIR__ . '/config_compartilhada.php';

// --- Como o nome do aluno aparece em telas públicas ---
// true  -> só a primeira letra + asteriscos (Douglas -> D*******)
// false -> (padrão) só o primeiro nome (Douglas)
const MOSTRAR_APENAS_PRIMEIRA_LETRA = false;

// --- Loja visível sem login ---
// true  -> visitantes não logados também veem os itens (sem poder comprar)
// false -> (padrão) pede login antes de mostrar a loja
const LOJA_VISIVEL_SEM_LOGIN = false;

// --- Trava de escrita concorrente em CSV ---
const LOCK_TIMEOUT_SEGUNDOS = 10;

// --- Duração do login ---
// Depois de LOGIN_DURACAO_SEGUNDOS sem nenhuma requisição autenticada, a
// sessão expira sozinha e o aluno precisa logar de novo — mesmo que o
// navegador continue aberto (é uma janela "deslizante": qualquer uso
// autenticado renova o prazo, não é um limite fixo a partir do login).
// LOGIN_DURACAO_ATIVADA = false volta ao comportamento antigo (a sessão
// dura até o navegador fechar, sem expirar sozinha).
const LOGIN_DURACAO_ATIVADA = true;
const LOGIN_DURACAO_SEGUNDOS = 8 * 3600; // 8 horas

// --- Ranking completo (página ranking.html) ---
const RANKING_LIMITE_PADRAO = 20;
const RANKING_LIMITE_MAXIMO = 100;

// --- Limitar o alcance da busca no ranking ---
// true (padrão) -> a busca só encontra alunos dentro do topo
//                  max(RANKING_BUSCA_LIMITE_N, RANKING_LIMITE_MAXIMO) por
//                  saldo — ou seja, ninguém descobre, digitando letra por
//                  letra, um aluno que não conseguiria ver de outra forma
//                  na página.
// false -> a busca pode encontrar qualquer aluno com saldo > 0 (alcance
//          completo, sem esse limite).
const RANKING_BUSCA_LIMITADA = true;
const RANKING_BUSCA_LIMITE_N = 50;

// --- Busca do ranking feita no navegador, sem ir ao servidor a cada letra ---
// Só faz sentido (e só tem efeito) quando RANKING_BUSCA_LIMITADA também
// está ligado: nesse caso o navegador já buscou de uma vez, no carregamento
// da página, exatamente o mesmo conjunto de alunos (o topo
// max(RANKING_BUSCA_LIMITE_N, RANKING_LIMITE_MAXIMO)) que uma busca no
// servidor enxergaria — ou seja, filtrar essa lista já carregada dá
// exatamente o mesmo resultado, sem gastar uma requisição por letra
// digitada. Padrão true. Com RANKING_BUSCA_LIMITADA desligado isso não
// seria seguro (exigiria carregar a lista inteira de alunos no navegador só
// pra buscar), então esta config é ignorada nesse caso — a busca continua
// indo ao servidor.
const RANKING_BUSCA_LOCAL = true;

// --- Ranking: considerar só o ano letivo atual? ---
// true (padrão) -> saldo/gasto usados no ranking (líderes, mestres das
//                  moedas, soma por turma) consideram só atividades e
//                  pedidos DESTE ano civil — um aluno que só ganhou moedas
//                  em anos anteriores aparece com 0 no ranking deste ano,
//                  mesmo que ainda tenha esse saldo disponível pra gastar
//                  na loja (a menos que EXPIRAR_SALDO_ANO_NOVO também
//                  esteja ligada). Ex.: aluno que foi de uma turma em 2025
//                  pra outra em 2026 some do ranking de 2026 até ganhar
//                  alguma moeda neste ano. Não afeta o saldo real (perfil,
//                  loja) — só a métrica mostrada no ranking.
// false -> ranking usa o saldo acumulado de sempre (comportamento antigo,
//          sem filtrar por ano).
const RANKING_APENAS_ANO_ATUAL = true;

// --- Expirar saldo no início de cada ano letivo? ---
// false (padrão) -> saldo acumula normalmente, sem expiração; o que o
//                   aluno não gastou continua disponível pra sempre.
// true  -> no primeiro cálculo de saldo de um aluno em cada ano novo
//          (perfil, loja, ranking — em qualquer lugar que leia o saldo
//          dele), o que sobrou do(s) ano(s) anterior(es) é descontado por
//          uma linha VIRTUAL "Expirado" — nunca gravada em atividades.csv,
//          sempre recalculada — com valor negativo igual ao saldo que ele
//          tinha, então a soma zera. A partir daí esse valor não pode mais
//          ser usado na loja. Isto afeta o saldo DE VERDADE, diferente de
//          RANKING_APENAS_ANO_ATUAL (que só muda o que é MOSTRADO no
//          ranking, sem tocar no saldo real).
// Fica em config_compartilhada.php (não aqui) porque o painel admin
// isolado também precisa deste valor pra calcular o saldo de um aluno do
// mesmo jeito que o site público (api/financeiro_utils.php) — veja aquele
// arquivo.

// --- Forçar aprovação em casos específicos da primeira troca do ano, MESMO
//     com EXIGIR_APROVACAO_TROCA_TURMA desligada? ---
// Só têm efeito quando EXIGIR_APROVACAO_TROCA_TURMA está desligada — com
// ela ligada, tudo já exige aprovação de qualquer forma. Servem pra deixar
// o auto-aplicar ligado no geral (trocas de turma comuns não incomodam
// ninguém esperando um admin) mas ainda exigir uma conferência humana nos
// casos mais fáceis de errar ou de tentar abusar. Veja "Troca de turma:
// forçando aprovação em casos específicos" no README.
//
// true (padrão) -> na primeira turma de um aluno no ano, se a série
//                  (primeiro dígito) não mudou (ex.: 1010 -> 1006 — pode
//                  ser repetência de ano, pode ser só uma realocação),
//                  exige aprovação mesmo assim, em vez de deixar represada
//                  só a decisão de retroatividade (retroativo_definido).
const EXIGIR_APROVACAO_TROCA_SERIE_REPETIDA = true;

// true (padrão) -> só a PRIMEIRA troca de turma de um aluno em cada ano
//                  (a da matrícula/cadastro, se for o caso, ou a primeira
//                  solicitação do ano) pode auto-aplicar sozinha; qualquer
//                  troca ADICIONAL no mesmo ano sempre exige aprovação,
//                  não importa a combinação de turmas. Sem isso, um aluno
//                  poderia contornar a checagem acima pedindo uma turma
//                  qualquer primeiro (auto-aplicada) e, em seguida, pedir
//                  de volta a turma que na prática queria o tempo todo —
//                  como a segunda pedida não seria mais "a primeira do
//                  ano", passaria batido pela checagem de série repetida.
const TROCA_TURMA_UMA_LIVRE_POR_ANO = true;

// A turma exatamente idêntica à do ano anterior (mesmo valor, ano
// diferente) SEMPRE exige aprovação, independente de qualquer config acima
// — não é uma opção, é regra fixa (turmas normalmente são reconstituídas
// todo ano; um valor idêntico ano a ano é o caso mais sujeito a engano de
// todos, então não faz sentido deixar como "auto-aplicar" nunca).
function config_publica(): array {
    return [
        'senhaMinTamanho' => SENHA_MIN_TAMANHO,
        'senhaMinLetras' => SENHA_MIN_LETRAS,
        'senhaMinNumeros' => SENHA_MIN_NUMEROS,
        'senhaMinEspeciais' => SENHA_MIN_ESPECIAIS,
        'matriculaTamanho' => MATRICULA_TAMANHO,
        'matriculaAnoMin' => MATRICULA_ANO_MIN,
        'matriculaAnoMax' => MATRICULA_ANO_MAX,
        'turmaTamanho' => TURMA_TAMANHO,
        'whatsappLink' => WHATSAPP_LINK,
        'mostrarApenasPrimeiraLetra' => MOSTRAR_APENAS_PRIMEIRA_LETRA,
        'lojaVisivelSemLogin' => LOJA_VISIVEL_SEM_LOGIN,
        'rankingLimitePadrao' => RANKING_LIMITE_PADRAO,
        'rankingLimiteMaximo' => RANKING_LIMITE_MAXIMO,
        'rankingBuscaLimitada' => RANKING_BUSCA_LIMITADA,
        'rankingBuscaLimiteN' => RANKING_BUSCA_LIMITE_N,
        // Só faz sentido de fato quando rankingBuscaLimitada também é true
        // — veja o comentário de RANKING_BUSCA_LOCAL em config.php.
        'rankingBuscaLocal' => RANKING_BUSCA_LOCAL,
        // Vem de config_compartilhada.php — o navegador usa isto só pra
        // decidir a mensagem mostrada depois de uma solicitação de troca de
        // turma em perfil.html (a validação de verdade é sempre no servidor).
        'exigirAprovacaoTrocaTurma' => EXIGIR_APROVACAO_TROCA_TURMA,
    ];
}

/** Valida uma senha contra as regras acima. Devolve null se estiver ok, ou a mensagem de erro. */
function validar_senha(string $senha): ?string {
    if (mb_strlen($senha) < SENHA_MIN_TAMANHO) {
        return "A senha deve ter pelo menos " . SENHA_MIN_TAMANHO . " caracteres.";
    }
    if (SENHA_MIN_LETRAS > 0 && (int)preg_match_all('/\p{L}/u', $senha) < SENHA_MIN_LETRAS) {
        return "A senha deve ter pelo menos " . SENHA_MIN_LETRAS . " letra(s).";
    }
    if (SENHA_MIN_NUMEROS > 0 && (int)preg_match_all('/[0-9]/', $senha) < SENHA_MIN_NUMEROS) {
        return "A senha deve ter pelo menos " . SENHA_MIN_NUMEROS . " número(s).";
    }
    if (SENHA_MIN_ESPECIAIS > 0 && (int)preg_match_all('/[^\p{L}0-9]/u', $senha) < SENHA_MIN_ESPECIAIS) {
        return "A senha deve ter pelo menos " . SENHA_MIN_ESPECIAIS . " caractere(s) especial(is).";
    }
    return null;
}

/** Valida o formato da matrícula (tamanho fixo, começa com um ano plausível). */
function validar_matricula(string $matricula): ?string {
    if (!preg_match('/^\d{' . MATRICULA_TAMANHO . '}$/', $matricula)) {
        return "Matrícula inválida.";
    }
    $ano = (int)substr($matricula, 0, 4);
    if ($ano < MATRICULA_ANO_MIN || $ano > MATRICULA_ANO_MAX) {
        return "Matrícula inválida.";
    }
    return null;
}

/** Valida o formato da turma (tamanho fixo, só números). */
function validar_turma(string $turma): ?string {
    if (!preg_match('/^\d{' . TURMA_TAMANHO . '}$/', $turma)) {
        return "A turma deve ter exatamente " . TURMA_TAMANHO . " números.";
    }
    return null;
}

/** Exige nome e sobrenome (pelo menos duas palavras). */
function validar_nome_completo(string $nome): ?string {
    $palavras = array_filter(preg_split('/\s+/u', trim($nome)) ?: [], fn($p) => $p !== '');
    if (count($palavras) < 2) {
        return "Digite o nome completo (nome e sobrenome).";
    }
    return null;
}

/** Gera um token de recuperação de senha: 6 caracteres A-Z0-9. */
function gerar_token_recuperacao(): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token = '';
    for ($i = 0; $i < 6; $i++) {
        $token .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $token;
}

/** Nome exibido em locais públicos: primeiro nome, ou primeira letra + asteriscos. */
function nome_publico(string $nomeCompleto): string {
    $primeiro = trim(explode(' ', trim($nomeCompleto))[0] ?? '');
    if ($primeiro === '') return '';
    if (!MOSTRAR_APENAS_PRIMEIRA_LETRA) return $primeiro;

    $letras = preg_split('//u', $primeiro, -1, PREG_SPLIT_NO_EMPTY) ?: [$primeiro];
    $resto = count($letras) - 1;
    return $letras[0] . str_repeat('*', max($resto, 0));
}
