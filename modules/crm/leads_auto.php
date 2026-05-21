<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/leads_ia_lib.php';

// ── Pesquisa automática ───────────────────────────────────────────────────────
// Quando chamada com ?exec=1, roda todos os presets em sequência,
// salva novos leads no banco e retorna JSON com o resumo.
// Pode ser acionada por cron, pelo botão abaixo ou por chamada direta.

set_time_limit(600); // presets em sequência = longa execução

if (isset($_GET['exec'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo        = dbPDO();
        $clientesYz = iaCarregarClientesYzidro();
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Erro de conexão: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $presets   = LEADS_PRESETS;
    $executar  = array_keys($presets);

    // Filtrar presets específicos via ?presets=nutricao_animal,fertilizantes
    if (!empty($_GET['presets'])) {
        $solicitados = array_map('trim', explode(',', (string) $_GET['presets']));
        $executar    = array_values(array_intersect($executar, $solicitados));
    }

    $resultados   = [];
    $totalNovos   = 0;
    $totalFiltrados = 0;
    $erros        = 0;

    foreach ($executar as $key) {
        $res = iaRunPreset($key, $pdo, $clientesYz);
        $resultados[] = [
            'preset'    => $key,
            'titulo'    => $res['titulo'] ?? $key,
            'ok'        => $res['ok'],
            'novos'     => $res['novos'] ?? 0,
            'filtrados' => $res['filtrados'] ?? 0,
            'message'   => $res['message'] ?? '',
        ];
        if ($res['ok']) {
            $totalNovos     += $res['novos'] ?? 0;
            $totalFiltrados += $res['filtrados'] ?? 0;
        } else {
            $erros++;
        }
    }

    echo json_encode([
        'ok'              => true,
        'executado_em'    => date('Y-m-d H:i:s'),
        'presets_rodados' => count($executar),
        'total_novos'     => $totalNovos,
        'total_filtrados' => $totalFiltrados,
        'erros'           => $erros,
        'detalhes'        => $resultados,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Contagem atual no banco ───────────────────────────────────────────────────
$totalBanco  = 0;
$ultimaBusca = null;
try {
    $pdo = dbPDO();
    iaProspEnsureSchema($pdo);
    $totalBanco  = (int) $pdo->query('SELECT COUNT(*) FROM LEADS_PROSPECTADOS')->fetchColumn();
    $ultimaBusca = $pdo->query('SELECT MAX(GRAVADO_EM) FROM LEADS_PROSPECTADOS')->fetchColumn() ?: null;
} catch (Throwable) {}

$presets = LEADS_PRESETS;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prospecção com IA — Automático | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.content{overflow-y:auto;overflow-x:hidden;}
.nav-pills{display:flex;gap:8px;margin-bottom:20px}
.nav-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.03);color:var(--text-secondary);font-size:12px;font-weight:600;text-decoration:none}
.nav-pill:hover{background:rgba(255,255,255,.07);color:var(--text-primary)}
.nav-pill.active{background:rgba(45,106,255,.1);border-color:rgba(45,106,255,.35);color:#7db3ff}
.preset-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:24px}
.preset-card{background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:8px}
.preset-title{font-size:13px;font-weight:800;color:var(--text-primary)}
.preset-desc{font-size:11px;line-height:1.45;color:var(--text-secondary);flex:1}
.preset-meta{display:flex;flex-wrap:wrap;gap:6px}
.preset-pill{font-size:10px;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text-muted)}
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stat-box{background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:14px;padding:20px}
.stat-num{font-size:28px;font-weight:800;color:var(--text-primary)}
.stat-lbl{font-size:11px;color:var(--text-muted);margin-top:4px}
.run-box{background:rgba(45,106,255,.06);border:1px solid rgba(45,106,255,.25);border-radius:14px;padding:24px;text-align:center}
.run-title{font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:6px}
.run-desc{font-size:12px;color:var(--text-secondary);margin-bottom:18px}
.log-box{background:rgba(0,0,0,.3);border:1px solid var(--border);border-radius:10px;padding:16px;font-family:ui-monospace,monospace;font-size:12px;line-height:1.7;color:var(--text-muted);min-height:120px;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;margin-top:18px;display:none}
.badge-ok{color:var(--teal)}.badge-err{color:var(--red)}.badge-new{color:var(--amber)}@media(max-width:900px){.preset-grid{grid-template-columns:repeat(2,1fr)}.stat-row{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.preset-grid,.stat-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">
<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Executar Automático</h1>
      <p>Roda todos os presets de uma vez, salva novos leads e ignora os já conhecidos.</p>
    </div>
  </div>
</header>

<div class="content">

  <div class="nav-pills">
    <a href="leads_resultados.php" class="nav-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Resultados
    </a>
    <a href="leads_auto.php" class="nav-pill active">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      Executar Automático
    </a>
  </div>

  <div class="stat-row">
    <div class="stat-box">
      <div class="stat-num"><?= number_format($totalBanco) ?></div>
      <div class="stat-lbl">Leads no banco</div>
    </div>
    <div class="stat-box">
      <div class="stat-num"><?= count($presets) ?></div>
      <div class="stat-lbl">Presets disponíveis</div>
    </div>
    <div class="stat-box">
      <div class="stat-num" style="font-size:14px;"><?= $ultimaBusca ? date('d/m H:i', strtotime($ultimaBusca)) : '—' ?></div>
      <div class="stat-lbl">Última gravação no banco</div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:20px;">
    <div class="panel-header"><span class="panel-title">Executar agora</span></div>
    <div style="padding:20px;">
      <div class="run-box">
        <div class="run-title">Rodar todos os <?= count($presets) ?> presets automaticamente</div>
        <div class="run-desc">A IA pesquisa cada segmento na web, filtra os já salvos e grava os novos leads no banco. Pode levar alguns minutos.</div>
        <button id="btnRun" class="btn-primary" onclick="executarAuto()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          <span id="btnRunTxt">Executar pesquisa automática</span>
        </button>
        <div class="log-box" id="logBox"></div>
      </div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:20px;">
    <div class="panel-header"><span class="panel-title">Presets que serão executados</span></div>
    <div style="padding:20px;">
      <div class="preset-grid">
        <?php foreach ($presets as $key => $cfg): ?>
          <div class="preset-card">
            <div class="preset-title"><?= iaH($cfg['titulo']) ?></div>
            <div class="preset-desc"><?= iaH($cfg['descricao']) ?></div>
            <div class="preset-meta">
              <span class="preset-pill"><?= iaH($cfg['produto_foco']) ?></span>
              <span class="preset-pill"><?= iaH(ucfirst($cfg['porte'])) ?></span>
              <span class="preset-pill"><?= implode(', ', $cfg['ufs']) ?></span>
              <span class="preset-pill">até <?= $cfg['quantidade'] ?> leads</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>


</div>
</div>
</div>

<script>
async function executarAuto() {
  const btn  = document.getElementById('btnRun');
  const txt  = document.getElementById('btnRunTxt');
  const log  = document.getElementById('logBox');

  btn.disabled = true;
  txt.textContent = 'Executando…';
  btn.style.opacity = '.7';
  log.style.display = 'block';
  log.textContent = '⏳ Conectando à IA e pesquisando cada segmento…\n';

  try {
    const res  = await fetch('leads_auto.php?exec=1');
    const data = await res.json();

    log.textContent = '';
    log.textContent += `✅ Execução concluída em ${data.executado_em}\n`;
    log.textContent += `📦 Presets rodados: ${data.presets_rodados}\n`;
    log.textContent += `🆕 Total de novos leads: ${data.total_novos}\n`;
    log.textContent += `♻️  Já existiam (ignorados): ${data.total_filtrados}\n`;
    if (data.erros > 0) log.textContent += `❌ Erros: ${data.erros}\n`;
    log.textContent += '\n── Detalhe por preset ──\n';

    for (const d of (data.detalhes || [])) {
      const icon = d.ok ? '✅' : '❌';
      log.textContent += `${icon} ${d.titulo}: ${d.message}\n`;
    }
  } catch (e) {
    log.textContent += `\n❌ Erro de comunicação: ${e.message}`;
  }

  txt.textContent = 'Executar pesquisa automática';
  btn.disabled = false;
  btn.style.opacity = '1';
}
</script>
</body>
</html>
