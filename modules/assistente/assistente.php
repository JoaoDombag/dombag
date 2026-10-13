<?php
// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Assistente IA em tela cheia   (rota: /assistente)
//  O chat é o mesmo do botão flutuante (public/js/chat-widget.js), montado em
//  modo "página": lista de conversas à esquerda e conversa ocupando a tela.
// ══════════════════════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// Avisa o sidebar.php para carregar o chat em modo página (sem botão flutuante).
$DOMBAG_CHAT_PAGINA = true;
$chatConfigurado = (bool) (dombagConfig('GEMINI_API_KEY') || dombagConfig('ANTHROPIC_API_KEY'));
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assistente IA | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.chat-content { padding: 16px 24px 20px; }
#assistente-chat { flex: 1; min-height: 0; }
.chat-off {
  max-width: 520px; background: var(--card-bg); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 24px 28px; color: var(--text-muted); line-height: 1.6;
}
.chat-off h2 { color: var(--text-primary); font-size: 16px; margin-bottom: 8px; }
@media (max-width: 768px) {
  /* no celular o chat ocupa toda a área abaixo da barra móvel */
  .main > .topbar { display: none; }
  .chat-content { padding: 0 !important; display: flex !important; }
  #assistente-chat { height: calc(100dvh - 52px); flex: none; }
}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="page-title">
        <h1>Assistente IA</h1>
        <p>Converse sobre vendas, pedidos, faturamento e produção, e gere relatórios em PDF ou Excel</p>
      </div>
    </header>

    <div class="content chat-content">
      <?php if ($chatConfigurado): ?>
        <div id="assistente-chat"></div>
      <?php else: ?>
        <div class="chat-off">
          <h2>Assistente não configurado</h2>
          Falta a chave da IA. Um administrador precisa preencher a chave do Google Gemini
          ou do Anthropic Claude em
          <a href="/modules/parametros/parametros.php?aba=ia">Parâmetros do Sistema → Assistente IA</a>
          (a chave OpenAI da aba Integrações não é usada pelo assistente).
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
