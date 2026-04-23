<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// Apenas admins
$pdo = dbPDO();
$row = $pdo->prepare('SELECT USU_ADMIN FROM USUARIOS WHERE USU_CODIGO = :c');
$row->execute([':c' => (int)($_SESSION['usu_codigo'] ?? 0)]);
if (!$row->fetchColumn()) {
    http_response_code(403);
    include $_SERVER['DOCUMENT_ROOT'] . '/shared/403.php';
    exit;
}

define('MIGRATIONS_DIR', $_SERVER['DOCUMENT_ROOT'] . '/migrations');

// ── Dados ─────────────────────────────────────────────────────────────────────

// Registros aplicados com seu log de SQL
$registros = [];
try {
    $st = $pdo->query('
        SELECT m.mig_id, m.mig_executado_em, s.mig_sql
        FROM DB_MIGRATIONS m
        LEFT JOIN DB_MIGRATIONS_SQL s ON s.mig_id = m.mig_id
        ORDER BY m.mig_executado_em
    ');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $registros[$r['mig_id']] = $r;
    }
} catch (Throwable) {}

// Arquivos PHP na pasta /migrations/
$files = is_dir(MIGRATIONS_DIR) ? (glob(MIGRATIONS_DIR . '/*.php') ?: []) : [];
sort($files);

$migrations   = [];
foreach ($files as $f) {
    $id = basename($f, '.php');
    $reg = $registros[$id] ?? null;
    $log = $reg ? json_decode($reg['mig_sql'] ?? '[]', true) : null;
    $migrations[] = [
        'id'      => $id,
        'applied' => $reg !== null,
        'em'      => $reg['mig_executado_em'] ?? null,
        'log'     => $log ?: [],
    ];
}

// Migrations registradas mas sem arquivo (removidas ou renomeadas)
$idsArquivos = array_column($migrations, 'id');
foreach ($registros as $id => $reg) {
    if (!in_array($id, $idsArquivos, true)) {
        $log = json_decode($reg['mig_sql'] ?? '[]', true);
        $migrations[] = [
            'id'       => $id,
            'applied'  => true,
            'em'       => $reg['mig_executado_em'],
            'log'      => $log ?: [],
            'sem_arquivo' => true,
        ];
    }
}

$pendingCount = count(array_filter($migrations, fn($m) => !$m['applied']));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Migrations | DOMBAG</title>
  <link rel="stylesheet" href="/public/css/unified_admin.css">
  <link rel="icon" href="/public/css/icone.ico" type="image/png">
  <style>
.content{display:flex;flex-direction:column;gap:18px;}

.info-box{background:rgba(45,106,255,.07);border:1px solid rgba(45,106,255,.2);border-radius:8px;padding:11px 16px;font-size:12px;color:rgba(125,179,255,.9);display:flex;align-items:flex-start;gap:8px;line-height:1.6;}
.info-box code{background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px;font-family:'Cascadia Code','Fira Code',monospace;font-size:11px;}

