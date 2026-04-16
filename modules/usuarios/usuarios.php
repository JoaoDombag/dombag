<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Cadastro de Usuários
//  Operações: listar, criar, editar (nome + login), redefinir senha,
//             ativar/desativar, excluir
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── Garante que a tabela tem a coluna USU_PERFIL (pode não existir em versões antigas) ──
try {
    $cols = $pdo->query("SHOW COLUMNS FROM USUARIOS LIKE 'USU_PERFIL'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE USUARIOS ADD COLUMN USU_PERFIL VARCHAR(20) NOT NULL DEFAULT 'usuario' AFTER USU_NOME");
    }
} catch (Throwable) {
}

// ── Garante tabela grupo_usuario e coluna GRU_CODIGO em USUARIOS ──────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grupo_usuario (
            GRU_CODIGO    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            GRU_NOME      VARCHAR(50)  NOT NULL,
            GRU_DESCRICAO VARCHAR(200) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable) {}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM USUARIOS LIKE 'GRU_CODIGO'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE USUARIOS ADD COLUMN GRU_CODIGO INT UNSIGNED NULL AFTER USU_PERFIL");
    }
} catch (Throwable) {}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM USUARIOS LIKE 'USU_ATIVO'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE USUARIOS ADD COLUMN USU_ATIVO TINYINT(1) NOT NULL DEFAULT 1 AFTER GRU_CODIGO");
    }
} catch (Throwable) {}

