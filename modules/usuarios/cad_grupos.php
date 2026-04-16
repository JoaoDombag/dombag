<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Cadastro de Grupos de Usuários
//  Operações: listar, criar, editar, excluir
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── Schema: cria tabela grupo_usuario se não existir ──────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grupo_usuario (
            GRU_CODIGO    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            GRU_NOME      VARCHAR(50)  NOT NULL,
            GRU_DESCRICAO VARCHAR(200) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable) {}

// ── Ações POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Criar grupo ───────────────────────────────────────────────────────────
    if ($acao === 'criar') {
        $nome = trim($_POST['nome'] ?? '');
        $desc = trim($_POST['descricao'] ?? '');
        if (!$nome) {
            $msg = 'Informe o nome do grupo.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->prepare('INSERT INTO grupo_usuario (GRU_NOME, GRU_DESCRICAO) VALUES (:n, :d)')
                    ->execute([':n' => $nome, ':d' => $desc ?: null]);
                $msg = 'Grupo <strong>' . htmlspecialchars($nome) . '</strong> criado com sucesso.';
                $msg_tipo = 'ok';
            } catch (PDOException $e) {
                $msg = 'Erro ao criar grupo: ' . htmlspecialchars($e->getMessage());
                $msg_tipo = 'err';
            }
        }
    }

    // ── Editar grupo ──────────────────────────────────────────────────────────
    if ($acao === 'editar') {
        $cod  = (int)($_POST['cod'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $desc = trim($_POST['descricao'] ?? '');
        if (!$cod || !$nome) {
            $msg = 'Preencha o nome do grupo.';
            $msg_tipo = 'err';
        } else {
            $pdo->prepare('UPDATE grupo_usuario SET GRU_NOME=:n, GRU_DESCRICAO=:d WHERE GRU_CODIGO=:c')
                ->execute([':n' => $nome, ':d' => $desc ?: null, ':c' => $cod]);
            $msg = 'Grupo atualizado.';
            $msg_tipo = 'ok';
        }
    }

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
                $pdo->prepare('DELETE FROM grupo_usuario WHERE GRU_CODIGO = :c')->execute([':c' => $cod]);
                try {
                    $pdo->prepare('DELETE FROM permissao_acesso WHERE PAC_GRU_CODIGO = :c')->execute([':c' => $cod]);
                } catch (Throwable) {}
                $msg = 'Grupo excluído.';
                $msg_tipo = 'ok';
            }
        }
    }
}

