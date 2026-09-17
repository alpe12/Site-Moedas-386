<?php
declare(strict_types=1);

// ============================================================
// O ÚNICO VALOR REALMENTE COMPARTILHADO entre o site público (api/config.php)
// e o painel admin isolado (admin/api/_bootstrap.php) — por isso mora num
// arquivo à parte, minúsculo, em vez de fazer o painel admin exigir todo o
// config.php do site público (o que colidiria com constantes de mesmo nome
// nos dois lados, já que os dois são isolados de propósito). Se algum dia
// mais flags precisarem ser compartilhadas dos dois lados, adicione aqui —
// não em nenhum dos dois config.php.
// ============================================================

if (!defined('EXIGIR_APROVACAO_CONTA')) {
    define('EXIGIR_APROVACAO_CONTA', false);
}

// Mesma ideia de EXIGIR_APROVACAO_CONTA, mas pra trocas de turma
// solicitadas pelo aluno em perfil.html — veja api/turma_utils.php pra a
// lógica de "o que conta como aplicado" e o comentário no cadastro de
// turmas_historico.csv (api/_bootstrap.php) pra o formato completo.
// false (padrão) -> toda troca solicitada já vale na hora.
// true  -> a turma só muda de verdade depois que um admin trocar a coluna
//          "aprovado" pra 1 em .private/turmas_historico.csv; até lá, a
//          turma anterior continua sendo a "atual" em qualquer tela.
if (!defined('EXIGIR_APROVACAO_TROCA_TURMA')) {
    define('EXIGIR_APROVACAO_TROCA_TURMA', false);
}
