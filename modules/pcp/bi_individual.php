<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/bi_individual_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — BI Individual: total de bags produzidos no dia x meta TOTAL
//  da equipe, e ao lado a produção de cada funcionário (painel para TV)
//  Fonte: PostgreSQL ERP Yzidro (somente leitura) + meta local (MySQL)
// ══════════════════════════════════════════════════════════════

function biiEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ── Dia selecionado no filtro (?data=YYYY-MM-DD). Padrão: hoje. ──────────────
function biiDia(): string
{
    $d = trim((string) ($_GET['data'] ?? ''));
    if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $t = DateTime::createFromFormat('Y-m-d', $d);
        if ($t && $t->format('Y-m-d') === $d) {
            return $d;
        }
    }
    return date('Y-m-d');
}

function biiDiaLabel(string $dia): string
{
    return $dia === date('Y-m-d') ? 'HOJE' : date('d/m', strtotime($dia));
}

function biiBuildPayload($pg, PDO $pdo, string $dia): array
{
    return [
        'meta'          => biiFetchMeta($pdo),
        'funcionarios'  => biiFetchProducaoPorFuncionario($pg, $dia),
        'dia'           => $dia,
        'dia_label'     => biiDiaLabel($dia),
        'atualizado_em' => date('H:i:s'),
    ];
}

$pdo = dbPDO();
$pg  = dbPG();
$dia = biiDia();

