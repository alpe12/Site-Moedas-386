<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Log das ações dos alunos (login, troca de dados, resgates...). Só admins:
// exige a sessão do painel, e o arquivo em si fica em .private/, fechado
// para o navegador. Só leitura; mais recente primeiro.
$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

$limite = min(max((int)($_GET['limite'] ?? 200), 1), 1000);
$busca = mb_strtolower(trim((string)($_GET['busca'] ?? '')));

$linhas = csv_assoc(SITE_USER_LOG_CSV);

if ($busca !== '') {
    $linhas = array_values(array_filter($linhas, function (array $l) use ($busca): bool {
        foreach (['matricula', 'nome', 'acao', 'detalhes'] as $campo) {
            if (mb_strpos(mb_strtolower((string)($l[$campo] ?? '')), $busca) !== false) return true;
        }
        return false;
    }));
}

// O arquivo cresce de cima para baixo: inverter antes do usort() deixa, entre
// ações do mesmo segundo, a gravada por último primeiro (o usort do PHP 8 é estável).
$linhas = array_reverse($linhas);
usort($linhas, fn($a, $b) => strcmp((string)($b['data'] ?? ''), (string)($a['data'] ?? '')));

json_response(array_slice(array_map(fn($l) => [
    'data' => $l['data'] ?? '',
    'matricula' => $l['matricula'] ?? '',
    'nome' => $l['nome'] ?? '',
    'acao' => $l['acao'] ?? '',
    'detalhes' => $l['detalhes'] ?? '',
    'ip' => $l['ip'] ?? '',
], $linhas), 0, $limite));
