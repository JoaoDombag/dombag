<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/crm/leads_common.php';

$pdo = leadsPdo();
leadsEnsureSchema($pdo);

// ── Export CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $pdo->query('SELECT NOME,TELEFONE,EMAIL,EMPRESA,PRODUTO_INTERESSE,ORIGEM_LP,ORIGEM_BOTAO,UTM_SOURCE,UTM_MEDIUM,UTM_CAMPAIGN,STATUS_ATUAL,VENDEDOR,VALOR_VENDA,MOTIVO_PERDA,DATA_CRIACAO FROM LEADS_ADS ORDER BY DATA_CRIACAO DESC')->fetchAll(PDO::FETCH_ASSOC));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_webhook_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $r) fputcsv($out, $r, ';');
    }
    fclose($out);
    exit;
}

// ── Atualização via AJAX ──────────────────────────────────────────────────────
$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id       = (int) ($_POST['id'] ?? 0);
        $status   = leadsNormalizeStatus((string) ($_POST['status_atual'] ?? ''));
        $obs      = trim((string) ($_POST['motivo_perda'] ?? ''));
        $vendedor = trim((string) ($_POST['vendedor'] ?? ''));
        $valor    = trim((string) ($_POST['valor_venda'] ?? ''));
        $valorDb  = $valor === '' ? null : (float) str_replace(',', '.', $valor);

        if ($id <= 0 || !isset(LEADS_STATUS_OPTIONS[$status])) {
            echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']);
            exit;
        }

        $sql = 'UPDATE LEADS_ADS SET STATUS_ATUAL=:s, MOTIVO_PERDA=:m, VENDEDOR=:v, VALOR_VENDA=:vv, DATA_QUALIFICACAO=NOW()';
        if ($status === 'VENDA')    $sql .= ', DATA_VENDA_FECHADA = NOW()';
        if (in_array($status, ['PERDIDO','DESQUALIFICADO'], true)) $sql .= ', DATA_PERDA = NOW()';
        $sql .= ' WHERE ID=:id';

        $pdo->prepare($sql)->execute([':s' => $status, ':m' => $obs, ':v' => $vendedor, ':vv' => $valorDb, ':id' => $id]);
        echo json_encode(['ok' => true, 'msg' => 'Lead atualizado.', 'status' => $status, 'status_label' => leadsStatusLabel($status)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$f = [
    'status' => (string) ($_GET['status'] ?? ''),
    'busca'  => trim((string) ($_GET['busca'] ?? '')),
    'lp'     => trim((string) ($_GET['lp'] ?? '')),
];

$where  = [];
$params = [];
if ($f['status'] !== '' && isset(LEADS_STATUS_OPTIONS[leadsNormalizeStatus($f['status'])])) {
    $where[]          = 'STATUS_ATUAL = :status';
    $params['status'] = leadsNormalizeStatus($f['status']);
}
if ($f['lp'] !== '') {
    $where[]     = 'ORIGEM_LP = :lp';
    $params['lp'] = $f['lp'];
}
if ($f['busca'] !== '') {
    $where[]     = '(NOME LIKE :b OR TELEFONE LIKE :b OR EMAIL LIKE :b OR EMPRESA LIKE :b OR PRODUTO_INTERESSE LIKE :b OR VENDEDOR LIKE :b)';
    $params['b'] = '%' . $f['busca'] . '%';
}

$sql = 'SELECT * FROM LEADS_ADS';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY DATA_CRIACAO DESC, ID DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));

$stats = leadsCountByStatus($pdo);
$total = array_sum($stats);
$lps   = array_map(fn($o) => array_change_key_case($o, CASE_LOWER), $pdo->query('SELECT ORIGEM_LP, COUNT(*) qtd FROM LEADS_ADS GROUP BY ORIGEM_LP ORDER BY qtd DESC, ORIGEM_LP ASC')->fetchAll(PDO::FETCH_ASSOC));

