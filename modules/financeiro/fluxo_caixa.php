<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/fin_schema.php';

$pdo = dbPDO();
finEnsureSchema($pdo);

// ── Período (padrão: últimos 6 meses + próximos 3) ───────────────────────────
$mesAtual  = date('Y-m');
$mesInicio = $_GET['de'] ?? date('Y-m', strtotime('-5 months'));
$mesFim    = $_GET['ate'] ?? date('Y-m', strtotime('+2 months'));

// Garante ordem correta
if ($mesInicio > $mesFim) [$mesInicio, $mesFim] = [$mesFim, $mesInicio];

// Gera lista de meses no intervalo
$meses = [];
$cur = $mesInicio;
while ($cur <= $mesFim) {
    $meses[] = $cur;
    $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
}

// ── Receitas por mês (contas pagas) ──────────────────────────────────────────
$receitasMes = [];
try {
    $rows = $pdo->prepare("SELECT DATE_FORMAT(data_vencimento,'%Y-%m') AS mes, SUM(valor) AS total
        FROM fin_contas_receber
        WHERE status = 'PAGO' AND DATE_FORMAT(data_vencimento,'%Y-%m') BETWEEN ? AND ?
        GROUP BY mes ORDER BY mes");
    $rows->execute([$mesInicio, $mesFim]);
    foreach ($rows->fetchAll() as $r) $receitasMes[$r['mes']] = (float)$r['total'];
} catch (Throwable) {}

// ── Despesas por mês (contas pagas) ──────────────────────────────────────────
$despesasMes = [];
try {
    $rows = $pdo->prepare("SELECT DATE_FORMAT(data_vencimento,'%Y-%m') AS mes, SUM(valor) AS total
        FROM fin_contas_pagar
        WHERE status = 'PAGO' AND DATE_FORMAT(data_vencimento,'%Y-%m') BETWEEN ? AND ?
        GROUP BY mes ORDER BY mes");
    $rows->execute([$mesInicio, $mesFim]);
    foreach ($rows->fetchAll() as $r) $despesasMes[$r['mes']] = (float)$r['total'];
} catch (Throwable) {}

// ── Previsto por mês (em aberto) ─────────────────────────────────────────────
$previstaReceber = [];
$previstaPagar   = [];
try {
    $rows = $pdo->prepare("SELECT DATE_FORMAT(data_vencimento,'%Y-%m') AS mes, SUM(valor-valor_pago) AS total
        FROM fin_contas_receber
        WHERE status IN ('ABERTO','PARCIAL','VENCIDO') AND DATE_FORMAT(data_vencimento,'%Y-%m') BETWEEN ? AND ?
        GROUP BY mes");
    $rows->execute([$mesInicio, $mesFim]);
    foreach ($rows->fetchAll() as $r) $previstaReceber[$r['mes']] = (float)$r['total'];

    $rows = $pdo->prepare("SELECT DATE_FORMAT(data_vencimento,'%Y-%m') AS mes, SUM(valor-valor_pago) AS total
        FROM fin_contas_pagar
        WHERE status IN ('ABERTO','PARCIAL','VENCIDO') AND DATE_FORMAT(data_vencimento,'%Y-%m') BETWEEN ? AND ?
        GROUP BY mes");
    $rows->execute([$mesInicio, $mesFim]);
    foreach ($rows->fetchAll() as $r) $previstaPagar[$r['mes']] = (float)$r['total'];
} catch (Throwable) {}

// ── Totais gerais ─────────────────────────────────────────────────────────────
$totalReceitas  = array_sum($receitasMes);
$totalDespesas  = array_sum($despesasMes);
$totalPrevRec   = array_sum($previstaReceber);
$totalPrevPag   = array_sum($previstaPagar);

// Valor máximo para escala das barras
$maxVal = 1.0;
foreach ($meses as $m) {
    $maxVal = max($maxVal,
        $receitasMes[$m]   ?? 0,
        $despesasMes[$m]   ?? 0,
        $previstaReceber[$m] ?? 0,
        $previstaPagar[$m]   ?? 0
    );
}

$nomeMes = ['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun',
            '07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fluxo de Caixa | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
.kpi-card { background:rgba(255,255,255,.025); border:1px solid var(--border); border-radius:16px; padding:20px 22px; display:flex; flex-direction:column; gap:5px; }
.kpi-card-val { font-size:22px; font-weight:800; }
.kpi-card-lbl { font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
.kpi-card-sub { font-size:11px; color:var(--text-muted); }

.period-form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:16px 18px; border-bottom:1px solid var(--border); }
.period-form .field { min-width:140px; }
.period-form .field label { font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px; }
.period-form input { height:36px; font-size:12px; }

/* Gráfico de barras puro CSS */
.chart-wrap { padding:20px; overflow-x:auto; }
.chart { display:flex; align-items:flex-end; gap:8px; min-width:max-content; height:200px; padding-bottom:0; }
.chart-col { display:flex; flex-direction:column; align-items:center; gap:4px; width:80px; height:100%; justify-content:flex-end; }
.chart-bars { display:flex; gap:3px; align-items:flex-end; height:170px; }
.chart-bar { width:18px; border-radius:4px 4px 0 0; min-height:2px; transition:opacity .2s; }
.chart-bar:hover { opacity:.75; }
.bar-rec  { background:linear-gradient(180deg,#10b981,#059669); }
.bar-desp { background:linear-gradient(180deg,#ef4444,#dc2626); }
.bar-prev-rec  { background:rgba(16,185,129,.3); border:1px solid rgba(16,185,129,.5); }
.bar-prev-pag  { background:rgba(239,68,68,.2);  border:1px solid rgba(239,68,68,.4); }
.chart-label { font-size:11px; color:var(--text-muted); text-align:center; white-space:nowrap; }
.chart-saldo { font-size:10px; font-weight:700; text-align:center; }

.legend { display:flex; flex-wrap:wrap; gap:14px; padding:0 20px 16px; border-bottom:1px solid var(--border); }
.legend-item { display:flex; align-items:center; gap:6px; font-size:11px; color:var(--text-muted); }
.legend-dot { width:10px; height:10px; border-radius:3px; }

/* Tabela */
.fin-tbl { width:100%; border-collapse:collapse; }
.fin-tbl th { padding:9px 14px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); background:rgba(0,0,0,.1); }
.fin-tbl td { padding:11px 14px; font-size:13px; border-bottom:1px solid var(--border); color:var(--text-primary); }
.fin-tbl .mes-atual td { background:rgba(45,106,255,.04); }
.fin-tbl tfoot td { font-weight:800; font-size:13px; background:rgba(0,0,0,.1); }
.pos { color:#10b981; } .neg { color:var(--red); }

@media(max-width:900px) { .kpi-row { grid-template-columns:1fr 1fr; } }
@media(max-width:500px) { .kpi-row { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">

<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Fluxo de Caixa</h1>
      <p>Receitas e despesas realizadas e previstas por mês.</p>
    </div>
  </div>
</header>

<div class="content">

  <!-- KPIs -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-card-lbl">Recebido (período)</div>
      <div class="kpi-card-val" style="color:#10b981;"><?= finMoney($totalReceitas) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-card-lbl">Pago (período)</div>
      <div class="kpi-card-val" style="color:var(--red);"><?= finMoney($totalDespesas) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-card-lbl">Saldo realizado</div>
      <div class="kpi-card-val <?= $totalReceitas-$totalDespesas >= 0 ? 'pos' : 'neg' ?>"><?= finMoney($totalReceitas - $totalDespesas) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-card-lbl">Saldo previsto</div>
      <div class="kpi-card-val <?= $totalPrevRec-$totalPrevPag >= 0 ? 'pos' : 'neg' ?>"><?= finMoney($totalPrevRec - $totalPrevPag) ?></div>
      <div class="kpi-card-sub">em aberto/vencido</div>
    </div>
  </div>

  <!-- Gráfico -->
  <div class="panel" style="margin-bottom:20px;">
    <div class="panel-header"><span class="panel-title">Visão mensal</span></div>

    <form method="GET" action="">
      <div class="period-form">
        <div class="field"><label>De</label><input type="month" name="de" value="<?= finH($mesInicio) ?>"></div>
        <div class="field"><label>Até</label><input type="month" name="ate" value="<?= finH($mesFim) ?>"></div>
        <button type="submit" class="btn-primary" style="height:36px;align-self:flex-end;">Aplicar</button>
      </div>
    </form>

    <div class="chart-wrap">
      <div class="chart">
        <?php foreach ($meses as $m):
          $rec  = $receitasMes[$m]   ?? 0;
          $desp = $despesasMes[$m]   ?? 0;
          $pRec = $previstaReceber[$m] ?? 0;
          $pPag = $previstaPagar[$m]   ?? 0;
          $saldo = $rec - $desp;
          $hRec  = $maxVal > 0 ? round(($rec  / $maxVal) * 160) : 0;
          $hDesp = $maxVal > 0 ? round(($desp / $maxVal) * 160) : 0;
          $hPRec = $maxVal > 0 ? round(($pRec / $maxVal) * 160) : 0;
          $hPPag = $maxVal > 0 ? round(($pPag / $maxVal) * 160) : 0;
          [$ano, $mo] = explode('-', $m);
          $lbl = ($nomeMes[$mo] ?? $mo) . '/' . substr($ano,2);
          $isAtual = $m === $mesAtual;
        ?>
        <div class="chart-col" title="<?= $lbl ?>&#10;Recebido: <?= finMoney($rec) ?>&#10;Pago: <?= finMoney($desp) ?>&#10;Saldo: <?= finMoney($saldo) ?>">
          <div class="chart-bars">
            <?php if ($hRec  > 0): ?><div class="chart-bar bar-rec"   style="height:<?= $hRec ?>px;"  title="Recebido: <?= finMoney($rec) ?>"></div><?php endif; ?>
            <?php if ($hDesp > 0): ?><div class="chart-bar bar-desp"  style="height:<?= $hDesp ?>px;" title="Pago: <?= finMoney($desp) ?>"></div><?php endif; ?>
            <?php if ($hPRec > 0): ?><div class="chart-bar bar-prev-rec" style="height:<?= $hPRec ?>px;" title="A receber: <?= finMoney($pRec) ?>"></div><?php endif; ?>
            <?php if ($hPPag > 0): ?><div class="chart-bar bar-prev-pag" style="height:<?= $hPPag ?>px;" title="A pagar: <?= finMoney($pPag) ?>"></div><?php endif; ?>
          </div>
          <div class="chart-label" style="<?= $isAtual ? 'color:var(--text-primary);font-weight:700;' : '' ?>"><?= $lbl ?></div>
          <?php if ($rec > 0 || $desp > 0): ?>
          <div class="chart-saldo <?= $saldo >= 0 ? 'pos' : 'neg' ?>"><?= $saldo >= 0 ? '+' : '' ?><?= number_format($saldo/1000,1) ?>k</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="legend">
      <div class="legend-item"><div class="legend-dot" style="background:#10b981;"></div> Recebido</div>
      <div class="legend-item"><div class="legend-dot" style="background:var(--red);"></div> Pago</div>
      <div class="legend-item"><div class="legend-dot" style="background:rgba(16,185,129,.35);border:1px solid rgba(16,185,129,.6);"></div> A receber (previsto)</div>
      <div class="legend-item"><div class="legend-dot" style="background:rgba(239,68,68,.25);border:1px solid rgba(239,68,68,.5);"></div> A pagar (previsto)</div>
    </div>
  </div>

  <!-- Tabela detalhada -->
  <div class="panel">
    <div class="panel-header"><span class="panel-title">Detalhe por mês</span></div>
    <div style="overflow-x:auto;">
      <table class="fin-tbl">
        <thead>
          <tr>
            <th>Mês</th>
            <th style="text-align:right;">Recebido</th>
            <th style="text-align:right;">Pago</th>
            <th style="text-align:right;">Saldo realizado</th>
            <th style="text-align:right;">A receber</th>
            <th style="text-align:right;">A pagar</th>
            <th style="text-align:right;">Saldo previsto</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $acumRecebido = $acumPago = 0.0;
        foreach ($meses as $m):
          [$ano, $mo] = explode('-', $m);
          $rec  = $receitasMes[$m]    ?? 0;
          $desp = $despesasMes[$m]    ?? 0;
          $pRec = $previstaReceber[$m] ?? 0;
          $pPag = $previstaPagar[$m]   ?? 0;
          $saldoReal = $rec - $desp;
          $saldoPrev = $pRec - $pPag;
          $acumRecebido += $rec;
          $acumPago     += $desp;
        ?>
          <tr class="<?= $m === $mesAtual ? 'mes-atual' : '' ?>">
            <td style="font-weight:<?= $m === $mesAtual ? '700' : '400' ?>;">
              <?= ($nomeMes[$mo] ?? $mo) ?> <?= $ano ?>
              <?php if ($m === $mesAtual): ?> <span style="font-size:10px;color:#7db3ff;margin-left:4px;">atual</span><?php endif; ?>
            </td>
            <td style="text-align:right;color:#10b981;"><?= $rec > 0 ? finMoney($rec) : '—' ?></td>
            <td style="text-align:right;color:var(--red);"><?= $desp > 0 ? finMoney($desp) : '—' ?></td>
            <td style="text-align:right;font-weight:700;" class="<?= $saldoReal >= 0 ? 'pos' : 'neg' ?>"><?= ($rec > 0 || $desp > 0) ? finMoney($saldoReal) : '—' ?></td>
            <td style="text-align:right;color:rgba(16,185,129,.7);"><?= $pRec > 0 ? finMoney($pRec) : '—' ?></td>
            <td style="text-align:right;color:rgba(239,68,68,.7);"><?= $pPag > 0 ? finMoney($pPag) : '—' ?></td>
            <td style="text-align:right;" class="<?= $saldoPrev >= 0 ? 'pos' : 'neg' ?>"><?= ($pRec > 0 || $pPag > 0) ? finMoney($saldoPrev) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td>
            <td style="text-align:right;color:#10b981;"><?= finMoney($totalReceitas) ?></td>
            <td style="text-align:right;color:var(--red);"><?= finMoney($totalDespesas) ?></td>
            <td style="text-align:right;" class="<?= $totalReceitas-$totalDespesas >= 0 ? 'pos' : 'neg' ?>"><?= finMoney($totalReceitas - $totalDespesas) ?></td>
            <td style="text-align:right;color:rgba(16,185,129,.7);"><?= finMoney($totalPrevRec) ?></td>
            <td style="text-align:right;color:rgba(239,68,68,.7);"><?= finMoney($totalPrevPag) ?></td>
            <td style="text-align:right;" class="<?= $totalPrevRec-$totalPrevPag >= 0 ? 'pos' : 'neg' ?>"><?= finMoney($totalPrevRec - $totalPrevPag) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

</div>
</div>
</div>
</body>
</html>
