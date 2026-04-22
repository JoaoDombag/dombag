<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Configuração Central
//  Inclua com: require_once $_SERVER['DOCUMENT_ROOT'].'/config/config.php';
// ══════════════════════════════════════════════════

// ── Credenciais: variáveis de ambiente (produção) ou secrets.php (local) ──
if (getenv('DB_PASS') !== false) {
    // Produção: lê das variáveis de ambiente configuradas no Railway
define('DB_HOST', getenv('MYSQLHOST'));
define('DB_PORT', getenv('MYSQLPORT'));
define('DB_NAME', getenv('MYSQLDATABASE'));
define('DB_USER', getenv('MYSQLUSER'));
define('DB_PASS', getenv('MYSQLPASSWORD'));
    define('DB_CHARSET', 'utf8mb4');

    define('PG_HOST',   getenv('PG_HOST')   ?: '');
    define('PG_PORT',   getenv('PG_PORT')   ?: '5432');
    define('PG_DBNAME', getenv('PG_DBNAME') ?: '');
    define('PG_USER',   getenv('PG_USER')   ?: '');
    define('PG_PASS',   getenv('PG_PASS')   ?: '');
} else {
    // Desenvolvimento local: lê do arquivo secrets.php
    $_secrets_file = __DIR__ . '/secrets.php';
    if (!file_exists($_secrets_file)) {
        die('Arquivo config/secrets.php não encontrado. Copie secrets.example.php e preencha as credenciais.');
    }
    require_once $_secrets_file;
    unset($_secrets_file);
}

// ── Helpers de conexão ────────────────────────────
if (!function_exists('dbPDO')) {
    function dbPDO(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }
        return $pdo;
    }

    function dbPG()
    {
        // Retorna null se a extensão pgsql não estiver disponível (ex: InfinityFree)
        if (!function_exists('pg_connect')) {
            return null;
        }
        return @pg_connect(
            'host=' . PG_HOST . ' port=' . PG_PORT .
            ' dbname=' . PG_DBNAME . ' user=' . PG_USER . ' password=' . PG_PASS
        ) ?: null;
    }
}
