<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Endpoint leve só para a tela de login saber se já existe uma sessão
// válida (e já redirecionar para o perfil). Não exige login, só relata o
// status, então nunca devolve 401 — e, sem sessão, não carrega nenhum CSV
// (com sessão, aplicar_expiracao_login() lê usuarios.csv para conferir se a
// senha da conta mudou desde que ela começou).
sessao_iniciar_se_necessario();
aplicar_expiracao_login();

json_response(['autenticado' => !empty($_SESSION['authenticated']) && !empty($_SESSION['matricula'])]);
