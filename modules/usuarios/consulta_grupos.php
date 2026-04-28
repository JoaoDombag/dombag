<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Consulta de Grupos de Usuários
//  Operações: listar, excluir
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── Schema: cria tabela GRUPO_USUARIO se não existir ──────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS GRUPO_USUARIO (
            GRU_CODIGO    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            GRU_NOME      VARCHAR(50)  NOT NULL,
            GRU_DESCRICAO VARCHAR(200) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable) {}

// ── Ações POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Excluir grupo ─────────────────────────────────────────────────────────
    if ($acao === 'excluir') {
        $cod = (int)($_POST['cod'] ?? 0);
        if ($cod > 0) {
            $count = 0;
            try {
                $st = $pdo->prepare('SELECT COUNT(*) FROM USUARIOS WHERE GRU_CODIGO = :c');
                $st->execute([':c' => $cod]);
                $count = (int)$st->fetchColumn();
            } catch (Throwable) {}

            if ($count > 0) {
                $msg = "Não é possível excluir: este grupo possui {$count} usuário(s) vinculado(s).";
                $msg_tipo = 'err';
            } else {
                $pdo->prepare('DELETE FROM GRUPO_USUARIO WHERE GRU_CODIGO = :c')->execute([':c' => $cod]);
                try {
                    $pdo->prepare('DELETE FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :c')->execute([':c' => $cod]);
                } catch (Throwable) {}
                $msg = 'Grupo excluído com sucesso.';
                $msg_tipo = 'ok';
            }
        }
    }
}

