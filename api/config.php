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

// --- CPF e celular para contato no cadastro ---
// Cada um dos dois campos aceita um destes modos:
//   'oculto'      -> não aparece no formulário e não é pedido. A coluna
//                    continua existindo em usuarios.csv, sempre vazia
//                    (mesmo que alguém envie o valor direto à API, ele é
//                    ignorado).
//   'opcional'    -> (padrão) aparece no formulário; pode ficar em branco,
//                    mas se for preenchido tem que ser válido (o CPF passa
//                    também pela conferência dos dígitos verificadores).
//   'obrigatorio' -> aparece e precisa ser preenchido com um valor válido.
// Trocar o modo vale só para cadastros novos — contas que já existem não
// são alteradas nem exigidas a preencher nada.
// Gravação em usuarios.csv: CPF como 000.000.000-00 e celular como
// (00) 90000-0000 — com a pontuação de propósito, para o Excel/Sheets não
// engolir o zero à esquerda de um CPF ao abrir o arquivo.
const CPF_MODO = 'opcional';
const TELEFONE_MODO = 'opcional';

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
        'cpfModo' => cpf_modo(),
        'telefoneModo' => telefone_modo(),
        'dddsValidos' => ddds_validos(),
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

/**
 * Lê o modo de um campo opcional do cadastro ('oculto', 'opcional' ou
 * 'obrigatorio'). Um valor desconhecido (erro de digitação em config.php)
 * cai em 'oculto' — o mais discreto, já que não coleta nada — e deixa um
 * aviso no log de erros do PHP em vez de falhar calado.
 */
function modo_campo_cadastro(string $valor, string $nomeConfig): string {
    static $avisados = [];
    $normalizado = strtr(mb_strtolower(trim($valor)), ['ó' => 'o']);
    if (in_array($normalizado, ['oculto', 'opcional', 'obrigatorio'], true)) return $normalizado;
    if (empty($avisados[$nomeConfig])) {
        $avisados[$nomeConfig] = true;
        error_log("config: $nomeConfig tem um valor inválido (\"$valor\"); use 'oculto', 'opcional' ou 'obrigatorio'. Tratando como 'oculto'.");
    }
    return 'oculto';
}

function cpf_modo(): string { return modo_campo_cadastro(CPF_MODO, 'CPF_MODO'); }
function telefone_modo(): string { return modo_campo_cadastro(TELEFONE_MODO, 'TELEFONE_MODO'); }

/**
 * Aplica o modo de um campo opcional do cadastro ao valor recebido.
 * Devolve [valorParaGravar, erro]: em 'oculto' o valor enviado é
 * ignorado (grava ''); em 'opcional' um valor em branco passa e um
 * preenchido é validado; em 'obrigatorio' o valor em branco também é erro.
 * $validar devolve null se o valor estiver ok, ou a mensagem de erro;
 * $formatar devolve a forma como o valor é gravado.
 */
function resolver_campo_cadastro(string $modo, string $bruto, string $rotulo, callable $validar, callable $formatar): array {
    if ($modo === 'oculto') return ['', null];
    $bruto = trim($bruto);
    if ($bruto === '') {
        return $modo === 'obrigatorio' ? ['', "Informe o $rotulo."] : ['', null];
    }
    if (($erro = $validar($bruto)) !== null) return ['', $erro];
    return [$formatar($bruto), null];
}

/**
 * Versão de resolver_campo_cadastro() para EDITAR um campo que já existe
 * (perfil do aluno, api/conta.php). Devolve [novoValor, erro], onde
 * novoValor é null quando não há nada a mudar:
 *   - modo 'oculto', ou campo que nem veio na requisição ($enviado null);
 *   - valor enviado igual ao que já está gravado — inclusive em branco
 *     sobre em branco, que NÃO é erro nem em modo 'obrigatorio': o modo só
 *     vale para cadastros novos, contas antigas sem o campo não são
 *     obrigadas a preenchê-lo (veja o comentário de CPF_MODO).
 * Fora isso, valem as mesmas regras do cadastro: em 'obrigatorio' não dá
 * para apagar um valor já preenchido; em 'opcional' dá (enviar em branco
 * limpa o campo).
 */
