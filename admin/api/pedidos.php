<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

const STATUS_VALIDOS = ['Pendente', 'Aprovado', 'Resgatado', 'Cancelado'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filtroStatus = trim((string)($_GET['status'] ?? ''));
    $historicoTurmas = carregar_historico_turmas_agrupado_admin();

    $lista = [];
    foreach (csv_assoc(SITE_ORDERS_CSV) as $p) {
        $status = (string)($p['status'] ?? '');
        if ($filtroStatus !== '' && $filtroStatus !== 'Todos' && $status !== $filtroStatus) continue;

        $matricula = (string)($p['matricula'] ?? '');
        $historicoDoAluno = $historicoTurmas[$matricula] ?? [];
        // A turma de QUANDO o pedido foi feito, não a atual — o aluno pode
        // ter trocado de turma depois. turmaHistorico vai completo pro
        // ícone de histórico em script_admin_pedidos.js.
        $turmaNaEpoca = formatar_registro_turma(turma_na_data($historicoDoAluno, (string)($p['data'] ?? '')));

        $lista[] = [
            'id' => $p['id'] ?? '',
            'data' => $p['data'] ?? '',
            'matricula' => $matricula,
            'nomeAluno' => $p['nomeAluno'] ?? '',
            'turma' => $turmaNaEpoca['turma'] ?? '',
            'turmaHistorico' => array_map('formatar_registro_turma', $historicoDoAluno),
            'item' => $p['item'] ?? '',
            'valor' => normalize_number((string)($p['valor'] ?? '0')),
            'status' => $status,
        ];
    }
    usort($lista, fn($a, $b) => strcmp((string)$b['id'], (string)$a['id']));
    json_response($lista);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = request_json();
    $acao = (string)($data['acao'] ?? '');

    if ($acao === 'atualizar_status') {
        $id = trim((string)($data['id'] ?? ''));
        $novoStatus = trim((string)($data['status'] ?? ''));

        if (!in_array($novoStatus, STATUS_VALIDOS, true)) {
            json_response(['sucesso' => false, 'mensagem' => 'Status inválido.'], 400);
        }

        $resultado = with_locked_csv(SITE_ORDERS_CSV, function (array $pedidos) use ($id, $novoStatus) {
            $encontrado = false;
            $statusAntigo = null;
            foreach ($pedidos as &$p) {
                if ((string)($p['id'] ?? '') !== $id) continue;
                $statusAntigo = $p['status'] ?? '';
                $p['status'] = $novoStatus;
                $encontrado = true;
            }
            unset($p);
            if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
            return ['rows' => $pedidos, 'return' => ['ok' => true, 'statusAntigo' => $statusAntigo]];
        }, SITE_CSV_HEADERS['pedidos']);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Pedido não encontrado.'], 404);
        }

        registrar_log_admin($admin['email'], 'atualizar_status_pedido', "id=$id; status: \"{$resultado['statusAntigo']}\" → \"$novoStatus\"");
        json_response(['sucesso' => true, 'mensagem' => 'Status atualizado.']);
    }

    json_response(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
