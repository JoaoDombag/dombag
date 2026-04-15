<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
// ══════════════════════════════════════════════════
//  DOMBAG — Logout
//  Acesse diretamente: /logout.php
// ══════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroi a sessão completamente
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'],
        $p['domain'],
        $p['secure'],
        $p['httponly']
    );
}

session_destroy();

// Redireciona para o login
header('Location: /login?saiu=1');
exit;
