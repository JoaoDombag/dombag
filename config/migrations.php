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

// ── Registro inline de migrations ─────────────────────────────────────────────
function _migRegistry(?array $entry = null): array
{
    static $registry = [];
    if ($entry !== null) {
        $registry[] = $entry;
    }
    return $registry;
}

/**
 * Registra uma migration inline, sem precisar criar arquivo.
 *
 * registrarmigration('2026_04_27_01_nova_coluna', function() {
 *     adicionarcampotb('MAQUINAS', 'MAQ_NOVA', TP_VARCHAR100);
 * });
 */
function registrarmigration(string $id, callable $fn): void
{
    _migRegistry(['id' => $id, 'fn' => $fn]);
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
 * Adiciona uma chave única à tabela se ela ainda não existir (por nome).
 *
 * adicionarchaveunica('clientes', 'uq_cli_cnpj', 'cli_cnpj')
 */
function adicionarchaveunica(string $tabela, string $nomeChave, string $colunas): void
{
    $pdo = _migCtx('pdo');
    $log = _migCtx('log');

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$tabela, $nomeChave]);

    $sql = "ALTER TABLE `{$tabela}` ADD UNIQUE KEY `{$nomeChave}` ({$colunas})";

    if ((int) $st->fetchColumn() > 0) {
        $log[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'chave única já existe'];
    } else {
        $pdo->exec($sql);
        $log[] = ['status' => 'ok', 'sql' => $sql, 'msg' => ''];
    }

    _migCtx('log', $log);
}

/**
 * Adiciona uma foreign key à tabela se ela ainda não existir (por nome).
 *
 * adicionarfk('vendas', 'fk_ven_cliente', 'cli_codigo', 'clientes', 'cli_codigo', 'SET NULL', 'CASCADE')
 */
function adicionarfk(
    string $tabela,
    string $nomeFk,
    string $coluna,
    string $tabelaRef,
    string $colunaRef,
    string $onDelete = 'RESTRICT',
    string $onUpdate = 'CASCADE'
): void {
    $pdo = _migCtx('pdo');
    $log = _migCtx('log');

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $st->execute([$tabela, $nomeFk]);

    $sql = "ALTER TABLE `{$tabela}` ADD CONSTRAINT `{$nomeFk}` "
         . "FOREIGN KEY (`{$coluna}`) REFERENCES `{$tabelaRef}` (`{$colunaRef}`) "
         . "ON DELETE {$onDelete} ON UPDATE {$onUpdate}";

    if ((int) $st->fetchColumn() > 0) {
        $log[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'foreign key já existe'];
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

    try {
        $pdo->exec($sql);
        $log[] = ['status' => 'ok', 'sql' => $sql, 'msg' => ''];
    } catch (PDOException $e) {
        $mysqlErrno = $e->errorInfo[1] ?? null;

        // MySQL 5.7/InnoDB bug: CREATE TABLE IF NOT EXISTS lança 1022 quando o nome
        // de uma FK constraint já existe no schema — tratar como skip, não como erro fatal.
        $isCreateIfNotExists = stripos($sql, 'CREATE TABLE IF NOT EXISTS') !== false
                            || stripos($sql, 'CREATE INDEX IF NOT EXISTS') !== false;

        // ADD (UNIQUE) KEY/INDEX não tem sintaxe "IF NOT EXISTS" no MySQL: se o nome
        // da chave já existe (1061), é sempre seguro tratar como skip idempotente.
        $isDuplicateKeyName = $mysqlErrno === 1061;

        if (($isCreateIfNotExists && $e->getCode() === '23000') || $isDuplicateKeyName) {
            $log[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'objeto já existe: ' . $e->getMessage()];
        } else {
            throw $e;
        }
    }

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
            CREATE TABLE IF NOT EXISTS DB_MIGRATIONS (
                MIG_ID           VARCHAR(60)  NOT NULL,
                MIG_TITULO       VARCHAR(200) NOT NULL DEFAULT '',
                MIG_EXECUTADO_EM DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (MIG_ID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS DB_MIGRATIONS_SQL (
                MIG_ID            VARCHAR(60) NOT NULL,
                MIG_SQL           TEXT        NOT NULL,
                MIG_ATUALIZADO_EM DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (MIG_ID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $applied = $pdo->query('SELECT MIG_ID FROM DB_MIGRATIONS')
                       ->fetchAll(PDO::FETCH_COLUMN);

        $hasError = false;

        // ── Migrations por arquivo (legado) ───────────────────────────────────
        $dir   = $_SERVER['DOCUMENT_ROOT'] . '/migrations';
        $files = is_dir($dir) ? (glob($dir . '/*.php') ?: []) : [];
        sort($files);

        foreach ($files as $file) {
            $id = basename($file, '.php');
            if (in_array($id, $applied, true)) {
                continue;
            }

            _migCtx('pdo', $pdo);
            _migCtx('log', []);

            try {
                (static function (string $_mig_file): void {
                    require $_mig_file;
                })($file);

                $log = _migCtx('log');

                $pdo->prepare('INSERT IGNORE INTO DB_MIGRATIONS (MIG_ID, MIG_TITULO) VALUES (?, ?)')
                    ->execute([$id, $id]);

                $pdo->prepare('
                    INSERT INTO DB_MIGRATIONS_SQL (MIG_ID, MIG_SQL) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE MIG_SQL = VALUES(MIG_SQL), MIG_ATUALIZADO_EM = NOW()
                ')->execute([$id, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]);

            } catch (Throwable $e) {
                error_log('[MIG] Falha em ' . $id . ': ' . $e->getMessage());
                echo '<script>console.error(' . json_encode('[MIG] ' . $id . ': ' . $e->getMessage()) . ')</script>';
                $hasError = true;
                break;
            }
        }

        // ── Migrations registradas inline via registrarmigration() ────────────
        if (!$hasError) {
            foreach (_migRegistry() as $entry) {
                $id = $entry['id'];
                if (in_array($id, $applied, true)) {
                    continue;
                }

                _migCtx('pdo', $pdo);
                _migCtx('log', []);

                try {
                    ($entry['fn'])();

                    $log = _migCtx('log');

                    $pdo->prepare('INSERT IGNORE INTO DB_MIGRATIONS (MIG_ID, MIG_TITULO) VALUES (?, ?)')
                        ->execute([$id, $id]);

                    $pdo->prepare('
                        INSERT INTO DB_MIGRATIONS_SQL (MIG_ID, MIG_SQL) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE MIG_SQL = VALUES(MIG_SQL), MIG_ATUALIZADO_EM = NOW()
                    ')->execute([$id, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]);

                } catch (Throwable $e) {
                    error_log('[MIG] Falha em ' . $id . ': ' . $e->getMessage());
                    echo '<script>console.error(' . json_encode('[MIG] ' . $id . ': ' . $e->getMessage()) . ')</script>';
                    $hasError = true;
                    break;
                }
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
