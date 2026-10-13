<?php

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Chat com IA: provedor Google Gemini (API generateContent, REST)
//
//  Mesmo contrato do laço do Claude (chatResponder): recebe o histórico, chama
//  a API em streaming, executa as ferramentas pedidas e devolve o histórico novo.
//
//  Detalhe importante dos modelos Gemini 3.x: as partes da resposta podem trazer
//  "thoughtSignature", que PRECISA voltar igual no histórico (senão a API
//  recusa com MISSING_THOUGHT_SIGNATURE). Por isso guardamos as partes do modelo
//  exatamente como chegaram — só juntamos pedaços de texto puro do streaming.
// ══════════════════════════════════════════════════════════════════════════════

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;

final class GeminiLimiteException extends RuntimeException {}        // 429: cota gratuita esgotada
final class GeminiIndisponivelException extends RuntimeException {}  // 500/503: modelo sobrecarregado

final class GeminiCliente
{
    /** Modelo que atendeu a última chamada (para log e para manter na mesma pergunta). */
    public string $modelo;
    private array $modelos;
    /** Modelos que falharam nesta requisição (não insiste neles). */
    private array $falhos = [];

    /**
     * @param list<string>          $reservas       modelos tentados se o principal estiver sobrecarregado/sem cota
     * @param ClientInterface|null  $transporte     só para testes; em produção o streaming é feito com cURL
     * @param int                   $esperaInicio   segundos sem receber nada antes de desistir do modelo
     */
    public function __construct(
        private string $chave,
        string $modelo,
        private ?ClientInterface $transporte = null,
        private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        array $reservas = [],
        private int $esperaInicio = 20,
    ) {
        $this->modelo  = $modelo;
        $this->modelos = array_values(array_unique(array_filter(array_merge([$modelo], $reservas))));
    }

    /** Ainda há modelo que não falhou nesta requisição? (para refazer a pergunta) */
    public function temAlternativa(): bool
    {
        return (bool) array_diff($this->modelos, $this->falhos);
    }

    public function marcarFalho(string $modelo): void
    {
        $this->falhos[] = $modelo;
    }

