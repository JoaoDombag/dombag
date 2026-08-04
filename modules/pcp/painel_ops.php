<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Painel de OPs (Ordens de Produção)
//  Fonte: PostgreSQL ERP Yzidro (VW_ORDEM_PRODUCAO + apontamentos)
//  Somente leitura.
// ══════════════════════════════════════════════════════════════

function popEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function popSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function popFmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function popFmtDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function popStatusLabel(string $status): array
{
    $map = [
        'PENDENTE'    => ['Pendente', 'warn'],
        'EM_PRODUCAO' => ['Em Produção', 'ok'],
        'ATRASADA'    => ['Atrasada', 'danger'],
        'FINALIZADA'  => ['Finalizada', 'info'],
    ];
    return $map[$status] ?? [$status, 'info'];
}

function popBaseUrl(): string
{
    return '/pcp/painel-ops';
}

// ── Consulta principal ──────────────────────────────────────────────────────
function popFetchOPs($pg, array $filters): array
{
    [$baseSql, $params] = popBuildBaseCte($filters);

    $whereStatus = '';
    if ($filters['status'] !== '') {
        $params[] = $filters['status'];
        $whereStatus = 'WHERE STATUS_CALC = $' . count($params);
    }

    $sql = "
        {$baseSql}
        SELECT * FROM CALC
        {$whereStatus}
        ORDER BY DATA_EMISSAO DESC, COD_PRODUCAO DESC
        LIMIT 1000
    ";

    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar as OPs: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function popFetchContagens($pg, array $filters): array
{
    [$baseSql, $params] = popBuildBaseCte($filters);
    $sql = "
        {$baseSql}
        SELECT STATUS_CALC, COUNT(*) AS QTD FROM CALC GROUP BY STATUS_CALC
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);

    $out = ['PENDENTE' => 0, 'EM_PRODUCAO' => 0, 'ATRASADA' => 0, 'FINALIZADA' => 0];
    foreach ($rows as $r) {
        $out[$r['status_calc']] = (int) $r['qtd'];
    }
    return $out;
}

