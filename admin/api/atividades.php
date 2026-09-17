<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

/** matricula => nome — só pra resolver nomes e validar matrículas existentes. */
function mapa_nomes_alunos(): array {
    $mapa = [];
    foreach (csv_assoc(SITE_USERS_CSV) as $u) {
        $mapa[(string)$u['matricula']] = (string)$u['nome'];
    }
    return $mapa;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $nomes = mapa_nomes_alunos();
    $historicoTurmas = carregar_historico_turmas_agrupado_admin();
    $lista = [];
    foreach (csv_assoc(SITE_ACTIVITIES_CSV) as $a) {
        $matriculas = matriculas_da_linha((string)($a['matriculas'] ?? ''));
        $dataAtividade = (string)($a['data'] ?? '');
        $alunosResolvidos = array_map(function ($m) use ($nomes, $historicoTurmas, $dataAtividade) {
            $historicoDoAluno = $historicoTurmas[$m] ?? [];
            // A turma de QUANDO a atividade foi lançada, não a atual — o
            // aluno pode ter trocado de turma depois. turmaHistorico vai
            // completo pro ícone de histórico em script_admin_atividades.js.
            $turmaNaEpoca = formatar_registro_turma(turma_na_data($historicoDoAluno, $dataAtividade));
            return [
                'matricula' => $m,
                'nome' => $nomes[$m] ?? '(aluno não encontrado)',
                'turma' => $turmaNaEpoca['turma'] ?? '',
                'turmaHistorico' => array_map('formatar_registro_turma', $historicoDoAluno),
            ];
        }, $matriculas);

        $lista[] = [
            'id' => $a['id'] ?? '',
            'atividade' => $a['atividade'] ?? '',
            'valor' => normalize_number((string)($a['valor'] ?? '0')),
            'data' => $a['data'] ?? '',
            'alunos' => $alunosResolvidos,
        ];
    }
    usort($lista, fn($x, $y) => strcmp((string)$y['data'], (string)$x['data']));
    json_response($lista);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = request_json();
    $acao = (string)($data['acao'] ?? '');

    if ($acao === 'remover') {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') json_response(['sucesso' => false, 'mensagem' => 'Atividade inválida.'], 400);

        $resultado = with_locked_csv(SITE_ACTIVITIES_CSV, function (array $linhas) use ($id) {
            $antes = count($linhas);
            $linhas = array_values(array_filter($linhas, fn($l) => (string)($l['id'] ?? '') !== $id));
            if (count($linhas) === $antes) return ['return' => ['erro' => 'nao_encontrada']];
            return ['rows' => $linhas, 'return' => ['ok' => true]];
        }, SITE_CSV_HEADERS['atividades']);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Não foi possível remover a atividade.'], 400);
        }

        registrar_log_admin($admin['email'], 'remover_atividade', "id=$id");
        json_response(['sucesso' => true, 'mensagem' => 'Atividade removida.']);
    }

    // acao === 'criar' ou 'editar'
    $atividadeNome = sanitize_plain_text((string)($data['atividade'] ?? ''), 120);
    $valor = normalize_number((string)($data['valor'] ?? ''));
    $dataAtividade = trim((string)($data['data'] ?? '')) ?: date('Y-m-d');
    $matriculas = array_values(array_unique(array_filter(array_map(
        fn($m) => trim((string)$m),
        is_array($data['matriculas'] ?? null) ? $data['matriculas'] : []
    ))));

    if ($atividadeNome === null) {
        json_response(['sucesso' => false, 'mensagem' => 'Nome da atividade inválido.'], 400);
    }
    if ($valor == 0.0) {
        json_response(['sucesso' => false, 'mensagem' => 'Informe um valor diferente de zero.'], 400);
    }
    if (!$matriculas) {
        json_response(['sucesso' => false, 'mensagem' => 'Adicione pelo menos um aluno.'], 400);
    }

    // Confere se todas as matrículas informadas realmente existem — evita
    // salvar uma atividade referenciando um aluno inexistente por engano.
    $alunosConhecidos = mapa_nomes_alunos();
    $desconhecidas = array_values(array_filter($matriculas, fn($m) => !isset($alunosConhecidos[$m])));
    if ($desconhecidas) {
        json_response(['sucesso' => false, 'mensagem' => 'Matrícula(s) não encontrada(s): ' . implode(', ', $desconhecidas)], 400);
    }

    $matriculasCampo = implode(';', $matriculas);

    if ($acao === 'criar') {
        $id = 'act-' . bin2hex(random_bytes(4));
        $ok = append_csv(SITE_ACTIVITIES_CSV, [$id, $matriculasCampo, $dataAtividade, $atividadeNome, number_format($valor, 2, '.', '')], SITE_CSV_HEADERS['atividades']);
        if (!$ok) json_response(['sucesso' => false, 'mensagem' => 'Não foi possível salvar a atividade.'], 500);

        registrar_log_admin($admin['email'], 'criar_atividade', "id=$id atividade=\"$atividadeNome\" valor=$valor alunos=" . count($matriculas));
        json_response(['sucesso' => true, 'mensagem' => 'Atividade criada.', 'id' => $id]);
    }

    if ($acao === 'editar') {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') json_response(['sucesso' => false, 'mensagem' => 'Atividade inválida.'], 400);

        $resultado = with_locked_csv(SITE_ACTIVITIES_CSV, function (array $linhas) use ($id, $matriculasCampo, $dataAtividade, $atividadeNome, $valor) {
            $encontrada = false;
            $linhaAntiga = null;
            foreach ($linhas as &$l) {
                if ((string)($l['id'] ?? '') !== $id) continue;
                $linhaAntiga = $l;
                $l['matriculas'] = $matriculasCampo;
                $l['data'] = $dataAtividade;
                $l['atividade'] = $atividadeNome;
                $l['valor'] = number_format($valor, 2, '.', '');
                $encontrada = true;
            }
            unset($l);
            if (!$encontrada) return ['return' => ['erro' => 'nao_encontrada']];
            return ['rows' => $linhas, 'return' => ['ok' => true, 'antiga' => $linhaAntiga]];
        }, SITE_CSV_HEADERS['atividades']);

        if (!is_array($resultado) || !empty($resultado['erro'])) {
            json_response(['sucesso' => false, 'mensagem' => 'Não foi possível editar a atividade.'], 400);
        }

        $nova = ['matriculas' => $matriculasCampo, 'data' => $dataAtividade, 'atividade' => $atividadeNome, 'valor' => number_format($valor, 2, '.', '')];
        $diff = construir_diff($resultado['antiga'] ?? [], $nova, ['atividade', 'valor', 'data', 'matriculas']);
        registrar_log_admin($admin['email'], 'editar_atividade', "id=$id; $diff");
        json_response(['sucesso' => true, 'mensagem' => 'Atividade atualizada.']);
    }

    json_response(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
