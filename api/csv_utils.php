<?php
declare(strict_types=1);

// ============================================================
// UTILITÁRIOS DE CSV COM TRAVA DE ARQUIVO — SEM EFEITOS COLATERAIS
// Só funções de leitura/escrita/trava de CSV e uns validadores genéricos;
// não inicia sessão, não manda cabeçalho HTTP nenhum, não depende de
// nenhuma configuração específica de um site (fora LOCK_TIMEOUT_SEGUNDOS,
// que cada site define no próprio config.php). Por isso tanto o site
// principal (api/_bootstrap.php) quanto o painel administrativo isolado
// em /admin/ (admin/api/_bootstrap.php) exigem este arquivo sem herdar
// nada um do outro além disto: é só a camada de acesso ao "banco de
// dados" (os CSVs), compartilhada porque os dados também são
// compartilhados entre o site público e o painel admin.
// ============================================================

if (!defined('LOCK_TIMEOUT_SEGUNDOS')) {
    define('LOCK_TIMEOUT_SEGUNDOS', 10);
}

/**
 * Tenta obter um lock (LOCK_EX ou LOCK_SH) sem travar o processo para
 * sempre: tenta em modo não-bloqueante e, se o arquivo já está travado por
 * outra requisição, espera um pouco e tenta de novo, até
 * LOCK_TIMEOUT_SEGUNDOS. Ou seja: uma segunda escrita simultânea espera sua
 * vez em vez de falhar na hora, mas não fica presa indefinidamente se algo
 * der errado.
 */
function acquire_lock(mixed $fh, int $modo): bool {
    $inicio = microtime(true);
    $espera = 20_000; // microssegundos; cresce um pouco a cada tentativa
    while (true) {
        if (flock($fh, $modo | LOCK_NB)) return true;
        if ((microtime(true) - $inicio) >= LOCK_TIMEOUT_SEGUNDOS) return false;
        usleep($espera);
        $espera = min($espera + 20_000, 200_000);
    }
}

/**
 * Lê um CSV inteiro em memória como uma lista de linhas (arrays indexados).
 * Uso interno; a maior parte do código deve preferir csv_assoc().
 */
function csv_rows(string $file): array {
    if (!is_readable($file)) return [];
    $fh = fopen($file, 'rb');
    if (!$fh) return [];
    if (!acquire_lock($fh, LOCK_SH)) { fclose($fh); return []; }
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) $rows[] = $row;
    flock($fh, LOCK_UN);
    fclose($fh);
    return $rows;
}

function rows_to_assoc(array $rows): array {
    if (!$rows) return [];
    $headers = array_map('trim', array_shift($rows));
    $out = [];
    foreach ($rows as $row) {
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $row = array_pad($row, count($headers), '');
        $item = [];
        foreach ($headers as $i => $header) $item[$header] = (string)$row[$i];
        $out[] = $item;
    }
    return $out;
}

function csv_assoc(string $file): array {
    return rows_to_assoc(csv_rows($file));
}

/**
 * Lê só a primeira linha do arquivo (cabeçalho), a partir de onde o
 * cursor já estiver — não é chamada isolada em uso normal, é um passo de
 * garantir_cabecalho_atualizado(). Devolve [] se o arquivo estiver vazio.
 */
function ler_cabecalho_atual(mixed $fh): array {
    rewind($fh);
    $primeira = fgetcsv($fh);
    return $primeira !== false ? array_map('trim', $primeira) : [];
}

/**
 * Garante que o arquivo (já aberto com lock exclusivo) tenha pelo menos as
 * colunas de $headerEsperado. Se faltar alguma — o schema do código
 * cresceu desde que o arquivo foi criado, ex.: uma coluna nova adicionada
 * numa atualização — reescreve o arquivo inteiro com o cabeçalho novo,
 * remapeando cada linha já existente POR NOME de coluna (não por posição):
 * é o que permite uma coluna nova ser inserida no MEIO do schema (este
 * projeto sempre mantém "aprovado" como a última coluna de propósito, pra
 * facilitar edição manual — então toda coluna nova entra ANTES dela, nunca
 * depois; um upgrade por posição juntaria o valor de "aprovado" de uma
 * linha antiga com o nome de coluna errado). Colunas que a linha antiga
 * não tinha viram "" (vazio) na linha upgradada. Devolve o cabeçalho
 * efetivo a partir de agora (o novo, se upgradou; o mesmo de antes, senão;
 * $headerEsperado se o arquivo estava vazio).
 *
 * Sem isso, um arquivo criado sob um schema mais curto travava PARA SEMPRE
 * nesse schema: with_locked_csv() e append_csv() confiavam cegamente no
 * cabeçalho que já estava no arquivo. append_csv() então gravava mais
 * valores do que o cabeçalho tinha nomes — o que não dá erro nenhum na
 * hora, mas desalinha a leitura de todo mundo depois — e with_locked_csv()
 * simplesmente descartava, silenciosa e permanentemente, qualquer coluna
 * que o cabeçalho antigo não conhecesse, toda vez que reescrevia o
 * arquivo. Nenhum dos dois erra visivelmente; os dois corrompem dado calado.
 */
