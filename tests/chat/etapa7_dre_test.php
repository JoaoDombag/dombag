<?php
// Visão das notas fiscais (DRE) e cruzamento com o Histórico de Vendas.

$pdo = dbPDO();
limparDadosTeste($pdo);
echo "\nNotas fiscais / DRE e conciliação\n";

teste('DRE: só gestão — representante vinculado nunca recebe as consultas de NF-e', function () {
    $rep = chatMontarEscopo(ctxTeste(UID_A, ['permissoes' => ['/relatorios/historico-faturamento']]), [['re_codigo' => 6, 're_nome' => 'X']]);
    confere(!in_array('faturamento_notas', $rep['liberadas'], true) && !in_array('conciliar_faturamento', $rep['liberadas'], true), 'representante recebeu visão gerencial');
    $grupo = chatMontarEscopo(ctxTeste(UID_A, ['permissoes' => ['/relatorios/historico-faturamento']]), []);
    confere(in_array('faturamento_notas', $grupo['liberadas'], true) && in_array('conciliar_faturamento', $grupo['liberadas'], true), 'grupo com a página deveria receber');
    $semPagina = chatMontarEscopo(ctxTeste(UID_A, ['permissoes' => ['/yzidro/consulta']]), []);
    confere(!in_array('conciliar_faturamento', $semPagina['liberadas'], true), 'sem a página do Histórico não recebe');
    // e a execução recusa mesmo se a IA chamar
    $r = chatExecutarFerramenta('conciliar_faturamento', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30'], $rep, 'x', dbPDO());
    igual(false, $r['ok']);
});

teste('DRE: validação dos novos parâmetros', function () {
    [, $e] = chatValidarArgumentos('faturamento_notas', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30', 'agrupar_por' => 'cnpj']);
    confere($e !== null, 'agrupamento inválido aceito');
    [, $e] = chatValidarArgumentos('faturamento_notas', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30', 'todas_empresas' => 'sim']);
    confere($e !== null, 'boolean inválido aceito');
    [$ok, $e] = chatValidarArgumentos('faturamento_notas', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30', 'agrupar_por' => 'empresa', 'todas_empresas' => true]);
    igual(null, $e);
});

$pg = chatPG();
if (!$pg) {
    echo "  ⚠ ERP indisponível — testes com dados reais PULADOS\n";
    return;
}
$adm = chatMontarEscopo(ctxTeste(UID_A, ['admin' => true]), []);
$conv = str_repeat('d', 32);

teste('DRE (ERP real): totais de NF-e batem com a soma direta na tabela NOTA', function () use ($pdo, $pg, $adm, $conv) {
    $r = chatExecutarFerramenta('faturamento_notas', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30'], $adm, $conv, $pdo);
    confere($r['ok']);
    $g = $r['dados']['grupos'][0];
    $direto = chatPGConsulta($pg, "SELECT COUNT(*) AS n, SUM(N.NF_TOTAL) AS s, SUM(N.TOTAL_IPI) AS i, SUM(N.TOTAL_NOTA) AS t
        FROM NOTA N JOIN TIPO_OPERACAO TP ON TP.TO_CODIGO = N.TO_CODIGO
        WHERE N.EMP_CODIGO = 1 AND N.ENTRADA_SAIDA = 'S' AND N.NF_ESTADONFE = 100 AND TP.TO_DESCRICAO ILIKE 'VENDA%'
          AND N.NF_DATA_EMISSAO >= '2026-09-01' AND N.NF_DATA_EMISSAO < '2026-10-01'", [])[0];
    igual((int) $direto['n'], $g['notas']);
    igual(round((float) $direto['s'], 2), $g['valor_produtos']);
    igual(round((float) $direto['i'], 2), $g['ipi']);
    igual(round((float) $direto['t'], 2), $g['total_nota']);
    igual(round($g['valor_produtos'] + $g['ipi'], 2), $g['total_nota'], 'produtos + IPI = total da nota');
});

teste('conciliação (ERP real): números fecham entre si e com o Histórico', function () use ($pdo, $adm, $conv) {
    $r = chatExecutarFerramenta('conciliar_faturamento', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30'], $adm, $conv, $pdo);
    confere($r['ok']);
    $res = $r['dados']['resumo'];
    $hist = chatExecutarFerramenta('resumo_faturamento', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30'], $adm, $conv, $pdo);
    igual($hist['dados']['grupos'][0]['valor'], $res['historico_valor'], 'mesmo total do Histórico de Faturamento');
    igual($hist['dados']['grupos'][0]['pedidos'], $res['historico_pedidos']);
    $nfe = chatExecutarFerramenta('faturamento_notas', ['data_ini' => '2026-09-01', 'data_fim' => '2026-09-30'], $adm, $conv, $pdo);
    igual($nfe['dados']['grupos'][0]['total_nota'], $res['nfe_valor_com_ipi'], 'mesmo total de NF-e');
    // cada NF-e do período é: conciliada no período, sem pedido, ou casada com pedido de outro período
    $porTipo = array_count_values(array_column($r['dados']['divergencias'], 'tipo'));
    $nfeForaPar = count(array_filter($r['dados']['divergencias'], fn($x) => $x['tipo'] === 'Datas em períodos diferentes' && str_starts_with($x['documento'], 'NF-e')));
    igual($res['nfe_quantidade'], $res['conciliados'] + ($porTipo['NF-e sem pedido'] ?? 0) + $nfeForaPar, 'contagem de NF-e');
    igual(round($res['nfe_valor_sem_ipi'] - $res['historico_valor'], 2), $res['diferenca_sem_ipi']);
    // pedido sem NF-e que tem nota de outro CNPJ traz essa nota na observação
    $comOutro = array_filter($r['dados']['divergencias'], fn($x) => str_contains($x['observacao'], 'outro CNPJ'));
    igual($res['pedidos_sem_nfe_com_nota_de_outro_cnpj'], count($comOutro));
});

teste('conciliação vira relatório (fonte com tabela de divergências)', function () use ($pdo, $conv) {
    $f = chatFonteSalvar($pdo, $conv, UID_A, 'conciliar_faturamento', [
        'periodo' => '2026-09-01 a 2026-09-30', 'criterio' => 't',
        'resumo' => ['historico_valor' => 100.0, 'nfe_valor_sem_ipi' => 90.0, 'nfe_valor_com_ipi' => 95.0, 'diferenca_sem_ipi' => -10.0,
                     'conciliados' => 1, 'quantidade_divergencias' => 1, 'outros_cnpjs_valor_com_ipi' => 0.0],
        'divergencias' => [['tipo' => 'Pedido sem NF-e', 'documento' => 'Pedido 1', 'data' => '2026-09-02', 'cliente' => 'C',
                            'valor_sem_ipi' => 10.0, 'ipi' => null, 'observacao' => 'x']],
    ]);
    [$m, $erro] = chatMontarRelatorio($pdo, $conv, UID_A, ['titulo' => 'Cruzamento', 'formato' => 'xlsx',
        'indicadores' => [['fonte' => $f, 'campo' => 'diferenca_sem_ipi']],
        'secoes' => [['subtitulo' => 'Divergências', 'tabela' => ['fonte' => $f]]]]);
    igual(null, $erro);
    igual(-10.0, (float) $m['indicadores'][0]['valor']);
    igual('Divergência', $m['secoes'][0]['tabela']['colunas'][0]['rotulo']);
    igual('PK', substr(chatRenderXlsx($m, 'T', new DateTimeImmutable()), 0, 2));
});

limparDadosTeste($pdo);
$pdo->exec("DELETE FROM CHAT_FONTE WHERE CCV_ID = '{$conv}'");