// ── AJAX: salvar meta total do dia ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_meta') {
    header('Content-Type: application/json; charset=utf-8');
    $valor = max(0.0, (float) str_replace(',', '.', (string) ($_POST['meta'] ?? '0')));
    try {
        biiSaveMeta($pdo, $valor);
        echo json_encode(['ok' => true, 'meta' => $valor]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: atualizar dados (polling em tempo real) ─────────────────────────────
if (($_GET['action'] ?? '') === 'refresh') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    try {
        echo json_encode(biiBuildPayload($pg, $pdo, $dia), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$error = '';
$payload = ['meta' => biiFetchMeta($pdo), 'funcionarios' => [], 'dia' => $dia, 'dia_label' => biiDiaLabel($dia), 'atualizado_em' => date('H:i:s')];
if (!$pg) {
    $error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    try {
        $payload = biiBuildPayload($pg, $pdo, $dia);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="escuro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BI Individual | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  :root {
    --biz-bg: #05070a;
    --biz-card: #0d1117;
    --biz-card2: #10161d;
    --biz-border: rgba(255,255,255,.09);
    --biz-orange: #f0a638;
    --biz-teal: #58d6c9;
    --biz-teal-2: #34b6a8;
    --biz-blue: #3aa7ff;
    --biz-text: #eef2f7;
    --biz-muted: #8fa0b3;
  }

  /* Painel de TV: #bizDashboard ocupa exatamente a área visível (JS:
     biApplyScale fixa largura/altura em px) e tudo dentro encolhe/cresce
     pra caber, sem rolagem nem corte. No celular vira página rolável. */
  .content { display: flex; align-items: flex-start; justify-content: center; overflow: hidden; min-width: 0; padding: 0; }
  @media (max-width: 768px) {
    .app-wrapper { height: 100vh !important; overflow: hidden !important; flex-direction: row !important; }
    .main { height: 100vh !important; overflow: hidden !important; }
    /* altura = o que sobra abaixo das barras (que podem ter 2-3 linhas) */
    .content { padding: 0 !important; overflow: hidden !important; height: auto !important; min-height: 0; flex: 1 1 auto !important; display: flex !important; }
  }

  #bizDashboard {
    display: flex; gap: 20px;
    background: var(--biz-bg);
    padding: 22px 24px; color: var(--biz-text); font-family: 'Segoe UI', sans-serif;
    box-sizing: border-box; flex-shrink: 0; min-height: 0;
    /* fontes em cqw: relativas à largura do próprio painel (TV ou monitor) */
    container-type: inline-size;
  }

  .biz-card { background: var(--biz-card); border: 1px solid var(--biz-border); border-radius: 16px; padding: 18px 22px; box-shadow: 0 10px 24px -16px rgba(0,0,0,.5); min-width: 0; min-height: 0; display: flex; flex-direction: column; }
  .biz-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--biz-border); flex: 0 0 auto; }
  .biz-card-head h2 { font-size: clamp(13px, 1cqw, 20px); font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: var(--biz-text); }
  .biz-count-badge { font-size: clamp(11px, .85cqw, 15px); font-weight: 800; padding: 3px 12px; border-radius: 20px; background: rgba(88,214,201,.18); color: var(--biz-teal); white-space: nowrap; }
  .biz-empty-msg { margin: auto; text-align: center; color: var(--biz-muted); font-size: clamp(13px, 1.2cqw, 20px); font-weight: 700; padding: 30px 0; }

  /* ══ Esquerda: TOTAL produzido x meta total ══════════════════════════════ */
  .biz-total { flex: 0 0 36%; }
  .biz-total.hit { border-color: rgba(45,106,255,.55); background: rgba(45,106,255,.09); box-shadow: 0 0 0 1px rgba(45,106,255,.3), 0 0 40px -10px rgba(45,106,255,.4); }
  .biz-ring-wrap { flex: 1 1 auto; min-height: 0; display: flex; padding: 4px 0; }
  .biz-ring-shape { margin: auto; aspect-ratio: 1 / 1; height: 100%; max-width: 100%; }
  .biz-ring-shape svg { width: 100%; height: 100%; display: block; overflow: visible; }
  .biz-total-foot { flex: 0 0 auto; text-align: center; }
  .biz-total-nums { font-size: clamp(14px, 1.3cqw, 24px); font-weight: 700; color: var(--biz-muted); }
  .biz-total-nums strong { font-size: clamp(34px, 4cqw, 76px); color: #fff; font-weight: 900; font-variant-numeric: tabular-nums; letter-spacing: -1px; }
  .biz-total-nums .meta { font-size: clamp(20px, 2cqw, 38px); color: var(--biz-text); font-weight: 800; }
  .biz-total-msg { margin-top: 6px; font-size: clamp(15px, 1.5cqw, 28px); font-weight: 800; color: var(--biz-text); }
  .biz-total.hit .biz-total-msg { color: #7db3ff; }
  /* 3 indicadores sempre na mesma linha (grade de 3 colunas iguais) */
  .biz-total-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 12px; }
  .biz-stat { background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 10px; padding: 8px 6px; min-width: 0; text-align: center; }
  .biz-stat b { display: block; font-size: clamp(18px, 1.7cqw, 32px); font-weight: 900; color: var(--biz-teal); font-variant-numeric: tabular-nums; line-height: 1.1; }
  .biz-stat span { display: block; font-size: clamp(9px, .75cqw, 14px); font-weight: 700; color: var(--biz-muted); text-transform: uppercase; letter-spacing: .03em; line-height: 1.2; }

  /* Meta total editável (lápis no cabeçalho do card) */
  .biz-head-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .biz-meta-btn { width: 26px; height: 26px; border-radius: 6px; border: 1px solid var(--biz-border); background: transparent; color: var(--biz-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .biz-meta-btn:hover { background: rgba(255,255,255,.06); color: var(--biz-text); }
  .biz-meta-form { display: none; align-items: center; gap: 6px; }
  .biz-meta-form.open { display: flex; }
  .biz-meta-form input { width: 100px; background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 6px; color: var(--biz-text); font: inherit; font-size: 13px; padding: 4px 8px; }
  .biz-meta-save, .biz-meta-cancel { font: inherit; font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 6px; cursor: pointer; }
  .biz-meta-save { background: var(--biz-teal-2); color: #05070a; border: 0; }
  .biz-meta-cancel { background: transparent; color: var(--biz-muted); border: 1px solid var(--biz-border); }
  body.biz-fs-fallback .biz-meta-btn, body.biz-fs-fallback .biz-meta-form { display: none !important; }

  /* ══ Direita: produção individual (uma linha por pessoa) ═════════════════ */
  .biz-ind { flex: 1 1 0; }
  /* As linhas dividem a altura do card igualmente (1fr); com muita gente
     a lista vira 2 colunas (JS define grid-template-columns). */
  .biz-ind-list { flex: 1 1 auto; min-height: 0; display: grid; grid-auto-rows: minmax(0, 1fr); gap: 8px 18px; }
  .biz-ind-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; align-content: center; gap: 4px 16px;
                 background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 10px; padding: 6px 16px; min-height: 0; overflow: hidden; }
  .biz-ind-name { font-size: clamp(14px, 1.35cqw, 26px); font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .biz-ind-qtd { grid-row: span 3; text-align: right; font-variant-numeric: tabular-nums; }
  .biz-ind-qtd b { display: block; font-size: clamp(20px, 2.1cqw, 40px); font-weight: 900; color: #fff; line-height: 1.05; }
  .biz-ind-qtd span { font-size: clamp(10px, .85cqw, 15px); font-weight: 700; color: var(--biz-muted); }
  .biz-ind-bar { height: clamp(6px, .55cqw, 11px); background: rgba(255,255,255,.07); border-radius: 6px; overflow: hidden; }
  .biz-ind-fill { height: 100%; border-radius: 6px; background: linear-gradient(90deg, var(--biz-blue), var(--biz-teal)); transition: width .5s ease; }
  .biz-ind-op { display: flex; align-items: center; gap: 7px; min-width: 0; font-size: clamp(11px, .95cqw, 17px); font-weight: 700; color: var(--biz-teal); }
  .biz-ind-op > span:last-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .biz-ind-op.off { color: var(--biz-muted); font-weight: 600; }
  .biz-live-dot-sm { flex: 0 0 auto; width: 8px; height: 8px; border-radius: 50%; background: var(--biz-teal); animation: bizPulse 1.8s infinite; }

  /* ── Tela em pé ou estreita (retrato, tablet): o total vira uma faixa no
     topo — anel à esquerda, números à direita — e a lista ocupa o resto da
     altura. JS (biApplyScale) liga a classe bi-vertical pela proporção. ── */
  body.bi-vertical #bizDashboard { flex-direction: column; gap: 14px; }
  body.bi-vertical .biz-total { flex: 0 0 auto; display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: 24px; align-items: center; }
  body.bi-vertical .biz-total .biz-card-head { grid-column: 1 / -1; }
  body.bi-vertical .biz-ring-wrap { flex: none; width: clamp(130px, 24cqw, 300px); height: clamp(130px, 24cqw, 300px); padding: 0; }
  body.bi-vertical .biz-total-foot { text-align: left; }
  body.bi-vertical .biz-total-nums strong { font-size: clamp(32px, 6cqw, 70px); }
  body.bi-vertical .biz-total-msg { font-size: clamp(14px, 2.4cqw, 26px); }
  body.bi-vertical .biz-total-stats { max-width: 520px; }
  body.bi-vertical .biz-stat span { font-size: clamp(9px, 1.5cqw, 13px); }
  body.bi-vertical .biz-ind-name { font-size: clamp(14px, 2.2cqw, 24px); }
  body.bi-vertical .biz-ind-qtd b { font-size: clamp(20px, 3.4cqw, 38px); }
  body.bi-vertical .biz-ind-qtd span, body.bi-vertical .biz-ind-op { font-size: clamp(11px, 1.6cqw, 16px); }

  /* ── Topbar: quebra em 2 linhas assim que título + ações não cabem lado a
     lado (antes só no celular, e em tablet o título ficava sobre o "Ao vivo") ── */
  @media (max-width: 1200px) {
    .topbar { flex-wrap: wrap; row-gap: 8px; height: auto; }
    .topbar-left { min-width: 0; }
    .topbar-actions { flex-wrap: wrap; row-gap: 8px; }
  }
  .biz-btn-short { display: none; }
  @media (max-width: 768px) {
    .topbar { flex-wrap: wrap; row-gap: 8px; }
    .topbar-left { width: 100%; }
    .topbar-actions { width: 100%; flex-wrap: wrap; row-gap: 8px; column-gap: 8px; }
    .last-update { display: none; }
    .biz-date-filter { margin-left: auto; }
    .topbar-actions .btn-secondary { flex: 1 1 0; min-width: 0; justify-content: center; padding: 8px 10px; font-size: 12px; white-space: nowrap; overflow: hidden; }
    .biz-btn-long { display: none; }
    .biz-btn-short { display: inline; }
  }

  /* ── Celular (fora da tela cheia): página rolável, total em cima e a
     lista de pessoas embaixo. JS: biApplyScale liga a classe bi-mobile. ── */
  body.bi-mobile .content { overflow-y: auto !important; overflow-x: hidden !important; display: block !important; -webkit-overflow-scrolling: touch; }
  body.bi-mobile #bizDashboard { width: 100% !important; height: auto !important; flex-direction: column; padding: 12px; gap: 12px; }
  body.bi-mobile .biz-card { padding: 14px; }
  body.bi-mobile .biz-total, body.bi-mobile .biz-ind { flex: none; }
  body.bi-mobile .biz-ring-wrap { flex: none; }
  body.bi-mobile .biz-ring-shape { width: 190px; height: 190px; }
  body.bi-mobile .biz-total-nums strong { font-size: 36px; }
  /* Lista no celular: 1 coluna, linhas com altura do conteúdo. Os !important
     anulam o que o JS define pro painel de TV (linhas de altura fixa e fluxo
     por coluna) — sem isso as linhas ficavam com altura zero, empilhadas. */
  body.bi-mobile .biz-ind-list { grid-template-columns: 1fr !important; grid-template-rows: none !important; grid-auto-flow: row !important; grid-auto-rows: auto !important; flex: none; }
  body.bi-mobile .biz-ind-row { padding: 10px 12px; overflow: visible; }
  body.bi-mobile .biz-ind-name { font-size: 15px; }
  body.bi-mobile .biz-ind-qtd b { font-size: 24px; }
  body.bi-mobile .biz-ind-qtd span, body.bi-mobile .biz-ind-op { font-size: 12px; }
  body.bi-mobile .biz-total-msg { font-size: 15px; }
  body.bi-mobile .biz-stat b { font-size: 20px; }
  body.bi-mobile .biz-stat span { font-size: 9.5px; }

  .biz-fullscreen-btn { display: flex; align-items: center; gap: 7px; }
  /* Botão flutuante para sair da tela cheia quando o navegador não tem a API
     nativa (iPhone/fallback): a topbar some e não há Esc no celular. */
  .biz-fs-exit {
    display: none; position: fixed; top: 10px; right: 10px; z-index: 1000;
    width: 38px; height: 38px; border-radius: 50%; border: 1px solid rgba(255,255,255,.15);
    background: rgba(15,32,64,.75); color: #e8edf5; cursor: pointer;
    align-items: center; justify-content: center; opacity: .55;
  }
  .biz-fs-exit:hover, .biz-fs-exit:active { opacity: 1; }
  html:not(:fullscreen) body.biz-fs-fallback .biz-fs-exit { display: flex; }
  @media (max-width: 768px) { body.biz-fs-fallback .biz-fs-exit { display: flex; } }

  /* ── Topbar mais legível de longe (o painel fica numa TV) ── */
  .page-title h1 { font-size: 22px; font-weight: 800; }
  .page-title p { font-size: 14px; font-weight: 600; color: var(--biz-text); }
  .last-update { font-size: 14px; font-weight: 700; }

  /* ── Filtro de data discreto ── */
  .biz-date-filter { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: var(--biz-muted); }
  .biz-date-filter input[type="date"] { background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 8px; color: var(--biz-text); font: inherit; font-size: 12px; padding: 4px 8px; color-scheme: dark; }
  .biz-date-filter input[type="date"]:hover { border-color: rgba(88,214,201,.35); }
  .biz-date-nav { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 8px; color: var(--biz-text); cursor: pointer; padding: 0; }
  .biz-date-nav:hover:not(:disabled) { border-color: rgba(88,214,201,.35); color: var(--biz-teal); }
  .biz-date-nav:disabled { opacity: .35; cursor: default; }
  .biz-date-reset { color: var(--biz-teal); text-decoration: none; font-weight: 600; }
  .biz-date-reset[hidden] { display: none; }

  /* ── Indicador "ao vivo" ── */
  .biz-live { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--biz-teal); }
  .biz-live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--biz-teal); animation: bizPulse 1.8s infinite; }
  @keyframes bizPulse {
    0%   { box-shadow: 0 0 0 0 rgba(88,214,201,.55); }
    70%  { box-shadow: 0 0 0 7px rgba(88,214,201,0); }
    100% { box-shadow: 0 0 0 0 rgba(88,214,201,0); }
  }

  /* ── Tela cheia: esconde menus e barras, o painel ocupa a tela toda ── */
  html:fullscreen .sidebar, body.biz-fs-fallback .sidebar,
  html:fullscreen .topbar,  body.biz-fs-fallback .topbar { display: none !important; }
  html:fullscreen .mobile-topbar, body.biz-fs-fallback .mobile-topbar,
  html:fullscreen .mob-sub,       body.biz-fs-fallback .mob-sub,
  html:fullscreen .mob-scroll,    body.biz-fs-fallback .mob-scroll,
  html:fullscreen .mob-sub-backdrop, body.biz-fs-fallback .mob-sub-backdrop { display: none !important; }
  html:fullscreen .main, body.biz-fs-fallback .main { padding-top: 0 !important; }
  html:fullscreen .app-wrapper, body.biz-fs-fallback .app-wrapper { height: 100vh !important; overflow: hidden; }
  html:fullscreen .main,        body.biz-fs-fallback .main        { height: 100vh !important; overflow: hidden; }
  html:fullscreen .content,     body.biz-fs-fallback .content     { height: 100vh; padding: 0; align-items: center; overflow: hidden; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>BI Individual</h1>
          <p>Total de bags produzidos x meta do dia, e a produção de cada pessoa — atualização automática</p>
        </div>
      </div>
      <div class="topbar-actions">
        <span class="biz-live"><span class="biz-live-dot"></span>Ao vivo</span>
        <span class="biz-date-filter">
          <button type="button" class="biz-date-nav" id="biDataPrev" title="Dia anterior" aria-label="Dia anterior">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          </button>
          <input type="date" id="biDataFiltro" value="<?= biiEscape($payload['dia']) ?>" max="<?= date('Y-m-d') ?>" title="Ver produção de outro dia">
          <button type="button" class="biz-date-nav" id="biDataNext" title="Próximo dia" aria-label="Próximo dia"<?= $dia === date('Y-m-d') ? ' disabled' : '' ?>>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <a href="/pcp/bi-individual" class="biz-date-reset" id="biDataReset"<?= $dia === date('Y-m-d') ? ' hidden' : '' ?>>Hoje</a>
        </span>
        <span class="last-update">Atualizado às <span id="biUpdatedAt"><?= biiEscape($payload['atualizado_em']) ?></span></span>
        <a href="/pcp/bi" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
          <span class="biz-btn-long">BI da Produção</span><span class="biz-btn-short">BI Produção</span>
        </a>
        <button type="button" class="btn-secondary biz-fullscreen-btn" id="biFullscreenBtn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
          <span id="biFullscreenLabel">Tela cheia</span>
        </button>
      </div>
    </header>

    <button type="button" class="biz-fs-exit" id="biFsExit" title="Sair da tela cheia" aria-label="Sair da tela cheia">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3"/><path d="M21 8h-3a2 2 0 0 1-2-2V3"/><path d="M3 16h3a2 2 0 0 1 2 2v3"/><path d="M16 21v-3a2 2 0 0 1 2-2h3"/></svg>
    </button>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= biiEscape($error) ?>
        </div>
      <?php endif; ?>

      <div id="bizDashboard">

        <!-- Esquerda: total de bags produzidos no dia x meta total da equipe -->
        <div class="biz-card biz-total" id="biTotalCard">
          <div class="biz-card-head">
            <h2>Total Produzido <span id="biDiaTitulo"><?= biiEscape($payload['dia_label']) ?></span></h2>
            <div class="biz-head-right">
              <button type="button" class="biz-meta-btn" id="biMetaEditBtn" title="Editar meta total do dia" aria-label="Editar meta total do dia">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
              </button>
              <form class="biz-meta-form" id="biMetaForm">
                <input type="number" min="0" step="1" id="biMetaInput" value="<?= (int) $payload['meta'] ?>" title="Meta total de bags do dia">
                <button type="submit" class="biz-meta-save">Salvar</button>
                <button type="button" class="biz-meta-cancel" id="biMetaCancel">Cancelar</button>
              </form>
            </div>
          </div>
          <div class="biz-ring-wrap"><div class="biz-ring-shape"><svg id="biGaugeTotal"></svg></div></div>
          <div class="biz-total-foot">
            <div class="biz-total-nums"><strong id="biTotalQtd">0</strong> / <span class="meta" id="biMetaTxt">0</span> bags</div>
            <div class="biz-total-msg" id="biTotalMsg"></div>
            <div class="biz-total-stats">
              <div class="biz-stat"><b id="biStatPessoas">0</b><span>pessoas</span></div>
              <div class="biz-stat"><b id="biStatAgora">0</b><span>produzindo agora</span></div>
              <div class="biz-stat"><b id="biStatOps">0</b><span>O.Ps no dia</span></div>
            </div>
          </div>
        </div>

        <!-- Direita: produção de cada pessoa (ranking), com a O.P ativa -->
        <div class="biz-card biz-ind">
          <div class="biz-card-head">
            <h2>Produção Individual</h2>
            <span class="biz-count-badge" id="biFuncsCount">0</span>
          </div>
          <div class="biz-ind-list" id="biIndList"></div>
        </div>

      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
function biFmt(n, casas) {
  return Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: casas || 0, maximumFractionDigits: casas || 0 });
}
function biEsc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
let biMetaAtual = <?= (float) $payload['meta'] ?>;
let biUltimosFuncs = [];
const biMqMobile = window.matchMedia('(max-width: 768px)');
function biIsKiosk() {
  return document.body.classList.contains('biz-fs-fallback') || !!biFsElement();
}
let biDiaLabel = <?= json_encode($payload['dia_label'], JSON_UNESCAPED_UNICODE) ?>;
const biDiaAtual = <?= json_encode($payload['dia']) ?>;

// ── Filtro de data: recarrega a página com ?data=YYYY-MM-DD (ou volta pra
// hoje). O padrão é sempre hoje — sem parâmetro na URL. ─────────────────────
const biDataInput = document.getElementById('biDataFiltro');
function biIrParaData(v) {
  const hoje = biDataInput ? biDataInput.max : '';
  window.location.href = (!v || v === hoje) ? '/pcp/bi-individual' : '/pcp/bi-individual?data=' + encodeURIComponent(v);
}
function biDeslocaDia(dias) {
  const d = new Date(biDiaAtual + 'T00:00:00');
  d.setDate(d.getDate() + dias);
  const iso = d.toISOString().slice(0, 10);
  if (biDataInput && iso > biDataInput.max) return; // não navega pro futuro
  biIrParaData(iso);
}
if (biDataInput) {
  biDataInput.addEventListener('change', () => biIrParaData(biDataInput.value));
}
document.getElementById('biDataPrev')?.addEventListener('click', () => biDeslocaDia(-1));
document.getElementById('biDataNext')?.addEventListener('click', () => biDeslocaDia(1));

// ── Anel de progresso do total: azul com brilho quando a equipe bate a meta ──
function biRenderGauge(svg, pct, hit, semMeta) {
  const clamped = Math.max(0, Math.min(100, pct));
  const r = 82, cx = 100, cy = 100, sw = 24;
  const circ = 2 * Math.PI * r;
  const dash = circ * (clamped / 100);
  const fg = clamped > 0
    ? `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="url(#biGradTotal)" stroke-width="${sw}"
         stroke-linecap="round" stroke-dasharray="${dash.toFixed(1)} ${(circ - dash).toFixed(1)}"
         transform="rotate(-90 ${cx} ${cy})" ${hit ? 'filter="url(#biGlowTotal)"' : ''}/>`
    : '';
  svg.setAttribute('viewBox', '0 0 200 200');
  svg.innerHTML = `
    <defs>
      <linearGradient id="biGradTotal" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="${hit ? '#2d6aff' : '#3aa7ff'}"/>
        <stop offset="100%" stop-color="#58d6c9"/>
      </linearGradient>
      <filter id="biGlowTotal" x="-60%" y="-60%" width="220%" height="220%">
        <feGaussianBlur stdDeviation="5" result="blur"/>
        <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="${sw}"/>
    ${fg}
    <text x="${cx}" y="${cy}" text-anchor="middle" dominant-baseline="central" fill="#eef2f7" font-size="${semMeta ? 22 : 46}" font-weight="900">${semMeta ? 'Defina a meta' : biFmt(pct, 0) + '%'}</text>
  `;
}

// ── Total do dia x meta total ────────────────────────────────────────────────
function biRenderTotal(funcs) {
  const total = funcs.reduce((s, f) => s + Number(f.qtd_produzida || 0), 0);
  const meta = biMetaAtual;
  const hit = meta > 0 && total >= meta;
  const pct = meta > 0 ? (total / meta) * 100 : 0;

  document.getElementById('biTotalCard').classList.toggle('hit', hit);
  biRenderGauge(document.getElementById('biGaugeTotal'), pct, hit, meta <= 0);
  document.getElementById('biTotalQtd').textContent = biFmt(total, 0);
  if (!biMetaForm.classList.contains('open')) {
    document.getElementById('biMetaTxt').textContent = meta > 0 ? biFmt(meta, 0) : '—';
  }
  document.getElementById('biTotalMsg').textContent = meta <= 0
    ? 'Clique no lápis para definir a meta do dia'
    : hit
      ? (total > meta ? `Meta batida — ${biFmt(total - meta, 0)} acima!` : 'Meta batida!')
      : `Faltam ${biFmt(meta - total, 0)} para a meta`;

  const ops = new Set();
  funcs.forEach(f => (f.ops_detalhe || []).forEach(o => ops.add(o.op)));
  document.getElementById('biStatPessoas').textContent = funcs.length;
  document.getElementById('biStatAgora').textContent = funcs.filter(f => f.em_producao).length;
  document.getElementById('biStatOps').textContent = ops.size;
  return total;
}

// ── O.P ativa da pessoa (apontamento em aberto) — "O.P xxxx - cliente" ──────
function biFuncOpAtiva(f) {
  const ativas = (f.ops_detalhe || []).filter(o => o.em_producao);
  if (!ativas.length) {
    return '<div class="biz-ind-op off"><span>Nenhuma O.P ativa</span></div>';
  }
  const o = ativas[0];
  const mais = ativas.length > 1 ? ` (+${ativas.length - 1})` : '';
  return `<div class="biz-ind-op"><span class="biz-live-dot-sm"></span><span><strong>O.P ${o.op}</strong>${o.cliente ? ' - ' + biEsc(o.cliente) : ''}${mais}</span></div>`;
}

// ── Lista individual: barra proporcional a quem mais produziu, quantidade e
// participação no total. Com muita gente, 2 colunas pra caber na TV. ────────
function biRenderFuncs(funcs) {
  const total = biRenderTotal(funcs);
  document.getElementById('biFuncsCount').textContent = funcs.length + (funcs.length === 1 ? ' pessoa' : ' pessoas');
  const list = document.getElementById('biIndList');
  if (!funcs.length) {
    list.style.gridTemplateColumns = '1fr';
    list.style.gridTemplateRows = '';
    list.innerHTML = '<div class="biz-empty-msg">Ninguém produziu ' + (biDiaLabel === 'HOJE' ? 'hoje' : 'em ' + biEsc(biDiaLabel)) + '.</div>';
    return;
  }
  biAjustaLinhas(funcs.length);
  const maior = Math.max(...funcs.map(f => Number(f.qtd_produzida || 0)), 1);
  list.innerHTML = funcs.map(f => {
    const qtd = Number(f.qtd_produzida || 0);
    const part = total > 0 ? (qtd / total) * 100 : 0;
    return `
      <div class="biz-ind-row">
        <div class="biz-ind-name">${biEsc(f.nome)}</div>
        <div class="biz-ind-qtd"><b>${biFmt(qtd, 0)}</b><span>${biFmt(part, 0)}% do total</span></div>
        <div class="biz-ind-bar"><div class="biz-ind-fill" style="width:${(qtd / maior) * 100}%"></div></div>
        ${biFuncOpAtiva(f)}
      </div>`;
  }).join('');
}

// ── Grade da lista: colunas e altura das linhas conforme o espaço real ──────
// Mais de 10 pessoas → 2 colunas (preenchidas coluna por coluna, pra ler o
// ranking de cima pra baixo). As linhas dividem a altura do card, mas nunca
// passam de ~110px: em tela alta (retrato) ou com pouca gente, sobra espaço
// embaixo em vez de linhas enormes e vazias. No celular o CSS ignora isto.
const BI_LINHA_MAX_H = 110;
function biAjustaLinhas(n) {
  const list = document.getElementById('biIndList');
  if (!n) return;
  const cols = n > 10 ? 2 : 1;
  const linhas = Math.ceil(n / cols);
  const cabem = Math.floor((list.clientHeight || 0) / BI_LINHA_MAX_H);
  list.style.gridTemplateColumns = `repeat(${cols}, minmax(0, 1fr))`;
  // Sobra altura: linhas de altura fixa (colunas equilibradas, espaço livre
  // embaixo). Senão: dividem a altura igualmente.
  list.style.gridTemplateRows = cabem > linhas
    ? `repeat(${linhas}, ${BI_LINHA_MAX_H}px)`
    : `repeat(${linhas}, minmax(0, 1fr))`;
  list.style.gridAutoFlow = cols > 1 ? 'column' : 'row';
}

// ── Editar meta total (lápis no card do total) ───────────────────────────────
const biMetaForm = document.getElementById('biMetaForm');
const biMetaInput = document.getElementById('biMetaInput');
document.getElementById('biMetaEditBtn').addEventListener('click', () => {
  biMetaInput.value = Math.round(biMetaAtual);
  biMetaForm.classList.add('open');
  biMetaInput.focus();
  biMetaInput.select();
});
document.getElementById('biMetaCancel').addEventListener('click', () => {
  biMetaForm.classList.remove('open');
});
biMetaForm.addEventListener('submit', (e) => {
  e.preventDefault();
  const valor = Math.max(0, Number(biMetaInput.value || 0));
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=save_meta&meta=' + encodeURIComponent(valor),
  })
    .then(r => r.json())
    .then(data => {
      biMetaForm.classList.remove('open');
      if (data.ok) {
        biMetaAtual = Number(data.meta);
        biRenderFuncs(biUltimosFuncs);
      }
    })
    .catch(() => { biMetaForm.classList.remove('open'); });
});

// ── Ajusta #bizDashboard ao espaço real de .content: no celular vira página
// rolável; no resto (inclusive tela cheia/TV) ocupa exatamente a área visível.
function biApplyScale() {
  const stage = document.getElementById('bizDashboard');
  const wrap = stage.parentElement; // .content
  const availW = wrap.clientWidth;
  const availH = wrap.clientHeight;
  if (!availW || !availH) return;

  // Celular fora da tela cheia (não zera o scroll — a atualização a cada 15s
  // não pode pular pro topo).
  const mobile = !biIsKiosk() && biMqMobile.matches;
  document.body.classList.toggle('bi-mobile', mobile);
  if (mobile) {
    document.body.classList.remove('bi-vertical');
    stage.style.width = '';
    stage.style.height = '';
    return;
  }
  // Tela em pé (retrato) ou estreita: total numa faixa no topo, lista embaixo
  document.body.classList.toggle('bi-vertical', availH > availW * 0.85 || availW < 900);
  stage.style.width = availW + 'px';
  stage.style.height = availH + 'px';
  wrap.scrollTop = 0;
  // A altura da lista mudou junto com o painel: recalcula as linhas
  biAjustaLinhas(biUltimosFuncs.length);
}
window.addEventListener('resize', biApplyScale);
biMqMobile.addEventListener('change', biApplyScale);

function biRenderAll(data) {
  biMetaAtual = Number(data.meta || 0);
  if (data.dia_label) biDiaLabel = data.dia_label;
  biUltimosFuncs = data.funcionarios || [];
  biRenderFuncs(biUltimosFuncs);
  const upd = document.getElementById('biUpdatedAt');
  if (upd) upd.textContent = data.atualizado_em || '';
  biApplyScale();
}

const BI_INITIAL = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;
biRenderAll(BI_INITIAL);

// ── Atualização periódica (tempo real) ────────────────────────────────────────
function biRefresh() {
  fetch('?action=refresh&data=' + encodeURIComponent(biDiaAtual), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (data.error) return;
      biRenderAll(data);
    })
    .catch(() => {});
}
setInterval(biRefresh, 15000);

