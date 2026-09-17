<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

const TAMANHO_MAXIMO_IMAGEM = 5 * 1024 * 1024; // 5 MB
const EXTENSOES_POR_MIME = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

/** Salva bytes de imagem já validados; devolve ['caminho'=>...] ou ['erro'=>...]. */
function salvar_bytes_imagem(string $bytes): array {
    $tamanho = strlen($bytes);
    if ($tamanho === 0 || $tamanho > TAMANHO_MAXIMO_IMAGEM) {
        return ['erro' => 'Arquivo vazio ou maior que 5 MB.'];
    }

    $info = @getimagesizefromstring($bytes);
    if ($info === false || !isset(EXTENSOES_POR_MIME[$info['mime']])) {
        return ['erro' => 'O arquivo não é uma imagem válida (use jpg, png, gif ou webp).'];
    }
    $extensao = EXTENSOES_POR_MIME[$info['mime']];

    // Nomeado pelo hash do conteúdo: a mesma imagem enviada duas vezes vira
    // o mesmo arquivo (dedup automático), e isso é o que torna a limpeza
    // de imagens órfãs simples (um caminho só pra procurar nos CSVs).
    $hash = hash('sha256', $bytes);
    $nomeArquivo = "$hash.$extensao";
    $caminhoRelativo = "uploads/$nomeArquivo";
    $caminhoAbsoluto = UPLOADS_DIR . '/' . $nomeArquivo;

    if (!is_file($caminhoAbsoluto)) {
        if (file_put_contents($caminhoAbsoluto, $bytes, LOCK_EX) === false) {
            return ['erro' => 'Não foi possível salvar a imagem no servidor (verifique permissões de escrita em uploads/).'];
        }
    }

    return ['caminho' => $caminhoRelativo];
}

/** Recusa hosts que parecem apontar pra rede interna — baixar imagem é uma ação só de admin, mas ainda assim vale a cautela básica. */
function host_parece_interno(string $host): bool {
    $host = strtolower(trim($host, '[]'));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
    if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|169\.254\.)/', $host)) return true;
    return false;
}

// Upload direto de arquivo (multipart/form-data, campo "arquivo").
if (!empty($_FILES['arquivo']) && ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    admin_enforce_rate_limit('upload_imagem', $admin['email'], 30, 300);

    $bytes = file_get_contents($_FILES['arquivo']['tmp_name']);
    if ($bytes === false) {
        json_response(['sucesso' => false, 'mensagem' => 'Não foi possível ler o arquivo enviado.'], 400);
    }

    $resultado = salvar_bytes_imagem($bytes);
    if (isset($resultado['erro'])) {
        json_response(['sucesso' => false, 'mensagem' => $resultado['erro']], 400);
    }

    registrar_log_admin($admin['email'], 'upload_imagem', "arquivo enviado; caminho={$resultado['caminho']}");
    json_response(['sucesso' => true, 'caminho' => $resultado['caminho']]);
}

$data = request_json();
$acao = (string)($data['acao'] ?? '');

// Remoção de imagem (limpa arquivo do disco se não houver referências em uploads/).
if ($acao === 'remover') {
    $caminho = trim((string)($data['caminho'] ?? ''));
    if ($caminho !== '' && str_starts_with($caminho, 'uploads/')) {
        limpar_imagem_se_orfa($caminho);
        registrar_log_admin($admin['email'], 'remover_imagem', "caminho=$caminho");
    }
    json_response(['sucesso' => true, 'mensagem' => 'Imagem removida.']);
}

// Baixar a partir de uma URL — o servidor baixa e passa a servir uma cópia
// local; a URL original só fica registrada no log, não é usada pra exibir
// a imagem depois (evita depender de um serviço externo continuar no ar).
$url = trim((string)($data['url'] ?? ''));
if ($url === '') {
    json_response(['sucesso' => false, 'mensagem' => 'Envie um arquivo ou informe uma URL.'], 400);
}

admin_enforce_rate_limit('upload_imagem', $admin['email'], 30, 300);

$partesUrl = parse_url($url);
if (!$partesUrl || !in_array($partesUrl['scheme'] ?? '', ['http', 'https'], true) || empty($partesUrl['host'])) {
    json_response(['sucesso' => false, 'mensagem' => 'URL inválida.'], 400);
}
if (host_parece_interno($partesUrl['host'])) {
    json_response(['sucesso' => false, 'mensagem' => 'Essa URL não é permitida.'], 400);
}

$contexto = stream_context_create([
    'http' => ['timeout' => 8, 'follow_location' => 1, 'max_redirects' => 3, 'header' => "User-Agent: EcoCoinAdmin/1.0\r\n"],
    'https' => ['timeout' => 8],
]);
// O 5º parâmetro corta a leitura no limite de tamanho — evita que uma URL
// pra um arquivo gigante trave o servidor tentando baixar tudo.
$bytes = @file_get_contents($url, false, $contexto, 0, TAMANHO_MAXIMO_IMAGEM + 1);
if ($bytes === false) {
    json_response(['sucesso' => false, 'mensagem' => 'Não foi possível baixar a imagem dessa URL.'], 400);
}

$resultado = salvar_bytes_imagem($bytes);
if (isset($resultado['erro'])) {
    json_response(['sucesso' => false, 'mensagem' => $resultado['erro']], 400);
}

registrar_log_admin($admin['email'], 'download_imagem', "baixada de $url; caminho={$resultado['caminho']}");
json_response(['sucesso' => true, 'caminho' => $resultado['caminho'], 'urlOrigem' => $url]);
