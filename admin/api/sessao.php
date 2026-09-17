<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Endpoint bem leve só pra a tela de login saber se já existe uma sessão
// válida (e já redirecionar pro painel) — não exige login, só relata o
// status, então nunca dá 401.
admin_sessao_iniciar_se_necessario();
admin_aplicar_expiracao_login();

json_response(['autenticado' => !empty($_SESSION['authenticated'])]);
