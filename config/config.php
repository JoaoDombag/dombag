<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Configuração Central
//  Inclua com: require_once $_SERVER['DOCUMENT_ROOT'].'/config/config.php';
// ══════════════════════════════════════════════════

// ── MySQL ─────────────────────────────────────────
// Prioridade: variáveis de ambiente (Railway) > arquivo local (HostGator/dev)
$_localConfig = __DIR__ . '/db.local.php';
if (file_exists($_localConfig)) {
    require_once $_localConfig;
}

define('DB_HOST',    getenv('MYSQLHOST')     ?: (defined('_DB_HOST')    ? _DB_HOST    : 'localhost'));
define('DB_PORT',    getenv('MYSQLPORT')     ?: (defined('_DB_PORT')    ? _DB_PORT    : '3306'));
define('DB_NAME',    getenv('MYSQLDATABASE') ?: (defined('_DB_NAME')    ? _DB_NAME    : ''));
define('DB_USER',    getenv('MYSQLUSER')     ?: (defined('_DB_USER')    ? _DB_USER    : ''));
define('DB_PASS',    getenv('MYSQLPASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: (defined('_DB_PASS') ? _DB_PASS : ''));
define('DB_CHARSET', 'utf8mb4');

// ── PostgreSQL (ERP Yzidro — somente leitura) ─────
define('PG_HOST',   getenv('PG_HOST')   ?: (defined('_PG_HOST')   ? _PG_HOST   : 'pg-yzidro-004.yzidro.com'));
define('PG_PORT',   getenv('PG_PORT')   ?: (defined('_PG_PORT')   ? _PG_PORT   : '44551'));
define('PG_DBNAME', getenv('PG_DBNAME') ?: (defined('_PG_DBNAME') ? _PG_DBNAME : '004703_dom_bag_ltda'));
define('PG_USER',   getenv('PG_USER')   ?: (defined('_PG_USER')   ? _PG_USER   : '004703consulta'));
define('PG_PASS',   getenv('PG_PASS')   ?: (defined('_PG_PASS')   ? _PG_PASS   : ''));

// ── Helpers de conexão ────────────────────────────
if (!function_exists('dbPDO')) {
    function dbPDO(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $opts = [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
            // Conecta sem banco para criar se não existir
            $base = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
                DB_USER, DB_PASS, $opts
            );
            $base->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $base->exec('USE `' . DB_NAME . '`');
            $pdo = $base;
        }
        return $pdo;
    }

    function dbPG()
    {
        if (!function_exists('pg_connect')) {
            return null;
        }
        return @pg_connect(
            'host=' . PG_HOST . ' port=' . PG_PORT .
            ' dbname=' . PG_DBNAME . ' user=' . PG_USER . ' password=' . PG_PASS
        ) ?: null;
    }
}
