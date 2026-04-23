<?php
// TEMPORÁRIO — delete depois de diagnosticar

echo "<pre>\n";

// Lista todas as variáveis de ambiente que contêm MYSQL ou DATABASE
echo "=== Variáveis de ambiente (MYSQL / DATABASE) ===\n";
foreach ($_SERVER as $k => $v) {
    if (stripos($k, 'mysql') !== false || stripos($k, 'database') !== false) {
        $isPwd = stripos($k, 'pass') !== false || stripos($k, 'pwd') !== false;
        echo "$k = " . ($isPwd ? str_repeat('*', strlen($v)) : $v) . "\n";
    }
}

echo "\n=== Tentativa de conexão ===\n";
$host = getenv('MYSQLHOST')     ?: '(não definido)';
$port = getenv('MYSQLPORT')     ?: '3306';
$name = getenv('MYSQLDATABASE') ?: '(não definido)';
$user = getenv('MYSQLUSER')     ?: '(não definido)';
$pass = getenv('MYSQLPASSWORD') ?: '';

// Tenta também com MYSQL_ROOT_PASSWORD se MYSQLPASSWORD falhar
$passAlt = getenv('MYSQL_ROOT_PASSWORD') ?: '';

echo "Usando: $user @ $host:$port / $name\n\n";

foreach (['MYSQLPASSWORD' => $pass, 'MYSQL_ROOT_PASSWORD' => $passAlt] as $varName => $pwd) {
    if ($pwd === '') { echo "$varName = (vazio) — pulando\n"; continue; }
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "✅ Conectou com $varName\n";
        $r = $pdo->query("SHOW TABLES LIKE 'USUARIOS'")->fetchAll();
        echo "Tabela USUARIOS: " . (count($r) ? "✅ existe" : "❌ não existe") . "\n";
        break;
    } catch (PDOException $e) {
        echo "❌ $varName falhou: " . $e->getMessage() . "\n";
    }
}

echo "</pre>\n";
