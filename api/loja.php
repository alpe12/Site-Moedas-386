<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

sessao_iniciar_se_necessario();
aplicar_expiracao_login();
$autenticado = !empty($_SESSION['authenticated']) && !empty($_SESSION['matricula']);
$matricula = $autenticado ? (string)$_SESSION['matricula'] : null;
$nomeUsuario = $autenticado ? (string)($_SESSION['nome'] ?? '') : null;

function item_esta_ativo(array $item): bool {
    return in_array(trim((string)($item['ativo'] ?? '')), ['1', 'true', 'sim'], true);
}

/** Catálogo completo (inclui itens desativados) indexado por id. */
function carregar_catalogo(): array {
    $porId = [];
    foreach (csv_assoc(ITEMS_CSV) as $item) {
        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') continue;
        $porId[$id] = [
            'id' => $id,
            'nome' => trim((string)($item['nome'] ?? '')),
            'valor' => normalize_number((string)($item['valor'] ?? '0')),
            'icone' => trim((string)($item['icone'] ?? '')),
            'imagem' => trim((string)($item['imagem'] ?? '')),
            'ativo' => item_esta_ativo($item),
        ];
    }
    return $porId;
}

function itens_ativos_para_cliente(): array {
    $itensAtivos = array_values(array_filter(carregar_catalogo(), fn($i) => $i['ativo']));
    return array_map(fn($i) => [
        'id' => $i['id'], 'nome' => $i['nome'], 'valor' => round($i['valor'], 2),
        'icone' => $i['icone'], 'imagem' => $i['imagem'],
    ], $itensAtivos);
}

function mensagem_erro_lock(string $erro): ?string {
    return match ($erro) {
        'bloqueado' => 'O sistema está ocupado no momento. Tente novamente em alguns segundos.',
        'arquivo_indisponivel' => 'Não foi possível acessar os dados agora. Tente novamente em instantes.',
        default => null,
    };
}

