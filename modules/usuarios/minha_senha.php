<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

$pdo      = dbPDO();
$msg      = '';
$msg_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova_senha  = $_POST['nova_senha']  ?? '';
    $confirma    = $_POST['confirma']    ?? '';

    if ($senha_atual === '' || $nova_senha === '' || $confirma === '') {
        $msg      = 'Preencha todos os campos.';
        $msg_tipo = 'err';
    } elseif ($nova_senha !== $confirma) {
        $msg      = 'A nova senha e a confirmação não coincidem.';
        $msg_tipo = 'err';
    } elseif (strlen($nova_senha) < 8) {
        $msg      = 'A nova senha deve ter pelo menos 8 caracteres.';
        $msg_tipo = 'err';
    } else {
        $st = $pdo->prepare('SELECT USU_SENHA FROM USUARIOS WHERE USU_CODIGO = :c');
        $st->execute([':c' => usuCodigo()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($senha_atual, $row['USU_SENHA'])) {
            $msg      = 'Senha atual incorreta.';
            $msg_tipo = 'err';
        } else {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE USUARIOS SET USU_SENHA = :s WHERE USU_CODIGO = :c')
                ->execute([':s' => $hash, ':c' => usuCodigo()]);
            $msg      = 'Senha alterada com sucesso.';
            $msg_tipo = 'ok';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Minha Senha | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.senha-card {
  max-width: 460px;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 28px 32px;
}
.field-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 18px;
}
.field-group label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: .05em;
}
.field-group input {
  background: rgba(255,255,255,.05);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 9px 13px;
  font-size: 13.5px;
  color: var(--text-primary);
  font-family: 'Segoe UI', system-ui, sans-serif;
  transition: border-color .15s;
  width: 100%;
}
.field-group input:focus {
  outline: none;
  border-color: var(--blue-accent);
}
.field-sep {
  border: none;
  border-top: 1px solid var(--border);
  margin: 22px 0;
}
.hint {
  font-size: 11.5px;
  color: var(--text-muted);
  margin-top: 3px;
}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="page-title">
        <h1>Minha Senha</h1>
        <p>Altere sua senha de acesso ao sistema</p>
      </div>
    </header>

    <div class="content">

      <?php if ($msg): ?>
      <div class="alert alert-<?= $msg_tipo ?>" id="alertMsg">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <?= $msg_tipo === 'ok'
            ? '<polyline points="20 6 9 17 4 12"/>'
            : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' ?>
        </svg>
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>

      <div class="senha-card">

        <div style="margin-bottom:22px;">
          <div style="font-size:13px;color:var(--text-muted);">
            Usuário: <strong style="color:var(--text-primary);"><?= htmlspecialchars(usuLogin()) ?></strong>
          </div>
        </div>

        <form method="POST">

          <div class="field-group">
            <label for="senha_atual">Senha atual</label>
            <input type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" required>
          </div>

          <hr class="field-sep">

          <div class="field-group">
            <label for="nova_senha">Nova senha</label>
            <input type="password" id="nova_senha" name="nova_senha" autocomplete="new-password" required>
            <span class="hint">Mínimo de 8 caracteres.</span>
          </div>

          <div class="field-group">
            <label for="confirma">Confirmar nova senha</label>
            <input type="password" id="confirma" name="confirma" autocomplete="new-password" required>
          </div>

          <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:6px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <rect x="3" y="11" width="18" height="11" rx="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            Alterar senha
          </button>

        </form>
      </div>

    </div><!-- /content -->
  </div>
</div>

<script>
const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.style.display = 'none', 400);
}, 5000);
</script>
</body>
</html>
