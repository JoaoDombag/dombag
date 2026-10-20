<?php
// Provedor Gemini: streaming, chamadas de ferramenta com thoughtSignature,
// bloqueios, limite gratuito (429), conversão de schema e relatório.

$pdo = dbPDO();
limparDadosTeste($pdo);
echo "\nGemini\n";

// Stream SSE no formato do streamGenerateContent. Cada item de $pedacos é a
// lista de "parts" de um chunk; o último chunk leva finishReason e usage.
function sseGemini(array $pedacos, string $fim = 'STOP', array $extra = []): string
{
    $s = '';
    $n = count($pedacos);
    foreach ($pedacos as $i => $parts) {
        $ch = ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'index' => 0]], 'modelVersion' => 'gemini-3.8-flash'];
        if ($i === $n - 1) {
            $ch['candidates'][0]['finishReason'] = $fim;
            $ch['usageMetadata'] = ['promptTokenCount' => 900, 'cachedContentTokenCount' => 300, 'candidatesTokenCount' => 40,
                                    'thoughtsTokenCount' => 25, 'totalTokenCount' => 965];
        }
        $s .= 'data: ' . json_encode($ch + $extra, JSON_UNESCAPED_UNICODE) . "\r\n\r\n";
    }
    return $s;
}

function geminiFalso(TransporteFalso $t): GeminiCliente
{
    return new GeminiCliente('chave-teste', 'gemini-3.8-flash', $t);
}

// Respostas em sequência: inteiro = erro HTTP com esse código; string = corpo SSE 200.
final class TransporteStatus implements Psr\Http\Client\ClientInterface
{
    public int $chamadas = 0;
    public array $modelos = [];
    private array $fila;
    public function __construct(int|string ...$respostas) { $this->fila = $respostas; }
    public function sendRequest(Psr\Http\Message\RequestInterface $r): Psr\Http\Message\ResponseInterface
    {
        $this->chamadas++;
        preg_match('#/models/([^:]+):#', (string) $r->getUri(), $m);
        $this->modelos[] = $m[1] ?? '?';
        $x = count($this->fila) > 1 ? array_shift($this->fila) : $this->fila[0];
        return is_int($x)
            ? new GuzzleHttp\Psr7\Response($x, [], '{"error":{"code":' . $x . '}}')
            : new GuzzleHttp\Psr7\Response(200, ['content-type' => 'text/event-stream'], $x);
    }
}

