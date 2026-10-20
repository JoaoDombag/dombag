<?php
// Etapa 2 — ferramentas de consulta: escopo, validação e isolamento entre usuários.
// Os testes de isolamento rodam contra o ERP real (somente leitura); se o ERP
// estiver fora do ar eles são pulados e isso aparece na saída.

$pdo = dbPDO();
limparDadosTeste($pdo);
echo "\nEtapa 2 — ferramentas de consulta\n";

function nomesLiberados(array $ctx): array
{
    return array_column(chatFerramentasDisponiveis($ctx), 'name');
}

teste('escopo: admin vê tudo, sem filtro de representante', function () {
    $c = chatMontarEscopo(ctxTeste(UID_A, ['admin' => true]), []);
    igual(null, $c['re']);
    igual(['meu_perfil', 'listar_pedidos', 'detalhe_pedido', 'resumo_faturamento', 'status_producao_pedido', 'faturamento_notas', 'conciliar_faturamento', 'gerar_relatorio'], nomesLiberados($c));
});

teste('escopo: representante vinculado só vê os próprios RE_CODIGO', function () {
    $c = chatMontarEscopo(ctxTeste(), [['re_codigo' => 6, 're_nome' => 'X']]);
    igual([6], $c['re']);
    igual(6, count(nomesLiberados($c)));
    // mesmo com permissão de página, continua restrito
    $c = chatMontarEscopo(ctxTeste(UID_A, ['permissoes' => ['/yzidro/consulta']]), [['re_codigo' => 6, 're_nome' => 'X']]);
    igual([6], $c['re']);
});

teste('escopo: sem vínculo e sem permissão → só meu_perfil', function () {
    igual(['meu_perfil'], nomesLiberados(chatMontarEscopo(ctxTeste(), [])));
});

teste('escopo: sem vínculo, ferramentas conforme páginas do grupo', function () {
    $c = chatMontarEscopo(ctxTeste(UID_A, ['permissoes' => ['/relatorios/historico-faturamento']]), []);
    igual(['meu_perfil', 'resumo_faturamento', 'faturamento_notas', 'conciliar_faturamento', 'gerar_relatorio'], nomesLiberados($c));
    igual(null, $c['re']);
});

teste('escopo: ERP fora do ar → não admin fica sem consultas (falha fechada)', function () {
    $c = chatMontarEscopo(ctxTeste(UID_A, ['permissoes' => ['/yzidro/consulta']]), null);
    igual(['meu_perfil'], nomesLiberados($c));
});

teste('validação: rejeita parâmetros que tentam trocar o escopo', function () {
    foreach (['re_codigo', 'representante', 'usuario', 'usu_codigo', 'sql'] as $k) {
        [, $erro] = chatValidarArgumentos('listar_pedidos', ['data_ini' => '2026-01-01', 'data_fim' => '2026-01-31', $k => 6]);
        confere($erro !== null && str_contains($erro, 'não permitido'), "aceitou {$k}");
    }
});

teste('validação: datas, período, tipos e enum', function () {
    $casos = [
        ['listar_pedidos', ['data_ini' => '2026-02-30', 'data_fim' => '2026-03-01']],
        ['listar_pedidos', ['data_ini' => '2025-01-01', 'data_fim' => '2026-06-01']],
        ['listar_pedidos', ['data_ini' => '2026-05-01', 'data_fim' => '2026-04-01']],
        ['listar_pedidos', ['data_ini' => '2026-05-01']],
        ['listar_pedidos', ['data_ini' => '2026-05-01', 'data_fim' => '2026-05-02', 'limite' => 5000]],
        ['detalhe_pedido', ['pedido' => '1 OR 1=1']],
        ['detalhe_pedido', ['pedido' => -3]],
        ['resumo_faturamento', ['data_ini' => '2026-01-01', 'data_fim' => '2026-01-31', 'agrupar_por' => 'cliente; DROP TABLE vendas']],
    ];
    foreach ($casos as [$f, $in]) {
        [, $erro] = chatValidarArgumentos($f, $in);
        confere($erro !== null, "aceitou {$f} " . json_encode($in));
    }
    [$ok, $erro] = chatValidarArgumentos('detalhe_pedido', ['pedido' => '123']);
    igual(null, $erro);
    igual(123, $ok['pedido']);
});

