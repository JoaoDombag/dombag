<?php

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Download de relatório gerado pelo chat
//  Rota: /api/chat/relatorio/<token>
//
//  Só entrega se: sessão válida + token do PRÓPRIO usuário + dentro das 24h.
//  Qualquer outra situação (outro usuário, expirado, inexistente) recebe a
//  MESMA resposta 404, para não revelar se o token existe.
// ══════════════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/chat_lib.php';
require_once __DIR__ . '/chat_relatorio.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$uid = chatSessaoUid();
session_write_close();

if ($uid <= 0) {
    header('Location: /login');
    exit;
}

$arq = null;
try {
    $arq = chatRelatorioParaDownload(dbPDO(), (string) ($_GET['t'] ?? ''), $uid);
} catch (Throwable $e) {
    error_log('[CHAT] download: ' . $e->getMessage());
}

if (!$arq) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Relatório indisponível</title>'
       . '<p style="font-family:sans-serif;padding:24px">Relatório não encontrado ou link expirado (os links valem 24 horas).<br>'
       . 'Peça ao assistente para gerar o relatório novamente.</p>';
    exit;
}

[$caminho, $nome, $formato] = $arq;
$tipos = [
    'pdf'  => 'application/pdf',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
header('Content-Type: ' . $tipos[$formato]);
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Content-Length: ' . filesize($caminho));
readfile($caminho);