// ── AJAX: toggle ativo ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_toggle'])) {
    header('Content-Type: application/json');
    $cod = (int) ($_POST['cod'] ?? 0);
    $ativo = (int) ($_POST['ativo'] ?? 0);
    if ($cod > 0) {
        $pdo->prepare('UPDATE USUARIOS SET USU_ATIVO = :a WHERE USU_CODIGO = :c')
            ->execute([':a' => $ativo, ':c' => $cod]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ── Ações POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Criar usuário ──────────────────────────────────────────────────────
    if ($acao === 'criar') {
        $login = trim($_POST['login'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $perfil = in_array($_POST['perfil'] ?? '', ['admin', 'usuario']) ? $_POST['perfil'] : 'usuario';
        $senha = $_POST['senha'] ?? '';
        $conf = $_POST['confirma'] ?? '';
        $gru_codigo = (int)($_POST['gru_codigo'] ?? 0) ?: null;

        if (!$login || !$nome || !$senha) {
            $msg = 'Preencha todos os campos obrigatórios.';
            $msg_tipo = 'err';
        } elseif (strlen($login) > 25) {
            $msg = 'O login deve ter no máximo 25 caracteres.';
            $msg_tipo = 'err';
        } elseif (strlen($senha) < 6) {
            $msg = 'A senha deve ter ao menos 6 caracteres.';
            $msg_tipo = 'err';
        } elseif ($senha !== $conf) {
            $msg = 'As senhas não coincidem.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO USUARIOS (USU_LOGIN, USU_SENHA, USU_NOME, USU_PERFIL, GRU_CODIGO)
                     VALUES (:login, :senha, :nome, :perfil, :gru)'
                )->execute([
                    ':login' => $login,
                    ':senha' => password_hash($senha, PASSWORD_DEFAULT),
                    ':nome' => $nome,
                    ':perfil' => $perfil,
                    ':gru' => $gru_codigo,
                ]);
                $msg = 'Usuário <strong>' . htmlspecialchars($login) . '</strong> criado com sucesso.';
                $msg_tipo = 'ok';
            } catch (PDOException $e) {
                $msg = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Login "' . htmlspecialchars($login) . '" já está em uso. Escolha outro.'
                    : 'Erro ao criar usuário: ' . htmlspecialchars($e->getMessage());
                $msg_tipo = 'err';
            }
        }
    }

    // ── Editar usuário (nome + login + perfil + grupo) ─────────────────────────
    if ($acao === 'editar') {
        $cod = (int) ($_POST['cod'] ?? 0);
        $login = trim($_POST['login'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $perfil = in_array($_POST['perfil'] ?? '', ['admin', 'usuario']) ? $_POST['perfil'] : 'usuario';
        $gru_codigo = (int)($_POST['gru_codigo'] ?? 0) ?: null;

        if (!$cod || !$login || !$nome) {
            $msg = 'Preencha todos os campos.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->prepare(
                    'UPDATE USUARIOS SET USU_LOGIN=:login, USU_NOME=:nome, USU_PERFIL=:perfil, GRU_CODIGO=:gru
                     WHERE USU_CODIGO=:cod'
                )->execute([':login' => $login, ':nome' => $nome, ':perfil' => $perfil, ':gru' => $gru_codigo, ':cod' => $cod]);
                $msg = 'Usuário atualizado.';
                $msg_tipo = 'ok';
            } catch (PDOException $e) {
                $msg = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Login "' . htmlspecialchars($login) . '" já está em uso.'
                    : 'Erro: ' . htmlspecialchars($e->getMessage());
                $msg_tipo = 'err';
            }
        }
    }

    // ── Redefinir senha ────────────────────────────────────────────────────
    if ($acao === 'senha') {
        $cod = (int) ($_POST['cod'] ?? 0);
        $senha = $_POST['nova_senha'] ?? '';
        $conf = $_POST['conf_senha'] ?? '';

        if (!$cod || !$senha) {
            $msg = 'Informe a nova senha.';
            $msg_tipo = 'err';
        } elseif (strlen($senha) < 6) {
            $msg = 'A senha deve ter ao menos 6 caracteres.';
            $msg_tipo = 'err';
        } elseif ($senha !== $conf) {
            $msg = 'As senhas não coincidem.';
            $msg_tipo = 'err';
        } else {
            $pdo->prepare('UPDATE USUARIOS SET USU_SENHA=:s WHERE USU_CODIGO=:c')
                ->execute([':s' => password_hash($senha, PASSWORD_DEFAULT), ':c' => $cod]);
            $msg = 'Senha redefinida com sucesso.';
            $msg_tipo = 'ok';
        }
    }

    // ── Excluir ────────────────────────────────────────────────────────────
    if ($acao === 'excluir') {
        $cod = (int) ($_POST['cod'] ?? 0);
        if ($cod > 0 && $cod !== usuCodigo()) {
            $pdo->prepare('DELETE FROM USUARIOS WHERE USU_CODIGO=:c')->execute([':c' => $cod]);
            $msg = 'Usuário excluído.';
            $msg_tipo = 'ok';
        } elseif ($cod === usuCodigo()) {
            $msg = 'Você não pode excluir o próprio usuário.';
            $msg_tipo = 'err';
        }
    }
}

// ── Dados para edição ─────────────────────────────────────────────────────────
$editando = null;
if (isset($_GET['editar'])) {
    $s = $pdo->prepare('SELECT * FROM USUARIOS WHERE USU_CODIGO=:c');
    $s->execute([':c' => (int) $_GET['editar']]);
    $editando = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Lista de usuários ─────────────────────────────────────────────────────────
$usuarios = $pdo->query(
    'SELECT u.USU_CODIGO, u.USU_LOGIN, u.USU_NOME, u.USU_PERFIL, u.USU_ATIVO,
            u.GRU_CODIGO, g.GRU_NOME
     FROM USUARIOS u
     LEFT JOIN grupo_usuario g ON g.GRU_CODIGO = u.GRU_CODIGO
     ORDER BY u.USU_NOME'
)->fetchAll(PDO::FETCH_ASSOC);

$total = count($usuarios);
$admins = count(array_filter($usuarios, fn ($u) => ($u['USU_PERFIL'] ?? '') === 'admin'));

// ── Lista de grupos para o formulário ────────────────────────────────────────
$grupos_select = $pdo->query('SELECT GRU_CODIGO, GRU_NOME FROM grupo_usuario ORDER BY GRU_NOME')
                     ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Usuários | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ─── Exclusivos desta página ─────────────────────── */
.page-grid{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start;flex:1;min-height:0;}
@media(max-width:1100px){.page-grid{grid-template-columns:1fr;}}

/* Badges e pills */
.perfil-admin{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(167,139,250,.12);color:#a78bfa;}
.perfil-usuario{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(255,255,255,.06);color:var(--text-muted);}
.status-ativo{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(0,201,167,.1);color:var(--teal);}
.status-inativo{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(239,68,68,.1);color:var(--red);}
.usu-avatar{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex-shrink:0;color:#fff;}
.td-actions{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;border:1px solid transparent;}
.btn-sm-edit{background:rgba(45,106,255,.1);border-color:rgba(45,106,255,.2);color:#7db3ff;}
.btn-sm-edit:hover{background:rgba(45,106,255,.2);}
.btn-sm-pwd{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.2);color:var(--amber);}
.btn-sm-pwd:hover{background:rgba(245,158,11,.2);}
.btn-sm-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:var(--red);}
.btn-sm-danger:hover{background:rgba(239,68,68,.2);}

/* Toggle switch */
.toggle-wrap{display:flex;align-items:center;gap:6px;}
.toggle{position:relative;width:36px;height:20px;flex-shrink:0;}
.toggle input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:rgba(255,255,255,.12);border-radius:20px;cursor:pointer;transition:.2s;}
.toggle-slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s;}
.toggle input:checked + .toggle-slider{background:var(--teal);}
.toggle input:checked + .toggle-slider::before{transform:translateX(16px);}

/* Badge do form */
.badge-edit{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(245,158,11,.12);color:var(--amber);display:none;}
.badge-edit.visible{display:inline-flex;align-items:center;gap:4px;}

/* Senha strength */
.pwd-strength{height:3px;border-radius:2px;margin-top:5px;transition:all .2s;}
.pwd-strength.weak   {background:var(--red);   width:33%;}
.pwd-strength.medium {background:var(--amber); width:66%;}
.pwd-strength.strong {background:var(--teal);  width:100%;}

/* Modais */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:#152845;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:26px 26px 20px;width:440px;max-width:calc(100vw - 32px);transform:translateY(10px) scale(.98);transition:transform .2s;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1);}
.modal-box h3{font-size:15px;font-weight:600;margin-bottom:8px;}
.modal-box p{font-size:12.5px;color:var(--text-muted);line-height:1.55;margin-bottom:4px;}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end;}

