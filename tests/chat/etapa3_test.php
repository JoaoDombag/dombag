<?php
// Etapa 3 — relatórios: fontes, validação, conteúdo só das fontes, links
// temporários (dono + 24h), limite diário e segurança do XLSX.

$pdo = dbPDO();
limparDadosTeste($pdo);
echo "\nEtapa 3 — relatórios\n";

$convA = str_repeat('a', 32);
$convB = str_repeat('b', 32);

function fonteGrupos(PDO $pdo, string $conv, int $uid, array $grupos, string $agr = 'cliente'): string
{
    return chatFonteSalvar($pdo, $conv, $uid, 'resumo_faturamento', [
        'periodo_faturamento' => '2026-09-01 a 2026-09-30', 'agrupado_por' => $agr, 'observacao' => 'teste', 'grupos' => $grupos,
    ]);
}

$gruposA = [
    ['grupo' => '=HYPERLINK("http://x","clique")', 'pedidos' => 2, 'pecas' => 100, 'valor' => 1500.5, 'ticket_medio' => 750.25],
    ['grupo' => '<b>Cliente</b> & Cia', 'pedidos' => 1, 'pecas' => 50, 'valor' => 999.99, 'ticket_medio' => 999.99],
];

teste('fontes: numeradas por conversa e invisíveis para outro usuário/conversa', function () use ($pdo, $convA, $convB, $gruposA) {
    igual('F1', fonteGrupos($pdo, $convA, UID_A, $gruposA));
    igual('F2', fonteGrupos($pdo, $convA, UID_A, []));
    igual('F1', fonteGrupos($pdo, $convB, UID_B, [['grupo' => 'Segredo do B', 'pedidos' => 9, 'pecas' => 9, 'valor' => 9, 'ticket_medio' => 1]]));
    confere(chatFonteCarregar($pdo, $convA, UID_A, 'F1') !== null);
    igual(null, chatFonteCarregar($pdo, $convA, UID_B, 'F1'), 'outro usuário, mesma conversa');
    igual(null, chatFonteCarregar($pdo, $convB, UID_A, 'F1'), 'mesmo usuário, conversa de outro');
});

teste('números do relatório vêm da fonte, não da IA', function () use ($pdo, $convA, $gruposA) {
    [$m, $erro] = chatMontarRelatorio($pdo, $convA, UID_A, [
        'titulo' => 'T', 'formato' => 'pdf',
        'indicadores' => [['fonte' => 'F1', 'campo' => 'total_valor']],
        'secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F1', 'colunas' => ['grupo', 'valor']]]],
    ]);
    igual(null, $erro);
    igual(2500.49, $m['indicadores'][0]['valor']);
    igual([[$gruposA[0]['grupo'], 1500.5], [$gruposA[1]['grupo'], 999.99]], $m['secoes'][0]['tabela']['linhas']);
    igual(false, $m['sem_dados']);
});

teste('IA não consegue injetar linhas/valores nem usar fonte alheia', function () use ($pdo, $convA) {
    $base = ['titulo' => 'T', 'formato' => 'pdf'];
    $casos = [
        'linhas digitadas'      => ['secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F1', 'linhas' => [['X', 1e9]]]]]],
        'valores no gráfico'    => ['secoes' => [['subtitulo' => 'S', 'grafico' => ['tipo' => 'barra', 'fonte' => 'F1', 'rotulos' => 'grupo', 'valores' => 'valor', 'dados' => [1, 2]]]]],
        'indicador com valor'   => ['indicadores' => [['fonte' => 'F1', 'campo' => 'total_valor', 'valor' => 1e9]], 'secoes' => [['subtitulo' => 'S']]],
        'campo extra na raiz'   => ['tabelas' => [], 'secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F1']]]],
        'fonte inexistente'     => ['secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F9']]]],
        'coluna inexistente'    => ['secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F1', 'colunas' => ['custo']]]]],
        'valores não numéricos' => ['secoes' => [['subtitulo' => 'S', 'grafico' => ['tipo' => 'pizza', 'fonte' => 'F1', 'rotulos' => 'grupo', 'valores' => 'grupo']]]],
        'sem nenhuma fonte'     => ['secoes' => [['subtitulo' => 'S', 'texto' => 'Vendemos R$ 10 milhões.']]],
        'formato inválido'      => ['formato' => 'docx', 'secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F1']]]],
    ];
    foreach ($casos as $nome => $c) {
        [$m, $erro] = chatMontarRelatorio($pdo, $convA, UID_A, $c + $base);
        confere($erro !== null && $m === null, "aceitou: {$nome}");
    }
    // fonte F1 do usuário B (mesmo código!) não é acessível pelo A em outra conversa
    [, $erro] = chatMontarRelatorio($pdo, str_repeat('b', 32), UID_A, $base + ['secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F1']]]]);
    confere($erro !== null && str_contains($erro, 'não existe'), 'usou fonte de outro usuário');
});