function usuario_atual(string $matricula): ?array {
    foreach (csv_assoc(USERS_CSV) as $u) {
        if ((string)$u['matricula'] === $matricula) return $u;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $aba = (string)($_GET['aba'] ?? '');

    if ($aba === 'Pedidos Loja') {
        if (!$autenticado) json_response(['sucesso' => false, 'mensagem' => 'Não autenticado.'], 401);

        $pedidos = [];
        foreach (csv_assoc(ORDERS_CSV) as $p) {
            if ((string)$p['matricula'] !== $matricula) continue;
            $pedidos[] = [
                'id' => $p['id'],
                // "item" é o nome do item no momento da compra — continua
                // certo mesmo que o item seja renomeado ou desativado depois.
                'item' => $p['item'],
                'valor' => normalize_number($p['valor']),
                'status' => $p['status'],
                // só pedidos "Pendente" podem ser cancelados pelo próprio aluno.
                'cancelavel' => trim((string)$p['status']) === 'Pendente',
            ];
        }
        usort($pedidos, fn($a, $b) => strcmp((string)$b['id'], (string)$a['id']));
        json_response($pedidos);
    }

    if (!$autenticado) {
        if (!LOJA_VISIVEL_SEM_LOGIN) {
            json_response(['sucesso' => false, 'mensagem' => 'Não autenticado.'], 401);
        }
        // Modo "loja visível sem login": só o catálogo público, sem
        // qualquer dado pessoal (sem saldo, sem turma, sem nome de ninguém).
        json_response([
            'autenticado' => false,
            'itens' => itens_ativos_para_cliente(),
        ]);
    }

    $usuario = usuario_atual($matricula);
    if (!$usuario) json_response(['sucesso' => false, 'mensagem' => 'Aluno não encontrado.'], 404);

    $financeiro = calcular_saldo_aluno($matricula);
    $turmaAtual = turma_atual_deste_ano(turma_atual_do_aluno(carregar_historico_turmas_agrupado()[$matricula] ?? []), ano_letivo_atual());

    json_response([
        'autenticado' => true,
        'nome' => $nomeUsuario,
        'turma' => $turmaAtual['turma'] ?? '',
        'saldo' => round($financeiro['saldo'], 2),
        'itens' => itens_ativos_para_cliente(),
        'contaAtiva' => conta_esta_ativa($usuario),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$autenticado) json_response(['sucesso' => false, 'mensagem' => 'Não autenticado.'], 401);

    $data = request_json();
    $acao = (string)($data['acao'] ?? 'comprar');

    if ($acao === 'cancelar') {
        enforce_rate_limit('cancelar_pedido', $matricula, 20, 300);

        $pedidoId = trim((string)($data['pedido_id'] ?? ''));
        if ($pedidoId === '') json_response(['sucesso' => false, 'mensagem' => 'Pedido inválido.'], 400);

        // Cancelar é só mudar o status para "Cancelado" — não existe uma
        // transação de estorno separada. O valor "volta" automaticamente
        // porque pedidos "Cancelado" nunca entram na conta de gasto (veja
        // calcular_resumo_financeiro()), então não há como devolver o
        // valor duas vezes: a segunda tentativa de cancelar o mesmo pedido
        // encontra o status já diferente de "Pendente" e é recusada abaixo.
        $resultado = with_locked_csv(ORDERS_CSV, function (array $pedidos) use ($matricula, $pedidoId) {
            $encontrado = false;
            foreach ($pedidos as &$p) {
                if ((string)($p['id'] ?? '') !== $pedidoId) continue;
                if ((string)($p['matricula'] ?? '') !== $matricula) {
                    return ['return' => ['erro' => 'nao_encontrado']];
                }
                if (trim((string)($p['status'] ?? '')) !== 'Pendente') {
                    return ['return' => ['erro' => 'nao_pendente']];
                }
                $p['status'] = 'Cancelado';
                $encontrado = true;
            }
            unset($p);
            if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
            return ['rows' => $pedidos, 'return' => ['ok' => true]];
        }, headers_para_arquivo(ORDERS_CSV));

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
            $mensagens = [
                'nao_encontrado' => 'Pedido não encontrado.',
                'nao_pendente' => 'Este pedido já não está mais pendente e não pode ser cancelado.',
            ];
            $mensagem = $mensagens[$erro] ?? (mensagem_erro_lock($erro) ?? 'Não foi possível cancelar o pedido.');
            json_response(['sucesso' => false, 'mensagem' => $mensagem], 400);
        }

        json_response(['sucesso' => true, 'mensagem' => 'Pedido cancelado. O valor voltou para o seu saldo.']);
    }

    // acao === 'comprar' (padrão, também aceito sem 'acao' por compatibilidade)
    enforce_rate_limit('resgate', $matricula, 20, 300);

    $usuario = usuario_atual($matricula);
    if ($usuario && !conta_esta_ativa($usuario)) {
        json_response(['sucesso' => false, 'mensagem' => 'Sua conta ainda está pendente de aprovação e não pode fazer resgates.'], 403);
    }

    $itemId = trim((string)($data['item_id'] ?? ''));
    $catalogo = carregar_catalogo();
    if (!isset($catalogo[$itemId]) || !$catalogo[$itemId]['ativo']) {
        json_response(['sucesso' => false, 'mensagem' => 'Item indisponível.'], 400);
    }
    $item = $catalogo[$itemId];
    $preco = $item['valor'];

    // Um único lock em pedidos_loja.csv cobre "calcular saldo atual,
    // verificar se dá, e já inserir o novo pedido" numa única operação —
    // isso evita tanto o gasto em dobro (duas compras simultâneas lendo o
    // mesmo saldo antes de qualquer uma escrever) quanto o estado
    // inconsistente de antes (saldo debitado só em pedidos_loja.csv agora,
    // então não existe mais um segundo arquivo que possa ficar
    // dessincronizado se a escrita falhar no meio do caminho).
    $resultado = with_locked_csv(ORDERS_CSV, function (array $pedidos) use ($matricula, $item, $preco, $nomeUsuario) {
        // Agrupado por ano localmente em vez de chamar
        // calcular_saldo_aluno()/agrupar_financeiro_por_ano(): elas relêem
        // pedidos_loja.csv via csv_assoc(), e este processo já está com um
        // lock exclusivo aberto nele por fora — essa releitura ficaria
        // esperando o próprio lock que ele mesmo segura, até dar timeout.
        // $pedidos (parâmetro do callback) já é a versão atualizada; só as
        // atividades precisam ser lidas — atividades.csv é outro arquivo,
        // sem esse problema.
        $porAno = [];
        foreach ($pedidos as $p) {
            if ((string)($p['matricula'] ?? '') !== $matricula) continue;
            if (trim((string)($p['status'] ?? '')) === 'Cancelado') continue;
            $ano = ano_da_data((string)($p['data'] ?? ''));
            $porAno[$ano]['gasto'] = ($porAno[$ano]['gasto'] ?? 0.0) + normalize_number((string)($p['valor'] ?? '0'));
        }
        foreach (csv_assoc(ACTIVITIES_CSV) as $a) {
            $matriculasDaLinha = matriculas_da_linha((string)($a['matriculas'] ?? ''));
            if (!in_array($matricula, $matriculasDaLinha, true)) continue;
            $ano = ano_da_data((string)($a['data'] ?? ''));
            $porAno[$ano]['ganho'] = ($porAno[$ano]['ganho'] ?? 0.0) + normalize_number((string)($a['valor'] ?? '0'));
        }
        // Aplica a mesma expiração anual usada em calcular_saldo_aluno()
        // (se EXPIRAR_SALDO_ANO_NOVO estiver ligada) — sem isso, um saldo já
        // expirado no perfil ainda poderia ser gasto aqui.
        $saldoAtual = aplicar_expiracao_anual($porAno)['saldo'];

        if ($saldoAtual < $preco) {
            return ['return' => ['erro' => 'saldo_insuficiente']];
        }

        $id = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $pedidos[] = [
            'id' => $id, 'data' => date('c'), 'matricula' => $matricula, 'nomeAluno' => (string)$nomeUsuario,
            'item_id' => $item['id'], 'item' => $item['nome'],
            'valor' => number_format($preco, 2, '.', ''), 'status' => 'Pendente',
        ];

        return ['rows' => $pedidos, 'return' => ['ok' => true, 'saldo' => $saldoAtual - $preco]];
    }, headers_para_arquivo(ORDERS_CSV));

    if (!is_array($resultado) || !empty($resultado['erro'])) {
        $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
        $mensagens = ['saldo_insuficiente' => 'Saldo insuficiente.'];
        $mensagem = $mensagens[$erro] ?? (mensagem_erro_lock($erro) ?? 'Não foi possível registrar o pedido.');
        json_response(['sucesso' => false, 'mensagem' => $mensagem], 400);
    }

    json_response([
        'sucesso' => true,
        'mensagem' => 'Pedido registrado com sucesso.',
        'saldo' => round($resultado['saldo'], 2),
    ]);
}

json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