// ── Tela cheia (Fullscreen API + fallback via classe CSS, pra funcionar
// mesmo se o navegador bloquear a API nativa) ───────────────────────────────
const biFsBtn = document.getElementById('biFullscreenBtn');
const biFsLabel = document.getElementById('biFullscreenLabel');

function biFsElement() {
  return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
}
function biRequestFs(el) {
  const fn = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
  return fn ? fn.call(el) : Promise.reject(new Error('Fullscreen API indisponível'));
}
function biExitFs() {
  const fn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
  return fn ? fn.call(document) : Promise.reject(new Error('Fullscreen API indisponível'));
}
function biSetFsState(active) {
  document.body.classList.toggle('biz-fs-fallback', active);
  biFsLabel.textContent = active ? 'Sair da tela cheia' : 'Tela cheia';
  requestAnimationFrame(biApplyScale);
  // A animação da tela cheia do sistema pode demorar mais que um frame
  setTimeout(biApplyScale, 300);
}

biFsBtn.addEventListener('click', () => {
  const active = document.body.classList.contains('biz-fs-fallback');
  if (!active) {
    biSetFsState(true);
    biRequestFs(document.documentElement).catch(() => {});
  } else {
    biSetFsState(false);
    if (biFsElement()) biExitFs().catch(() => {});
  }
});
document.getElementById('biFsExit').addEventListener('click', () => {
  biSetFsState(false);
  if (biFsElement()) biExitFs().catch(() => {});
});
['fullscreenchange', 'webkitfullscreenchange', 'MSFullscreenChange'].forEach(ev => {
  document.addEventListener(ev, () => {
    if (!biFsElement()) {
      biSetFsState(false);
    } else {
      requestAnimationFrame(biApplyScale);
    }
  });
});
</script>
</body>
</html>
