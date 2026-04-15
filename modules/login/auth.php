<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Guarda de sessão
//  Inclua em TODAS as páginas protegidas:
//  require_once $_SERVER['DOCUMENT_ROOT'].'/modules/login/auth.php';
// ══════════════════════════════════════════════════

if (basename($_SERVER['PHP_SELF']) === 'login.php') {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_login_url = '/login';
$_timeout = 8 * 60 * 60; // 8 horas

if (!empty($_SESSION['usu_codigo'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $_timeout) {
        session_unset();
        session_destroy();
        header('Location: ' . $_login_url . '?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
} else {
    header('Location: ' . $_login_url);
    exit;
}

if (!function_exists('usuNome')) {
    function usuNome(): string
    {
        return $_SESSION['usu_nome'] ?? '';
    }
    function usuLogin(): string
    {
        return $_SESSION['usu_login'] ?? '';
    }
    function usuCodigo(): int
    {
        return (int) ($_SESSION['usu_codigo'] ?? 0);
    }
}

// ── Verificação de permissões de acesso ───────────────────────────────────────
try {
    $__pdo_auth = dbPDO();
    $__st_auth  = $__pdo_auth->prepare('SELECT USU_ADMIN, GRU_CODIGO FROM USUARIOS WHERE USU_CODIGO = :c');
    $__st_auth->execute([':c' => (int)($_SESSION['usu_codigo'] ?? 0)]);
    $__row_auth = $__st_auth->fetch(PDO::FETCH_ASSOC);

    if ($__row_auth && empty($__row_auth['USU_ADMIN'])) {
        // Carrega permissões do grupo
        $__perms_auth = [];
        if (!empty($__row_auth['GRU_CODIGO'])) {
            $__sp_auth = $__pdo_auth->prepare(
                'SELECT PAC_PAGINA FROM permissao_acesso WHERE PAC_GRU_CODIGO = :g'
            );
            $__sp_auth->execute([':g' => (int)$__row_auth['GRU_CODIGO']]);
            $__perms_auth = array_column($__sp_auth->fetchAll(PDO::FETCH_ASSOC), 'PAC_PAGINA');
        }

        // Página atual
        $__uri_auth    = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $__script_auth = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

        $__allowed = false;
        foreach ($__perms_auth as $__p) {
            if ($__uri_auth === $__p || $__script_auth === $__p) {
                $__allowed = true;
                break;
            }
            if (str_ends_with($__script_auth, ltrim($__p, '/'))) {
                $__allowed = true;
                break;
            }
        }

        if (!$__allowed) {
            http_response_code(403);
            include $_SERVER['DOCUMENT_ROOT'] . '/shared/403.php';
            exit;
        }
    }
} catch (Throwable) {
    // Em caso de erro no DB, não bloqueia o acesso
}
