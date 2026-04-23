<?php

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Engine de Migrations
//  Incluso automaticamente pelo auth.php após login.
//
//  Nos arquivos de migration use as funções helper abaixo:
//    adicionarcampotb('tabela', 'coluna', TP_VARCHAR100)
//    removercampotb('tabela', 'coluna')
//    executarsql('ALTER TABLE ...')
// ══════════════════════════════════════════════════════════════════════════════

// ── Constantes de tipo SQL ────────────────────────────────────────────────────
if (!defined('TP_VARCHAR50')) {
    define('TP_VARCHAR50',  "VARCHAR(50)  NOT NULL DEFAULT ''");
    define('TP_VARCHAR100', "VARCHAR(100) NOT NULL DEFAULT ''");
    define('TP_VARCHAR200', "VARCHAR(200) NOT NULL DEFAULT ''");
    define('TP_VARCHAR500', "VARCHAR(500) NOT NULL DEFAULT ''");
    define('TP_TEXT',       'TEXT NULL');
    define('TP_INT',        'INT NOT NULL DEFAULT 0');
    define('TP_BIGINT',     'BIGINT NOT NULL DEFAULT 0');
    define('TP_TINYINT',    'TINYINT(1) NOT NULL DEFAULT 0');
    define('TP_DECIMAL102', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    define('TP_DECIMAL152', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00');
    define('TP_DATETIME',   'DATETIME NULL');
    define('TP_DATE',       'DATE NULL');
}

// ── Contexto interno (compartilhado entre runner e helpers) ───────────────────
function _migCtx(string $key, $val = null)
{
    static $ctx = ['pdo' => null, 'log' => []];
    if ($val !== null) {
        $ctx[$key] = $val;
    }
    return $ctx[$key];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Funções helper — use dentro dos arquivos de migration
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Adiciona uma coluna à tabela se ela ainda não existir.
 *
 * adicionarcampotb('maquinas', 'maq_ativo', TP_TINYINT)
 */
function adicionarcampotb(string $tabela, string $coluna, string $tipo): void
{
    $pdo = _migCtx('pdo');
    $log = _migCtx('log');

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$tabela, $coluna]);

    $sql = "ALTER TABLE `{$tabela}` ADD COLUMN `{$coluna}` {$tipo}";

    if ((int) $st->fetchColumn() > 0) {
        $log[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'coluna já existe'];
    } else {
        $pdo->exec($sql);
        $log[] = ['status' => 'ok', 'sql' => $sql, 'msg' => ''];
    }

    _migCtx('log', $log);
}

/**
 * Cria uma tabela se ela ainda não existir.
 * Passe o corpo do CREATE TABLE sem "CREATE TABLE `nome` (" e sem o ")" final —
 * apenas as definições de colunas e índices.
 *
 * adicionartabela('minha_tabela', "
 *     id   INT NOT NULL AUTO_INCREMENT,
 *     nome VARCHAR(100) NOT NULL DEFAULT '',
 *     PRIMARY KEY (id)
 * ");
 */
function adicionartabela(string $tabela, string $definicao): void
{
    $pdo = _migCtx('pdo');
    $log = _migCtx('log');

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$tabela]);

    $sql = "CREATE TABLE `{$tabela}` ({$definicao}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if ((int) $st->fetchColumn() > 0) {
        $log[] = ['status' => 'skip', 'sql' => "CREATE TABLE `{$tabela}`", 'msg' => 'tabela já existe'];
    } else {
        $pdo->exec($sql);
        $log[] = ['status' => 'ok', 'sql' => $sql, 'msg' => ''];
    }

    _migCtx('log', $log);
}

/**
 * Remove uma coluna da tabela se ela existir.
 *
 * removercampotb('tabela', 'coluna_antiga')
 */
function removercampotb(string $tabela, string $coluna): void
{
    $pdo = _migCtx('pdo');
    $log = _migCtx('log');

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$tabela, $coluna]);

    $sql = "ALTER TABLE `{$tabela}` DROP COLUMN `{$coluna}`";

    if ((int) $st->fetchColumn() === 0) {
        $log[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'coluna não existe'];
    } else {
        $pdo->exec($sql);
        $log[] = ['status' => 'ok', 'sql' => $sql, 'msg' => ''];
    }

    _migCtx('log', $log);
}

/**
 * Executa SQL livre (CREATE TABLE, INSERT de dados iniciais, etc.).
 * Não verifica idempotência — use IF NOT EXISTS ou lógica própria.
 *
 * executarsql("CREATE TABLE IF NOT EXISTS ...")
 */
function executarsql(string $sql): void
{
    $pdo = _migCtx('pdo');
    $log = _migCtx('log');

    $pdo->exec($sql);
    $log[] = ['status' => 'ok', 'sql' => $sql, 'msg' => ''];

    _migCtx('log', $log);
}

// ══════════════════════════════════════════════════════════════════════════════
//  Runner — chamado pelo auth.php uma vez por sessão
// ══════════════════════════════════════════════════════════════════════════════

function rodarMigrations(): void
{
    if (!empty($_SESSION['mig_ok'])) {
        return;
    }

    try {
        $pdo = dbPDO();

        // Bootstrap: garante que as tabelas de controle existam
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS db_migrations (
                mig_id           VARCHAR(60)  NOT NULL,
                mig_titulo       VARCHAR(200) NOT NULL DEFAULT '',
                mig_executado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (mig_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS db_migrations_sql (
                mig_id            VARCHAR(60) NOT NULL,
                mig_sql           TEXT        NOT NULL,
                mig_atualizado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (mig_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $dir = $_SERVER['DOCUMENT_ROOT'] . '/migrations';
        if (!is_dir($dir)) {
            $_SESSION['mig_ok'] = true;
            return;
        }

        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        $applied = $pdo->query('SELECT mig_id FROM db_migrations')
                       ->fetchAll(PDO::FETCH_COLUMN);

        $hasError = false;

        foreach ($files as $file) {
            $id = basename($file, '.php');
            if (in_array($id, $applied, true)) {
                continue;
            }

            // Prepara contexto para os helpers
            _migCtx('pdo', $pdo);
            _migCtx('log', []);

            try {
                // Executa o arquivo de migration em escopo isolado
                (static function (string $_mig_file): void {
                    require $_mig_file;
                })($file);

                $log = _migCtx('log');

                // Registra como aplicada
                $pdo->prepare('INSERT IGNORE INTO db_migrations (mig_id, mig_titulo) VALUES (?, ?)')
                    ->execute([$id, $id]);

                // Grava o log de SQL gerado
                $pdo->prepare('
                    INSERT INTO db_migrations_sql (mig_id, mig_sql) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE mig_sql = VALUES(mig_sql), mig_atualizado_em = NOW()
                ')->execute([$id, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]);

            } catch (Throwable $e) {
                error_log('[MIG] Falha em ' . $id . ': ' . $e->getMessage());
                echo '<script>console.error(' . json_encode('[MIG] ' . $id . ': ' . $e->getMessage()) . ')</script>';
                $hasError = true;
                break;
            }
        }

        if (!$hasError) {
            $_SESSION['mig_ok'] = true;
        }

    } catch (Throwable $e) {
        error_log('[MIG] Erro crítico: ' . $e->getMessage());
        echo '<script>console.error(' . json_encode('[MIG] Erro crítico: ' . $e->getMessage()) . ')</script>';
    }
}
