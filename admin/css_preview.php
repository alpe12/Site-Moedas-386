<?php
declare(strict_types=1);

/**
 * css_preview.php — serve, como CSS, SÓ os trechos de ../style.css marcados
 * pra prévia do painel admin.
 *
 * POR QUE EXISTE
 * admin/conteudo.html e admin/loja.html têm um botão "Visualizar" que mostra
 * como o slide do carrossel / o card de item da loja vai aparecer no site
 * público. Pra isso fiel de verdade, a prévia precisa das MESMAS regras CSS
 * do site. Mas carregar style.css inteiro no painel vazaria as regras
 * globais dele (body, table, th, td, tr:hover, o reset "* { margin:0 }"...)
 * pras tabelas e o layout do admin. Este arquivo resolve as duas coisas: lê
 * style.css e devolve só o que foi marcado pra isso — a prévia usa as regras
 * reais (nunca uma cópia que pode ficar desatualizada) e o resto do painel
 * não é afetado.
 *
 * COMO MARCAR UM TRECHO (em style.css)
 * Tudo que estiver entre um par de comentários CSS, no nível raiz do arquivo:
 *
 *     / * @preview:inicio  legenda opcional, só pra quem lê * /
 *     .minha-regra { ... }
 *     / * @preview:fim * /
 *
 * (escritos SEM os espaços entre "/" e "*" — aparecem separados aqui só
 * porque um comentário PHP não pode conter o fecha-comentário de verdade.)
 *
 * O que vale a pena saber:
 *   - Pode haver quantos pares quiser; a saída é a concatenação deles, na
 *     ordem em que aparecem em style.css.
 *   - Marcador dentro de @media/@supports NÃO funciona: copiamos o texto
 *     entre os marcadores como está, sem reconstruir o bloco em volta. E
 *     @media fica de fora de propósito — a largura da janela do admin não é
 *     a do visitante, e as prévias são caixas de largura fixa.
 *   - Marcadores desbalanceados ou aninhados → erro 500 com o motivo escrito
 *     num comentário CSS (e no error_log), em vez de servir CSS quebrado.
 *   - Regras marcadas que usam var(--x) precisam que --x esteja num trecho
 *     marcado também. O :root inteiro de style.css já está marcado.
 *   - Se o HTML das prévias (script_admin_loja.js / script_admin_conteudo.js)
 *     passar a usar uma classe nova, a regra dela tem que ir pra dentro de
 *     um par de marcadores — senão a prévia sai sem estilo.
 *
 * CACHE — mesmas regras de responder_com_cache() em api/_bootstrap.php
 * (veja o README do site, "Cache no navegador e em CDN"). Resumo:
 *   1) Last-Modified / If-Modified-Since: a data é a MAIS RECENTE entre
 *      style.css e este próprio arquivo (mexer em qualquer um dos dois
 *      invalida o cache). Se o cliente já tem essa versão, 304 sem nem ler
 *      o CSS.
 *   2) ETag / If-None-Match: calculado a partir do CSS já extraído, então
 *      mudar um trecho NÃO marcado de style.css (o que não altera esta
 *      saída) ainda resulta em 304, só que depois de extrair.
 *   3) Cache-Control: public, no-cache, max-age=0 (guardar pode; reusar sem
 *      revalidar não).
 *
 * Este arquivo NÃO inclui _bootstrap.php e nunca inicia sessão: uma resposta
 * com Set-Cookie não é cacheável por CDN, e o conteúdo é o mesmo CSS público
 * que qualquer visitante já baixa em style.css — não há nada a proteger aqui.
 */

$arquivoCss = __DIR__ . '/../style.css';

/** Responde erro de forma visível (comentário CSS) e sem deixar cachear. */
function css_preview_erro(string $motivo): never {
    error_log('css_preview.php: ' . $motivo);
    header_remove('Last-Modified');
    header_remove('ETag');
    http_response_code(500);
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo '/* css_preview.php: ' . str_replace('*/', '* /', $motivo) . ' */';
    exit;
}

if (!is_file($arquivoCss) || !is_readable($arquivoCss)) {
    css_preview_erro('style.css não encontrado ou ilegível.');
}

header('Cache-Control: public, no-cache, max-age=0');

// 1) Last-Modified: o mais recente entre o CSS e este próprio arquivo.
$mtimeMaisRecente = max((int)filemtime($arquivoCss), (int)filemtime(__FILE__));
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtimeMaisRecente) . ' GMT');

$seModificadoDesde = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
if ($seModificadoDesde !== '') {
    $timestampCliente = strtotime($seModificadoDesde);
    if ($timestampCliente !== false && $timestampCliente >= $mtimeMaisRecente) {
        http_response_code(304);
        exit;
    }
}

// 2) Extração dos trechos marcados.
$css = file_get_contents($arquivoCss);
if ($css === false) {
    css_preview_erro('não consegui ler style.css.');
}

// Só casa o comentário CSS de verdade (barra+asterisco colados no marcador):
// a explicação no topo do style.css escreve "/ * ... * /" com espaços, então
// nunca é confundida com um marcador.
$reInicio = '#/\*\s*@preview:inicio\b[^*]*\*/#';
$reFim    = '#/\*\s*@preview:fim\b[^*]*\*/#';

$totalInicios = preg_match_all($reInicio, $css);
$totalFins    = preg_match_all($reFim, $css);
if ($totalInicios === 0) {
    css_preview_erro('nenhum marcador @preview:inicio encontrado em style.css.');
}
if ($totalInicios !== $totalFins) {
    css_preview_erro("marcadores desbalanceados em style.css ($totalInicios inicio x $totalFins fim).");
}

preg_match_all(
    '#/\*\s*@preview:inicio\b([^*]*)\*/(.*?)/\*\s*@preview:fim\b[^*]*\*/#s',
    $css,
    $blocos,
    PREG_SET_ORDER
);
$partes = [];
foreach ($blocos as $i => $bloco) {
    if (preg_match($reInicio, $bloco[2])) {
        css_preview_erro('marcadores @preview aninhados (um "inicio" antes do "fim" anterior), bloco #' . ($i + 1) . '.');
    }
    $legenda = trim($bloco[1]);
    $partes[] = '/* @preview' . ($legenda !== '' ? ' ' . $legenda : '') . ' */' . "\n" . trim($bloco[2]);
}
// Checado DEPOIS do laço de propósito: com marcadores aninhados o pareamento
// sempre sobra (menos blocos que "inicios"), e o laço acima já explica isso
// com uma mensagem melhor. Aqui pega o resto (ex.: um "fim" solto no meio).
if (count($blocos) !== $totalInicios) {
    css_preview_erro('não consegui parear todos os marcadores @preview em style.css.');
}

$corpo = "/* Gerado por admin/css_preview.php a partir dos trechos @preview de style.css.\n"
       . "   NÃO edite aqui: edite o trecho marcado em style.css. */\n\n"
       . implode("\n\n", $partes) . "\n";

// 3) ETag do conteúdo já extraído.
$etag = substr(hash('sha256', $corpo), 0, 32);
header('ETag: "' . $etag . '"');

$etagCliente = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
if ($etagCliente !== '' && str_contains($etagCliente, $etag)) {
    http_response_code(304);
    exit;
}

http_response_code(200);
header('Content-Type: text/css; charset=utf-8');
echo $corpo;
exit;
