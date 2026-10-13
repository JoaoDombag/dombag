<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Configuração Central
//  Inclua com: require_once $_SERVER['DOCUMENT_ROOT'].'/config/config.php';
// ══════════════════════════════════════════════════

// ── Detecção de ambiente ───────────────────────────
$_host = $_SERVER['HTTP_HOST'] ?? 'cli';
define('APP_ENV', str_contains($_host, 'localhost') || str_contains($_host, '127.0.0.1') ? 'local' : 'production');

// ── Sessão: caminho fixo para evitar perda entre workers do HostGator ─────────
if (session_status() === PHP_SESSION_NONE) {
    $_sessPath = __DIR__ . '/sessions';
    if (!is_dir($_sessPath)) {
        mkdir($_sessPath, 0700, true);
    }
    session_save_path($_sessPath);
}

// ── Credenciais por ambiente ───────────────────────
$_credFile = __DIR__ . (APP_ENV === 'local' ? '/db.local.php' : '/db.production.php');
if (file_exists($_credFile)) {
    require_once $_credFile;
}

// ── Segredos fora da pasta pública (chave da Anthropic etc.) ──────────────────
// Arquivo PHP que faz `return ['ANTHROPIC_API_KEY' => '...', ...];`, guardado
// UM nível acima da raiz do site (em produção: ~/dombag.env.php), fora do git
// e fora do alcance do rsync do deploy. Variável de ambiente real tem prioridade.
if (!function_exists('dombagEnv')) {
    function dombagEnv(string $chave, ?string $padrao = null): ?string
    {
        static $arquivo = null;
        if ($arquivo === null) {
            $caminho = getenv('DOMBAG_ENV_FILE') ?: dirname(__DIR__, 2) . '/dombag.env.php';
            $dados   = is_file($caminho) ? include $caminho : [];
            $arquivo = is_array($dados) ? $dados : [];
        }
        $v = getenv($chave);
        if ($v !== false && $v !== '') {
            return $v;
        }
        return isset($arquivo[$chave]) && $arquivo[$chave] !== '' ? (string) $arquivo[$chave] : $padrao;
    }
}

// ── MySQL ──────────────────────────────────────────
define('DB_HOST',    dombagEnv('MYSQLHOST')     ?: (defined('_DB_HOST') ? _DB_HOST : 'localhost'));
define('DB_PORT',    dombagEnv('MYSQLPORT')     ?: (defined('_DB_PORT') ? _DB_PORT : '3306'));
define('DB_NAME',    dombagEnv('MYSQLDATABASE') ?: (defined('_DB_NAME') ? _DB_NAME : ''));
define('DB_USER',    dombagEnv('MYSQLUSER')     ?: (defined('_DB_USER') ? _DB_USER : ''));
define('DB_PASS',    dombagEnv('MYSQLPASSWORD') ?: dombagEnv('MYSQL_ROOT_PASSWORD') ?: (defined('_DB_PASS') ? _DB_PASS : ''));
define('DB_CHARSET', 'utf8mb4');

// ── PostgreSQL (ERP Yzidro — somente leitura) ──────
define('PG_HOST',   dombagEnv('PG_HOST')   ?: (defined('_PG_HOST')   ? _PG_HOST   : 'pg-yzidro-004.yzidro.com'));
define('PG_PORT',   dombagEnv('PG_PORT')   ?: (defined('_PG_PORT')   ? _PG_PORT   : '44552'));
define('PG_DBNAME', dombagEnv('PG_DBNAME') ?: (defined('_PG_DBNAME') ? _PG_DBNAME : '004703_dom_bag_ltda'));
define('PG_USER',   dombagEnv('PG_USER')   ?: (defined('_PG_USER')   ? _PG_USER   : '004703consulta'));
define('PG_PASS',   dombagEnv('PG_PASS')   ?: (defined('_PG_PASS')   ? _PG_PASS   : ''));

// ── Helpers de conexão ─────────────────────────────
if (!function_exists('dbPDO')) {
    function dbPDO(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $opts = [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                     PDO::ATTR_CASE               => PDO::CASE_UPPER];
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER, DB_PASS, $opts
            );
        }
        return $pdo;
    }

    function dbPG()
    {
        static $conn = null, $tried = false;
        if ($tried) return $conn;
        $tried = true;

        if (!function_exists('pg_connect')) {
            error_log('[YZIDRO] Extensão pgsql não habilitada no PHP');
            return null;
        }

        $conn = @pg_connect(
            'host=' . PG_HOST . ' port=' . PG_PORT .
            ' dbname=' . PG_DBNAME . ' user=' . PG_USER . ' password=' . PG_PASS .
            ' connect_timeout=10'
        );

        if (!$conn) {
            error_log('[YZIDRO] Falha ao conectar: ' . pg_last_error());
            $conn = null;
        }

        return $conn;
    }

    // Tema visual do sistema (claro/escuro), configurado em Parâmetros do Sistema
    // e aplicado globalmente — vale para todos os usuários.
    function dombagTema(): string
    {
        static $tema = null;
        if ($tema === null) {
            $tema = 'escuro';
            try {
                $v = dbPDO()->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'tema_sistema'")->fetchColumn();
                if (in_array($v, ['claro', 'escuro'], true)) $tema = $v;
            } catch (Throwable) {}
        }
        return $tema;
    }
}

// Configurações do Assistente IA editáveis em Parâmetros (ver config_ia.php).
require_once __DIR__ . '/config_ia.php';
