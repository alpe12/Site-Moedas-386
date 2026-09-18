<?php
declare(strict_types=1);

// ============================================================
// CÁLCULO DE SALDO — SEM EFEITOS COLATERAIS
// Funções puras pra calcular ganho/gasto/saldo a partir de
// atividades.csv + pedidos_loja.csv — nunca existe um "saldo" gravado em
// lugar nenhum, é sempre recalculado na hora (veja o comentário sobre isso
// em api/_bootstrap.php), pra nunca ficar dessincronizado do extrato que o
// explica.
//
// Compartilhado entre o site público e o painel admin isolado pelo mesmo
// motivo de api/turma_utils.php: são os MESMOS dados e a MESMA regra de
// expiração anual, então essa conta não pode divergir entre os dois lados
// (ex.: o painel admin mostrando "everything about a student" com um saldo
// diferente do que o próprio aluno vê no perfil seria pior que não mostrar
// saldo nenhum). Por isso as funções que leem CSV recebem os caminhos dos
// arquivos como parâmetro (sufixo "_de") em vez de usar as constantes
// ACTIVITIES_CSV/ORDERS_CSV do site público diretamente — o painel admin
// tem suas próprias constantes (SITE_ACTIVITIES_CSV/SITE_ORDERS_CSV) pros
// mesmos arquivos físicos, por isolamento. api/_bootstrap.php mantém
// wrappers com os nomes/assinaturas originais (sem sufixo, sem parâmetro
// de arquivo) pra todo o código existente do site público continuar
// funcionando sem nenhuma mudança.
//
// Depende só de EXPIRAR_SALDO_ANO_NOVO (compartilhada via
// config_compartilhada.php, como EXIGIR_APROVACAO_CONTA/
// EXIGIR_APROVACAO_TROCA_TURMA) e de ano_letivo_atual() (turma_utils.php)
// — nada de sessão, nada de cache, nada específico de um site só.
// ============================================================

if (!defined('EXPIRAR_SALDO_ANO_NOVO')) {
    define('EXPIRAR_SALDO_ANO_NOVO', false);
}

/** Extrai o ano (inteiro) de uma data em qualquer formato usado no site (Y-m-d ou ISO 8601 completo). */
function ano_da_data(string $data): int {
    $timestamp = strtotime($data);
    return $timestamp !== false ? (int)date('Y', $timestamp) : ano_letivo_atual();
}

/**
 * Dado ganho/gasto já agrupados por ano (ano => ['ganho'=>float,'gasto'=>float]),
 * aplica a expiração anual se EXPIRAR_SALDO_ANO_NOVO estiver ligada e devolve
 * o total final. Não lê nenhum arquivo — quem chama já precisa ter montado
 * $porAno. Isso permite reaproveitar a mesma regra de expiração tanto em
 * calcular_saldo_aluno() (que lê os CSVs direto) quanto dentro do lock de
 * compra em api/loja.php, onde reler pedidos_loja.csv de novo faria o
 * processo esperar por um lock que ele mesmo já está segurando (trava até
 * dar timeout) — ali o chamador já tem os dados em mãos e só passa pra cá.
 *
 * Sem EXPIRAR_SALDO_ANO_NOVO: soma tudo, sem distinguir ano (comportamento
 * de sempre). Com a flag ligada: percorre os anos em ordem; sempre que o
 * saldo levado de um ano pro próximo é positivo, uma linha virtual
 * "Expirado" (nunca gravada em CSV nenhum) desconta esse valor antes de
 * somar o ano seguinte — na prática, zera o que não foi gasto.
 */
function aplicar_expiracao_anual(array $porAno): array {
    if (!$porAno) return ['ganho' => 0.0, 'gasto' => 0.0, 'saldo' => 0.0, 'expiracoes' => []];
    ksort($porAno);

    if (!EXPIRAR_SALDO_ANO_NOVO) {
        $ganho = array_sum(array_map(fn($v) => $v['ganho'] ?? 0.0, $porAno));
        $gasto = array_sum(array_map(fn($v) => $v['gasto'] ?? 0.0, $porAno));
        return ['ganho' => $ganho, 'gasto' => $gasto, 'saldo' => $ganho - $gasto, 'expiracoes' => []];
    }

    $saldoCarregado = 0.0;
    $ganhoTotal = 0.0;
    $gastoTotal = 0.0;
    $expiracoes = [];
    $anoAnterior = null;

    foreach ($porAno as $ano => $valores) {
        if ($anoAnterior !== null && $saldoCarregado > 0.0) {
            $anoExpiracao = $anoAnterior + 1;
            $expiracoes[] = ['ano' => $anoExpiracao, 'valor' => -$saldoCarregado, 'data' => $anoExpiracao . '-01-01'];
            $ganhoTotal -= $saldoCarregado;
            $saldoCarregado = 0.0;
        }
        $ganhoAno = $valores['ganho'] ?? 0.0;
        $gastoAno = $valores['gasto'] ?? 0.0;
        $ganhoTotal += $ganhoAno;
        $gastoTotal += $gastoAno;
        $saldoCarregado += $ganhoAno - $gastoAno;
        $anoAnterior = (int)$ano;
    }

    return ['ganho' => $ganhoTotal, 'gasto' => $gastoTotal, 'saldo' => $ganhoTotal - $gastoTotal, 'expiracoes' => $expiracoes];
}

/**
 * Calcula ganho, gasto e saldo de TODOS os alunos de uma vez, já com a
 * expiração anual aplicada se EXPIRAR_SALDO_ANO_NOVO estiver ligada (veja
 * aplicar_expiracao_anual()). Devolve um mapa
 * matricula => ['ganho'=>float,'gasto'=>float,'saldo'=>float]. Alunos sem
 * nenhuma atividade nem pedido simplesmente não aparecem aqui — trate a
 * ausência como zero. Este é o saldo "de verdade" (usado na loja e no
 * perfil) — para o saldo usado no RANKING, veja
 * calcular_resumo_financeiro_do_ano_de(), que é uma métrica separada.
 */
