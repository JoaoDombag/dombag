<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Consulta de Vendas (ERP Yzidro)
//  Fonte: VW_VENDAS (PostgreSQL, somente leitura)
// ══════════════════════════════════════════════════════════════

function cvEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cvSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function cvFmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function cvFmtMoney($value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function cvBaseUrl(): string
{
    return '/yzidro/consulta';
}

// ── Lista de pedidos (cabeçalho) ──────────────────────────────────────────────
// Usa VW_VENDAS com os mesmos filtros padrão de todo o sistema.
// Agrega por pedido para exibir uma linha por pedido na grid principal.
function cvFetchVendas($pg, array $filters): array
{
    $params = [];
    $extras = [];

    if ($filters['emissao_ini'] !== '') {
        $params[] = $filters['emissao_ini'];
        $extras[] = 'VV.DATAEMISSAO >= $' . count($params);
    }
    if ($filters['emissao_fim'] !== '') {
        $params[] = $filters['emissao_fim'];
        $extras[] = 'VV.DATAEMISSAO <= $' . count($params);
    }
    if ($filters['fatura_ini'] !== '') {
        $params[] = $filters['fatura_ini'];
        $extras[] = 'VV.DATAFATURA >= $' . count($params);
    }
    if ($filters['fatura_fim'] !== '') {
        $params[] = $filters['fatura_fim'];
        $extras[] = 'VV.DATAFATURA <= $' . count($params);
    }
    if ($filters['pedido'] !== '') {
        $params[] = '%' . $filters['pedido'] . '%';
        $extras[] = 'VV.PEDIDO::TEXT ILIKE $' . count($params);
    }
    if ($filters['cliente'] !== '') {
        $params[] = '%' . $filters['cliente'] . '%';
        $extras[] = '(VV.NOME_FANTASIA ILIKE $' . count($params)
                  . ' OR VV.CLIENTE ILIKE $' . count($params) . ')';
    }
    if ($filters['representante'] !== '') {
        $params[] = '%' . $filters['representante'] . '%';
        $extras[] = 'CAST(VV.VENDEDOR_VENDA AS VARCHAR) ILIKE $' . count($params);
    }

    $where = "VV.COD_GRUPO IN (6)
          AND VV.OPERACAO_PEDIDO = 'V'
          AND VV.COD_ESTADO IN ('9')
          AND VV.EMP_CODIGO = 1";

    if ($extras) {
        $where .= "\n          AND " . implode("\n          AND ", $extras);
    }

    $sql = "
        SELECT VV.PEDIDO::TEXT                                      AS pedido
              ,CAST(IIF(VV.NOME_FANTASIA = ''                                                                 
                       ,VV.CLIENTE                                                                                       
                       ,VV.NOME_FANTASIA) AS VARCHAR(80))           AS cliente
              ,COALESCE(CAST(VV.VENDEDOR_VENDA AS VARCHAR(64)), '') AS representante
              ,TO_CHAR(VV.DATAEMISSAO, 'YYYY-MM-DD')                AS dataemissao
              ,TO_CHAR(VV.DATAFATURA, 'YYYY-MM-DD')                 AS datafatura
              ,COALESCE(SUM(VV.TOTAL_PRODUTO), 0)                   AS total_pedido
              ,COUNT(*)                                             AS qtd_itens
              ,MIN(VV.QTD)                                         AS min_qtd
              ,MAX(VV.QTD)                                         AS max_qtd
        FROM VW_VENDAS VV
        WHERE {$where}
        GROUP BY VV.PEDIDO, VV.NOME_FANTASIA, VV.CLIENTE, VV.VENDEDOR_VENDA, VV.DATAFATURA, VV.DATAEMISSAO
        ORDER BY VV.DATAEMISSAO DESC, VV.PEDIDO DESC
    ";

    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar as vendas do ERP: ' . pg_last_error($pg));
    }

    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $rows[] = $row;
    }
    pg_free_result($res);
    return $rows;
}

