<?php
declare(strict_types=1);

// ============================================================
// BOOTSTRAP DO PAINEL ADMINISTRATIVO
// Isolado do site público de propósito: a única coisa que este arquivo
// exige de fora de /admin/ é api/csv_utils.php — uma camada de
// leitura/escrita/trava de CSV sem NENHUM efeito colateral (sem sessão,
// sem cabeçalho HTTP, sem constante de config nenhuma), compartilhada
// porque os dados (os CSVs) também são compartilhados. Fora isso, o
// painel admin não lê nem depende de nenhum outro arquivo do site
// principal — sessão, config, cache, tudo próprio.
// ============================================================

const ADMIN_PRIVATE_DIR = __DIR__ . '/../.private';
const ADMINS_CSV = ADMIN_PRIVATE_DIR . '/admins.csv';
const ADMIN_TOKEN_FILE = ADMIN_PRIVATE_DIR . '/admin_token.txt';
const ADMIN_LOG_CSV = ADMIN_PRIVATE_DIR . '/admin_log.csv';
const ADMIN_RATE_LIMIT_FILE = ADMIN_PRIVATE_DIR . '/admin_rate_limit.json';

const ADMIN_CSV_HEADERS = [
    'admins' => ['email', 'nome', 'senha_hash', 'ativo', 'criado_em'],
    'log' => ['data', 'admin_email', 'acao', 'detalhes'],
];

// Caminhos para os "bancos de dados" do site público que o painel admin
// também precisa ler e (só nestes, nunca em outros arquivos fora de
// /admin/) escrever — atividades, itens da loja, pedidos, conteúdo da
// home, e ler usuários pra busca de alunos.
const SITE_PRIVATE_DIR = __DIR__ . '/../../.private';
const SITE_USERS_CSV = SITE_PRIVATE_DIR . '/usuarios.csv';
const SITE_ACTIVITIES_CSV = SITE_PRIVATE_DIR . '/atividades.csv';
const SITE_ORDERS_CSV = SITE_PRIVATE_DIR . '/pedidos_loja.csv';
const SITE_ITEMS_CSV = SITE_PRIVATE_DIR . '/itens_loja.csv';
const SITE_CARROSSEL_CSV = SITE_PRIVATE_DIR . '/carrossel.csv';
const SITE_EVENTOS_CSV = SITE_PRIVATE_DIR . '/eventos.csv';
const SITE_PROJETOS_CSV = SITE_PRIVATE_DIR . '/projetos.csv';
const SITE_TURMAS_HISTORICO_CSV = SITE_PRIVATE_DIR . '/turmas_historico.csv';

const SITE_CSV_HEADERS = [
    // Não existe mais uma coluna "turma" aqui — desde a troca de turma
    // pelo aluno, a turma (atual e histórico) vive inteiramente em
    // turmas_historico.csv. Veja api/turma_utils.php (site público,
    // compartilhado com o admin abaixo) pra a lógica de "qual é a turma
    // atual"/"qual valia numa data" — atividades.php e pedidos.php usam a
    // segunda pra mostrar a turma que o aluno tinha NA ÉPOCA de cada ação.
    'usuarios' => ['matricula', 'nome', 'email', 'senha_hash', 'reset_token', 'ativo'],
    'atividades' => ['id', 'matriculas', 'data', 'atividade', 'valor'],
    'pedidos' => ['id', 'data', 'matricula', 'nomeAluno', 'item_id', 'item', 'valor', 'status'],
    'itens' => ['id', 'nome', 'valor', 'icone', 'imagem', 'ativo'],
    'carrossel' => ['id', 'imagem', 'legenda', 'ordem', 'ativo'],
    'eventos' => ['id', 'tag', 'titulo', 'descricao', 'rodape', 'link', 'ordem', 'ativo'],
    'projetos' => ['id', 'titulo', 'parceria', 'descricao', 'ordem', 'ativo'],
    'turmas_historico' => ['id', 'matricula', 'turma', 'ano', 'data_solicitacao', 'data_efetiva', 'retroativo_definido', 'aprovacao_forcada', 'aprovado'],
];