teste('gemini: resposta em streaming, chave no cabeçalho, sistema e raciocínio', function () use ($pdo) {
    $t = new TransporteFalso([sseGemini([[['text' => 'Olá! ']], [['text' => 'Posso ajudar.']]])]);
    $ev = [];
    $r = chatResponder(geminiFalso($t), $pdo, ctxTeste(), str_repeat('1', 32), [], 'oi', [], fn() => null, coletor($ev));
    igual('ok', $r['status']);
    igual('Olá! Posso ajudar.', $r['texto']);
    igual(2, count(array_filter($ev, fn($e) => $e[0] === 'delta')), 'deltas');
    igual([['role' => 'user', 'parts' => [['text' => 'oi']]], ['role' => 'model', 'parts' => [['text' => 'Olá! Posso ajudar.']]]], $r['historico']);
    $req = $t->requisicoes[0];
    confere(str_contains($req['systemInstruction']['parts'][0]['text'], 'Nunca invente dados'), 'prompt de sistema');
    igual('low', $req['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null);
    confere(!isset($req['tools']), 'sem ferramentas');
    igual(['chave-teste'], $t->cabecalhos[0]['x-goog-api-key'] ?? null, 'chave no cabeçalho');
    confere(!str_contains($t->urls[0], 'chave-teste'), 'chave não pode ir na URL');
    confere(str_ends_with($t->urls[0], '/models/gemini-3.8-flash:streamGenerateContent?alt=sse'), 'endpoint: ' . $t->urls[0]);
    // log com tokens (entrada sem o cache)
    $l = $pdo->query('SELECT CHL_IN, CHL_OUT, CHL_CACHE_READ, CHL_MODELO FROM CHAT_LOG WHERE USU_CODIGO = ' . UID_A . ' ORDER BY CHL_CODIGO DESC LIMIT 1')->fetch();
    igual([600, 65, 300, 'gemini-3.8-flash'], [(int) $l['CHL_IN'], (int) $l['CHL_OUT'], (int) $l['CHL_CACHE_READ'], $l['CHL_MODELO']]);
});

teste('gemini: chamada de ferramenta devolve thoughtSignature intacta + functionResponse', function () use ($pdo) {
    $sig = 'CsMBAb4+9vsSIGNATURE==';
    $t = new TransporteFalso([
        sseGemini([[['text' => 'Vou consultar.']], [['functionCall' => ['name' => 'meu_perfil', 'args' => new stdClass(), 'id' => 'call-1'], 'thoughtSignature' => $sig]]]),
        sseGemini([[['text' => 'Você é administrador.']]]),
    ]);
    $ferr = [['name' => 'meu_perfil', 'description' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => [], 'additionalProperties' => false]]];
    $ev = [];
    $chamou = [];
    $r = chatResponder(geminiFalso($t), $pdo, ctxTeste(), str_repeat('2', 32), [], 'quem sou?', $ferr,
        function ($n, $i) use (&$chamou) { $chamou[] = [$n, $i]; return ['ok' => true, 'conteudo' => '{"nome":"X"}', 'linhas' => 1]; }, coletor($ev));
    igual('ok', $r['status']);
    igual([['meu_perfil', []]], $chamou);
    // ferramenta sem argumentos vai sem "parameters"
    igual(['name' => 'meu_perfil', 'description' => 'x'], $t->requisicoes[0]['tools'][0]['functionDeclarations'][0]);
    $c = $t->requisicoes[1]['contents'];
    igual('model', $c[1]['role']);
    igual($sig, $c[1]['parts'][1]['thoughtSignature'], 'assinatura devolvida');
    confere(str_contains($t->brutos[1], '"args":{}'), 'args vazio deve ir como objeto');
    igual(['functionResponse' => ['name' => 'meu_perfil', 'response' => ['resultado' => ['nome' => 'X']], 'id' => 'call-1']], $c[2]['parts'][0]);
    igual('user', $c[2]['role']);
    // e o histórico salvo (recarregado do banco) continua válido
    $rec = chatGeminiPrepararHistorico(json_decode(json_encode($r['historico']), true));
    confere(str_contains(json_encode($rec), '"args":{}') && str_contains(json_encode($rec), $sig), 'histórico recarregado');
});

teste('gemini: erro de ferramenta vai como {"erro": ...}', function () use ($pdo) {
    $t = new TransporteFalso([
        sseGemini([[['functionCall' => ['name' => 'detalhe_pedido', 'args' => ['pedido' => 1, 're_codigo' => 9]]]]]),
        sseGemini([[['text' => 'Não pude consultar.']]]),
    ]);
    $ctx = chatMontarEscopo(ctxTeste(), [['re_codigo' => 6, 're_nome' => 'X']]);
    $ev = [];
    chatResponder(geminiFalso($t), $pdo, $ctx, str_repeat('3', 32), [], 'pedido 1', chatFerramentasDisponiveis($ctx),
        fn($n, $i) => chatExecutarFerramenta($n, $i, $ctx, str_repeat('3', 32), $pdo), coletor($ev));
    $resp = $t->requisicoes[1]['contents'][2]['parts'][0]['functionResponse']['response'];
    confere(isset($resp['erro']) && str_contains($resp['erro'], 'não permitido: re_codigo'), 'parâmetro extra deveria ser recusado');
});

teste('gemini: schema convertido sem campos que o Gemini recusa', function () {
    $ctx  = chatMontarEscopo(ctxTeste(UID_A, ['admin' => true]), []);
    $decl = chatGeminiFerramentas(chatFerramentasDisponiveis($ctx))[0]['functionDeclarations'];
    igual(['meu_perfil', 'listar_pedidos', 'detalhe_pedido', 'resumo_faturamento', 'status_producao_pedido', 'faturamento_notas', 'conciliar_faturamento', 'gerar_relatorio'], array_column($decl, 'name'));
    $json = json_encode($decl);
    foreach (['additionalProperties', '"pattern"', '"format"', 'eagerInputStreaming', 'inputSchema'] as $proibido) {
        confere(!str_contains($json, $proibido), "schema contém {$proibido}");
    }
    $lp = $decl[1]['parameters'];
    igual(['data_ini', 'data_fim'], $lp['required']);
    confere(str_contains($lp['properties']['data_ini']['description'], 'AAAA-MM-DD'), 'formato da data vira texto');
    igual('array', $decl[7]['parameters']['properties']['secoes']['type']);
});

teste('gemini: bloqueio de segurança descarta a saída e não altera o histórico', function () use ($pdo) {
    $t = new TransporteFalso([sseGemini([[['text' => 'parc']]], 'SAFETY')]);
    $ev = [];
    $r = chatResponder(geminiFalso($t), $pdo, ctxTeste(), str_repeat('4', 32), [], 'x', [], fn() => null, coletor($ev));
    igual('recusa', $r['status']);
    igual([], $r['historico']);
    confere(in_array(['descartar', []], $ev, true));
    $t = new TransporteFalso(['data: ' . json_encode(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]) . "\n\n"]);
    $r = chatResponder(geminiFalso($t), $pdo, ctxTeste(), str_repeat('4', 32), [], 'x', [], fn() => null, coletor($ev));
    igual('recusa', $r['status']);
});