function garantir_cabecalho_atualizado(mixed $fh, array $headerEsperado): array {
    if (!$headerEsperado) {
        return ler_cabecalho_atual($fh);
    }

    $headerAtual = ler_cabecalho_atual($fh);
    if (!$headerAtual) {
        // Arquivo vazio — nada pra upgradar; quem chamou grava o cabeçalho normalmente.
        return $headerEsperado;
    }
    if (!array_diff($headerEsperado, $headerAtual)) {
        // $headerAtual já tem toda coluna que $headerEsperado precisa
        // (mesmo que em outra ordem, ou com colunas extras) — não mexe.
        return $headerAtual;
    }
    if (array_diff($headerAtual, $headerEsperado)) {
        // O arquivo tem uma coluna que $headerEsperado nem conhece — situação
        // incomum (schema do código encolheu, ou foi editado à mão de um
        // jeito inesperado). Mais seguro não mexer do que arriscar perder
        // uma coluna que algum outro código ainda possa depender.
        return $headerAtual;
    }

    // Upgrade: remapeia cada linha existente por NOME, não por posição.
    rewind($fh);
    $linhasReconstruidas = [];
    $primeira = true;
    while (($linha = fgetcsv($fh)) !== false) {
        if ($primeira) { $primeira = false; continue; } // pula o cabeçalho antigo
        if (count($linha) === 1 && trim((string)$linha[0]) === '') continue;
        $linha = array_pad($linha, count($headerAtual), '');
        $mapa = array_combine($headerAtual, array_slice($linha, 0, count($headerAtual)));
        $linhasReconstruidas[] = array_map(fn($h) => $mapa[$h] ?? '', $headerEsperado);
    }
    rewind($fh);
    ftruncate($fh, 0);
    fputcsv($fh, $headerEsperado);
    foreach ($linhasReconstruidas as $linha) fputcsv($fh, $linha);
    fflush($fh);
    return $headerEsperado;
}

/**
 * Devolve true em sucesso. Devolve false tanto se não conseguiu abrir o
 * arquivo quanto se não conseguiu o lock dentro do tempo limite — quem
 * chama deve tratar isso como "tente de novo", não necessariamente como
 * erro permanente. $fallbackHeaders é usado tanto se o arquivo estiver
 * vazio/for novo quanto para upgradar um cabeçalho mais curto que ele —
 * veja garantir_cabecalho_atualizado().
 */
function append_csv(string $file, array $row, array $fallbackHeaders = []): bool {
    $fh = fopen($file, 'c+');
    if (!$fh) { error_log("append_csv: não foi possível abrir $file (verifique permissões de escrita)"); return false; }
    if (!acquire_lock($fh, LOCK_EX)) { fclose($fh); error_log("append_csv: timeout esperando lock em $file"); return false; }

    $header = garantir_cabecalho_atualizado($fh, $fallbackHeaders);

    fseek($fh, 0, SEEK_END);
    if (ftell($fh) === 0 && $header) {
        fputcsv($fh, $header);
    }
    fseek($fh, 0, SEEK_END);
    // $row deve vir com os valores na mesma ordem de $header — completa
    // com "" se vier mais curto (defensivo; não deveria acontecer).
    $linhaCompleta = $header ? array_pad($row, count($header), '') : $row;
    $ok = fputcsv($fh, $linhaCompleta) !== false;
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/**
 * Abre um CSV com um único lock exclusivo cobrindo leitura + escrita, para
 * que operações "ler, verificar, atualizar/inserir" sejam atômicas mesmo
 * com requisições concorrentes (evita condição de corrida em
 * saldo/estoque/duplicidade). Se não conseguir o lock dentro de
 * LOCK_TIMEOUT_SEGUNDOS, devolve ['erro' => 'bloqueado'] em vez de travar a
 * requisição para sempre.
 *
 * $fallbackHeaders só é usado se o arquivo estiver vazio/for novo.
 *
 * $callback recebe (array $linhasAssociativas, array $cabecalhos) e deve
 * devolver:
 *   - null / array sem 'rows'  => nada é escrito (ex.: erro de validação);
 *   - ['rows' => array, 'return' => mixed] => regrava o arquivo com 'rows'
 *     (cada linha é um array associativo com as mesmas chaves de $headers —
 *     tanto para atualizar linhas existentes quanto para acrescentar linhas
 *     novas ao array antes de devolver) e with_locked_csv() devolve
 *     'return' para quem chamou.
 */
function with_locked_csv(string $file, callable $callback, array $fallbackHeaders = []): mixed {
    $fh = fopen($file, 'c+b');
    if (!$fh) {
        error_log("with_locked_csv: não foi possível abrir $file (verifique permissões de escrita)");
        return ['erro' => 'arquivo_indisponivel'];
    }
    if (!acquire_lock($fh, LOCK_EX)) {
        fclose($fh);
        error_log("with_locked_csv: timeout esperando lock em $file");
        return ['erro' => 'bloqueado'];
    }

    // Se o cabeçalho do arquivo estiver mais curto que $fallbackHeaders (o
    // schema do código cresceu desde que o arquivo foi criado), upgrada o
    // arquivo ANTES de ler — sem isso, a regravação mais abaixo (que só
    // grava as colunas que $headers já conhece) descartaria silenciosamente
    // qualquer coluna nova. Veja garantir_cabecalho_atualizado().
    garantir_cabecalho_atualizado($fh, $fallbackHeaders);

    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) $rows[] = $row;
    $assoc = rows_to_assoc($rows);
    $headers = $rows ? array_map('trim', $rows[0]) : $fallbackHeaders;

    $result = $callback($assoc, $headers);

    if (is_array($result) && array_key_exists('rows', $result)) {
        $lines = [];
        foreach ($result['rows'] as $item) {
            $line = [];
            foreach ($headers as $h) $line[] = (string)($item[$h] ?? '');
            $lines[] = $line;
        }
        rewind($fh);
        ftruncate($fh, 0);
        fputcsv($fh, $headers);
        foreach ($lines as $line) fputcsv($fh, $line);
        fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);
    return is_array($result) ? ($result['return'] ?? null) : $result;
}

