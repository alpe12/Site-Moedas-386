<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

$limite = min(max((int)($_GET['limite'] ?? 200), 1), 1000);

$linhas = csv_assoc(ADMIN_LOG_CSV);
// Mais recente primeiro (a data ISO 8601 ordena corretamente como texto).
usort($linhas, fn($a, $b) => strcmp((string)($b['data'] ?? ''), (string)($a['data'] ?? '')));

json_response(array_slice(array_map(fn($l) => [
    'data' => $l['data'] ?? '',
    'admin_email' => $l['admin_email'] ?? '',
    'acao' => $l['acao'] ?? '',
    'detalhes' => $l['detalhes'] ?? '',
], $linhas), 0, $limite));
