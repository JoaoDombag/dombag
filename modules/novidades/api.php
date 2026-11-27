<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/novidades.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Marca as Novidades como vistas (POST /api/novidades)
//  Chamado pela janela de shared/novidades.php ao fechar. Só exige login:
//  todo usuário vê as novidades (o nome api.php pula a permissão de página).
// ══════════════════════════════════════════════════════════════

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['novidades_csrf'])
    || !hash_equals($_SESSION['novidades_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

// Grava a versão que a pessoa viu na janela (se um deploy entrou no meio,
// a mais nova ainda aparece na próxima tela).
$versao = (string) ($_POST['versao'] ?? '');
if (!isset(NOVIDADES[$versao])) {
    $versao = novidadesUltima();
}

try {
    // Só avança: uma aba antiga fechando depois não volta a versão para trás
    $st = dbPDO()->prepare(
        'UPDATE USUARIOS SET USU_NOVIDADES_VISTA = :v
          WHERE USU_CODIGO = :c AND (USU_NOVIDADES_VISTA IS NULL OR USU_NOVIDADES_VISTA < :v2)'
    );
    $st->execute([':v' => $versao, ':v2' => $versao, ':c' => (int) $_SESSION['usu_codigo']]);
} catch (Throwable) {
    // Sem a coluna (migração pendente) vale ao menos para esta sessão
}
$_SESSION['novidades_vista'] = $versao;

echo json_encode(['ok' => true, 'versao' => $versao]);