teste('consulta vazia → relatório diz que não há dados', function () use ($pdo, $convA) {
    [$m, $erro] = chatMontarRelatorio($pdo, $convA, UID_A, ['titulo' => 'Vazio', 'formato' => 'pdf',
        'secoes' => [['subtitulo' => 'S', 'tabela' => ['fonte' => 'F2'], 'grafico' => ['tipo' => 'barra', 'fonte' => 'F2', 'rotulos' => 'grupo', 'valores' => 'valor']]]]);
    igual(null, $erro);
    igual(true, $m['sem_dados']);
    $pdf = chatRenderPdf($m, 'Teste', new DateTimeImmutable());
    igual('%PDF', substr($pdf, 0, 4));
});

teste('PDF: cabeçalho, rodapé com data e nº de página, gráficos de barra/linha/pizza', function () use ($pdo, $convA) {
    $f = fonteGrupos($pdo, $convA, UID_A, [
        ['grupo' => '2026-07', 'pedidos' => 3, 'pecas' => 10, 'valor' => 300, 'ticket_medio' => 100],
        ['grupo' => '2026-08', 'pedidos' => 4, 'pecas' => 20, 'valor' => 500, 'ticket_medio' => 125],
    ], 'mes');
    $secoes = [];
    foreach (['barra', 'linha', 'pizza'] as $tipo) {
        $secoes[] = ['subtitulo' => $tipo, 'grafico' => ['tipo' => $tipo, 'fonte' => $f, 'rotulos' => 'grupo', 'valores' => 'valor']];
    }
    [$m, $erro] = chatMontarRelatorio($pdo, $convA, UID_A, ['titulo' => 'Gráficos', 'formato' => 'pdf', 'secoes' => $secoes]);
    igual(null, $erro);
    foreach ($m['secoes'] as $s) {
        $svg = chatGraficoSvg($s['grafico']);
        confere(str_starts_with($svg, '<svg') && simplexml_load_string($svg) !== false, "SVG inválido ({$s['subtitulo']})");
    }
    $pdf = chatRenderPdf($m, 'Fulano', new DateTimeImmutable('2026-10-09 10:00'));
    igual('%PDF', substr($pdf, 0, 4));
    confere(strlen($pdf) > 5000, 'PDF pequeno demais');
});

teste('XLSX: uma aba por tabela, números como número, texto sem fórmula/tags', function () use ($pdo, $convA) {
    [$m] = chatMontarRelatorio($pdo, $convA, UID_A, ['titulo' => 'Planilha', 'formato' => 'xlsx',
        'secoes' => [['subtitulo' => 'Clientes', 'tabela' => ['fonte' => 'F1']], ['subtitulo' => 'Clientes', 'tabela' => ['fonte' => 'F1']]]]);
    $bytes = chatRenderXlsx($m, 'Teste', new DateTimeImmutable());
    igual('PK', substr($bytes, 0, 2));
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    file_put_contents($tmp, $bytes);
    $ler = function (string $interno) use ($tmp): string {
        // lê um arquivo do zip sem depender da extensão zip do PHP
        $zip = file_get_contents($tmp);
        $pos = strpos($zip, $interno);
        confere($pos !== false, "zip sem {$interno}");
        $h = unpack('Vsig/vver/vflag/vmet/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen', substr($zip, $pos - 30, 30));
        $dados = substr($zip, $pos + $h['nlen'] + $h['elen'], $h['csize']);
        return $h['met'] === 8 ? gzinflate($dados) : $dados;
    };
    $wb = $ler('xl/workbook.xml');
    igual(3, substr_count($wb, '<sheet '), 'Resumo + 2 abas');
    confere(str_contains($wb, 'Clientes 2'), 'nomes de aba repetidos devem ser únicos');
    $aba  = $ler('xl/worksheets/sheet2.xml');
    $strs = $ler('xl/sharedStrings.xml') . $aba;
    confere(!str_contains($aba, '<f>'), 'há fórmula na planilha');
    confere(str_contains($strs, '=HYPERLINK(&quot;http://x&quot;') || str_contains($strs, '=HYPERLINK("http://x"'), 'texto com = deveria ficar literal');
    confere(str_contains($strs, '&lt;b&gt;Cliente&lt;/b&gt; &amp; Cia'), 'tags deveriam ficar literais');
    confere((bool) preg_match('/<c r="D2"[^>]*><v>1500\.5/', $aba), 'valor deveria ser numérico');
    @unlink($tmp);
});

