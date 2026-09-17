<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/*
 * Endpoint público (sem login) que serve o conteúdo editorial da página
 * inicial — carrossel, eventos, projetos em destaque — editável pelo
 * painel admin em /admin/conteudo.html. Cacheável como o ranking: nada
 * aqui é pessoal, é o mesmo conteúdo pra qualquer visitante.
 */

function ordenar_por_ordem(array $linhas): array {
    usort($linhas, fn($a, $b) => ((int)($a['ordem'] ?? 0)) <=> ((int)($b['ordem'] ?? 0)));
    return $linhas;
}

function apenas_ativos(array $linhas): array {
    return array_values(array_filter($linhas, fn($l) => trim((string)($l['ativo'] ?? '')) === '1'));
}

responder_com_cache([CARROSSEL_CSV, EVENTOS_CSV, PROJETOS_CSV], __FILE__, function () {
    $carrossel = ordenar_por_ordem(apenas_ativos(csv_assoc(CARROSSEL_CSV)));
    $eventos = ordenar_por_ordem(apenas_ativos(csv_assoc(EVENTOS_CSV)));
    $projetos = ordenar_por_ordem(apenas_ativos(csv_assoc(PROJETOS_CSV)));

    return [
        'carrossel' => array_map(fn($c) => [
            'imagem' => $c['imagem'] ?? '', 'legenda' => $c['legenda'] ?? '',
        ], $carrossel),
        'eventos' => array_map(fn($e) => [
            'id' => $e['id'] ?? '', 'tag' => $e['tag'] ?? '', 'titulo' => $e['titulo'] ?? '',
            'descricao' => $e['descricao'] ?? '', 'rodape' => $e['rodape'] ?? '', 'link' => $e['link'] ?? '',
        ], $eventos),
        'projetos' => array_map(fn($p) => [
            'titulo' => $p['titulo'] ?? '', 'parceria' => $p['parceria'] ?? '', 'descricao' => $p['descricao'] ?? '',
        ], $projetos),
    ];
});
