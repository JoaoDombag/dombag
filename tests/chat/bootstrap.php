<?php

// ══════════════════════════════════════════════════════════════════════════════
//  Testes do chat com IA — infraestrutura comum
//  Rodar:  php tests/chat/run.php
//  Usa o MySQL local (tabelas CHAT_*) com usuários de teste 900001/900002, que
//  são apagados no fim. A API da Anthropic é substituída por um transporte
//  HTTP falso que devolve eventos SSE roteirizados — nenhum token é gasto.
// ══════════════════════════════════════════════════════════════════════════════

$_SERVER['HTTP_HOST']     = 'localhost';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/migrations.php';
require_once dirname(__DIR__, 2) . '/modules/chat/chat_lib.php';
require_once dirname(__DIR__, 2) . '/modules/chat/chat_ferramentas.php';

use Anthropic\Client;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

const UID_A = 900001;
const UID_B = 900002;

$_SESSION = [];
ob_start();
rodarMigrations();
ob_end_clean();

// ── Mini framework de asserções ──────────────────────────────────────────────
$GLOBALS['__ok'] = 0;
$GLOBALS['__falhas'] = [];

function teste(string $nome, callable $fn): void
{
    try {
        $fn();
        echo "  ✔ {$nome}\n";
    } catch (Throwable $e) {
        $GLOBALS['__falhas'][] = $nome;
        echo "  ✘ {$nome}\n      → " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}

function confere(bool $cond, string $msg = 'condição falsa'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
    $GLOBALS['__ok']++;
}

function igual(mixed $esperado, mixed $obtido, string $msg = ''): void
{
    confere($esperado === $obtido, ($msg ? "{$msg}: " : '') . 'esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true));
}

// ── Transporte falso da API ──────────────────────────────────────────────────
final class TransporteFalso implements ClientInterface
{
    /** @var list<array> corpos JSON das requisições recebidas */
    public array $requisicoes = [];
    /** @var list<string> corpos brutos */
    public array $brutos = [];
    public array $urls = [];
    public array $cabecalhos = [];

    /** @param list<string> $respostas corpos SSE, um por requisição */
    public function __construct(private array $respostas) {}

    public function sendRequest(RequestInterface $r): ResponseInterface
    {
        $this->brutos[] = (string) $r->getBody();
        $this->urls[] = (string) $r->getUri();
        $this->cabecalhos[] = $r->getHeaders();
        $this->requisicoes[] = json_decode($this->brutos[array_key_last($this->brutos)], true);
        $corpo = array_shift($this->respostas) ?? throw new RuntimeException('requisição inesperada à API');
        return new Response(200, ['content-type' => 'text/event-stream'], $corpo);
    }
}

function clienteFalso(TransporteFalso $t): Client
{
    return new Client(apiKey: 'teste', requestOptions: ['transporter' => $t, 'streamingTransporter' => $t, 'maxRetries' => 0]);
}

// Monta um stream SSE de uma resposta. $blocos: ['text' => '...'] | ['tool_use' => [id, nome, input]]
function sseResposta(array $blocos, string $stop = 'end_turn', int $in = 100, int $out = 20): string
{
    $ev = [['type' => 'message_start', 'message' => [
        'id' => 'msg_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [], 'stop_reason' => null, 'stop_sequence' => null,
        'usage' => ['input_tokens' => $in, 'output_tokens' => 1, 'cache_read_input_tokens' => 50, 'cache_creation_input_tokens' => 0],
    ]]];
    foreach ($blocos as $i => $b) {
        if (isset($b['text'])) {
            $ev[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']];
            foreach (mb_str_split($b['text'], 8) as $pedaco) {
                $ev[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $pedaco]];
            }
        } elseif (isset($b['tool_use'])) {
            [$id, $nome, $input] = $b['tool_use'];
            $ev[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $nome, 'input' => new stdClass()]];
            $ev[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode((object) $input)]];
        } elseif (isset($b['fallback'])) {
            $ev[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'fallback', 'from' => ['model' => 'a'], 'to' => ['model' => 'b']]];
        } elseif (isset($b['thinking'])) {
            $ev[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']];
            $ev[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']];
        }
        $ev[] = ['type' => 'content_block_stop', 'index' => $i];
    }
    $ev[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => $out]];
    $ev[] = ['type' => 'message_stop'];

    $s = '';
    foreach ($ev as $e) {
        $s .= "event: {$e['type']}\ndata: " . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    return $s;
}

function ctxTeste(int $uid = UID_A, array $extra = []): array
{
    return $extra + ['uid' => $uid, 'nome' => 'Teste', 'admin' => false, 'permissoes' => [], 'ferramentas' => []];
}

function limparDadosTeste(PDO $pdo): void
{
    $dir = chatDirRelatorios();
    foreach ($pdo->query('SELECT CHR_ARQUIVO FROM CHAT_RELATORIO WHERE USU_CODIGO IN (' . UID_A . ',' . UID_B . ')')->fetchAll(PDO::FETCH_COLUMN) as $a) {
        @unlink($dir . '/' . basename($a));
    }
    foreach (['CHAT_CONVERSA', 'CHAT_USO', 'CHAT_LOG', 'CHAT_FONTE', 'CHAT_RELATORIO'] as $t) {
        $pdo->exec("DELETE FROM {$t} WHERE USU_CODIGO IN (" . UID_A . ',' . UID_B . ')');
    }
    $pdo->exec("DELETE FROM CHAT_USO WHERE CHU_IP = '203.0.113.9'");
}

// Coletor de eventos emitidos para o navegador.
function coletor(array &$eventos): callable
{
    return function (string $nome, array $dados) use (&$eventos): void {
        $eventos[] = [$nome, $dados];
    };
}
