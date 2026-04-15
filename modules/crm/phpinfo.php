<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// Somente admins podem ver o phpinfo
try {
    $__pdo = dbPDO();
    $__st  = $__pdo->prepare('SELECT USU_ADMIN FROM USUARIOS WHERE USU_CODIGO = ?');
    $__st->execute([usuCodigo()]);
    if (!(bool) $__st->fetchColumn()) {
        http_response_code(403);
        include $_SERVER['DOCUMENT_ROOT'] . '/shared/403.php';
        exit;
    }
} catch (Throwable) {
    http_response_code(403);
    exit;
}

phpinfo();
