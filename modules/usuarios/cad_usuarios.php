<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Cadastro / Edição de Usuário
//  GET  ?id=X  → modo edição
//  POST acao=criar  → INSERT
//  POST acao=editar → UPDATE
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── DDL mínimo: garante tabelas / colunas necessárias ────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS GRUPO_USUARIO (
            GRU_CODIGO    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            GRU_NOME      VARCHAR(50)  NOT NULL,
            GRU_DESCRICAO VARCHAR(200) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable) {}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM USUARIOS LIKE 'USU_PERFIL'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE USUARIOS ADD COLUMN USU_PERFIL VARCHAR(20) NOT NULL DEFAULT 'usuario' AFTER USU_NOME");
    }
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

// ── Grupos para o select ─────────────────────────────────────────────────────
$grupos = $pdo->query('SELECT GRU_CODIGO, GRU_NOME FROM GRUPO_USUARIO ORDER BY GRU_NOME')
              ->fetchAll(PDO::FETCH_ASSOC);

// ── Modo: edição ou criação ───────────────────────────────────────────────────
$id_editar = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$registro  = null;

if ($id_editar > 0) {
    $stmt = $pdo->prepare('SELECT * FROM USUARIOS WHERE USU_CODIGO = :c');
    $stmt->execute([':c' => $id_editar]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registro) {
        // ID inválido: redireciona para a lista
        header('Location: /usuarios');
        exit;
    }
}

$editando = ($registro !== null);

// ── Valores do formulário (reaproveita POST em caso de erro) ─────────────────
$f_login    = $registro['USU_LOGIN']   ?? '';
$f_nome     = $registro['USU_NOME']    ?? '';
$f_perfil   = $registro['USU_PERFIL']  ?? 'usuario';
$f_gru      = (int) ($registro['GRU_CODIGO'] ?? 0);

// ── Processamento POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Criar usuário ─────────────────────────────────────────────────────
    if ($acao === 'criar') {
        $f_login  = trim($_POST['login']   ?? '');
        $f_nome   = trim($_POST['nome']    ?? '');
        $f_perfil = in_array($_POST['perfil'] ?? '', ['admin','usuario']) ? $_POST['perfil'] : 'usuario';
        $f_gru    = (int) ($_POST['gru_codigo'] ?? 0) ?: null;
        $senha    = $_POST['senha']    ?? '';
        $conf     = $_POST['confirma'] ?? '';

        if (!$f_login || !$f_nome || !$senha) {
            $msg = 'Preencha todos os campos obrigatórios.';
            $msg_tipo = 'err';
        } elseif (strlen($f_login) > 25) {
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
                    ':login'  => $f_login,
                    ':senha'  => password_hash($senha, PASSWORD_DEFAULT),
                    ':nome'   => $f_nome,
                    ':perfil' => $f_perfil,
                    ':gru'    => $f_gru,
                ]);
                header('Location: /usuarios');
                exit;
            } catch (PDOException $e) {
                $msg = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Login "' . htmlspecialchars($f_login) . '" já está em uso. Escolha outro.'
                    : 'Erro ao criar usuário: ' . htmlspecialchars($e->getMessage());
                $msg_tipo = 'err';
            }
        }
    }

    // ── Editar usuário ────────────────────────────────────────────────────
    if ($acao === 'editar') {
        $cod      = (int) ($_POST['cod'] ?? 0);
        $f_login  = trim($_POST['login']   ?? '');
        $f_nome   = trim($_POST['nome']    ?? '');
        $f_perfil = in_array($_POST['perfil'] ?? '', ['admin','usuario']) ? $_POST['perfil'] : 'usuario';
        $f_gru    = (int) ($_POST['gru_codigo'] ?? 0) ?: null;

        if (!$cod || !$f_login || !$f_nome) {
            $msg = 'Preencha todos os campos obrigatórios.';
            $msg_tipo = 'err';
        } elseif (strlen($f_login) > 25) {
            $msg = 'O login deve ter no máximo 25 caracteres.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->prepare(
                    'UPDATE USUARIOS SET USU_LOGIN=:login, USU_NOME=:nome, USU_PERFIL=:perfil, GRU_CODIGO=:gru
                     WHERE USU_CODIGO=:cod'
                )->execute([
                    ':login'  => $f_login,
                    ':nome'   => $f_nome,
                    ':perfil' => $f_perfil,
                    ':gru'    => $f_gru,
                    ':cod'    => $cod,
                ]);
                header('Location: /usuarios');
                exit;
            } catch (PDOException $e) {
                $msg = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Login "' . htmlspecialchars($f_login) . '" já está em uso.'
                    : 'Erro ao atualizar: ' . htmlspecialchars($e->getMessage());
                $msg_tipo = 'err';
            }
        }
    }
}

