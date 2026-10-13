<?php
// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Dashboard
//  KPIs de produção (apontamentos) e de saída (pedidos finalizados), tudo
//  direto do ERP Yzidro (PostgreSQL, somente leitura) — ver dashboard_dados.php.
//  As antigas tabelas locais (PRODUCAO_DIARIA, ITENS_VENDAS, PCP_PROGRAMACAO)
//  pararam de ser alimentadas e deixaram de ser usadas aqui.
// ══════════════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/dashboard_dados.php';

// ── Catálogo de widgets (a tela /dashboard/widgets usa o mesmo) ───────
require_once __DIR__ . '/dashboard_widgets.php';

// ── Preferências de widgets do usuário ────────────────────────────────
$_dash_uid = (int) ($_SESSION['usu_codigo'] ?? 0);
$dashWidgets = dashWidgetsDoUsuario($_dash_uid);

function dashW(string $key): bool
{
    global $dashWidgets, $_dash_uid;
    return in_array($key, $dashWidgets, true) && dashWidgetPermitido($key, $_dash_uid);
}

// ── Board Trello padrão ───────────────────────────────────────────────
$trello_default_board = '';
if (dashW('trello_geral') || dashW('trello_atividade')) {
    try {
        $v = dbPDO()->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'trello_default_board'")->fetchColumn();
        $trello_default_board = $v !== false ? trim((string) $v) : '';
    } catch (Throwable) {}
}

// ── Dados do ERP (só o que os widgets ativos usam) ────────────────────
$erp_ok   = false;
$hoje     = date('Y-m-d');
$prodDias = [];      // 'Y-m-d' => qtd
$prodMes  = 0.0;
$metaMes  = 0.0;
$agora    = ['funcionarios' => 0, 'ops' => 0];
$opsAtivas = [];
$saidasMes = [];     // pedidos finalizados desde o 1º dia do mês (ou de 14 dias atrás)
$ultimaSaida = null;

$precisaProducao = dashW('kpi_producao') || dashW('grafico_14dias');
$precisaSaida    = dashW('kpi_saida') || dashW('saidas_hoje') || dashW('grafico_14dias');
$pg = ($precisaProducao || $precisaSaida || dashW('ops_produzindo')) ? dbPG() : null;
if ($pg) {
    $erp_ok = true;
    if ($precisaProducao) {
        $prodDias = dashProducaoPorDia($pg, 14);
        $prodMes  = dashProducaoMes($pg);
        $agora    = dashProduzindoAgora($pg);
        try {
            $v = dbPDO()->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'meta_producao'")->fetchColumn();
            $metaMes = $v !== false ? (float) $v : 0.0;
        } catch (Throwable) {}
    }
    if (dashW('ops_produzindo')) {
        $opsAtivas = dashOpsAtivas($pg);
    }
    if ($precisaSaida) {
        $desde = min(date('Y-m-01'), date('Y-m-d', strtotime('-13 days')));
        $saidasMes = dashSaidasDesde($pg, $desde);
        if (dashW('saidas_hoje')) {
            $ultimaSaida = dashUltimaSaida($pg);
        }
    }
}

// ── Agregados ─────────────────────────────────────────────────────────
$prodHoje = $prodDias[$hoje] ?? 0.0;
// Último dia útil anterior com produção (pra comparar com hoje)
$prodAnterior = 0.0;
$diaAnterior  = null;
foreach ($prodDias as $d => $q) {
    if ($d < $hoje && $q > 0 && ($diaAnterior === null || $d > $diaAnterior)) {
        $diaAnterior  = $d;
        $prodAnterior = $q;
    }
}
$saidasHoje = array_values(array_filter($saidasMes, fn ($s) => $s['dia'] === $hoje));
$saidasDoMes = array_values(array_filter($saidasMes, fn ($s) => $s['dia'] >= date('Y-m-01')));
$totHoje = dashSomaSaidas($saidasHoje);
$totMes  = dashSomaSaidas($saidasDoMes);

// Série de 14 dias: produção (bags apontados) x saída de bags
$serie = ['labels' => [], 'producao' => [], 'saida' => []];
$bagsPorDia = [];
foreach ($saidasMes as $s) {
    $bagsPorDia[$s['dia']] = ($bagsPorDia[$s['dia']] ?? 0) + $s['bag'];
}
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $serie['labels'][]   = date('d/m', strtotime($d));
    $serie['producao'][] = round($prodDias[$d] ?? 0);
    $serie['saida'][]    = round($bagsPorDia[$d] ?? 0);
}

