<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/migrations.php';

$__sess_lifetime = 30 * 24 * 60 * 60;
session_set_cookie_params([
    'lifetime' => $__sess_lifetime,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string) $__sess_lifetime);
session_start();

// Garante que o schema existe antes de qualquer query
rodarMigrations();

// Já logado? Redireciona
if (!empty($_SESSION['usu_codigo'])) {
    header('Location: /dashboard');
    exit;
}

$erro = '';
$login_val = '';

// ── Token CSRF ────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Processa POST ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $erro = 'Requisição inválida. Recarregue a página.';
    } else {
        // Conta tentativas (anti brute-force simples)
        $_SESSION['login_tent'] = ($_SESSION['login_tent'] ?? 0) + 1;

        if ($_SESSION['login_tent'] > 8) {
            $erro = 'Muitas tentativas incorretas. Aguarde alguns minutos.';
        } else {
            $login = trim($_POST['login'] ?? '');
            $senha = $_POST['senha'] ?? '';
            $login_val = htmlspecialchars($login);

            if ($login === '' || $senha === '') {
                $erro = 'Preencha usuário e senha.';
            } else {
                try {
                    $pdo = dbPDO();
                    $stmt = $pdo->prepare(
                        'SELECT USU_CODIGO, USU_NOME, USU_SENHA
                         FROM USUARIOS WHERE USU_LOGIN = :login LIMIT 1'
                    );
                    $stmt->execute([':login' => $login]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && password_verify($senha, $user['USU_SENHA'])) {
                        session_regenerate_id(true);
                        $_SESSION['usu_codigo'] = $user['USU_CODIGO'];
                        $_SESSION['usu_nome'] = $user['USU_NOME'];
                        $_SESSION['usu_login'] = $login;
                        $_SESSION['login_tent'] = 0;
                        unset($_SESSION['csrf_token']);
                        header('Location: /dashboard');
                        exit;
                    } else {
                        $erro = 'Usuário ou senha incorretos.';
                    }
                } catch (PDOException $e) {
                    $erro = 'Erro de conexão com o banco de dados.';
                    error_log('[LOGIN] PDOException: ' . $e->getMessage());
                    echo '<script>console.error(' . json_encode('[LOGIN] ' . $e->getMessage()) . ')</script>';
                }
            }
        }
    }
    // Regenera token após cada tentativa
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DOMBAG — Entrar</title>
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;width:100%;}
body{
  font-family:'Segoe UI',system-ui,sans-serif;
  background:#0a1628;
  color:#e8edf5;
  overflow:hidden;
}

/* ── Fundo animado ─────────────────────────────── */
.bg-deco{position:fixed;inset:0;pointer-events:none;z-index:0;}
.bg-deco::before{
  content:'';position:absolute;inset:0;
  background-image:
    linear-gradient(rgba(45,106,255,.035) 1px,transparent 1px),
    linear-gradient(90deg,rgba(45,106,255,.035) 1px,transparent 1px);
  background-size:52px 52px;
  animation:gridMove 32s linear infinite;
}
@keyframes gridMove{0%{background-position:0 0;}100%{background-position:52px 52px;}}
.bg-deco::after{
  content:'';position:absolute;
  left:50%;top:50%;transform:translate(-50%,-50%);
  width:900px;height:900px;
  background:radial-gradient(circle,rgba(30,79,201,.12) 0%,transparent 65%);
  border-radius:50%;
}

/* ── Layout ────────────────────────────────────── */
.page{
  display:flex;align-items:center;justify-content:center;
  height:100vh;
  padding:20px 16px;
  position:relative;z-index:1;
}

/* ── Card ──────────────────────────────────────── */
.form-card{
  background:#112240;
  border:1px solid rgba(255,255,255,.08);
  border-radius:18px;
  box-shadow:0 32px 80px rgba(0,0,0,.55),0 8px 24px rgba(0,0,0,.3);
  width:100%;
  max-width:480px;
  overflow:hidden;
  animation:fadeUp .4s cubic-bezier(.4,0,.2,1) both;
}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:none;}}