// ── Itens de um pedido específico ─────────────────────────────────────────────
function cvFetchItensPedido($pg, string $pedido): array
{
    $sql = "
        SELECT
            VV.PEDIDO::TEXT                                         AS pedido,
            TO_CHAR(VV.DATAEMISSAO, 'DD/MM/YYYY')                   AS dataemissao,
            TO_CHAR(VV.DATAFATURA, 'DD/MM/YYYY')                    AS datafatura,
            COALESCE(CAST(VV.PRODUTO AS VARCHAR(120)), '')          AS produto,
            COALESCE(CAST(VV.GRUPO_CLIENTES AS VARCHAR(64)), '')    AS grupo,
            COALESCE(VV.QTD, 0)                                     AS qtd,
            COALESCE(VV.UNIDADE, '')                                AS unidade,
            COALESCE(CAST(VV.VALOR_UNIT AS FLOAT), 0)               AS valor_unit,
            COALESCE(VV.TOTAL_PRODUTO, 0)                           AS total_produto,
            COALESCE(VV.STATUS_PEDIDO::TEXT, 'Pendente')            AS status_pedido,
            COALESCE(CAST(VV.SEGMENTO AS VARCHAR(64)), '')          AS segmento,
            COALESCE(CAST(VV.OBS AS TEXT), '')                      AS obs
        FROM VW_VENDAS VV
        WHERE VV.PEDIDO::TEXT = \$1
          AND VV.COD_GRUPO IN (6)
          AND VV.OPERACAO_PEDIDO = 'V'
          AND VV.COD_ESTADO IN ('9')
          AND VV.EMP_CODIGO = 1
        ORDER BY VV.PRODUTO
    ";

    $res = @pg_query_params($pg, $sql, [$pedido]);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar os itens do pedido: ' . pg_last_error($pg));
    }

    $items = [];
    while ($row = pg_fetch_assoc($res)) {
        $items[] = $row;
    }
    pg_free_result($res);
    return $items;
}

// ── Filtros da requisição ─────────────────────────────────────────────────────
$filters = [
    'emissao_ini' => cvSanitizeDate($_GET['emissao_ini'] ?? ''),
    'emissao_fim' => cvSanitizeDate($_GET['emissao_fim'] ?? ''),
    'fatura_ini' => cvSanitizeDate($_GET['fatura_ini'] ?? ''),
    'fatura_fim' => cvSanitizeDate($_GET['fatura_fim'] ?? ''),
    'pedido' => trim((string) ($_GET['pedido'] ?? '')),
    'cliente' => trim((string) ($_GET['cliente'] ?? '')),
    'representante' => trim((string) ($_GET['representante'] ?? '')),
];

// ── Conexão ERP ───────────────────────────────────────────────────────────────
$pg = pcpGetPG();
if (!$pg) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'Falha ao conectar no ERP.']);
    exit;
}