/* Badge */
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.3px;}
.badge-ok      {background:rgba(0,201,167,.12);color:var(--teal);}
.badge-pend    {background:rgba(251,191,36,.10);color:#fbbf24;}
.badge-missing {background:rgba(239,68,68,.10);color:var(--red);}

/* Lista */
.mig-list{display:flex;flex-direction:column;gap:0;}
.mig-row{border-bottom:1px solid rgba(255,255,255,.04);}
.mig-row:last-child{border-bottom:none;}

.mig-header{display:flex;align-items:center;gap:12px;padding:13px 18px;cursor:pointer;user-select:none;transition:background .12s;}
.mig-header:hover{background:rgba(255,255,255,.025);}

.mig-name{flex:1;font-size:13px;font-family:'Cascadia Code','Fira Code','Consolas',monospace;color:var(--text-primary);}
.mig-date{font-size:11.5px;color:var(--text-muted);flex-shrink:0;}

.mig-chevron{width:14px;height:14px;flex-shrink:0;color:var(--text-muted);transition:transform .2s;}
.mig-row.open .mig-chevron{transform:rotate(180deg);}

/* Log de SQL */
.mig-log{display:none;padding:0 18px 14px 18px;}
.mig-row.open .mig-log{display:block;}

.log-item{display:flex;align-items:flex-start;gap:10px;padding:7px 0;border-top:1px solid rgba(255,255,255,.04);font-size:12px;}
.log-item:first-child{border-top:none;}
.log-status{flex-shrink:0;font-size:10px;font-weight:700;letter-spacing:.4px;padding:2px 7px;border-radius:4px;margin-top:1px;}
.log-status.ok   {background:rgba(0,201,167,.12);color:var(--teal);}
.log-status.skip {background:rgba(255,255,255,.06);color:var(--text-muted);}
.log-status.err  {background:rgba(239,68,68,.1);color:var(--red);}
.log-sql{flex:1;font-family:'Cascadia Code','Fira Code',monospace;font-size:11.5px;color:var(--text-primary);line-height:1.5;word-break:break-all;}
.log-msg{font-size:11px;color:var(--text-muted);margin-top:2px;}

.empty-state{padding:32px;text-align:center;color:var(--text-muted);font-size:13px;}

.topbar-right{display:flex;align-items:center;gap:10px;}
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">

    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Migrations</h1>
          <p><?= count($migrations) ?> migration<?= count($migrations) !== 1 ? 's' : '' ?> —
             <?= $pendingCount ?> pendente<?= $pendingCount !== 1 ? 's' : '' ?></p>
        </div>
      </div>
    </header>

    <div class="content">

      <div class="info-box">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>
          Crie arquivos <code>.php</code> em <code>/migrations/</code> com nome no formato
          <code>AAAA_MM_DD_NN_descricao.php</code>. Use as funções
          <code>adicionartabela()</code>, <code>adicionarcampotb()</code>, <code>removercampotb()</code> e <code>executarsql()</code>.
          As constantes de tipo disponíveis são: <code>TP_TINYINT</code>, <code>TP_INT</code>,
          <code>TP_VARCHAR100</code>, <code>TP_TEXT</code>, <code>TP_DATETIME</code>, entre outras.
          Migrations pendentes rodam <strong>automaticamente no próximo login</strong>.
        </span>
      </div>

      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Arquivos em /migrations/</span>
          <span style="font-size:11.5px;color:var(--text-muted)"><?= count($migrations) ?> total</span>
        </div>

        <?php if (empty($migrations)): ?>
          <div class="empty-state">
            Nenhum arquivo <code>.php</code> encontrado em <code>/migrations/</code>.
          </div>
        <?php else: ?>
        <div class="mig-list">
          <?php foreach ($migrations as $m): ?>
          <div class="mig-row" id="row-<?= htmlspecialchars($m['id']) ?>">

            <div class="mig-header" onclick="toggleRow('<?= htmlspecialchars($m['id']) ?>')">
              <span class="mig-name"><?= htmlspecialchars($m['id']) ?>.php</span>

              <?php if (!empty($m['sem_arquivo'])): ?>
                <span class="badge badge-missing">sem arquivo</span>
              <?php elseif ($m['applied']): ?>
                <span class="badge badge-ok">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  Aplicada
                </span>
              <?php else: ?>
                <span class="badge badge-pend">Pendente</span>
              <?php endif; ?>

              <?php if ($m['em']): ?>
                <span class="mig-date"><?= date('d/m/Y H:i', strtotime($m['em'])) ?></span>
              <?php endif; ?>

              <?php if (!empty($m['log'])): ?>
              <svg class="mig-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
              <?php endif; ?>
            </div>

            <?php if (!empty($m['log'])): ?>
            <div class="mig-log">
              <?php foreach ($m['log'] as $entry): ?>
              <div class="log-item">
                <span class="log-status <?= htmlspecialchars($entry['status']) ?>">
                  <?= strtoupper(htmlspecialchars($entry['status'])) ?>
                </span>
                <div>
                  <div class="log-sql"><?= htmlspecialchars($entry['sql']) ?></div>
                  <?php if (!empty($entry['msg'])): ?>
                    <div class="log-msg"><?= htmlspecialchars($entry['msg']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
function toggleRow(id) {
  const row = document.getElementById('row-' + id);
  if (row) row.classList.toggle('open');
}
</script>
</body>
</html>
