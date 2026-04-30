<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Produção ERP Yzidro
//  Lista ordens de produção finalizadas e exibe itens segmentados por setor.
// ══════════════════════════════════════════════════════════════════════════════

function ypEsc($v): string  { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function ypDate(?string $v): string {
    $v = trim((string) $v);
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : $v;
}
function ypSanitDate(?string $v): string {
    $v = trim((string) $v);
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return $dt ? $dt->format('Y-m-d') : '';
}
function ypMoney($v): string { return 'R$ ' . number_format((float) $v, 2, ',', '.'); }
function ypQty($v): string   { return number_format((float) $v, 0, ',', '.'); }

// Classifica produto em 'BAG', 'SACARIA' ou '' pelo nome
function ypSetor(string $nome): string {
    $n = strtoupper($nome);
    if (str_contains($n, 'BIG BAG') || str_contains($n, 'BIGBAG') || preg_match('/\bBAG\b/', $n)) {
        return 'BAG';
    }
    if (str_contains($n, 'SACARIA') || str_contains($n, 'SACO')) {
        return 'SACARIA';
    }
    return 'OUTROS';
}

// ── AJAX: itens de uma OP ────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'itens') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $prodCod = trim((string) ($_GET['prod_codigo'] ?? ''));
        if ($prodCod === '') throw new RuntimeException('PROD_CODIGO não informado.');

        $pg = dbPG();
        if (!$pg) throw new RuntimeException('Sem conexão com o ERP.');

        $sql = "
            SELECT I.PROD_CODIGO
                  ,P.PRO_CODIGO
                  ,P.PRO_CODIGO_OR
                  ,P.PRO_REFERENCIA
                  ,P.PRO_DESCRICAO
                  ,P.PRO_UNIDADE
                  ,CAST(IIF((COALESCE(P.PRO_SEGUNDA_UNIDADE, '') = '')
                           ,P.PRO_UNIDADE
                           ,P.PRO_SEGUNDA_UNIDADE) AS VARCHAR(16)) AS PRO_SEGUNDA_UNIDADE
                  ,P.PRO_TAMANHO
                  ,I.ITE_QTD
                  ,I.ITE_QTD_BAIXA
                  ,I.ITE_DATA
                  ,I.ITE_VALOR_UNIT
                  ,I.ITE_TOTAL
                  ,I.MV_CODIGO
                  ,I.ITE_OBS
              FROM      ITENS_PRODUCAO I
             INNER JOIN PRODUTO        P ON P.PRO_CODIGO  = I.PRO_CODIGO
             INNER JOIN PRODUCAO       V ON V.PROD_CODIGO = I.PROD_CODIGO
             WHERE V.EMP_CODIGO = 1
               AND I.PROD_CODIGO = \$1
               AND COALESCE(I.ITE_ITENS_GERADORES, '') NOT LIKE 'Item Gerado Automaticamente%'
             ORDER BY P.PRO_DESCRICAO, P.PRO_TAMANHO
        ";

        $res = @pg_query_params($pg, $sql, [$prodCod]);
        if (!$res) throw new RuntimeException('Erro ao consultar itens: ' . pg_last_error($pg));

        $itens = [];
        while ($row = pg_fetch_assoc($res)) {
            $row['setor'] = ypSetor($row['pro_descricao'] ?? '');
            $itens[] = $row;
        }
        pg_free_result($res);
        pg_close($pg);

        echo json_encode(['ok' => true, 'itens' => $itens], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$defaultMes = date('Y-m');

$fMes = trim((string) ($_GET['mes'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $fMes)) $fMes = $defaultMes;

// Deriva ini/fim do mês selecionado
$fIni = $fMes . '-01';
$fFim = date('Y-m-t', strtotime($fIni));

$fCliente = trim((string) ($_GET['cliente'] ?? ''));
$fSetor   = in_array($_GET['setor'] ?? '', ['BAG', 'SACARIA', ''], true) ? ($_GET['setor'] ?? '') : '';

// ── Consulta principal (ERP PostgreSQL) ───────────────────────────────────────
$ordens   = [];
$erp_msg  = '';
$erp_ok   = false;

$pg = dbPG();
if ($pg) {
    try {
        $params  = [];
        $extras  = [];

        if ($fIni !== '') {
            $params[] = $fIni;
            $extras[] = 'V.PROD_DATA >= $' . count($params);
        }
        if ($fFim !== '') {
            $params[] = $fFim;
            $extras[] = 'V.PROD_DATA <= $' . count($params);
        }
        if ($fCliente !== '') {
            $params[] = '%' . $fCliente . '%';
            $extras[] = 'C.CLI_NOME ILIKE $' . count($params);
        }

        $extraWhere = $extras ? "\n  AND " . implode("\n  AND ", $extras) : '';

        $sql = "
            SELECT
                V.PROD_CODIGO,
                V.PROD_DATA,
                V.PROD_OBS,
                V.PROD_TIPO,
                V.FINALIZADO,
                V.DATA_ENTREGA,
                V.VEN_COD_PEDIDO,
                C.CLI_CODIGO,
                C.CLI_NOME,
                V.LOTE_CLIENTE,
                V.VENDA_REF,
                V.TIPO_PRODUCAO,
                V.DATA_FINALIZA,
                COALESCE(SUM(CASE WHEN COALESCE(IP.ITE_ITENS_GERADORES,'') NOT LIKE 'Item Gerado Automaticamente%' THEN IP.ITE_QTD       ELSE 0 END), 0) AS total_qtd,
                COALESCE(SUM(CASE WHEN COALESCE(IP.ITE_ITENS_GERADORES,'') NOT LIKE 'Item Gerado Automaticamente%' THEN IP.ITE_QTD_BAIXA ELSE 0 END), 0) AS total_qtd_baixa,
                CAST(
                    CASE
                        WHEN SUM(IP.ITE_QTD) <= SUM(IP.ITE_QTD_FAT) THEN '0'
                        WHEN SUM(COALESCE(IP.ITE_QTD_FAT, 0)) = 0    THEN '2'
                        WHEN SUM(IP.ITE_QTD) > SUM(IP.ITE_QTD_FAT)   THEN '1'
                        ELSE '3'
                    END AS CHAR(1)
                ) AS STATUS_IMPORTADO
            FROM PRODUCAO V
            LEFT JOIN CLIENTES C
                   ON C.CLI_CODIGO = V.CLI_CODIGO
            LEFT JOIN ITENS_PRODUCAO IP
                   ON IP.PROD_CODIGO = V.PROD_CODIGO
            LEFT JOIN OP_ITENS_CONSUMIDOS OIC
                   ON OIC.PROD_CODIGO = V.PROD_CODIGO
            WHERE V.EMP_CODIGO = 1
              AND V.FINALIZADO = 'Y'
              {$extraWhere}
            GROUP BY
                V.PROD_CODIGO,
                V.PROD_DATA,
                V.PROD_TOTAL,
                V.PROD_OBS,
                V.PROD_TIPO,
                V.FINALIZADO,
                V.DATA_ENTREGA,
                V.VEN_COD_PEDIDO,
                C.CLI_CODIGO,
                C.CLI_NOME,
                V.LOTE_CLIENTE,
                V.VENDA_REF,
                V.TIPO_PRODUCAO,
                V.DATA_FINALIZA
            ORDER BY V.PROD_CODIGO DESC
        ";

        $res = @pg_query_params($pg, $sql, $params);
        if (!$res) throw new RuntimeException('Erro na consulta: ' . pg_last_error($pg));

        while ($row = pg_fetch_assoc($res)) {
            $ordens[] = $row;
        }
        pg_free_result($res);
        $erp_ok = true;
    } catch (Throwable $e) {
        $erp_msg = $e->getMessage();
    }
    pg_close($pg);
} else {
    $erp_msg = 'Sem conexão com o ERP Yzidro (PostgreSQL).';
}

$totalOrdens   = count($ordens);
$totalQtd      = array_reduce($ordens, fn($c, $r) => $c + (float) ($r['total_qtd']       ?? 0), 0.0);
$totalQtdBaixa = array_reduce($ordens, fn($c, $r) => $c + (float) ($r['total_qtd_baixa'] ?? 0), 0.0);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Produção ERP Yzidro | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ── Filtros ────────────────────────────────────────── */
.filter-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    margin-bottom: 16px;
}
.filter-grid {
    display: grid;
    grid-template-columns: 160px minmax(200px,1fr) 160px;
    gap: 16px;
    align-items: end;
}
.filter-field { display: flex; flex-direction: column; gap: 6px; }
.filter-field label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
}
.filter-field input,
.filter-field select { height: 36px; padding: 0 10px; font-size: 12.5px; }
.filter-actions { margin-top: 14px; display: flex; gap: 10px; align-items: center; }

/* ── KPIs ─────────────────────────────────────────── */
.kpi-row-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

/* ── Tabela ──────────────────────────────────────── */
.op-table     { width: 100%; border-collapse: collapse; font-size: 13px; }
.op-table th  {
    padding: 9px 14px; text-align: left; font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted);
    border-bottom: 1px solid var(--border); white-space: nowrap;
}
.op-table td  { padding: 10px 14px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
.op-table tr:last-child td { border-bottom: none; }
.op-table tbody tr:hover td { background: rgba(255,255,255,.025); }
.col-r { text-align: right; }
.col-mono { font-family: monospace; font-size: 12px; }
.col-nowrap { white-space: nowrap; }

/* Botão expandir */
.op-toggle {
    width: 28px; height: 28px; border-radius: 7px;
    border: 1px solid var(--border); background: transparent;
    color: var(--text-muted); cursor: pointer; font-size: 17px; line-height: 1;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
}
.op-toggle:hover { background: var(--card-hover); color: var(--text-primary); }
.op-toggle[aria-expanded="true"] {
    background: rgba(36,83,212,.18); border-color: rgba(79,123,255,.35); color: #7db3ff;
}

/* Status importado */
.st-badge {
    display: inline-flex; align-items: center; padding: 3px 9px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap;
}
.st-0 { background: rgba(0,201,167,.12);   color: #00c9a7; }
.st-1 { background: rgba(245,158,11,.12);  color: #f59e0b; }
.st-2 { background: rgba(239,68,68,.12);   color: #ef4444; }
.st-3 { background: rgba(255,255,255,.06); color: var(--text-muted); }

/* Linha de detalhe */
.op-detail-row { display: none; }
.op-detail-row.open { display: table-row; }
.op-detail-cell {
    padding: 0 !important;
    background: rgba(0,0,0,.12) !important;
    border-bottom: 1px solid var(--border);
}
.op-detail-box { padding: 16px 20px 18px; }

/* Setores dentro do detalhe */
.setor-section { margin-bottom: 18px; }
.setor-section:last-child { margin-bottom: 0; }
.setor-title {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .07em; color: var(--text-muted);
    margin-bottom: 8px; padding-bottom: 5px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 8px;
}
.setor-bag  { color: #7db3ff; }
.setor-sac  { color: #00c9a7; }
.setor-out  { color: var(--text-muted); }

/* Sub-tabela de itens */
.sub-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.sub-table th {
    padding: 7px 12px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-muted); border-bottom: 1px solid var(--border);
    background: rgba(0,0,0,.12); white-space: nowrap;
}
.sub-table td {
    padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,.04);
    color: var(--text-primary);
}
.sub-table tr:last-child td { border-bottom: none; }
.sub-r { text-align: right; }
.sub-muted { color: var(--text-muted); font-size: 11.5px; }

.op-loading, .op-empty { padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
.op-error {
    padding: 12px 16px; border-radius: 8px; font-size: 13px;
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2); color: #ef4444;
}

@media (max-width: 1024px) { .filter-grid { grid-template-columns: 1fr 1fr; } .kpi-row-3 { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px)  { .filter-grid { grid-template-columns: 1fr; }    .kpi-row-3 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Produção ERP Yzidro</h1>
          <p>Ordens finalizadas · itens segmentados por setor</p>
        </div>
      </div>
    </header>

    <div class="content">

      <?php if ($erp_msg !== ''): ?>
      <div class="op-error" style="margin-bottom:16px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= ypEsc($erp_msg) ?>
      </div>
      <?php endif; ?>

      <!-- Filtros -->
      <div class="filter-card">
        <form method="GET" action="/yzidro/producao">
          <div class="filter-grid">
            <div class="filter-field">
              <label>Mês</label>
              <input type="month" name="mes" value="<?= ypEsc($fMes) ?>">
            </div>
            <div class="filter-field">
              <label>Cliente</label>
              <input type="text" name="cliente" value="<?= ypEsc($fCliente) ?>" placeholder="Nome do cliente">
            </div>
            <div class="filter-field">
              <label>Setor</label>
              <select name="setor">
                <option value=""        <?= $fSetor === ''        ? 'selected' : '' ?>>Todos</option>
                <option value="BAG"     <?= $fSetor === 'BAG'     ? 'selected' : '' ?>>Big Bag</option>
                <option value="SACARIA" <?= $fSetor === 'SACARIA' ? 'selected' : '' ?>>Sacaria</option>
              </select>
            </div>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-primary" style="height:36px;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Filtrar
            </button>
            <a href="/yzidro/producao" class="btn-secondary" style="height:36px;text-decoration:none;display:inline-flex;align-items:center;">Limpar</a>
          </div>
        </form>
      </div>

      <!-- KPIs -->
      <div class="kpi-row-3">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Ordens finalizadas</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalOrdens, 0, ',', '.') ?></div>
          <div class="kpi-footer"><?= ypEsc(date('m/Y', strtotime($fIni))) ?></div>
        </div>
        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Qtd produzida (baixa)</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalQtdBaixa, 0, ',', '.') ?></div>
          <div class="kpi-footer">Planejada: <?= number_format($totalQtd, 0, ',', '.') ?></div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-header">
            <span class="kpi-label">ERP</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="font-size:14px;letter-spacing:0"><?= $erp_ok ? 'Conectado' : 'Offline' ?></div>
          <div class="kpi-footer">PostgreSQL Yzidro</div>
        </div>
      </div>

      <!-- Tabela de ordens -->
      <div class="panel-table" style="padding-bottom:4px;">
        <div class="panel-header">
          <span class="panel-title">Ordens de Produção</span>
          <span style="font-size:12px;color:var(--text-muted);"><?= number_format($totalOrdens, 0, ',', '.') ?> ordem<?= $totalOrdens !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($ordens)): ?>
        <div class="op-empty">Nenhuma ordem finalizada encontrada no período selecionado.</div>
        <?php else: ?>
        <div style="overflow-x:auto;width:100%;">
          <table class="op-table" style="table-layout:auto;min-width:860px;">
            <thead>
              <tr>
                <th style="width:44px;"></th>
                <th style="width:70px;">OP</th>
                <th style="width:100%;">Cliente</th>
                <th style="white-space:nowrap;">Data OP</th>
                <th style="white-space:nowrap;">Finalizada</th>
                <th style="white-space:nowrap;">Pedido ERP</th>
                <th style="white-space:nowrap;">Lote</th>
                <th class="col-r" style="white-space:nowrap;">Qtd plan.</th>
                <th class="col-r" style="white-space:nowrap;">Qtd prod.</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ordens as $op):
                  $prodCod   = (string) ($op['prod_codigo'] ?? '');
                  $detailId  = 'op_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $prodCod);
                  $stCode    = (string) ($op['status_importado'] ?? '3');
                  $stLabels  = ['0' => 'Faturado', '1' => 'Parcial', '2' => 'Não faturado', '3' => '—'];
                  $stLabel   = $stLabels[$stCode] ?? '—';
              ?>
              <tr>
                <td style="width:44px;text-align:center;">
                  <button
                    class="op-toggle"
                    data-prod="<?= ypEsc($prodCod) ?>"
                    data-detail="<?= ypEsc($detailId) ?>"
                    aria-expanded="false"
                    title="Ver itens"
                  >+</button>
                </td>
                <td class="col-mono"><strong><?= ypEsc($prodCod) ?></strong></td>
                <td><?= ypEsc(mb_substr((string)($op['cli_nome'] ?? '—'), 0, 40)) ?></td>
                <td class="col-nowrap"><?= ypDate($op['prod_data'] ?? '') ?></td>
                <td class="col-nowrap"><?= ypDate($op['data_finaliza'] ?? '') ?></td>
                <td><?= ypEsc($op['ven_cod_pedido'] ?? '—') ?></td>
                <td><?= ypEsc($op['lote_cliente'] ?? '—') ?></td>
                <td class="col-r col-nowrap"><?= ypQty((float)($op['total_qtd'] ?? 0)) ?></td>
                <td class="col-r col-nowrap"><?= ypQty((float)($op['total_qtd_baixa'] ?? 0)) ?></td>
              </tr>
              <tr id="<?= ypEsc($detailId) ?>" class="op-detail-row">
                <td colspan="9" class="op-detail-cell">
                  <div class="op-detail-box">
                    <div class="op-detail-content">
                      <div class="op-loading">Carregando itens…</div>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<script>
function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function qtyBR(v) {
    return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}
function moneyBR(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function setorClass(setor) {
    if (setor === 'BAG')     return 'setor-bag';
    if (setor === 'SACARIA') return 'setor-sac';
    return 'setor-out';
}
function setorLabel(setor) {
    if (setor === 'BAG')     return 'Big Bag';
    if (setor === 'SACARIA') return 'Sacaria';
    return 'Outros';
}

function buildSetorTable(itens) {
    if (!itens.length) return '<div class="op-empty">Nenhum item neste setor.</div>';
    const rows = itens.map(i => {
        const pct = i.ite_qtd > 0 ? Math.min(100, Math.round((i.ite_qtd_baixa / i.ite_qtd) * 100)) : 0;
        const barColor = pct >= 100 ? 'var(--teal)' : pct > 0 ? '#f59e0b' : 'rgba(255,255,255,.12)';
        return `
        <tr>
            <td><strong>${escHtml(i.pro_descricao || '—')}</strong>
                ${i.pro_tamanho ? `<span class="sub-muted"> · ${escHtml(i.pro_tamanho)}</span>` : ''}
                ${i.pro_referencia ? `<br><span class="sub-muted">Ref: ${escHtml(i.pro_referencia)}</span>` : ''}
            </td>
            <td class="sub-r">${qtyBR(i.ite_qtd)} <span class="sub-muted">${escHtml(i.pro_unidade || '')}</span></td>
            <td class="sub-r"><strong>${qtyBR(i.ite_qtd_baixa)}</strong> <span class="sub-muted">${escHtml(i.pro_segunda_unidade || i.pro_unidade || '')}</span></td>
            <td style="min-width:100px;">
                <div style="height:4px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden;">
                    <div style="height:100%;width:${pct}%;background:${barColor};border-radius:2px;transition:width .4s;"></div>
                </div>
                <span class="sub-muted" style="font-size:10px;">${pct}%</span>
            </td>
            <td class="sub-muted">${escHtml(i.ite_obs || '')}</td>
        </tr>
    `}).join('');
    return `
        <table class="sub-table">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th class="sub-r">Qtd planejada</th>
                    <th class="sub-r">Qtd produzida</th>
                    <th>Progresso</th>
                    <th>Obs.</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
}

function buildDetail(itens) {
    if (!Array.isArray(itens) || !itens.length) {
        return '<div class="op-empty">Nenhum item encontrado para esta ordem.</div>';
    }

    const setores = ['BAG', 'SACARIA', 'OUTROS'];
    let html = '';

    for (const setor of setores) {
        const grupo = itens.filter(i => i.setor === setor);
        if (!grupo.length) continue;
        const cls = setorClass(setor);
        const lbl = setorLabel(setor);
        html += `
            <div class="setor-section">
                <div class="setor-title">
                    <span class="${cls}">${escHtml(lbl)}</span>
                    <span style="color:var(--text-muted);font-weight:400;">${grupo.length} item${grupo.length !== 1 ? 's' : ''}</span>
                </div>
                ${buildSetorTable(grupo)}
            </div>
        `;
    }
    return html || '<div class="op-empty">Nenhum item encontrado.</div>';
}

async function toggleOp(btn) {
    const prodCod  = btn.dataset.prod;
    const detailId = btn.dataset.detail;
    const row      = document.getElementById(detailId);
    if (!row) return;

    const willOpen = !row.classList.contains('open');

    if (!willOpen) {
        row.classList.remove('open');
        btn.setAttribute('aria-expanded','false');
        btn.textContent = '+';
        return;
    }

    row.classList.add('open');
    btn.setAttribute('aria-expanded','true');
    btn.textContent = '−';

    const container = row.querySelector('.op-detail-content');
    if (container.dataset.loaded === '1') return;

    container.innerHTML = '<div class="op-loading">Carregando itens…</div>';

    try {
        const res  = await fetch(`/yzidro/producao?ajax=itens&prod_codigo=${encodeURIComponent(prodCod)}`);
        const data = await res.json();
        if (!data.ok) throw new Error(data.msg || 'Falha ao carregar itens.');
        container.innerHTML = buildDetail(data.itens);
        container.dataset.loaded = '1';
    } catch (err) {
        container.innerHTML = `<div class="op-error">${escHtml(err.message)}</div>`;
    }
}

document.querySelectorAll('.op-toggle').forEach(btn => {
    btn.addEventListener('click', () => toggleOp(btn));
});
</script>
</body>
</html>