function calcular_resumo_financeiro_de(string $atividadesCsv, string $pedidosCsv): array {
    $porAlunoAno = [];
    foreach (csv_assoc($atividadesCsv) as $a) {
        $matriculas = matriculas_da_linha((string)($a['matriculas'] ?? ''));
        if (!$matriculas) continue;
        $valor = normalize_number((string)($a['valor'] ?? '0'));
        $ano = ano_da_data((string)($a['data'] ?? ''));
        foreach ($matriculas as $matricula) {
            $porAlunoAno[$matricula][$ano]['ganho'] = ($porAlunoAno[$matricula][$ano]['ganho'] ?? 0.0) + $valor;
        }
    }
    foreach (csv_assoc($pedidosCsv) as $p) {
        if (trim((string)($p['status'] ?? '')) === 'Cancelado') continue;
        $matricula = trim((string)($p['matricula'] ?? ''));
        if ($matricula === '') continue;
        $ano = ano_da_data((string)($p['data'] ?? ''));
        $porAlunoAno[$matricula][$ano]['gasto'] = ($porAlunoAno[$matricula][$ano]['gasto'] ?? 0.0) + normalize_number((string)($p['valor'] ?? '0'));
    }

    $resumo = [];
    foreach ($porAlunoAno as $matricula => $porAno) {
        $r = aplicar_expiracao_anual($porAno);
        $resumo[$matricula] = ['ganho' => $r['ganho'], 'gasto' => $r['gasto'], 'saldo' => $r['saldo']];
    }
    return $resumo;
}

/**
 * Ganho/gasto/saldo de todos os alunos considerando SÓ atividades e
 * pedidos de um ano específico — ignora por completo qualquer saldo
 * carregado de anos anteriores (não tem nada a ver com
 * EXPIRAR_SALDO_ANO_NOVO/aplicar_expiracao_anual(): aqui os outros anos nem
 * entram na conta, não é que o saldo deles "expirou"). Usado só pelo
 * ranking quando RANKING_APENAS_ANO_ATUAL está ligada — veja api/ranking.php.
 */
function calcular_resumo_financeiro_do_ano_de(string $atividadesCsv, string $pedidosCsv, int $ano): array {
    $resumo = [];
    foreach (csv_assoc($atividadesCsv) as $a) {
        if (ano_da_data((string)($a['data'] ?? '')) !== $ano) continue;
        $matriculas = matriculas_da_linha((string)($a['matriculas'] ?? ''));
        if (!$matriculas) continue;
        $valor = normalize_number((string)($a['valor'] ?? '0'));
        foreach ($matriculas as $matricula) {
            $resumo[$matricula] ??= ['ganho' => 0.0, 'gasto' => 0.0];
            $resumo[$matricula]['ganho'] += $valor;
        }
    }
    foreach (csv_assoc($pedidosCsv) as $p) {
        if (trim((string)($p['status'] ?? '')) === 'Cancelado') continue;
        if (ano_da_data((string)($p['data'] ?? '')) !== $ano) continue;
        $matricula = trim((string)($p['matricula'] ?? ''));
        if ($matricula === '') continue;
        $resumo[$matricula] ??= ['ganho' => 0.0, 'gasto' => 0.0];
        $resumo[$matricula]['gasto'] += normalize_number((string)($p['valor'] ?? '0'));
    }
    foreach ($resumo as &$r) $r['saldo'] = $r['ganho'] - $r['gasto'];
    unset($r);
    return $resumo;
}

/**
 * Agrupa ganho/gasto de UM aluno por ano (lendo os CSVs) — a peça que
 * calcular_saldo_aluno_de() passa pra aplicar_expiracao_anual().
 */
function agrupar_financeiro_por_ano_de(string $atividadesCsv, string $pedidosCsv, string $matricula): array {
    $porAno = [];
    foreach (csv_assoc($atividadesCsv) as $a) {
        $matriculas = matriculas_da_linha((string)($a['matriculas'] ?? ''));
        if (!in_array($matricula, $matriculas, true)) continue;
        $ano = ano_da_data((string)($a['data'] ?? ''));
        $porAno[$ano]['ganho'] = ($porAno[$ano]['ganho'] ?? 0.0) + normalize_number((string)($a['valor'] ?? '0'));
    }
    foreach (csv_assoc($pedidosCsv) as $p) {
        if ((string)($p['matricula'] ?? '') !== $matricula) continue;
        if (trim((string)($p['status'] ?? '')) === 'Cancelado') continue;
        $ano = ano_da_data((string)($p['data'] ?? ''));
        $porAno[$ano]['gasto'] = ($porAno[$ano]['gasto'] ?? 0.0) + normalize_number((string)($p['valor'] ?? '0'));
    }
    return $porAno;
}

/**
 * Mesma conta de calcular_resumo_financeiro_de(), mas só para um aluno —
 * inclui 'expiracoes' (a lista de linhas virtuais "Expirado" que valeram
 * pra ele, se EXPIRAR_SALDO_ANO_NOVO estiver ligada) pra quem quiser
 * mostrar no extrato (veja api/profile.php e admin/api/aluno_detalhe.php).
 */
function calcular_saldo_aluno_de(string $atividadesCsv, string $pedidosCsv, string $matricula): array {
    return aplicar_expiracao_anual(agrupar_financeiro_por_ano_de($atividadesCsv, $pedidosCsv, $matricula));
}
