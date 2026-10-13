<?php
// Etapa 1 — chat básico: laço com a API, streaming, histórico, limites e log.

$pdo = dbPDO();
limparDadosTeste($pdo);

echo "\nEtapa 1 — chat básico\n";

teste('responde em streaming e acrescenta a troca ao histórico', function () use ($pdo) {
    $t = new TransporteFalso([sseResposta([['text' => 'Olá! Posso ajudar com seus pedidos.']])]);
    $ev = [];
    $r = chatResponder(clienteFalso($t), $pdo, ctxTeste(), str_repeat('a', 32), [], 'oi', [],
        fn() => throw new LogicException('não deveria chamar ferramenta'), coletor($ev));

    igual('ok', $r['status']);
    igual('Olá! Posso ajudar com seus pedidos.', $r['texto']);
    igual(2, count($r['historico']));
    igual('user', $r['historico'][0]['role']);
    igual('assistant', $r['historico'][1]['role']);
    confere(count(array_filter($ev, fn($e) => $e[0] === 'delta')) > 1, 'esperava vários deltas (streaming)');

    $req = $t->requisicoes[0];
    igual('claude-sonnet-5-5', $req['model'], 'modelo padrão');
    igual('default', $req['fallbacks'] ?? null, 'fallback no servidor');
    igual(['type' => 'ephemeral'], $req['system'][0]['cache_control'] ?? null, 'cache do prompt de sistema');
    igual(['type' => 'ephemeral'], $req['cache_control'] ?? null, 'cache automático do histórico');
    confere(str_contains($req['system'][0]['text'], 'Nunca invente dados'), 'regra de não inventar dados');
    confere(!isset($req['tools']), 'sem ferramentas na etapa 1');
});

teste('executa ferramenta pedida pela IA e devolve tool_result', function () use ($pdo) {
    $t = new TransporteFalso([
        sseResposta([['text' => 'Vou consultar.'], ['tool_use' => ['toolu_1', 'meu_perfil', []]]], 'tool_use'),
        sseResposta([['text' => 'Você é o representante X.']]),
    ]);
    $ev = [];
    $chamadas = [];
    $r = chatResponder(clienteFalso($t), $pdo, ctxTeste(), str_repeat('b', 32), [], 'quem sou eu?',
        [['name' => 'meu_perfil', 'description' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]],
        function ($nome, $input) use (&$chamadas) {
            $chamadas[] = $nome;
            return ['ok' => true, 'conteudo' => '{"representante":"X"}', 'linhas' => 1];
        }, coletor($ev));

    igual(['meu_perfil'], $chamadas);
    igual('ok', $r['status']);
    igual(4, count($r['historico']), 'user, assistant(tool_use), user(tool_result), assistant');
    $segunda = $t->requisicoes[1]['messages'];
    igual('tool_result', $segunda[2]['content'][0]['type']);
    igual('toolu_1', $segunda[2]['content'][0]['tool_use_id']);
    // tool_use sem argumentos tem de ir como objeto {} (não lista [])
    confere(str_contains($t->brutos[1], '"name":"meu_perfil","input":{}') || str_contains($t->brutos[1], '"input":{}'), 'input vazio enviado como lista');
    confere(in_array(['status', ['ferramenta' => 'meu_perfil']], $ev, true), 'evento de status da ferramenta');
});

teste('recusa: descarta a saída parcial e não altera o histórico', function () use ($pdo) {
    $t = new TransporteFalso([sseResposta([['text' => 'parcial']], 'refusal')]);
    $ev = [];
    $anterior = [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'b']]]];
    $r = chatResponder(clienteFalso($t), $pdo, ctxTeste(), str_repeat('c', 32), $anterior, 'pedido proibido', [], fn() => null, coletor($ev));
    igual('recusa', $r['status']);
    igual($anterior, $r['historico']);
    confere(in_array(['descartar', []], $ev, true), 'evento descartar');
});

