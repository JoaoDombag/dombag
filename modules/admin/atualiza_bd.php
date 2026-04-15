<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════
//  DOMBAG — Console SQL (somente administradores)
// ══════════════════════════════════════════════════

try {
    $__pdo  = dbPDO();
    $__row  = $__pdo->prepare('SELECT USU_ADMIN FROM USUARIOS WHERE USU_CODIGO = :c');
    $__row->execute([':c' => (int)($_SESSION['usu_codigo'] ?? 0)]);
    if (!$__row->fetchColumn()) {
        http_response_code(403);
        include $_SERVER['DOCUMENT_ROOT'] . '/shared/403.php';
        exit;
    }
} catch (Throwable) {
    http_response_code(403);
    exit('Acesso negado.');
}

$pdo     = dbPDO();
$result  = null;   // ['type' => 'rows'|'affected'|'error', ...]
$sql_in  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sql_in = trim($_POST['sql'] ?? '');

    if ($sql_in === '') {
        $result = ['type' => 'error', 'msg' => 'Informe o SQL a executar.'];
    } else {
        try {
            $stmt = $pdo->query($sql_in);

            // Se retornou linhas (SELECT / SHOW / DESCRIBE / EXPLAIN …)
            if ($stmt && $stmt->columnCount() > 0) {
                $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $cols    = $rows ? array_keys($rows[0]) : [];
                $result  = ['type' => 'rows', 'cols' => $cols, 'rows' => $rows];
            } else {
                $affected = $stmt ? $stmt->rowCount() : $pdo->exec($sql_in);
                $result   = ['type' => 'affected', 'count' => (int)$affected];
            }
        } catch (PDOException $ex) {
            $result = ['type' => 'error', 'msg' => $ex->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Console SQL | DOMBAG</title>
  <link rel="stylesheet" href="/public/css/unified_admin.css">
  <style>
.content{display:flex;flex-direction:column;gap:18px;}

.warn-box{background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:11px 16px;font-size:12px;color:rgba(252,165,165,.85);display:flex;align-items:flex-start;gap:8px;line-height:1.5;}

.sql-textarea{
  width:100%;background:rgba(0,0,0,.3);border:1px solid var(--border);
  border-radius:8px;padding:14px 16px;font-size:13.5px;
  font-family:'Cascadia Code','Fira Code','Consolas',monospace;
  color:var(--text-primary);outline:none;resize:vertical;min-height:160px;
  transition:border-color .15s;line-height:1.6;
}
.sql-textarea:focus{border-color:rgba(45,106,255,.55);}
.sql-textarea::placeholder{color:var(--text-muted);}

.form-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.hint{font-size:11.5px;color:var(--text-muted);}

/* resultado */
.result-box{border-radius:10px;overflow:hidden;border:1px solid var(--border);}
.result-header{padding:10px 18px;display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;border-bottom:1px solid var(--border);}
.result-header.ok {background:rgba(0,201,167,.08);color:var(--teal);}
.result-header.err{background:rgba(239,68,68,.08);color:var(--red);}
.result-header.info{background:rgba(45,106,255,.08);color:#7db3ff;}
.result-body{overflow-x:auto;}

.res-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.res-table th{padding:9px 14px;text-align:left;font-size:10.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-muted);background:rgba(0,0,0,.15);border-bottom:1px solid var(--border);white-space:nowrap;}
.res-table td{padding:9px 14px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--text-primary);font-variant-numeric:tabular-nums;white-space:pre;}
.res-table tbody tr:last-child td{border-bottom:none;}
.res-table tbody tr:hover td{background:rgba(255,255,255,.025);}
.null-val{color:var(--text-muted);font-style:italic;}

.affected-num{font-size:28px;font-weight:700;letter-spacing:-1px;color:var(--teal);padding:20px 20px 8px;}
.affected-sub{font-size:12px;color:var(--text-muted);padding:0 20px 18px;}
.error-msg{padding:14px 18px;font-size:13px;font-family:'Cascadia Code','Fira Code',monospace;line-height:1.6;color:var(--red);}

.file-hint{font-size:11.5px;color:var(--text-muted);display:flex;align-items:center;gap:6px;}
.file-hint code{font-size:11px;background:rgba(255,255,255,.06);padding:2px 7px;border-radius:4px;color:var(--text-primary);}
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">

    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Console SQL</h1>
          <p>Execute SQL diretamente no banco de dados — somente administradores</p>
        </div>
      </div>
    </header>

    <div class="content">

      <!-- Aviso -->
      <div class="warn-box">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>Comandos executados aqui <strong>não são reversíveis</strong>. Para registar alterações de estrutura, guarde o SQL em <code>atualizabd.sql</code> na raiz do projeto.</span>
      </div>

      <!-- Formulário -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">SQL</span>
          <span class="file-hint">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            histórico em <code>atualizabd.sql</code>
          </span>
        </div>
        <div style="padding:20px;">
          <form method="POST" id="sqlForm">
            <textarea
              class="sql-textarea"
              name="sql"
              id="sqlInput"
              spellcheck="false"
              autocomplete="off"
              placeholder="SELECT * FROM maquinas LIMIT 10;
-- ou --
ALTER TABLE tabela ADD COLUMN coluna VARCHAR(100) NULL;"
            ><?= htmlspecialchars($sql_in) ?></textarea>

            <div class="form-row" style="margin-top:12px;">
              <button type="submit" class="btn-primary" id="btnRun">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Executar
              </button>
              <button type="button" class="btn-secondary" onclick="document.getElementById('sqlInput').value='';document.getElementById('sqlInput').focus();">
                Limpar
              </button>
              <span class="hint">Ctrl + Enter para executar</span>
            </div>
          </form>
        </div>
      </div>

      <!-- Resultado -->
      <?php if ($result): ?>
      <div class="result-box">

        <?php if ($result['type'] === 'error'): ?>
          <div class="result-header err">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            Erro ao executar
          </div>
          <div class="error-msg"><?= htmlspecialchars($result['msg']) ?></div>

        <?php elseif ($result['type'] === 'affected'): ?>
          <div class="result-header ok">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            Executado com sucesso
          </div>
          <div class="affected-num"><?= $result['count'] ?></div>
          <div class="affected-sub">linha<?= $result['count'] !== 1 ? 's' : '' ?> afetada<?= $result['count'] !== 1 ? 's' : '' ?></div>

        <?php elseif ($result['type'] === 'rows'): ?>
          <div class="result-header info">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
            <?= count($result['rows']) ?> linha<?= count($result['rows']) !== 1 ? 's' : '' ?> retornada<?= count($result['rows']) !== 1 ? 's' : '' ?>
          </div>
          <?php if (empty($result['rows'])): ?>
            <div class="affected-sub" style="padding:18px 20px;">Nenhum resultado.</div>
          <?php else: ?>
          <div class="result-body">
            <table class="res-table">
              <thead>
                <tr>
                  <?php foreach ($result['cols'] as $col): ?>
                  <th><?= htmlspecialchars($col) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                <tr>
                  <?php foreach ($row as $val): ?>
                  <td><?php if ($val === null): ?><span class="null-val">NULL</span><?php else: ?><?= htmlspecialchars((string)$val) ?><?php endif; ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
// Ctrl+Enter executa
document.getElementById('sqlInput').addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    e.preventDefault();
    document.getElementById('sqlForm').submit();
  }
});

// Foco automático no textarea
document.getElementById('sqlInput').focus();
// Move cursor para o fim
const ta = document.getElementById('sqlInput');
ta.selectionStart = ta.selectionEnd = ta.value.length;
</script>
</body>
</html>
