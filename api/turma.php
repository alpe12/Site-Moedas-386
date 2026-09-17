<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/*
 * Duas ações do próprio aluno sobre sua turma (perfil.html), ambas com
 * confirmação no navegador antes de enviar (ver script_login_perfil.js) —
 * o servidor não pede confirmação de novo, só valida e grava:
 *
 * - "solicitar" (padrão): pede uma troca de turma. Sempre grava uma linha
 *   NOVA em turmas_historico.csv com aprovado=0 (nunca edita uma linha
 *   antiga — é assim que o histórico completo de turmas do aluno fica
 *   preservado). Se EXIGIR_APROVACAO_TROCA_TURMA estiver desligado
 *   (padrão), essa linha já vale como a turma atual do aluno na mesma
 *   hora, mesmo guardada com aprovado=0 — veja turma_registro_esta_aplicado()
 *   em api/turma_utils.php. Se for a primeira turma do aluno no ano, ainda
 *   decide (ou marca pra um admin decidir) se ela conta retroativa a 1º de
 *   janeiro — veja o comentário no topo de api/turma_utils.php.
 *
 * - "cancelar": desiste de uma solicitação que o próprio aluno pediu,
 *   contanto que ainda não tenha sido decidida (aprovado ainda "0"). Só
 *   faz sentido cancelar ANTES de qualquer decisão — depois de aprovada ou
 *   recusada, é tarde demais pro aluno desfazer sozinho. Diferente de uma
 *   recusa por um admin (que grava aprovado=-1 e mantém a linha pra
 *   auditoria), cancelar REMOVE a linha inteira: é como se ela nunca
 *   tivesse sido pedida — não há decisão nenhuma pra manter registro de.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

function mensagem_erro_lock(string $erro): ?string {
    return match ($erro) {
        'bloqueado' => 'O sistema está ocupado no momento. Tente novamente em alguns segundos.',
        'arquivo_indisponivel' => 'Não foi possível acessar os dados agora. Tente novamente em instantes.',
        default => null,
    };
}

$user = require_login();
$matricula = $user['matricula'];
$data = request_json();
$acao = (string)($data['acao'] ?? 'solicitar');

if ($acao === 'cancelar') {
    enforce_rate_limit('cancelar_troca_turma', $matricula, 20, 3600);

    $id = trim((string)($data['id'] ?? ''));
    if ($id === '') json_response(['sucesso' => false, 'mensagem' => 'Solicitação inválida.'], 400);

    $resultado = with_locked_csv(TURMAS_HISTORICO_CSV, function (array $linhas) use ($matricula, $id) {
        $indiceRemover = null;
        foreach ($linhas as $i => $linha) {
            if ((string)($linha['id'] ?? '') !== $id) continue;
            if ((string)($linha['matricula'] ?? '') !== $matricula) {
                return ['return' => ['erro' => 'nao_encontrada']];
            }
            if (trim((string)($linha['aprovado'] ?? '')) !== '0') {
                return ['return' => ['erro' => 'ja_decidida']];
            }
            $indiceRemover = $i;
            break;
        }
        if ($indiceRemover === null) return ['return' => ['erro' => 'nao_encontrada']];
        array_splice($linhas, $indiceRemover, 1);
        return ['rows' => $linhas, 'return' => ['ok' => true]];
    }, headers_para_arquivo(TURMAS_HISTORICO_CSV));

    if (!is_array($resultado) || !empty($resultado['erro'])) {
        $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
        $mensagens = [
            'nao_encontrada' => 'Solicitação não encontrada.',
            'ja_decidida' => 'Essa solicitação já foi decidida e não pode mais ser cancelada.',
        ];
        $mensagem = $mensagens[$erro] ?? (mensagem_erro_lock($erro) ?? 'Não foi possível cancelar a solicitação.');
        json_response(['sucesso' => false, 'mensagem' => $mensagem], 400);
    }

    json_response(['sucesso' => true, 'mensagem' => 'Solicitação cancelada.']);
}

// acao === 'solicitar' (padrão)
enforce_rate_limit('trocar_turma', $matricula, 10, 3600);

$turma = trim((string)($data['turma'] ?? ''));
if (($erro = validar_turma($turma)) !== null) {
    json_response(['sucesso' => false, 'mensagem' => $erro], 400);
}

$ano = ano_letivo_atual();
$agora = date('c');

// Um único lock cobre "ler o histórico deste aluno, checar se já existe
// uma troca represada, decidir retroatividade, e já inserir a linha nova"
// numa operação atômica — evita que duplo-clique (ou duas abas) crie duas
// solicitações pendentes ao mesmo tempo.
$resultado = with_locked_csv(TURMAS_HISTORICO_CSV, function (array $linhas) use ($matricula, $turma, $ano, $agora) {
    $doAluno = array_values(array_filter($linhas, fn($l) => (string)($l['matricula'] ?? '') === $matricula));

    if (existe_troca_turma_pendente($doAluno)) {
        return ['return' => ['erro' => 'ja_pendente']];
    }

    $atual = turma_atual_do_aluno($doAluno);
    if ($atual !== null && (string)$atual['turma'] === $turma && (int)$atual['ano'] === $ano) {
        return ['return' => ['erro' => 'mesma_turma']];
    }

    // Retroatividade e força de aprovação — ambas só entram em jogo se
    // esta for a primeira turma do aluno no ano (veja o comentário
    // completo no topo de api/turma_utils.php). $atual nunca é null aqui
    // na prática (chegar até este endpoint já exige estar logado, ou
    // seja, ter passado pelo cadastro, que sempre cria a primeira linha)
    // — o null é só defensivo.
    $dataEfetiva = $agora;
    $retroativoDefinido = '1'; // padrão: nada a decidir
    $aprovacaoForcada = '0';

    if (!eh_primeira_turma_do_ano($atual, $ano)) {
        // Regra 1 (TROCA_TURMA_UMA_LIVRE_POR_ANO): só a primeira troca do
        // ano pode auto-aplicar sozinha — qualquer troca adicional no
        // mesmo ano sempre exige aprovação, pra ninguém contornar a regra
        // 3 abaixo pedindo uma turma de passagem primeiro.
        if (TROCA_TURMA_UMA_LIVRE_POR_ANO) {
            $aprovacaoForcada = '1';
        }
    } elseif ($atual !== null) {
        if ((string)$atual['turma'] === $turma) {
            // Regra 2: idêntica à do ano anterior -> sempre força (fixo, não é opção).
            $aprovacaoForcada = '1';
        } elseif (primeiro_digito_turma((string)$atual['turma']) !== primeiro_digito_turma($turma)) {
            // Série mudou -> avanço de ano normal, sempre retroativo, decidido automaticamente.
            $dataEfetiva = (new DateTime("$ano-01-01 00:00:00"))->format('c');
        } else {
            // Regra 3 (EXIGIR_APROVACAO_TROCA_SERIE_REPETIDA): série igual,
            // valor diferente -> ambíguo (repetência de ano vs. realocação
            // de verdade). Fica com a retroatividade represada esperando
            // um admin decidir de qualquer forma; força aprovação da
            // troca em si também, a menos que a config esteja desligada.
            $retroativoDefinido = '0';
            if (EXIGIR_APROVACAO_TROCA_SERIE_REPETIDA) {
                $aprovacaoForcada = '1';
            }
        }
    }

    $linhas[] = [
        'id' => 'turma-' . bin2hex(random_bytes(4)),
        'matricula' => $matricula,
        'turma' => $turma,
        'ano' => (string)$ano,
        'data_solicitacao' => $agora,
        'data_efetiva' => $dataEfetiva,
        'retroativo_definido' => $retroativoDefinido,
        'aprovacao_forcada' => $aprovacaoForcada,
        'aprovado' => '0',
    ];
    return ['rows' => $linhas, 'return' => ['ok' => true, 'precisaAprovacao' => EXIGIR_APROVACAO_TROCA_TURMA || $aprovacaoForcada === '1']];
}, headers_para_arquivo(TURMAS_HISTORICO_CSV));

if (!is_array($resultado) || !empty($resultado['erro'])) {
    $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
    $mensagens = [
        'ja_pendente' => 'Você já tem uma atualização de turma aguardando aprovação.',
        'mesma_turma' => 'Sua turma já está registrada como essa.',
    ];
    $mensagem = $mensagens[$erro] ?? (mensagem_erro_lock($erro) ?? 'Não foi possível registrar a solicitação.');
    json_response(['sucesso' => false, 'mensagem' => $mensagem], 400);
}

$precisaAprovacao = $resultado['precisaAprovacao'] ?? EXIGIR_APROVACAO_TROCA_TURMA;

json_response([
    'sucesso' => true,
    'mensagem' => $precisaAprovacao
        ? 'Solicitação enviada! Sua turma será atualizada assim que um administrador aprovar.'
        : 'Turma atualizada com sucesso!',
    'precisaAprovacao' => $precisaAprovacao,
]);
