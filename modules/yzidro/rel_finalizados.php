<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Relatório de Pedidos Finalizados (Sacaria × Big Bag)
//  Fonte: MySQL — VENDAS, ITENS_VENDAS, PRODUTOS
// ══════════════════════════════════════════════════════════════════════════════

function rfEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rfDate(?string $v): string {
    $v = trim((string)$v);
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : $v;
}
function rfNum(int $v): string    { return number_format($v, 0, ',', '.'); }
function rfMoney(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function rfSanitDate(?string $v): string {
    $v = trim((string)$v);
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return $dt ? $dt->format('Y-m-d') : '';
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$ini = rfSanitDate($_GET['ini'] ?? '') ?: date('Y-m-01');
$fim = rfSanitDate($_GET['fim'] ?? '') ?: date('Y-m-d');
$tipo_filtro = in_array($_GET['tipo'] ?? '', ['SAC','BAG',''], true) ? ($_GET['tipo'] ?? '') : '';

// ── Consulta principal ────────────────────────────────────────────────────────
$pedidos   = [];
$kpi_ped   = 0;
$kpi_sac   = 0;
$kpi_bag   = 0;
$kpi_total = 0.0;
$db_error  = '';

try {
    $pdo = dbPDO();

    // Pedidos onde TODOS os itens estão finalizados, dentro do período
    $sql = "
        SELECT
            v.ven_codigo,
            v.ven_codigo_yzidro                                    AS pedido,
            COALESCE(NULLIF(v.ven_fantasia,''), v.ven_cliente)     AS cliente,
            v.ven_entrega,
            v.ven_emissao,
            COALESCE(v.ven_total, 0)                               AS total,
            SUM(CASE WHEN p.pro_tipo = 'SACARIA' THEN iv.iv_qtde ELSE 0 END) AS qtd_sac,
            SUM(CASE WHEN p.pro_tipo = 'BAG'     THEN iv.iv_qtde ELSE 0 END) AS qtd_bag,
            SUM(CASE WHEN p.pro_tipo NOT IN ('SACARIA','BAG') OR p.pro_tipo IS NULL
                     THEN iv.iv_qtde ELSE 0 END)                   AS qtd_outro,
            SUM(iv.iv_qtde)                                        AS qtd_total
        FROM VENDAS v
        JOIN ITENS_VENDAS iv ON iv.ven_codigo = v.ven_codigo
        JOIN PRODUTOS p      ON p.pro_codigo  = iv.pro_codigo
        WHERE v.ven_entrega BETWEEN :ini AND :fim
          AND NOT EXISTS (
              SELECT 1 FROM ITENS_VENDAS x
              WHERE x.ven_codigo = v.ven_codigo
                AND x.iv_status <> 'Finalizado'
          )
    ";

    $params = [':ini' => $ini, ':fim' => $fim];

    if ($tipo_filtro === 'SAC') {
        $sql .= " HAVING qtd_sac > 0";
    } elseif ($tipo_filtro === 'BAG') {
        $sql .= " HAVING qtd_bag > 0";
    }

    $sql .= "
        GROUP BY v.ven_codigo, v.ven_codigo_yzidro, v.ven_fantasia,
                 v.ven_cliente, v.ven_entrega, v.ven_emissao, v.ven_total
        ORDER BY v.ven_entrega DESC, v.ven_codigo_yzidro DESC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pedidos as $p) {
        $kpi_ped++;
        $kpi_sac   += (int)$p['qtd_sac'];
        $kpi_bag   += (int)$p['qtd_bag'];
        $kpi_total += (float)$p['total'];
    }
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}

$ini_fmt = date('d/m/Y', strtotime($ini));
$fim_fmt = date('d/m/Y', strtotime($fim));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório de Finalizados | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ── Filtros ─────────────────────────────────────────── */
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
    padding: 16px 20px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
}
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-group label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; }
.filter-group input,
.filter-group select {
    height: 36px;
    padding: 0 10px;
    background: var(--input-bg, rgba(255,255,255,.05));
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 13px;
    min-width: 130px;
}
.filter-group input:focus,
.filter-group select:focus { outline: none; border-color: rgba(45,106,255,.5); }
.filter-bar .btn-primary { height: 36px; align-self: flex-end; }

/* ── KPIs ────────────────────────────────────────────── */
.kpi-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
@media(max-width:900px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } }
@media(max-width:520px) { .kpi-row { grid-template-columns: 1fr; } }

/* ── Tabela ──────────────────────────────────────────── */
.rel-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rel-table th {
    padding: 9px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.rel-table th.num, .rel-table td.num { text-align: right; }
.rel-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    color: var(--text-primary);
    vertical-align: middle;
}
.rel-table tr:last-child td { border-bottom: none; }
.rel-table tbody tr:hover td { background: rgba(255,255,255,.025); }

.rel-table tfoot td {
    padding: 10px 14px;
    font-weight: 700;
    border-top: 1px solid var(--border);
    color: var(--text-primary);
}

