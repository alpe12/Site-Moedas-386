<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

$matricula = trim((string)($_GET['matricula'] ?? ''));
if ($matricula === '') {
    json_response(['sucesso' => false, 'mensagem' => 'Informe a matrícula do aluno.'], 400);
}

$usuario = null;
foreach (csv_assoc(SITE_USERS_CSV) as $u) {
    if ((string)($u['matricula'] ?? '') === $matricula) { $usuario = $u; break; }
}
if ($usuario === null) {
    json_response(['sucesso' => false, 'mensagem' => 'Nenhum aluno encontrado com essa matrícula.'], 404);
}

// agrupar_historico_turmas_agrupado_admin() já devolve, pra cada aluno, a
// lista ordenada da mais antiga pra mais nova (por data_efetiva) — veja
// agrupar_historico_turmas() em api/turma_utils.php.
$historicoTurmas = carregar_historico_turmas_agrupado_admin();
$historicoDoAluno = $historicoTurmas[$matricula] ?? [];

// Não existe uma coluna "criado_em"/"cadastrado_em" em usuarios.csv (ao
// contrário de admin/.private/admins.csv, que tem uma). A melhor
// aproximação disponível é a PRIMEIRA linha do histórico de turmas: ela é
// criada no exato momento do cadastro, junto com o usuário (veja
// auth.php, ação "cadastro") — nunca depois. Continua sendo só uma
// aproximação: um aluno cadastrado manualmente direto no CSV, sem passar
// pelo formulário, não teria essa linha "seed" correspondendo ao momento
// certo.
$primeiraLinhaHistorico = $historicoDoAluno[0] ?? null;
$cadastroAproximado = $primeiraLinhaHistorico !== null
    ? (string)($primeiraLinhaHistorico['data_solicitacao'] ?? '')
    : null;

$turmaAtual = formatar_registro_turma(turma_atual_do_aluno($historicoDoAluno));
// Mais recente primeiro, como as outras listas do painel (atividades, pedidos).
$turmaHistoricoFormatado = array_reverse(array_map('formatar_registro_turma', $historicoDoAluno));

// Mesmo cálculo (mesmo arquivo compartilhado, financeiro_utils.php) que o
// site público usa no perfil e na loja do próprio aluno — nunca uma versão
// simplificada que poderia mostrar um saldo diferente do que o aluno vê.
$financeiro = calcular_saldo_aluno_de(SITE_ACTIVITIES_CSV, SITE_ORDERS_CSV, $matricula);

$atividades = [];
foreach (csv_assoc(SITE_ACTIVITIES_CSV) as $a) {
    $matriculasDaLinha = matriculas_da_linha((string)($a['matriculas'] ?? ''));
    if (!in_array($matricula, $matriculasDaLinha, true)) continue;

    $dataAtividade = (string)($a['data'] ?? '');
    // A turma de QUANDO a atividade foi lançada, não a atual — mesmo
    // raciocínio de admin/api/atividades.php.
    $turmaNaEpoca = formatar_registro_turma(turma_na_data($historicoDoAluno, $dataAtividade));
    $atividades[] = [
        'id' => $a['id'] ?? '',
        'data' => $dataAtividade,
        'atividade' => $a['atividade'] ?? '',
        'valor' => normalize_number((string)($a['valor'] ?? '0')),
        'turma' => $turmaNaEpoca['turma'] ?? '',
        // Quantos alunos (incluindo este) essa mesma linha de
        // atividades.csv credita de uma vez — só informativo, pra deixar
        // claro no extrato quando um lançamento foi pra uma turma inteira
        // e não só pra este aluno.
        'quantidadeAlunosNaLinha' => count($matriculasDaLinha),
    ];
}
usort($atividades, fn($x, $y) => strcmp((string)$y['data'], (string)$x['data']) ?: strcmp((string)$y['id'], (string)$x['id']));

$pedidos = [];
foreach (csv_assoc(SITE_ORDERS_CSV) as $p) {
    if ((string)($p['matricula'] ?? '') !== $matricula) continue;

    $dataPedido = (string)($p['data'] ?? '');
    $turmaNaEpoca = formatar_registro_turma(turma_na_data($historicoDoAluno, $dataPedido));
    $pedidos[] = [
        'id' => $p['id'] ?? '',
        'data' => $dataPedido,
        'item_id' => $p['item_id'] ?? '',
        'item' => $p['item'] ?? '',
        'valor' => normalize_number((string)($p['valor'] ?? '0')),
        'status' => $p['status'] ?? '',
        'turma' => $turmaNaEpoca['turma'] ?? '',
    ];
}
usort($pedidos, fn($x, $y) => strcmp((string)$y['id'], (string)$x['id']));

json_response([
    'sucesso' => true,
    'aluno' => [
        'matricula' => (string)($usuario['matricula'] ?? ''),
        'nome' => (string)($usuario['nome'] ?? ''),
        'email' => (string)($usuario['email'] ?? ''),
        // Valor bruto de usuarios.csv + a config que decide se ele chega a
        // ser exigido — mesmo padrão de admin/api/painel.php ($contasPendentes):
        // o PHP do painel nunca lê o config.php do site público, então quem
        // decide se mostra isso como "pendente" é o JAVASCRIPT, comparando
        // com api/config_publica.php (window.adminConfigPromise).
        'ativoBruto' => trim((string)($usuario['ativo'] ?? '')) === '1',
        'exigeAprovacaoConta' => EXIGIR_APROVACAO_CONTA,
        // Guardado em texto puro de propósito no CSV (pra um monitor
        // conseguir ajudar um aluno que perdeu o código) — veja o
        // README-PHP-CSV.md. Mostrar aqui só centraliza algo que qualquer
        // admin já conseguiria ler abrindo usuarios.csv direto.
        'resetToken' => (string)($usuario['reset_token'] ?? ''),
    ],
    'cadastroAproximado' => $cadastroAproximado,
    'turmaAtual' => $turmaAtual,
    'turmaHistorico' => $turmaHistoricoFormatado,
    'financeiro' => $financeiro,
    'atividades' => $atividades,
    'pedidos' => $pedidos,
]);
