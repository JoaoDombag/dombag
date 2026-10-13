<?php

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Endpoint do chat com IA   (rota: /api/chat)
//
//  GET  /api/chat?acao=historico&conversa=<id>   → JSON com as mensagens exibidas
//  POST /api/chat  {acao:"enviar", conversa?, mensagem}
//       cabeçalho X-CSRF-Token obrigatório        → text/event-stream (SSE)
//
//  Eventos SSE: conversa · delta · status · descartar · arquivo · erro · fim
// ══════════════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/chat_lib.php';
require_once __DIR__ . '/chat_ferramentas.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function chatJson(int $codigo, array $dados): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = chatSessaoUid();
if ($uid <= 0) {
    chatJson(401, ['erro' => 'Sua sessão expirou. Faça login novamente.']);
}

// Migrations normalmente rodam no login; garante as tabelas do chat para
// quem já estava logado quando este módulo foi publicado.
if (empty($_SESSION['chat_mig_ok'])) {
    require_once dirname(__DIR__, 2) . '/config/migrations.php';
    ob_start();
    unset($_SESSION['mig_ok']);
    rodarMigrations();
    ob_end_clean();
    $_SESSION['chat_mig_ok'] = 1;
}

$csrfSessao = (string) ($_SESSION['chat_csrf'] ?? '');
// Libera o arquivo de sessão: a resposta da IA pode levar segundos e não pode
// travar as outras páginas do usuário.
session_write_close();

try {
    $pdo = dbPDO();
    $ctx = chatContextoUsuario($pdo, $uid);
} catch (Throwable $e) {
    error_log('[CHAT] ' . $e->getMessage());
    chatJson(503, ['erro' => 'Chat indisponível no momento.']);
}
if (!$ctx) {
    chatJson(403, ['erro' => 'Usuário sem acesso ao chat.']);
}

// ── GET: lista de conversas / histórico para redesenhar a janela ────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['acao'] ?? '') === 'conversas') {
        chatJson(200, ['conversas' => chatListarConversas($pdo, $uid)]);
    }
    if (($_GET['acao'] ?? '') === 'uso') {
        chatJson(200, chatUso($pdo, $ctx));
    }
    if (($_GET['acao'] ?? '') === 'inicio') {
        // Apresentação personalizada do usuário logado (escopo vindo da sessão).
        $ctxInicio = chatMontarEscopo($ctx, $ctx['admin'] ? [] : chatRepresentantesDoUsuario($uid));
        chatJson(200, chatTextoInicio($ctxInicio));
    }
    $c = chatCarregarConversa($pdo, (string) ($_GET['conversa'] ?? ''), $uid);
    chatJson(200, ['mensagens' => $c['exibicao'] ?? [], 'cheia' => $c ? chatConversaCheia($c) : false]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatJson(405, ['erro' => 'Método não permitido.']);
}

// ── POST: validações ────────────────────────────────────────────────────────
$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrfSessao === '' || !hash_equals($csrfSessao, $csrf)) {
    chatJson(403, ['erro' => 'Requisição inválida. Recarregue a página.']);
}

$entrada  = json_decode((string) file_get_contents('php://input', length: 20000), true);

// Apagar conversa (só a do próprio usuário).
if (($entrada['acao'] ?? '') === 'apagar') {
    chatJson(200, ['ok' => chatApagarConversa($pdo, (string) ($entrada['conversa'] ?? ''), $uid)]);
}

$mensagem = trim((string) ($entrada['mensagem'] ?? ''));
$cfg      = chatConfig();
if ($mensagem === '') {
    chatJson(422, ['erro' => 'Digite uma mensagem.']);
}
if (mb_strlen($mensagem) > $cfg['max_chars']) {
    chatJson(422, ['erro' => "A mensagem pode ter no máximo {$cfg['max_chars']} caracteres."]);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if ($erroLimite = chatVerificarLimite($pdo, $uid, $ip, 'msg')) {
    chatJson(429, ['erro' => $erroLimite]);
}

// Uma mensagem por vez por usuário (evita corridas no histórico).
$trava = 'dombag_chat_' . $uid;
if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($trava) . ', 0)')->fetchColumn() !== 1) {
    chatJson(409, ['erro' => 'Aguarde a resposta anterior terminar.']);
}