.badge-sac  { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; background:rgba(0,201,167,.12); color:#00c9a7; }
.badge-bag  { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; background:rgba(45,106,255,.12); color:#7db3ff; }
.badge-mix  { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; background:rgba(245,158,11,.12); color:#f59e0b; }

.zero { color: var(--text-muted); }

.empty-state { padding: 48px 24px; text-align: center; color: var(--text-muted); font-size: 13px; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Pedidos Finalizados</h1>
          <p>Sacaria × Big Bag — por data de entrega</p>
        </div>
      </div>
    </header>

    <div class="content">

      <?php if ($db_error): ?>
      <div class="alert alert-err">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Erro ao consultar banco: <?= rfEsc($db_error) ?>
      </div>
      <?php endif; ?>

      <!-- Filtros -->
      <form method="GET" action="/yzidro/finalizados">
        <div class="filter-bar">
          <div class="filter-group">
            <label>De</label>
            <input type="date" name="ini" value="<?= rfEsc($ini) ?>">
          </div>
          <div class="filter-group">
            <label>Até</label>
            <input type="date" name="fim" value="<?= rfEsc($fim) ?>">
          </div>
          <div class="filter-group">
            <label>Tipo</label>
            <select name="tipo">
              <option value=""  <?= $tipo_filtro === ''    ? 'selected' : '' ?>>Todos</option>
              <option value="SAC" <?= $tipo_filtro === 'SAC' ? 'selected' : '' ?>>Sacaria</option>
              <option value="BAG" <?= $tipo_filtro === 'BAG' ? 'selected' : '' ?>>Big Bag</option>
            </select>
          </div>
          <button type="submit" class="btn-primary">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Filtrar
          </button>
        </div>
      </form>

      <!-- KPIs -->
      <div class="kpi-row">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Pedidos finalizados</span>
            <div class="kpi-icon">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
              </svg>
            </div>
          </div>
          <div class="kpi-value"><?= rfNum($kpi_ped) ?></div>
          <div class="kpi-footer"><?= rfEsc($ini_fmt) ?> – <?= rfEsc($fim_fmt) ?></div>
        </div>

        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Sacaria</span>
            <div class="kpi-icon">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
              </svg>
            </div>
          </div>
          <div class="kpi-value"><?= rfNum($kpi_sac) ?></div>
          <div class="kpi-footer">unidades finalizadas</div>
        </div>

        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Big Bag</span>
            <div class="kpi-icon">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                <circle cx="12" cy="10" r="3"/>
              </svg>
            </div>
          </div>
          <div class="kpi-value"><?= rfNum($kpi_bag) ?></div>
          <div class="kpi-footer">unidades finalizadas</div>
        </div>

        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Valor total</span>
            <div class="kpi-icon">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
              </svg>
            </div>
          </div>
          <div class="kpi-value" style="font-size:18px;letter-spacing:-0.5px;"><?= rfMoney($kpi_total) ?></div>
          <div class="kpi-footer">soma dos pedidos</div>
        </div>
      </div>

      <!-- Tabela -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Detalhamento por pedido</span>
          <span style="font-size:12px;color:var(--text-muted);"><?= rfNum($kpi_ped) ?> pedido<?= $kpi_ped !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($pedidos)): ?>
        <div class="empty-state">Nenhum pedido finalizado no período selecionado.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="rel-table">
            <thead>
              <tr>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Entrega</th>
                <th>Emissão</th>
                <th class="num">Sacaria (un)</th>
                <th class="num">Big Bag (un)</th>
                <th class="num">Total (un)</th>
                <th class="num">Tipo</th>
                <th class="num">Valor</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($pedidos as $p):
                $sac  = (int)$p['qtd_sac'];
                $bag  = (int)$p['qtd_bag'];
                $out  = (int)$p['qtd_outro'];
                if ($sac > 0 && $bag > 0) $badge = '<span class="badge-mix">Misto</span>';
                elseif ($sac > 0)          $badge = '<span class="badge-sac">Sacaria</span>';
                elseif ($bag > 0)          $badge = '<span class="badge-bag">Big Bag</span>';
                else                       $badge = '<span class="badge-mix">Outro</span>';
            ?>
              <tr>
                <td><strong><?= rfEsc($p['pedido']) ?></strong></td>
                <td><?= rfEsc(mb_substr($p['cliente'], 0, 40)) ?><?= mb_strlen($p['cliente']) > 40 ? '…' : '' ?></td>
                <td><?= rfDate($p['ven_entrega']) ?></td>
                <td><?= rfDate($p['ven_emissao']) ?></td>
                <td class="num"><?= $sac > 0 ? rfNum($sac) : '<span class="zero">—</span>' ?></td>
                <td class="num"><?= $bag > 0 ? rfNum($bag) : '<span class="zero">—</span>' ?></td>
                <td class="num"><strong><?= rfNum((int)$p['qtd_total']) ?></strong></td>
                <td class="num"><?= $badge ?></td>
                <td class="num"><?= $p['total'] > 0 ? rfMoney((float)$p['total']) : '<span class="zero">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4">Total</td>
                <td class="num"><?= rfNum($kpi_sac) ?></td>
                <td class="num"><?= rfNum($kpi_bag) ?></td>
                <td class="num"><?= rfNum($kpi_sac + $kpi_bag) ?></td>
                <td></td>
                <td class="num"><?= rfMoney($kpi_total) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
</body>
</html>