    /**
     * Chama streamGenerateContent e entrega cada pedaço (JSON decodificado).
     *
     * $podeTrocar: true só na 1ª chamada de cada pergunta. Se o modelo estiver
     * sobrecarregado (503), lento demais para começar ou sem cota (429), tenta o
     * próximo da lista que ainda não falhou. No meio de uma pergunta com
     * ferramentas o modelo não muda, porque as thoughtSignature são ligadas ao
     * modelo que as gerou — quem cuida disso é o laço, refazendo a pergunta.
     * @return iterable<array>
     */
    public function stream(array $corpo, bool $podeTrocar = true): iterable
    {
        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $candidatos = $podeTrocar ? array_values(array_diff($this->modelos, $this->falhos)) : [$this->modelo];
        $ultimoErro = null;

        foreach ($candidatos as $modelo) {
            for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
                $recebeu = false;
                try {
                    $url = rtrim($this->baseUrl, '/') . '/models/' . rawurlencode($modelo) . ':streamGenerateContent?alt=sse';
                    $fonte = $this->transporte ? $this->abrirPsr($url, $json) : $this->abrirCurl($url, $json);
                    foreach ($fonte as $ev) {
                        if (!$recebeu) {
                            $recebeu = true;
                            $this->modelo = $modelo;
                        }
                        yield $ev;
                    }
                    $this->modelo = $modelo;
                    return;
                } catch (GeminiIndisponivelException $e) {
                    $ultimoErro = $e;
                    if ($recebeu) {
                        $this->falhos[] = $modelo;
                        throw $e;
                    }
                    // 503 rápido costuma passar na 2ª tentativa; demora para começar, não.
                    if ($tentativa === 1 && !str_contains($e->getMessage(), 'sem resposta')) {
                        usleep(800000);
                        continue;
                    }
                } catch (GeminiLimiteException $e) {
                    $ultimoErro = $e;                 // cota é por modelo: vai direto para o próximo
                }
                $this->falhos[] = $modelo;
                break;
            }
        }
        throw $ultimoErro ?? new GeminiIndisponivelException('nenhum modelo Gemini disponível');
    }

    // Erros HTTP viram exceções tipadas.
    private static function erroHttp(int $codigo, string $corpo): RuntimeException
    {
        return match (true) {
            $codigo === 429                    => new GeminiLimiteException('limite do Gemini atingido'),
            in_array($codigo, [500, 503], true) => new GeminiIndisponivelException("Gemini HTTP {$codigo}"),
            default                            => new RuntimeException("Gemini HTTP {$codigo}: " . mb_substr($corpo, 0, 500)),
        };
    }

    // Produção: cURL com leitura incremental (curl_multi), entregando cada
    // evento SSE assim que chega. O fopen do PHP (usado pelo Guzzle em modo
    // stream) trava em respostas de erro e não serve aqui.
    private function abrirCurl(string $url, string $json): iterable
    {
        $buf = '';
        $status = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->chave], // chave fora da URL
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_LOW_SPEED_LIMIT => 1,             // parou de mandar dados por 30 s no meio: desiste
            CURLOPT_LOW_SPEED_TIME  => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $linha) use (&$status) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) {
                    $status = (int) $m[1];
                }
                return strlen($linha);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $dados) use (&$buf, &$chegou) {
                $buf .= $dados;
                $chegou = true;
                return strlen($dados);
            },
        ]);
        $chegou = false;
        $inicio = microtime(true);
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        try {
            do {
                $rc = curl_multi_exec($mh, $ativo);
                if ($ativo) {
                    curl_multi_select($mh, 0.25);
                }
                // Modelo sobrecarregado às vezes fica "pensando" por minutos sem
                // responder: se nada chegar no prazo, passa para o próximo modelo.
                if (!$chegou && microtime(true) - $inicio > $this->esperaInicio) {
                    throw new GeminiIndisponivelException("sem resposta em {$this->esperaInicio}s");
                }
                if ($status >= 200 && $status < 300) {
                    foreach (self::extrairEventos($buf) as $ev) {
                        yield $ev;
                    }
                }
            } while ($ativo && $rc === CURLM_OK);

            $info = curl_multi_info_read($mh);
            if ($info && $info['result'] !== CURLE_OK) {
                throw new GeminiIndisponivelException('falha de conexão com o Gemini: ' . curl_strerror($info['result']));
            }
            if ($status >= 400 || $status === 0) {
                throw self::erroHttp($status, $buf);
            }
            foreach (self::extrairEventos($buf, true) as $ev) {
                yield $ev;
            }
        } finally {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            curl_multi_close($mh);
        }
    }

    // Testes: transporte PSR-18 injetado.
    private function abrirPsr(string $url, string $json): iterable
    {
        $resp = $this->transporte->sendRequest(new Request('POST', $url, [
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $this->chave,
        ], $json));
        if ($resp->getStatusCode() >= 400) {
            throw self::erroHttp($resp->getStatusCode(), (string) $resp->getBody());
        }
        $corpo = $resp->getBody();
        $buf = '';
        while (!$corpo->eof()) {
            $buf .= $corpo->read(8192);
            foreach (self::extrairEventos($buf) as $ev) {
                yield $ev;
            }
        }
        foreach (self::extrairEventos($buf, true) as $ev) {
            yield $ev;
        }
    }

    // Tira do buffer os eventos SSE completos ("data: {...}\n\n").
    private static function extrairEventos(string &$buf, bool $final = false): array
    {
        $buf = str_replace("\r\n", "\n", $buf);
        $eventos = [];
        while (($p = strpos($buf, "\n\n")) !== false) {
            $bloco = substr($buf, 0, $p);
            $buf   = substr($buf, $p + 2);
            if ($j = self::dados($bloco)) {
                $eventos[] = $j;
            }
        }
        if ($final && trim($buf) !== '') {
            if ($j = self::dados($buf)) {
                $eventos[] = $j;
            }
            $buf = '';
        }
        return $eventos;
    }

    private static function dados(string $bloco): ?array
    {
        $d = '';
        foreach (explode("\n", $bloco) as $l) {
            if (str_starts_with($l, 'data:')) {
                $d .= ltrim(substr($l, 5));
            }
        }
        return $d !== '' ? json_decode($d, true) : null;
    }
}

