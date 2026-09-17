<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Público de propósito: são só regras de formulário e flags de exibição,
// nada sensível. O navegador busca isto uma vez por página para nunca
// duplicar valores que já existem em config.php. Cacheável via ETag: só
// muda quando alguém edita config.php (ou faz um novo deploy).
responder_com_cache([], __FILE__, fn() => config_publica());