require __DIR__ . '/config.php';
require __DIR__ . '/../../api/csv_utils.php';
// O único valor que o site público e o painel admin realmente
// compartilham (fora os dados) — veja o comentário naquele arquivo. Tem
// que vir ANTES de turma_utils.php: aquele arquivo só define
// EXIGIR_APROVACAO_TROCA_TURMA como um fallback defensivo se ainda não
// existir, e a definição de verdade (a que realmente conta) é esta aqui.
require __DIR__ . '/../../api/config_compartilhada.php';
// Funções puras pra interpretar turmas_historico.csv (turma_atual_do_aluno,
// turma_na_data, etc.) — compartilhadas com o site público pelo mesmo
// motivo de csv_utils.php: são os MESMOS dados, então essa lógica não pode
// divergir entre os dois lados.
require __DIR__ . '/../../api/turma_utils.php';
// Funções puras pra calcular ganho/gasto/saldo (calcular_saldo_aluno_de(),
// etc.) — mesmo motivo de turma_utils.php acima: o painel admin precisa
// mostrar exatamente o mesmo saldo que o site público calcula, nunca uma
// versão simplificada que poderia divergir (ex.: ignorando a expiração
// anual de saldo). Usado por admin/api/aluno_detalhe.php.
require __DIR__ . '/../../api/financeiro_utils.php';

ini_set('session.use_strict_mode', '1');

session_name('ecocoin_admin_session');

// Sessões do painel admin ficam na PRÓPRIA pasta privada dele
// (admin/.private/sessoes/), nunca na do site público nem na pasta
// compartilhada padrão do servidor — criada sozinha se ainda não existir.
const ADMIN_SESSIONS_DIR = ADMIN_PRIVATE_DIR . '/sessoes';
if (!is_dir(ADMIN_SESSIONS_DIR)) {
    @mkdir(ADMIN_SESSIONS_DIR, 0700, true);
}
if (is_dir(ADMIN_SESSIONS_DIR) && is_writable(ADMIN_SESSIONS_DIR)) {
    session_save_path(ADMIN_SESSIONS_DIR);
} else {
    error_log('Não foi possível usar ' . ADMIN_SESSIONS_DIR . ' para sessões admin (verifique permissões).');
}