// ── Links de download ───────────────────────────────────────────────────────
teste('link: abre só para o dono e só dentro de 24h', function () use ($pdo, $convA) {
    $arq = chatSalvarRelatorio($pdo, UID_A, $convA, '%PDF-teste', 'pdf', 'Relatório Ação & Teste');
    $token = substr($arq['url'], strlen('/api/chat/relatorio/'));
    confere((bool) preg_match('/^[a-f0-9]{32}$/', $token), 'token fora do formato');
    igual('relatorio-acao-teste-' . date('Ymd'), substr($arq['nome'], 0, strlen('relatorio-acao-teste-') + 8));

    $ok = chatRelatorioParaDownload($pdo, $token, UID_A);
    confere($ok !== null, 'dono não conseguiu baixar');
    igual('%PDF-teste', file_get_contents($ok[0]));
    // arquivo fica fora da raiz pública e com nome aleatório (≠ token)
    confere(!str_starts_with(realpath($ok[0]), realpath(dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR), 'arquivo dentro da pasta do site');
    confere(!str_contains($ok[0], $token), 'nome do arquivo igual ao token');

    igual(null, chatRelatorioParaDownload($pdo, $token, UID_B), 'outro usuário baixou');
    igual(null, chatRelatorioParaDownload($pdo, $token, 0), 'sem login baixou');
    igual(null, chatRelatorioParaDownload($pdo, '../../config/config', UID_A), 'token malformado');

    $pdo->prepare('UPDATE CHAT_RELATORIO SET CHR_EXPIRA = NOW() - INTERVAL 1 SECOND WHERE CHR_TOKEN = ?')->execute([$token]);
    igual(null, chatRelatorioParaDownload($pdo, $token, UID_A), 'link expirado ainda abre');

    chatLimparExpirados($pdo);
    confere(!is_file($ok[0]), 'arquivo expirado não foi apagado');
    $st = $pdo->prepare('SELECT COUNT(*) FROM CHAT_RELATORIO WHERE CHR_TOKEN = ?');
    $st->execute([$token]);
    igual(0, (int) $st->fetchColumn());
});

teste('limite de 5 relatórios por dia por usuário', function () use ($pdo) {
    $cfg = chatConfig();
    for ($i = 0; $i < $cfg['rel_dia']; $i++) {
        igual(null, chatVerificarLimite($pdo, UID_B, '', 'relatorio'));
        chatRegistrarUso($pdo, UID_B, '', 'relatorio');
    }
    confere(chatVerificarLimite($pdo, UID_B, '', 'relatorio') !== null, '6º relatório deveria ser bloqueado');
    igual(null, chatVerificarLimite($pdo, UID_A, '', 'relatorio'), 'outro usuário afetado');
});

teste('ponta a ponta: consulta → gerar_relatorio → botão de download', function () use ($pdo) {
    $conv = str_repeat('c', 32);
    $ctx  = chatMontarEscopo(ctxTeste(UID_A, ['admin' => true, 'ip' => '127.0.0.1']), []);
    $ferr = chatFerramentasDisponiveis($ctx);
    // fonte simulada no lugar da consulta ao ERP (ferramenta real testada na etapa 2)
    $f = fonteGrupos($pdo, $conv, UID_A, [['grupo' => 'Total', 'pedidos' => 94, 'pecas' => 1, 'valor' => 2969518.25, 'ticket_medio' => 31590.62]], 'total');
    $t = new TransporteFalso([
        sseResposta([['tool_use' => ['toolu_r', 'gerar_relatorio', [
            'titulo' => 'Faturamento de setembro', 'formato' => 'xlsx',
            'indicadores' => [['fonte' => $f, 'campo' => 'total_valor']],
            'secoes' => [['subtitulo' => 'Resumo', 'tabela' => ['fonte' => $f]]],
        ]]]], 'tool_use'),
        sseResposta([['text' => 'Pronto, o relatório está disponível.']]),
    ]);
    $ev = [];
    $r = chatResponder(clienteFalso($t), $pdo, $ctx + ['ferramentas' => array_column($ferr, 'name')], $conv, [], 'gere o relatório', $ferr,
        fn($n, $i) => chatExecutarFerramenta($n, $i, $ctx, $conv, $pdo), coletor($ev));
    igual('ok', $r['status']);
    $arq = array_values(array_filter($ev, fn($e) => $e[0] === 'arquivo'));
    igual(1, count($arq), 'evento arquivo');
    igual('xlsx', $arq[0][1]['formato']);
    confere(str_starts_with($arq[0][1]['url'], '/api/chat/relatorio/'));
    confere(!str_contains($t->brutos[1], '/api/chat/relatorio/'), 'link não deve ser enviado à IA');
    confere(in_array('gerar_relatorio', array_column($t->requisicoes[0]['tools'], 'name'), true));
});

limparDadosTeste($pdo);
