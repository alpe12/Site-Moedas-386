<?php
declare(strict_types=1);

const PRIVATE_DIR = __DIR__ . '/../.private';
const USERS_CSV = PRIVATE_DIR . '/usuarios.csv';
const ACTIVITIES_CSV = PRIVATE_DIR . '/atividades.csv';
const ORDERS_CSV = PRIVATE_DIR . '/pedidos_loja.csv';
const ITEMS_CSV = PRIVATE_DIR . '/itens_loja.csv';
const CARROSSEL_CSV = PRIVATE_DIR . '/carrossel.csv';
const EVENTOS_CSV = PRIVATE_DIR . '/eventos.csv';
const PROJETOS_CSV = PRIVATE_DIR . '/projetos.csv';
const TURMAS_HISTORICO_CSV = PRIVATE_DIR . '/turmas_historico.csv';
const RATE_LIMIT_FILE = PRIVATE_DIR . '/rate_limit.json';

const CSV_HEADERS = [
    // reset_token: 6 caracteres A-Z0-9, gerado no cadastro, guardado em
    // texto puro de propósito — é para um monitor conseguir ler e ajudar o
    // aluno a recuperar a senha, não é segredo criptográfico.
    // ativo: só importa quando EXIGIR_APROVACAO_CONTA está ligado em
    // config.php; veja conta_esta_ativa().
    // Não existe mais uma coluna "turma" aqui — a turma (atual e todo o
    // histórico de trocas) vive inteiramente em turmas_historico.csv desde
    // que a troca de turma pelo aluno foi adicionada; veja o comentário de
    // 'turmas_historico' logo abaixo e api/turma_utils.php. O cadastro cria
    // a primeira linha de lá junto com o usuário (auth.php).
    'usuarios' => ['matricula', 'nome', 'email', 'senha_hash', 'reset_token', 'ativo'],
    // Créditos e ajustes de saldo (valor pode ser negativo). Não existe mais
    // um "saldo" gravado em algum CSV: ganho/gasto/saldo de cada aluno são
    // sempre calculados na hora a partir deste arquivo + pedidos_loja.csv —
    // veja calcular_resumo_financeiro(). Isso evita saldo e histórico
    // ficarem dessincronizados por uma edição manual de um só lado.
    // "id" identifica a linha (usado pelo painel admin pra editar/remover
    // uma atividade específica). "matriculas" aceita mais de uma matrícula
    // na mesma linha, separadas por ";" (nunca vírgula — colidiria com o
    // separador do próprio CSV): uma atividade dada pra turma inteira
    // ("Boas notas", por exemplo) pode ser uma linha só em vez de uma linha
    // por aluno. Veja matriculas_da_linha().
    'atividades' => ['id', 'matriculas', 'data', 'atividade', 'valor'],
    // item_id referencia itens_loja.csv; item/valor ficam "congelados" (uma
    // cópia do nome e do preço no momento da compra), para que o histórico
    // de pedidos não mude se o catálogo for editado depois.
    // status "Cancelado" é o único que não entra na conta de gasto — ou
    // seja, cancelar um pedido "devolve" o valor automaticamente, sem
    // nenhuma transação de estorno separada.
    'pedidos'  => ['id', 'data', 'matricula', 'nomeAluno', 'item_id', 'item', 'valor', 'status'],
    // icone: um emoji/texto curto mostrado se não houver imagem.
    // imagem: URL de uma foto (png/jpg/etc); se preenchida, tem prioridade sobre o ícone.
    // ativo = "1" aparece na loja; "0" fica desativado (não aparece para
    // compra) mas continua no arquivo — necessário para o histórico de
    // pedidos antigos continuar fazendo sentido. O painel admin decide, ao
    // "remover" um item, entre desativar (ativo=0) ou apagar de vez a
    // linha — apagar só é permitido se não houver nenhum pedido referenciando o id.
    'itens'    => ['id', 'nome', 'valor', 'icone', 'imagem', 'ativo'],
    // Conteúdo editorial da página inicial (carrossel, eventos, projetos em
    // destaque), editável pelo painel admin em /admin/conteudo.html.
    // "ordem" controla a posição de exibição (menor primeiro).
    'carrossel' => ['id', 'imagem', 'legenda', 'ordem', 'ativo'],
    'eventos' => ['id', 'tag', 'titulo', 'descricao', 'rodape', 'link', 'ordem', 'ativo'],
    'projetos' => ['id', 'titulo', 'parceria', 'descricao', 'ordem', 'ativo'],
    // Uma linha por associação aluno/turma/ano — um aluno acumula uma linha
    // nova a cada troca (nunca edita/apaga uma linha antiga), pra manter o
    // histórico completo de turmas por que ele já passou. Formato completo
    // (o que cada coluna significa, quando "aprovado" é exigido, etc.) no
    // topo de api/turma_utils.php e na seção "Troca de turma" do README.
    'turmas_historico' => ['id', 'matricula', 'turma', 'ano', 'data_solicitacao', 'data_efetiva', 'retroativo_definido', 'aprovacao_forcada', 'aprovado'],
];

