<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/*
 * Duas decisões independentes sobre uma troca de turma, feitas a partir do
 * painel (painel.html) depois do admin revisar tudo (nome, matrícula,
 * turma anterior/solicitada e o histórico completo) no modal de decisão —
 * veja abrirModalDecisaoTurma() em script_admin.js:
 *
 * - "aprovar"/"recusar": decide se a troca vale ou não. "Aprovar" grava
 *   aprovado=1. "Recusar" grava aprovado=-1 — a linha nunca é apagada nem
 *   editada além dessa coluna (mesma regra de nunca reescrever histórico
 *   que vale pro resto de turmas_historico.csv), só passa a nunca contar
 *   como aplicada (veja turma_registro_esta_aplicado() em
 *   api/turma_utils.php), o que naturalmente faz a turma anterior do aluno
 *   voltar a valer como "a atual" — tanto se a troca ainda estava represada
 *   esperando aprovação, quanto se ela já tinha entrado em vigor sozinha
 *   (EXIGIR_APROVACAO_TROCA_TURMA desligada) e o admin quer desfazer.
 *   Recusar também resolve retroativo_definido (não há mais nada a decidir
 *   sobre a data de uma troca que nunca vai contar).
 *
 * - "definir_retroativo": só pra quando retroativo_definido ainda é "0"
 *   (primeira turma do aluno no ano, série igual à anterior — caso
 *   ambíguo, veja o comentário no topo de api/turma_utils.php). Decide se
 *   data_efetiva conta desde 1º de janeiro daquele ano ou fica na data
 *   real do pedido. Independente de aprovar/recusar — pode ser decidido
 *   antes, depois, ou mesmo sem nunca mexer em aprovado (ex.: com
 *   EXIGIR_APROVACAO_TROCA_TURMA desligada, a troca já vale sozinha e só a
 *   data retroativa fica em aberto).
 */

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

$data = request_json();
$acao = (string)($data['acao'] ?? '');
$id = trim((string)($data['id'] ?? ''));

if ($id === '') {
    json_response(['sucesso' => false, 'mensagem' => 'Solicitação inválida.'], 400);
}
if (!in_array($acao, ['aprovar', 'recusar', 'definir_retroativo'], true)) {
    json_response(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

admin_enforce_rate_limit('turma_decisao', $admin['email'], 60, 3600);

if ($acao === 'definir_retroativo') {
    $retroativo = !empty($data['retroativo']);

    $resultado = with_locked_csv(SITE_TURMAS_HISTORICO_CSV, function (array $linhas) use ($id, $retroativo) {
        $encontrada = false;
        foreach ($linhas as &$linha) {
            if ((string)($linha['id'] ?? '') !== $id) continue;
            if (trim((string)($linha['retroativo_definido'] ?? '')) === '1') {
                return ['return' => ['erro' => 'ja_decidida']];
            }
            $ano = (string)($linha['ano'] ?? '');
            $linha['data_efetiva'] = $retroativo
                ? (new DateTime("$ano-01-01 00:00:00"))->format('c')
                : (string)($linha['data_solicitacao'] ?? '');
            $linha['retroativo_definido'] = '1';
            $encontrada = true;
            break;
        }
        unset($linha);
        if (!$encontrada) return ['return' => ['erro' => 'nao_encontrada']];
        return ['rows' => $linhas, 'return' => ['ok' => true]];
    }, SITE_CSV_HEADERS['turmas_historico']);

    if (!is_array($resultado) || !empty($resultado['erro'])) {
        $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
        $mensagens = [
            'nao_encontrada' => 'Solicitação não encontrada.',
            'ja_decidida' => 'A retroatividade desta troca já tinha sido decidida.',
        ];
        $mensagem = $mensagens[$erro] ?? ($erro === 'bloqueado'
            ? 'O sistema está ocupado no momento. Tente novamente em alguns segundos.'
            : 'Não foi possível registrar a decisão.');
        json_response(['sucesso' => false, 'mensagem' => $mensagem], 400);
    }

    registrar_log_admin($admin['email'], 'definir_retroativo_turma', "id=$id retroativo=" . ($retroativo ? '1' : '0'));
    json_response([
        'sucesso' => true,
        'mensagem' => $retroativo ? 'Troca marcada como retroativa a 1º de janeiro.' : 'Troca marcada como não retroativa.',
    ]);
}

// acao === 'aprovar' ou 'recusar'
$novoValor = $acao === 'aprovar' ? '1' : '-1';

$resultado = with_locked_csv(SITE_TURMAS_HISTORICO_CSV, function (array $linhas) use ($id, $novoValor, $acao) {
    $encontrada = false;
    foreach ($linhas as &$linha) {
        if ((string)($linha['id'] ?? '') !== $id) continue;
        // Confere de novo, já dentro do lock: evita que um duplo-clique (ou
        // dois admins decidindo a mesma solicitação ao mesmo tempo) processe
        // a mesma linha duas vezes.
        if (trim((string)($linha['aprovado'] ?? '')) !== '0') {
            return ['return' => ['erro' => 'ja_decidida']];
        }
        $linha['aprovado'] = $novoValor;
        if ($acao === 'recusar') {
            // Uma troca recusada nunca vai contar — não há mais nada a
            // decidir sobre a data dela, mesmo que a retroatividade ainda
            // estivesse em aberto.
            $linha['retroativo_definido'] = '1';
        }
        $encontrada = true;
        break;
    }
    unset($linha);
    if (!$encontrada) return ['return' => ['erro' => 'nao_encontrada']];
    return ['rows' => $linhas, 'return' => ['ok' => true]];
}, SITE_CSV_HEADERS['turmas_historico']);

if (!is_array($resultado) || !empty($resultado['erro'])) {
    $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
    $mensagens = [
        'nao_encontrada' => 'Solicitação não encontrada.',
        'ja_decidida' => 'Essa solicitação já tinha sido decidida.',
    ];
    $mensagem = $mensagens[$erro] ?? ($erro === 'bloqueado'
        ? 'O sistema está ocupado no momento. Tente novamente em alguns segundos.'
        : 'Não foi possível registrar a decisão.');
    json_response(['sucesso' => false, 'mensagem' => $mensagem], 400);
}

registrar_log_admin(
    $admin['email'],
    $acao === 'aprovar' ? 'aprovar_troca_turma' : 'recusar_troca_turma',
    "id=$id"
);

json_response([
    'sucesso' => true,
    'mensagem' => $acao === 'aprovar' ? 'Troca de turma aprovada.' : 'Troca de turma recusada.',
]);
