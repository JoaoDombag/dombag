<?php
// TEMPORÁRIO — delete depois de diagnosticar
require_once __DIR__ . '/config/config.php';

echo "<pre>\n";
echo "DB_HOST = " . DB_HOST . "\n";
echo "DB_PORT = " . DB_PORT . "\n";
echo "DB_NAME = " . DB_NAME . "\n";
echo "DB_USER = " . DB_USER . "\n";
echo "DB_PASS = " . (DB_PASS !== '' ? str_repeat('*', strlen(DB_PASS)) : '(vazio)') . "\n\n";

try {
    $pdo = dbPDO();
    echo "✅ Conexão OK\n";
    $r = $pdo->query("SHOW TABLES LIKE 'USUARIOS'")->fetchAll();
    echo "Tabela USUARIOS: " . (count($r) ? "✅ existe" : "❌ não existe (migration vai criar no login)") . "\n";
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
echo "</pre>\n";
