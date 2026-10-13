<?php
// Executa todos os testes do chat:  php tests/chat/run.php
require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*_test.php') as $arquivo) {
    require $arquivo;
}

$falhas = $GLOBALS['__falhas'];
echo "\n" . $GLOBALS['__ok'] . ' verificações ok, ' . count($falhas) . " teste(s) com falha.\n";
exit($falhas ? 1 : 0);
