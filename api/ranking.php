<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/*
 * Endpoint público de ranking (não exige login: o placar geral é
 * intencionalmente visível a qualquer visitante, como um mural da escola).
 *
 * Importante: mesmo sendo público, ele NUNCA expõe a matrícula, o e-mail
 * ou os campos internos (ganho/gasto brutos) de cada aluno — apenas o que
 * cada tela realmente precisa para desenhar o ranking. O nome também já
 * sai recortado (primeiro nome, ou só a primeira letra conforme
 * MOSTRAR_APENAS_PRIMEIRA_LETRA em config.php) — isso é feito aqui no
 * servidor, e não no navegador, porque um nome completo que saia da API
 * pode ser visto pelo DevTools mesmo que a tela mostre só parte dele.
 *
 * ?view=resumo   (padrão) -> usado pela página inicial: top 3 líderes por
 *                 saldo, top 3 "mestres das moedas" por gasto, e o total de
 *                 EcoCoins por turma. Resposta pequena, pensada para widgets.
 *                 Alunos/turmas com valor zero não aparecem.
 * ?view=completo -> usado pela página de ranking. Parâmetros opcionais:
 *                 - limite: quantos alunos devolver (máx. RANKING_LIMITE_MAXIMO,
 *                   sempre — mesmo que o parâmetro peça mais)
 *                 - busca: filtra pelo primeiro nome (ignorado se
 *                   MOSTRAR_APENAS_PRIMEIRA_LETRA estiver ligado — nesse
 *                   modo a busca fica desativada também no servidor). Se
 *                   RANKING_BUSCA_LIMITADA estiver ligado, a busca só
 *                   enxerga os alunos dentro do topo
 *                   max(RANKING_BUSCA_LIMITE_N, RANKING_LIMITE_MAXIMO) por
 *                   saldo, para que não dê para descobrir quem está fora
 *                   desse topo só testando termos de busca.
 */

function montar_lista_alunos(): array {
    $matriculasAtivas = null; // null = não precisa filtrar (aprovação manual desligada)
    if (EXIGIR_APROVACAO_CONTA) {
        $matriculasAtivas = [];
        foreach (csv_assoc(USERS_CSV) as $u) {
            if (conta_esta_ativa($u)) $matriculasAtivas[(string)$u['matricula']] = true;
        }
    }

    $anoAtual = ano_letivo_atual();
    $financeiro = RANKING_APENAS_ANO_ATUAL ? calcular_resumo_financeiro_do_ano($anoAtual) : calcular_resumo_financeiro();
    $historicoTurmas = carregar_historico_turmas_agrupado();

    $alunos = [];
    foreach (csv_assoc(USERS_CSV) as $u) {
        $matricula = (string)($u['matricula'] ?? '');
        $nomeCompleto = trim((string)($u['nome'] ?? ''));
        if ($matricula === '' || $nomeCompleto === '') continue;
        if ($matriculasAtivas !== null && !isset($matriculasAtivas[$matricula])) continue;

        $r = $financeiro[$matricula] ?? ['ganho' => 0.0, 'gasto' => 0.0, 'saldo' => 0.0];
        $turmaAtual = turma_atual_do_aluno($historicoTurmas[$matricula] ?? []);
        $alunos[] = [
            'nome'  => nome_publico($nomeCompleto),
            'turma' => (string)($turmaAtual['turma'] ?? ''),
            // Se a turma mais recente do aluno não é deste ano (ex.: ele
            // nunca foi realocado pra uma turma do ano corrente), ele conta
            // no ranking individual normalmente, mas fica de fora de
            // qualquer agrupamento/soma POR turma — turmas se repetem de
            // número ano a ano, então misturar anos diferentes sob o mesmo
            // número de turma juntaria duas turmas que não têm nada a ver.
            'turmaEsteAno' => $turmaAtual !== null && (int)$turmaAtual['ano'] === $anoAtual,
            'saldo' => $r['saldo'],
            'gasto' => $r['gasto'],
        ];
    }
    return $alunos;
}

$view = (string)($_GET['view'] ?? 'resumo');
$arquivosDeDados = [USERS_CSV, ACTIVITIES_CSV, ORDERS_CSV, TURMAS_HISTORICO_CSV];

if ($view === 'completo') {
    responder_com_cache($arquivosDeDados, __FILE__, function () {
        $alunos = montar_lista_alunos();

        $comSaldo = array_values(array_filter($alunos, fn($a) => $a['saldo'] > 0));
        usort($comSaldo, fn($a, $b) => $b['saldo'] <=> $a['saldo']);

        $busca = MOSTRAR_APENAS_PRIMEIRA_LETRA ? '' : mb_strtolower(trim((string)($_GET['busca'] ?? '')));

        $base = $comSaldo;
        if ($busca !== '' && RANKING_BUSCA_LIMITADA) {
            $tamanhoPool = max(RANKING_BUSCA_LIMITE_N, RANKING_LIMITE_MAXIMO);
            $base = array_slice($comSaldo, 0, $tamanhoPool);
        }
        if ($busca !== '') {
            $base = array_values(array_filter($base, fn($a) => str_contains(mb_strtolower($a['nome']), $busca)));
        }

        $limite = (int)($_GET['limite'] ?? RANKING_LIMITE_PADRAO);
        if ($limite <= 0) $limite = RANKING_LIMITE_PADRAO;
        $limite = min($limite, RANKING_LIMITE_MAXIMO); // nunca mais que o teto, não importa o que o parâmetro peça

        return array_map(
            fn($a) => ['nome' => $a['nome'], 'turma' => $a['turma'], 'turmaEsteAno' => $a['turmaEsteAno'], 'saldo' => round($a['saldo'], 2)],
            array_slice($base, 0, $limite)
        );
    });
}

responder_com_cache($arquivosDeDados, __FILE__, function () {
    $alunos = montar_lista_alunos();

    $porSaldo = array_values(array_filter($alunos, fn($a) => $a['saldo'] > 0));
    usort($porSaldo, fn($a, $b) => $b['saldo'] <=> $a['saldo']);
    $lideres = array_slice(array_map(
        fn($a) => ['nome' => $a['nome'], 'turma' => $a['turma'], 'valor' => round($a['saldo'], 2)],
        $porSaldo
    ), 0, 3);

    $porGasto = array_values(array_filter($alunos, fn($a) => $a['gasto'] > 0));
    usort($porGasto, fn($a, $b) => $b['gasto'] <=> $a['gasto']);
    $mestres = array_slice(array_map(
        fn($a) => ['nome' => $a['nome'], 'turma' => $a['turma'], 'valor' => round($a['gasto'], 2)],
        $porGasto
    ), 0, 3);

    $totaisPorTurma = [];
    foreach ($alunos as $a) {
        // Turmas se repetem de número ano a ano — sem este filtro, um
        // aluno que não foi realocado desde o ano passado ficaria somado
        // sob o número de uma turma que já é outra, este ano.
        if (!$a['turmaEsteAno']) continue;
        $turma = $a['turma'] !== '' ? $a['turma'] : 'Sem turma';
        $totaisPorTurma[$turma] = ($totaisPorTurma[$turma] ?? 0.0) + $a['saldo'];
    }
    $totaisPorTurma = array_filter($totaisPorTurma, fn($total) => $total > 0);
    arsort($totaisPorTurma);
    $turmas = [];
    foreach (array_slice($totaisPorTurma, 0, 5, true) as $turma => $total) {
        $turmas[] = ['turma' => $turma, 'total' => round($total, 2)];
    }

    return ['lideres' => $lideres, 'mestres' => $mestres, 'turmas' => $turmas];
});