$titulo_pagina = $editando
    ? 'Editando: ' . htmlspecialchars($registro['USU_NOME'])
    : 'Novo Usuário';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $editando ? 'Editar Usuário' : 'Novo Usuário' ?> | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ─── Formulário centralizado ──────────────────────────── */
.cad-wrap {
  display: flex;
  justify-content: center;
  padding: 8px 0 32px;
}
.cad-card {
  width: 100%;
  max-width: 520px;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}
.cad-head {
  padding: 18px 22px 14px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
}
.cad-head h2 { font-size: 14px; font-weight: 600; }
.cad-body { padding: 22px; display: flex; flex-direction: column; gap: 14px; }
.cad-foot { padding: 14px 22px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; }

/* Badge edição */
.badge-edit {
  font-size: 10.5px; font-weight: 700; padding: 3px 9px;
  border-radius: 20px; background: rgba(245,158,11,.12);
  color: var(--amber); display: inline-flex; align-items: center; gap: 4px;
}

/* Campos */
.field { display: flex; flex-direction: column; gap: 5px; }
.field label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .5px; color: var(--text-muted);
}
.field input, .field select {
  height: 40px; padding: 0 12px;
  background: rgba(255,255,255,.05);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text-primary);
  font-family: inherit; font-size: 13px; outline: none;
  transition: border-color .15s, background .15s;
}
.field input:focus, .field select:focus {
  border-color: rgba(45,106,255,.5);
  background: rgba(45,106,255,.06);
}
.field input::placeholder { color: var(--text-muted); }
.field select option { background: #112240; }

/* Nota informativa */
.field-note {
  font-size: 11px; color: var(--text-muted);
  margin-top: 2px; line-height: 1.5;
}

/* Bloco de senha */
#senhaBlock { display: flex; flex-direction: column; gap: 14px; }
.senha-aviso {
  font-size: 11.5px; color: var(--text-muted);
  background: rgba(245,158,11,.07);
  border: 1px solid rgba(245,158,11,.15);
  border-radius: 8px; padding: 10px 12px;
  display: flex; align-items: flex-start; gap: 8px;
  line-height: 1.5;
}

/* Barra de força */
.pwd-strength { height: 3px; border-radius: 2px; margin-top: 5px; transition: all .2s; }
.pwd-strength.weak   { background: var(--red);   width: 33%; }
.pwd-strength.medium { background: var(--amber); width: 66%; }
.pwd-strength.strong { background: var(--teal);  width: 100%; }
.pwd-label { font-size: 10.5px; margin-top: 4px; }
.pwd-label.weak   { color: var(--red); }
.pwd-label.medium { color: var(--amber); }
.pwd-label.strong { color: var(--teal); }

/* Select uppercase */
.field select { text-transform: uppercase; font-size: 12px; }
.field select option { text-transform: uppercase; }
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
          <p><?= $editando ? 'Atualize os dados do usuário' : 'Preencha os dados para criar um novo acesso' ?></p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/usuarios" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Ver Lista
        </a>
      </div>
    </header>

    <div class="content" style="overflow:auto;">

      <?php if ($msg): ?>
      <div class="alert <?= $msg_tipo === 'ok' ? 'alert-ok' : 'alert-err' ?>" id="alertMsg"
           style="max-width:520px;margin:0 auto 16px;">
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
          <div class="cad-head">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <?php if ($editando): ?>
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
              <?php else: ?>
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
              <?php endif; ?>
            </svg>
            <h2><?= $editando ? 'Dados do Usuário' : 'Novo Usuário' ?></h2>
            <?php if ($editando): ?>
            <span class="badge-edit">
              <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Editando
            </span>
            <?php endif; ?>
          </div>

          <form method="POST" id="frmCad" onsubmit="return validarForm()">
            <input type="hidden" name="acao" value="<?= $editando ? 'editar' : 'criar' ?>">
            <?php if ($editando): ?>
            <input type="hidden" name="cod"  value="<?= (int) $registro['USU_CODIGO'] ?>">
            <?php endif; ?>

            <div class="cad-body">

              <!-- Login -->
              <div class="field">
                <label>Login * <span style="font-size:10px;text-transform:none;letter-spacing:0;font-weight:400;">(máx. 25 caracteres)</span></label>
                <input type="text" name="login" id="f_login"
                       value="<?= htmlspecialchars($f_login) ?>"
                       placeholder="joao.silva" maxlength="25" autocomplete="off" required>
              </div>

              <!-- Nome -->
              <div class="field">
                <label>Nome Completo *</label>
                <input type="text" name="nome" id="f_nome"
                       value="<?= htmlspecialchars($f_nome) ?>"
                       placeholder="João Silva" maxlength="100" required>
              </div>

              <!-- Perfil -->
              <div class="field">
                <label>Perfil</label>
                <select name="perfil" id="f_perfil">
                  <option value="usuario" <?= $f_perfil !== 'admin' ? 'selected' : '' ?>>Usuário</option>
                  <option value="admin"   <?= $f_perfil === 'admin'  ? 'selected' : '' ?>>Administrador</option>
                </select>
              </div>

              <!-- Grupo -->
              <div class="field">
                <label>Grupo</label>
                <select name="gru_codigo" id="f_gru">
                  <option value="0">— Sem grupo —</option>
                  <?php foreach ($grupos as $g): ?>
                  <option value="<?= $g['GRU_CODIGO'] ?>"
                    <?= (int)$f_gru === (int)$g['GRU_CODIGO'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($g['GRU_NOME']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Bloco de senha: só aparece ao criar -->
              <?php if (!$editando): ?>
              <div id="senhaBlock">
                <div class="field">
                  <label>Senha * <span style="font-size:10px;text-transform:none;letter-spacing:0;font-weight:400;">(mín. 6 caracteres)</span></label>
                  <input type="password" name="senha" id="f_senha"
                         placeholder="••••••" autocomplete="new-password" required>
                  <div class="pwd-strength" id="pwdBar" style="display:none;"></div>
                  <span class="pwd-label" id="pwdLabel" style="display:none;"></span>
                </div>
                <div class="field">
                  <label>Confirmar Senha *</label>
                  <input type="password" name="confirma" id="f_conf"
                         placeholder="••••••" autocomplete="new-password" required>
                  <p id="conf_err" style="display:none;color:var(--red);font-size:11.5px;margin-top:3px;">
                    As senhas não coincidem.
                  </p>
                </div>
              </div>
              <?php else: ?>
              <div class="senha-aviso">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;margin-top:1px;color:var(--amber);"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Para redefinir a senha deste usuário, use o botão <strong>Senha</strong> na lista de usuários.</span>
              </div>
              <?php endif; ?>

            </div><!-- /cad-body -->

            <div class="cad-foot">
              <a href="/usuarios" class="btn-secondary">Cancelar</a>
              <button type="submit" class="btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?= $editando ? 'Salvar Alterações' : 'Criar Usuário' ?>
              </button>
            </div>
          </form>

        </div><!-- /cad-card -->
      </div><!-- /cad-wrap -->

    </div><!-- /content -->
  </div>
</div>

<script>
// ── Validação do formulário ───────────────────────────────────────────────────
function validarForm() {
  <?php if (!$editando): ?>
  const s = document.getElementById('f_senha')?.value ?? '';
  const c = document.getElementById('f_conf')?.value  ?? '';
  if (s.length < 6) { alert('A senha deve ter ao menos 6 caracteres.'); return false; }
  if (s !== c)      { alert('As senhas não coincidem.');                 return false; }
  <?php endif; ?>
  return true;
}

<?php if (!$editando): ?>
// ── Barra de força da senha ───────────────────────────────────────────────────
function avaliarForca(pwd) {
  const bar   = document.getElementById('pwdBar');
  const label = document.getElementById('pwdLabel');
  if (!bar) return;
  if (!pwd) { bar.style.display = 'none'; label.style.display = 'none'; return; }
  bar.style.display   = 'block';
  label.style.display = 'block';
  const score = (pwd.length >= 8 ? 1 : 0)
    + (/[A-Z]/.test(pwd) ? 1 : 0)
    + (/[0-9]/.test(pwd) ? 1 : 0)
    + (/[^a-zA-Z0-9]/.test(pwd) ? 1 : 0);
  const nivel = score <= 1 ? 'weak' : score <= 2 ? 'medium' : 'strong';
  const texto = { weak:'Fraco', medium:'Médio', strong:'Forte' };
  bar.className   = 'pwd-strength ' + nivel;
  label.className = 'pwd-label '    + nivel;
  label.textContent = 'Força: ' + texto[nivel];
}

document.getElementById('f_senha').addEventListener('input', function() {
  avaliarForca(this.value);
});

document.getElementById('f_conf').addEventListener('input', function() {
  const match = this.value === document.getElementById('f_senha').value;
  document.getElementById('conf_err').style.display = (this.value && !match) ? 'block' : 'none';
});
<?php endif; ?>

// ── Auto-oculta alerta de sucesso ────────────────────────────────────────────
const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity    = '0';
  setTimeout(() => alertEl.style.display = 'none', 400);
}, 4000);
</script>
</body>
</html>