$statusMap = [
    'NOVO'              => ['class' => 'sl-novo',   'label' => 'Novo'],
    'EM_ANALISE'        => ['class' => 'sl-analise','label' => 'Em análise'],
    'QUALIFICADO'       => ['class' => 'sl-ok',     'label' => 'Qualificado'],
    'DESQUALIFICADO'    => ['class' => 'sl-desc',   'label' => 'Desqualificado'],
    'CLIENTE_EXISTENTE' => ['class' => 'sl-exist',  'label' => 'Cliente existente'],
    'DUPLICADO'         => ['class' => 'sl-dup',    'label' => 'Duplicado'],
    'VENDA'             => ['class' => 'sl-venda',  'label' => 'Venda'],
    'PERDIDO'           => ['class' => 'sl-desc',   'label' => 'Perdido'],
];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leads do Webhook | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ── Status pills ── */
.sl-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.sl-novo   { background:rgba(45,106,255,.1);   color:#7db3ff; }
.sl-analise{ background:rgba(245,158,11,.1);   color:var(--amber); }
.sl-ok     { background:rgba(0,201,167,.1);    color:var(--teal); }
.sl-desc   { background:rgba(239,68,68,.1);    color:var(--red); }
.sl-exist  { background:rgba(167,139,250,.1);  color:var(--purple); }
.sl-dup    { background:rgba(255,255,255,.08); color:var(--text-muted); }
.sl-venda  { background:rgba(16,185,129,.12);  color:#10b981; }

/* ── KPI cards ── */
.kpi-row { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
.kpi-card { background:rgba(255,255,255,.025); border:1px solid var(--border); border-radius:14px; padding:18px 20px; display:flex; flex-direction:column; gap:4px; }
.kpi-card-num { font-size:26px; font-weight:800; color:var(--text-primary); }
.kpi-card-lbl { font-size:11px; color:var(--text-muted); }
.kpi-card.c-blue  .kpi-card-num { color:#7db3ff; }
.kpi-card.c-teal  .kpi-card-num { color:var(--teal); }
.kpi-card.c-green .kpi-card-num { color:#10b981; }
.kpi-card.c-red   .kpi-card-num { color:var(--red); }

/* ── Filter bar ── */
.filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:16px 18px; border-bottom:1px solid var(--border); }
.filter-bar .field { min-width:160px; }
.filter-bar .field label { font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px; }
.filter-bar input, .filter-bar select { height:36px; font-size:12px; }
.filter-actions { display:flex; gap:8px; align-items:flex-end; }

/* ── Leads table ── */
.leads-tbl { width:100%; border-collapse:collapse; }
.leads-tbl th { padding:9px 14px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); background:#112240; white-space:nowrap; position:sticky; top:0; z-index:2; }
.leads-tbl td { padding:12px 14px; font-size:12.5px; border-bottom:1px solid var(--border); vertical-align:top; color:var(--text-primary); }
.leads-tbl tbody tr:hover td { background:rgba(255,255,255,.025); }
.leads-tbl tbody tr.row-saving td { opacity:.6; }
.td-muted { color:var(--text-muted); font-size:11px; margin-top:3px; }

/* ── Qualificação inline ── */
.qual-wrap { display:flex; flex-direction:column; gap:6px; min-width:220px; }
.qual-select { width:100%; height:34px; padding:0 8px; background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:7px; color:var(--text-primary); font-family:inherit; font-size:12.5px; cursor:pointer; outline:none; }
.qual-input { width:100%; padding:6px 8px; background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:7px; color:var(--text-primary); font-family:inherit; font-size:12px; outline:none; }
.qual-obs { width:100%; min-height:52px; padding:6px 8px; background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:7px; color:var(--text-primary); font-family:inherit; font-size:12px; resize:none; outline:none; }
.btn-qual { height:30px; padding:0 12px; font-size:12px; font-weight:600; border-radius:6px; border:none; cursor:pointer; background:var(--blue-accent); color:#fff; font-family:inherit; display:inline-flex; align-items:center; gap:5px; }
.btn-qual:hover { background:var(--blue-light); }
.btn-qual:disabled { opacity:.6; cursor:wait; }
.save-msg { font-size:11px; display:none; margin-top:2px; }
.save-msg.ok  { color:var(--teal); }
.save-msg.err { color:var(--red); }

/* ── Empty ── */
.empty-state { padding:60px 20px; text-align:center; color:var(--text-muted); font-size:14px; }

@media(max-width:1100px) { .kpi-row { grid-template-columns:repeat(3,1fr); } }
@media(max-width:700px)  { .kpi-row { grid-template-columns:1fr 1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">

<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Leads do Webhook</h1>
      <p>Leads capturados nos formulários do site e enviados automaticamente.</p>
    </div>
  </div>
  <div class="topbar-actions">
    <a href="?export=csv" class="btn-secondary">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/>
        <line x1="12" y1="15" x2="12" y2="3"/>
      </svg>
      Exportar CSV
    </a>
  </div>
</header>

<div class="content">

  <!-- KPIs -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-card-num"><?= number_format($total, 0, ',', '.') ?></div>
      <div class="kpi-card-lbl">Total de leads</div>
    </div>
    <div class="kpi-card c-blue">
      <div class="kpi-card-num"><?= number_format($stats['NOVO'] ?? 0, 0, ',', '.') ?></div>
      <div class="kpi-card-lbl">Novos</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-card-num" style="color:var(--amber);"><?= number_format(($stats['EM_ANALISE'] ?? 0), 0, ',', '.') ?></div>
      <div class="kpi-card-lbl">Em análise</div>
    </div>
    <div class="kpi-card c-teal">
      <div class="kpi-card-num"><?= number_format($stats['QUALIFICADO'] ?? 0, 0, ',', '.') ?></div>
      <div class="kpi-card-lbl">Qualificados</div>
    </div>
    <div class="kpi-card c-green">
      <div class="kpi-card-num"><?= number_format($stats['VENDA'] ?? 0, 0, ',', '.') ?></div>
      <div class="kpi-card-lbl">Vendas fechadas</div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="panel" style="margin-bottom:20px;">
    <form method="GET" action="">
      <div class="filter-bar">
        <div class="field">
          <label>Busca</label>
          <input type="text" name="busca" value="<?= leadsH($f['busca']) ?>" placeholder="Nome, telefone, e-mail, empresa…">
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="">Todos</option>
            <?php foreach (LEADS_STATUS_OPTIONS as $k => $l): ?>
              <option value="<?= leadsH($k) ?>" <?= leadsNormalizeStatus($f['status']) === $k ? 'selected' : '' ?>><?= leadsH($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Landing Page</label>
          <select name="lp">
            <option value="">Todas</option>
            <?php foreach ($lps as $o): ?>
              <option value="<?= leadsH((string) $o['origem_lp']) ?>" <?= $f['lp'] === (string) $o['origem_lp'] ? 'selected' : '' ?>>
                <?= leadsH((string) $o['origem_lp']) ?> (<?= (int) $o['qtd'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-primary" style="height:36px;">Filtrar</button>
          <a href="qualificacao_leads.php" class="btn-secondary" style="height:36px;display:inline-flex;align-items:center;">Limpar</a>
        </div>
      </div>
    </form>
  </div>

  <!-- Tabela -->
  <div class="panel-table">
    <div class="panel-header">
      <span class="panel-title">Leads</span>
      <span class="count-badge"><?= count($rows) ?></span>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty-state">Nenhum lead encontrado<?= $f['busca'] || $f['status'] || $f['lp'] ? ' com os filtros aplicados.' : '.' ?></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="leads-tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Lead</th>
            <th>Origem</th>
            <th>Status</th>
            <th>Qualificar</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $st    = (string) ($r['status_atual'] ?? 'NOVO');
          $sInfo = $statusMap[$st] ?? ['class' => 'sl-novo', 'label' => $st];
          $rid   = (int) $r['id'];
        ?>
          <tr id="row-<?= $rid ?>">

            <!-- ID -->
            <td style="font-size:11px; color:var(--text-muted); white-space:nowrap;">
              #<?= $rid ?><br>
              <span style="font-size:10px;"><?= date('d/m/Y', strtotime((string) $r['data_criacao'])) ?></span><br>
              <span style="font-size:10px;"><?= date('H:i', strtotime((string) $r['data_criacao'])) ?></span>
            </td>

            <!-- Lead -->
            <td>
              <div style="font-weight:700; font-size:13px;">
                <?= $r['nome'] ? leadsH((string) $r['nome']) : '<span style="color:var(--text-muted)">Sem nome</span>' ?>
              </div>
              <?php if ($r['empresa']): ?>
                <div class="td-muted"><?= leadsH((string) $r['empresa']) ?></div>
              <?php endif; ?>
              <?php if ($r['produto_interesse']): ?>
                <div class="td-muted"><?= leadsH((string) $r['produto_interesse']) ?></div>
              <?php endif; ?>
              <?php if ($r['telefone']): ?>
                <div style="margin-top:5px; font-size:12.5px;"><?= leadsH(leadsPhoneForDisplay((string) $r['telefone'])) ?></div>
              <?php endif; ?>
              <?php if ($r['email']): ?>
                <div class="td-muted"><?= leadsH((string) $r['email']) ?></div>
              <?php endif; ?>
            </td>

            <!-- Origem -->
            <td>
              <?php if ($r['origem_lp']): ?>
                <div style="font-weight:600; font-size:12px;"><?= leadsH((string) $r['origem_lp']) ?></div>
              <?php endif; ?>
              <?php if ($r['origem_botao']): ?>
                <div class="td-muted">Botão: <?= leadsH((string) $r['origem_botao']) ?></div>
              <?php endif; ?>
              <?php if ($r['utm_campaign']): ?>
                <div class="td-muted">Campanha: <?= leadsH((string) $r['utm_campaign']) ?></div>
              <?php endif; ?>
              <?php if ($r['utm_source']): ?>
                <div class="td-muted"><?= leadsH((string) $r['utm_source']) ?><?= $r['utm_medium'] ? ' / ' . leadsH((string) $r['utm_medium']) : '' ?></div>
              <?php endif; ?>
              <?php if ($r['vendedor']): ?>
                <div class="td-muted" style="margin-top:4px;">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:middle;margin-right:2px;"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                  <?= leadsH((string) $r['vendedor']) ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- Status atual -->
            <td id="status-cell-<?= $rid ?>">
              <span class="sl-pill <?= $sInfo['class'] ?>"><?= leadsH($sInfo['label']) ?></span>
              <?php if ($r['motivo_perda']): ?>
                <div class="td-muted" style="max-width:160px; margin-top:4px;"><?= leadsH((string) $r['motivo_perda']) ?></div>
              <?php endif; ?>
              <?php if ($r['valor_venda']): ?>
                <div class="td-muted" style="margin-top:4px;">R$ <?= number_format((float) $r['valor_venda'], 2, ',', '.') ?></div>
              <?php endif; ?>
            </td>

            <!-- Qualificação inline -->
            <td>
              <div class="qual-wrap">
                <select class="qual-select" id="sel-<?= $rid ?>">
                  <?php foreach (LEADS_STATUS_OPTIONS as $k => $l): ?>
                    <option value="<?= leadsH($k) ?>" <?= $st === $k ? 'selected' : '' ?>><?= leadsH($l) ?></option>
                  <?php endforeach; ?>
                </select>
                <input class="qual-input" id="vend-<?= $rid ?>" placeholder="Vendedor" value="<?= leadsH((string) $r['vendedor']) ?>">
                <input class="qual-input" id="valor-<?= $rid ?>" placeholder="Valor venda (ex.: 12500,00)" value="<?= $r['valor_venda'] !== null ? leadsH((string) $r['valor_venda']) : '' ?>">
                <textarea class="qual-obs" id="obs-<?= $rid ?>" placeholder="Observação / motivo perda…"><?= leadsH((string) $r['motivo_perda']) ?></textarea>
                <button class="btn-qual" id="btn-<?= $rid ?>" onclick="salvarLead(<?= $rid ?>)">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  Salvar
                </button>
                <div class="save-msg" id="msg-<?= $rid ?>"></div>
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
async function salvarLead(id) {
  const btn   = document.getElementById('btn-' + id);
  const msg   = document.getElementById('msg-' + id);
  const sel   = document.getElementById('sel-' + id);
  const obs   = document.getElementById('obs-' + id);
  const vend  = document.getElementById('vend-' + id);
  const valor = document.getElementById('valor-' + id);
  const row   = document.getElementById('row-' + id);

  btn.disabled = true;
  btn.textContent = 'Salvando…';
  msg.style.display = 'none';
  row.classList.add('row-saving');

  const fd = new FormData();
  fd.append('id', id);
  fd.append('status_atual', sel.value);
  fd.append('motivo_perda', obs.value);
  fd.append('vendedor', vend.value);
  fd.append('valor_venda', valor.value);

  try {
    const res  = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
    const data = await res.json();

    if (data.ok) {
      const cell = document.getElementById('status-cell-' + id);
      if (cell) {
        const cls = {
          NOVO:'sl-novo', EM_ANALISE:'sl-analise', QUALIFICADO:'sl-ok',
          DESQUALIFICADO:'sl-desc', CLIENTE_EXISTENTE:'sl-exist',
          DUPLICADO:'sl-dup', VENDA:'sl-venda', PERDIDO:'sl-desc'
        }[data.status] || 'sl-novo';
        let html = `<span class="sl-pill ${cls}">${data.status_label}</span>`;
        if (obs.value.trim())   html += `<div class="td-muted" style="max-width:160px;margin-top:4px;">${obs.value.trim()}</div>`;
        if (valor.value.trim()) html += `<div class="td-muted" style="margin-top:4px;">R$ ${valor.value.trim()}</div>`;
        cell.innerHTML = html;
      }
      msg.className = 'save-msg ok';
      msg.textContent = '✓ Salvo';
    } else {
      msg.className = 'save-msg err';
      msg.textContent = '✗ ' + (data.msg || 'Erro');
    }
  } catch (e) {
    msg.className = 'save-msg err';
    msg.textContent = '✗ Falha na requisição';
  }

  msg.style.display = 'block';
  btn.disabled = false;
  btn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg> Salvar';
  row.classList.remove('row-saving');
  setTimeout(() => { msg.style.display = 'none'; }, 3000);
}
</script>
</body>
</html>