// ── Dados para edição ─────────────────────────────────────────────────────────
$editando = null;
if (isset($_GET['editar'])) {
    $s = $pdo->prepare('SELECT * FROM grupo_usuario WHERE GRU_CODIGO = :c');
    $s->execute([':c' => (int)$_GET['editar']]);
    $editando = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Lista de grupos com contagem de usuários ──────────────────────────────────
try {
    $grupos_db = $pdo->query('
        SELECT g.GRU_CODIGO, g.GRU_NOME, g.GRU_DESCRICAO,
               COUNT(u.USU_CODIGO) AS QTD_USUARIOS
        FROM grupo_usuario g
        LEFT JOIN USUARIOS u ON u.GRU_CODIGO = g.GRU_CODIGO
        GROUP BY g.GRU_CODIGO, g.GRU_NOME, g.GRU_DESCRICAO
        ORDER BY g.GRU_NOME
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $grupos_db = $pdo->query('SELECT *, 0 AS QTD_USUARIOS FROM grupo_usuario ORDER BY GRU_NOME')
                  ->fetchAll(PDO::FETCH_ASSOC);
}

$total = count($grupos_db);
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
.page-grid{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start;flex:1;min-height:0;}
@media(max-width:1100px){.page-grid{grid-template-columns:1fr;}}

.badge-edit{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(245,158,11,.12);color:var(--amber);display:none;}
.badge-edit.visible{display:inline-flex;align-items:center;gap:4px;}

.td-actions{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;border:1px solid transparent;text-decoration:none;}
.btn-sm-edit{background:rgba(45,106,255,.1);border-color:rgba(45,106,255,.2);color:#7db3ff;}
.btn-sm-edit:hover{background:rgba(45,106,255,.2);}
.btn-sm-perm{background:rgba(0,201,167,.08);border-color:rgba(0,201,167,.2);color:var(--teal);}
.btn-sm-perm:hover{background:rgba(0,201,167,.16);}
.btn-sm-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:var(--red);}
.btn-sm-danger:hover{background:rgba(239,68,68,.2);}

.count-users{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 6px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(45,106,255,.1);color:#7db3ff;}

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
        <button class="btn-primary" onclick="novoGrupo()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Novo Grupo
        </button>
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
            <div class="kpi-mini-val"><?= array_sum(array_column($grupos_db, 'QTD_USUARIOS')) ?></div>
            <div class="kpi-mini-lbl">Usuários vinculados</div>
          </div>
        </div>
      </div>

      <div class="page-grid">

        <!-- ─── FORMULÁRIO ─────────────────────────────────────────────── -->
        <div class="form-panel" id="formPanel">
          <div class="form-panel-head">
            <h2 id="formTitle">Novo Grupo</h2>
            <span class="badge-edit" id="badgeEdit">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Editando
            </span>
          </div>

          <form method="POST" id="frmGrupo" onsubmit="return validarForm()">
            <input type="hidden" name="acao" id="f_acao" value="criar">
            <input type="hidden" name="cod"  id="f_cod"  value="0">
            <div class="form-body">

              <div class="field">
                <label>Nome do Grupo *</label>
                <input type="text" name="nome" id="f_nome"
                       placeholder="Ex: Produção, Vendas, Financeiro..."
                       maxlength="50" required autocomplete="off">
              </div>

              <div class="field">
                <label>Descrição <span style="font-size:10px;font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;">(opcional)</span></label>
                <input type="text" name="descricao" id="f_desc"
                       placeholder="Breve descrição do grupo..."
                       maxlength="200" autocomplete="off">
              </div>

            </div>
            <div class="form-actions">
              <button type="submit" class="btn-primary" id="btnSalvar">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span id="btnTxt">Criar Grupo</span>
              </button>
              <button type="button" class="btn-secondary" id="btnCancelar"
                      onclick="novoGrupo()" style="display:none;">
                Cancelar
              </button>
            </div>
          </form>
        </div>

        <!-- ─── TABELA ─────────────────────────────────────────────────── -->
        <div class="panel-table">
          <div class="panel-header">
            <h2 style="font-size:14px;font-weight:600;">Grupos Cadastrados</h2>
            <span class="count-badge"><?= $total ?></span>
          </div>

          <?php if (empty($grupos_db)): ?>
          <div class="empty-state">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
            <p>Nenhum grupo cadastrado.<br>Crie o primeiro usando o formulário ao lado.</p>
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
              <tr id="row-g-<?= $g['GRU_CODIGO'] ?>">
                <td style="font-weight:600;"><?= htmlspecialchars($g['GRU_NOME']) ?></td>
                <td class="td-muted"><?= $g['GRU_DESCRICAO'] ? htmlspecialchars($g['GRU_DESCRICAO']) : '—' ?></td>
                <td class="td-center">
                  <span class="count-users"><?= (int)$g['QTD_USUARIOS'] ?></span>
                </td>
                <td>
                  <div class="td-actions">
                    <button class="btn-sm btn-sm-edit"
                            onclick="editarGrupo(<?= $g['GRU_CODIGO'] ?>, '<?= addslashes($g['GRU_NOME']) ?>', '<?= addslashes($g['GRU_DESCRICAO'] ?? '') ?>')">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      Editar
                    </button>
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

      </div><!-- /page-grid -->
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
<?php if ($editando): ?>
editarGrupo(
  <?= (int)$editando['GRU_CODIGO'] ?>,
  '<?= addslashes($editando['GRU_NOME']) ?>',
  '<?= addslashes($editando['GRU_DESCRICAO'] ?? '') ?>'
);
<?php endif; ?>

function novoGrupo() {
  document.getElementById('f_acao').value = 'criar';
  document.getElementById('f_cod').value  = '0';
  document.getElementById('f_nome').value = '';
  document.getElementById('f_desc').value = '';
  document.getElementById('formTitle').textContent = 'Novo Grupo';
  document.getElementById('btnTxt').textContent    = 'Criar Grupo';
  document.getElementById('badgeEdit').classList.remove('visible');
  document.getElementById('btnCancelar').style.display = 'none';
  document.querySelectorAll('[id^="row-g-"]').forEach(r => r.removeAttribute('style'));
  document.getElementById('f_nome').focus();
}

function editarGrupo(cod, nome, desc) {
  document.getElementById('f_acao').value = 'editar';
  document.getElementById('f_cod').value  = cod;
  document.getElementById('f_nome').value = nome;
  document.getElementById('f_desc').value = desc;
  document.getElementById('formTitle').textContent = 'Editar Grupo';
  document.getElementById('btnTxt').textContent    = 'Salvar Alterações';
  document.getElementById('badgeEdit').classList.add('visible');
  document.getElementById('btnCancelar').style.display = 'inline-flex';
  document.querySelectorAll('[id^="row-g-"]').forEach(r => r.removeAttribute('style'));
  const row = document.getElementById('row-g-' + cod);
  if (row) row.style.background = 'rgba(245,158,11,.06)';
  document.getElementById('formPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  document.getElementById('f_nome').focus();
}

function validarForm() {
  const nome = document.getElementById('f_nome').value.trim();
  if (!nome) { alert('Informe o nome do grupo.'); return false; }
  return true;
}

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