// ── Monta a CTE base (filtros de emissão/cliente/produto/cód. OP) + cálculo de status ──
function popBuildBaseCte(array $filters): array
{
    $params = [];
    $extras = [];

    if ($filters['emissao_ini'] !== '') {
        $params[] = $filters['emissao_ini'];
        $extras[] = 'V.DATA_EMISSAO >= $' . count($params);
    }
    if ($filters['emissao_fim'] !== '') {
        $params[] = $filters['emissao_fim'];
        $extras[] = 'V.DATA_EMISSAO <= $' . count($params) . "::date + interval '1 day'";
    }
    if ($filters['cod_op'] !== '') {
        $params[] = (int) $filters['cod_op'];
        $extras[] = 'V.COD_PRODUCAO = $' . count($params);
    }
    if ($filters['cliente'] !== '') {
        $params[] = '%' . $filters['cliente'] . '%';
        $extras[] = 'V.CLIENTE ILIKE $' . count($params);
    }
    if ($filters['produto'] !== '') {
        $params[] = '%' . $filters['produto'] . '%';
        $extras[] = 'V.PRODUTO ILIKE $' . count($params);
    }

    $where = $extras ? ('WHERE ' . implode("\n      AND ", $extras)) : '';

    $sql = "
        WITH BASE AS (
           SELECT V.COD_PRODUCAO, V.COD_PRODUTO, V.DATA_EMISSAO, V.DATA_ENTREGA, V.DATA_FINALIZA
                 ,V.COD_CLIENTE, V.CLIENTE, V.PRODUTO, V.UNIDADE, V.TAMANHO, V.VENDA_REF
                 ,V.QTD_PEDIDO, V.VALOR_UNIT, V.TOTAL_PRODUTOS
                 ,COALESCE((
                     SELECT SUM(A.OIAP_QTD_PRODUZIDA)
                       FROM OP_ITENS_ATIVIDADES_APONTADAS A
                      WHERE A.PROD_CODIGO = V.COD_PRODUCAO
                        AND A.PRO_CODIGO  = V.COD_PRODUTO
                        AND NOT A.OIAP_EXCLUIDO
                  ), 0)                                                        AS QTD_PRODUZIDA_REAL
             FROM VW_ORDEM_PRODUCAO V
             {$where}
        ),
        CALC AS (
           SELECT B.*
                 ,AT.QTD_ATIVOS                                                AS AT_QTD_ATIVOS
                 ,AT.CT_DESCRICAO                                              AS AT_CT_DESCRICAO
                 ,AT.FU_NOME                                                   AS AT_FU_NOME
                 ,CASE
                    WHEN B.DATA_FINALIZA IS NOT NULL THEN 'FINALIZADA'
                    WHEN COALESCE(AT.QTD_ATIVOS, 0) > 0 THEN 'EM_PRODUCAO'
                    WHEN B.QTD_PRODUZIDA_REAL > 0 THEN 'EM_PRODUCAO'
                    WHEN B.DATA_ENTREGA IS NOT NULL AND B.DATA_ENTREGA < CURRENT_DATE THEN 'ATRASADA'
                    ELSE 'PENDENTE'
                  END                                                          AS STATUS_CALC
             FROM BASE B
             LEFT JOIN LATERAL (
                  SELECT COUNT(*)                                    AS QTD_ATIVOS
                        ,STRING_AGG(DISTINCT CT.CT_DESCRICAO, ', ')  AS CT_DESCRICAO
                        ,STRING_AGG(DISTINCT F.FU_NOME, ', ')        AS FU_NOME
                    FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
                   INNER JOIN CENTRO_TRABALHO CT ON CT.CT_CODIGO = IAA.CT_CODIGO
                   INNER JOIN FUNCIONARIO     F  ON F.FU_CODIGO  = IAA.FU_CODIGO
                   WHERE IAA.PROD_CODIGO = B.COD_PRODUCAO
                     AND IAA.PRO_CODIGO  = B.COD_PRODUTO
                     AND NOT IAA.OIAP_EXCLUIDO
                     AND IAA.OIAP_DATA_HORA_FIM IS NULL
             ) AT ON TRUE
        )
    ";

    return [$sql, $params];
}

