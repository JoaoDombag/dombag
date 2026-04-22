<?php

// ══════════════════════════════════════════════════
//  DOMBAG — Template de credenciais
//  Copie este arquivo para secrets.php e preencha
//  secrets.php está no .gitignore e nunca deve ser commitado
// ══════════════════════════════════════════════════

// ── MySQL ─────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'dombag');
define('DB_USER',    'root');
define('DB_PASS',    'SENHA_MYSQL_AQUI');
define('DB_CHARSET', 'utf8mb4');

// ── PostgreSQL (ERP Yzidro — somente leitura) ─────
define('PG_HOST',   'HOST_POSTGRES_AQUI');
define('PG_PORT',   '5432');
define('PG_DBNAME', 'NOME_BANCO_AQUI');
define('PG_USER',   'USUARIO_AQUI');
define('PG_PASS',   'SENHA_POSTGRES_AQUI');