// ── AJAX: itens de um pedido ──────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'itens') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pedido = trim((string) ($_GET['pedido'] ?? ''));
        if ($pedido === '') {
            throw new RuntimeException('Pedido não informado.');
        }
        $itens = cvFetchItensPedido($pg, $pedido);
        echo json_encode([
            'ok' => true,
            'pedido' => $pedido,
            'itens' => $itens,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    pg_close($pg);
    exit;
}

// ── Busca principal ───────────────────────────────────────────────────────────
$error = '';
$vendas = [];
try {
    $vendas = cvFetchVendas($pg, $filters);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
pg_close($pg);

$totalVendas = count($vendas);
$totalFaturado = array_reduce($vendas, fn ($c, $r) => $c + (float) ($r['total_pedido'] ?? 0), 0.0);
$ticketMedio = $totalVendas > 0 ? ($totalFaturado / $totalVendas) : 0.0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Consulta de Vendas | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
  /* ── Apenas estilos específicos desta página.
     Layout, topbar, sidebar, inputs, botões e tabela base
     vêm do unified_admin.css carregado acima.           ── */

  /* KPIs */
  .kpi-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
  }

  /* Filtros */
  .filter-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    margin-bottom: 16px;
  }
  .filter-grid{
    display:grid;
    grid-template-columns:
      150px 150px 150px 150px   /* 4 datas */
      220px                     /* pedido */
      minmax(260px, 1fr)        /* cliente */
      minmax(240px, 1fr);        /* representante */
    gap:16px;
    align-items:end;
  }

  .filter-field.filter-date input[type="date"]{
    width:100%;
    min-width:0;
  }
  .filter-field { display: flex; flex-direction: column; gap: 6px; }

  .filter-field.filter-date{
    max-width:none;
  }

  .filter-field.filter-date input[type="date"]{
    width: 100%;
    min-width: 0;
  }

  .filter-field label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
  }
  .filter-field input,
  .filter-field select {
    height: 36px;
    padding: 0 10px;
    font-size: 12.5px;
    font-family: 'Segoe UI', sans-serif;
  }
  .filter-field select option { background: var(--blue-mid); }
  .filter-actions{
    margin-top:16px;
    display:flex;
    gap:12px;
  }

  /* Tabela específica desta página */
  .cv-table-wrap { overflow-x: auto; }

  .cv-toggle-cell { width: 52px; text-align: center; }
  .cv-right       { text-align: right; }
  .cv-nowrap      { white-space: nowrap; }
  .cv-money       { text-align: right; white-space: nowrap; font-weight: 600; color: var(--text-primary); }
  .cv-num         { text-align: right; color: var(--text-muted); font-size: 12px; }
  .cv-cliente-col { min-width: 220px; font-weight: 500; }
  .cv-pedido-col  { font-weight: 700; color: #7db3ff; }

  /* Botão expandir linha */
  .cv-toggle-btn {
    width: 28px; height: 28px; border-radius: 7px;
    border: 1px solid var(--border);
    background: transparent; color: var(--text-muted);
    cursor: pointer; font-size: 17px; line-height: 1;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
  }
  .cv-toggle-btn:hover { background: var(--card-hover); color: var(--text-primary); }
  .cv-toggle-btn[aria-expanded="true"] {
    background: rgba(36,83,212,.18);
    border-color: rgba(79,123,255,.35);
    color: #7db3ff;
  }

  /* Linha de detalhe expandida */
  .cv-detail-row { display: none; }
  .cv-detail-row.open { display: table-row; }
  .cv-detail-cell {
    padding: 0 !important;
    background: rgba(0,0,0,.12) !important;
    border-bottom: 1px solid var(--border);
  }
  .cv-detail-box { padding: 16px 20px 18px; }
  .cv-detail-head { margin-bottom: 12px; }
  .cv-detail-head h3 {
    font-size: 13px; font-weight: 600;
    color: var(--text-primary); margin: 0 0 8px;
  }
  .cv-detail-chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .cv-chip {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 999px; background: var(--card-bg);
    border: 1px solid var(--border);
    font-size: 11.5px; font-weight: 500; color: var(--text-muted);
  }

  /* Estados de carregamento / erro / vazio */
  .cv-detail-loading,
  .cv-empty-state {
    padding: 24px; text-align: center;
    color: var(--text-muted); font-size: 13px;
  }
  .cv-detail-error {
    padding: 12px 16px; border-radius: 8px; font-size: 13px;
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2);
    color: var(--red);
  }

  /* Subtabela de itens */
  .cv-subtable { width: 100%; border-collapse: collapse; margin-top: 4px; }
  .cv-subtable th {
    padding: 8px 14px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-muted); border-bottom: 1px solid var(--border);
    background: rgba(0,0,0,.08); white-space: nowrap;
  }
  .cv-subtable td {
    padding: 10px 14px; font-size: 12.5px;
    border-bottom: 1px solid var(--border);
    color: var(--text-primary);
  }
  .cv-subtable tr:last-child td { border-bottom: none; }
  .cv-subtable tr:hover td { background: rgba(255,255,255,.02); }

  /* Pills de grupo */
  .cv-pill {
    display: inline-flex; align-items: center; padding: 3px 8px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
  }
  .cv-pill-bag     { background: rgba(36,83,212,.15);  color: #7db3ff; }
  .cv-pill-sacaria { background: rgba(20,184,166,.12); color: var(--teal); }
  .cv-pill-default { background: var(--card-bg);       color: var(--text-muted); border: 1px solid var(--border); }

  .cv-obs   { max-width: 280px; line-height: 1.4; white-space: normal; color: var(--text-muted); font-size: 12px; }
  .cv-muted { color: var(--text-muted); font-size: 12px; }

  /* Erro de conexão */
  .alert-error {
    padding: 12px 16px; border-radius: var(--radius);
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2);
    color: var(--red); font-size: 13px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
  }

  @media (max-width: 1280px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (max-width: 900px) {
    .kpi-grid-3 { grid-template-columns: 1fr; }
    .filter-grid { grid-template-columns: 1fr 1fr; }
    .cv-cliente-col { min-width: 140px; }
  }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Consulta de Vendas</h1>
          <p>Pedidos faturados do ERP — somente leitura. Clique para ver os itens.</p>
        </div>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= cvEscape($error) ?>
        </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-grid-3">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Pedidos exibidos</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalVendas, 0, ',', '.') ?></div>
          <div class="kpi-footer">no período filtrado</div>
        </div>
        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Valor total exibido</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="font-size:22px;letter-spacing:-.5px"><?= cvEscape(cvFmtMoney($totalFaturado)) ?></div>
          <div class="kpi-footer">soma dos pedidos listados</div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-header">
            <span class="kpi-label">Ticket médio</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="font-size:22px;letter-spacing:-.5px"><?= cvEscape(cvFmtMoney($ticketMedio)) ?></div>
          <div class="kpi-footer">por pedido</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="filter-card">
        <form method="get" action="<?= cvEscape(cvBaseUrl()) ?>">
          <div class="filter-grid">

            <div class="filter-field filter-date">
              <label for="emissao_ini">Emissão inicial</label>
              <input type="date" id="emissao_ini" name="emissao_ini" value="<?= cvEscape($filters['emissao_ini']) ?>">
            </div>
                  
            <div class="filter-field filter-date">
              <label for="emissao_fim">Emissão final</label>
              <input type="date" id="emissao_fim" name="emissao_fim" value="<?= cvEscape($filters['emissao_fim']) ?>">
            </div>
                  
            <div class="filter-field filter-date">
              <label for="fatura_ini">Faturamento inicial</label>
              <input type="date" id="fatura_ini" name="fatura_ini" value="<?= cvEscape($filters['fatura_ini']) ?>">
            </div>
                  
            <div class="filter-field filter-date">
              <label for="fatura_fim">Faturamento final</label>
              <input type="date" id="fatura_fim" name="fatura_fim" value="<?= cvEscape($filters['fatura_fim']) ?>">
            </div>

            <div class="filter-field">
              <label for="pedido">Pedido</label>
              <input type="text" id="pedido" name="pedido" value="<?= cvEscape($filters['pedido']) ?>" placeholder="Ex.: 2587">
            </div>
            <div class="filter-field">
              <label for="cliente">Cliente</label>
              <input type="text" id="cliente" name="cliente" value="<?= cvEscape($filters['cliente']) ?>" placeholder="Nome do cliente">
            </div>
            <div class="filter-field">
              <label for="representante">Representante</label>
              <input type="text" id="representante" name="representante" value="<?= cvEscape($filters['representante']) ?>" placeholder="Nome do representante">
            </div>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-refresh">Filtrar</button>
            <a href="<?= cvEscape(cvBaseUrl()) ?>" class="icon-btn" title="Limpar filtros" style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
          </div>
        </form>
      </div>

      <!-- Tabela de pedidos -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Pedidos faturados</span>
          <span class="source-badge src-erp">ERP</span>
        </div>
        <div class="cv-table-wrap">
          <table>
            <thead>
              <tr>
                <th class="cv-toggle-cell"></th>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Emissão</th>
                <th>Faturamento</th>
                <th>Representante</th>
                <th class="cv-right">Itens</th>
                <th class="cv-right">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$vendas): ?>
                <tr>
                  <td colspan="7">
                    <div class="cv-empty-state">Nenhuma venda encontrada com os filtros informados.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($vendas as $row):
                    $pedido = (string) ($row['pedido'] ?? '');
                    $detailId = 'detail_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $pedido);
                    ?>
                  <tr>
                    <td class="cv-toggle-cell">
                      <button
                        type="button"
                        class="cv-toggle-btn"
                        data-pedido="<?= cvEscape($pedido) ?>"
                        data-detail-id="<?= cvEscape($detailId) ?>"
                        aria-expanded="false"
                        title="Ver itens"
                      >+</button>
                    </td>
                    <td class="cv-pedido-col"><?= cvEscape($pedido) ?></td>
                    <td class="cv-cliente-col"><?= cvEscape($row['cliente'] ?? '—') ?></td>
                    <td class="cv-nowrap"><?= cvEscape(cvFmtDate($row['dataemissao'] ?? '')) ?></td>
                    <td class="cv-nowrap"><?= cvEscape(cvFmtDate($row['datafatura'] ?? '')) ?></td>
                    <td class="cv-nowrap"><?= cvEscape($row['representante'] ?? '—') ?></td>
                    <td class="cv-num"><?= (int) ($row['qtd_itens'] ?? 0) ?></td>
                    <td class="cv-money"><?= cvEscape(cvFmtMoney($row['total_pedido'] ?? 0)) ?></td>
                  </tr>
                  <tr id="<?= cvEscape($detailId) ?>" class="cv-detail-row">
                    <td colspan="8" class="cv-detail-cell">
                      <div class="cv-detail-box">
                        <div class="cv-detail-head">
                          <h3>Itens do pedido <?= cvEscape($pedido) ?></h3>
                          <div class="cv-detail-chips">
                            <span class="cv-chip"><?= cvEscape($row['cliente'] ?? '—') ?></span>
                            <span class="cv-chip"><?= cvEscape($row['representante'] ?? '—') ?></span>
                            <span class="cv-chip"><?= cvEscape(cvFmtMoney($row['total_pedido'] ?? 0)) ?></span>
                          </div>
                        </div>
                        <div class="cv-detail-content">
                          <div class="cv-detail-loading">Carregando itens…</div>
                        </div>
                      </div>
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

