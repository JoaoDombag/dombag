<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════
//  DOMBAG — Consulta de Máquinas
//  Lista todas as máquinas; exclusão via POST.
// ══════════════════════════════════════════════════

$db_error  = '';
$db_ok_msg = '';
$maquinas  = [];

try {
    $pdo = dbPDO();

    // ── DDL / migrations ────────────────────────
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS DEPARTAMENTOS (
            dp_codigo    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dp_descricao VARCHAR(25) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $pdo->exec("
        INSERT IGNORE INTO DEPARTAMENTOS (dp_descricao) VALUES ('BAG'), ('SACARIA')
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS MAQUINAS (
            maq_codigo         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            maq_descricao      VARCHAR(120)  NOT NULL UNIQUE,
            maq_qtde           NUMERIC(6,2)  NOT NULL DEFAULT 1,
            maq_producao_min   NUMERIC(10,4) NOT NULL DEFAULT 0
                               COMMENT 'Unidades por minuto',
            maq_horas_dia      NUMERIC(5,2)  NOT NULL DEFAULT 8,
            dp_codigo          INT UNSIGNED  NOT NULL
                               COMMENT 'Departamento da máquina',
            maq_conta_producao TINYINT(1)    NOT NULL DEFAULT 1
                               COMMENT '1=conta no total; 0=processo intermediário',
            CONSTRAINT fk_maq_depto
                FOREIGN KEY (dp_codigo)
                REFERENCES DEPARTAMENTOS (dp_codigo)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Garante colunas adicionadas após a criação inicial da tabela
    $actualCols = array_map(
        'strtolower',
        $pdo->query('SHOW COLUMNS FROM MAQUINAS')->fetchAll(PDO::FETCH_COLUMN)
    );

    $missingCols = [
        'maq_qtde'           => "ALTER TABLE MAQUINAS ADD COLUMN maq_qtde NUMERIC(6,2) NOT NULL DEFAULT 1",
        'maq_producao_min'   => "ALTER TABLE MAQUINAS ADD COLUMN maq_producao_min NUMERIC(10,4) NOT NULL DEFAULT 0 COMMENT 'Unidades por minuto'",
        'maq_horas_dia'      => "ALTER TABLE MAQUINAS ADD COLUMN maq_horas_dia NUMERIC(5,2) NOT NULL DEFAULT 8",
        'maq_conta_producao' => "ALTER TABLE MAQUINAS ADD COLUMN maq_conta_producao TINYINT(1) NOT NULL DEFAULT 1",
        'maq_grupo'          => "ALTER TABLE MAQUINAS ADD COLUMN maq_grupo VARCHAR(80) NULL DEFAULT NULL",
    ];
    foreach ($missingCols as $col => $ddl) {
        if (!in_array($col, $actualCols)) {
            $pdo->exec($ddl);
        }
    }

    // ── Ação POST: excluir ───────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
        $cod = intval($_POST['maq_codigo'] ?? 0);
        if ($cod > 0) {
            try {
                $pdo->prepare('DELETE FROM MAQUINAS WHERE maq_codigo = :cod')
                    ->execute([':cod' => $cod]);
                $db_ok_msg = 'Máquina excluída com sucesso.';
            } catch (PDOException $e) {
                $db_error = 'Não é possível excluir: esta máquina possui registros vinculados.';
            }
        }
    }

    // ── Busca lista de máquinas ──────────────────
    $maquinas = array_map(
        fn($r) => array_change_key_case($r, CASE_LOWER),
        $pdo->query('
            SELECT m.*, d.dp_descricao
            FROM   MAQUINAS m
            INNER  JOIN DEPARTAMENTOS d ON d.dp_codigo = m.dp_codigo
            ORDER  BY m.maq_codigo
        ')->fetchAll(PDO::FETCH_ASSOC)
    );

} catch (PDOException $e) {
    $db_error = 'Erro no banco de dados: ' . $e->getMessage();
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
    .topbar-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

    /* Botões */
    .btn-primary { background: var(--blue-accent); color: #fff; border: none; padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; font-family: 'Segoe UI', sans-serif; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background .15s; }
    .btn-primary:hover { background: var(--blue-light); }
    .btn-secondary { background: transparent; border: 1px solid var(--border); color: var(--text-muted); padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 500; font-family: 'Segoe UI', sans-serif; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
    .btn-secondary:hover { background: var(--card-hover); color: var(--text-primary); }
    .btn-danger { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.25); color: var(--red); padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; font-family: 'Segoe UI', sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all .15s; }
    .btn-danger:hover { background: rgba(239,68,68,.22); }
    .btn-edit { background: rgba(45,106,255,.1); border: 1px solid rgba(45,106,255,.2); color: #7db3ff; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; font-family: 'Segoe UI', sans-serif; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: all .15s; }
    .btn-edit:hover { background: rgba(45,106,255,.2); }

    /* Content */
    .content { flex: 1; overflow: hidden; padding: 24px; display: flex; flex-direction: column; gap: 0; }

    /* Alertas */
    .alert { border-radius: 8px; padding: 10px 16px; font-size: 12.5px; display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
    .alert-err { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: var(--red); }
    .alert-ok  { background: rgba(0,201,167,.1);  border: 1px solid rgba(0,201,167,.2);  color: var(--teal); }

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
    .cod-badge  { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 20px; padding: 0 6px; border-radius: 5px; background: rgba(45,106,255,.12); color: #7db3ff; font-size: 11px; font-weight: 700; }
    .depto-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; background: rgba(167,139,250,.1); color: #a78bfa; }
    .grupo-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; background: rgba(16,185,129,.1); color: #34d399; }
    .desc-main { font-weight: 600; }
    .num-mono  { font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--text-muted); }
    .cap-pill  { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
    .cap-pill.ok   { background: rgba(0,201,167,.1);   color: var(--teal); }
    .cap-pill.zero { background: rgba(239,68,68,.1);   color: var(--red);  }
    .td-actions { display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .empty-state { text-align: center; padding: 56px 20px; color: var(--text-muted); }
    .empty-state p { font-size: 13px; }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 500; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
    .modal-overlay.open { opacity: 1; pointer-events: all; }
    .modal-confirm { background: #152845; border: 1px solid rgba(255,255,255,.1); border-radius: 14px; padding: 28px 28px 22px; width: 380px; max-width: calc(100vw - 40px); transform: translateY(10px) scale(.98); transition: transform .2s; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
    .modal-overlay.open .modal-confirm { transform: translateY(0) scale(1); }
    .modal-confirm h3 { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
    .modal-confirm p  { font-size: 12.5px; color: var(--text-muted); line-height: 1.5; }
    .modal-confirm-actions { display: flex; gap: 8px; margin-top: 22px; justify-content: flex-end; }
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
          <p>Visualize e gerencie as máquinas de produção</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/maquinas/cadastro" class="btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nova Máquina
        </a>
      </div>
    </header>

    <div class="content">

      <?php if ($db_error): ?>
      <div class="alert alert-err">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($db_error) ?>
      </div>
      <?php endif; ?>

      <?php if ($db_ok_msg): ?>
      <div class="alert alert-ok" id="alertOk">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($db_ok_msg) ?>
      </div>
      <?php endif; ?>

      <div class="table-panel">
        <div class="table-panel-head">
          <h2>Máquinas</h2>
          <span class="count-badge"><?= count($maquinas) ?> máquina<?= count($maquinas) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($maquinas)): ?>
        <div class="empty-state">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="display:block;margin:0 auto 12px;opacity:.2;">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
          </svg>
          <p>Nenhuma máquina cadastrada.<br>Clique em <strong>Nova Máquina</strong> para adicionar.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="maq-table">
            <thead>
              <tr>
                <th style="width:44px;">#</th>
                <th>Descrição</th>
                <th>Grupo PCP</th>
                <th>Depto</th>
                <th style="width:55px;text-align:center;">Qtde</th>
                <th style="width:95px;text-align:right;">Prod./min</th>
                <th style="width:85px;text-align:right;">Horas/dia</th>
                <th style="width:120px;text-align:center;">Cap. Diária</th>
                <th style="width:90px;text-align:center;">Conta total</th>
                <th style="width:110px;"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($maquinas as $m):
                $cap = ($m['maq_qtde'] ?? 1) * ($m['maq_producao_min'] ?? 0) * 60 * ($m['maq_horas_dia'] ?? 8);
            ?>
              <tr>
                <td><span class="cod-badge"><?= $m['maq_codigo'] ?></span></td>
                <td><span class="desc-main"><?= htmlspecialchars($m['maq_descricao']) ?></span></td>
                <td>
                  <?php if (!empty($m['maq_grupo'])): ?>
                    <span class="grupo-badge"><?= htmlspecialchars($m['maq_grupo']) ?></span>
                  <?php else: ?>
                    <span class="num-mono" style="font-size:11px;opacity:.5;">— usa descrição —</span>
                  <?php endif; ?>
                </td>
                <td><span class="depto-badge"><?= htmlspecialchars($m['dp_descricao']) ?></span></td>
                <td style="text-align:center;" class="num-mono"><?= number_format($m['maq_qtde'], 0, ',', '.') ?></td>
                <td style="text-align:right;"  class="num-mono"><?= number_format($m['maq_producao_min'], 4, ',', '.') ?></td>
                <td style="text-align:right;"  class="num-mono"><?= number_format($m['maq_horas_dia'], 1, ',', '.') ?></td>
                <td style="text-align:center;">
                  <span class="cap-pill <?= $cap > 0 ? 'ok' : 'zero' ?>">
                    <?= $cap > 0 ? number_format($cap, 0, ',', '.') . ' un' : 'Indefinida' ?>
                  </span>
                </td>
                <td style="text-align:center;">
                  <?php if ($m['maq_conta_producao']): ?>
                    <span class="cap-pill ok" title="Conta no total de produção">Sim</span>
                  <?php else: ?>
                    <span class="cap-pill zero" title="Processo intermediário — não duplica contagem">Não</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="td-actions">
                    <a class="btn-edit" href="/maquinas/cadastro?id=<?= $m['maq_codigo'] ?>">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      Editar
                    </a>
                    <button class="btn-danger" onclick="confirmarExclusao(
                      <?= $m['maq_codigo'] ?>,
                      <?= htmlspecialchars(json_encode($m['maq_descricao'])) ?>
                    )">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                      Excluir
                    </button>
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

<!-- Modal confirmação exclusão -->
<div class="modal-overlay" id="modalExclusao">
  <div class="modal-confirm">
    <h3>Confirmar exclusão</h3>
    <p id="modalExclusaoTexto">Tem certeza que deseja excluir esta máquina?</p>
    <form method="POST" id="frmExclusao">
      <input type="hidden" name="acao"       value="excluir">
      <input type="hidden" name="maq_codigo" id="excluir_codigo" value="">
      <div class="modal-confirm-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal()">Cancelar</button>
        <button type="submit" class="btn-danger">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Sim, excluir
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function confirmarExclusao(cod, desc) {
  document.getElementById('excluir_codigo').value = cod;
  document.getElementById('modalExclusaoTexto').innerHTML =
    'Tem certeza que deseja excluir <strong>' + desc + '</strong>?<br>' +
    '<span style="font-size:11.5px;color:var(--red);">Não será possível excluir se houver registros vinculados.</span>';
  document.getElementById('modalExclusao').classList.add('open');
}
function fecharModal() {
  document.getElementById('modalExclusao').classList.remove('open');
}
document.getElementById('modalExclusao').addEventListener('click', function(e) {
  if (e.target === this) fecharModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') fecharModal();
});

const alertOk = document.getElementById('alertOk');
if (alertOk) {
  setTimeout(function() {
    alertOk.style.transition = 'opacity .5s';
    alertOk.style.opacity    = '0';
    setTimeout(function() { alertOk.style.display = 'none'; }, 500);
  }, 4000);
}
</script>
</body>
</html>