// ── Ferramentas: formato SDK Anthropic → functionDeclarations do Gemini ──────
// O Gemini aceita um subconjunto de schema; tiramos o que ele pode recusar
// (additionalProperties, pattern, format...). A validação de verdade continua
// no servidor (chatValidarArgumentos / chatMontarRelatorio).
function chatGeminiSchema(array $s): array
{
    $permitidas = ['type', 'description', 'properties', 'required', 'enum', 'items', 'minimum', 'maximum',
                   'minItems', 'maxItems', 'maxLength', 'nullable'];
    $saida = [];
    foreach ($s as $k => $v) {
        if (!in_array($k, $permitidas, true)) {
            continue;
        }
        if ($k === 'properties') {
            $props = [];
            foreach ((array) $v as $nome => $sub) {
                $props[$nome] = chatGeminiSchema((array) $sub);
            }
            $v = $props;
        } elseif ($k === 'items') {
            $v = chatGeminiSchema((array) $v);
        }
        $saida[$k] = $v;
    }
    if (($s['format'] ?? '') === 'date') {
        $saida['description'] = trim(($saida['description'] ?? '') . ' Formato AAAA-MM-DD.');
    }
    if (isset($s['pattern'])) {
        $saida['description'] = trim(($saida['description'] ?? '') . ' Ex.: F1, F2.');
    }
    if (($saida['type'] ?? '') === 'object' && empty($saida['properties'])) {
        unset($saida['properties'], $saida['required']);
    }
    return $saida;
}

function chatGeminiFerramentas(array $ferramentas): array
{
    $decl = [];
    foreach ($ferramentas as $f) {
        $d = ['name' => $f['name'], 'description' => $f['description']];
        $schema = chatGeminiSchema((array) ($f['inputSchema'] ?? []));
        if (!empty($schema['properties'])) {          // ferramenta sem argumentos: sem "parameters"
            $d['parameters'] = $schema;
        }
        $decl[] = $d;
    }
    return $decl ? [['functionDeclarations' => $decl]] : [];
}

// Junta os pedaços do streaming: texto puro consecutivo vira uma parte só;
// qualquer parte com outros campos (functionCall, thoughtSignature...) fica intacta.
function chatGeminiJuntarPartes(array $partes): array
{
    $saida = [];
    foreach ($partes as $p) {
        $soTexto = array_keys($p) === ['text'];
        $ultimo  = array_key_last($saida);
        if ($soTexto && $ultimo !== null && array_keys($saida[$ultimo]) === ['text']) {
            $saida[$ultimo]['text'] .= $p['text'];
        } else {
            $saida[] = $p;
        }
    }
    foreach ($saida as &$p) {                         // args vazio tem de voltar como objeto {}
        if (isset($p['functionCall'])) {
            $p['functionCall']['args'] = (object) ($p['functionCall']['args'] ?? []);
        }
    }
    unset($p);
    return $saida;
}

// Ao recarregar do banco, json_decode(assoc) transforma {} em [] — corrige.
function chatGeminiPrepararHistorico(array $historico): array
{
    foreach ($historico as &$c) {
        foreach ($c['parts'] ?? [] as $i => $p) {
            if (isset($p['functionCall'])) {
                $c['parts'][$i]['functionCall']['args'] = (object) ($p['functionCall']['args'] ?? []);
            }
            if (isset($p['functionResponse'])) {
                $c['parts'][$i]['functionResponse']['response'] = (object) ($p['functionResponse']['response'] ?? []);
            }
        }
    }
    unset($c);
    return $historico;
}

const CHAT_GEMINI_BLOQUEIO = ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION', 'IMAGE_SAFETY'];

