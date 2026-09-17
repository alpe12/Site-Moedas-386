<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

// Pedidos pendentes — sempre mostrados no painel, é o que precisa de ação
// mais frequente (aprovar/marcar como resgatado).
$pedidosPendentes = [];
foreach (csv_assoc(SITE_ORDERS_CSV) as $p) {
    if ((string)($p['status'] ?? '') !== 'Pendente') continue;
    $pedidosPendentes[] = [
        'id' => $p['id'] ?? '', 'data' => $p['data'] ?? '', 'matricula' => $p['matricula'] ?? '',
        'nomeAluno' => $p['nomeAluno'] ?? '', 'item' => $p['item'] ?? '',
        'valor' => normalize_number((string)($p['valor'] ?? '0')),
    ];
}
usort($pedidosPendentes, fn($a, $b) => strcmp((string)$b['id'], (string)$a['id']));

// Contas de aluno pendentes (ativo != "1"). O painel sempre devolve esta
// lista (é só ler usuarios.csv, o "banco de dados" compartilhado) — mas
// como toda conta nova do site público começa com ativo=0 independente de
// EXIGIR_APROVACAO_CONTA estar ligado lá (rastreia aprovações manuais sem
// depender da flag), é o JAVASCRIPT do painel que decide se exibe esta
// seção, checando antes se aquela flag está ligada via
// GET /api/config_publica.php do site público (isolamento: o PHP daqui
// nunca lê o config.php do site público, só os dados compartilhados).
$historicoTurmas = carregar_historico_turmas_agrupado_admin();
$contasPendentes = [];
foreach (csv_assoc(SITE_USERS_CSV) as $u) {
    if (trim((string)($u['ativo'] ?? '')) === '1') continue;
    $matricula = (string)($u['matricula'] ?? '');
    $turmaAtual = turma_atual_do_aluno($historicoTurmas[$matricula] ?? []);
    $contasPendentes[] = ['matricula' => $matricula, 'nome' => $u['nome'] ?? '', 'turma' => $turmaAtual['turma'] ?? ''];
}

$adminsPendentes = [];
foreach (csv_assoc(ADMINS_CSV) as $a) {
    if (trim((string)($a['ativo'] ?? '')) === '1') continue;
    $adminsPendentes[] = ['email' => $a['email'] ?? '', 'nome' => $a['nome'] ?? ''];
}

// Trocas de turma que ainda precisam de alguma atenção do admin: ou a
// aprovação em si ainda não foi decidida (aprovado == "0"), ou ela já foi
// decidida mas a retroatividade da data ainda está em aberto
// (retroativo_definido == "0" — só acontece na primeira turma do ano de
// um aluno quando a série não mudou, veja o comentário no topo de
// api/turma_utils.php). Mesmo raciocínio de $contasPendentes acima: toda
// solicitação nasce com aprovado=0 mesmo quando EXIGIR_APROVACAO_TROCA_TURMA
// está desligada (só pra o admin saber que uma troca aconteceu sem
// intervenção manual), então esta lista sempre inclui as duas situações —
// quem decide se a seção aparece, de novo, é o JS do painel, consultando a
// flag em /api/config_publica.php. Aprovar/recusar/definir retroatividade
// é feito por admin/api/turmas.php (ação, não leitura — por isso fica num
// arquivo separado deste, que é só leitura).
$nomesAlunos = [];
foreach (csv_assoc(SITE_USERS_CSV) as $u) {
    $nomesAlunos[(string)($u['matricula'] ?? '')] = (string)($u['nome'] ?? '');
}
$trocasTurmaPendentes = [];
foreach (csv_assoc(SITE_TURMAS_HISTORICO_CSV) as $t) {
    $aprovadoBruto = trim((string)($t['aprovado'] ?? ''));
    $retroativoDefinido = trim((string)($t['retroativo_definido'] ?? '1')) === '1';
    if ($aprovadoBruto !== '0' && $retroativoDefinido) continue; // nada em aberto nesta linha

    $matricula = (string)($t['matricula'] ?? '');
    $historicoDoAluno = $historicoTurmas[$matricula] ?? [];
    $turmaAnterior = turma_atual_do_aluno(array_values(array_filter(
        $historicoDoAluno,
        fn($linha) => (string)($linha['id'] ?? '') !== (string)($t['id'] ?? '')
    )));

    $aprovacaoForcada = trim((string)($t['aprovacao_forcada'] ?? '')) === '1';
    // Reconstituído aqui só pra dar um motivo legível ao admin — a decisão
    // de verdade já foi tomada e gravada (aprovacao_forcada) no momento da
    // solicitação, em api/turma.php; isto não influencia nada, só explica.
    $motivoAprovacaoForcada = null;
    if ($aprovacaoForcada) {
        $anoSolicitado = (int)($t['ano'] ?? 0);
        $anoAnterior = $turmaAnterior !== null ? (int)($turmaAnterior['ano'] ?? 0) : null;
        if ($anoAnterior !== null && $anoAnterior >= $anoSolicitado) {
            $motivoAprovacaoForcada = 'Aluno já tinha atualizado a turma neste ano — trocas adicionais sempre exigem aprovação.';
        } elseif ($turmaAnterior !== null && (string)$turmaAnterior['turma'] === (string)($t['turma'] ?? '')) {
            $motivoAprovacaoForcada = 'Turma idêntica à do ano anterior.';
        } else {
            $motivoAprovacaoForcada = 'Mesma série do ano anterior (possível repetência).';
        }
    }

    $trocasTurmaPendentes[] = [
        'id' => $t['id'] ?? '',
        'matricula' => $matricula,
        'nome' => $nomesAlunos[$matricula] ?? '(aluno não encontrado)',
        'turmaAnterior' => $turmaAnterior['turma'] ?? '',
        'turmaAnteriorAno' => $turmaAnterior['ano'] ?? null,
        'turmaSolicitada' => $t['turma'] ?? '',
        'ano' => $t['ano'] ?? '',
        'data' => $t['data_solicitacao'] ?? '',
        'statusAprovacao' => match ($aprovadoBruto) { '1' => 'aprovado', '-1' => 'recusado', default => 'pendente' },
        'retroativoPendente' => !$retroativoDefinido,
        'aprovacaoForcada' => $aprovacaoForcada,
        'motivoAprovacaoForcada' => $motivoAprovacaoForcada,
        // Histórico completo do aluno, pra o admin decidir com todo o
        // contexto (não só a troca anterior/solicitada desta linha) — veja
        // o modal de decisão em script_admin.js.
        'turmaHistorico' => array_map('formatar_registro_turma', $historicoDoAluno),
    ];
}
usort($trocasTurmaPendentes, fn($a, $b) => strcmp((string)$b['data'], (string)$a['data']));

json_response([
    'pedidosPendentes' => $pedidosPendentes,
    'contasPendentes' => $contasPendentes,
    'adminsPendentes' => $adminsPendentes,
    'trocasTurmaPendentes' => $trocasTurmaPendentes,
]);