function request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

function normalize_number(string $value): float {
    $value = trim(str_replace(',', '.', $value));
    return is_numeric($value) ? (float)$value : 0.0;
}

/**
 * Valida e normaliza texto livre fornecido pelo usuário (nome, turma, etc.)
 * antes de gravar em CSV. Bloqueia caracteres que poderiam disparar
 * injeção de fórmula caso o CSV seja aberto no Excel/Sheets (=, +, -, @,
 * tab) e limita o conjunto a letras, números, espaços e pontuação básica —
 * isso também evita que HTML/JS acabe armazenado e depois renderizado em
 * outras páginas. Pensado para campos "de identidade" (nome de pessoa,
 * nome de item, turma) — para texto descritivo mais longo (parágrafos,
 * legendas), veja sanitize_texto_livre(), menos restritivo.
 */
function sanitize_plain_text(string $value, int $maxLength): ?string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '' || mb_strlen($value) > $maxLength) return null;
    if (!preg_match('/^[\p{L}0-9\s\'\-.,ºª()]+$/u', $value)) return null;
    if (preg_match('/^[=+\-@\t]/', $value)) return null;
    return $value;
}

/**
 * Como sanitize_plain_text(), mas para texto livre mais longo e descritivo
 * (descrições, legendas, títulos de conteúdo editorial) — permite
 * pontuação comum (! ? : ; ( ) % & / —, emoji, etc.) que um nome de pessoa
 * não precisaria ter. Ainda bloqueia caracteres de controle e o mesmo
 * gatilho de injeção de fórmula no início (=, +, -, @, tab) — a defesa
 * contra HTML/JS armazenado continua sendo escapar na hora de exibir
 * (escapeHtml no navegador), não a validação de entrada aqui.
 */
function sanitize_texto_livre(string $value, int $maxLength): ?string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '' || mb_strlen($value) > $maxLength) return null;
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) return null;
    if (preg_match('/^[=+\-@\t]/', $value)) return null;
    return $value;
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Uma linha de atividades.csv pode valer para mais de um aluno ao mesmo
 * tempo — a coluna "matriculas" aceita uma lista separada por ";".
 * (De propósito NÃO aceita vírgula: vírgula já é o separador de colunas do
 * próprio CSV. Um valor como "672026,672027" só sobrevive intacto num CSV
 * se o campo inteiro estiver entre aspas — o que o Excel/Sheets faz sozinho
 * ao salvar, mas ninguém garante isso editando o arquivo à mão num editor
 * de texto simples. Usando só ";" essa ambiguidade nem chega a existir.)
 * Devolve a lista já limpa (sem espaços, sem entradas vazias, sem repetição).
 * Compartilhada entre o site público e o painel admin (ambos precisam
 * interpretar essa coluna), por isso mora na camada sem efeitos colaterais.
 */
function matriculas_da_linha(string $campo): array {
    $partes = explode(';', $campo);
    return array_values(array_unique(array_filter(array_map('trim', $partes), fn($m) => $m !== '')));
}
