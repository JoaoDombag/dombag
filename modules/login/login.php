<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';

session_start();

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;width:100%;}
body{
  font-family:'DM Sans',system-ui,sans-serif;
  background:#060d18;
  color:#e8edf5;
  overflow:hidden;
}

/* ══════════════════════════════════
   FUNDO
══════════════════════════════════ */
.bg-deco{
  position:fixed;inset:0;pointer-events:none;z-index:0;
}
.bg-deco::before{
  content:'';position:absolute;inset:0;
  background-image:
    linear-gradient(rgba(45,106,255,.04) 1px,transparent 1px),
    linear-gradient(90deg,rgba(45,106,255,.04) 1px,transparent 1px);
  background-size:48px 48px;
  animation:gridMove 28s linear infinite;
}
@keyframes gridMove{0%{background-position:0 0;}100%{background-position:48px 48px;}}
.bg-deco::after{
  content:'';position:absolute;
  left:50%;top:50%;transform:translate(-50%,-50%);
  width:800px;height:800px;
  background:radial-gradient(circle,rgba(45,106,255,.1) 0%,transparent 65%);
  border-radius:50%;
}

/* ══════════════════════════════════
   TOPBAR
══════════════════════════════════ */
.topbar{
  position:fixed;top:0;left:0;right:0;
  z-index:10;
  height:64px;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 40px;
  background:rgba(6,13,24,.85);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  border-bottom:1px solid rgba(255,255,255,.07);
}
.topbar-logo{
  font-family:'Barlow Condensed',sans-serif;
  font-size:26px;font-weight:800;
  letter-spacing:3px;text-transform:uppercase;
  color:#e8edf5;
  display:flex;align-items:center;gap:10px;
  text-decoration:none;
}
.topbar-logo .accent{color:#2d6aff;}
.topbar-logo-dot{
  width:8px;height:8px;
  background:#2d6aff;
  border-radius:50%;
  box-shadow:0 0 10px rgba(45,106,255,.7);
}
.topbar-nav{
  display:flex;align-items:center;gap:6px;
}
.topbar-nav a{
  font-size:13.5px;font-weight:500;
  color:rgba(180,200,230,.6);
  text-decoration:none;
  padding:6px 14px;border-radius:8px;
  transition:color .15s,background .15s;
}
.topbar-nav a:hover{color:#e8edf5;background:rgba(255,255,255,.06);}
.topbar-badge{
  font-size:11px;font-weight:700;
  letter-spacing:.5px;text-transform:uppercase;
  color:#7a90b0;
  border:1px solid rgba(255,255,255,.1);
  border-radius:20px;
  padding:4px 12px;
}

@media(max-width:640px){
  .topbar-nav{display:none;}
  .topbar{padding:0 20px;}
}

/* ══════════════════════════════════
   LAYOUT PRINCIPAL
══════════════════════════════════ */
.page{
  display:flex;align-items:center;justify-content:center;
  min-height:100vh;
  padding:80px 16px 24px;
  position:relative;z-index:1;
}

/* ══════════════════════════════════
   CARD DO FORMULÁRIO
══════════════════════════════════ */
.form-card{
  background:rgba(255,255,255,.97);
  border-radius:18px;
  box-shadow:0 32px 80px rgba(0,0,0,.55),0 8px 24px rgba(0,0,0,.3);
  padding:48px 44px 40px;
  width:100%;
  max-width:420px;
  animation:fadeUp .4s cubic-bezier(.4,0,.2,1) both;
  color:#1a1a2e;
}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:none;}}

/* ── Cabeçalho ── */
.card-header{margin-bottom:32px;text-align:center;}
.card-title{
  font-size:24px;font-weight:700;
  color:#1a1a2e;
  letter-spacing:-.3px;
}
.card-sub{
  font-size:13px;color:#8888a0;
  margin-top:6px;line-height:1.5;
}

