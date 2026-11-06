<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/documentacao/documentacao_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Documentação do sistema
//  Conteúdo: documentacao_dados.php. Visual: documentacao_corpo.php.
// ══════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documentação | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Documentação</h1>
          <p>Como o sistema funciona, tela por tela</p>
        </div>
      </div>
    </header>

    <?php
    // sidebar.php acabou de carregar as permissões de quem está logado
    $telasPorArea = docTelasPorArea($DOMBAG_PAGINAS, 'sb_can', $_sb_admin, $_sb_desativados);
    include __DIR__ . '/documentacao_corpo.php';
    ?>
  </div>
</div>
</body>
</html>