teste('gemini: limite gratuito (429) vira mensagem própria; 503 tenta de novo uma vez', function () use ($pdo) {
    $ev = [];
    $t = new TransporteStatus(429);
    $r = chatResponder(new GeminiCliente('k', 'gemini-3.8-flash', $t), $pdo, ctxTeste(), str_repeat('5', 32), [], 'oi', [], fn() => null, coletor($ev));
    igual('limite_provedor', $r['status']);
    igual(1, $t->chamadas);
    $t = new TransporteStatus(503);
    $r = chatResponder(new GeminiCliente('k', 'gemini-3.8-flash', $t), $pdo, ctxTeste(), str_repeat('5', 32), [], 'oi', [], fn() => null, coletor($ev));
    igual('indisponivel', $r['status']);
    igual(2, $t->chamadas);
});

teste('gemini: modelo principal sobrecarregado/sem cota → usa o reserva', function () use ($pdo) {
    $ev = [];
    $t = new TransporteStatus(503, 503, sseGemini([[['text' => 'Resposta do reserva.']]]));
    $cli = new GeminiCliente('k', 'gemini-3.5-flash-lite', $t, 'https://x/v1beta', ['gemini-3.8-flash']);
    $r = chatResponder($cli, $pdo, ctxTeste(), str_repeat('8', 32), [], 'oi', [], fn() => null, coletor($ev));
    igual('ok', $r['status']);
    igual(['gemini-3.5-flash-lite', 'gemini-3.5-flash-lite', 'gemini-3.8-flash'], $t->modelos);
    igual('gemini-3.8-flash', $cli->modelo, 'modelo que atendeu');
    // 429 no principal: vai direto para o reserva (sem repetir)
    $t = new TransporteStatus(429, sseGemini([[['text' => 'ok']]]));
    $cli = new GeminiCliente('k', 'gemini-3.5-flash-lite', $t, 'https://x/v1beta', ['gemini-3.8-flash']);
    igual('ok', chatResponder($cli, $pdo, ctxTeste(), str_repeat('8', 32), [], 'oi', [], fn() => null, coletor($ev))['status']);
    igual(['gemini-3.5-flash-lite', 'gemini-3.8-flash'], $t->modelos);
    // sem reserva, caiu no meio da pergunta → desiste (não troca de modelo no meio)
    $chamada = sseGemini([[['functionCall' => ['name' => 'meu_perfil', 'args' => []], 'thoughtSignature' => 's']]]);
    $ferr = [['name' => 'meu_perfil', 'description' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]];
    $t = new TransporteStatus($chamada, 503);
    $cli = new GeminiCliente('k', 'gemini-3.5-flash-lite', $t, 'https://x/v1beta');
    $r = chatResponder($cli, $pdo, ctxTeste(), str_repeat('8', 32), [], 'oi', $ferr, fn() => ['ok' => true, 'conteudo' => '{}', 'linhas' => 0], coletor($ev));
    igual('indisponivel', $r['status']);
    igual(['gemini-3.5-flash-lite', 'gemini-3.5-flash-lite', 'gemini-3.5-flash-lite'], $t->modelos, '2ª rodada só repete o mesmo modelo');
});