// ── Lista de grupos com contagem de usuários ──────────────────────────────────
try {
    $grupos_db = $pdo->query('
        SELECT g.GRU_CODIGO, g.GRU_NOME, g.GRU_DESCRICAO,
               COUNT(u.USU_CODIGO) AS QTD_USUARIOS
        FROM GRUPO_USUARIO g
        LEFT JOIN USUARIOS u ON u.GRU_CODIGO = g.GRU_CODIGO
        GROUP BY g.GRU_CODIGO, g.GRU_NOME, g.GRU_DESCRICAO
        ORDER BY g.GRU_NOME
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $grupos_db = $pdo->query('SELECT *, 0 AS QTD_USUARIOS FROM GRUPO_USUARIO ORDER BY GRU_NOME')
                  ->fetchAll(PDO::FETCH_ASSOC);
}

$total           = count($grupos_db);
$total_usuarios  = array_sum(array_column($grupos_db, 'QTD_USUARIOS'));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grupos de Usuários | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.td-actions{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;border:1px solid transparent;text-decoration:none;}
.btn-sm-edit{background:rgba(45,106,255,.1);border-color:rgba(45,106,255,.2);color:#7db3ff;}
.btn-sm-edit:hover{background:rgba(45,106,255,.2);}
.btn-sm-perm{background:rgba(0,201,167,.08);border-color:rgba(0,201,167,.2);color:var(--teal);}
.btn-sm-perm:hover{background:rgba(0,201,167,.16);}
.btn-sm-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:var(--red);}
.btn-sm-danger:hover{background:rgba(239,68,68,.2);}

.count-users{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 6px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(45,106,255,.1);color:#7db3ff;}

.table-panel{background:var(--panel-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.table-panel .panel-header{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);}
.table-panel .panel-header h2{font-size:14px;font-weight:600;flex:1;}
.table-wrap{overflow-x:auto;}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:#152845;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:26px 26px 20px;width:440px;max-width:calc(100vw - 32px);transform:translateY(10px) scale(.98);transition:transform .2s;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1);}
.modal-box h3{font-size:15px;font-weight:600;margin-bottom:8px;}
.modal-box p{font-size:12.5px;color:var(--text-muted);line-height:1.55;margin-bottom:4px;}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end;}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Grupos de Usuários</h1>
          <p>Organize usuários em grupos para controlar permissões de acesso</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a class="btn-primary" href="/usuarios/grupos/cadastro">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Novo Grupo
        </a>
      </div>
    </header>

    <div class="content">

      <?php if ($msg): ?>
      <div class="alert <?= $msg_tipo === 'ok' ? 'alert-ok' : 'alert-err' ?>" id="alertMsg">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <?= $msg_tipo === 'ok'
            ? '<polyline points="20 6 9 17 4 12"/>'
            : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' ?>
        </svg>
        <?= $msg ?>
      </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-strip">
        <div class="kpi-mini c-blue">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= $total ?></div>
            <div class="kpi-mini-lbl">Grupos cadastrados</div>
          </div>
        </div>
        <div class="kpi-mini c-teal">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= $total_usuarios ?></div>
            <div class="kpi-mini-lbl">Usuários vinculados</div>
          </div>
        </div>
      </div>

      <!-- Tabela de Grupos -->
      <div class="table-panel">
        <div class="panel-header">
          <h2>Grupos Cadastrados</h2>
          <span class="count-badge"><?= $total ?></span>
        </div>

        <?php if (empty($grupos_db)): ?>
        <div class="empty-state">
          <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
          <p>Nenhum grupo cadastrado.<br>Clique em <strong>Novo Grupo</strong> para começar.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Nome do Grupo</th>
                <th>Descrição</th>
                <th class="td-center">Usuários</th>
                <th>Operações</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($grupos_db as $g): ?>
            <tr>
              <td style="font-weight:600;"><?= htmlspecialchars($g['GRU_NOME']) ?></td>
              <td class="td-muted"><?= $g['GRU_DESCRICAO'] ? htmlspecialchars($g['GRU_DESCRICAO']) : '—' ?></td>
              <td class="td-center">
                <span class="count-users"><?= (int)$g['QTD_USUARIOS'] ?></span>
              </td>
              <td>
                <div class="td-actions">
                  <a class="btn-sm btn-sm-edit"
                     href="/usuarios/grupos/cadastro?id=<?= $g['GRU_CODIGO'] ?>">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Editar
                  </a>
                  <a class="btn-sm btn-sm-perm"
                     href="/modules/usuarios/permissoes.php?gru=<?= $g['GRU_CODIGO'] ?>">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Permissões
                  </a>
                  <button class="btn-sm btn-sm-danger"
                          onclick="confirmarExclusao(<?= $g['GRU_CODIGO'] ?>, '<?= addslashes($g['GRU_NOME']) ?>', <?= (int)$g['QTD_USUARIOS'] ?>)">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
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

    </div><!-- /content -->
  </div>
</div>

<!-- ── Modal: Confirmar Exclusão ──────────────────────────────────────────── -->
<div class="modal-overlay" id="modalExclusao">
  <div class="modal-box">
    <h3>Confirmar Exclusão</h3>
    <p id="modal_excl_txt"></p>
    <form method="POST" id="frmExclusao">
      <input type="hidden" name="acao" value="excluir">
      <input type="hidden" name="cod"  id="modal_excl_cod" value="">
      <div class="modal-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal('modalExclusao')">Cancelar</button>
        <button type="submit" class="btn-danger" id="btnConfExcl">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Sim, excluir
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function confirmarExclusao(cod, nome, qtdUsuarios) {
  const txt = document.getElementById('modal_excl_txt');
  const btn = document.getElementById('btnConfExcl');
  if (qtdUsuarios > 0) {
    txt.innerHTML = 'O grupo <strong>' + nome + '</strong> possui <strong>' + qtdUsuarios +
      ' usuário(s)</strong> vinculado(s) e não pode ser excluído.';
    btn.style.display = 'none';
  } else {
    txt.innerHTML = 'Tem certeza que deseja excluir o grupo <strong>' + nome +
      '</strong>? Esta ação não pode ser desfeita.';
    btn.style.display = '';
  }
  document.getElementById('modal_excl_cod').value = cod;
  document.getElementById('modalExclusao').classList.add('open');
}

function fecharModal(id) {
  document.getElementById(id).classList.remove('open');
}

document.getElementById('modalExclusao').addEventListener('click', e => {
  if (e.target === e.currentTarget) fecharModal('modalExclusao');
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') fecharModal('modalExclusao');
});

const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.style.display = 'none', 400);
}, 4000);
</script>
</body>
</html>