teste('ferramenta não liberada é recusada mesmo se a IA chamar', function () use ($pdo) {
    $c = chatMontarEscopo(ctxTeste(), []);
    $r = chatExecutarFerramenta('listar_pedidos', ['data_ini' => '2026-01-01', 'data_fim' => '2026-01-31'], $c, 'x', $pdo);
    igual(false, $r['ok']);
    $r = chatExecutarFerramenta('sql_livre', ['q' => 'select 1'], chatMontarEscopo(ctxTeste(UID_A, ['admin' => true]), []), 'x', $pdo);
    igual(false, $r['ok']);
});

// ── Isolamento entre usuários contra o ERP real ─────────────────────────────
$pg = chatPG();
if (!$pg) {
    echo "  ⚠ ERP indisponível — testes de isolamento PULADOS\n";
    return;
}

// Dois representantes vinculados a logins, ambos com pedidos no último ano.
$reps = chatPGConsulta($pg, "
    SELECT R.RE_CODIGO, R.RE_NOME, MAX(V.VEN_COD_PEDIDO) AS pedido
      FROM REPRESENTANTES R JOIN VENDAS V ON V.RE_CODIGO = R.RE_CODIGO AND V.EMP_CODIGO = 1
     WHERE R.USU_CODIGO IS NOT NULL AND V.VEN_DATA >= CURRENT_DATE - 365 AND COALESCE(V.VEN_STATUS, 'V') <> 'O'
     GROUP BY 1, 2 HAVING COUNT(*) > 3 ORDER BY 1 LIMIT 2", []);
if (count($reps) < 2) {
    echo "  ⚠ menos de 2 representantes vinculados com pedidos — testes de isolamento PULADOS\n";
    return;
}
[$repA, $repB] = $reps;
$ctxA = chatMontarEscopo(ctxTeste(UID_A, ['nome' => 'Rep A']), [['re_codigo' => (int) $repA['re_codigo'], 're_nome' => $repA['re_nome']]]);
$ctxB = chatMontarEscopo(ctxTeste(UID_B, ['nome' => 'Rep B']), [['re_codigo' => (int) $repB['re_codigo'], 're_nome' => $repB['re_nome']]]);
$pedB = (int) $repB['pedido'];
$ini  = date('Y-m-d', strtotime('-365 days'));
$fim  = date('Y-m-d');

teste("isolamento: listar_pedidos do rep A só traz pedidos do rep A", function () use ($pdo, $pg, $ctxA, $repA, $repB, $pedB, $ini, $fim) {
    $r = chatExecutarFerramenta('listar_pedidos', ['data_ini' => $ini, 'data_fim' => $fim, 'limite' => 200], $ctxA, 'x', $pdo);
    confere($r['ok'] && $r['linhas'] > 0, 'sem pedidos para o rep A');
    $nums = array_column($r['dados']['pedidos'], 'pedido');
    $reDosPedidos = chatPGConsulta($pg, 'SELECT DISTINCT RE_CODIGO FROM VENDAS WHERE EMP_CODIGO = 1 AND VEN_COD_PEDIDO = ANY($1::int[])', [chatPGArray($nums)]);
    igual([(int) $repA['re_codigo']], array_map('intval', array_column($reDosPedidos, 're_codigo')), 'representantes dos pedidos listados');
    confere(!in_array($pedB, $nums, true), 'pedido do rep B apareceu para o rep A');
    confere(!str_contains($r['conteudo'], $repB['re_nome']), 'nome do rep B no resultado');
    // totais batem com a contagem direta do rep A (sem orçamentos)
    $direto = chatPGConsulta($pg, "SELECT COUNT(*) AS n FROM VENDAS WHERE EMP_CODIGO = 1 AND RE_CODIGO = $1
        AND VEN_DATA >= $2::date AND VEN_DATA < $3::date + 1 AND COALESCE(VEN_STATUS, 'V') <> 'O'", [(int) $repA['re_codigo'], $ini, $fim]);
    igual((int) $direto[0]['n'], $r['dados']['total_pedidos']);
});

teste('isolamento: rep A não vê itens nem produção do pedido do rep B', function () use ($pdo, $ctxA, $ctxB, $pedB) {
    $r = chatExecutarFerramenta('detalhe_pedido', ['pedido' => $pedB], $ctxA, 'x', $pdo);
    igual(false, $r['dados']['encontrado']);
    confere(!isset($r['dados']['itens']) && !isset($r['dados']['cliente']), 'vazou dados do pedido');
    $r = chatExecutarFerramenta('status_producao_pedido', ['pedido' => $pedB], $ctxA, 'x', $pdo);
    igual(false, $r['dados']['encontrado']);
    // controle: o próprio rep B enxerga o pedido
    $r = chatExecutarFerramenta('detalhe_pedido', ['pedido' => $pedB], $ctxB, 'x', $pdo);
    igual(true, $r['dados']['encontrado']);
});

teste('isolamento: resumo de faturamento do rep A só contém o rep A', function () use ($pdo, $ctxA, $repA, $ini, $fim) {
    $r = chatExecutarFerramenta('resumo_faturamento', ['data_ini' => $ini, 'data_fim' => $fim, 'agrupar_por' => 'representante'], $ctxA, 'x', $pdo);
    confere($r['ok']);
    foreach ($r['dados']['grupos'] as $g) {
        igual($repA['re_nome'], $g['grupo']);
    }
});

teste('isolamento ponta a ponta: IA pede pedido do rep B na sessão do rep A', function () use ($pdo, $ctxA, $pedB, $pg) {
    $clienteB = chatPGConsulta($pg, "SELECT COALESCE(NULLIF(C.CLI_NOME_FANTASIA, ''), C.CLI_NOME) AS n FROM VENDAS V JOIN CLIENTES C ON C.CLI_CODIGO = V.CLI_CODIGO WHERE V.VEN_COD_PEDIDO = $1", [$pedB])[0]['n'] ?? '';
    $t = new TransporteFalso([
        // a IA tenta inclusive passar um re_codigo "esperto"
        sseResposta([['tool_use' => ['toolu_a', 'detalhe_pedido', ['pedido' => $pedB, 're_codigo' => 999]]]], 'tool_use'),
        sseResposta([['tool_use' => ['toolu_b', 'detalhe_pedido', ['pedido' => $pedB]]]], 'tool_use'),
        sseResposta([['text' => 'Não encontrei esse pedido.']]),
    ]);
    $ev = [];
    $ferr = chatFerramentasDisponiveis($ctxA);
    $r = chatResponder(clienteFalso($t), $pdo, $ctxA + ['ferramentas' => array_column($ferr, 'name')], str_repeat('f', 32), [],
        "detalhes do pedido {$pedB}", $ferr,
        fn($n, $i) => chatExecutarFerramenta($n, $i, $ctxA, 'x', $pdo), coletor($ev));
    igual('ok', $r['status']);
    confere(str_contains($t->brutos[1], 'Parâmetro não permitido: re_codigo') || str_contains($t->brutos[1], 'Parâmetro não permitido: re_codigo'), 'parâmetro extra deveria ser rejeitado');
    confere(str_contains($t->brutos[2], '\"encontrado\":false') || str_contains($t->brutos[2], '"encontrado":false'), 'deveria responder não encontrado');
    confere($clienteB === '' || !str_contains(implode('', $t->brutos), $clienteB), 'nome do cliente do rep B foi enviado à IA');
});

limparDadosTeste($pdo);

// ── Tela inicial personalizada (sem IA) ─────────────────────────────────────
teste('tela inicial: apresentação e sugestões seguem as permissões de cada usuário', function () {
    $adm = chatTextoInicio(chatMontarEscopo(ctxTeste(UID_A, ['admin' => true, 'nome' => 'Ana Lima']), []));
    confere(str_starts_with($adm['texto'], 'Olá, **Ana**!'), 'primeiro nome');
    confere(str_contains($adm['texto'], 'administrador') && str_contains($adm['texto'], 'Status de produção') && str_contains($adm['texto'], 'PDF ou Excel'));
    igual(5, count($adm['sugestoes']));
    confere(str_contains($adm['texto'], 'Notas fiscais / DRE') && str_contains($adm['texto'], 'Cruzamento'), 'admin vê as consultas de DRE');

    $rep = chatTextoInicio(chatMontarEscopo(ctxTeste(UID_A, ['nome' => 'Rep']), [['re_codigo' => 6, 're_nome' => 'DANIELE']]));
    confere(str_contains($rep['texto'], 'representante **DANIELE**') && str_contains($rep['texto'], 'somente os seus pedidos'));

    $fat = chatTextoInicio(chatMontarEscopo(ctxTeste(UID_A, ['permissoes' => ['/relatorios/historico-faturamento']]), []));
    confere(str_contains($fat['texto'], 'Resumo de faturamento') && !str_contains($fat['texto'], 'Lista de pedidos'), 'só o que o grupo libera');
    confere(!in_array('Liste os pedidos emitidos nos últimos 7 dias', $fat['sugestoes'], true), 'sugestão de consulta não liberada');

    $nada = chatTextoInicio(chatMontarEscopo(ctxTeste(UID_A, ['nome' => 'Maria']), []));
    igual([], $nada['sugestoes']);
    confere(str_contains($nada['texto'], 'não tem acesso') && !str_contains($nada['texto'], 'Você tem acesso'), 'texto contraditório');
});