// ── Todos os apontamentos (histórico completo) de uma OP+produto ─────────────
function popFetchApontamentosOP($pg, int $codProducao, int $codProduto): array
{
    $sql = "
        SELECT IAA.OIAP_CODIGO, IAA.OIAP_STATUS, IAA.OIAP_DATA_HORA_INICIO, IAA.OIAP_DATA_HORA_FIM
              ,IAA.OIAP_QTD_PRODUZIDA, F.FU_CODIGO, F.FU_NOME, CT.CT_CODIGO, CT.CT_DESCRICAO
          FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
         INNER JOIN FUNCIONARIO     F  ON F.FU_CODIGO  = IAA.FU_CODIGO
         INNER JOIN CENTRO_TRABALHO CT ON CT.CT_CODIGO = IAA.CT_CODIGO
         WHERE IAA.PROD_CODIGO = $1
           AND IAA.PRO_CODIGO  = $2
           AND NOT IAA.OIAP_EXCLUIDO
         ORDER BY IAA.OIAP_DATA_HORA_INICIO DESC
    ";
    $res = @pg_query_params($pg, $sql, [$codProducao, $codProduto]);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Filtros da requisição ────────────────────────────────────────────────────
$filters = [
    'emissao_ini' => popSanitizeDate($_GET['emissao_ini'] ?? date('Y-m-d', strtotime('-90 days'))),
    'emissao_fim' => popSanitizeDate($_GET['emissao_fim'] ?? date('Y-m-d')),
    'status'      => trim((string) ($_GET['status'] ?? '')),
    'cod_op'      => trim((string) ($_GET['cod_op'] ?? '')),
    'cliente'     => trim((string) ($_GET['cliente'] ?? '')),
    'produto'     => trim((string) ($_GET['produto'] ?? '')),
];

$pg = dbPG();

// ── AJAX: histórico completo de apontamentos de uma OP (painel lateral) ──────
if (($_GET['action'] ?? '') === 'detalhe_op') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    $codProducao = (int) ($_GET['cod_producao'] ?? 0);
    $codProduto = (int) ($_GET['cod_produto'] ?? 0);
    try {
        $apontamentos = ($codProducao > 0 && $codProduto > 0) ? popFetchApontamentosOP($pg, $codProducao, $codProduto) : [];
        echo json_encode(['apontamentos' => $apontamentos], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$error = '';
$ops = [];
$contagens = ['PENDENTE' => 0, 'EM_PRODUCAO' => 0, 'ATRASADA' => 0, 'FINALIZADA' => 0];

if (!$pg) {
    $error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    try {
        $ops = popFetchOPs($pg, $filters);
        $contagens = popFetchContagens($pg, $filters);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$activeFiltersCount = count(array_filter([
    $filters['status'], $filters['cod_op'], $filters['cliente'], $filters['produto'],
], fn ($v) => $v !== ''));
$totalOPs = array_sum($contagens);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel de OPs | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .kpi-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
  @media (max-width: 900px) { .kpi-grid-4 { grid-template-columns: repeat(2, 1fr); } }

  .filter-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; margin-bottom: 16px; }
  .filter-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; user-select: none; }
  .filter-card-head h2 { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
  .filter-count-badge { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: rgba(45,106,255,.12); color: #7db3ff; }
  .filter-toggle-btn { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s, transform .2s; flex-shrink: 0; }
  .filter-toggle-btn:hover { background: var(--card-hover); color: var(--text-primary); }
  .filter-toggle-btn svg { transition: transform .2s ease; }
  .filter-card.collapsed .filter-toggle-btn svg { transform: rotate(-90deg); }
  .filter-card-body { overflow: hidden; max-height: 300px; opacity: 1; margin-top: 16px; transition: max-height .25s ease, opacity .2s ease, margin .25s ease; }
  .filter-card.collapsed .filter-card-body { max-height: 0; opacity: 0; margin-top: 0; }
  .filter-grid { display: grid; grid-template-columns: 150px 150px 150px minmax(200px,1fr) minmax(220px,1fr) minmax(200px,1fr); gap: 16px; align-items: end; }
  .filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
  .filter-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
  .filter-field input, .filter-field select { height: 36px; padding: 0 10px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; width: 100%; min-width: 0; }
  .filter-field select option { background: var(--blue-mid); }
  .filter-actions { margin-top: 16px; display: flex; gap: 12px; }
  @media (max-width: 1300px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (max-width: 700px) { .filter-grid { grid-template-columns: 1fr 1fr; } }

  .pop-cod { color: var(--text-muted); font-size: 11px; }
  .pop-row { cursor: pointer; }
  .pop-row:hover { background: var(--card-hover); }

  .pop-bar-wrap { display: flex; align-items: center; gap: 8px; min-width: 140px; }
  .pop-bar-bg { flex: 1; height: 6px; background: rgba(255,255,255,.07); border-radius: 3px; overflow: hidden; }
  .pop-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg,#00c9a7,#00e5bf); }
  .pop-bar-fill.over { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
  .pop-bar-pct { font-size: 11px; color: var(--text-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }

  .pop-empty-row td { text-align: center; padding: 28px 16px; color: var(--text-muted); font-size: 12.5px; }

  /* ── Painel lateral (drawer) ─────────────────────────────────────────────── */
  .pop-drawer-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 300; }
  .pop-drawer-overlay.open { opacity: 1; pointer-events: all; }
  .pop-drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 400px; max-width: calc(100vw - 24px); background: #0d1e3a; border-left: 1px solid var(--border); box-shadow: -12px 0 40px rgba(0,0,0,.45); transform: translateX(100%); transition: transform .25s ease; z-index: 301; display: flex; flex-direction: column; }
  .pop-drawer.open { transform: translateX(0); }
  .pop-drawer-head { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-shrink: 0; }
  .pop-drawer-title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
  .pop-drawer-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
  .pop-drawer-close { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .pop-drawer-close:hover { background: var(--card-hover); color: var(--text-primary); }
  .pop-drawer-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
  .pop-field-group { margin-bottom: 18px; }
  .pop-field-group-title { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 8px; }
  .pop-field-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border); }
  .pop-field-row:last-child { border-bottom: none; }
  .pop-field-label { font-size: 12px; color: var(--text-muted); }
  .pop-field-value { font-size: 12.5px; font-weight: 600; color: var(--text-primary); text-align: right; }
  .pop-progress { background: rgba(0,201,167,.08); border: 1px solid rgba(0,201,167,.25); border-radius: var(--radius); padding: 14px 16px; margin-bottom: 18px; }
  .pop-progress.over { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.25); }
  .pop-progress-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
  .pop-progress-pct { font-size: 20px; font-weight: 700; color: var(--teal); }
  .pop-progress.over .pop-progress-pct { color: var(--amber); }
  .pop-progress-nums { font-size: 11.5px; color: var(--text-muted); margin-top: 6px; text-align: right; }

  .pop-apont-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  .pop-apont-table th { text-align: left; padding: 7px 8px; color: var(--text-muted); font-weight: 600; font-size: 10px; text-transform: uppercase; border-bottom: 1px solid var(--border); white-space: nowrap; }
  .pop-apont-table td { padding: 7px 8px; border-bottom: 1px solid var(--border); color: var(--text-primary); }
  .pop-apont-table td.td-right { text-align: right; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Painel de OPs</h1>
          <p>Status e produção — dados do ERP</p>
        </div>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= popEscape($error) ?>
        </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-grid-4">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Total no período</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($totalOPs, 0, ',', '.') ?></div>
          <div class="kpi-footer">ordens de produção</div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-header">
            <span class="kpi-label">Pendentes</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($contagens['PENDENTE'], 0, ',', '.') ?></div>
          <div class="kpi-footer">sem produção iniciada</div>
        </div>
        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Em Produção</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($contagens['EM_PRODUCAO'], 0, ',', '.') ?></div>
          <div class="kpi-footer">com apontamento ou produção parcial</div>
        </div>
        <div class="kpi-card red">
          <div class="kpi-header">
            <span class="kpi-label">Atrasadas</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($contagens['ATRASADA'], 0, ',', '.') ?></div>
          <div class="kpi-footer">prazo de entrega vencido</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="filter-card" id="filterCard">
        <div class="filter-card-head" id="filterCardHead">
          <h2>
            Filtros
            <?php if ($activeFiltersCount > 0): ?>
              <span class="filter-count-badge"><?= $activeFiltersCount ?> ativo<?= $activeFiltersCount !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
          </h2>
          <button type="button" class="filter-toggle-btn" id="filterToggleBtn" title="Minimizar filtros">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
        </div>
        <div class="filter-card-body" id="filterCardBody">
        <form method="get" action="<?= popEscape(popBaseUrl()) ?>">
          <div class="filter-grid">
            <div class="filter-field">
              <label for="emissao_ini">Emissão de</label>
              <input type="date" id="emissao_ini" name="emissao_ini" value="<?= popEscape($filters['emissao_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="emissao_fim">Emissão até</label>
              <input type="date" id="emissao_fim" name="emissao_fim" value="<?= popEscape($filters['emissao_fim']) ?>">
            </div>
            <div class="filter-field">
              <label for="status">Status</label>
              <select id="status" name="status">
                <option value="">Todos</option>
                <option value="PENDENTE" <?= $filters['status'] === 'PENDENTE' ? 'selected' : '' ?>>Pendente</option>
                <option value="EM_PRODUCAO" <?= $filters['status'] === 'EM_PRODUCAO' ? 'selected' : '' ?>>Em Produção</option>
                <option value="ATRASADA" <?= $filters['status'] === 'ATRASADA' ? 'selected' : '' ?>>Atrasada</option>
                <option value="FINALIZADA" <?= $filters['status'] === 'FINALIZADA' ? 'selected' : '' ?>>Finalizada</option>
              </select>
            </div>
            <div class="filter-field">
              <label for="cod_op">Cód. OP</label>
              <input type="number" id="cod_op" name="cod_op" value="<?= popEscape($filters['cod_op']) ?>" placeholder="Ex.: 3066">
            </div>
            <div class="filter-field">
              <label for="cliente">Cliente</label>
              <input type="text" id="cliente" name="cliente" value="<?= popEscape($filters['cliente']) ?>" placeholder="Nome do cliente">
            </div>
            <div class="filter-field">
              <label for="produto">Produto</label>
              <input type="text" id="produto" name="produto" value="<?= popEscape($filters['produto']) ?>" placeholder="Ex.: Big Bag">
            </div>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-refresh">Filtrar</button>
            <a href="<?= popEscape(popBaseUrl()) ?>" class="icon-btn" title="Limpar filtros" style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
          </div>
        </form>
        </div>
      </div>

      <!-- Tabela -->
      <div class="panel-table">
        <div class="panel-header">
          <span class="panel-title">Ordens de Produção</span>
          <span class="source-badge src-erp">ERP</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Status</th>
                <th>Cód. OP</th>
                <th>Emissão</th>
                <th>Entrega</th>
                <th>Cliente</th>
                <th>Produto</th>
                <th class="td-right">Qtd. Pedido</th>
                <th>Progresso</th>
                <th>Máquinas Ativas</th>
                <th>Operadores Ativos</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$ops): ?>
                <tr>
                  <td colspan="10">
                    <div class="td-muted" style="padding:24px;text-align:center;">Nenhuma OP encontrada com os filtros informados.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($ops as $row):
                    $produzida = (float) ($row['qtd_produzida_real'] ?? 0);
                    $pedido = (float) ($row['qtd_pedido'] ?? 0);
                    $pct = $pedido > 0 ? round(($produzida / $pedido) * 100) : 0;
                    $over = $pct > 100;
                    $fillPct = min(100, $pct);
                    [$stLabel, $stClass] = popStatusLabel((string) ($row['status_calc'] ?? ''));
                ?>
                  <tr class="pop-row" data-op="<?= popEscape(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>">
                    <td><span class="status-pill <?= $stClass ?>"><?= popEscape($stLabel) ?></span></td>
                    <td class="pop-cod"><?= popEscape($row['cod_producao'] ?? '—') ?></td>
                    <td><?= popEscape(popFmtDate($row['data_emissao'] ?? '')) ?></td>
                    <td><?= popEscape(popFmtDate($row['data_entrega'] ?? '')) ?></td>
                    <td><?= popEscape($row['cliente'] ?? '—') ?></td>
                    <td><?= popEscape($row['produto'] ?? '—') ?></td>
                    <td class="td-right"><?= number_format($pedido, 0, ',', '.') ?></td>
                    <td>
                      <div class="pop-bar-wrap">
                        <div class="pop-bar-bg"><div class="pop-bar-fill<?= $over ? ' over' : '' ?>" style="width:<?= $fillPct ?>%"></div></div>
                        <span class="pop-bar-pct"><?= $pct ?>%</span>
                      </div>
                    </td>
                    <td class="td-muted">
                      <?= popEscape($row['at_ct_descricao'] ?? '—') ?>
                      <?php if ((int) ($row['at_qtd_ativos'] ?? 0) > 1): ?>
                        <span class="filter-count-badge"><?= (int) $row['at_qtd_ativos'] ?>x</span>
                      <?php endif; ?>
                    </td>
                    <td class="td-muted"><?= popEscape($row['at_fu_nome'] ?? '—') ?></td>
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

<!-- Painel lateral de detalhes -->
<div class="pop-drawer-overlay" id="popDrawerOverlay"></div>
<div class="pop-drawer" id="popDrawer">
  <div class="pop-drawer-head">
    <div>
      <div class="pop-drawer-title" id="popDrawerTitle">—</div>
      <div class="pop-drawer-sub" id="popDrawerSub">—</div>
    </div>
    <button type="button" class="pop-drawer-close" id="popDrawerClose" title="Fechar">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="pop-drawer-body" id="popDrawerBody"></div>
</div>

<script>
const STATUS_MAP = {
  PENDENTE:    ['Pendente', 'warn'],
  EM_PRODUCAO: ['Em Produção', 'ok'],
  ATRASADA:    ['Atrasada', 'danger'],
  FINALIZADA:  ['Finalizada', 'info'],
};
const AT_STATUS_MAP = {
  A:  ['Em Andamento', 'info'],
  EP: ['Em Produção', 'ok'],
  P:  ['Pausado', 'warn'],
  F:  ['Finalizado', 'info'],
  C:  ['Cancelado', 'danger'],
};

function popEsc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function popFmtDate(v) {
  if (!v) return '—';
  const d = new Date(v.replace(' ', 'T'));
  if (isNaN(d.getTime())) return v;
  return d.toLocaleDateString('pt-BR');
}
function popFmtDateTime(v) {
  if (!v) return '—';
  const d = new Date(v.replace(' ', 'T'));
  if (isNaN(d.getTime())) return v;
  return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function popStatusPill(status) {
  const [label, cls] = STATUS_MAP[status] || [status || '—', 'info'];
  return `<span class="status-pill ${cls}">${label}</span>`;
}
function popAtStatusPill(status) {
  const s = (status || '').trim();
  if (!s) return '—';
  const [label, cls] = AT_STATUS_MAP[s] || [s, 'info'];
  return `<span class="status-pill ${cls}">${label}</span>`;
}

function abrirDetalheOP(op) {
  document.getElementById('popDrawerTitle').textContent = 'OP ' + op.cod_producao;
  document.getElementById('popDrawerSub').textContent = op.produto || '—';

  const produzida = Number(op.qtd_produzida_real || 0);
  const pedido = Number(op.qtd_pedido || 0);
  const pct = pedido > 0 ? Math.round((produzida / pedido) * 100) : 0;
  const over = pct > 100;

  document.getElementById('popDrawerBody').innerHTML = `
    <div class="pop-progress${over ? ' over' : ''}">
      <div class="pop-progress-header">
        <span class="pop-field-label">Progresso da OP</span>
        <span class="pop-progress-pct">${pedido > 0 ? pct + '%' : '—'}</span>
      </div>
      <div class="pop-bar-bg" style="height:8px;"><div class="pop-bar-fill${over ? ' over' : ''}" style="width:${Math.min(100, pct)}%"></div></div>
      <div class="pop-progress-nums">Produzido: <strong>${produzida.toLocaleString('pt-BR')}</strong> / Pedido: <strong>${pedido.toLocaleString('pt-BR')}</strong></div>
    </div>

    <div class="pop-field-group">
      <div class="pop-field-group-title">Status</div>
      <div class="pop-field-row"><span class="pop-field-label">Situação</span><span class="pop-field-value">${popStatusPill(op.status_calc)}</span></div>
      <div class="pop-field-row"><span class="pop-field-label">Emissão</span><span class="pop-field-value">${popFmtDate(op.data_emissao)}</span></div>
      <div class="pop-field-row"><span class="pop-field-label">Entrega</span><span class="pop-field-value">${popFmtDate(op.data_entrega)}</span></div>
      <div class="pop-field-row"><span class="pop-field-label">Finalização</span><span class="pop-field-value">${popFmtDateTime(op.data_finaliza)}</span></div>
    </div>

    <div class="pop-field-group">
      <div class="pop-field-group-title">Cliente e Produto</div>
      <div class="pop-field-row"><span class="pop-field-label">Cliente</span><span class="pop-field-value">${popEsc(op.cliente)} (${popEsc(op.cod_cliente)})</span></div>
      <div class="pop-field-row"><span class="pop-field-label">Produto</span><span class="pop-field-value">${popEsc(op.produto)}</span></div>
      <div class="pop-field-row"><span class="pop-field-label">Tamanho</span><span class="pop-field-value">${popEsc(op.tamanho || '—')}</span></div>
      <div class="pop-field-row"><span class="pop-field-label">Unidade</span><span class="pop-field-value">${popEsc(op.unidade || '—')}</span></div>
      <div class="pop-field-row"><span class="pop-field-label">Pedido de Venda</span><span class="pop-field-value">${popEsc(op.venda_ref || '—')}</span></div>
    </div>

    <div class="pop-field-group">
      <div class="pop-field-group-title">Apontamentos (todos os funcionários)</div>
      <div id="popApontamentosList" class="pop-drawer-loading">Carregando...</div>
    </div>
  `;

  abrirDrawer();

  fetch(`?action=detalhe_op&cod_producao=${op.cod_producao}&cod_produto=${op.cod_produto}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      const el = document.getElementById('popApontamentosList');
      if (!el) return; // usuário já fechou/trocou o painel
      const lista = data.apontamentos || [];
      if (!lista.length) {
        el.innerHTML = '<div class="td-muted" style="padding:8px 0;">Nenhum apontamento registrado para esta OP.</div>';
        return;
      }
      el.className = '';
      el.innerHTML = `
        <div style="overflow-x:auto;">
          <table class="pop-apont-table">
            <thead><tr><th>Operador</th><th>Centro de Trabalho</th><th>Status</th><th class="td-right">Qtd.</th><th>Início</th><th>Fim</th></tr></thead>
            <tbody>
              ${lista.map(a => `
                <tr>
                  <td>${popEsc(a.fu_nome)}</td>
                  <td class="td-muted">${popEsc(a.ct_descricao)}</td>
                  <td>${popAtStatusPill(a.oiap_status)}</td>
                  <td class="td-right">${Number(a.oiap_qtd_produzida || 0).toLocaleString('pt-BR')}</td>
                  <td class="td-nowrap">${popFmtDateTime(a.oiap_data_hora_inicio)}</td>
                  <td class="td-nowrap">${popFmtDateTime(a.oiap_data_hora_fim)}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
    })
    .catch(() => {
      const el = document.getElementById('popApontamentosList');
      if (el) el.innerHTML = '<div class="td-muted" style="padding:8px 0;">Erro ao carregar apontamentos.</div>';
    });
}

function abrirDrawer() {
  document.getElementById('popDrawer').classList.add('open');
  document.getElementById('popDrawerOverlay').classList.add('open');
}
function fecharDrawer() {
  document.getElementById('popDrawer').classList.remove('open');
  document.getElementById('popDrawerOverlay').classList.remove('open');
}
document.getElementById('popDrawerClose').addEventListener('click', fecharDrawer);
document.getElementById('popDrawerOverlay').addEventListener('click', fecharDrawer);
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharDrawer(); });

document.querySelectorAll('.pop-row').forEach(row => {
  row.addEventListener('click', () => {
    let op = {};
    try { op = JSON.parse(row.dataset.op || '{}'); } catch (e) { op = {}; }
    abrirDetalheOP(op);
  });
});

function popSetupCollapsible(cardId, headId, toggleId, storageKey) {
  const card = document.getElementById(cardId);
  const head = document.getElementById(headId);
  const toggle = document.getElementById(toggleId);
  if (!card || !head || !toggle) return;
  function setCollapsed(collapsed) {
    card.classList.toggle('collapsed', collapsed);
    localStorage.setItem(storageKey, collapsed ? '1' : '0');
  }
  setCollapsed(localStorage.getItem(storageKey) === '1');
  head.addEventListener('click', () => setCollapsed(!card.classList.contains('collapsed')));
}
popSetupCollapsible('filterCard', 'filterCardHead', 'filterToggleBtn', 'pop_filtros_colapsados');
</script>
</body>
</html>