// ── Helpers de formato ────────────────────────────────────────────────
function fmtNum(float $v): string   { return number_format($v, 0, ',', '.'); }
function fmtMoeda(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function fmtMoedaCurta(float $v): string
{
    $a = abs($v);
    return match (true) {
        $a >= 1e6 => 'R$ ' . number_format($v / 1e6, 1, ',', '.') . ' mi',
        $a >= 1e3 => 'R$ ' . number_format($v / 1e3, 0, ',', '.') . ' mil',
        default   => fmtMoeda($v),
    };
}
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= e(dombagTema()) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | DOMBAG</title>
  <link rel="stylesheet" href="/public/css/unified_admin.css">
  <link rel="icon" href="/public/css/icone.ico" type="image/png">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    /* ── Layout do dashboard ───────────────────────── */
    .content           { display:flex; flex-direction:column; gap:16px; overflow-y:auto; overflow-x:hidden; }
    .dash-grid         { display:grid; gap:16px; align-items:stretch; }
    .dash-grid > div   { display:flex; flex-direction:column; }
    .dash-grid > div > .panel, .dash-grid > .panel { flex:1; }
    @media(max-width:1100px) { .dash-grid { grid-template-columns:1fr !important; } }

    /* ── Linhas de KPI ─────────────────────────────── */
    .dash-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px; }
    .dash-kpis .kpi-card { margin:0; }
    .kpi-sub   { font-size:11.5px; color:var(--text-muted); margin-top:2px; }
    .kpi-sub b { color:var(--text-primary); font-weight:600; }
    .kpi-meta-bar { height:5px; background:rgba(255,255,255,.07); border-radius:3px; overflow:hidden; margin:8px 0 6px; }
    .kpi-meta-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#2d6aff,#00c9a7); }
    .kpi-footer a { font-weight:600; text-decoration:none; }
    .dash-sec { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin:4px 2px -6px; }
    @media(max-width:480px) { .dash-kpis { grid-template-columns:1fr 1fr; } .kpi-value { font-size:22px; } }

    /* ── Gráfico ───────────────────────────────────── */
    .chart-wrap { position:relative; height:220px; padding:0 16px 12px; }
    @media(max-width:768px) { .chart-wrap { height:170px; } }

    /* ── Listas (OPs, saídas) ──────────────────────── */
    .dash-row   { display:flex; align-items:center; gap:12px; padding:10px 20px; border-bottom:1px solid var(--border); }
    .dash-row:last-child { border-bottom:none; }
    .dash-row-main { flex:1; min-width:0; }
    .dash-row-t { font-size:12.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .dash-row-s { font-size:11px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }
    .dash-row-r { display:flex; flex-direction:column; align-items:flex-end; gap:2px; flex-shrink:0; }
    .dash-row-v { font-size:12.5px; font-weight:700; color:#7db3ff; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .dash-num   { font-weight:700; color:#7db3ff; font-size:12.5px; white-space:nowrap; flex-shrink:0; }
    .dash-bar   { height:5px; background:rgba(255,255,255,.06); border-radius:3px; overflow:hidden; margin-top:6px; }
    .dash-bar-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#3aa7ff,#00c9a7); }
    .dash-bar-fill.over { background:linear-gradient(90deg,#f0a638,#fbbf24); }
    .dash-live  { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--teal); box-shadow:0 0 6px var(--teal); margin-right:6px; vertical-align:middle; }
    .dash-dev   { font-size:10px; font-weight:700; padding:1px 6px; border-radius:8px; background:rgba(239,68,68,.12); color:var(--red); margin-left:6px; }

    /* ── Misc ──────────────────────────────────────── */
    .erp-status    { display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--text-muted); }
    .erp-dot       { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .erp-dot.on    { background:var(--teal); box-shadow:0 0 6px var(--teal); }
    .erp-dot.off   { background:var(--red); }
    .empty-row     { text-align:center; padding:32px 20px; color:var(--text-muted); font-size:13px; }
    .greeting      { font-size:11.5px; color:var(--text-muted); }
    .greeting strong { color:var(--text-primary); }

    /* ── Barras do Trello ──────────────────────────── */
    .maq-bar-row   { display:flex; flex-direction:column; gap:10px; padding:16px 20px; }
    .maq-bar-item  { display:flex; flex-direction:column; gap:4px; }
    .maq-bar-label { display:flex; justify-content:space-between; align-items:center; }
    .maq-bar-name  { font-size:12.5px; font-weight:500; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px; }
    .maq-bar-val   { font-size:12px; font-weight:700; color:#7db3ff; white-space:nowrap; }
    .maq-bar-bg    { height:6px; background:rgba(255,255,255,.06); border-radius:3px; overflow:hidden; }
    .maq-bar-fill  { height:100%; border-radius:3px; transition:width .6s ease; }
    .trello-inner-grid { display:grid; grid-template-columns:1fr 180px; gap:0; align-items:start; }
    @media(max-width:768px) { .trello-inner-grid { grid-template-columns:1fr; } .trello-inner-grid > div:last-child { border-left:none !important; border-top:1px solid var(--border); } .maq-bar-name { max-width:none; } }
  </style>
</head>
<body>
<div class="app-wrapper">

  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">

    <!-- ── Topbar ───────────────────────────────────── -->
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Painel de Controle</h1>
          <p id="topbar-date"></p>
        </div>
      </div>
      <div class="topbar-actions">
        <div class="erp-status">
          <span class="erp-dot <?= $erp_ok ? 'on' : 'off' ?>"></span>
          ERP <?= $erp_ok ? 'conectado' : 'offline' ?>
        </div>
        <a href="/dashboard/widgets" class="btn-secondary" title="Personalizar dashboard"
           style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:12px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
          </svg>
          Widgets
        </a>
        <span class="greeting">Olá, <strong><?= e(usuNome()) ?></strong></span>
        <a href="/logout" class="icon-btn" title="Sair" style="text-decoration:none;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </a>
      </div>
    </header>

    <?php
    // ════════════════════════════════════════════════════════════════════
    // Pré-renderiza cada widget em buffer para respeitar a ordem salva
    // ════════════════════════════════════════════════════════════════════
    $_w = [];
    $semErp = '<div class="empty-row">⚠ ERP offline — sem dados.</div>';

    // ── KPIs de Produção ──────────────────────────────────────────────
    if (dashW('kpi_producao')) {
        $pctMeta = $metaMes > 0 ? $prodMes / $metaMes * 100 : 0;
        ob_start(); ?>
        <div class="dash-sec">Produção</div>
        <div class="dash-kpis">
          <div class="kpi-card teal">
            <div class="kpi-header">
              <span class="kpi-label">Produzido Hoje</span>
              <div class="kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            </div>
            <div class="kpi-value"><?= fmtNum($prodHoje) ?></div>
            <div class="kpi-footer">
              peças apontadas
              <?php if ($diaAnterior): ?>&nbsp;· <?= date('d/m', strtotime($diaAnterior)) ?>: <b><?= fmtNum($prodAnterior) ?></b><?php endif; ?>
            </div>
          </div>

          <div class="kpi-card blue">
            <div class="kpi-header">
              <span class="kpi-label">Produção do Mês</span>
              <div class="kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
            </div>
            <div class="kpi-value"><?= fmtNum($prodMes) ?></div>
            <?php if ($metaMes > 0): ?>
              <div class="kpi-meta-bar"><div class="kpi-meta-fill" style="width:<?= min(100, round($pctMeta)) ?>%"></div></div>
              <div class="kpi-footer"><b><?= number_format($pctMeta, 0, ',', '.') ?>%</b>&nbsp;da meta de <?= fmtNum($metaMes) ?></div>
            <?php else: ?>
              <div class="kpi-footer">sem meta definida &nbsp;<a href="/pcp/bi" style="color:#7db3ff;">Definir no BI →</a></div>
            <?php endif; ?>
          </div>

          <div class="kpi-card <?= $agora['funcionarios'] > 0 ? 'teal' : 'amber' ?>">
            <div class="kpi-header">
              <span class="kpi-label">Produzindo Agora</span>
              <div class="kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            </div>
            <div class="kpi-value"><?= fmtNum($agora['funcionarios']) ?></div>
            <div class="kpi-footer">
              funcionário<?= $agora['funcionarios'] !== 1 ? 's' : '' ?> em <?= fmtNum($agora['ops']) ?> O.P<?= $agora['ops'] !== 1 ? 's' : '' ?>
              &nbsp;<a href="/pcp/bi-individual" style="color:var(--teal);">Ver →</a>
            </div>
          </div>
        </div>
        <?php $_html = ob_get_clean();
        $_w['kpi_producao'] = $erp_ok ? $_html : '<div class="panel">' . $semErp . '</div>';
    }

    // ── KPIs de Saída ─────────────────────────────────────────────────
    if (dashW('kpi_saida')) {
        ob_start(); ?>
        <div class="dash-sec">Saída</div>
        <div class="dash-kpis">
          <div class="kpi-card <?= $totHoje['pedidos'] > 0 ? 'teal' : 'amber' ?>">
            <div class="kpi-header">
              <span class="kpi-label">Saída Hoje</span>
              <div class="kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
            </div>
            <div class="kpi-value"><?= fmtNum($totHoje['pedidos']) ?></div>
            <div class="kpi-footer">pedido<?= $totHoje['pedidos'] !== 1 ? 's' : '' ?> · <?= fmtNum($totHoje['bag']) ?> bags · <?= fmtNum($totHoje['sac']) ?> sacaria</div>
          </div>

          <div class="kpi-card blue">
            <div class="kpi-header">
              <span class="kpi-label">Saída do Mês</span>
              <div class="kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            </div>
            <div class="kpi-value"><?= fmtNum($totMes['pedidos']) ?></div>
            <div class="kpi-footer">pedidos · <?= fmtNum($totMes['bag']) ?> bags · <?= fmtNum($totMes['sac']) ?> sacaria</div>
          </div>

          <div class="kpi-card teal">
            <div class="kpi-header">
              <span class="kpi-label">Faturado no Mês</span>
              <div class="kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
            </div>
            <div class="kpi-value" title="<?= e(fmtMoeda($totMes['valor'])) ?>"><?= e(fmtMoedaCurta($totMes['valor'])) ?></div>
            <div class="kpi-footer">
              <?= $totHoje['valor'] != 0 ? e(fmtMoedaCurta($totHoje['valor'])) . ' hoje' : 'nada faturado hoje' ?>
              &nbsp;<a href="/relatorios/historico-faturamento" style="color:var(--teal);">Histórico →</a>
            </div>
          </div>
        </div>
        <?php $_html = ob_get_clean();
        $_w['kpi_saida'] = $erp_ok ? $_html : '<div class="panel">' . $semErp . '</div>';
    }

    // ── Gráfico: Produção x Saída de Bags — 14 dias ──────────────────
    if (dashW('grafico_14dias')) {
        ob_start(); ?>
        <div class="panel">
          <div class="panel-header">
            <span class="panel-title">Produção x Saída de Bags — Últimos 14 Dias</span>
            <a class="panel-action" href="/pcp/bi">BI da Produção →</a>
          </div>
          <?= $erp_ok ? '<div class="chart-wrap"><canvas id="chartProdSaida"></canvas></div>' : $semErp ?>
        </div>
        <?php $_w['grafico_14dias'] = ob_get_clean();
    }

    // ── OPs em produção agora ─────────────────────────────────────────
    if (dashW('ops_produzindo')) {
        ob_start(); ?>
        <div class="panel">
          <div class="panel-header">
            <span class="panel-title">O.Ps em Produção Agora</span>
            <a class="panel-action" href="/pcp/bi">Ver no BI →</a>
          </div>
          <?php if (!$erp_ok): ?>
            <?= $semErp ?>
          <?php elseif (!$opsAtivas): ?>
            <div class="empty-row">Nenhuma O.P com apontamento em aberto.</div>
          <?php else: foreach ($opsAtivas as $o):
              $pct = $o['pedido'] > 0 ? round($o['lido'] / $o['pedido'] * 100) : 0; ?>
            <div class="dash-row">
              <span class="dash-num"><span class="dash-live"></span>O.P <?= e($o['op']) ?></span>
              <div class="dash-row-main">
                <div class="dash-row-t" title="<?= e($o['cliente']) ?>"><?= e($o['cliente'] ?: '—') ?></div>
                <div class="dash-row-s" title="<?= e($o['produto']) ?>"><?= e($o['produto']) ?> · <?= $o['funcs'] ?> pessoa<?= $o['funcs'] !== 1 ? 's' : '' ?></div>
                <?php if ($o['pedido'] > 0): ?><div class="dash-bar"><div class="dash-bar-fill<?= $pct > 100 ? ' over' : '' ?>" style="width:<?= min(100, $pct) ?>%"></div></div><?php endif; ?>
              </div>
              <div class="dash-row-r">
                <span class="dash-row-v"><?= $o['pedido'] > 0 ? $pct . '%' : '—' ?></span>
                <span class="dash-row-s"><?= fmtNum($o['lido']) ?> / <?= $o['pedido'] > 0 ? fmtNum($o['pedido']) : '—' ?></span>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <?php $_w['ops_produzindo'] = ob_get_clean();
    }

    // ── Pedidos que saíram hoje ───────────────────────────────────────
    if (dashW('saidas_hoje')) {
        ob_start(); ?>
        <div class="panel">
          <div class="panel-header">
            <span class="panel-title">Saídas de Hoje</span>
            <a class="panel-action" href="/relatorios/historico-faturamento">Histórico →</a>
          </div>
          <?php if (!$erp_ok): ?>
            <?= $semErp ?>
          <?php elseif (!$saidasHoje): ?>
            <div class="empty-row">
              Nenhum pedido saiu hoje ainda.
              <?php if ($ultimaSaida): ?><br><span style="font-size:11.5px;">Última saída em <?= date('d/m/Y', strtotime($ultimaSaida)) ?>.</span><?php endif; ?>
            </div>
          <?php else: foreach (array_slice($saidasHoje, 0, 8) as $s): ?>
            <div class="dash-row">
              <span class="dash-num"><?= e($s['pedido']) ?></span>
              <div class="dash-row-main">
                <div class="dash-row-t" title="<?= e($s['cliente']) ?>"><?= e($s['cliente'] ?: '—') ?><?= $s['devolucao'] ? '<span class="dash-dev">DEV</span>' : '' ?></div>
                <div class="dash-row-s">
                  <?= implode(' · ', array_filter([
                      $s['bag'] != 0 ? fmtNum($s['bag']) . ' bags' : '',
                      $s['sac'] != 0 ? fmtNum($s['sac']) . ' sacaria' : '',
                      $s['bag'] == 0 && $s['sac'] == 0 ? fmtNum($s['pecas']) . ' peças' : '',
                  ])) ?>
                </div>
              </div>
              <div class="dash-row-r"><span class="dash-row-v"><?= e(fmtMoeda($s['valor'])) ?></span></div>
            </div>
          <?php endforeach; ?>
            <?php if (count($saidasHoje) > 8): ?>
              <div class="dash-row" style="justify-content:center;"><a class="panel-action" href="/relatorios/historico-faturamento?data_ini=<?= $hoje ?>&data_fim=<?= $hoje ?>">+ <?= count($saidasHoje) - 8 ?> pedido(s) →</a></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php $_w['saidas_hoje'] = ob_get_clean();
    }

    // ── Trello — Visão Geral ──────────────────────────────────────────
    if (dashW('trello_geral')) {
        ob_start(); ?>
        <div id="trello-geral-wrap" style="display:flex;flex-direction:column;">
          <div class="panel" style="flex:1;"><div style="padding:20px;color:var(--text-muted);font-size:13px;">Carregando…</div></div>
        </div>
        <?php $_w['trello_geral'] = ob_get_clean();
    }

    // ── Trello — Atividade ────────────────────────────────────────────
    if (dashW('trello_atividade')) {
        ob_start(); ?>
        <div id="trello-atividade-wrap" style="display:flex;flex-direction:column;">
          <div class="panel" style="flex:1;"><div style="padding:20px;color:var(--text-muted);font-size:13px;">Carregando…</div></div>
        </div>
        <?php $_w['trello_atividade'] = ob_get_clean();
    }

    // ── Renderiza em ordem, juntando pares lado a lado ────────────────
    $_pairs = [
        'ops_produzindo'   => ['saidas_hoje',      '1fr 1fr'],
        'saidas_hoje'      => ['ops_produzindo',   '1fr 1fr'],
        'trello_geral'     => ['trello_atividade', '7fr 3fr'],
        'trello_atividade' => ['trello_geral',     '3fr 7fr'],
    ];
    $_active = array_values(array_filter($dashWidgets, fn ($k) => isset($_w[$k])));
    $_skip   = [];
    ?>

    <div class="content">
    <?php if (!$_active): ?>
      <div class="panel"><div class="empty-row">Nenhum widget ativo. <a href="/dashboard/widgets" style="color:#7db3ff;">Escolher widgets →</a></div></div>
    <?php endif; ?>
    <?php
    for ($_i = 0; $_i < count($_active); $_i++) {
        $_key = $_active[$_i];
        if (in_array($_key, $_skip, true)) continue;
        $_next = $_active[$_i + 1] ?? null;
        $_pair = $_pairs[$_key] ?? null;
        if ($_pair && $_next === $_pair[0]) {
            echo '<div class="dash-grid" style="grid-template-columns:' . $_pair[1] . ';">' . $_w[$_key] . $_w[$_next] . '</div>';
            $_skip[] = $_next;
        } else {
            echo $_w[$_key];
        }
    }
    ?>
    </div><!-- /content -->

  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
// ── Data no topbar ─────────────────────────────────────────────────────
(function () {
  const dias  = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
  const meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  const d = new Date();
  document.getElementById('topbar-date').textContent =
    dias[d.getDay()] + ', ' + d.getDate() + ' de ' + meses[d.getMonth()] + ' de ' + d.getFullYear();
})();

// ── Trello Dashboard ───────────────────────────────────────────────────
(function () {
  const API     = '/modules/trello/api.php';
  const boardId = <?= json_encode($trello_default_board) ?>;
  let _bid   = boardId;
  let _bname = '';

  const wrapGeral = document.getElementById('trello-geral-wrap');
  const wrapAtiv  = document.getElementById('trello-atividade-wrap');
  if (!wrapGeral && !wrapAtiv) return;

  if (!boardId) {
    fetch(API + '?action=my_boards').then(r => r.json()).then(boards => {
      if (Array.isArray(boards) && boards.length) {
        _bid   = boards[0].id;
        _bname = boards[0].name;
        init();
      } else {
        setErr('Trello não configurado.');
      }
    }).catch(() => setErr('Trello não configurado.'));
    return;
  }
  init();

  function init() {
    if (wrapGeral) loadGeral();
    if (wrapAtiv)  loadAtividade();
  }

  // ── Visão Geral ───────────────────────────────────────────────────
  function loadGeral() {
    fetch(API + '?action=board_stats&boardId=' + encodeURIComponent(_bid))
      .then(r => r.json())
      .then(d => {
        if (d.error) { setErr('Trello: ' + d.error, wrapGeral); return; }

        const overdueColor = d.overdue   > 0 ? 'var(--red)'   : 'var(--teal)';
        const todayColor   = d.due_today > 0 ? 'var(--amber)' : 'var(--text-muted)';

        let bars = '';
        (d.lists || []).forEach(l => {
          const w = d.total > 0 ? Math.round(l.count / d.total * 100) : 0;
          bars += `
            <div class="maq-bar-item">
              <div class="maq-bar-label">
                <span class="maq-bar-name">${escH(l.name)}</span>
                <span class="maq-bar-val">${l.count}</span>
              </div>
              <div class="maq-bar-bg">
                <div class="maq-bar-fill" style="width:${w}%;background:linear-gradient(90deg,#5a3fc0,#9b6dff);"></div>
              </div>
            </div>`;
        });

        wrapGeral.innerHTML = `
          <div class="panel" style="flex:1;">
            <div class="panel-header">
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="panel-title">${_bname ? escH(_bname) : 'Trello'} — Visão Geral</span>
                <span class="source-badge" style="background:rgba(155,109,255,.12);color:#9b6dff;">Trello</span>
              </div>
              <a class="panel-action" href="/trello?board=${encodeURIComponent(_bid)}">Ver board →</a>
            </div>
            <div class="trello-inner-grid">
              <div class="maq-bar-row">
                ${bars || '<p style="color:var(--text-muted);font-size:13px;">Nenhum card aberto.</p>'}
              </div>
              <div style="display:flex;flex-direction:column;border-left:1px solid var(--border);">
                <div style="padding:10px 14px;border-bottom:1px solid var(--border);">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;">Total</div>
                  <div style="font-size:18px;font-weight:700;">${d.total}</div>
                  <div style="font-size:11px;color:var(--text-muted);">cards em aberto</div>
                </div>
                <div style="padding:10px 14px;border-bottom:1px solid var(--border);">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;">Vencidos</div>
                  <div style="font-size:18px;font-weight:700;color:${overdueColor};">${d.overdue}</div>
                  <div style="font-size:11px;color:var(--text-muted);">${d.overdue > 0 ? 'prazo ultrapassado' : 'nenhum atrasado'}</div>
                </div>
                <div style="padding:10px 14px;">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;">Vencem Hoje</div>
                  <div style="font-size:18px;font-weight:700;color:${todayColor};">${d.due_today}</div>
                  <div style="font-size:11px;color:var(--text-muted);">com prazo hoje</div>
                </div>
              </div>
            </div>
          </div>`;
      })
      .catch(() => setErr('Erro ao carregar.', wrapGeral));
  }

  // ── Atividade ─────────────────────────────────────────────────────
  function loadAtividade() {
    fetch(API + '?action=board_actions&boardId=' + encodeURIComponent(_bid) + '&limit=30')
      .then(r => r.json())
      .then(actions => {
        let body = '';
        if (!Array.isArray(actions) || !actions.length) {
          body = '<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">Nenhum movimento registrado.</div>';
        } else {
          let rows = '';
          actions.forEach(a => {
            rows += `
              <div style="display:flex;align-items:flex-start;gap:12px;padding:11px 20px;border-bottom:1px solid var(--border);">
                <div style="margin-top:3px;flex-shrink:0;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#9b6dff" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="9 18 15 12 9 6"/>
                  </svg>
                </div>
                <div style="flex:1;min-width:0;">
                  <div style="font-size:13px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                       title="${escH(a.card_name)}">${escH(a.card_name)}</div>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                    <span style="color:var(--text-primary);">${escH(a.list_before)}</span>
                    <span style="margin:0 5px;color:#9b6dff;">→</span>
                    <span style="color:var(--teal);">${escH(a.list_after)}</span>
                  </div>
                  ${a.membro ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">por ${escH(a.membro)}</div>` : ''}
                </div>
                <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;">${tempoAtras(a.date)}</div>
              </div>`;
          });
          body = `<div style="overflow-y:auto;max-height:240px;">${rows}</div>`;
        }

        wrapAtiv.innerHTML = `
          <div class="panel" style="flex:1;">
            <div class="panel-header">
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="panel-title">${_bname ? escH(_bname) : 'Trello'} — Atividade</span>
                <span class="source-badge" style="background:rgba(155,109,255,.12);color:#9b6dff;">Trello</span>
              </div>
              <a class="panel-action" href="/trello?board=${encodeURIComponent(_bid)}">Ver board →</a>
            </div>
            ${body}
          </div>`;
      })
      .catch(() => setErr('Erro ao carregar atividade.', wrapAtiv));
  }

  // ── Helpers ───────────────────────────────────────────────────────
  function setErr(msg, wrap) {
    const targets = wrap ? [wrap] : [wrapGeral, wrapAtiv].filter(Boolean);
    targets.forEach(el => {
      el.innerHTML = `<div class="panel"><div style="padding:20px;color:var(--text-muted);font-size:13px;">${escH(msg)}</div></div>`;
    });
  }
  function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function tempoAtras(iso) {
    const min = Math.round((Date.now() - new Date(iso)) / 60000);
    if (min < 1)  return 'agora';
    if (min < 60) return `há ${min} min`;
    const h = Math.floor(min / 60);
    if (h  < 24) return `há ${h}h`;
    const d = Math.floor(h / 24);
    return d === 1 ? 'ontem' : `há ${d} dias`;
  }
})();

// ── Gráfico: produção (bags apontados) x saída de bags, 14 dias ───────
(function () {
  const ctx = document.getElementById('chartProdSaida');
  if (!ctx || typeof Chart === 'undefined') return;
  const serie = <?= json_encode($serie) ?>;
  const fmt = v => Number(v).toLocaleString('pt-BR');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: serie.labels,
      datasets: [
        { label: 'Produzido', data: serie.producao, backgroundColor: 'rgba(45,106,255,.55)', borderColor: '#2d6aff', borderWidth: 1, borderRadius: 4 },
        { label: 'Saída (bags)', data: serie.saida, backgroundColor: 'rgba(0,201,167,.6)', borderColor: '#00c9a7', borderWidth: 1, borderRadius: 4 },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#7a90b0', boxWidth: 12, font: { size: 11 } } },
        tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.raw) } }
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#7a90b0', font: { size: 11 } } },
        y: { grid: { color: 'rgba(255,255,255,.05)' }, ticks: { color: '#7a90b0', font: { size: 11 }, callback: v => v >= 1000 ? (v / 1000).toLocaleString('pt-BR') + 'k' : v } }
      }
    }
  });
})();
</script>
</body>
</html>
