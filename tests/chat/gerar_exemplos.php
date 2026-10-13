<?php
// Gera um relatório PDF e um XLSX de EXEMPLO com dados FICTÍCIOS, passando pelo
// mesmo caminho do chat (fontes → validação → render).  Uso:
//   php tests/chat/gerar_exemplos.php            → docs/chat/exemplos/
// Não usa dados reais do ERP (os arquivos vão para o repositório).

require __DIR__ . '/bootstrap.php';

$pdo      = dbPDO();
$conversa = str_repeat('e', 32);
limparDadosTeste($pdo);

$meses = [['2026-04', 41, 812340, 2410500.10], ['2026-05', 47, 905120, 2688730.45], ['2026-06', 44, 876400, 2541980.00],
          ['2026-07', 39, 785727, 2639041.05], ['2026-08', 52, 1100636, 3175417.44], ['2026-09', 49, 1032085, 2969518.25]];
$fMes = chatFonteSalvar($pdo, $conversa, UID_A, 'resumo_faturamento', [
    'periodo_faturamento' => '2026-04-01 a 2026-09-30', 'agrupado_por' => 'mes', 'observacao' => 'Dados fictícios de exemplo.',
    'grupos' => array_map(fn($m) => ['grupo' => $m[0], 'pedidos' => $m[1], 'pecas' => $m[2], 'valor' => $m[3], 'ticket_medio' => round($m[3] / $m[1], 2)], $meses),
]);
$fTipo = chatFonteSalvar($pdo, $conversa, UID_A, 'resumo_faturamento', [
    'periodo_faturamento' => '2026-09-01 a 2026-09-30', 'agrupado_por' => 'tipo', 'observacao' => 'Dados fictícios de exemplo.',
    'grupos' => [
        ['grupo' => 'Sacaria', 'pedidos' => 36, 'pecas' => 1014278, 'valor' => 1824461.65, 'ticket_medio' => 50679.49],
        ['grupo' => 'Bag', 'pedidos' => 13, 'pecas' => 17807, 'valor' => 1145056.60, 'ticket_medio' => 88081.28],
    ],
]);
$reps = ['Representante Exemplo A' => 1130693.80, 'Representante Exemplo B' => 900571.59, 'Representante Exemplo C' => 334301.61,
         'Representante Exemplo D' => 328840.25, 'Representante Exemplo E' => 175110.00, 'Representante Exemplo F' => 100001.00];
$fRep = chatFonteSalvar($pdo, $conversa, UID_A, 'resumo_faturamento', [
    'periodo_faturamento' => '2026-09-01 a 2026-09-30', 'agrupado_por' => 'representante', 'observacao' => 'Dados fictícios de exemplo.',
    'grupos' => array_map(fn($n, $v) => ['grupo' => $n, 'pedidos' => (int) round($v / 52000) + 1, 'pecas' => (int) round($v / 3), 'valor' => $v,
        'ticket_medio' => round($v / ((int) round($v / 52000) + 1), 2)], array_keys($reps), $reps),
]);
$pedidos = [];
for ($i = 0; $i < 15; $i++) {
    $pedidos[] = ['pedido' => 4040 - $i, 'emissao' => sprintf('2026-09-%02d', 30 - $i * 2), 'finalizacao' => sprintf('2026-09-%02d', 30 - $i * 2),
        'operacao' => 'Venda', 'situacao' => $i < 4 ? 'Aguardando Produção' : 'Faturado', 'cliente' => sprintf('Cliente Exemplo %02d', $i + 1),
        'cidade' => ['Matão', 'Rio Claro', 'Araraquara', 'São Carlos'][$i % 4], 'representante' => 'Representante Exemplo ' . 'ABC'[$i % 3],
        'valor' => round(18000 + ($i * 7919 % 97000) + 0.45, 2)];
}
$fPed = chatFonteSalvar($pdo, $conversa, UID_A, 'listar_pedidos', [
    'periodo_emissao' => '2026-09-01 a 2026-09-30', 'total_pedidos' => 15, 'valor_total' => round(array_sum(array_column($pedidos, 'valor')), 2),
    'listados' => 15, 'aviso' => null, 'pedidos' => $pedidos,
]);