require __DIR__ . '/config.php';
require __DIR__ . '/csv_utils.php';
require __DIR__ . '/turma_utils.php';

// Sem isto, o PHP aceita um ID de sessão que o navegador mandou mesmo que
// esse ID não exista mais no servidor (ex.: logo depois de um logout) — e
// simplesmente cria um arquivo novo, vazio, com aquele mesmo ID de volta.
// É provavelmente por isso que um arquivo de sessão pode parecer "esvaziado
// em vez de apagado": o session_destroy() do logout.php realmente apaga o
// arquivo, mas alguma outra aba/requisição que ainda tinha o cookie antigo
// guardado acaba recriando um arquivo vazio com o mesmo nome logo em
// seguida. "Strict mode" também é uma boa prática de segurança à parte
// (evita ataques de session fixation, onde alguém força a vítima a usar um
// ID de sessão escolhido por ele).
ini_set('session.use_strict_mode', '1');

session_name('ecocoin_session');

// Sessões deste site ficam numa pasta própria dentro de .private/ (que já
// tem .htaccess bloqueando acesso via navegador), em vez da pasta de sessão
// compartilhada padrão do servidor. Isso evita de vez qualquer conflito de
// nome de arquivo com outros sites que usem essa mesma pasta compartilhada
// — este site nem chega a guardar nada lá. A pasta é criada na hora se
// ainda não existir, então não depende de nenhum provisionamento manual: o
// próprio PHP cuida disso no primeiro request.
const SESSIONS_DIR = PRIVATE_DIR . '/sessoes';
if (!is_dir(SESSIONS_DIR)) {
    @mkdir(SESSIONS_DIR, 0700, true);
}
if (is_dir(SESSIONS_DIR) && is_writable(SESSIONS_DIR)) {
    session_save_path(SESSIONS_DIR);
} else {
    error_log('Não foi possível usar ' . SESSIONS_DIR . ' para sessões (verifique permissões de .private/) — usando o local padrão do servidor.');
}

