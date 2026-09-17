<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

/** ids de itens que aparecem em pelo menos um pedido (de qualquer status, inclusive Cancelado). */
function ids_referenciados(): array {
    $ids = [];
    foreach (csv_assoc(SITE_ORDERS_CSV) as $p) {
        $id = trim((string)($p['item_id'] ?? ''));
        if ($id !== '') $ids[$id] = true;
    }
    return $ids;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $referenciados = ids_referenciados();
    $lista = [];
    foreach (csv_assoc(SITE_ITEMS_CSV) as $i) {
        $id = (string)($i['id'] ?? '');
        if ($id === '') continue;
        $lista[] = [
            'id' => $id,
            'nome' => (string)($i['nome'] ?? ''),
            'valor' => normalize_number((string)($i['valor'] ?? '0')),
            'icone' => (string)($i['icone'] ?? ''),
            'imagem' => (string)($i['imagem'] ?? ''),
            'ativo' => trim((string)($i['ativo'] ?? '')) === '1',
            'referenciado' => isset($referenciados[$id]),
        ];
    }
    // Ativos primeiro, depois inativos; dentro de cada grupo, por nome.
    usort($lista, fn($a, $b) => [$b['ativo'], $a['nome']] <=> [$a['ativo'], $b['nome']]);
    json_response($lista);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = request_json();
    $acao = (string)($data['acao'] ?? '');

    if ($acao === 'criar' || $acao === 'editar') {
        $nome = sanitize_plain_text((string)($data['nome'] ?? ''), 80);
        $valor = normalize_number((string)($data['valor'] ?? ''));
        $icone = trim((string)($data['icone'] ?? ''));
        $imagem = trim((string)($data['imagem'] ?? ''));

        if ($nome === null) json_response(['sucesso' => false, 'mensagem' => 'Nome inválido.'], 400);
        if ($valor <= 0) json_response(['sucesso' => false, 'mensagem' => 'Informe um preço maior que zero.'], 400);
        if (mb_strlen($icone) > 8) json_response(['sucesso' => false, 'mensagem' => 'Ícone deve ser bem curto (emoji ou 1-2 caracteres).'], 400);
        if ($imagem !== '' && !filter_var($imagem, FILTER_VALIDATE_URL) && !str_starts_with($imagem, 'Imagens/') && !str_starts_with($imagem, 'uploads/')) {
            json_response(['sucesso' => false, 'mensagem' => 'Imagem inválida.'], 400);
        }

        if ($acao === 'criar') {
            $id = trim((string)($data['id'] ?? ''));
            if (!preg_match('/^[a-z0-9\-]{2,40}$/', $id)) {
                json_response(['sucesso' => false, 'mensagem' => 'Use um identificador só com letras minúsculas, números e hífen (2-40 caracteres).'], 400);
            }

            $resultado = with_locked_csv(SITE_ITEMS_CSV, function (array $itens) use ($id, $nome, $valor, $icone, $imagem) {
                foreach ($itens as $i) {
                    if ((string)($i['id'] ?? '') === $id) return ['return' => ['erro' => 'id_duplicado']];
                }
                $itens[] = ['id' => $id, 'nome' => $nome, 'valor' => number_format($valor, 2, '.', ''), 'icone' => $icone, 'imagem' => $imagem, 'ativo' => '1'];
                return ['rows' => $itens, 'return' => ['ok' => true]];
            }, SITE_CSV_HEADERS['itens']);

            if (!is_array($resultado) || !empty($resultado['erro'])) {
                json_response(['sucesso' => false, 'mensagem' => 'Já existe um item com esse identificador.'], 409);
            }
            registrar_log_admin($admin['email'], 'criar_item', "id=$id nome=\"$nome\" valor=$valor");
            json_response(['sucesso' => true, 'mensagem' => 'Item criado.']);
        }

        // editar
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') json_response(['sucesso' => false, 'mensagem' => 'Item inválido.'], 400);

        $resultado = with_locked_csv(SITE_ITEMS_CSV, function (array $itens) use ($id, $nome, $valor, $icone, $imagem) {
            $encontrado = false;
            $itemAntigo = null;
            foreach ($itens as &$i) {
                if ((string)($i['id'] ?? '') !== $id) continue;
                $itemAntigo = $i;
                $i['nome'] = $nome;
                $i['valor'] = number_format($valor, 2, '.', '');
                $i['icone'] = $icone;
                $i['imagem'] = $imagem;
                $encontrado = true;
            }
            unset($i);
            if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
            return ['rows' => $itens, 'return' => ['ok' => true, 'antigo' => $itemAntigo]];
        }, SITE_CSV_HEADERS['itens']);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Não foi possível editar o item.'], 400);
        }
        $novo = ['nome' => $nome, 'valor' => number_format($valor, 2, '.', ''), 'icone' => $icone, 'imagem' => $imagem];
        $diff = construir_diff($resultado['antigo'] ?? [], $novo, ['nome', 'valor', 'icone', 'imagem']);
        $imagemAntiga = (string)($resultado['antigo']['imagem'] ?? '');
        if ($imagemAntiga !== '' && $imagemAntiga !== $imagem) {
            limpar_imagem_se_orfa($imagemAntiga);
        }
        registrar_log_admin($admin['email'], 'editar_item', "id=$id; $diff");
        json_response(['sucesso' => true, 'mensagem' => 'Item atualizado.']);
    }

    if ($acao === 'ativar' || $acao === 'desativar') {
        $id = trim((string)($data['id'] ?? ''));
        $novoValor = $acao === 'ativar' ? '1' : '0';

        $resultado = with_locked_csv(SITE_ITEMS_CSV, function (array $itens) use ($id, $novoValor) {
            $encontrado = false;
            foreach ($itens as &$i) {
                if ((string)($i['id'] ?? '') !== $id) continue;
                $i['ativo'] = $novoValor;
                $encontrado = true;
            }
            unset($i);
            if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
            return ['rows' => $itens, 'return' => ['ok' => true]];
        }, SITE_CSV_HEADERS['itens']);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Item não encontrado.'], 404);
        }
        registrar_log_admin($admin['email'], $acao . '_item', "id=$id");
        json_response(['sucesso' => true, 'mensagem' => $acao === 'ativar' ? 'Item ativado.' : 'Item desativado.']);
    }

    if ($acao === 'remover') {
        $id = trim((string)($data['id'] ?? ''));
        if (isset(ids_referenciados()[$id])) {
            // Tem pedido associado — nunca remove de vez (o id poderia ser
            // reaproveitado depois, o que corromperia o histórico desses
            // pedidos). Só garante que fica desativado.
            with_locked_csv(SITE_ITEMS_CSV, function (array $itens) use ($id) {
                foreach ($itens as &$i) {
                    if ((string)($i['id'] ?? '') === $id) $i['ativo'] = '0';
                }
                unset($i);
                return ['rows' => $itens, 'return' => ['ok' => true]];
            }, SITE_CSV_HEADERS['itens']);

            registrar_log_admin($admin['email'], 'desativar_item_forcado', "id=$id (tinha pedidos associados)");
            json_response(['sucesso' => true, 'resultado' => 'desativado_por_seguranca',
                'mensagem' => 'Este item tem pedidos associados e não pode ser removido — foi apenas desativado.']);
        }

        json_response(['sucesso' => true, 'resultado' => 'sem_referencias',
            'mensagem' => 'Este item nunca foi pedido. Deseja apenas desativar ou remover definitivamente?']);
    }

    if ($acao === 'remover_definitivo') {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') json_response(['sucesso' => false, 'mensagem' => 'Item inválido.'], 400);

        // Confere de novo, agora sob o MESMO lock da escrita — cobre o caso
        // de alguém ter feito um pedido bem entre o admin abrir a tela e
        // clicar em remover.
        $resultado = with_locked_csv(SITE_ITEMS_CSV, function (array $itens) use ($id) {
            $referenciadoAgora = false;
            foreach (csv_assoc(SITE_ORDERS_CSV) as $p) {
                if ((string)($p['item_id'] ?? '') === $id) { $referenciadoAgora = true; break; }
            }

            if ($referenciadoAgora) {
                foreach ($itens as &$i) {
                    if ((string)($i['id'] ?? '') === $id) $i['ativo'] = '0';
                }
                unset($i);
                return ['rows' => $itens, 'return' => ['resultado' => 'referenciado_na_hora']];
            }

            $imagemRemovida = '';
            foreach ($itens as $i) {
                if ((string)($i['id'] ?? '') === $id) { $imagemRemovida = (string)($i['imagem'] ?? ''); break; }
            }

            $antes = count($itens);
            $itens = array_values(array_filter($itens, fn($i) => (string)($i['id'] ?? '') !== $id));
            if (count($itens) === $antes) return ['return' => ['resultado' => 'nao_encontrado']];
            return ['rows' => $itens, 'return' => ['resultado' => 'removido', 'imagem' => $imagemRemovida]];
        }, SITE_CSV_HEADERS['itens']);

        $resultadoFinal = is_array($resultado) ? ($resultado['resultado'] ?? '') : '';

        if ($resultadoFinal === 'referenciado_na_hora') {
            registrar_log_admin($admin['email'], 'desativar_item_forcado', "id=$id (pedido criado durante a remoção)");
            json_response(['sucesso' => true, 'resultado' => 'desativado_por_seguranca',
                'mensagem' => 'Um pedido para este item foi registrado bem agora — ele foi desativado em vez de removido.']);
        }
        if ($resultadoFinal === 'removido') {
            if (!empty($resultado['imagem'])) limpar_imagem_se_orfa($resultado['imagem']);
            registrar_log_admin($admin['email'], 'remover_item', "id=$id");
            json_response(['sucesso' => true, 'resultado' => 'removido', 'mensagem' => 'Item removido definitivamente.']);
        }

        json_response(['sucesso' => false, 'mensagem' => 'Item não encontrado ou não foi possível removê-lo.'], 404);
    }

    json_response(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
