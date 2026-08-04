<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════
//  DOMBAG — Consulta de Máquinas
//  Fonte: PostgreSQL ERP Yzidro (maq_veic) — somente leitura
// ══════════════════════════════════════════════════

$db_error = '';
$maquinas = [];

$pg = dbPG();
if (!$pg) {
    $db_error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    $result = pg_query($pg, '
        SELECT mv_codigo
              ,mv_descricao
          FROM maq_veic
         ORDER BY mv_codigo
    ');
    if (!$result) {
        $db_error = 'Erro ao consultar máquinas: ' . pg_last_error($pg);
    } else {
        $maquinas = pg_fetch_all($result) ?: [];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Máquinas | DOMBAG</title>
  <link rel="stylesheet" href="/public/css/unified_admin.css">
  <link rel="icon" href="/public/css/icone.ico" type="image/png">
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--blue-deep); color: var(--text-primary); overflow: hidden; height: 100vh; }
    .app-wrapper { display: flex; height: 100vh; overflow: hidden; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

    /* Topbar */
    .topbar { padding: 14px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--blue-mid); flex-shrink: 0; gap: 12px; }
    .topbar-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .page-title h1 { font-size: 17px; font-weight: 600; letter-spacing: -.2px; }
    .page-title p  { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }

    /* Content */
    .content { flex: 1; overflow: hidden; padding: 24px; display: flex; flex-direction: column; gap: 0; }

    /* Alertas */
    .alert { border-radius: 8px; padding: 10px 16px; font-size: 12.5px; display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
    .alert-err { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: var(--red); }

    /* Table panel */
    .table-panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
    .table-panel-head { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .table-panel-head h2 { font-size: 14px; font-weight: 600; }
    .count-badge { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: rgba(45,106,255,.12); color: #7db3ff; }
    .table-wrap { overflow: auto; flex: 1; min-height: 0; }

    /* Tabela */
    .maq-table { width: 100%; border-collapse: collapse; }
    .maq-table th { padding: 9px 16px; text-align: left; font-size: 10px; font-weight: 700; color: var(--text-muted); letter-spacing: .7px; text-transform: uppercase; border-bottom: 1px solid var(--border); background: #112240; position: sticky; top: 0; z-index: 2; }
    .maq-table td { padding: 12px 16px; font-size: 13px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
    .maq-table tr:last-child td { border-bottom: none; }
    .maq-table tbody tr { transition: background .12s; }
    .maq-table tbody tr:hover td { background: rgba(255,255,255,.025); }
    .cod-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 20px; padding: 0 6px; border-radius: 5px; background: rgba(45,106,255,.12); color: #7db3ff; font-size: 11px; font-weight: 700; }
    .desc-main { font-weight: 600; }
    .empty-state { text-align: center; padding: 56px 20px; color: var(--text-muted); }
    .empty-state p { font-size: 13px; }

    @media (max-width: 768px) {
      body { overflow-x: hidden; overflow-y: auto; height: auto; }
      .app-wrapper { height: auto; min-height: 100vh; overflow: visible; }
      .main { height: auto; overflow: visible; padding-top: 52px; }
      .content { padding: 14px; overflow: visible; height: auto; display: block; }
      .topbar { padding: 10px 14px; }
      .table-panel { flex: none; overflow: visible; }
      .table-wrap { overflow-x: auto; max-height: none; }
    }
    @media (max-width: 480px) {
      .maq-table th { font-size: 9px; padding: 7px 8px; }
      .maq-table td { font-size: 11.5px; padding: 8px 8px; }
    }
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">

    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Máquinas Cadastradas</h1>
          <p>Consulta ao ERP — somente leitura</p>
        </div>
      </div>
    </header>

    <div class="content">

      <?php if ($db_error): ?>
      <div class="alert alert-err">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($db_error) ?>
      </div>
      <?php endif; ?>

      <div class="table-panel">
        <div class="table-panel-head">
          <h2>Máquinas</h2>
          <span class="count-badge"><?= count($maquinas) ?> registro<?= count($maquinas) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($maquinas)): ?>
        <div class="empty-state">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="display:block;margin:0 auto 12px;opacity:.2;">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
          </svg>
          <p>Nenhuma máquina encontrada no ERP.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="maq-table">
            <thead>
              <tr>
                <th style="width:60px;">#</th>
                <th>Descrição</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($maquinas as $m): ?>
              <tr>
                <td><span class="cod-badge"><?= htmlspecialchars($m['mv_codigo']) ?></span></td>
                <td><span class="desc-main"><?= htmlspecialchars($m['mv_descricao']) ?></span></td>
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
</body>
</html>
