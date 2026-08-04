<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Reunião de Planejamento (Programação de Pedidos)
//  Grid local (MySQL) de pedidos/OPs adicionados à programação.
//  Candidatos importados do ERP PostgreSQL (tabela PRODUCAO), somente leitura.
//  "Marcar como finalizado" é um controle local — não altera o ERP.
// ══════════════════════════════════════════════════════════════

function p2Escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function p2SanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function p2FmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function p2FmtDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function p2EnsureTabela(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS PCP_PROGRAMACAO (
            PRG_CODIGO          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            PROD_CODIGO         INT UNSIGNED  NOT NULL,
            VENDA_REF           VARCHAR(40)   NULL,
            VEN_COD_PEDIDO      VARCHAR(40)   NULL,
            CLI_CODIGO          INT           NULL,
            CLI_NOME            VARCHAR(255)  NULL,
            PROD_DATA           DATE          NULL,
            DATA_ENTREGA        DATE          NULL,
            DATA_ENTREGA_VENDEDOR DATE        NULL,
            DATA_ENTREGA_ESPERADA DATE        NULL,
            PROD_TOTAL          DECIMAL(14,2) NULL,
            TIPO_PRODUCAO       VARCHAR(5)    NULL,
            PRG_ADICIONADO_EM   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRG_ADICIONADO_POR  VARCHAR(120)  NULL,
            PRG_FINALIZADO      TINYINT(1)    NOT NULL DEFAULT 0,
            PRG_FINALIZADO_EM   DATETIME      NULL,
            PRG_FINALIZADO_POR  VARCHAR(120)  NULL,
            INDEX idx_prod (PROD_CODIGO),
            INDEX idx_finalizado (PRG_FINALIZADO)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach (['DATA_ENTREGA_VENDEDOR', 'DATA_ENTREGA_ESPERADA'] as $coluna) {
        $chk = $db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $chk->execute(['PCP_PROGRAMACAO', $coluna]);
        if (!(int) $chk->fetchColumn()) {
            $db->exec("ALTER TABLE PCP_PROGRAMACAO ADD COLUMN {$coluna} DATE NULL");
        }
    }

    $chkCol = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $chkCol->execute(['PCP_PROGRAMACAO', 'ETP_CODIGO']);
    if (!(int) $chkCol->fetchColumn()) {
        $db->exec('ALTER TABLE PCP_PROGRAMACAO ADD COLUMN ETP_CODIGO INT UNSIGNED NULL DEFAULT NULL');
    }

    $chkFk = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $chkFk->execute(['PCP_PROGRAMACAO', 'FK_PRG_ETAPA']);
    if (!(int) $chkFk->fetchColumn()) {
        try {
            $db->exec('
                ALTER TABLE PCP_PROGRAMACAO
                ADD CONSTRAINT FK_PRG_ETAPA
                FOREIGN KEY (ETP_CODIGO) REFERENCES ETAPA_PRODUCAO (ETP_CODIGO)
                ON DELETE SET NULL ON UPDATE CASCADE
            ');
        } catch (Throwable) {
            // ETAPA_PRODUCAO pode ainda não existir num primeiro carregamento; a FK é adicionada
            // assim que a migration correspondente rodar.
        }
    }

    // Pedidos programados antes da coluna existir começam como "Pendente de produção"
    $etpPadrao = p2EtapaPadraoCodigo($db);
    if ($etpPadrao !== null) {
        $db->prepare('UPDATE PCP_PROGRAMACAO SET ETP_CODIGO = ? WHERE ETP_CODIGO IS NULL')
           ->execute([$etpPadrao]);
    }
}

// ── Etapa "Pendente de produção" (padrão para OPs recém-adicionadas) ─────────
function p2EtapaPadraoCodigo(PDO $db): ?int
{
    try {
        $cod = $db->query("SELECT ETP_CODIGO FROM ETAPA_PRODUCAO WHERE ETP_DESCRICAO = 'Pendente de produção' LIMIT 1")
                  ->fetchColumn();
        return $cod !== false ? (int) $cod : null;
    } catch (Throwable) {
        return null;
    }
}

// ── ERP: OPs candidatas para importar/programar (FINALIZADO = 'N') ───────────
function p2FetchOPsDisponiveis($pg, array $filtros, array $excluirCodigos): array
{
    $params = [];
    $extras = [];

    if ($filtros['cod_op'] !== '') {
        $params[] = (int) $filtros['cod_op'];
        $extras[] = 'V.PROD_CODIGO = $' . count($params);
    }
    if ($filtros['cod_venda'] !== '') {
        $params[] = '%' . $filtros['cod_venda'] . '%';
        $n = count($params);
        $extras[] = "(V.VENDA_REF ILIKE \${$n} OR V.VEN_COD_PEDIDO::text ILIKE \${$n})";
    }
    if ($filtros['cliente'] !== '') {
        $params[] = '%' . $filtros['cliente'] . '%';
        $extras[] = 'C.CLI_NOME ILIKE $' . count($params);
    }
    foreach ($excluirCodigos as $cod) {
        $extras[] = 'V.PROD_CODIGO <> ' . (int) $cod;
    }

    $where = 'V.EMP_CODIGO = 1 AND TRIM(V.FINALIZADO) = \'N\'';
    if ($extras) {
        $where .= "\n       AND " . implode("\n       AND ", $extras);
    }

    $sql = "
        SELECT V.PROD_CODIGO, V.PROD_DATA, V.PROD_TOTAL, V.PROD_OBS, V.PROD_TIPO, V.FINALIZADO
              ,V.DATA_ENTREGA, V.VEN_COD_PEDIDO, C.CLI_CODIGO, C.CLI_NOME, V.LOTE_CLIENTE
              ,V.VENDA_REF, V.TIPO_PRODUCAO
          FROM      PRODUCAO       V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO   = V.CLI_CODIGO
         WHERE {$where}
         ORDER BY V.PROD_CODIGO DESC
         LIMIT 200
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar as OPs no ERP: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── ERP: dados atuais de uma única OP (usado ao adicionar/exibir na grid) ────
function p2FetchOPUnica($pg, int $prodCodigo): ?array
{
    $sql = "
        SELECT V.PROD_CODIGO, V.PROD_DATA, V.PROD_TOTAL, V.PROD_TIPO, V.FINALIZADO
              ,V.DATA_ENTREGA, V.VEN_COD_PEDIDO, C.CLI_CODIGO, C.CLI_NOME
              ,V.VENDA_REF, V.TIPO_PRODUCAO
          FROM      PRODUCAO       V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO   = V.CLI_CODIGO
         WHERE V.EMP_CODIGO = 1 AND V.PROD_CODIGO = $1
    ";
    $res = @pg_query_params($pg, $sql, [$prodCodigo]);
    if (!$res) {
        return null;
    }
    $row = pg_fetch_assoc($res) ?: null;
    pg_free_result($res);
    return $row ?: null;
}

// ── Grid local: pedidos programados (ativos), com dados atualizados do ERP ───
function p2FetchProgramacao(PDO $db, $pg): array
{
    $stmt = $db->query('
        SELECT p.PRG_CODIGO, p.PROD_CODIGO, p.VENDA_REF, p.VEN_COD_PEDIDO, p.CLI_CODIGO, p.CLI_NOME,
               p.PROD_DATA, p.DATA_ENTREGA, p.DATA_ENTREGA_VENDEDOR, p.DATA_ENTREGA_ESPERADA,
               p.PROD_TOTAL, p.TIPO_PRODUCAO,
               p.PRG_ADICIONADO_EM, p.PRG_ADICIONADO_POR,
               p.ETP_CODIGO, e.ETP_DESCRICAO
        FROM PCP_PROGRAMACAO p
        LEFT JOIN ETAPA_PRODUCAO e ON e.ETP_CODIGO = p.ETP_CODIGO
        WHERE p.PRG_FINALIZADO = 0
        ORDER BY p.PRG_ADICIONADO_EM DESC
    ');
    $locais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$locais || !$pg) {
        return array_map(static function (array $l): array {
            $l = array_change_key_case($l, CASE_LOWER);
            $l['finalizado_erp'] = null;
            return $l;
        }, $locais);
    }

    $codigos = array_map(static fn ($l) => (int) $l['PROD_CODIGO'], $locais);
    $codigosSql = implode(',', $codigos);

    $sql = "
        SELECT V.PROD_CODIGO, V.PROD_DATA, V.PROD_TOTAL, V.PROD_TIPO, V.FINALIZADO
              ,V.DATA_ENTREGA, V.VEN_COD_PEDIDO, C.CLI_CODIGO, C.CLI_NOME, V.VENDA_REF, V.TIPO_PRODUCAO
          FROM      PRODUCAO       V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO   = V.CLI_CODIGO
         WHERE V.PROD_CODIGO IN ({$codigosSql})
    ";
    $res = @pg_query($pg, $sql);
    $erpByCodigo = [];
    if ($res) {
        foreach (pg_fetch_all($res) ?: [] as $r) {
            $erpByCodigo[(int) $r['prod_codigo']] = $r;
        }
        pg_free_result($res);
    }

    $out = [];
    foreach ($locais as $l) {
        $l = array_change_key_case($l, CASE_LOWER);
        $erp = $erpByCodigo[(int) $l['prod_codigo']] ?? null;
        if ($erp) {
            $l['cli_nome'] = $erp['cli_nome'] ?? $l['cli_nome'];
            $l['cli_codigo'] = $erp['cli_codigo'] ?? $l['cli_codigo'];
            $l['data_entrega'] = $erp['data_entrega'] ?? $l['data_entrega'];
            $l['prod_total'] = $erp['prod_total'] ?? $l['prod_total'];
            $l['venda_ref'] = $erp['venda_ref'] ?? $l['venda_ref'];
            $l['ven_cod_pedido'] = $erp['ven_cod_pedido'] ?? $l['ven_cod_pedido'];
            $l['tipo_producao'] = $erp['tipo_producao'] ?? $l['tipo_producao'];
            $l['finalizado_erp'] = trim((string) $erp['finalizado']);
        } else {
            $l['finalizado_erp'] = null;
        }
        $out[] = $l;
    }
    return $out;
}

$pdo = dbPDO();
p2EnsureTabela($pdo);
$pg = dbPG();

// ── AJAX: buscar OPs candidatas (modal "Adicionar Pedido à Programação") ─────
if (($_GET['action'] ?? '') === 'buscar_ops') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    $filtros = [
        'cod_op'    => trim((string) ($_GET['cod_op'] ?? '')),
        'cod_venda' => trim((string) ($_GET['cod_venda'] ?? '')),
        'cliente'   => trim((string) ($_GET['cliente'] ?? '')),
    ];
    try {
        $stmt = $pdo->query('SELECT PROD_CODIGO FROM PCP_PROGRAMACAO WHERE PRG_FINALIZADO = 0');
        $jaAdicionados = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $rows = p2FetchOPsDisponiveis($pg, $filtros, $jaAdicionados);
        echo json_encode(['ops' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: adicionar OP à programação ──────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'adicionar') {
    header('Content-Type: application/json; charset=utf-8');
    $prodCodigo = (int) ($_POST['prod_codigo'] ?? 0);
    if ($prodCodigo <= 0) {
        echo json_encode(['error' => 'OP inválida.']);
        exit;
    }
    $dataEntregaVendedor = p2SanitizeDate($_POST['data_entrega_vendedor'] ?? '');
    $dataEntregaEsperada = p2SanitizeDate($_POST['data_entrega_esperada'] ?? '');
    if ($dataEntregaVendedor === '' || $dataEntregaEsperada === '') {
        echo json_encode(['error' => 'Informe a Data de entrega do vendedor e a Data de entrega esperada.']);
        exit;
    }
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM PCP_PROGRAMACAO WHERE PROD_CODIGO = ? AND PRG_FINALIZADO = 0');
        $stmt->execute([$prodCodigo]);
        if ((int) $stmt->fetchColumn() > 0) {
            echo json_encode(['error' => 'Esta OP já está na programação.']);
            exit;
        }

        $op = p2FetchOPUnica($pg, $prodCodigo);
        if (!$op) {
            echo json_encode(['error' => 'OP não encontrada no ERP.']);
            exit;
        }
        if (trim((string) $op['finalizado']) !== 'N') {
            echo json_encode(['error' => 'Esta OP já está finalizada no ERP e não pode ser importada.']);
            exit;
        }

        $ins = $pdo->prepare('
            INSERT INTO PCP_PROGRAMACAO
                (PROD_CODIGO, VENDA_REF, VEN_COD_PEDIDO, CLI_CODIGO, CLI_NOME, PROD_DATA, DATA_ENTREGA,
                 DATA_ENTREGA_VENDEDOR, DATA_ENTREGA_ESPERADA, PROD_TOTAL, TIPO_PRODUCAO, ETP_CODIGO, PRG_ADICIONADO_POR)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $ins->execute([
            $prodCodigo,
            $op['venda_ref'],
            $op['ven_cod_pedido'],
            $op['cli_codigo'],
            $op['cli_nome'],
            $op['prod_data'] ? substr((string) $op['prod_data'], 0, 10) : null,
            $op['data_entrega'] ? substr((string) $op['data_entrega'], 0, 10) : null,
            $dataEntregaVendedor,
            $dataEntregaEsperada,
            null,
            $op['tipo_producao'],
            p2EtapaPadraoCodigo($pdo),
            usuNome(),
        ]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: marcar como finalizado (local, não altera o ERP) ───────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'finalizar') {
    header('Content-Type: application/json; charset=utf-8');
    $prgCodigo = (int) ($_POST['prg_codigo'] ?? 0);
    try {
        $stmt = $pdo->prepare('
            UPDATE PCP_PROGRAMACAO
               SET PRG_FINALIZADO = 1, PRG_FINALIZADO_EM = NOW(), PRG_FINALIZADO_POR = ?
             WHERE PRG_CODIGO = ? AND PRG_FINALIZADO = 0
        ');
        $stmt->execute([usuNome(), $prgCodigo]);
        echo json_encode(['ok' => $stmt->rowCount() > 0]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: atualizar etapa de produção de um pedido programado ────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'atualizar_etapa') {
    header('Content-Type: application/json; charset=utf-8');
    $prgCodigo = (int) ($_POST['prg_codigo'] ?? 0);
    $etpCodigo = (int) ($_POST['etp_codigo'] ?? 0);
    try {
        if (!$prgCodigo || !$etpCodigo) {
            throw new RuntimeException('Dados inválidos.');
        }
        $chk = $pdo->prepare('SELECT COUNT(*) FROM ETAPA_PRODUCAO WHERE ETP_CODIGO = ?');
        $chk->execute([$etpCodigo]);
        if (!(int) $chk->fetchColumn()) {
            throw new RuntimeException('Etapa de produção inválida.');
        }
        $stmt = $pdo->prepare('UPDATE PCP_PROGRAMACAO SET ETP_CODIGO = ? WHERE PRG_CODIGO = ?');
        $stmt->execute([$etpCodigo, $prgCodigo]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Lista de etapas de produção (para o seletor da grid) ─────────────────────
try {
    $etapasProducao = $pdo->query('SELECT ETP_CODIGO, ETP_DESCRICAO FROM ETAPA_PRODUCAO ORDER BY ETP_CODIGO')
                          ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $etapasProducao = [];
}

// ── Carga inicial da página ────────────────────────────────────────────────────
$error = '';
$programacao = [];
try {
    $programacao = p2FetchProgramacao($pdo, $pg);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
if (!$pg && $error === '') {
    $error = 'ERP indisponível — exibindo apenas dados salvos localmente (podem estar desatualizados).';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reunião de Planejamento | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .p2-cod { color: var(--text-muted); font-size: 11px; }
  .p2-empty-row td { text-align: center; padding: 28px 16px; color: var(--text-muted); font-size: 12.5px; }

  .status-sel {
    font-size: 11px; padding: 2px 5px; border-radius: 6px;
    border: 1px solid var(--border); background: var(--card-bg);
    color: var(--text-primary); cursor: pointer; display: block;
  }

  /* ── Modal grande: buscar/adicionar pedidos ──────────────────────────────── */
  .p2-modal-box { width: 1180px; max-width: calc(100vw - 32px); max-height: 85vh; display: flex; flex-direction: column; }
  .p2-modal-body { overflow-y: auto; margin-top: 14px; flex: 1; min-height: 0; }
  .p2-filter-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 14px; }
  .p2-filter-field { display: flex; flex-direction: column; gap: 6px; }
  .p2-filter-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
  .p2-filter-field input { height: 36px; padding: 0 10px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; width: 170px; }
  #p2ResultsBody td:nth-child(2) { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #p2ResultsBody input[type="date"] { width: 130px; height: 30px; padding: 0 6px; font-size: 11.5px; }
  .p2-add-btn { padding: 5px 12px; height: 28px; border-radius: 7px; border: none; background: var(--blue-accent, #1e4fc9); color: #fff; font-family: 'Segoe UI', sans-serif; font-size: 11.5px; font-weight: 600; cursor: pointer; white-space: nowrap; }
  .p2-add-btn:hover { background: var(--blue-light); }
  .p2-add-btn:disabled { opacity: .5; cursor: default; }
  .p2-finalizar-btn { padding: 5px 12px; height: 28px; border-radius: 7px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); font-family: 'Segoe UI', sans-serif; font-size: 11.5px; font-weight: 600; cursor: pointer; white-space: nowrap; }
  .p2-finalizar-btn:hover { background: rgba(0,201,167,.12); color: var(--teal); border-color: rgba(0,201,167,.3); }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Reunião de Planejamento</h1>
          <p>Programação de pedidos — controle local</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/pcp/etapas" class="btn-secondary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:5px;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Etapas de Produção
        </a>
        <button type="button" class="btn-refresh" id="btnAbrirBusca">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:5px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Adicionar Pedido à Programação
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= p2Escape($error) ?>
        </div>
      <?php endif; ?>

      <div class="panel-table">
        <div class="panel-header">
          <span class="panel-title">Pedidos Programados</span>
          <span class="filter-count-badge" id="p2CountBadge" style="background:rgba(45,106,255,.12);color:#7db3ff;"><?= count($programacao) ?></span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Cód. OP</th>
                <th>Cliente</th>
                <th>Pedido / Venda Ref.</th>
                <th>Emissão</th>
                <th>Entrega</th>
                <th>Entrega Vendedor</th>
                <th>Entrega Esperada</th>
                <th>Etapa de Produção</th>
                <th>Adicionado em</th>
                <th>Adicionado por</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="p2Tbody">
              <?php if (!$programacao): ?>
                <tr class="p2-empty-row"><td colspan="11">Nenhum pedido na programação. Clique em "Adicionar Pedido à Programação" para começar.</td></tr>
              <?php else: ?>
                <?php foreach ($programacao as $row): ?>
                  <tr id="p2-row-<?= (int) $row['prg_codigo'] ?>">
                    <td class="p2-cod"><?= p2Escape($row['prod_codigo']) ?></td>
                    <td><?= p2Escape($row['cli_nome'] ?? '—') ?> <span class="p2-cod">(<?= p2Escape($row['cli_codigo'] ?? '—') ?>)</span></td>
                    <td><?= p2Escape($row['venda_ref'] ?: ($row['ven_cod_pedido'] ?: '—')) ?></td>
                    <td><?= p2Escape(p2FmtDate($row['prod_data'] ?? '')) ?></td>
                    <td><?= p2Escape(p2FmtDate($row['data_entrega'] ?? '')) ?></td>
                    <td><?= p2Escape(p2FmtDate($row['data_entrega_vendedor'] ?? '')) ?></td>
                    <td><?= p2Escape(p2FmtDate($row['data_entrega_esperada'] ?? '')) ?></td>
                    <td>
                      <select class="status-sel"
                        data-prg="<?= (int) $row['prg_codigo'] ?>"
                        data-prev="<?= (int) ($row['etp_codigo'] ?? 0) ?>"
                        onchange="p2AtualizarEtapa(this)">
                        <option value="">— Selecione —</option>
                        <?php foreach ($etapasProducao as $etp): ?>
                        <option value="<?= (int) $etp['ETP_CODIGO'] ?>" <?= (int) ($row['etp_codigo'] ?? 0) === (int) $etp['ETP_CODIGO'] ? 'selected' : '' ?>>
                          <?= p2Escape($etp['ETP_DESCRICAO']) ?>
                        </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td class="td-nowrap"><?= p2Escape(p2FmtDateTime($row['prg_adicionado_em'] ?? '')) ?></td>
                    <td class="td-muted"><?= p2Escape($row['prg_adicionado_por'] ?? '—') ?></td>
                    <td class="td-right">
                      <button type="button" class="p2-finalizar-btn" onclick="p2Finalizar(<?= (int) $row['prg_codigo'] ?>)">Marcar como Finalizado</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- Modal: buscar/adicionar pedidos -->
<div class="modal-overlay" id="p2ModalOverlay">
  <div class="modal-box p2-modal-box">
    <h3>Adicionar Pedido à Programação</h3>
    <p>Somente OPs ainda não finalizadas no ERP (Finalizado = N) são exibidas.</p>
    <div class="p2-modal-body">
      <div class="p2-filter-row">
        <div class="p2-filter-field">
          <label for="p2CodOp">Código OP</label>
          <input type="number" id="p2CodOp" placeholder="Ex.: 3066">
        </div>
        <div class="p2-filter-field">
          <label for="p2CodVenda">Código Venda</label>
          <input type="text" id="p2CodVenda" placeholder="Ex.: 3663">
        </div>
        <div class="p2-filter-field">
          <label for="p2Cliente">Cliente</label>
          <input type="text" id="p2Cliente" placeholder="Nome do cliente">
        </div>
        <button type="button" class="btn-refresh" id="p2BtnBuscar">Buscar</button>
      </div>
      <div class="table-wrap" style="max-height:44vh;">
        <table>
          <thead>
            <tr>
              <th>Cód. OP</th>
              <th>Cliente</th>
              <th>Pedido / Venda Ref.</th>
              <th>Emissão</th>
              <th>Entrega</th>
              <th>Entrega Vendedor</th>
              <th>Entrega Esperada</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="p2ResultsBody">
            <tr class="p2-empty-row"><td colspan="8">Informe um filtro e clique em Buscar, ou busque sem filtros para ver as OPs mais recentes.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="icon-btn" id="p2ModalCloseBtn" style="width:auto;padding:0 14px;font-size:13px;font-weight:600;">Fechar</button>
    </div>
  </div>
</div>

<script>
function p2Esc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function p2FmtDate(v) {
  if (!v) return '—';
  const d = new Date(String(v).replace(' ', 'T'));
  if (isNaN(d.getTime())) return v;
  return d.toLocaleDateString('pt-BR');
}

// ── Modal de busca ────────────────────────────────────────────────────────────
function p2AbrirModal() {
  document.getElementById('p2ModalOverlay').classList.add('open');
  p2Buscar();
}
function p2FecharModal() {
  document.getElementById('p2ModalOverlay').classList.remove('open');
}
document.getElementById('btnAbrirBusca').addEventListener('click', p2AbrirModal);
document.getElementById('p2ModalCloseBtn').addEventListener('click', p2FecharModal);
document.getElementById('p2ModalOverlay').addEventListener('click', e => { if (e.target.id === 'p2ModalOverlay') p2FecharModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') p2FecharModal(); });

function p2Buscar() {
  const params = new URLSearchParams({
    action: 'buscar_ops',
    cod_op: document.getElementById('p2CodOp').value.trim(),
    cod_venda: document.getElementById('p2CodVenda').value.trim(),
    cliente: document.getElementById('p2Cliente').value.trim(),
  });
  const tbody = document.getElementById('p2ResultsBody');
  tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="8">Buscando...</td></tr>';

  fetch('?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        tbody.innerHTML = `<tr class="p2-empty-row"><td colspan="8">${p2Esc(data.error)}</td></tr>`;
        return;
      }
      const ops = data.ops || [];
      if (!ops.length) {
        tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="8">Nenhuma OP encontrada.</td></tr>';
        return;
      }
      tbody.innerHTML = ops.map(op => `
        <tr>
          <td class="p2-cod">${p2Esc(op.prod_codigo)}</td>
          <td title="${p2Esc(op.cli_nome || '—')}">${p2Esc(op.cli_nome || '—')}</td>
          <td>${p2Esc(op.venda_ref || op.ven_cod_pedido || '—')}</td>
          <td>${p2FmtDate(op.prod_data)}</td>
          <td>${p2FmtDate(op.data_entrega)}</td>
          <td><input type="date" class="p2-data-vendedor"></td>
          <td><input type="date" class="p2-data-esperada"></td>
          <td class="td-right"><button type="button" class="p2-add-btn" onclick="p2Adicionar(${op.prod_codigo}, this)">Adicionar</button></td>
        </tr>
      `).join('');
    })
    .catch(() => {
      tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="8">Erro ao buscar OPs.</td></tr>';
    });
}
document.getElementById('p2BtnBuscar').addEventListener('click', p2Buscar);
['p2CodOp', 'p2CodVenda', 'p2Cliente'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); p2Buscar(); } });
});

function p2Adicionar(prodCodigo, btn) {
  const row = btn.closest('tr');
  const dataVendedor = row.querySelector('.p2-data-vendedor').value;
  const dataEsperada = row.querySelector('.p2-data-esperada').value;
  if (!dataVendedor || !dataEsperada) {
    alert('Informe a Data de entrega do vendedor e a Data de entrega esperada antes de adicionar.');
    return;
  }
  btn.disabled = true;
  btn.textContent = 'Adicionando...';
  const body = new URLSearchParams({
    action: 'adicionar',
    prod_codigo: prodCodigo,
    data_entrega_vendedor: dataVendedor,
    data_entrega_esperada: dataEsperada,
  });
  fetch('?action=adicionar', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: body.toString(),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        alert(data.error);
        btn.disabled = false;
        btn.textContent = 'Adicionar';
        return;
      }
      window.location.reload();
    })
    .catch(() => {
      alert('Erro ao adicionar OP à programação.');
      btn.disabled = false;
      btn.textContent = 'Adicionar';
    });
}

// ── Marcar como finalizado (grid principal) ───────────────────────────────────
function p2Finalizar(prgCodigo) {
  if (!confirm('Marcar este pedido como finalizado e removê-lo da programação?')) return;
  fetch('?action=finalizar', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'action=finalizar&prg_codigo=' + encodeURIComponent(prgCodigo),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error || !data.ok) {
        alert(data.error || 'Não foi possível finalizar.');
        return;
      }
      const row = document.getElementById('p2-row-' + prgCodigo);
      if (row) row.remove();
      const badge = document.getElementById('p2CountBadge');
      if (badge) badge.textContent = Math.max(0, (parseInt(badge.textContent, 10) || 1) - 1);
      const tbody = document.getElementById('p2Tbody');
      if (tbody && !tbody.querySelector('tr:not(.p2-empty-row)')) {
        tbody.innerHTML = '<tr class="p2-empty-row"><td colspan="11">Nenhum pedido na programação. Clique em "Adicionar Pedido à Programação" para começar.</td></tr>';
      }
    })
    .catch(() => alert('Erro ao finalizar o pedido.'));
}

// ── Atualizar etapa de produção de um pedido programado ───────────────────────
function p2AtualizarEtapa(sel) {
  const prgCodigo = sel.dataset.prg;
  const etpCodigo = sel.value;
  const anterior = sel.dataset.prev || '';
  if (!etpCodigo) return;
  sel.disabled = true;
  fetch('?action=atualizar_etapa', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'action=atualizar_etapa&prg_codigo=' + encodeURIComponent(prgCodigo) + '&etp_codigo=' + encodeURIComponent(etpCodigo),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error || !data.ok) {
        alert(data.error || 'Não foi possível atualizar a etapa.');
        sel.value = anterior;
        return;
      }
      sel.dataset.prev = etpCodigo;
    })
    .catch(() => {
      alert('Erro ao atualizar a etapa.');
      sel.value = anterior;
    })
    .finally(() => { sel.disabled = false; });
}
</script>
</body>
</html>
