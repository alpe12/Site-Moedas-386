<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Só regras de formulário (senha, se "lembrar-me" deve aparecer) — nada
// sensível. Sem sessão pessoal envolvida, então nem precisa checar login.
json_response(admin_config_publica());