<script>
  const currentParams = new URLSearchParams(window.location.search);
  const baseUrl = <?= json_encode(cvBaseUrl(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function moneyBR(value) {
    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function qtyBR(value) {
    return Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g,  '&lt;')
      .replace(/>/g,  '&gt;')
      .replace(/"/g,  '&quot;')
      .replace(/'/g,  '&#039;');
  }

  function groupBadge(group, product) {
    const g = String(group   || '').toUpperCase();
    const p = String(product || '').toUpperCase();
    if (g.includes('BAG') || p.includes('BIG BAG')) {
      return `<span class="cv-pill cv-pill-bag">${escapeHtml(group || 'Bag')}</span>`;
    }
    if (g.includes('SAC') || p.includes('SACO')) {
      return `<span class="cv-pill cv-pill-sacaria">${escapeHtml(group || 'Sacaria')}</span>`;
    }
    return `<span class="cv-pill cv-pill-default">${escapeHtml(group || 'Outro')}</span>`;
  }

  function buildItemsTable(items) {
    if (!Array.isArray(items) || !items.length) {
      return '<div class="cv-empty-state">Nenhum item encontrado para este pedido.</div>';
    }

    const rows = items.map(item => `
      <tr>
        <td>
          <strong>${escapeHtml(item.produto || '—')}</strong>
          ${item.segmento ? `<br><span class="cv-muted">${escapeHtml(item.segmento)}</span>` : ''}
        </td>
        <td>${groupBadge(item.grupo, item.produto)}</td>
        <td class="cv-right">${qtyBR(item.qtd)}</td>
        <td>${escapeHtml(item.unidade || '—')}</td>
        <td class="cv-right nowrap">${moneyBR(item.valor_unit)}</td>
        <td class="cv-right nowrap"><strong>${moneyBR(item.total_produto)}</strong></td>
        <td>${escapeHtml(item.status_pedido || '—')}</td>
        <td class="cv-obs">${escapeHtml(item.obs || '—')}</td>
      </tr>
    `).join('');

    return `
      <table class="cv-subtable">
        <thead>
          <tr>
            <th>Produto</th>
            <th>Grupo</th>
            <th class="cv-right">Qtd.</th>
            <th>Un.</th>
            <th class="cv-right">Valor unit.</th>
            <th class="cv-right">Total</th>
            <th>Status</th>
            <th>Obs.</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  async function toggleOrder(button) {
    const detailId  = button.dataset.detailId;
    const pedido    = button.dataset.pedido;
    const detailRow = document.getElementById(detailId);
    if (!detailRow) return;

    const willOpen = !detailRow.classList.contains('open');

    // Fecha linha aberta anteriormente
    document.querySelectorAll('.cv-detail-row.open').forEach(row => {
      if (row !== detailRow) row.classList.remove('open');
    });
    document.querySelectorAll('.cv-toggle-btn[aria-expanded="true"]').forEach(btn => {
      if (btn !== button) {
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = '+';
      }
    });

    if (!willOpen) {
      detailRow.classList.remove('open');
      button.setAttribute('aria-expanded', 'false');
      button.textContent = '+';
      return;
    }

    detailRow.classList.add('open');
    button.setAttribute('aria-expanded', 'true');
    button.textContent = '−';

    const container = detailRow.querySelector('.cv-detail-content');
    if (container.dataset.loaded === '1') return;

    container.innerHTML = '<div class="cv-detail-loading">Carregando itens…</div>';

    const qs = new URLSearchParams(currentParams);
    qs.set('ajax', 'itens');
    qs.set('pedido', pedido);

    try {
      const response = await fetch(`${baseUrl}?${qs.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      if (!data.ok) throw new Error(data.msg || 'Falha ao carregar os itens.');
      container.innerHTML = buildItemsTable(data.itens);
      container.dataset.loaded = '1';
    } catch (err) {
      container.innerHTML = `<div class="cv-detail-error">${escapeHtml(err.message || 'Falha ao carregar os itens.')}</div>`;
    }
  }

  document.querySelectorAll('.cv-toggle-btn').forEach(button => {
    button.addEventListener('click', () => toggleOrder(button));
  });
</script>
</body>
</html>