/* Pw modal fields */
.modal-field{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
.modal-field label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);}
.modal-field input{height:38px;padding:0 12px;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-family:inherit;font-size:13px;outline:none;}
.modal-field input:focus{border-color:rgba(45,106,255,.5);background:rgba(45,106,255,.06);}
@media(max-width:768px){.page-grid{grid-template-columns:1fr;}.orders-panel{overflow-x:auto;}}
@media(max-width:480px){.kpi-strip{grid-template-columns:1fr 1fr;}.td-actions{flex-wrap:wrap;}}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Cadastro de Usuários</h1>
          <p>Gerencie os acessos ao sistema DOMBAG</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn-primary" onclick="novoUsuario()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Novo Usuário
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
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= $total ?></div>
            <div class="kpi-mini-lbl">Total de usuários</div>
          </div>
        </div>
        <div class="kpi-mini c-teal">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= 3 ?></div>
            <div class="kpi-mini-lbl">Ativos</div>
          </div>
        </div>
        <div class="kpi-mini c-red">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= 0 ?></div>
            <div class="kpi-mini-lbl">Inativos</div>
          </div>
        </div>
        <div class="kpi-mini c-amber">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= $admins ?></div>
            <div class="kpi-mini-lbl">Administradores</div>
          </div>
        </div>
      </div>

      <div class="page-grid">

        <!-- ─── FORMULÁRIO ─────────────────────────────────────────────── -->
        <div class="form-panel" id="formPanel">
          <div class="form-panel-head">
            <h2 id="formTitle">Novo Usuário</h2>
            <span class="badge-edit" id="badgeEdit">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Editando
            </span>
          </div>

          <form method="POST" id="frmUsuario" onsubmit="return validarForm()">
            <input type="hidden" name="acao"    id="f_acao"   value="criar">
            <input type="hidden" name="cod"     id="f_cod"    value="0">
            <div class="form-body">

              <div class="field">
                <label>Login * <span style="font-size:10px;color:var(--text-muted);text-transform:none;letter-spacing:0;font-weight:400;">(máx. 25 caracteres)</span></label>
                <input type="text" name="login" id="f_login" placeholder="joao.silva"
                       maxlength="25" autocomplete="off" required>
              </div>

              <div class="field">
                <label>Nome Completo *</label>
                <input type="text" name="nome" id="f_nome" placeholder="João Silva"
                       maxlength="50" required>
              </div>

              <div class="field">
                <label>Perfil</label>
                <select name="perfil" id="f_perfil">
                  <option value="usuario">Usuário padrão</option>
                  <option value="admin">Administrador</option>
                </select>
              </div>

              <div class="field">
                <label>Grupo</label>
                <select name="gru_codigo" id="f_gru">
                  <option value="0">— Sem grupo —</option>
                  <?php foreach ($grupos_select as $g): ?>
                  <option value="<?= $g['GRU_CODIGO'] ?>"><?= htmlspecialchars($g['GRU_NOME']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Campos de senha: visíveis ao criar, ocultos ao editar -->
              <div id="senhaBlock">
                <div class="field">
                  <label>Senha * <span style="font-size:10px;color:var(--text-muted);text-transform:none;letter-spacing:0;font-weight:400;">(mín. 6 caracteres)</span></label>
                  <input type="password" name="senha" id="f_senha"
                         placeholder="••••••" autocomplete="new-password">
                  <div class="pwd-strength" id="pwdBar" style="display:none;"></div>
                </div>
                <div class="field">
                  <label>Confirmar Senha *</label>
                  <input type="password" name="confirma" id="f_conf"
                         placeholder="••••••" autocomplete="new-password">
                  <p id="conf_err" style="display:none;color:var(--red);font-size:11.5px;margin-top:3px;">As senhas não coincidem.</p>
                </div>
              </div>

            </div>
            <div class="form-actions">
              <button type="submit" class="btn-primary" id="btnSalvar">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span id="btnTxt">Criar Usuário</span>
              </button>
              <button type="button" class="btn-secondary" id="btnCancelar"
                      onclick="novoUsuario()" style="display:none;">
                Cancelar
              </button>
            </div>
          </form>
        </div>

        <!-- ─── TABELA ─────────────────────────────────────────────────── -->
        <div class="panel-table">
          <div class="panel-header">
            <h2 style="font-size:14px;font-weight:600;">Usuários Cadastrados</h2>
            <span class="count-badge"><?= $total ?></span>
          </div>

          <?php if (empty($usuarios)): ?>
          <div class="empty-state">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <p>Nenhum usuário cadastrado.<br>Crie o primeiro usando o formulário ao lado.</p>
          </div>
          <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Usuário</th>
                  <th>Login</th>
                  <th>Perfil</th>
                  <th>Grupo</th>
                  <th>Operações</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($usuarios as $u):
                  // Avatar com inicial e cor baseada no código
                  $cores = ['#2d6aff', '#00c9a7', '#f59e0b', '#a78bfa', '#ef4444', '#38bdf8'];
                  $cor = $cores[$u['USU_CODIGO'] % count($cores)];
                  $ini = mb_strtoupper(mb_substr($u['USU_NOME'], 0, 1));
                  $isSelf = ($u['USU_CODIGO'] === usuCodigo());
                  $isAdmin = ($u['USU_PERFIL'] ?? '') === 'admin';
                  ?>
              <tr id="row-u-<?= $u['USU_CODIGO'] ?>">
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div class="usu-avatar" style="background:<?= $cor ?>20;color:<?= $cor ?>;">
                      <?= $ini ?>
                    </div>
                    <div>
                      <div style="font-weight:600;font-size:13px;">
                        <?= htmlspecialchars($u['USU_NOME']) ?>
                        <?php if ($isSelf): ?>
                          <span style="font-size:10px;color:var(--text-muted);font-weight:400;">(você)</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="td-muted"><?= htmlspecialchars($u['USU_LOGIN']) ?></td>
                <td>
                  <?= $isAdmin
                        ? '<span class="perfil-admin"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Admin</span>'
                        : '<span class="perfil-usuario">Usuário</span>' ?>
                </td>
                <td class="td-muted">
                  <?= $u['GRU_NOME'] ? htmlspecialchars($u['GRU_NOME']) : '—' ?>
                </td>
                <td class="td-center">
                  <div class="toggle-wrap" style="justify-content:center;">
                    </label>
                  </div>
                </td>
                <td>
                  <div class="td-actions">
                    <button class="btn-sm btn-sm-edit"
                            onclick="editarUsuario(<?= $u['USU_CODIGO'] ?>, '<?= addslashes($u['USU_LOGIN']) ?>', '<?= addslashes($u['USU_NOME']) ?>', '<?= $u['USU_PERFIL'] ?? 'usuario' ?>', <?= (int)($u['GRU_CODIGO'] ?? 0) ?>)">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      Editar
                    </button>
                    <button class="btn-sm btn-sm-pwd"
                            onclick="abrirSenha(<?= $u['USU_CODIGO'] ?>, '<?= addslashes($u['USU_NOME']) ?>')">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                      Senha
                    </button>
                    <?php if (!$isSelf): ?>
                    <button class="btn-sm btn-sm-danger"
                            onclick="confirmarExclusao(<?= $u['USU_CODIGO'] ?>, '<?= addslashes($u['USU_NOME']) ?>')">
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                      Excluir
                    </button>
                    <?php endif; ?>
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

<!-- ── Modal: Redefinir Senha ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalSenha">
  <div class="modal-box">
    <h3>Redefinir Senha</h3>
    <p id="modal_senha_nome" style="margin-bottom:14px;"></p>
    <form method="POST" id="frmSenha" onsubmit="return validarSenhaModal()">
      <input type="hidden" name="acao" value="senha">
      <input type="hidden" name="cod"  id="modal_senha_cod" value="">
      <div class="modal-field">
        <label>Nova Senha *</label>
        <input type="password" name="nova_senha" id="modal_nova_senha"
               placeholder="mín. 6 caracteres" autocomplete="new-password">
        <div class="pwd-strength" id="pwdBarModal" style="display:none;"></div>
      </div>
      <div class="modal-field">
        <label>Confirmar Senha *</label>
        <input type="password" name="conf_senha" id="modal_conf_senha"
               placeholder="repita a senha" autocomplete="new-password">
        <p id="modal_conf_err" style="display:none;color:var(--red);font-size:11.5px;margin-top:3px;">As senhas não coincidem.</p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal('modalSenha')">Cancelar</button>
        <button type="submit" class="btn-primary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Salvar Senha
        </button>
      </div>
    </form>
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
        <button type="submit" class="btn-sm btn-sm-danger" style="padding:8px 14px;font-size:13px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Sim, excluir
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Formulário principal ─────────────────────────────────────────────────────
function novoUsuario() {
  document.getElementById('f_acao').value   = 'criar';
  document.getElementById('f_cod').value    = '0';
  document.getElementById('f_login').value  = '';
  document.getElementById('f_nome').value   = '';
  document.getElementById('f_perfil').value = 'usuario';
  document.getElementById('f_gru').value    = '0';
  document.getElementById('f_senha').value  = '';
  document.getElementById('f_conf').value   = '';
  document.getElementById('f_login').readOnly = false;
  document.getElementById('senhaBlock').style.display = '';
  document.getElementById('f_senha').required  = true;
  document.getElementById('f_conf').required   = true;
  document.getElementById('formTitle').textContent = 'Novo Usuário';
  document.getElementById('btnTxt').textContent    = 'Criar Usuário';
  document.getElementById('badgeEdit').classList.remove('visible');
  document.getElementById('btnCancelar').style.display = 'none';
  document.querySelectorAll('[id^="row-u-"]').forEach(r => r.removeAttribute('style'));
  document.getElementById('f_login').focus();
}

function editarUsuario(cod, login, nome, perfil, gru) {
  document.getElementById('f_acao').value   = 'editar';
  document.getElementById('f_cod').value    = cod;
  document.getElementById('f_login').value  = login;
  document.getElementById('f_nome').value   = nome;
  document.getElementById('f_perfil').value = perfil;
  document.getElementById('f_gru').value    = gru || 0;
  // Oculta bloco de senha ao editar (usa modal separado)
  document.getElementById('senhaBlock').style.display = 'none';
  document.getElementById('f_senha').required  = false;
  document.getElementById('f_conf').required   = false;
  document.getElementById('formTitle').textContent = 'Editar Usuário';
  document.getElementById('btnTxt').textContent    = 'Salvar Alterações';
  document.getElementById('badgeEdit').classList.add('visible');
  document.getElementById('btnCancelar').style.display = 'inline-flex';
  document.querySelectorAll('[id^="row-u-"]').forEach(r => r.removeAttribute('style'));
  const row = document.getElementById('row-u-'+cod);
  if (row) row.style.background = 'rgba(245,158,11,.06)';
  document.getElementById('formPanel').scrollIntoView({behavior:'smooth',block:'start'});
  document.getElementById('f_nome').focus();
}

function validarForm() {
  const acao = document.getElementById('f_acao').value;
  if (acao === 'criar') {
    const s = document.getElementById('f_senha').value;
    const c = document.getElementById('f_conf').value;
    if (s.length < 6) { alert('A senha deve ter ao menos 6 caracteres.'); return false; }
    if (s !== c) { alert('As senhas não coincidem.'); return false; }
  }
  return true;
}

// ── Força do password (barra visual) ────────────────────────────────────────
function avaliarForca(pwd, barId) {
  const bar = document.getElementById(barId);
  if (!bar) return;
  if (!pwd) { bar.style.display='none'; return; }
  bar.style.display = 'block';
  const score = (pwd.length >= 8 ? 1 : 0)
    + (/[A-Z]/.test(pwd) ? 1 : 0)
    + (/[0-9]/.test(pwd) ? 1 : 0)
    + (/[^a-zA-Z0-9]/.test(pwd) ? 1 : 0);
  bar.className = 'pwd-strength ' + (score <= 1 ? 'weak' : score <= 2 ? 'medium' : 'strong');
}

document.getElementById('f_senha').addEventListener('input', function(){
  avaliarForca(this.value, 'pwdBar');
});
document.getElementById('f_conf').addEventListener('input', function(){
  const match = this.value === document.getElementById('f_senha').value;
  document.getElementById('conf_err').style.display = (this.value && !match) ? 'block' : 'none';
});

// ── Toggle ativo (AJAX) ──────────────────────────────────────────────────────
async function toggleAtivo(cod, ativo) {
  const fd = new FormData();
  fd.append('ajax_toggle', '1');
  fd.append('cod', cod);
  fd.append('ativo', ativo ? '1' : '0');
  const res = await fetch(window.location.href, { method:'POST', body:fd });
  const data = await res.json();
  if (data.ok) {
    const el = document.getElementById('status-'+cod);
    if (el) el.textContent = ativo ? 'Ativo' : 'Inativo';
  }
}

// ── Modal Senha ──────────────────────────────────────────────────────────────
function abrirSenha(cod, nome) {
  document.getElementById('modal_senha_cod').value = cod;
  document.getElementById('modal_senha_nome').textContent = 'Redefinir senha de: ' + nome;
  document.getElementById('modal_nova_senha').value = '';
  document.getElementById('modal_conf_senha').value = '';
  document.getElementById('modal_conf_err').style.display = 'none';
  document.getElementById('pwdBarModal').style.display = 'none';
  document.getElementById('modalSenha').classList.add('open');
  setTimeout(() => document.getElementById('modal_nova_senha').focus(), 100);
}

document.getElementById('modal_nova_senha').addEventListener('input', function(){
  avaliarForca(this.value, 'pwdBarModal');
});
document.getElementById('modal_conf_senha').addEventListener('input', function(){
  const match = this.value === document.getElementById('modal_nova_senha').value;
  document.getElementById('modal_conf_err').style.display = (this.value && !match) ? 'block' : 'none';
});

function validarSenhaModal() {
  const s = document.getElementById('modal_nova_senha').value;
  const c = document.getElementById('modal_conf_senha').value;
  if (s.length < 6) { alert('A senha deve ter ao menos 6 caracteres.'); return false; }
  if (s !== c) { alert('As senhas não coincidem.'); return false; }
  return true;
}

// ── Modal Exclusão ───────────────────────────────────────────────────────────
function confirmarExclusao(cod, nome) {
  document.getElementById('modal_excl_cod').value = cod;
  document.getElementById('modal_excl_txt').innerHTML =
    'Deseja excluir o usuário <strong>' + nome + '</strong>? Esta ação não pode ser desfeita.';
  document.getElementById('modalExclusao').classList.add('open');
}

// ── Fecha modais ─────────────────────────────────────────────────────────────
function fecharModal(id) { document.getElementById(id).classList.remove('open'); }

['modalSenha','modalExclusao'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target === e.currentTarget) fecharModal(id);
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { fecharModal('modalSenha'); fecharModal('modalExclusao'); }
});

// ── Auto-oculta alerta de sucesso ────────────────────────────────────────────
const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.style.display='none', 400);
}, 4000);

<?php if ($editando): ?>
editarUsuario(
  <?= (int) $editando['USU_CODIGO'] ?>,
  '<?= addslashes($editando['USU_LOGIN']) ?>',
  '<?= addslashes($editando['USU_NOME']) ?>',
  '<?= addslashes($editando['USU_PERFIL'] ?? 'usuario') ?>',
  <?= (int)($editando['GRU_CODIGO'] ?? 0) ?>
);
<?php endif; ?>
</script>
</body>
</html>