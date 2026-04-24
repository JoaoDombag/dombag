<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Configuração Central
//  Inclua com: require_once $_SERVER['DOCUMENT_ROOT'].'/config/config.php';
// ══════════════════════════════════════════════════

// ── MySQL ─────────────────────────────────────────
// Em produção (Railway) lê as variáveis do serviço MySQL.
// Em desenvolvimento lê os valores locais abaixo.
define('DB_HOST',    getenv('MYSQLHOST')     );
define('DB_PORT',    getenv('MYSQLPORT')     );
define('DB_NAME',    getenv('MYSQLDATABASE') );
define('DB_USER',    getenv('MYSQLUSER')     );
define('DB_PASS',    getenv('MYSQLPASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ── PostgreSQL (ERP Yzidro — somente leitura) ─────
define('PG_HOST',   getenv('PG_HOST')   ?: 'pg-yzidro-004.yzidro.com');
define('PG_PORT',   getenv('PG_PORT')   ?: '44551');
define('PG_DBNAME', getenv('PG_DBNAME') ?: '004703_dom_bag_ltda');
define('PG_USER',   getenv('PG_USER')   ?: '004703consulta');
define('PG_PASS',   getenv('PG_PASS')   ?: 'Yz#2025Consulta');

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
