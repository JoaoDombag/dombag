<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// Apenas admins podem executar migrações
$__st = dbPDO()->prepare('SELECT USU_ADMIN FROM USUARIOS WHERE USU_CODIGO = ?');
$__st->execute([usuCodigo()]);
if (!(bool) $__st->fetchColumn()) {
    http_response_code(403);
    exit('Acesso negado.');
}

$pdo = dbPDO();

// Verifica o tipo atual da coluna
$col = $pdo->query("SHOW COLUMNS FROM ITENS_VENDAS LIKE 'IV_STATUS'")->fetch();
echo '<pre>Coluna atual: ';
print_r($col);
echo '</pre>';

$type = strtolower($col['Type'] ?? '');

if (strpos($type, 'enum') !== false) {
    // Altera o ENUM adicionando os novos valores
    $sql = "ALTER TABLE ITENS_VENDAS MODIFY COLUMN IV_STATUS
        ENUM('Pendente de produção','Produção','Aguardando','Aguardando envio','Aguardando expedição','Finalizado')
        NOT NULL DEFAULT 'Pendente de produção'";
    echo '<p>Executando: <code>' . htmlspecialchars($sql) . '</code></p>';
    $pdo->exec($sql);
    echo '<p style="color:green;font-weight:bold">✔ ALTER TABLE executado com sucesso!</p>';
} elseif (strpos($type, 'varchar') !== false) {
    // Se for VARCHAR, só garante que tem tamanho suficiente
    $sql = "ALTER TABLE ITENS_VENDAS MODIFY COLUMN IV_STATUS VARCHAR(60) DEFAULT 'Pendente de produção'";
    $pdo->exec($sql);
    echo '<p style="color:green;font-weight:bold">✔ VARCHAR expandido com sucesso!</p>';
} else {
    echo '<p style="color:orange">Tipo não reconhecido: ' . htmlspecialchars($type) . ' — execute manualmente.</p>';
}

// Confirma resultado
$col2 = $pdo->query("SHOW COLUMNS FROM ITENS_VENDAS LIKE 'IV_STATUS'")->fetch();
echo '<pre>Coluna após migração: ';
print_r($col2);
echo '</pre>';
echo '<p><a href="/pcp/kanban">← Voltar ao Kanban</a></p>';
