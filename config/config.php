<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Configuração Central
//  Inclua com: require_once $_SERVER['DOCUMENT_ROOT'].'/config/config.php';
// ══════════════════════════════════════════════════

// ── MySQL (banco local do site) ───────────────────
// Em produção (InfinityFree): altere para as credenciais do painel
// Painel → MySQL Databases → copie host, nome do banco, usuário e senha
define('DB_HOST',    'localhost');
define('DB_NAME',    'dombag');      // ex: epiz_12345678_dombag
define('DB_USER',    'root');        // ex: epiz_12345678
define('DB_PASS',    'Dombag@12345');
define('DB_CHARSET', 'utf8mb4');

// ── PostgreSQL (ERP Yzidro — somente leitura) ─────
// InfinityFree não suporta PostgreSQL. dbPG() retorna null
// e os módulos de ERP ficam desativados silenciosamente.
define('PG_HOST',   'pg-yzidro-004.yzidro.com');
define('PG_PORT',   '44551');
define('PG_DBNAME', '004703_dom_bag_ltda');
define('PG_USER',   '004703consulta');
define('PG_PASS',   'Yz#2025Consulta');

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
