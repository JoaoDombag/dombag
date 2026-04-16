<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Controle de Estoque | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.src-gsheet { background:rgba(34,197,94,.12); color:#22c55e; }

.iframe-shell {
  height: calc(100vh - 170px);
  min-height: 540px;
  background: #fff;
  border-top: 1px solid var(--border);
}
.iframe-shell iframe {
  width: 100%;
  height: 100%;
  border: none;
  display: block;
}

@media(max-width:700px) {
  .iframe-shell { height: calc(100vh - 200px); min-height: 420px; }
}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Controle de Estoque</h1>
          <p>Planilha integrada via Google Sheets</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a class="btn-secondary"
           href="https://docs.google.com/spreadsheets/d/13jr9_RIRiEBco483JA1SMa_nRsBkNR0h/edit?gid=112234550#gid=112234550"
           target="_blank" rel="noopener noreferrer">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Abrir no Sheets
        </a>
        <button class="btn-primary" onclick="document.getElementById('sheet-frame').src += ''">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Atualizar
        </button>
      </div>
    </header>

    <div class="content" style="padding:0;">
      <div class="panel" style="border-radius:0;border-left:none;border-right:none;border-bottom:none;">
        <div class="panel-header">
          <span class="panel-title">Controle de Estoque</span>
          <span class="source-badge src-gsheet">Google Sheets</span>
        </div>
        <div class="iframe-shell">
          <iframe
            id="sheet-frame"
            src="https://docs.google.com/spreadsheets/d/13jr9_RIRiEBco483JA1SMa_nRsBkNR0h/edit?gid=112234550#gid=112234550?widget=true&headers=false"
            title="Controle de Estoque"
            loading="lazy"
            allowfullscreen>
          </iframe>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
