<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Cadastro de Grupos de Usuários
//  Operações: criar, editar
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── Schema mínimo ─────────────────────────────────────────────────────────────
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

    // ── Criar grupo ───────────────────────────────────────────────────────────
    if ($acao === 'criar') {
        $nome = trim($_POST['nome'] ?? '');
        $desc = trim($_POST['descricao'] ?? '');
        if (!$nome) {
            $msg = 'Informe o nome do grupo.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->prepare('INSERT INTO GRUPO_USUARIO (GRU_NOME, GRU_DESCRICAO) VALUES (:n, :d)')
                    ->execute([':n' => $nome, ':d' => $desc ?: null]);
                header('Location: /usuarios/grupos');
                exit;
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
            try {
                $pdo->prepare('UPDATE GRUPO_USUARIO SET GRU_NOME=:n, GRU_DESCRICAO=:d WHERE GRU_CODIGO=:c')
                    ->execute([':n' => $nome, ':d' => $desc ?: null, ':c' => $cod]);
                header('Location: /usuarios/grupos');
                exit;
            } catch (PDOException $e) {
                $msg = 'Erro ao atualizar grupo: ' . htmlspecialchars($e->getMessage());
                $msg_tipo = 'err';
            }
        }
    }
}

// ── Fetch registro para edição se ?id= presente ───────────────────────────────
$editando = null;
$id_get   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_get > 0) {
    $s = $pdo->prepare('SELECT * FROM GRUPO_USUARIO WHERE GRU_CODIGO = :c');
    $s->execute([':c' => $id_get]);
    $editando = $s->fetch(PDO::FETCH_ASSOC);
}

// Valores pré-preenchidos (após erro de validação POST numa edição)
$f_cod  = $editando['GRU_CODIGO']    ?? (int)($_POST['cod']      ?? 0);
$f_nome = $_POST['nome']             ?? ($editando['GRU_NOME']    ?? '');
$f_desc = $_POST['descricao']        ?? ($editando['GRU_DESCRICAO'] ?? '');
$f_acao = $editando ? 'editar' : 'criar';

$titulo_pagina = $editando
    ? 'Editando: ' . htmlspecialchars($editando['GRU_NOME'])
    : 'Novo Grupo';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $editando ? 'Editar Grupo' : 'Novo Grupo' ?> | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.cad-wrap{display:flex;justify-content:center;padding:8px 0;}
.cad-card{width:100%;max-width:480px;}
.form-panel .form-panel-head{display:flex;align-items:center;gap:10px;}
.badge-edit{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(245,158,11,.12);color:var(--amber);display:inline-flex;align-items:center;gap:4px;}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1><?= $titulo_pagina ?></h1>
          <p><?= $editando ? 'Altere os dados do grupo e clique em Salvar' : 'Preencha os dados para criar um novo grupo' ?></p>
        </div>
      </div>
      <div class="topbar-actions">
        <a class="btn-secondary" href="/usuarios/grupos">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
          Ver Lista
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

      <div class="cad-wrap">
        <div class="cad-card">
          <div class="form-panel">
            <div class="form-panel-head">
              <h2><?= $editando ? 'Editar Grupo' : 'Novo Grupo' ?></h2>
              <?php if ($editando): ?>
              <span class="badge-edit">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Editando
              </span>
              <?php endif; ?>
            </div>

            <form method="POST" onsubmit="return validarForm()">
              <input type="hidden" name="acao" value="<?= $f_acao ?>">
              <input type="hidden" name="cod"  value="<?= $f_cod ?>">

              <div class="form-body">

                <div class="field">
                  <label>Nome do Grupo *</label>
                  <input type="text" name="nome" id="f_nome"
                         value="<?= htmlspecialchars($f_nome) ?>"
                         placeholder="Ex: Produção, Vendas, Financeiro..."
                         maxlength="50" required autocomplete="off" autofocus>
                </div>

                <div class="field">
                  <label>Descrição <span style="font-size:10px;font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;">(opcional)</span></label>
                  <input type="text" name="descricao" id="f_desc"
                         value="<?= htmlspecialchars($f_desc) ?>"
                         placeholder="Breve descrição do grupo..."
                         maxlength="200" autocomplete="off">
                </div>

              </div>

              <div class="form-actions">
                <button type="submit" class="btn-primary">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  <?= $editando ? 'Salvar Alterações' : 'Criar Grupo' ?>
                </button>
                <a class="btn-secondary" href="/usuarios/grupos">Cancelar</a>
              </div>
            </form>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div>
</div>

<script>
function validarForm() {
  const nome = document.getElementById('f_nome').value.trim();
  if (!nome) { alert('Informe o nome do grupo.'); return false; }
  return true;
}

const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.style.display = 'none', 400);
}, 4000);
</script>
</body>
</html>
