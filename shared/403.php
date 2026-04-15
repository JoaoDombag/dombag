<?php
// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Acesso Negado (403)
//  Incluído pelo auth.php quando o usuário não tem permissão para a página
// ══════════════════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acesso Negado | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
.err-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
.err-box{background:var(--card-bg);border:1px solid var(--border);border-radius:18px;padding:48px 40px;max-width:460px;width:100%;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,.35);}
.err-icon{width:64px;height:64px;border-radius:50%;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
.err-code{font-size:48px;font-weight:800;color:var(--red);line-height:1;margin-bottom:8px;}
.err-title{font-size:18px;font-weight:700;margin-bottom:10px;}
.err-desc{font-size:13.5px;color:var(--text-muted);line-height:1.6;margin-bottom:28px;}
.err-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
</style>
</head>
<body>
<div class="err-wrap">
  <div class="err-box">
    <div class="err-icon">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        <line x1="12" y1="15" x2="12" y2="17"/>
      </svg>
    </div>
    <div class="err-code">403</div>
    <div class="err-title">Acesso Negado</div>
    <p class="err-desc">
      Você não tem permissão para acessar esta página.<br>
      Contacte o administrador do sistema para solicitar acesso.
    </p>
    <div class="err-actions">
      <a href="/dashboard" class="btn-primary">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7" rx="1"/>
          <rect x="14" y="3" width="7" height="7" rx="1"/>
          <rect x="14" y="14" width="7" height="7" rx="1"/>
          <rect x="3" y="14" width="7" height="7" rx="1"/>
        </svg>
        Ir ao Dashboard
      </a>
      <a href="javascript:history.back()" class="btn-secondary">Voltar</a>
    </div>
  </div>
</div>
</body>
</html>
