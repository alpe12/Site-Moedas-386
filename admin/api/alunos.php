<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

// Busca de alunos por matrícula, nome ou turma (contém, sem diferenciar
// maiúsculas/minúsculas) — usado na tela de atividades pra achar quem
// adicionar. Devolve no máximo 30 resultados por vez pra manter a
// resposta leve; refine a busca se precisar de mais precisão.
$busca = mb_strtolower(trim((string)($_GET['busca'] ?? '')));
if ($busca === '') json_response([]);

$resultados = [];
$historicoTurmas = carregar_historico_turmas_agrupado_admin();
foreach (csv_assoc(SITE_USERS_CSV) as $u) {
    $matricula = (string)($u['matricula'] ?? '');
    $nome = (string)($u['nome'] ?? '');
    $turmaAtual = turma_atual_do_aluno($historicoTurmas[$matricula] ?? []);
    $turma = (string)($turmaAtual['turma'] ?? '');

    $alvo = mb_strtolower($matricula . ' ' . $nome . ' ' . $turma);
    if (!str_contains($alvo, $busca)) continue;

    $resultados[] = ['matricula' => $matricula, 'nome' => $nome, 'turma' => $turma];
    if (count($resultados) >= 30) break;
}

json_response($resultados);
