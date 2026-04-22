<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Credenciais (NÃO versionar este arquivo)
//  Copie secrets.example.php → secrets.php e preencha
// ══════════════════════════════════════════════════

// ── MySQL ─────────────────────────────────────────
define('DB_HOST', getenv('MYSQLHOST'));
define('DB_PORT', getenv('MYSQLPORT'));
define('DB_NAME', getenv('MYSQLDATABASE'));
define('DB_USER', getenv('MYSQLUSER'));
define('DB_PASS', getenv('MYSQLPASSWORD'));

// ── PostgreSQL (ERP Yzidro — somente leitura) ─────
define('PG_HOST',   'pg-yzidro-004.yzidro.com');
define('PG_PORT',   '44551');
define('PG_DBNAME', '004703_dom_bag_ltda');
define('PG_USER',   '004703consulta');
define('PG_PASS',   'Yz#2025Consulta');
