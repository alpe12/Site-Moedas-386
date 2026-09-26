<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$user = require_login();
$matricula = $user['matricula'];

$students = csv_assoc(USERS_CSV);
$student = null;
foreach ($students as $u) {
    if ((string)$u['matricula'] === $matricula) { $student = $u; break; }
}
if (!$student) json_response(['sucesso' => false, 'mensagem' => 'Usuário não encontrado.'], 404);

$financeiro = calcular_saldo_aluno($matricula);

$historicoTurmas = carregar_historico_turmas_agrupado();
$historicoDoAluno = $historicoTurmas[$matricula] ?? [];
$anoAtual = ano_letivo_atual();
// Se a turma mais recente do aluno não é deste ano (ele nunca foi
// realocado pra uma turma do ano corrente), ele NÃO vê essa turma velha
// como se fosse a atual — vê "sem turma definida" e é incentivado a
// corrigir. turma_atual_do_aluno() sem esse filtro continua sendo usada
// pra fins administrativos (turma_na_data() em compras/atividades
// antigas, e como "turma anterior" pra decidir retroatividade de uma
// solicitação nova) — só a visão do PRÓPRIO aluno aplica esse filtro.
$turmaAtual = formatar_registro_turma(turma_atual_deste_ano(turma_atual_do_aluno($historicoDoAluno), $anoAtual));

$trocaPendente = troca_turma_cancelavel($historicoDoAluno);
// troca_turma_cancelavel() devolve qualquer linha aprovado=0, mesmo já
// aplicada (EXIGIR_APROVACAO_TROCA_TURMA desligada, sem aprovacao_forcada)
// — o que interessa pra perfil.html é só o caso onde ainda falta um admin
// decidir: aí sim faz sentido mostrar "aguardando aprovação" e oferecer
// cancelar. Uma linha já em vigor não tem nada pendente do ponto de vista
// do aluno, mesmo que um admin ainda não tenha revisado.
if ($trocaPendente !== null && turma_registro_esta_aplicado($trocaPendente)) {
    $trocaPendente = null;
}

$atividades = [];
foreach (csv_assoc(ACTIVITIES_CSV) as $a) {
    $matriculasDaLinha = matriculas_da_linha((string)($a['matriculas'] ?? ''));
    if (!in_array($matricula, $matriculasDaLinha, true)) continue;
    $atividades[] = [
        'data' => $a['data'],
        'atividade' => $a['atividade'],
        'valor' => normalize_number($a['valor']),
    ];
}
// Linhas virtuais de expiração (EXPIRAR_SALDO_ANO_NOVO) — nunca existem em
// atividades.csv, só aparecem aqui pro aluno entender por que o saldo de um
// ano anterior não está mais disponível.
foreach ($financeiro['expiracoes'] as $exp) {
    $atividades[] = ['data' => $exp['data'], 'atividade' => 'Expirado', 'valor' => $exp['valor']];
}
// Mais recentes primeiro.
usort($atividades, fn($x, $y) => strcmp((string)$y['data'], (string)$x['data']));

json_response([
    'nome' => $student['nome'],
    // Só a turma mais recente é mostrada ao aluno — o histórico completo é
    // uso interno/admin (ver admin/api/atividades.php e pedidos.php).
    'turma' => $turmaAtual['turma'] ?? '',
    'matricula' => $student['matricula'],
    'saldo' => round($financeiro['saldo'], 2),
    'atividades' => $atividades,
    'contaAtiva' => conta_esta_ativa($student),
    // null se não houver nenhuma troca aguardando decisão; senão, os dados
    // que perfil.html precisa pra mostrar o aviso e oferecer "cancelar"
    // (api/turma.php, ação "cancelar", usando este id).
    'trocaTurmaPendente' => $trocaPendente !== null ? [
        'id' => $trocaPendente['id'] ?? '',
        'turma' => $trocaPendente['turma'] ?? '',
        'ano' => (int)($trocaPendente['ano'] ?? 0),
        'data' => $trocaPendente['data_solicitacao'] ?? '',
    ] : null,
]);