// Por padrão o PHP manda Cache-Control/Expires/Pragma próprios junto do
// cookie de sessão (o "limitador nocache") — inclusive um Expires no
// passado (1981), que polui QUALQUER resposta, mesmo as públicas, e
// convence o navegador a nunca guardar nem revalidar nada (fica sempre
// 200, nunca manda If-None-Match de volta). Desligamos isso aqui porque
// cada endpoint já define seus próprios cabeçalhos de cache explicitamente
// (json_response() manda no-store; responder_com_cache() manda ETag +
// no-cache) — não precisamos que o PHP decida isso por conta própria.
session_cache_limiter('');
session_set_cookie_params([
    // Sem duração configurada (LOGIN_DURACAO_ATIVADA=false), o cookie dura
    // até o navegador fechar (0 = "cookie de sessão"), como antes. Com a
    // duração ligada, o cookie já nasce com esse teto — a checagem de
    // verdade (que renova o prazo a cada uso) é feita no servidor por
    // aplicar_expiracao_login(), isto aqui é só um teto adicional pra não
    // deixar o cookie sobrevivendo no navegador além do que faz sentido.
    'lifetime' => LOGIN_DURACAO_ATIVADA ? LOGIN_DURACAO_SEGUNDOS : 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

/**
 * Só inicia a sessão PHP quando faz sentido — um visitante anônimo nas
 * páginas públicas (ranking, config), ou mesmo alguém checando "estou
 * logado?" em perfil.html sem nunca ter feito login, nunca chega a ganhar
 * um arquivo de sessão no servidor:
 *   - $forcar = true força o início mesmo sem cookie (usado só no momento
 *     em que o login dá certo, quando realmente precisamos criar uma
 *     sessão nova pra esse visitante que talvez nunca tenha tido uma);
 *   - sem $forcar, só retoma uma sessão se o navegador já mandou um cookie
 *     que PAREÇA um ID de sessão válido E o arquivo correspondente já
 *     existir em disco. Isso é de propósito mais rígido do que só checar
 *     `isset($_COOKIE[...])`: chamar session_start() com qualquer cookie
 *     (mesmo um velho/inválido) faz o PHP criar um arquivo novo na hora,
 *     mesmo que a sessão resultante fique vazia — então só vale a pena
 *     chamar session_start() quando já sabemos, sem criar nada, que existe
 *     mesmo uma sessão pra retomar.
 */
function sessao_iniciar_se_necessario(bool $forcar = false): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    if ($forcar) {
        session_start();
        return;
    }

    $idCookie = (string)($_COOKIE[session_name()] ?? '');
    if ($idCookie === '' || !preg_match('/^[a-zA-Z0-9,\-]{22,250}$/', $idCookie)) return;
    if (!is_file(SESSIONS_DIR . '/sess_' . $idCookie)) return;

    session_start();
}

/**
 * Se LOGIN_DURACAO_ATIVADA estiver ligado, confere se já passou tempo
 * demais desde a última requisição autenticada; se sim, encerra a sessão
 * (equivalente a um logout automático). Enquanto a sessão continuar válida,
 * renova o carimbo de "última atividade" — ou seja, é uma janela deslizante:
 * qualquer uso autenticado adia a expiração, não é um prazo fixo contado só
 * a partir do login.
 */
function aplicar_expiracao_login(): void {
    if (empty($_SESSION['authenticated'])) return;

    if (LOGIN_DURACAO_ATIVADA) {
        $ultimaAtividade = (int)($_SESSION['ultima_atividade'] ?? 0);
        if ($ultimaAtividade > 0 && (time() - $ultimaAtividade) > LOGIN_DURACAO_SEGUNDOS) {
            $_SESSION = [];
            session_destroy();
            return;
        }
    }

    $_SESSION['ultima_atividade'] = time();
}

function json_response(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // "private" é sinal pra qualquer cache compartilhado no meio do
    // caminho (ex.: Cloudflare) nunca guardar isto, mesmo que alguma regra
    // de cache tente forçar; "no-store" é o mesmo pedido pro navegador.
    header('Cache-Control: private, no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Serve uma resposta JSON pública e cacheável — pensada pra funcionar bem
 * tanto com o cache do navegador quanto com um CDN/proxy no meio do
 * caminho (ex.: Cloudflare). Usa dois validadores condicionais em
 * sequência, do mais barato pro mais preciso:
 *
 *   1) Last-Modified / If-Modified-Since: comparamos a data de modificação
 *      mais recente entre os arquivos de dados informados E os arquivos de
 *      código envolvidos (config.php, _bootstrap.php, o endpoint chamador)
 *      contra o que o navegador diz já ter. Se nada mudou desde então,
 *      devolvemos 304 SEM sequer chamar $gerarResposta — nem gastamos
 *      tempo montando uma resposta que sabemos que vai ser descartada.
 *   2) Se algum arquivo mudou (ou é a primeira visita), aí sim montamos a
 *      resposta — mas ela pode ainda ser idêntica à que o navegador já tem
 *      (ex.: um arquivo mudou por causa de outro aluno que nem aparece
 *      nesta resposta específica). Por isso calculamos um ETag a partir do
 *      CONTEÚDO já gerado (não mais da data dos arquivos) e comparamos com
 *      o que o navegador mandou — se bater, devolvemos 304 mesmo já tendo
 *      gerado o corpo, pelo menos economizando a banda de mandar de novo.
 *
 * Cache-Control é "public" (pode ser guardada por caches compartilhados,
 * não só pelo navegador de quem pediu) + "no-cache" (que apesar do nome
 * permite guardar, mas exige sempre revalidar com o servidor antes de
 * reusar — nunca serve uma cópia "às cegas" sem checar primeiro) +
 * "max-age=0" (deixa explícito que a resposta já nasce "velha" — alguns
 * navegadores só guardam uma resposta pra revalidação condicional futura
 * se algum indicador explícito de "tempo de vida" estiver presente, mesmo
 * que seja zero).
 *
 * Só use isto para dados verdadeiramente públicos (sem sessão/dado
 * pessoal): a resposta pode ficar guardada em caches compartilhados, então
 * nunca deve variar por usuário.
 */
function responder_com_cache(array $arquivosDeDados, string $arquivoChamador, callable $gerarResposta): never {
    header('Cache-Control: public, no-cache, max-age=0');

    $arquivos = array_merge($arquivosDeDados, [$arquivoChamador, __FILE__, __DIR__ . '/config.php', __DIR__ . '/config_compartilhada.php']);
    $mtimeMaisRecente = 0;
    foreach ($arquivos as $arquivo) {
        if (is_file($arquivo)) $mtimeMaisRecente = max($mtimeMaisRecente, filemtime($arquivo));
    }
    $lastModified = gmdate('D, d M Y H:i:s', $mtimeMaisRecente) . ' GMT';
    header('Last-Modified: ' . $lastModified);

    $seModificadoDesde = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if ($seModificadoDesde !== '') {
        $timestampCliente = strtotime($seModificadoDesde);
        if ($timestampCliente !== false && $timestampCliente >= $mtimeMaisRecente) {
            http_response_code(304);
            exit;
        }
    }

    $corpo = json_encode($gerarResposta(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $etag = substr(hash('sha256', (string)$corpo), 0, 32);
    header('ETag: "' . $etag . '"');

    $etagCliente = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($etagCliente !== '' && str_contains($etagCliente, $etag)) {
        http_response_code(304);
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo $corpo;
    exit;
}

function require_login(): array {
    sessao_iniciar_se_necessario();
    aplicar_expiracao_login();
    if (empty($_SESSION['authenticated']) || empty($_SESSION['matricula'])) {
        json_response(['sucesso' => false, 'mensagem' => 'Não autenticado.'], 401);
    }
    return [
        'matricula' => (string)$_SESSION['matricula'],
        'nome' => (string)($_SESSION['nome'] ?? ''),
    ];
}

function headers_para_arquivo(string $file): array {
    $mapa = [
        USERS_CSV => CSV_HEADERS['usuarios'],
        ACTIVITIES_CSV => CSV_HEADERS['atividades'],
        ORDERS_CSV => CSV_HEADERS['pedidos'],
        ITEMS_CSV => CSV_HEADERS['itens'],
        CARROSSEL_CSV => CSV_HEADERS['carrossel'],
        EVENTOS_CSV => CSV_HEADERS['eventos'],
        PROJETOS_CSV => CSV_HEADERS['projetos'],
        TURMAS_HISTORICO_CSV => CSV_HEADERS['turmas_historico'],
    ];
    return $mapa[$file] ?? [];
}

function conta_esta_ativa(array $usuario): bool {
    if (!EXIGIR_APROVACAO_CONTA) return true;
    return trim((string)($usuario['ativo'] ?? '')) === '1';
}

/** Lê turmas_historico.csv inteiro, já agrupado por matrícula (veja api/turma_utils.php). */
function carregar_historico_turmas_agrupado(): array {
    return agrupar_historico_turmas(csv_assoc(TURMAS_HISTORICO_CSV));
}

/** Extrai o ano (inteiro) de uma data em qualquer formato usado no site (Y-m-d ou ISO 8601 completo). */
function ano_da_data(string $data): int {
    $timestamp = strtotime($data);
    return $timestamp !== false ? (int)date('Y', $timestamp) : ano_letivo_atual();
}

/**
 * Dado ganho/gasto já agrupados por ano (ano => ['ganho'=>float,'gasto'=>float]),
 * aplica a expiração anual se EXPIRAR_SALDO_ANO_NOVO estiver ligada e devolve
 * o total final. Não lê nenhum arquivo — quem chama já precisa ter montado
 * $porAno. Isso permite reaproveitar a mesma regra de expiração tanto em
 * calcular_saldo_aluno() (que lê os CSVs direto) quanto dentro do lock de
 * compra em api/loja.php, onde reler pedidos_loja.csv de novo faria o
 * processo esperar por um lock que ele mesmo já está segurando (trava até
 * dar timeout) — ali o chamador já tem os dados em mãos e só passa pra cá.
 *
 * Sem EXPIRAR_SALDO_ANO_NOVO: soma tudo, sem distinguir ano (comportamento
 * de sempre). Com a flag ligada: percorre os anos em ordem; sempre que o
 * saldo levado de um ano pro próximo é positivo, uma linha virtual
 * "Expirado" (nunca gravada em CSV nenhum) desconta esse valor antes de
 * somar o ano seguinte — na prática, zera o que não foi gasto.
 */
function aplicar_expiracao_anual(array $porAno): array {
    if (!$porAno) return ['ganho' => 0.0, 'gasto' => 0.0, 'saldo' => 0.0, 'expiracoes' => []];
    ksort($porAno);

    if (!EXPIRAR_SALDO_ANO_NOVO) {
        $ganho = array_sum(array_map(fn($v) => $v['ganho'] ?? 0.0, $porAno));
        $gasto = array_sum(array_map(fn($v) => $v['gasto'] ?? 0.0, $porAno));
        return ['ganho' => $ganho, 'gasto' => $gasto, 'saldo' => $ganho - $gasto, 'expiracoes' => []];
    }

    $saldoCarregado = 0.0;
    $ganhoTotal = 0.0;
    $gastoTotal = 0.0;
    $expiracoes = [];
    $anoAnterior = null;

    foreach ($porAno as $ano => $valores) {
        if ($anoAnterior !== null && $saldoCarregado > 0.0) {
            $anoExpiracao = $anoAnterior + 1;
            $expiracoes[] = ['ano' => $anoExpiracao, 'valor' => -$saldoCarregado, 'data' => $anoExpiracao . '-01-01'];
            $ganhoTotal -= $saldoCarregado;
            $saldoCarregado = 0.0;
        }
        $ganhoAno = $valores['ganho'] ?? 0.0;
        $gastoAno = $valores['gasto'] ?? 0.0;
        $ganhoTotal += $ganhoAno;
        $gastoTotal += $gastoAno;
        $saldoCarregado += $ganhoAno - $gastoAno;
        $anoAnterior = (int)$ano;
    }

    return ['ganho' => $ganhoTotal, 'gasto' => $gastoTotal, 'saldo' => $ganhoTotal - $gastoTotal, 'expiracoes' => $expiracoes];
}

/**
 * Calcula ganho, gasto e saldo de TODOS os alunos de uma vez, já com a
 * expiração anual aplicada se EXPIRAR_SALDO_ANO_NOVO estiver ligada (veja
 * aplicar_expiracao_anual()). Devolve um mapa
 * matricula => ['ganho'=>float,'gasto'=>float,'saldo'=>float]. Alunos sem
 * nenhuma atividade nem pedido simplesmente não aparecem aqui — trate a
 * ausência como zero. Este é o saldo "de verdade" (usado na loja e no
 * perfil) — para o saldo usado no RANKING, veja
 * calcular_resumo_financeiro_do_ano(), que é uma métrica separada.
 */
function calcular_resumo_financeiro(): array {
    $porAlunoAno = [];
    foreach (csv_assoc(ACTIVITIES_CSV) as $a) {
        $matriculas = matriculas_da_linha((string)($a['matriculas'] ?? ''));
        if (!$matriculas) continue;
        $valor = normalize_number((string)($a['valor'] ?? '0'));
        $ano = ano_da_data((string)($a['data'] ?? ''));
        foreach ($matriculas as $matricula) {
            $porAlunoAno[$matricula][$ano]['ganho'] = ($porAlunoAno[$matricula][$ano]['ganho'] ?? 0.0) + $valor;
        }
    }
    foreach (csv_assoc(ORDERS_CSV) as $p) {
        if (trim((string)($p['status'] ?? '')) === 'Cancelado') continue;
        $matricula = trim((string)($p['matricula'] ?? ''));
        if ($matricula === '') continue;
        $ano = ano_da_data((string)($p['data'] ?? ''));
        $porAlunoAno[$matricula][$ano]['gasto'] = ($porAlunoAno[$matricula][$ano]['gasto'] ?? 0.0) + normalize_number((string)($p['valor'] ?? '0'));
    }

    $resumo = [];
    foreach ($porAlunoAno as $matricula => $porAno) {
        $r = aplicar_expiracao_anual($porAno);
        $resumo[$matricula] = ['ganho' => $r['ganho'], 'gasto' => $r['gasto'], 'saldo' => $r['saldo']];
    }
    return $resumo;
}

/**
 * Ganho/gasto/saldo de todos os alunos considerando SÓ atividades e
 * pedidos de um ano específico — ignora por completo qualquer saldo
 * carregado de anos anteriores (não tem nada a ver com
 * EXPIRAR_SALDO_ANO_NOVO/aplicar_expiracao_anual(): aqui os outros anos nem
 * entram na conta, não é que o saldo deles "expirou"). Usado só pelo
 * ranking quando RANKING_APENAS_ANO_ATUAL está ligada — veja api/ranking.php.
 */
function calcular_resumo_financeiro_do_ano(int $ano): array {
    $resumo = [];
    foreach (csv_assoc(ACTIVITIES_CSV) as $a) {
        if (ano_da_data((string)($a['data'] ?? '')) !== $ano) continue;
        $matriculas = matriculas_da_linha((string)($a['matriculas'] ?? ''));
        if (!$matriculas) continue;
        $valor = normalize_number((string)($a['valor'] ?? '0'));
        foreach ($matriculas as $matricula) {
            $resumo[$matricula] ??= ['ganho' => 0.0, 'gasto' => 0.0];
            $resumo[$matricula]['ganho'] += $valor;
        }
    }
    foreach (csv_assoc(ORDERS_CSV) as $p) {
        if (trim((string)($p['status'] ?? '')) === 'Cancelado') continue;
        if (ano_da_data((string)($p['data'] ?? '')) !== $ano) continue;
        $matricula = trim((string)($p['matricula'] ?? ''));
        if ($matricula === '') continue;
        $resumo[$matricula] ??= ['ganho' => 0.0, 'gasto' => 0.0];
        $resumo[$matricula]['gasto'] += normalize_number((string)($p['valor'] ?? '0'));
    }
    foreach ($resumo as &$r) $r['saldo'] = $r['ganho'] - $r['gasto'];
    unset($r);
    return $resumo;
}

/**
 * Agrupa ganho/gasto de UM aluno por ano (lendo os CSVs) — a peça que
 * calcular_saldo_aluno() passa pra aplicar_expiracao_anual().
 */
function agrupar_financeiro_por_ano(string $matricula): array {
    $porAno = [];
    foreach (csv_assoc(ACTIVITIES_CSV) as $a) {
        $matriculas = matriculas_da_linha((string)($a['matriculas'] ?? ''));
        if (!in_array($matricula, $matriculas, true)) continue;
        $ano = ano_da_data((string)($a['data'] ?? ''));
        $porAno[$ano]['ganho'] = ($porAno[$ano]['ganho'] ?? 0.0) + normalize_number((string)($a['valor'] ?? '0'));
    }
    foreach (csv_assoc(ORDERS_CSV) as $p) {
        if ((string)($p['matricula'] ?? '') !== $matricula) continue;
        if (trim((string)($p['status'] ?? '')) === 'Cancelado') continue;
        $ano = ano_da_data((string)($p['data'] ?? ''));
        $porAno[$ano]['gasto'] = ($porAno[$ano]['gasto'] ?? 0.0) + normalize_number((string)($p['valor'] ?? '0'));
    }
    return $porAno;
}

/**
 * Mesma conta de calcular_resumo_financeiro(), mas só para um aluno —
 * inclui 'expiracoes' (a lista de linhas virtuais "Expirado" que valeram
 * pra ele, se EXPIRAR_SALDO_ANO_NOVO estiver ligada) pra quem quiser
 * mostrar no extrato (veja api/profile.php).
 */
function calcular_saldo_aluno(string $matricula): array {
    return aplicar_expiracao_anual(agrupar_financeiro_por_ano($matricula));
}

/**
 * Limitador de tentativas simples baseado em arquivo, para reduzir força
 * bruta. Por padrão ($porIp = true) a chave inclui o IP do cliente — bom
 * para login/redefinição de senha, onde "muitas tentativas vindas do mesmo
 * lugar" é um sinal real de ataque. Passe $porIp = false para um limite
 * compartilhado por TODOS os clientes (útil para cadastro numa escola, onde
 * várias contas legítimas costumam vir do mesmo IP de saída — nesse caso
 * limitar por IP puniria a escola inteira por causa de uma turma cadastrando
 * ao mesmo tempo). Falha "aberta" (não bloqueia) se o arquivo de controle
 * não puder ser usado, para nunca derrubar o site por isso.
 */
function enforce_rate_limit(string $action, string $target, int $maxAttempts, int $windowSeconds, bool $porIp = true): void {
    $fh = @fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fh) return;
    if (!acquire_lock($fh, LOCK_EX)) { fclose($fh); return; }

    $raw = stream_get_contents($fh);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) $data = [];

    $now = time();
    $key = $action . '|' . $target . ($porIp ? '|' . client_ip() : '');
    $recent = array_values(array_filter($data[$key] ?? [], fn($t) => is_int($t) && $t > $now - $windowSeconds));

    $blocked = count($recent) >= $maxAttempts;
    if (!$blocked) {
        $recent[] = $now;
        $data[$key] = $recent;
        // Mantém o arquivo pequeno: descarta chaves sem atividade na última hora.
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