teste('gemini: caiu no meio da pergunta → refaz a pergunta inteira no modelo reserva', function () use ($pdo) {
    $chamada = fn($sig) => sseGemini([[['functionCall' => ['name' => 'meu_perfil', 'args' => []], 'thoughtSignature' => $sig]]]);
    $ferr = [['name' => 'meu_perfil', 'description' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]];
    // A pede a ferramenta, depois cai (503, 503); B refaz do zero e responde
    $t = new TransporteStatus($chamada('sigA'), 503, 503, $chamada('sigB'), sseGemini([[['text' => 'Resposta via B.']]]));
    $cli = new GeminiCliente('k', 'modelo-a', $t, 'https://x/v1beta', ['modelo-b']);
    $ev = [];
    $execucoes = 0;
    $r = chatResponder($cli, $pdo, ctxTeste(), str_repeat('9', 32), [], 'oi', $ferr,
        function () use (&$execucoes) { $execucoes++; return ['ok' => true, 'conteudo' => '{}', 'linhas' => 0]; }, coletor($ev));
    igual('ok', $r['status']);
    igual('Resposta via B.', $r['texto']);
    igual(['modelo-a', 'modelo-a', 'modelo-a', 'modelo-b', 'modelo-b'], $t->modelos);
    igual(2, $execucoes, 'consulta refeita no novo modelo');
    confere(in_array(['status', ['ferramenta' => 'trocar_modelo']], $ev, true), 'avisa o usuário da troca');
    // o histórico salvo só tem a tentativa que deu certo (assinatura do B, não do A)
    $json = json_encode($r['historico']);
    confere(str_contains($json, 'sigB') && !str_contains($json, 'sigA'), 'histórico misturou modelos');

    // relatório já gerado nesta pergunta → não refaz (evita arquivo duplicado)
    $rel = sseGemini([[['functionCall' => ['name' => 'gerar_relatorio', 'args' => []], 'thoughtSignature' => 's']]]);
    $t = new TransporteStatus($rel, 503, 503, $rel);
    $cli = new GeminiCliente('k', 'modelo-a', $t, 'https://x/v1beta', ['modelo-b']);
    $ferrRel = [['name' => 'gerar_relatorio', 'description' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]];
    $r = chatResponder($cli, $pdo, ctxTeste(), str_repeat('9', 32), [], 'gere', $ferrRel, fn() => ['ok' => true, 'conteudo' => '{}', 'linhas' => 0], coletor($ev));
    igual('indisponivel', $r['status']);
    igual(['modelo-a', 'modelo-a', 'modelo-a'], $t->modelos, 'não deveria refazer depois do relatório');
});

teste('gemini: MAX_TOKENS com chamada cortada não deixa functionCall órfão', function () use ($pdo) {
    $t = new TransporteFalso([sseGemini([[['text' => 'abc'], ['functionCall' => ['name' => 'x', 'args' => []]]]], 'MAX_TOKENS')]);
    $ev = [];
    $r = chatResponder(geminiFalso($t), $pdo, ctxTeste(), str_repeat('6', 32), [], 'oi', [], fn() => throw new LogicException('não deveria executar'), coletor($ev));
    igual('ok', $r['status']);
    igual([['text' => 'abc']], $r['historico'][1]['parts']);
});

teste('gemini: relatório ponta a ponta (fonte → gerar_relatorio → download)', function () use ($pdo) {
    $conv = str_repeat('7', 32);
    $ctx  = chatMontarEscopo(ctxTeste(UID_A, ['admin' => true, 'ip' => '127.0.0.1']), []);
    $f = chatFonteSalvar($pdo, $conv, UID_A, 'resumo_faturamento', ['periodo_faturamento' => '2026-09-01 a 2026-09-30', 'agrupado_por' => 'total',
        'observacao' => 't', 'grupos' => [['grupo' => 'Total', 'pedidos' => 94, 'pecas' => 1, 'valor' => 2969518.25, 'ticket_medio' => 31590.62]]]);
    $t = new TransporteFalso([
        sseGemini([[['functionCall' => ['name' => 'gerar_relatorio', 'args' => ['titulo' => 'Setembro', 'formato' => 'pdf',
            'secoes' => [['subtitulo' => 'Total', 'tabela' => ['fonte' => $f]]]]], 'thoughtSignature' => 'sig']]]),
        sseGemini([[['text' => 'Relatório pronto.']]]),
    ]);
    $ev = [];
    $r = chatResponder(geminiFalso($t), $pdo, $ctx, $conv, [], 'gere pdf', chatFerramentasDisponiveis($ctx),
        fn($n, $i) => chatExecutarFerramenta($n, $i, $ctx, $conv, $pdo), coletor($ev));
    igual('ok', $r['status']);
    $arq = array_values(array_filter($ev, fn($e) => $e[0] === 'arquivo'));
    igual(1, count($arq));
    $token = substr($arq[0][1]['url'], strlen('/api/chat/relatorio/'));
    confere(chatRelatorioParaDownload($pdo, $token, UID_A) !== null, 'dono baixa');
    igual(null, chatRelatorioParaDownload($pdo, $token, UID_B), 'outro usuário não baixa');
});

teste('conversa feita com um provedor não é continuada pelo outro', function () use ($pdo) {
    $c = ['id' => chatNovoId(), 'historico' => [['role' => 'user', 'content' => 'x']], 'exibicao' => [['papel' => 'user', 'texto' => 'x']], 'turnos' => 1, 'provedor' => 'anthropic'];
    chatSalvarConversa($pdo, UID_A, $c);
    confere(chatCarregarConversa($pdo, $c['id'], UID_A, 'anthropic') !== null);
    igual(null, chatCarregarConversa($pdo, $c['id'], UID_A, 'gemini'));
    igual(1, count(chatCarregarConversa($pdo, $c['id'], UID_A)['exibicao']), 'exibição continua disponível');
});

limparDadosTeste($pdo);