teste('erro na API: devolve status erro sem expor detalhes', function () use ($pdo) {
    $t = new TransporteFalso([]);   // nenhuma resposta → exceção no transporte
    $ev = [];
    $r = chatResponder(clienteFalso($t), $pdo, ctxTeste(), str_repeat('d', 32), [], 'oi', [], fn() => null, coletor($ev));
    igual('erro', $r['status']);
    igual([], $r['historico']);
});

teste('max_tokens: tool_use incompleto não fica no histórico', function () use ($pdo) {
    $t = new TransporteFalso([sseResposta([['text' => 'abc'], ['tool_use' => ['toolu_9', 'x', []]]], 'max_tokens')]);
    $ev = [];
    $r = chatResponder(clienteFalso($t), $pdo, ctxTeste(), str_repeat('e', 32), [], 'oi', [], fn() => null, coletor($ev));
    $tipos = array_column($r['historico'][1]['content'], 'type');
    igual(['text'], $tipos);
});

teste('fallback de modelo: remove thinking/tool_use anteriores à troca', function () {
    $c = chatNormalizarConteudo([
        ['type' => 'thinking', 'thinking' => '', 'signature' => 's'],
        ['type' => 'text', 'text' => 'parcial'],
        ['type' => 'fallback', 'from' => ['model' => 'a'], 'to' => ['model' => 'b']],
        ['type' => 'thinking', 'thinking' => '', 'signature' => 's2'],
        ['type' => 'text', 'text' => 'final'],
    ]);
    igual(['text', 'fallback', 'thinking', 'text'], array_column($c, 'type'));
});

teste('log grava tokens e métricas, sem texto das mensagens', function () use ($pdo) {
    $st = $pdo->query('SELECT * FROM CHAT_LOG WHERE USU_CODIGO = ' . UID_A . " AND CHL_TIPO = 'api' ORDER BY CHL_CODIGO LIMIT 1");
    $l = $st->fetch(PDO::FETCH_ASSOC);
    confere((bool) $l, 'log não gravado');
    igual(100, (int) $l['CHL_IN']);
    igual(20, (int) $l['CHL_OUT']);
    igual(50, (int) $l['CHL_CACHE_READ']);
    $tudo = json_encode($pdo->query('SELECT * FROM CHAT_LOG WHERE USU_CODIGO = ' . UID_A)->fetchAll());
    confere(!str_contains($tudo, 'Olá') && !str_contains($tudo, 'quem sou eu'), 'log contém texto de mensagem');
});

teste('conversa só é carregada pelo próprio dono', function () use ($pdo) {
    $c = ['id' => chatNovoId(), 'historico' => [['role' => 'user', 'content' => 'segredo']], 'exibicao' => [], 'turnos' => 1];
    chatSalvarConversa($pdo, UID_A, $c);
    confere(chatCarregarConversa($pdo, $c['id'], UID_A) !== null, 'dono não conseguiu carregar');
    igual(null, chatCarregarConversa($pdo, $c['id'], UID_B), 'outro usuário carregou a conversa');
    igual(null, chatCarregarConversa($pdo, "x' OR '1'='1", UID_B), 'id malformado');
});

teste('rate limit: 20 mensagens por hora por usuário', function () use ($pdo) {
    $ip = '203.0.113.9';
    for ($i = 0; $i < 20; $i++) {
        igual(null, chatVerificarLimite($pdo, UID_B, $ip, 'msg'), "mensagem {$i}");
        chatRegistrarUso($pdo, UID_B, $ip, 'msg');
    }
    confere(chatVerificarLimite($pdo, UID_B, $ip, 'msg') !== null, '21ª mensagem deveria ser bloqueada');
    igual(null, chatVerificarLimite($pdo, UID_A, '198.51.100.1', 'msg'), 'outro usuário não é afetado');
});

teste('conversa longa demais é recusada (histórico limitado)', function () {
    $cfg = chatConfig();
    confere(chatConversaCheia(['turnos' => $cfg['max_turnos'], 'historico' => []]));
    confere(chatConversaCheia(['turnos' => 0, 'historico' => [str_repeat('x', $cfg['max_hist_bytes'])]]));
    confere(!chatConversaCheia(['turnos' => 1, 'historico' => [['role' => 'user', 'content' => 'oi']]]));
});

limparDadosTeste($pdo);