/* ── Topo accent ───────────────────────────────── */
.card-top{
  border-top:3px solid #2d6aff;
  padding:36px 44px 28px;
  background:linear-gradient(135deg,rgba(30,79,201,.14) 0%,transparent 60%);
  border-bottom:1px solid rgba(255,255,255,.06);
}
.brand{
  display:flex;align-items:center;gap:10px;
  margin-bottom:28px;
}
.brand-dot{
  width:9px;height:9px;
  background:#2d6aff;border-radius:50%;
  box-shadow:0 0 12px rgba(45,106,255,.8);
}
.brand-name{
  font-size:20px;font-weight:800;
  letter-spacing:3px;text-transform:uppercase;
  color:#e8edf5;
}
.brand-name span{color:#2d6aff;}
.card-title{font-size:22px;font-weight:700;letter-spacing:-.3px;color:#e8edf5;}
.card-sub{font-size:13px;color:#7a90b0;margin-top:5px;line-height:1.5;}

/* ── Corpo ─────────────────────────────────────── */
.card-body{padding:28px 44px 36px;}

/* ── Alerta de erro ────────────────────────────── */
.alert-err{
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);
  border-radius:10px;padding:11px 14px;
  font-size:13px;color:#ef4444;
  display:flex;align-items:center;gap:8px;
  margin-bottom:22px;
  animation:errShake .35s ease;
}
@keyframes errShake{
  0%,100%{transform:none;}
  20%{transform:translateX(-4px);}
  40%{transform:translateX(4px);}
  60%{transform:translateX(-2px);}
  80%{transform:translateX(2px);}
}

/* ── Campos ────────────────────────────────────── */
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:18px;}
.field label{
  font-size:10.5px;font-weight:700;
  letter-spacing:.7px;text-transform:uppercase;
  color:#7a90b0;
}
.input-wrap{position:relative;}
.input-ico{
  position:absolute;left:13px;top:50%;transform:translateY(-50%);
  color:#7a90b0;pointer-events:none;transition:color .2s;
}
.input-wrap:focus-within .input-ico{color:#2d6aff;}
input[type=text],input[type=password]{
  width:100%;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);
  border-radius:10px;
  padding:11px 40px 11px 40px;
  font-size:14px;font-family:'Segoe UI',system-ui,sans-serif;
  color:#e8edf5;
  outline:none;
  transition:border-color .2s,background .2s,box-shadow .2s;
}
input::placeholder{color:#4a607a;}
input:focus{
  border-color:rgba(45,106,255,.55);
  background:rgba(45,106,255,.06);
  box-shadow:0 0 0 3px rgba(45,106,255,.1);
}
input.has-error{border-color:rgba(239,68,68,.5)!important;background:rgba(239,68,68,.04)!important;}

/* ── Botão olho ────────────────────────────────── */
.btn-eye{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;
  color:#4a607a;padding:4px;transition:color .15s;line-height:0;
}
.btn-eye:hover{color:#2d6aff;}

/* ── Botão submit ──────────────────────────────── */
.btn-login{
  width:100%;margin-top:10px;
  padding:13px 20px;
  background:#1e4fc9;
  border:none;border-radius:10px;color:#fff;
  font-family:'Segoe UI',system-ui,sans-serif;
  font-size:14px;font-weight:700;
  cursor:pointer;letter-spacing:.3px;
  transition:background .15s,transform .12s,box-shadow .15s;
  box-shadow:0 4px 16px rgba(30,79,201,.4);
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.btn-login:hover{background:#2d6aff;transform:translateY(-1px);box-shadow:0 8px 24px rgba(45,106,255,.45);}
.btn-login:active{transform:none;}

/* ── Footer ────────────────────────────────────── */
.card-footer{
  padding:16px 44px;
  border-top:1px solid rgba(255,255,255,.06);
  background:rgba(0,0,0,.12);
  text-align:center;
  font-size:11.5px;color:#4a607a;
}

/* ── Responsivo ────────────────────────────────── */
@media(max-width:540px){
  .card-top,.card-body{padding-left:24px;padding-right:24px;}
  .card-footer{padding-left:24px;padding-right:24px;}
  .card-title{font-size:20px;}
  .form-card{border-radius:14px;}
}
</style>
</head>
<body>

<div class="bg-deco"></div>

<?php if (APP_ENV === 'local'): ?>
<div style="
  position:fixed;top:12px;left:50%;transform:translateX(-50%);
  background:rgba(234,179,8,.15);border:1px solid rgba(234,179,8,.4);
  border-radius:999px;padding:5px 16px;
  font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
  color:#fbbf24;white-space:nowrap;
  z-index:9999;backdrop-filter:blur(4px);
">
  &#9679; Ambiente local &mdash; banco: <?= htmlspecialchars(DB_NAME ?: '(não configurado)') ?>
</div>
<?php endif; ?>

<div class="page">
  <div class="form-card">

    <div class="card-top">
      <div class="brand">
        <span class="brand-dot"></span>
        <span class="brand-name">DOM<span>BAG</span></span>
      </div>
      <h2 class="card-title">Faça seu login</h2>
      <p class="card-sub">Insira suas credenciais para acessar o sistema.</p>
    </div>

    <div class="card-body">

      <?php if ($erro): ?>
      <div class="alert-err">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($erro) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="/login" id="frmLogin" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="field">
          <label for="f_login">Usuário</label>
          <div class="input-wrap">
            <span class="input-ico">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <input type="text" id="f_login" name="login"
              placeholder="seu.usuario"
              value="<?= $login_val ?>"
              autocomplete="username" autofocus
              <?= $erro ? 'class="has-error"' : '' ?>>
          </div>
        </div>

        <div class="field">
          <label for="f_senha">Senha</label>
          <div class="input-wrap">
            <span class="input-ico">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="f_senha" name="senha"
              placeholder="••••••••"
              autocomplete="current-password"
              <?= $erro ? 'class="has-error"' : '' ?>>
            <button type="button" class="btn-eye" onclick="toggleEye()" title="Mostrar senha">
              <svg id="eyeSvg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-login" id="btnLogin">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Entrar
        </button>
      </form>

    </div><!-- /card-body -->

    <div class="card-footer">
      DOMBAG &mdash; Gestão Industrial &copy; <?= date('Y') ?>
    </div>

  </div>
</div>

<script>
function toggleEye() {
  const inp = document.getElementById('f_senha');
  const svg = document.getElementById('eyeSvg');
  if (inp.type === 'password') {
    inp.type = 'text';
    svg.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>`;
  } else {
    inp.type = 'password';
    svg.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
  }
}

document.getElementById('f_login').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('f_senha').focus(); }
});

document.getElementById('frmLogin').addEventListener('submit', () => {
  const btn = document.getElementById('btnLogin');
  btn.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .7s linear infinite;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Verificando…`;
  btn.style.opacity = '.75';
  btn.style.pointerEvents = 'none';
});
</script>
<style>@keyframes spin{to{transform:rotate(360deg);}}</style>
</body>
</html>