/* ── Campos estilo underline ── */
.field{display:flex;flex-direction:column;gap:0;margin-bottom:24px;}
.field label{
  font-size:11px;font-weight:700;
  letter-spacing:.8px;text-transform:uppercase;
  color:#aaaabc;margin-bottom:8px;
}
.input-wrap{position:relative;}
.input-ico{
  position:absolute;left:2px;top:50%;transform:translateY(-50%);
  color:#bbbbcc;pointer-events:none;transition:color .2s;
}
.input-wrap:focus-within .input-ico{color:#2d6aff;}
input[type=text],input[type=password]{
  width:100%;
  background:transparent;
  border:none;
  border-bottom:1.5px solid #d8d8e8;
  border-radius:0;
  padding:10px 36px 10px 28px;
  font-size:14px;font-family:'DM Sans',sans-serif;
  color:#1a1a2e;
  outline:none;
  appearance:none;-webkit-appearance:none;
  transition:border-color .2s;
}
input::placeholder{color:#c0c0d0;}
input:focus{border-bottom-color:#2d6aff;}
input.has-error{border-bottom-color:#ef4444!important;}

/* ── Botão olho ── */
.btn-eye{
  position:absolute;right:2px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;
  color:#c0c0d0;padding:4px;transition:color .15s;line-height:0;
}
.btn-eye:hover{color:#2d6aff;}

/* ── Alerta de erro ── */
.alert-err{
  background:#fff5f5;border:1px solid #fecaca;
  border-radius:10px;padding:11px 14px;
  font-size:13px;color:#dc2626;
  display:flex;align-items:center;gap:8px;
  margin-bottom:20px;
  animation:errShake .35s ease;
}
@keyframes errShake{
  0%,100%{transform:none;}
  20%{transform:translateX(-5px);}
  40%{transform:translateX(5px);}
  60%{transform:translateX(-3px);}
  80%{transform:translateX(3px);}
}

/* ── Botão submit ── */
.btn-login{
  width:100%;margin-top:8px;
  padding:14px 20px;
  background:#2d6aff;
  border:none;border-radius:10px;color:white;
  font-family:'DM Sans',sans-serif;
  font-size:15px;font-weight:700;
  cursor:pointer;letter-spacing:.3px;
  transition:background .15s,transform .15s,box-shadow .15s;
  box-shadow:0 6px 20px rgba(45,106,255,.35);
}
.btn-login:hover{background:#1a56e8;transform:translateY(-1px);box-shadow:0 10px 28px rgba(45,106,255,.45);}
.btn-login:active{transform:none;background:#1a42c0;}

/* ── Footer do card ── */
.card-footer{
  margin-top:24px;text-align:center;
  font-size:12px;color:#9898b0;line-height:1.7;
  border-top:1px solid #ebebf5;padding-top:18px;
}

/* ── Responsivo ── */
@media(max-width:480px){
  .form-card{padding:36px 24px 32px;border-radius:14px;}
  .card-title{font-size:21px;}
}
</style>
</head>
<body>

<div class="bg-deco"></div>

<!-- ── Topbar ── -->
<header class="topbar">
  <a href="#" class="topbar-logo">
    <span class="topbar-logo-dot"></span>
    DOM<span class="accent">BAG</span>
  </a>
  <nav class="topbar-nav">
    <a href="#">Início</a>
    <a href="#">Módulos</a>
    <a href="#">Suporte</a>
  </nav>
  <span class="topbar-badge">Sistema de Gestão Industrial</span>
</header>

<!-- ── Conteúdo ── -->
<div class="page">
  <div class="form-card">

    <div class="card-header">
      <h2 class="card-title">Faça o seu login</h2>
      <p class="card-sub">Insira suas credenciais para acessar o sistema.</p>
    </div>

    <?php if ($erro): ?>
    <div class="alert-err">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="frmLogin" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <!-- Usuário -->
      <div class="field">
        <label for="f_login">Seu usuário*</label>
        <div class="input-wrap">
          <span class="input-ico">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </span>
          <input
            type="text" id="f_login" name="login"
            placeholder="seu.usuario"
            value="<?= $login_val ?>"
            autocomplete="username"
            autofocus
            <?= $erro ? 'class="has-error"' : '' ?>
          >
        </div>
      </div>

      <!-- Senha -->
      <div class="field">
        <label for="f_senha">Sua senha*</label>
        <div class="input-wrap">
          <span class="input-ico">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </span>
          <input
            type="password" id="f_senha" name="senha"
            placeholder="••••••••"
            autocomplete="current-password"
            <?= $erro ? 'class="has-error"' : '' ?>
          >
          <button type="button" class="btn-eye" id="btnEye" onclick="toggleEye()" title="Mostrar senha">
            <svg id="eyeSvg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>



      <button type="submit" class="btn-login" id="btnLogin">
        Entrar
      </button>
    </form>

    <div class="card-footer">
      DOMBAG &mdash; Gestão Industrial &copy; <?= date('Y') ?>
    </div>

  </div>

</div><!-- /.page -->

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
