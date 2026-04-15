<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
$pdo = dbPDO();
$rows = $pdo->query("SELECT PAR_CHAVE, PAR_VALOR FROM parametros WHERE PAR_CHAVE LIKE 'dashboard_widgets%'")->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: text/plain');
foreach ($rows as $r) {
    echo $r['PAR_CHAVE'] . "\n";
    echo $r['PAR_VALOR'] . "\n\n";
}
if (!$rows) echo "Nenhuma preferência guardada.\n";
