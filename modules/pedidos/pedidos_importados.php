<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Pedidos de Venda (MySQL local)
//  Pedidos importados do ERP, separados em categorias PCP.
//  Botão "Planejar Produção" → /pcp/planejamento
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();

// Garante coluna ven_prazo_dias
(function () use ($pdo) {
    try {
        $chk = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $chk->execute(['vendas', 'ven_prazo_dias']);
        if (!(int) $chk->fetchColumn()) {
            $pdo->exec('ALTER TABLE vendas ADD COLUMN ven_prazo_dias INT NULL AFTER ven_status');
        }
    } catch (Throwable) {}
})();

// ── Helpers ───────────────────────────────────────────────────────────────────
function piEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function piSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function piFmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function piFmtMoney($value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function piBaseUrl(): string
{
    return '/vendas';
}

// ── AJAX: atualizar prazo de produção ─────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'set_prazo') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $venCod = (int) ($_GET['ven_codigo'] ?? 0);
        $prazo  = trim((string) ($_GET['prazo'] ?? ''));
        if (!$venCod) {
            throw new RuntimeException('Pedido não informado.');
        }
        // Garante coluna
        $chkCol = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $chkCol->execute(['vendas', 'ven_prazo_dias']);
        if (!(int) $chkCol->fetchColumn()) {
            $pdo->exec('ALTER TABLE vendas ADD COLUMN ven_prazo_dias INT NULL AFTER ven_status');
        }
        $prazoVal = $prazo === '' ? null : max(1, (int) $prazo);
        $st = $pdo->prepare('UPDATE vendas SET ven_prazo_dias = ? WHERE ven_codigo = ?');
        $st->execute([$prazoVal, $venCod]);
        echo json_encode(['ok' => true, 'prazo' => $prazoVal]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: itens de um pedido ──────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'itens') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $venCod = (int) ($_GET['ven_codigo'] ?? 0);
        if (!$venCod) {
            throw new RuntimeException('ID de pedido inválido.');
        }

        $stmt = $pdo->prepare('
            SELECT
                iv.iv_codigo,
                p.pro_descricao  AS produto,
                p.pro_categoria  AS categoria,
                iv.iv_qtde       AS qtd,
                iv.iv_unidade    AS unidade,
                iv.iv_vlr_unit   AS valor_unit,
                iv.iv_total      AS total_produto,
                iv.iv_status     AS status,
                iv.iv_obs        AS obs
            FROM itens_vendas iv
            JOIN produtos p ON p.pro_codigo = iv.pro_codigo
            WHERE iv.ven_codigo = :id
            ORDER BY p.pro_descricao
        ');
        $stmt->execute([':id' => $venCod]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'itens' => $itens,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$filters = [
    'entrega_ini' => piSanitizeDate($_GET['entrega_ini'] ?? ''),
    'entrega_fim' => piSanitizeDate($_GET['entrega_fim'] ?? ''),
    'emissao_ini' => piSanitizeDate($_GET['emissao_ini'] ?? ''),
    'emissao_fim' => piSanitizeDate($_GET['emissao_fim'] ?? ''),
    'pedido' => trim((string) ($_GET['pedido'] ?? '')),
    'cliente' => trim((string) ($_GET['cliente'] ?? '')),
    'representante' => trim((string) ($_GET['representante'] ?? '')),
    'situacao' => trim((string) ($_GET['situacao'] ?? '')),
];
$show_amostras = isset($_GET['amostras']);

// ── Busca pedidos do MySQL ────────────────────────────────────────────────────
$where = ['1=1'];
$params = [];

if ($filters['entrega_ini'] !== '') {
    $where[] = 'v.ven_entrega >= :ent_ini';
    $params[':ent_ini'] = $filters['entrega_ini'];
}
if ($filters['entrega_fim'] !== '') {
    $where[] = 'v.ven_entrega <= :ent_fim';
    $params[':ent_fim'] = $filters['entrega_fim'];
}
if ($filters['emissao_ini'] !== '') {
    $where[] = 'v.ven_emissao >= :emi_ini';
    $params[':emi_ini'] = $filters['emissao_ini'];
}
if ($filters['emissao_fim'] !== '') {
    $where[] = 'v.ven_emissao <= :emi_fim';
    $params[':emi_fim'] = $filters['emissao_fim'];
}
if ($filters['pedido'] !== '') {
    $where[] = 'v.ven_codigo_yzidro LIKE :ped';
    $params[':ped'] = '%' . $filters['pedido'] . '%';
}
if ($filters['cliente'] !== '') {
    $where[] = '(v.ven_fantasia LIKE :cli OR v.ven_cliente LIKE :cli)';
    $params[':cli'] = '%' . $filters['cliente'] . '%';
}
if ($filters['representante'] !== '') {
    $where[] = 'v.ven_representante LIKE :rep';
    $params[':rep'] = '%' . $filters['representante'] . '%';
}

$whereStr = implode(' AND ', $where);

// HAVING para filtro de situação e tipo
$havingParts = [];
switch ($filters['situacao']) {
    case 'finalizados':
        $havingParts[] = "COUNT(iv.iv_codigo) > 0 AND COUNT(iv.iv_codigo) = SUM(CASE WHEN iv.iv_status = 'Finalizado' THEN 1 ELSE 0 END)";
        break;
    case 'em_andamento':
        $havingParts[] = "COUNT(iv.iv_codigo) = 0 OR SUM(CASE WHEN iv.iv_status != 'Finalizado' THEN 1 ELSE 0 END) > 0";
        break;
}
if (!$show_amostras) {
    $havingParts[] = 'MIN(iv.iv_qtde) >= 20';
}
$having = $havingParts ? ('HAVING ' . implode(' AND ', $havingParts)) : '';

$stmt = $pdo->prepare("
    SELECT
        v.ven_codigo,
        v.ven_codigo_yzidro                                                      AS pedido,
        COALESCE(NULLIF(v.ven_fantasia,''), v.ven_cliente)                       AS cliente,
        v.ven_representante                                                      AS representante,
        v.ven_emissao                                                            AS dataemissao,
        v.ven_entrega                                                            AS datafatura,
        v.ven_total                                                              AS total_pedido,
        v.ven_prazo_dias                                                         AS prazo_dias,
        COUNT(iv.iv_codigo)                                                      AS qtd_itens,
        SUM(CASE WHEN iv.iv_status = 'Finalizado'          THEN 1 ELSE 0 END)   AS qtd_fin,
        SUM(CASE WHEN iv.iv_status = 'Produção'            THEN 1 ELSE 0 END)   AS qtd_prod,
        SUM(CASE WHEN iv.iv_status = 'Aguardando expedição' THEN 1 ELSE 0 END)  AS qtd_exp,
        SUM(CASE WHEN iv.iv_status = 'Pendente de produção' THEN 1 ELSE 0 END)  AS qtd_pend,
        MIN(iv.iv_qtde) AS min_qtde,
        MAX(iv.iv_qtde) AS max_qtde
    FROM vendas v
    LEFT JOIN itens_vendas iv ON iv.ven_codigo = v.ven_codigo
    WHERE {$whereStr}
    GROUP BY v.ven_codigo
    {$having}
    ORDER BY v.ven_entrega ASC, v.ven_codigo_yzidro DESC
");
$stmt->execute($params);
$vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalVendas = count($vendas);
$totalFaturado = array_reduce($vendas, fn ($c, $r) => $c + (float) ($r['total_pedido'] ?? 0), 0.0);
$ticketMedio = $totalVendas > 0 ? $totalFaturado / $totalVendas : 0.0;
$totalFin = count(array_filter($vendas, fn ($r) => (int) $r['qtd_itens'] > 0 && (int) $r['qtd_fin'] === (int) $r['qtd_itens']));
$totalAndamento = $totalVendas - $totalFin;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pedidos de Venda | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
  /* KPIs */
  .kpi-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
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
  .filter-grid {
    display: grid;
    grid-template-columns:
      150px 150px 150px 150px
      220px
      minmax(260px, 1fr)
      minmax(240px, 1fr);
    gap: 16px;
    align-items: end;
  }
  .filter-field { display: flex; flex-direction: column; gap: 6px; }
  .filter-field label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
  }
  .filter-field input { height: 36px; padding: 0 10px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; }
  .filter-actions { margin-top: 16px; display: flex; gap: 12px; }

  /* Tabela */
  .pi-table-wrap { overflow-x: auto; }
  .pi-toggle-cell { width: 52px; text-align: center; }
  .pi-right       { text-align: right; }
  .pi-nowrap      { white-space: nowrap; }
  .pi-money       { text-align: right; white-space: nowrap; font-weight: 600; color: var(--text-primary); }
  .pi-num         { text-align: right; color: var(--text-muted); font-size: 12px; }
  .pi-cliente-col { min-width: 220px; font-weight: 500; }
  .pi-pedido-col  { font-weight: 700; color: #7db3ff; }

  /* Botão expandir */
  .pi-toggle-btn {
    width: 28px; height: 28px; border-radius: 7px;
    border: 1px solid var(--border);
    background: transparent; color: var(--text-muted);
    cursor: pointer; font-size: 17px; line-height: 1;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
  }
  .pi-toggle-btn:hover { background: var(--card-hover); color: var(--text-primary); }
  .pi-toggle-btn[aria-expanded="true"] {
    background: rgba(36,83,212,.18);
    border-color: rgba(79,123,255,.35);
    color: #7db3ff;
  }

  /* Linha de detalhe */
  .pi-detail-row { display: none; }
  .pi-detail-row.open { display: table-row; }
  .pi-detail-cell {
    padding: 0 !important;
    background: rgba(0,0,0,.12) !important;
    border-bottom: 1px solid var(--border);
  }
  .pi-detail-box  { padding: 16px 20px 18px; }
  .pi-detail-head { margin-bottom: 12px; }
  .pi-detail-head h3 { font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0 0 8px; }
  .pi-detail-chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .pi-chip {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 999px; background: var(--card-bg); border: 1px solid var(--border);
    font-size: 11.5px; font-weight: 500; color: var(--text-muted);
  }
  .pi-detail-loading, .pi-empty-state {
    padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;
  }
  .pi-detail-error {
    padding: 12px 16px; border-radius: 8px; font-size: 13px;
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2); color: var(--red);
  }

  /* Subtabela de itens */
  .pi-subtable { width: 100%; border-collapse: collapse; margin-top: 4px; }
  .pi-subtable th {
    padding: 8px 14px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-muted); border-bottom: 1px solid var(--border);
    background: rgba(0,0,0,.08); white-space: nowrap;
  }
  .pi-subtable td {
    padding: 10px 14px; font-size: 12.5px;
    border-bottom: 1px solid var(--border); color: var(--text-primary);
  }
  .pi-subtable tr:last-child td { border-bottom: none; }

  /* Categoria chip */
  .cat-chip     { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
  .cat-bag-si   { background: rgba(45,106,255,.1);  color: #7db3ff; }
  .cat-bag-ci   { background: rgba(167,139,250,.1); color: #a78bfa; }
  .cat-bag-tsi  { background: rgba(56,189,248,.1);  color: #38bdf8; }
  .cat-bag-tci  { background: rgba(251,191,36,.1);  color: #fbbf24; }
  .cat-sac-si   { background: rgba(0,201,167,.1);   color: var(--teal); }
  .cat-sac-ci   { background: rgba(239,68,68,.1);   color: var(--red); }
  .cat-nc       { background: rgba(255,255,255,.06); color: var(--text-muted); }

  /* Status de produção chip */
  .status-chip  { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
  .st-pendente  { background: rgba(245,158,11,.12); color: var(--amber); }
  .st-producao  { background: rgba(45,106,255,.12); color: #7db3ff; }
  .st-expedicao { background: rgba(0,201,167,.12);  color: var(--teal); }
  .st-finalizado{ background: rgba(34,197,94,.12);  color: var(--green); }

  .pi-obs   { max-width: 280px; line-height: 1.4; white-space: normal; color: var(--text-muted); font-size: 12px; }
  .pi-muted { color: var(--text-muted); font-size: 12px; }

  /* Campo prazo editável */
  .prazo-wrap { display: flex; align-items: center; gap: 5px; white-space: nowrap; }
  .prazo-input {
    width: 54px; height: 28px; padding: 0 6px;
    font-size: 12.5px; font-family: 'Segoe UI', sans-serif;
    background: transparent; border: 1px solid transparent;
    border-radius: 6px; color: var(--text-primary); text-align: right;
    transition: background .15s, border-color .15s;
    -moz-appearance: textfield;
  }
  .prazo-input::-webkit-outer-spin-button,
  .prazo-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .prazo-input:hover { background: var(--card-hover); border-color: var(--border); }
  .prazo-input:focus { background: var(--card-bg); border-color: rgba(79,123,255,.5); outline: none; }
  .prazo-unit { font-size: 11px; color: var(--text-muted); }
  .prazo-saving { font-size: 11px; color: var(--text-muted); opacity: 0; transition: opacity .2s; }
  .prazo-saving.visible { opacity: 1; }

  /* Situação badge na tabela */
  .sit-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
  .sit-ok    { background: rgba(34,197,94,.12);  color: var(--green); }
  .sit-part  { background: rgba(251,191,36,.12); color: var(--amber); }
  .sit-none  { background: rgba(255,255,255,.06); color: var(--text-muted); }

  /* Tipo badge (Venda / Amostra) */
  .tipo-badge         { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
  .tipo-amostra       { background: rgba(139,92,246,.14);  color: #c4b5fd; }
  .tipo-misto         { background: rgba(251,191,36,.10);  color: var(--amber); }
  .filter-amostras{font-size:10.5px;color:var(--text-muted);opacity:.45;text-decoration:none;padding:0 8px;height:36px;display:inline-flex;align-items:center;border-radius:6px;border:1px solid transparent;transition:opacity .15s,border-color .15s;white-space:nowrap;}
  .filter-amostras:hover{opacity:.85;border-color:var(--border);}
  .filter-amostras.ativo{opacity:.7;color:#c4b5fd;border-color:rgba(139,92,246,.25);}

  @media (max-width: 1280px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } .kpi-grid-4 { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 900px)  { .filter-grid { grid-template-columns: 1fr 1fr; } .kpi-grid-4 { grid-template-columns: 1fr; } }
  @media (max-width: 640px)  { .filter-grid { grid-template-columns: 1fr; } .pi-table-wrap { overflow-x: auto; } }
  @media (max-width: 480px)  { .kpi-grid-4 { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Pedidos de Venda</h1>
          <p>Pedidos importados do ERP · clique para ver os itens</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/yzidro/pedidos" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Importar Vendas
        </a>
        <a href="/pcp/planejamento" class="btn-primary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="14"/></svg>
          Planejar Produção
        </a>
      </div>
    </header>

    <div class="content">

      <!-- KPIs -->
      <div class="kpi-grid-4">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Pedidos importados</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalVendas, 0, ',', '.') ?></div>
          <div class="kpi-footer">no período filtrado</div>
        </div>
        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Valor total</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="font-size:22px;letter-spacing:-.5px"><?= piEscape(piFmtMoney($totalFaturado)) ?></div>
          <div class="kpi-footer">soma dos pedidos listados</div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-header">
            <span class="kpi-label">Ticket médio</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="font-size:22px;letter-spacing:-.5px"><?= piEscape(piFmtMoney($ticketMedio)) ?></div>
          <div class="kpi-footer">por pedido</div>
        </div>
        <div class="kpi-card green">
          <div class="kpi-header">
            <span class="kpi-label">Finalizados</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalFin, 0, ',', '.') ?></div>
          <div class="kpi-footer"><?= number_format($totalAndamento, 0, ',', '.') ?> em andamento</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="filter-card">
        <form method="get" action="<?= piEscape(piBaseUrl()) ?>">
          <div class="filter-grid">
            <div class="filter-field">
              <label for="entrega_ini">Entrega inicial</label>
              <input type="date" id="entrega_ini" name="entrega_ini" value="<?= piEscape($filters['entrega_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="entrega_fim">Entrega final</label>
              <input type="date" id="entrega_fim" name="entrega_fim" value="<?= piEscape($filters['entrega_fim']) ?>">
            </div>
            <div class="filter-field">
              <label for="emissao_ini">Emissão inicial</label>
              <input type="date" id="emissao_ini" name="emissao_ini" value="<?= piEscape($filters['emissao_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="emissao_fim">Emissão final</label>
              <input type="date" id="emissao_fim" name="emissao_fim" value="<?= piEscape($filters['emissao_fim']) ?>">
            </div>
            <div class="filter-field">
              <label for="pedido">Pedido</label>
              <input type="text" id="pedido" name="pedido" value="<?= piEscape($filters['pedido']) ?>" placeholder="Ex.: 2587">
            </div>
            <div class="filter-field">
              <label for="cliente">Cliente</label>
              <input type="text" id="cliente" name="cliente" value="<?= piEscape($filters['cliente']) ?>" placeholder="Nome do cliente">
            </div>
            <div class="filter-field">
              <label for="representante">Representante</label>
              <input type="text" id="representante" name="representante" value="<?= piEscape($filters['representante']) ?>" placeholder="Nome do representante">
            </div>
          </div>
          <div class="filter-actions" style="align-items:center;">
            <div class="filter-field" style="flex-direction:row;align-items:center;gap:8px;margin:0;">
              <label for="situacao" style="margin:0;white-space:nowrap;">Situação</label>
              <select id="situacao" name="situacao" class="filter-select" style="height:36px;padding:0 10px;font-size:12.5px;min-width:190px;">
                <option value=""             <?= $filters['situacao'] === '' ? 'selected' : '' ?>>Todos</option>
                <option value="em_andamento" <?= $filters['situacao'] === 'em_andamento' ? 'selected' : '' ?>>Em andamento</option>
                <option value="finalizados"  <?= $filters['situacao'] === 'finalizados' ? 'selected' : '' ?>>Somente finalizados</option>
              </select>
            </div>
            <?php if ($show_amostras): ?>
            <input type="hidden" name="amostras" value="1">
            <?php endif; ?>
            <button type="submit" class="btn-refresh">Filtrar</button>
            <a href="<?= piEscape(piBaseUrl()) ?>" class="icon-btn" title="Limpar filtros"
               style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
            <?php
              $baseQs = array_filter([
                  'entrega_ini'   => $filters['entrega_ini'],
                  'entrega_fim'   => $filters['entrega_fim'],
                  'pedido'        => $filters['pedido'],
                  'cliente'       => $filters['cliente'],
                  'representante' => $filters['representante'],
                  'situacao'      => $filters['situacao'],
              ]);
              $urlAmostras    = piBaseUrl() . '?' . http_build_query(array_merge($baseQs, ['amostras' => '1']));
              $urlSemAmostras = piBaseUrl() . ($baseQs ? '?' . http_build_query($baseQs) : '');
            ?>
            <?php if ($show_amostras): ?>
            <a href="<?= piEscape($urlSemAmostras) ?>" class="filter-amostras ativo" title="Ocultar amostras">amostras ✕</a>
            <?php else: ?>
            <a href="<?= piEscape($urlAmostras) ?>" class="filter-amostras" title="Exibir amostras (< 20 un)">+ amostras</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Tabela de pedidos -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Pedidos de Venda</span>
          <span class="source-badge src-mysql">MySQL</span>
        </div>
        <div class="pi-table-wrap">
          <table>
            <thead>
              <tr>
                <th class="pi-toggle-cell"></th>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Emissão</th>
                <th>Entrega</th>
                <th>Representante</th>
                <th class="pi-right">Itens</th>
                <th class="pi-right">Total</th>
                <th>Prazo produção</th>
                <th>Tipo</th>
                <th>Situação</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$vendas): ?>
                <tr>
                  <td colspan="10">
                    <div class="pi-empty-state">Nenhum pedido encontrado. Importe pedidos do ERP primeiro.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($vendas as $row):
                    $venCod = (int) ($row['ven_codigo'] ?? 0);
                    $pedido = (string) ($row['pedido'] ?? '');
                    $detailId = 'detail_' . $venCod;
                    $qtdItens = (int) ($row['qtd_itens'] ?? 0);
                    $qtdFin = (int) ($row['qtd_fin'] ?? 0);
                    if ($qtdItens === 0) {
                        $sitClass = 'sit-none';
                        $sitLabel = '—';
                    } elseif ($qtdFin === $qtdItens) {
                        $sitClass = 'sit-ok';
                        $sitLabel = 'Finalizado';
                    } else {
                        $sitClass = 'sit-part';
                        $sitLabel = $qtdFin . '/' . $qtdItens . ' ✓';
                    }
                    $minQtde = $row['min_qtde'] !== null ? (float) $row['min_qtde'] : null;
                    $maxQtde = $row['max_qtde'] !== null ? (float) $row['max_qtde'] : null;
                    // Badge de tipo só exibido quando amostras estão visíveis
                    $tipoClass = ''; $tipoLabel = '';
                    if ($show_amostras && $minQtde !== null) {
                        if ($maxQtde < 20) {
                            $tipoClass = 'tipo-amostra'; $tipoLabel = 'Amostra';
                        } elseif ($minQtde < 20) {
                            $tipoClass = 'tipo-misto'; $tipoLabel = 'Misto';
                        }
                    }
                    ?>
                  <tr>
                    <td class="pi-toggle-cell">
                      <button
                        type="button"
                        class="pi-toggle-btn"
                        data-ven-codigo="<?= $venCod ?>"
                        data-detail-id="<?= piEscape($detailId) ?>"
                        data-pedido="<?= piEscape($pedido) ?>"
                        data-cliente="<?= piEscape($row['cliente'] ?? '—') ?>"
                        data-rep="<?= piEscape($row['representante'] ?? '—') ?>"
                        data-total="<?= piEscape(piFmtMoney($row['total_pedido'] ?? 0)) ?>"
                        aria-expanded="false"
                        title="Ver itens"
                      >+</button>
                    </td>
                    <td class="pi-pedido-col"><?= piEscape($pedido) ?></td>
                    <td class="pi-cliente-col"><?= piEscape($row['cliente'] ?? '—') ?></td>
                    <td class="pi-nowrap"><?= piEscape(piFmtDate($row['dataemissao'] ?? '')) ?></td>
                    <td class="pi-nowrap"><?= piEscape(piFmtDate($row['datafatura'] ?? '')) ?></td>
                    <td class="pi-nowrap"><?= piEscape($row['representante'] ?? '—') ?></td>
                    <td class="pi-num"><?= $qtdItens ?></td>
                    <td class="pi-money"><?= piEscape(piFmtMoney($row['total_pedido'] ?? 0)) ?></td>
                    <td>
                      <?php $prazoDias = $row['prazo_dias'] !== null ? (int) $row['prazo_dias'] : ''; ?>
                      <div class="prazo-wrap">
                        <input
                          type="number"
                          class="prazo-input"
                          min="1"
                          value="<?= piEscape((string) $prazoDias) ?>"
                          placeholder="—"
                          data-ven-codigo="<?= $venCod ?>"
                          title="Prazo de produção estimado (dias) — editável"
                        >
                        <span class="prazo-unit">dias</span>
                        <span class="prazo-saving" id="prazo-saving-<?= $venCod ?>">✓</span>
                      </div>
                    </td>
                    <td><?= $tipoLabel ? '<span class="tipo-badge ' . $tipoClass . '">' . $tipoLabel . '</span>' : '' ?></td>
                    <td><span class="sit-badge <?= $sitClass ?>"><?= piEscape($sitLabel) ?></span></td>
                  </tr>
                  <tr id="<?= piEscape($detailId) ?>" class="pi-detail-row">
                    <td colspan="10" class="pi-detail-cell">
                      <div class="pi-detail-box">
                        <div class="pi-detail-head">
                          <h3>Itens do pedido <?= piEscape($pedido) ?></h3>
                          <div class="pi-detail-chips">
                            <span class="pi-chip"><?= piEscape($row['cliente'] ?? '—') ?></span>
                            <span class="pi-chip"><?= piEscape($row['representante'] ?? '—') ?></span>
                            <span class="pi-chip"><?= piEscape(piFmtMoney($row['total_pedido'] ?? 0)) ?></span>
                          </div>
                        </div>
                        <div class="pi-detail-content">
                          <div class="pi-detail-loading">Carregando itens…</div>
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
  const baseUrl       = <?= json_encode(piBaseUrl(), JSON_UNESCAPED_SLASHES) ?>;
  const currentParams = new URLSearchParams(window.location.search);

  function moneyBR(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }
  function qtyBR(v) {
    return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  }
  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  // ── Categoria → CSS class ───────────────────────────────────────────────────
  function catClass(cat) {
    const map = {
      'Bag sem impressão':          'cat-bag-si',
      'Bag com impressão':          'cat-bag-ci',
      'Bag travado sem impressão':  'cat-bag-tsi',
      'Bag travado com impressão':  'cat-bag-tci',
      'Sacaria sem impressão':      'cat-sac-si',
      'Sacaria com impressão':      'cat-sac-ci',
    };
    return map[cat] || 'cat-nc';
  }

  // ── Status de produção → CSS class ──────────────────────────────────────────
  function statusClass(s) {
    const map = {
      'Pendente de produção': 'st-pendente',
      'Produção':             'st-producao',
      'Aguardando expedição': 'st-expedicao',
      'Finalizado':           'st-finalizado',
    };
    return map[s] || 'st-pendente';
  }

  const SHOW_AMOSTRAS = <?= $show_amostras ? 'true' : 'false' ?>;
  function tipoBadge(qtd) {
    if (!SHOW_AMOSTRAS) return '';
    const q = Number(qtd) || 0;
    return q < 20 ? '<span class="tipo-badge tipo-amostra">Amostra</span>' : '';
  }

  // ── Subtabela de itens ──────────────────────────────────────────────────────
  function buildItemsTable(items) {
    if (!Array.isArray(items) || !items.length) {
      return '<div class="pi-empty-state">Nenhum item encontrado para este pedido.</div>';
    }

    const rows = items.map(item => `
      <tr>
        <td><strong>${escHtml(item.produto || '—')}</strong></td>
        <td><span class="cat-chip ${catClass(item.categoria)}">${escHtml(item.categoria || '—')}</span></td>
        <td>${tipoBadge(item.qtd)}</td>
        <td class="pi-right">${qtyBR(item.qtd)} ${escHtml(item.unidade || '')}</td>
        <td class="pi-right pi-nowrap">${moneyBR(item.valor_unit)}</td>
        <td class="pi-right pi-nowrap"><strong>${moneyBR(item.total_produto)}</strong></td>
        <td><span class="status-chip ${statusClass(item.status)}">${escHtml(item.status || '—')}</span></td>
        <td class="pi-obs">${escHtml(item.obs || '—')}</td>
      </tr>
    `).join('');

    return `
      <table class="pi-subtable">
        <thead>
          <tr>
            <th>Produto</th>
            <th>Categoria PCP</th>
            <th>Tipo</th>
            <th class="pi-right">Qtd.</th>
            <th class="pi-right">Valor unit.</th>
            <th class="pi-right">Total</th>
            <th>Status produção</th>
            <th>Obs.</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  // ── Expandir / recolher itens ───────────────────────────────────────────────
  async function toggleOrder(button) {
    const detailId  = button.dataset.detailId;
    const venCodigo = button.dataset.venCodigo;
    const detailRow = document.getElementById(detailId);
    if (!detailRow) return;

    const willOpen = !detailRow.classList.contains('open');

    document.querySelectorAll('.pi-detail-row.open').forEach(row => {
      if (row !== detailRow) row.classList.remove('open');
    });
    document.querySelectorAll('.pi-toggle-btn[aria-expanded="true"]').forEach(btn => {
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

    const container = detailRow.querySelector('.pi-detail-content');
    if (container.dataset.loaded === '1') return;

    container.innerHTML = '<div class="pi-detail-loading">Carregando itens…</div>';

    try {
      const res  = await fetch(`${baseUrl}?ajax=itens&ven_codigo=${encodeURIComponent(venCodigo)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.msg || 'Falha ao carregar os itens.');
      container.innerHTML      = buildItemsTable(data.itens);
      container.dataset.loaded = '1';
    } catch (err) {
      container.innerHTML = `<div class="pi-detail-error">${escHtml(err.message)}</div>`;
    }
  }

  document.querySelectorAll('.pi-toggle-btn').forEach(btn => {
    btn.addEventListener('click', () => toggleOrder(btn));
  });

  // ── Campo prazo editável ───────────────────────────────────────────────────
  let prazoTimer = null;

  async function savePrazo(input) {
    const venCodigo = input.dataset.venCodigo;
    const prazo     = input.value.trim();
    const indicator = document.getElementById('prazo-saving-' + venCodigo);

    try {
      const res  = await fetch(`${baseUrl}?ajax=set_prazo&ven_codigo=${encodeURIComponent(venCodigo)}&prazo=${encodeURIComponent(prazo)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.msg || 'Erro ao salvar prazo.');
      if (indicator) {
        indicator.textContent = '✓';
        indicator.style.color = 'var(--green)';
        indicator.classList.add('visible');
        setTimeout(() => indicator.classList.remove('visible'), 1500);
      }
    } catch (err) {
      if (indicator) {
        indicator.textContent = '✗';
        indicator.style.color = 'var(--red)';
        indicator.classList.add('visible');
        setTimeout(() => indicator.classList.remove('visible'), 2000);
      }
    }
  }

  document.querySelectorAll('.prazo-input').forEach(input => {
    input.addEventListener('change', () => {
      clearTimeout(prazoTimer);
      prazoTimer = setTimeout(() => savePrazo(input), 400);
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    });
    // Impede que o scroll da página mude o número
    input.addEventListener('wheel', e => e.preventDefault(), { passive: false });
  });
</script>
</body>
</html>
