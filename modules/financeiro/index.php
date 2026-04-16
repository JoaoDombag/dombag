<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/fin_schema.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

$pdo  = dbPDO();
$hoje = date('Y-m-d');
$ano  = (int)date('Y');

finEnsureSchema($pdo);

// ── KPIs e painéis: ERP Yzidro (PostgreSQL) ───────────────────────────────────
$kpis = [
    'receber_total'   => 0.0,
    'pagar_total'     => 0.0,
    'receber_vencido' => 0.0,
    'pagar_vencido'   => 0.0,
];
$proximosReceber = [];
$proximosPagar   = [];
$erpErro         = '';

$pg = dbPG();
if ($pg) {
    // ── Totais a receber ─────────────────────────────────────────────────────
    $res = @pg_query($pg, "
        SELECT
            SUM(COALESCE(CR_SALDO_PARCELA_MP,0)) AS total,
            SUM(CASE WHEN CR_VENCIMENTO < CURRENT_DATE
                     THEN COALESCE(CR_SALDO_PARCELA_MP,0) ELSE 0 END) AS vencido
        FROM CONTAS_RECEBER
        WHERE CR_TIPO = 'C' AND CR_VINCULO IS NULL AND EMP_CODIGO = 1
          AND COALESCE(CR_SALDO_PARCELA_MP,0) > 0
    ");
    if ($res && $r = pg_fetch_assoc($res)) {
        $kpis['receber_total']   = (float)($r['total']   ?? 0);
        $kpis['receber_vencido'] = (float)($r['vencido'] ?? 0);
        pg_free_result($res);
    }

    // ── Totais a pagar ───────────────────────────────────────────────────────
    $res = @pg_query($pg, "
        SELECT
            SUM(COALESCE(CP_SALDO_MP,0)) AS total,
            SUM(CASE WHEN CP_VENCIMENTO < CURRENT_DATE
                     THEN COALESCE(CP_SALDO_MP,0) ELSE 0 END) AS vencido
        FROM CONTAS_PAGAR
        WHERE CP_TIPO = 'D' AND CP_VINCULO IS NULL AND EMP_CODIGO = 1
          AND COALESCE(CP_SALDO_MP,0) > 0
    ");
    if ($res && $r = pg_fetch_assoc($res)) {
        $kpis['pagar_total']   = (float)($r['total']   ?? 0);
        $kpis['pagar_vencido'] = (float)($r['vencido'] ?? 0);
        pg_free_result($res);
    }

    // ── Próximas a receber (10 mais urgentes) ─────────────────────────────────
    $res = @pg_query($pg, "
        SELECT CR.CR_OPERACAO                                           AS descricao,
               COALESCE(CL.CLI_NOME_FANTASIA, CL.CLI_NOME, '')         AS cliente_nome,
               CAST(COALESCE(CR.CR_SALDO_PARCELA_MP,0) AS NUMERIC(18,2)) AS saldo,
               TO_CHAR(CR.CR_VENCIMENTO,'YYYY-MM-DD')                  AS data_vencimento,
               CASE WHEN COALESCE(CR.CR_SALDO_PARCELA_MP,0) <= 0 THEN 'Recebido'
                    ELSE 'A Receber' END                               AS status_desc
        FROM CONTAS_RECEBER CR
        INNER JOIN CLIENTES CL ON CL.CLI_CODIGO = CR.CLI_CODIGO
        WHERE CR.CR_TIPO = 'C' AND CR.CR_VINCULO IS NULL
          AND CR.EMP_CODIGO = 1 AND COALESCE(CR.CR_SALDO_PARCELA_MP,0) > 0
        ORDER BY CR.CR_VENCIMENTO ASC
        LIMIT 10
    ");
    if ($res) {
        while ($r = pg_fetch_assoc($res)) $proximosReceber[] = $r;
        pg_free_result($res);
    }

    // ── Próximas a pagar (10 mais urgentes) ──────────────────────────────────
    $res = @pg_query($pg, "
        SELECT CP.CP_OPERACAO                                        AS descricao,
               COALESCE(F.FOR_NOMEFANTASIA, F.FOR_NOME, '')          AS fornecedor,
               CAST(COALESCE(CP.CP_SALDO_MP,0) AS NUMERIC(18,2))    AS saldo,
               TO_CHAR(CP.CP_VENCIMENTO,'YYYY-MM-DD')               AS data_vencimento,
               CASE WHEN COALESCE(CP.CP_SALDO_MP,0) <= 0 THEN 'Pago'
                    ELSE 'A Pagar' END                              AS status_desc
        FROM CONTAS_PAGAR CP
        INNER JOIN FORNECEDOR F ON F.FOR_CODIGO = CP.FOR_CODIGO
        WHERE CP.CP_TIPO = 'D' AND CP.CP_VINCULO IS NULL
          AND CP.EMP_CODIGO = 1 AND COALESCE(CP.CP_SALDO_MP,0) > 0
        ORDER BY CP.CP_VENCIMENTO ASC
        LIMIT 10
    ");
    if ($res) {
        while ($r = pg_fetch_assoc($res)) $proximosPagar[] = $r;
        pg_free_result($res);
    }

    pg_close($pg);
} else {
    $erpErro = 'ERP Yzidro indisponível — exibindo dados locais.';

    // Fallback: tabelas locais
    try {
        $pdo->exec("UPDATE fin_contas_receber SET status='VENCIDO' WHERE status IN ('ABERTO','PARCIAL') AND data_vencimento < '$hoje'");
        $pdo->exec("UPDATE fin_contas_pagar   SET status='VENCIDO' WHERE status IN ('ABERTO','PARCIAL') AND data_vencimento < '$hoje'");

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

        $rawRec = $pdo->query("SELECT descricao, cliente_nome, (valor - valor_pago) AS saldo, data_vencimento, status AS status_desc FROM fin_contas_receber WHERE status IN ('ABERTO','PARCIAL','VENCIDO') ORDER BY data_vencimento ASC LIMIT 10")->fetchAll();
        foreach ($rawRec as $r) {
            $proximosReceber[] = ['descricao' => $r['descricao'], 'cliente_nome' => $r['cliente_nome'], 'saldo' => $r['saldo'], 'data_vencimento' => $r['data_vencimento'], 'status_desc' => $r['status_desc']];
        }
        $rawPag = $pdo->query("SELECT descricao, fornecedor, (valor - valor_pago) AS saldo, data_vencimento, status AS status_desc FROM fin_contas_pagar WHERE status IN ('ABERTO','PARCIAL','VENCIDO') ORDER BY data_vencimento ASC LIMIT 10")->fetchAll();
        foreach ($rawPag as $r) {
            $proximosPagar[] = ['descricao' => $r['descricao'], 'fornecedor' => $r['fornecedor'], 'saldo' => $r['saldo'], 'data_vencimento' => $r['data_vencimento'], 'status_desc' => $r['status_desc']];
        }
    } catch (Throwable) {}
}

$saldo = $kpis['receber_total'] - $kpis['pagar_total'];

// ── Helper de status ERP → CSS ────────────────────────────────────────────────
function erpStClass(string $st, string $venc, string $hoje): string {
    if ($st !== 'Recebido' && $st !== 'Pago' && $venc < $hoje) return 'fs-vencido';
    return match($st) {
        'Recebido', 'Pago' => 'fs-pago',
        'Provisionado'     => 'fs-parcial',
        default            => 'fs-aberto',
    };
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Financeiro | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
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
.venc-tbl th { padding:8px 12px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); background:#112240; position:sticky; top:0; z-index:2; }
.venc-tbl td { padding:10px 12px; font-size:12.5px; border-bottom:1px solid var(--border); color:var(--text-primary); vertical-align:middle; }
.venc-tbl tbody tr:hover td { background:rgba(255,255,255,.025); }

.fs-aberto   { background:rgba(45,106,255,.1);  color:#7db3ff;      padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fs-pago     { background:rgba(16,185,129,.12); color:#10b981;      padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fs-parcial  { background:rgba(245,158,11,.1);  color:var(--amber); padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }
.fs-vencido  { background:rgba(239,68,68,.12);  color:var(--red);   padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; }

.empty-state { padding:40px 16px; text-align:center; color:var(--text-muted); font-size:13px; }
.alert-erp { padding:10px 16px; border-radius:8px; background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); color:var(--amber); font-size:12.5px; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.src-erp { display:inline-flex; align-items:center; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase; background:rgba(167,139,250,.12); color:#a78bfa; letter-spacing:.04em; }

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
      <p>Contas a receber e a pagar — ERP Yzidro<?= $erpErro ? ' (offline — dados locais)' : '' ?>.</p>
    </div>
  </div>
  <div class="topbar-actions">
    <a href="/financeiro/receber" class="btn-primary">+ Nova Conta a Receber</a>
    <a href="/financeiro/pagar"   class="btn-secondary">+ Nova Conta a Pagar</a>
  </div>
</header>

<div class="content">

  <?php if ($erpErro): ?>
  <div class="alert-erp">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= finH($erpErro) ?>
  </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="fin-kpi-grid">
    <div class="fin-kpi c-green">
      <div class="fin-kpi-lbl">A Receber &nbsp;<span class="src-erp">ERP</span></div>
      <div class="fin-kpi-val" style="font-size:20px;letter-spacing:-.5px"><?= finMoney($kpis['receber_total']) ?></div>
      <?php if ($kpis['receber_vencido'] > 0): ?>
        <div class="fin-kpi-sub" style="color:var(--red);">▲ <?= finMoney($kpis['receber_vencido']) ?> vencido</div>
      <?php else: ?>
        <div class="fin-kpi-sub" style="color:var(--text-muted);">nenhum vencido</div>
      <?php endif; ?>
    </div>
    <div class="fin-kpi c-red">
      <div class="fin-kpi-lbl">A Pagar &nbsp;<span class="src-erp">ERP</span></div>
      <div class="fin-kpi-val" style="font-size:20px;letter-spacing:-.5px"><?= finMoney($kpis['pagar_total']) ?></div>
      <?php if ($kpis['pagar_vencido'] > 0): ?>
        <div class="fin-kpi-sub" style="color:var(--red);">▲ <?= finMoney($kpis['pagar_vencido']) ?> vencido</div>
      <?php else: ?>
        <div class="fin-kpi-sub" style="color:var(--text-muted);">nenhum vencido</div>
      <?php endif; ?>
    </div>
    <div class="fin-kpi <?= $saldo >= 0 ? 'c-blue' : 'c-amber' ?>">
      <div class="fin-kpi-lbl">Saldo Previsto</div>
      <div class="fin-kpi-val" style="font-size:20px;letter-spacing:-.5px"><?= finMoney($saldo) ?></div>
      <div class="fin-kpi-sub" style="color:var(--text-muted);"><?= $saldo >= 0 ? 'Positivo' : 'Déficit' ?></div>
    </div>
    <div class="fin-kpi <?= ($kpis['receber_vencido'] + $kpis['pagar_vencido']) > 0 ? 'c-red' : '' ?>">
      <div class="fin-kpi-lbl">Total Vencido</div>
      <div class="fin-kpi-val" style="font-size:20px;letter-spacing:-.5px"><?= finMoney($kpis['receber_vencido'] + $kpis['pagar_vencido']) ?></div>
      <div class="fin-kpi-sub" style="color:var(--text-muted);">receber + pagar</div>
    </div>
  </div>

  <!-- Tabelas lado a lado -->
  <div class="fin-split">

    <!-- A Receber em aberto -->
    <div class="panel-table">
      <div class="panel-header">
        <div style="display:flex;align-items:center;gap:8px;">
          <span class="panel-title">A Receber em Aberto</span>
          <span class="src-erp">ERP</span>
        </div>
        <a href="/financeiro/receber" class="btn-secondary" style="height:28px;padding:0 10px;font-size:11px;display:inline-flex;align-items:center;">Ver todas →</a>
      </div>
      <?php if (empty($proximosReceber)): ?>
        <div class="empty-state"><?= $erpErro ? 'ERP indisponível.' : 'Nenhuma conta pendente.' ?></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="venc-tbl">
          <thead><tr><th>Descrição</th><th>Cliente</th><th>Vencimento</th><th>Saldo</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($proximosReceber as $r):
            $vencDt   = $r['data_vencimento'];
            $atrasado = $vencDt < $hoje && $r['status_desc'] !== 'Recebido';
            $stClass  = erpStClass($r['status_desc'], $vencDt, $hoje);
            $stLabel  = $atrasado && $r['status_desc'] === 'A Receber' ? 'Vencido' : $r['status_desc'];
          ?>
            <tr>
              <td style="font-weight:600;"><?= finH($r['descricao']) ?></td>
              <td style="font-size:12px;color:var(--text-muted);"><?= finH($r['cliente_nome'] ?: '—') ?></td>
              <td style="white-space:nowrap;<?= $atrasado ? 'color:var(--red);font-weight:700;' : '' ?>">
                <?= date('d/m/Y', strtotime($vencDt)) ?>
              </td>
              <td style="white-space:nowrap;font-weight:700;"><?= finMoney((float)$r['saldo']) ?></td>
              <td><span class="<?= $stClass ?>"><?= finH($stLabel) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- A Pagar em aberto -->
    <div class="panel-table">
      <div class="panel-header">
        <div style="display:flex;align-items:center;gap:8px;">
          <span class="panel-title">A Pagar em Aberto</span>
          <span class="src-erp">ERP</span>
        </div>
        <a href="/financeiro/pagar" class="btn-secondary" style="height:28px;padding:0 10px;font-size:11px;display:inline-flex;align-items:center;">Ver todas →</a>
      </div>
      <?php if (empty($proximosPagar)): ?>
        <div class="empty-state"><?= $erpErro ? 'ERP indisponível.' : 'Nenhuma conta pendente.' ?></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="venc-tbl">
          <thead><tr><th>Descrição</th><th>Fornecedor</th><th>Vencimento</th><th>Saldo</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($proximosPagar as $r):
            $vencDt   = $r['data_vencimento'];
            $atrasado = $vencDt < $hoje && $r['status_desc'] !== 'Pago';
            $stClass  = erpStClass($r['status_desc'], $vencDt, $hoje);
            $stLabel  = $atrasado && $r['status_desc'] === 'A Pagar' ? 'Vencido' : $r['status_desc'];
          ?>
            <tr>
              <td style="font-weight:600;"><?= finH($r['descricao']) ?></td>
              <td style="font-size:12px;color:var(--text-muted);"><?= finH($r['fornecedor'] ?: '—') ?></td>
              <td style="white-space:nowrap;<?= $atrasado ? 'color:var(--red);font-weight:700;' : '' ?>">
                <?= date('d/m/Y', strtotime($vencDt)) ?>
              </td>
              <td style="white-space:nowrap;font-weight:700;"><?= finMoney((float)$r['saldo']) ?></td>
              <td><span class="<?= $stClass ?>"><?= finH($stLabel) ?></span></td>
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
