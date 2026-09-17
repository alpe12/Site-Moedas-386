<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

const TIPOS_CONTEUDO = [
    'carrossel' => ['arquivo' => SITE_CARROSSEL_CSV, 'headers' => 'carrossel', 'prefixo' => 'car'],
    'eventos'   => ['arquivo' => SITE_EVENTOS_CSV, 'headers' => 'eventos', 'prefixo' => 'evt'],
    'projetos'  => ['arquivo' => SITE_PROJETOS_CSV, 'headers' => 'projetos', 'prefixo' => 'proj'],
];

/** Campos de texto livre esperados por tipo (fora id/ordem, tratados à parte). */
const CAMPOS_POR_TIPO = [
    'carrossel' => ['imagem', 'legenda'],
    'eventos'   => ['tag', 'titulo', 'descricao', 'rodape', 'link'],
    'projetos'  => ['titulo', 'parceria', 'descricao'],
];

$tipo = (string)($_GET['tipo'] ?? '');
$data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = request_json();
    $tipo = (string)($data['tipo'] ?? '');
}

if (!isset(TIPOS_CONTEUDO[$tipo])) {
    json_response(['sucesso' => false, 'mensagem' => 'Tipo de conteúdo inválido.'], 400);
}
$info = TIPOS_CONTEUDO[$tipo];
$arquivo = $info['arquivo'];
$headers = SITE_CSV_HEADERS[$info['headers']];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lista = csv_assoc($arquivo);
    usort($lista, fn($a, $b) => ((int)($a['ordem'] ?? 0)) <=> ((int)($b['ordem'] ?? 0)));
    $lista = array_map(function ($linha) {
        $linha['ordem'] = (int)($linha['ordem'] ?? 0);
        $linha['ativo'] = trim((string)($linha['ativo'] ?? '')) === '1';
        return $linha;
    }, $lista);
    json_response(array_values($lista));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($data['acao'] ?? '');

    if ($acao === 'remover') {
        $id = trim((string)($data['id'] ?? ''));
        $resultado = with_locked_csv($arquivo, function (array $linhas) use ($id) {
            $imagemRemovida = '';
            foreach ($linhas as $l) {
                if ((string)($l['id'] ?? '') === $id) { $imagemRemovida = (string)($l['imagem'] ?? ''); break; }
            }
            $antes = count($linhas);
            $linhas = array_values(array_filter($linhas, fn($l) => (string)($l['id'] ?? '') !== $id));
            if (count($linhas) === $antes) return ['return' => ['erro' => 'nao_encontrado']];
            return ['rows' => $linhas, 'return' => ['ok' => true, 'imagem' => $imagemRemovida]];
        }, $headers);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Não foi possível remover.'], 400);
        }
        if ($tipo === 'carrossel' && !empty($resultado['imagem'])) {
            limpar_imagem_se_orfa($resultado['imagem']);
        }
        registrar_log_admin($admin['email'], "remover_conteudo_$tipo", "id=$id");
        json_response(['sucesso' => true, 'mensagem' => 'Removido.']);
    }

    if ($acao === 'ativar' || $acao === 'desativar') {
        $id = trim((string)($data['id'] ?? ''));
        $novoValor = $acao === 'ativar' ? '1' : '0';

        $resultado = with_locked_csv($arquivo, function (array $linhas) use ($id, $novoValor) {
            $encontrado = false;
            foreach ($linhas as &$l) {
                if ((string)($l['id'] ?? '') !== $id) continue;
                $l['ativo'] = $novoValor;
                $encontrado = true;
            }
            unset($l);
            if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
            return ['rows' => $linhas, 'return' => ['ok' => true]];
        }, $headers);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Item não encontrado.'], 404);
        }
        registrar_log_admin($admin['email'], "{$acao}_conteudo_$tipo", "id=$id");
        json_response(['sucesso' => true, 'mensagem' => $acao === 'ativar' ? 'Ativado.' : 'Desativado.']);
    }

    if ($acao === 'duplicar') {
        $id = trim((string)($data['id'] ?? ''));
        $novoId = $info['prefixo'] . '-' . bin2hex(random_bytes(4));

        $resultado = with_locked_csv($arquivo, function (array $linhas) use ($id, $novoId, $headers) {
            $original = null;
            foreach ($linhas as $l) {
                if ((string)($l['id'] ?? '') === $id) { $original = $l; break; }
            }
            if ($original === null) return ['return' => ['erro' => 'nao_encontrado']];

            $copia = $original;
            $copia['id'] = $novoId;
            // Sempre nasce desativada — o admin decide quando publicar a cópia.
            $copia['ativo'] = '0';
            $linhas[] = $copia;
            return ['rows' => $linhas, 'return' => ['ok' => true]];
        }, $headers);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Não foi possível duplicar.'], 400);
        }
        registrar_log_admin($admin['email'], "duplicar_conteudo_$tipo", "id original=$id; novo id=$novoId (criado desativado)");
        json_response(['sucesso' => true, 'mensagem' => 'Duplicado — a cópia está desativada, edite e ative quando quiser.', 'id' => $novoId]);
    }

    // acao === 'criar' ou 'editar'
    $campos = CAMPOS_POR_TIPO[$tipo];
    $valores = [];
    foreach ($campos as $campo) {
        $bruto = trim((string)($data[$campo] ?? ''));
        if ($campo === 'imagem' || $campo === 'link') {
            // Campos que são URL: valida formato só se preenchido (link de evento pode ficar vazio).
            if ($bruto !== '' && !filter_var($bruto, FILTER_VALIDATE_URL) && !str_starts_with($bruto, 'Imagens/') && !str_starts_with($bruto, 'uploads/')) {
                json_response(['sucesso' => false, 'mensagem' => "Campo \"$campo\" precisa ser uma URL válida (ou um caminho começando com Imagens/ ou uploads/)."], 400);
            }
            $valores[$campo] = $bruto;
            continue;
        }
        $sanitizado = sanitize_texto_livre($bruto, 500);
        if ($sanitizado === null) {
            json_response(['sucesso' => false, 'mensagem' => "Campo \"$campo\" inválido ou vazio demais."], 400);
        }
        $valores[$campo] = $sanitizado;
    }
    $ordem = (int)($data['ordem'] ?? 0);

    if ($acao === 'criar') {
        $id = $info['prefixo'] . '-' . bin2hex(random_bytes(4));
        $linha = array_merge(['id' => $id], $valores, ['ordem' => (string)$ordem, 'ativo' => '1']);
        $ok = append_csv($arquivo, array_map(fn($h) => $linha[$h] ?? '', $headers), $headers);
        if (!$ok) json_response(['sucesso' => false, 'mensagem' => 'Não foi possível salvar.'], 500);

        registrar_log_admin($admin['email'], "criar_conteudo_$tipo", "id=$id");
        json_response(['sucesso' => true, 'mensagem' => 'Criado.', 'id' => $id]);
    }

    if ($acao === 'editar') {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') json_response(['sucesso' => false, 'mensagem' => 'Item inválido.'], 400);

        $resultado = with_locked_csv($arquivo, function (array $linhas) use ($id, $valores, $ordem) {
            $encontrado = false;
            $linhaAntiga = null;
            foreach ($linhas as &$l) {
                if ((string)($l['id'] ?? '') !== $id) continue;
                $linhaAntiga = $l;
                foreach ($valores as $campo => $valor) $l[$campo] = $valor;
                $l['ordem'] = (string)$ordem;
                $encontrado = true;
            }
            unset($l);
            if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
            return ['rows' => $linhas, 'return' => ['ok' => true, 'antiga' => $linhaAntiga]];
        }, $headers);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Não foi possível editar.'], 400);
        }
        $novaLinha = array_merge($valores, ['ordem' => (string)$ordem]);
        $diff = construir_diff($resultado['antiga'] ?? [], $novaLinha, array_merge(array_keys($valores), ['ordem']));
        if ($tipo === 'carrossel') {
            $imagemAntiga = (string)($resultado['antiga']['imagem'] ?? '');
            $imagemNova = (string)($valores['imagem'] ?? '');
            if ($imagemAntiga !== '' && $imagemAntiga !== $imagemNova) {
                limpar_imagem_se_orfa($imagemAntiga);
            }
        }
        registrar_log_admin($admin['email'], "editar_conteudo_$tipo", "id=$id; $diff");
        json_response(['sucesso' => true, 'mensagem' => 'Atualizado.']);
    }

    json_response(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