// Pedido de relatório como a IA enviaria (só textos + referências às fontes).
$pedido = [
    'titulo'  => 'Faturamento — 2º e 3º trimestres de 2026',
    'periodo' => 'Abril a setembro de 2026 (dados fictícios de exemplo)',
    'formato' => 'pdf',
    'resumo'  => "Relatório de exemplo gerado pelo Assistente DOMBAG com dados fictícios.\n\nO faturamento mensal ficou acima de R$ 2,4 milhões em todos os meses do período, com o maior valor em agosto. Em setembro, a Sacaria concentrou a maior parte do valor faturado.",
    'indicadores' => [
        ['fonte' => $fMes, 'campo' => 'total_valor'], ['fonte' => $fMes, 'campo' => 'total_pedidos'],
        ['fonte' => $fMes, 'campo' => 'ticket_medio'], ['fonte' => $fMes, 'campo' => 'total_pecas'],
    ],
    'secoes' => [
        ['subtitulo' => 'Evolução mensal', 'texto' => 'Valor faturado por mês, pela data de finalização da venda.',
         'grafico' => ['tipo' => 'linha', 'fonte' => $fMes, 'rotulos' => 'grupo', 'valores' => 'valor'],
         'tabela' => ['fonte' => $fMes]],
        ['subtitulo' => 'Participação por tipo de produto — setembro',
         'grafico' => ['tipo' => 'pizza', 'fonte' => $fTipo, 'rotulos' => 'grupo', 'valores' => 'valor'],
         'tabela' => ['fonte' => $fTipo, 'colunas' => ['grupo', 'pedidos', 'pecas', 'valor']]],
        ['subtitulo' => 'Faturamento por representante — setembro',
         'grafico' => ['tipo' => 'barra', 'fonte' => $fRep, 'rotulos' => 'grupo', 'valores' => 'valor']],
        ['subtitulo' => 'Pedidos de setembro', 'tabela' => ['fonte' => $fPed]],
    ],
];

$saida = dirname(__DIR__, 2) . '/docs/chat/exemplos';
if (!is_dir($saida)) {
    mkdir($saida, 0777, true);
}
$quando = new DateTimeImmutable('2026-10-09 15:30');
foreach (['pdf', 'xlsx'] as $fmt) {
    [$modelo, $erro] = chatMontarRelatorio($pdo, $conversa, UID_A, ['formato' => $fmt] + $pedido);
    if ($erro) {
        fwrite(STDERR, "erro: {$erro}\n");
        exit(1);
    }
    $bytes = $fmt === 'pdf' ? chatRenderPdf($modelo, 'Usuário de Teste', $quando) : chatRenderXlsx($modelo, 'Usuário de Teste', $quando);
    file_put_contents("{$saida}/relatorio-exemplo.{$fmt}", $bytes);
    echo "gerado: docs/chat/exemplos/relatorio-exemplo.{$fmt} (" . number_format(strlen($bytes) / 1024, 1) . " KB)\n";
}

// Exemplo "sem dados": consulta vazia → relatório diz isso.
$fVazio = chatFonteSalvar($pdo, $conversa, UID_A, 'listar_pedidos', [
    'periodo_emissao' => '2026-12-01 a 2026-12-31', 'total_pedidos' => 0, 'valor_total' => 0, 'listados' => 0, 'aviso' => null, 'pedidos' => [],
]);
[$modelo] = chatMontarRelatorio($pdo, $conversa, UID_A, ['titulo' => 'Pedidos de dezembro de 2026', 'formato' => 'pdf',
    'secoes' => [['subtitulo' => 'Pedidos', 'tabela' => ['fonte' => $fVazio]]]]);
file_put_contents("{$saida}/relatorio-sem-dados.pdf", chatRenderPdf($modelo, 'Usuário de Teste', $quando));
echo "gerado: docs/chat/exemplos/relatorio-sem-dados.pdf\n";

limparDadosTeste($pdo);
