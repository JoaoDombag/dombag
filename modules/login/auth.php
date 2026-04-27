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
    $__sess_lifetime = 30 * 24 * 60 * 60; // 30 dias
    session_set_cookie_params([
        'lifetime' => $__sess_lifetime,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) $__sess_lifetime);
    session_start();
}

$_login_url = '/login';
$_timeout = 30 * 24 * 60 * 60; // 30 dias

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

// Páginas sempre acessíveis: derivadas do registro central (publico=true)
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/pages.php';
$__always_allowed_paths = array_column(
    array_filter($DOMBAG_PAGINAS, fn($p) => !empty($p['publico'])),
    'href'
);

try {
    $__pdo_auth = dbPDO();
    $__st_auth  = $__pdo_auth->prepare('SELECT USU_ADMIN, GRU_CODIGO FROM USUARIOS WHERE USU_CODIGO = :c');
    $__st_auth->execute([':c' => (int)($_SESSION['usu_codigo'] ?? 0)]);
    $__row_auth = $__st_auth->fetch(PDO::FETCH_ASSOC);

    // Endpoints AJAX (api.php) pulam a verificação de permissão de página —
    // a sessão já garante o login; o acesso real é controlado pela página que os chama.
    $__is_api = str_ends_with(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api.php');

    if (!$__is_api && $__row_auth && empty($__row_auth['USU_ADMIN'])) {
        // Página atual
        $__uri_auth    = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $__script_auth = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

        // Sempre permitido (dashboard e widgets)
        $__always_ok = false;
        foreach ($__always_allowed_paths as $__ap) {
            if ($__uri_auth === $__ap || $__script_auth === $__ap
                || str_ends_with($__script_auth, ltrim($__ap, '/'))) {
                $__always_ok = true;
                break;
            }
        }

        if (!$__always_ok) {
            // Carrega permissões do grupo
            $__perms_auth = [];
            if (!empty($__row_auth['GRU_CODIGO'])) {
                $__sp_auth = $__pdo_auth->prepare(
                    'SELECT PAC_PAGINA FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :g'
                );
                $__sp_auth->execute([':g' => (int)$__row_auth['GRU_CODIGO']]);
                $__perms_auth = array_column($__sp_auth->fetchAll(PDO::FETCH_ASSOC), 'PAC_PAGINA');
            }

            // Cobertura de sub-páginas: ter permissão no pai cobre filhos não listados
            // (ex: /pcp/pdf é gerado pelo relatório de produção, não tem entrada própria)
            $__child_coverage = [
                '/relatorios/producao' => ['/pcp/pdf'],
                '/pcp/producao'        => ['/pcp/pdf'],
            ];
            foreach ($__child_coverage as $__parent => $__children) {
                if (in_array($__parent, $__perms_auth, true)) {
                    $__perms_auth = array_merge($__perms_auth, $__children);
                }
            }

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
                // Prefix match: /foo cobre /foo/bar (ex: /relatorios/estoque-futuro cobre /relatorios/estoque-futuro/pdf)
                if (str_starts_with($__uri_auth, rtrim($__p, '/') . '/')) {
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
    }
} catch (Throwable) {
    // Em caso de erro no DB, não bloqueia o acesso
}

// ── Roda migrations pendentes (uma vez por sessão) ────────────────────────────
if (empty($_SESSION['mig_ok'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/migrations.php';
    rodarMigrations();
}