$provedor = chatConfig()['provedor'];
$conversa = chatCarregarConversa($pdo, (string) ($entrada['conversa'] ?? ''), $uid, $provedor)
    ?? ['id' => chatNovoId(), 'historico' => [], 'exibicao' => [], 'turnos' => 0, 'provedor' => $provedor];

if (chatConversaCheia($conversa)) {
    chatJson(422, ['erro' => 'Esta conversa ficou longa demais. Clique em "Nova conversa" para continuar.', 'cheia' => true]);
}

try {
    $cliente = chatCliente();
} catch (Throwable $e) {
    error_log('[CHAT] ' . $e->getMessage());
    chatJson(503, ['erro' => 'Chat indisponível no momento.']);
}

chatRegistrarUso($pdo, $uid, $ip, 'msg');

// ── Streaming (SSE) ─────────────────────────────────────────────────────────
// Se a hospedagem segurar a saída em buffer, o navegador recebe tudo de uma vez
// no final — o widget funciona igual, só sem o efeito de digitação.
set_time_limit(300);
ignore_user_abort(true);
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_flush();
}
header('Content-Type: text/event-stream; charset=utf-8');
header('X-Accel-Buffering: no');
echo ':' . str_repeat(' ', 2048) . "\n\n";   // empurra buffers intermediários

$emitir = function (string $evento, array $dados): void {
    echo "event: {$evento}\ndata: " . json_encode($dados, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
};
$emitir('conversa', ['id' => $conversa['id']]);

$arquivos = [];
$emitirComArquivos = function (string $evento, array $dados) use ($emitir, &$arquivos): void {
    if ($evento === 'arquivo') {
        $arquivos[] = $dados;
    }
    $emitir($evento, $dados);
};

// Escopo de dados: SEMPRE a partir do uid da sessão.
$ctx = chatMontarEscopo($ctx, $ctx['admin'] ? [] : chatRepresentantesDoUsuario($uid));
$ctx['ip'] = $ip;
$ferramentas = chatFerramentasDisponiveis($ctx);
$ctx['ferramentas'] = array_column($ferramentas, 'name');
$res = chatResponder(
    $cliente, $pdo, $ctx, $conversa['id'], $conversa['historico'], $mensagem, $ferramentas,
    fn(string $nome, array $input) => chatExecutarFerramenta($nome, $input, $ctx, $conversa['id'], $pdo),
    $emitirComArquivos,
);

if ($res['status'] === 'ok' || $res['status'] === 'limite_rodadas') {
    $conversa['historico'] = $res['historico'];
    $conversa['exibicao'][] = ['papel' => 'user', 'texto' => $mensagem];
    $conversa['exibicao'][] = ['papel' => 'assistant', 'texto' => $res['texto'], 'arquivos' => $arquivos];
    $conversa['turnos']++;
    try {
        chatSalvarConversa($pdo, $uid, $conversa);
    } catch (Throwable $e) {
        error_log('[CHAT] falha ao salvar conversa: ' . $e->getMessage());
    }
    $emitir('fim', ['cheia' => chatConversaCheia($conversa)]);
} elseif ($res['status'] === 'limite_provedor') {
    $emitir('erro', ['msg' => 'O assistente atingiu o limite de uso gratuito por agora. Tente novamente em alguns minutos.']);
} elseif ($res['status'] === 'indisponivel') {
    $emitir('erro', ['msg' => 'O serviço de IA está sobrecarregado neste momento. Tente novamente em alguns instantes.']);
} elseif ($res['status'] === 'recusa') {
    $emitir('erro', ['msg' => 'Desculpe, não posso ajudar com esse pedido. Posso ajudar com vendas, pedidos, faturamento e produção.']);
} else {
    $emitir('erro', ['msg' => 'Não foi possível responder agora. Tente novamente em instantes.']);
}

$pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($trava) . ')');
