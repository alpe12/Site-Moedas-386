<?php
declare(strict_types=1);
require __DIR__ . '/api/_bootstrap.php';

sessao_iniciar_se_necessario();
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}
json_response(['sucesso' => true]);