session_cache_limiter('');
session_set_cookie_params([
    // O teto de verdade é controlado dentro da própria sessão
    // (duracao_maxima, que varia conforme "lembrar-me" foi marcado ou
    // não) — isto aqui é só o cookie em si, sem um teto de vida próprio.
    'lifetime' => 0,
    // Restrito a /admin/: o navegador nunca manda este cookie pro resto
    // do site, e o cookie do site público nunca chega aqui.
    'path' => '/admin/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

function json_response(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Mesma lógica de sessão preguiçosa do site público — veja o comentário lá (api/_bootstrap.php). */
function admin_sessao_iniciar_se_necessario(bool $forcar = false): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    if ($forcar) {
        session_start();
        return;
    }

    $idCookie = (string)($_COOKIE[session_name()] ?? '');
    if ($idCookie === '' || !preg_match('/^[a-zA-Z0-9,\-]{22,250}$/', $idCookie)) return;
    if (!is_file(ADMIN_SESSIONS_DIR . '/sess_' . $idCookie)) return;

    session_start();
}

/**
 * Igual ao site público, mas o teto de expiração vem da própria sessão
 * (duracao_maxima) em vez de uma única constante — porque "lembrar-me"
 * marcado no login usa um teto bem maior que o padrão.
 */
function admin_aplicar_expiracao_login(): void {
    if (empty($_SESSION['authenticated'])) return;

    $duracaoMaxima = (int)($_SESSION['duracao_maxima'] ?? ADMIN_LOGIN_DURACAO_SEGUNDOS);
    $ultimaAtividade = (int)($_SESSION['ultima_atividade'] ?? 0);
    if ($ultimaAtividade > 0 && (time() - $ultimaAtividade) > $duracaoMaxima) {
        $_SESSION = [];
        session_destroy();
        return;
    }

    $_SESSION['ultima_atividade'] = time();
}

function admin_require_login(): array {
    admin_sessao_iniciar_se_necessario();
    admin_aplicar_expiracao_login();
    if (empty($_SESSION['authenticated']) || empty($_SESSION['admin_email'])) {
        json_response(['sucesso' => false, 'mensagem' => 'Não autenticado.'], 401);
    }
    return [
        'email' => (string)$_SESSION['admin_email'],
        'nome' => (string)($_SESSION['admin_nome'] ?? ''),
    ];
}

function admin_conta_esta_ativa(array $admin): bool {
    if (!EXIGIR_APROVACAO_ADMIN) return true;
    return trim((string)($admin['ativo'] ?? '')) === '1';
}

/**
 * Lê turmas_historico.csv (do site público) agrupado por matrícula — a
 * mesma lógica de carregar_historico_turmas_agrupado() do lado público, só
 * lendo pelo caminho SITE_TURMAS_HISTORICO_CSV (o painel admin nunca usa as
 * constantes TURMAS_HISTORICO_CSV/USERS_CSV/etc. do site público
 * diretamente — sempre a cópia SITE_* própria, por isolamento).
 */
function carregar_historico_turmas_agrupado_admin(): array {
    return agrupar_historico_turmas(csv_assoc(SITE_TURMAS_HISTORICO_CSV));
}

/** Gera um token aleatório de $tamanho caracteres A-Z0-9. */
function gerar_token_mestre(int $tamanho): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $token .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $token;
}

/**
 * Confere o token mestre fornecido contra o atual (guardado em texto puro
 * em admin/.private/admin_token.txt), sob um lock exclusivo — evita que
 * duas requisições usando o mesmo token válido ao mesmo tempo consigam as
 * duas "gastar" o mesmo token. Se bater, já gera e grava um token NOVO
 * antes de devolver — um token só vence essa checagem uma vez. Se não
 * bater, o token atual continua o mesmo (uma tentativa errada nunca
 * invalida o token válido de verdade).
 */
function usar_token_mestre(string $tokenFornecido): bool {
    $fh = fopen(ADMIN_TOKEN_FILE, 'c+');
    if (!$fh) { error_log('usar_token_mestre: não foi possível abrir ' . ADMIN_TOKEN_FILE); return false; }
    if (!acquire_lock($fh, LOCK_EX)) { fclose($fh); error_log('usar_token_mestre: timeout esperando lock.'); return false; }

    rewind($fh);
    $atual = trim((string)stream_get_contents($fh));
    if ($atual === '') {
        // Primeira vez (arquivo vazio/recém-criado): gera um token novo em vez de falhar.
        $atual = gerar_token_mestre(ADMIN_TOKEN_TAMANHO);
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $atual);
        fflush($fh);
    }

    $bate = hash_equals($atual, trim($tokenFornecido));
    if ($bate) {
        $novo = gerar_token_mestre(ADMIN_TOKEN_TAMANHO);
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $novo);
        fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);
    return $bate;
}

/** Registra uma ação administrativa no log (admin/.private/admin_log.csv). */
function registrar_log_admin(string $adminEmail, string $acao, string $detalhes = ''): void {
    append_csv(ADMIN_LOG_CSV, [date('c'), $adminEmail, $acao, $detalhes], ADMIN_CSV_HEADERS['log']);
}

