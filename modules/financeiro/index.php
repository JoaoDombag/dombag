<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/fin_schema.php';

$pdo = dbPDO();
finEnsureSchema($pdo);

$hoje = date('Y-m-d');
$em30 = date('Y-m-d', strtotime('+30 days'));

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpis = [
    'receber_total'  => 0.0,
    'pagar_total'    => 0.0,
    'receber_vencido'=> 0.0,
    'pagar_vencido'  => 0.0,
];

try {
    // Atualiza status de vencidos automaticamente
    $pdo->exec("UPDATE fin_contas_receber SET status='VENCIDO' WHERE status='ABERTO'    AND data_vencimento < '$hoje'");
    $pdo->exec("UPDATE fin_contas_receber SET status='VENCIDO' WHERE status='PARCIAL'   AND data_vencimento < '$hoje'");
    $pdo->exec("UPDATE fin_contas_pagar   SET status='VENCIDO' WHERE status='ABERTO'    AND data_vencimento < '$hoje'");
    $pdo->exec("UPDATE fin_contas_pagar   SET status='VENCIDO' WHERE status='PARCIAL'   AND data_vencimento < '$hoje'");

    $r = $pdo->query("SELECT
        SUM(CASE WHEN status IN ('ABERTO','PARCIAL','VENCIDO') THEN valor - valor_pago ELSE 0 END) AS total,
        SUM(CASE WHEN status = 'VENCIDO' THEN valor - valor_pago ELSE 0 END) AS vencido
        FROM fin_contas_receber")->fetch();
    $kpis['receber_total']   = (float)($r['total']   ?? 0);
    $kpis['receber_vencido'] = (float)($r['vencido'] ?? 0);

    $r = $pdo->query("SELECT
        SUM(CASE WHEN status IN ('ABERTO','PARCIAL','VENCIDO') THEN valor - valor_pago ELSE 0 END) AS total,
        SUM(CASE WHEN status = 'VENCIDO' THEN valor - valor_pago ELSE 0 END) AS vencido
        FROM fin_contas_pagar")->fetch();
    $kpis['pagar_total']   = (float)($r['total']   ?? 0);
    $kpis['pagar_vencido'] = (float)($r['vencido'] ?? 0);
} catch (Throwable) {}

$saldo = $kpis['receber_total'] - $kpis['pagar_total'];

// ── Próximos vencimentos (30 dias) ────────────────────────────────────────────
$proximosReceber = [];
$proximosPagar   = [];
try {
    $proximosReceber = $pdo->query("SELECT id, descricao, cliente_nome, valor, valor_pago, data_vencimento, status
        FROM fin_contas_receber
        WHERE status IN ('ABERTO','PARCIAL','VENCIDO')
        ORDER BY data_vencimento ASC LIMIT 10")->fetchAll();

    $proximosPagar = $pdo->query("SELECT id, descricao, fornecedor, valor, valor_pago, data_vencimento, status
        FROM fin_contas_pagar
        WHERE status IN ('ABERTO','PARCIAL','VENCIDO')
        ORDER BY data_vencimento ASC LIMIT 10")->fetchAll();
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Financeiro | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
.fin-kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
.fin-kpi { background:rgba(255,255,255,.025); border:1px solid var(--border); border-radius:16px; padding:22px 24px; display:flex; flex-direction:column; gap:6px; }
.fin-kpi-val { font-size:24px; font-weight:800; color:var(--text-primary); }
.fin-kpi-lbl { font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
.fin-kpi-sub { font-size:11px; margin-top:4px; }
.c-green .fin-kpi-val { color:#10b981; }
.c-red   .fin-kpi-val { color:var(--red); }
.c-blue  .fin-kpi-val { color:#7db3ff; }
.c-amber .fin-kpi-val { color:var(--amber); }

.fin-split { display:grid; grid-template-columns:1fr 1fr; gap:16px; }

.venc-tbl { width:100%; border-collapse:collapse; }
.venc-tbl th { padding:8px 12px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); background:rgba(0,0,0,.1); }
.venc-tbl td { padding:10px 12px; font-size:12.5px; border-bottom:1px solid var(--border); color:var(--text-primary); vertical-align:middle; }
.venc-tbl tbody tr:hover td { background:rgba(255,255,255,.025); }

.fs-aberto   { background:rgba(45,106,255,.1);  color:#7db3ff;   padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fs-pago     { background:rgba(16,185,129,.12); color:#10b981;  padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fs-parcial  { background:rgba(245,158,11,.1);  color:var(--amber); padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fs-vencido  { background:rgba(239,68,68,.12);  color:var(--red);   padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fs-cancelado{ background:rgba(255,255,255,.06);color:var(--text-muted); padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }

.empty-state { padding:40px 16px; text-align:center; color:var(--text-muted); font-size:13px; }

@media(max-width:1100px) { .fin-kpi-grid { grid-template-columns:1fr 1fr; } .fin-split { grid-template-columns:1fr; } }
@media(max-width:640px)  { .fin-kpi-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">

<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Dashboard Financeiro</h1>
      <p>Visão geral de contas a receber, a pagar e saldo previsto.</p>
    </div>
  </div>
  <div class="topbar-actions">
    <a href="/financeiro/receber" class="btn-primary">+ Nova Conta a Receber</a>
    <a href="/financeiro/pagar"   class="btn-secondary">+ Nova Conta a Pagar</a>
  </div>
</header>

<div class="content">

  <!-- KPIs -->
  <div class="fin-kpi-grid">
    <div class="fin-kpi c-green">
      <div class="fin-kpi-lbl">A Receber</div>
      <div class="fin-kpi-val"><?= finMoney($kpis['receber_total']) ?></div>
      <?php if ($kpis['receber_vencido'] > 0): ?>
        <div class="fin-kpi-sub" style="color:var(--red);">▲ <?= finMoney($kpis['receber_vencido']) ?> vencido</div>
      <?php endif; ?>
    </div>
    <div class="fin-kpi c-red">
      <div class="fin-kpi-lbl">A Pagar</div>
      <div class="fin-kpi-val"><?= finMoney($kpis['pagar_total']) ?></div>
      <?php if ($kpis['pagar_vencido'] > 0): ?>
        <div class="fin-kpi-sub" style="color:var(--red);">▲ <?= finMoney($kpis['pagar_vencido']) ?> vencido</div>
      <?php endif; ?>
    </div>
    <div class="fin-kpi <?= $saldo >= 0 ? 'c-blue' : 'c-amber' ?>">
      <div class="fin-kpi-lbl">Saldo Previsto</div>
      <div class="fin-kpi-val"><?= finMoney($saldo) ?></div>
      <div class="fin-kpi-sub" style="color:var(--text-muted);"><?= $saldo >= 0 ? 'Positivo' : 'Déficit' ?></div>
    </div>
    <div class="fin-kpi <?= ($kpis['receber_vencido'] + $kpis['pagar_vencido']) > 0 ? 'c-red' : '' ?>">
      <div class="fin-kpi-lbl">Total Vencido</div>
      <div class="fin-kpi-val"><?= finMoney($kpis['receber_vencido'] + $kpis['pagar_vencido']) ?></div>
      <div class="fin-kpi-sub" style="color:var(--text-muted);">receber + pagar</div>
    </div>
  </div>

  <!-- Tabelas side a side -->
  <div class="fin-split">

    <!-- Próximas a receber -->
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title">A Receber em Aberto</span>
        <a href="/financeiro/receber" class="btn-secondary" style="height:28px;padding:0 10px;font-size:11px;display:inline-flex;align-items:center;">Ver todas →</a>
      </div>
      <?php if (empty($proximosReceber)): ?>
        <div class="empty-state">Nenhuma conta pendente.</div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="venc-tbl">
          <thead><tr><th>Descrição</th><th>Vencimento</th><th>Valor</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($proximosReceber as $row):
            $atrasado = $row['data_vencimento'] < $hoje && $row['status'] !== 'PAGO';
          ?>
            <tr>
              <td>
                <div style="font-weight:600;"><?= finH($row['descricao']) ?></div>
                <?php if ($row['cliente_nome']): ?><div style="font-size:11px;color:var(--text-muted);"><?= finH($row['cliente_nome']) ?></div><?php endif; ?>
              </td>
              <td style="white-space:nowrap;<?= $atrasado ? 'color:var(--red);font-weight:700;' : '' ?>">
                <?= date('d/m/Y', strtotime($row['data_vencimento'])) ?>
              </td>
              <td style="white-space:nowrap;font-weight:700;"><?= finMoney((float)$row['valor'] - (float)$row['valor_pago']) ?></td>
              <td><span class="<?= finStatusClass($row['status']) ?>"><?= finStatusLabel($row['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Próximas a pagar -->
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title">A Pagar em Aberto</span>
        <a href="/financeiro/pagar" class="btn-secondary" style="height:28px;padding:0 10px;font-size:11px;display:inline-flex;align-items:center;">Ver todas →</a>
      </div>
      <?php if (empty($proximosPagar)): ?>
        <div class="empty-state">Nenhuma conta pendente.</div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="venc-tbl">
          <thead><tr><th>Descrição</th><th>Vencimento</th><th>Valor</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($proximosPagar as $row):
            $atrasado = $row['data_vencimento'] < $hoje && $row['status'] !== 'PAGO';
          ?>
            <tr>
              <td>
                <div style="font-weight:600;"><?= finH($row['descricao']) ?></div>
                <?php if ($row['fornecedor']): ?><div style="font-size:11px;color:var(--text-muted);"><?= finH($row['fornecedor']) ?></div><?php endif; ?>
              </td>
              <td style="white-space:nowrap;<?= $atrasado ? 'color:var(--red);font-weight:700;' : '' ?>">
                <?= date('d/m/Y', strtotime($row['data_vencimento'])) ?>
              </td>
              <td style="white-space:nowrap;font-weight:700;"><?= finMoney((float)$row['valor'] - (float)$row['valor_pago']) ?></td>
              <td><span class="<?= finStatusClass($row['status']) ?>"><?= finStatusLabel($row['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /fin-split -->
</div>
</div>
</div>
</body>
</html>
