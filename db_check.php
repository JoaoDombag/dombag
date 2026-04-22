<?php
// TEMPORÁRIO — delete depois de diagnosticar
// Acesse: https://dombag-production.up.railway.app/db_check.php

$host = getenv('MYSQLHOST')     ?: '(não definido)';
$port = getenv('MYSQLPORT')     ?: '(não definido)';
$name = getenv('MYSQLDATABASE') ?: '(não definido)';
$user = getenv('MYSQLUSER')     ?: '(não definido)';
$pass = getenv('MYSQLPASSWORD') ?: '';

echo "<pre>\n";
echo "MYSQLHOST     = $host\n";
echo "MYSQLPORT     = $port\n";
echo "MYSQLDATABASE = $name\n";
echo "MYSQLUSER     = $user\n";
echo "MYSQLPASSWORD = " . (strlen($pass) > 0 ? str_repeat('*', strlen($pass)) : '(vazio)') . "\n\n";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✅ Conexão OK\n";

    // Verifica se tabela USUARIOS existe
    $r = $pdo->query("SHOW TABLES LIKE 'USUARIOS'")->fetchAll();
    echo "Tabela USUARIOS: " . (count($r) ? "✅ existe" : "❌ não existe") . "\n";
} catch (PDOException $e) {
    echo "❌ Erro PDO: " . $e->getMessage() . "\n";
}
echo "</pre>\n";