// Imagens enviadas/baixadas pelo painel ficam em uploads/ na RAIZ do site
// (pública de propósito — precisam ser exibidas pelo site público, então
// não podem ficar em nenhum .private/). Nomeadas pelo hash do conteúdo:
// duas imagens idênticas viram o mesmo arquivo automaticamente (economiza
// espaço) e isso também é o que torna a "contagem de referências" simples
// — um caminho só, comparado direto nos CSVs. Criada na hora se ainda não
// existir, como as pastas de sessão.
const UPLOADS_DIR = __DIR__ . '/../../uploads';
if (!is_dir(UPLOADS_DIR)) {
    @mkdir(UPLOADS_DIR, 0755, true);
}

/**
 * Confere se um caminho de imagem local (ex.: "uploads/abc123.jpg") ainda
 * está referenciado em algum lugar (carrossel ou itens da loja) e, se não
 * estiver mais, apaga o arquivo físico. Só mexe em arquivos dentro de
 * uploads/ — nunca em Imagens/ (tema do site, não gerido por aqui) nem em
 * URLs externas (nada pra apagar localmente nesse caso).
 */
function limpar_imagem_se_orfa(string $caminhoRelativo): void {
    $caminhoRelativo = trim($caminhoRelativo);
    if ($caminhoRelativo === '' || !str_starts_with($caminhoRelativo, 'uploads/')) return;

    foreach (csv_assoc(SITE_CARROSSEL_CSV) as $c) {
        if (trim((string)($c['imagem'] ?? '')) === $caminhoRelativo) return;
    }
    foreach (csv_assoc(SITE_ITEMS_CSV) as $i) {
        if (trim((string)($i['imagem'] ?? '')) === $caminhoRelativo) return;
    }

    $caminhoAbsoluto = UPLOADS_DIR . '/' . basename($caminhoRelativo);
    if (is_file($caminhoAbsoluto)) {
        @unlink($caminhoAbsoluto);
    }
}

/**
 * Monta uma descrição legível do que mudou entre duas versões de uma linha
 * (usado nas ações de "editar", pra o log mostrar de fato o que foi
 * alterado em vez de só repetir os valores novos). Só lista os campos que
 * realmente mudaram.
 */
function construir_diff(array $antigo, array $novo, array $campos): string {
    $partes = [];
    foreach ($campos as $campo) {
        $valorAntigo = (string)($antigo[$campo] ?? '');
        $valorNovo = (string)($novo[$campo] ?? '');
        if ($valorAntigo !== $valorNovo) {
            $partes[] = "$campo: \"$valorAntigo\" → \"$valorNovo\"";
        }
    }
    return $partes ? implode('; ', $partes) : '(nenhum campo mudou)';
}

/**
 * Limitador de tentativas do painel admin — mesma lógica do site público,
 * mas com seu próprio arquivo de controle (admin/.private/admin_rate_limit.json),
 * já que os dois painéis são isolados.
 */
function admin_enforce_rate_limit(string $action, string $target, int $maxAttempts, int $windowSeconds): void {
    $fh = @fopen(ADMIN_RATE_LIMIT_FILE, 'c+');
    if (!$fh) return;
    if (!acquire_lock($fh, LOCK_EX)) { fclose($fh); return; }

    $raw = stream_get_contents($fh);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) $data = [];

    $now = time();
    $key = $action . '|' . $target . '|' . client_ip();
    $recent = array_values(array_filter($data[$key] ?? [], fn($t) => is_int($t) && $t > $now - $windowSeconds));

    $blocked = count($recent) >= $maxAttempts;
    if (!$blocked) {
        $recent[] = $now;
        $data[$key] = $recent;
        foreach ($data as $k => $v) {
            $v = array_values(array_filter((array)$v, fn($t) => is_int($t) && $t > $now - 3600));
            if ($v) { $data[$k] = $v; } else { unset($data[$k]); }
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($data));
        fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);

    if ($blocked) {
        json_response(['sucesso' => false, 'mensagem' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'], 429);
    }
}