function resolver_edicao_campo(string $modo, mixed $enviado, string $atual, string $rotulo, callable $validar, callable $formatar): array {
    if ($modo === 'oculto' || $enviado === null) return [null, null];
    $bruto = trim((string)$enviado);
    if ($bruto === '' && $atual === '') return [null, null];
    [$valor, $erro] = resolver_campo_cadastro($modo, $bruto, $rotulo, $validar, $formatar);
    if ($erro !== null) return [null, $erro];
    return [$valor === $atual ? null : $valor, null];
}

/** Só os dígitos de um CPF no formato aceito (11 dígitos ou 000.000.000-00). null se o formato não bate. */
function cpf_digitos(string $cpf): ?string {
    $cpf = trim($cpf);
    if (!preg_match('/^(\d{11}|\d{3}\.\d{3}\.\d{3}-\d{2})$/', $cpf)) return null;
    return preg_replace('/\D/', '', $cpf);
}

/**
 * Confere os dois dígitos verificadores (regra do módulo 11) e rejeita
 * sequências repetidas como 111.111.111-11 e o número de teste 012.345.678-90
 * (que passaria na conta, mas é sempre um CPF falso).
 */
function cpf_digitos_verificadores_ok(string $d): bool {
    if (preg_match('/^(\d)\1{10}$/', $d) || $d === '01234567890') return false;
    foreach ([9, 10] as $n) {
        $soma = 0;
        for ($i = 0; $i < $n; $i++) $soma += (int)$d[$i] * ($n + 1 - $i);
        $esperado = ($soma * 10) % 11 % 10;
        if ((int)$d[$n] !== $esperado) return false;
    }
    return true;
}

/** Valida formato + dígitos verificadores do CPF. Devolve null se estiver ok, ou a mensagem de erro. */
function validar_cpf(string $cpf): ?string {
    $d = cpf_digitos($cpf);
    if ($d === null || !cpf_digitos_verificadores_ok($d)) return "CPF inválido.";
    return null;
}

/** CPF (já validado) na forma gravada em usuarios.csv: 000.000.000-00. */
function formatar_cpf(string $cpf): string {
    $d = cpf_digitos($cpf) ?? '';
    return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
}

/** DDDs em uso no Brasil. */
function ddds_validos(): array {
    return [
        11, 12, 13, 14, 15, 16, 17, 18, 19,
        21, 22, 24, 27, 28,
        31, 32, 33, 34, 35, 37, 38,
        41, 42, 43, 44, 45, 46, 47, 48, 49,
        51, 53, 54, 55,
        61, 62, 63, 64, 65, 66, 67, 68, 69,
        71, 73, 74, 75, 77, 79,
        81, 82, 83, 84, 85, 86, 87, 88, 89,
        91, 92, 93, 94, 95, 96, 97, 98, 99,
    ];
}

/**
 * Só os 11 dígitos (DDD + número) de um celular. Aceita pontuação comum
 * — (21) 91234-5678, 21 91234 5678 — e um +55/55 na frente. null se o
 * formato não bate.
 */
function telefone_digitos(string $telefone): ?string {
    $telefone = trim($telefone);
    if (!preg_match('/^\+?[\d\s().\-]+$/', $telefone)) return null;
    $d = preg_replace('/\D/', '', $telefone);
    if (strlen($d) === 13 && str_starts_with($d, '55')) $d = substr($d, 2);
    return strlen($d) === 11 ? $d : null;
}

/** Valida um celular brasileiro (DDD existente + 9 na frente do número). Devolve null se estiver ok, ou a mensagem de erro. */
function validar_telefone(string $telefone): ?string {
    $d = telefone_digitos($telefone);
    if ($d === null) return "Celular inválido. Digite o DDD e o número, ex.: (21) 91234-5678.";
    if (!in_array((int)substr($d, 0, 2), ddds_validos(), true)) return "Celular inválido: DDD inexistente.";
    if ($d[2] !== '9') return "Celular inválido: o número deve começar com 9 depois do DDD.";
    return null;
}

/** Celular (já validado) na forma gravada em usuarios.csv: (00) 90000-0000. */
function formatar_telefone(string $telefone): string {
    $d = telefone_digitos($telefone) ?? '';
    return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7, 4);
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