// ── Laço principal (Gemini) ──────────────────────────────────────────────────
function chatResponderGemini(
    GeminiCliente $cliente,
    PDO $pdo,
    array $ctx,
    string $conversaId,
    array $historico,
    string $mensagem,
    array $ferramentas,
    callable $executar,
    callable $emitir,
): array {
    $cfg    = chatConfig();
    $inicio = $historico;
    $historico[] = ['role' => 'user', 'parts' => [['text' => $mensagem]]];

    $base = [
        'systemInstruction' => ['parts' => [['text' => chatSystemPrompt() . "\n\n" . chatContextoTexto($ctx)]]],
        'generationConfig'  => ['maxOutputTokens' => $cfg['max_tokens']],
    ];
    if ($cfg['gemini_raciocinio'] !== '') {
        $base['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $cfg['gemini_raciocinio']];
    }
    if ($decl = chatGeminiFerramentas($ferramentas)) {
        $base['tools']      = $decl;
        $base['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
    }

    $textoFinal = '';
    $reinicios = 0;
    $gerouRelatorio = false;
    for ($rodada = 1; $rodada <= $cfg['max_rodadas']; $rodada++) {
        $t0 = microtime(true);
        $partes = [];
        $fim = '';
        $uso = [];
        $bloqueio = null;
        $textoRodada = '';
        try {
            // troca para o modelo reserva só na 1ª chamada da pergunta
            foreach ($cliente->stream($base + ['contents' => chatGeminiPrepararHistorico($historico)], $rodada === 1) as $ch) {
                if (!empty($ch['promptFeedback']['blockReason'])) {
                    $bloqueio = $ch['promptFeedback']['blockReason'];
                }
                $uso = $ch['usageMetadata'] ?? $uso;
                $cand = $ch['candidates'][0] ?? null;
                if (!$cand) {
                    continue;
                }
                $fim = $cand['finishReason'] ?? $fim;
                foreach ($cand['content']['parts'] ?? [] as $p) {
                    $partes[] = $p;
                    // texto visível (não o raciocínio interno) vai para a tela na hora
                    if (isset($p['text']) && empty($p['thought']) && $p['text'] !== '') {
                        $textoRodada .= $p['text'];
                        $emitir('delta', ['t' => $p['text']]);
                    }
                }
            }
        } catch (GeminiLimiteException | GeminiIndisponivelException $e) {
            $st = $e instanceof GeminiLimiteException ? 'limite_provedor' : 'indisponivel';
            chatLog($pdo, ['uid' => $ctx['uid'], 'conversa' => $conversaId, 'tipo' => 'api', 'modelo' => $cliente->modelo,
                           'ms' => (int) ((microtime(true) - $t0) * 1000), 'status' => $st]);
            // Caiu no meio de uma pergunta com consultas: o modelo não pode mudar
            // nessa altura (thoughtSignature), então refaz a pergunta inteira em
            // outro modelo. As consultas são só leitura e rápidas; só não refaz se
            // um relatório já foi gerado (para não criar o arquivo duas vezes).
            if ($rodada > 1 && !$gerouRelatorio && $reinicios < 2 && $cliente->temAlternativa()) {
                $reinicios++;
                $emitir('descartar', []);
                $emitir('status', ['ferramenta' => 'trocar_modelo']);
                $historico   = $inicio;
                $historico[] = ['role' => 'user', 'parts' => [['text' => $mensagem]]];
                $textoFinal  = '';
                $rodada      = 0;                     // o for incrementa para 1
                continue;
            }
            if ($textoRodada !== '' || $textoFinal !== '') {
                $emitir('descartar', []);
            }
            return ['historico' => $inicio, 'texto' => '', 'status' => $st];
        } catch (Throwable $e) {
            chatLog($pdo, ['uid' => $ctx['uid'], 'conversa' => $conversaId, 'tipo' => 'api', 'modelo' => $cliente->modelo,
                           'ms' => (int) ((microtime(true) - $t0) * 1000), 'status' => 'erro_api']);
            error_log('[CHAT] erro no Gemini: ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 500));
            return ['historico' => $inicio, 'texto' => '', 'status' => 'erro'];
        }

        $cache = (int) ($uso['cachedContentTokenCount'] ?? 0);
        $chamadas = array_values(array_filter($partes, fn($p) => isset($p['functionCall'])));
        chatLog($pdo, [
            'uid' => $ctx['uid'], 'conversa' => $conversaId, 'tipo' => 'api', 'modelo' => $cliente->modelo,
            'in' => max(0, (int) ($uso['promptTokenCount'] ?? 0) - $cache),
            'out' => (int) ($uso['candidatesTokenCount'] ?? 0) + (int) ($uso['thoughtsTokenCount'] ?? 0),
            'cache_read' => $cache, 'ms' => (int) ((microtime(true) - $t0) * 1000),
            'status' => $bloqueio ? 'bloqueio' : ($chamadas ? 'tool_use' : strtolower($fim ?: 'stop')),
        ]);

        if ($bloqueio || in_array($fim, CHAT_GEMINI_BLOQUEIO, true)) {
            $emitir('descartar', []);
            return ['historico' => $inicio, 'texto' => '', 'status' => 'recusa'];
        }
        if ($fim === 'MISSING_THOUGHT_SIGNATURE' || ($fim === 'MALFORMED_FUNCTION_CALL' && !$chamadas)) {
            error_log('[CHAT] Gemini finishReason ' . $fim);
            return ['historico' => $inicio, 'texto' => '', 'status' => 'erro'];
        }

        $conteudo = chatGeminiJuntarPartes($partes);
        if ($fim === 'MAX_TOKENS') {
            // chamada cortada não pode ficar sem resposta no histórico
            $conteudo = array_values(array_filter($conteudo, fn($p) => !isset($p['functionCall'])));
            $chamadas = [];
        }
        if (!$conteudo) {
            $conteudo = [['text' => '(sem resposta)']];
        }
        $historico[] = ['role' => 'model', 'parts' => $conteudo];
        $textoFinal .= $textoRodada;

        if (!$chamadas) {
            if ($fim === 'MAX_TOKENS') {
                $aviso = "\n\n(A resposta ficou longa demais e foi interrompida.)";
                $textoFinal .= $aviso;
                $emitir('delta', ['t' => $aviso]);
            }
            return ['historico' => $historico, 'texto' => $textoFinal, 'status' => 'ok'];
        }

        // Executa todas as chamadas e devolve as respostas numa única mensagem.
        $respostas = [];
        foreach ($chamadas as $p) {
            $fc   = $p['functionCall'];
            $nome = (string) ($fc['name'] ?? '');
            $emitir('status', ['ferramenta' => $nome]);
            $t1 = microtime(true);
            try {
                $r = $executar($nome, (array) ($fc['args'] ?? []));
                if ($nome === 'gerar_relatorio' && !empty($r['ok'])) {
                    $gerouRelatorio = true;
                }
            } catch (Throwable $e) {
                error_log('[CHAT] erro na ferramenta ' . $nome . ': ' . $e->getMessage());
                $r = ['ok' => false, 'conteudo' => 'Erro interno ao consultar. Informe ao usuário que a consulta falhou e sugira tentar novamente mais tarde.', 'linhas' => 0];
            }
            chatLog($pdo, ['uid' => $ctx['uid'], 'conversa' => $conversaId, 'tipo' => 'ferramenta', 'ferramenta' => $nome,
                           'linhas' => (int) ($r['linhas'] ?? 0), 'ms' => (int) ((microtime(true) - $t1) * 1000),
                           'status' => $r['ok'] ? 'ok' : 'erro']);
            if (!empty($r['evento'])) {
                $emitir($r['evento'][0], $r['evento'][1]);
            }
            $texto = mb_substr((string) $r['conteudo'], 0, $cfg['max_result_chars']);
            $json  = json_decode($texto, true);
            $resp  = $r['ok']
                ? (is_array($json) ? ['resultado' => $json] : ['resultado' => $texto])
                : ['erro' => $texto];
            $fr = ['name' => $nome, 'response' => $resp];
            if (isset($fc['id'])) {
                $fr['id'] = $fc['id'];
            }
            $respostas[] = ['functionResponse' => $fr];
        }
        $historico[] = ['role' => 'user', 'parts' => $respostas];
        if ($textoFinal !== '' && !str_ends_with($textoFinal, "\n")) {
            $textoFinal .= "\n\n";
            $emitir('delta', ['t' => "\n\n"]);
        }
    }

    $aviso = 'Não consegui concluir esta consulta. Tente reformular a pergunta de forma mais específica.';
    $emitir('delta', ['t' => $aviso]);
    return ['historico' => $inicio, 'texto' => $textoFinal . $aviso, 'status' => 'limite_rodadas'];
}
