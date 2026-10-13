<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/bi_individual_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — BI Individual (produção de cada funcionário no dia x meta
//  diária individual, painel para TV)
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

// ── AJAX: salvar meta individual ──────────────────────────────────────────────
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
    --biz-text: #eef2f7;
    --biz-muted: #8fa0b3;
    /* Largura de referência do painel: o #bizDashboard é sempre desenhado
       nessa largura fixa e depois escalado (JS: biApplyScale) pra cobrir o
       espaço real disponível — mesma técnica do BI da Produção. */
    --bi-ref-w: 1800px;
  }

  /* Painel de TV/kiosk: em vez de reorganizar colunas por breakpoint, o
     #bizDashboard é desenhado num tamanho fixo de referência (ver mais
     abaixo) e o JS (biApplyScale) calcula um único fator de escala pra
     cobrir o espaço disponível, aplicado via transform:scale() — a
     aparência fica idêntica em qualquer tamanho de tela, só menor/maior. */
  .content { display: flex; align-items: flex-start; justify-content: center; overflow: hidden; min-width: 0; padding: 0; }

  /* ── Fora da tela cheia: o painel preenche a largura E cabe na altura
     visível (JS fixa a altura em px). min-height some pra que a grade e os
     anéis encolham em vez de empurrar o conteúdo pra fora do painel. ── */
  body.bi-fit .content { overflow: hidden; }
  body.bi-fit #bizDashboard { min-height: 0; }
  body.bi-fit .biz-func-grid { min-height: 0; }
  body.bi-fit .biz-fu-card { overflow: hidden; }
  @media (max-width: 768px) {
    .app-wrapper { height: 100vh !important; overflow: hidden !important; flex-direction: row !important; }
    .main { height: 100vh !important; overflow: hidden !important; }
    /* altura = o que sobra abaixo das barras (que agora podem ter 2-3 linhas) */
    .content { padding: 0 !important; overflow: hidden !important; height: auto !important; min-height: 0; flex: 1 1 auto !important; display: flex !important; }
  }

  #bizDashboard {
    width: var(--bi-ref-w);
    min-height: 1000px;
    display: flex; flex-direction: column; gap: 16px;
    background: var(--biz-bg); border-width: 0; border-radius: 0;
    padding: 26px 28px; color: var(--biz-text); font-family: 'Segoe UI', sans-serif;
    box-shadow: none; box-sizing: border-box;
    flex-shrink: 0; transform-origin: center center;
    /* center (não "top"): fora da tela cheia não há transform (JS usa
       width/height fixos), então isso só importa pra tela cheia, onde o
       painel é centralizado por flex ANTES da escala — com origem no topo,
       o excesso de altura "crescia" sempre pra cima e ficava fora da tela. */
    /* As fontes dos cards usam cqw (abaixo) em vez de vw: precisam ser
       relativas à LARGURA DESENHADA do próprio painel, não à janela real —
       na tela cheia o painel é desenhado numa largura de referência quase
       fixa e depois escalado por transform, então vw ficaria errado
       (baseado na janela real) e quebraria o tamanho consistente da TV. */
    container-type: inline-size;
  }

  .biz-card { background: var(--biz-card); border: 1px solid var(--biz-border); border-radius: 14px; padding: 16px 20px 18px; box-shadow: 0 10px 24px -16px rgba(0,0,0,.5); min-width: 0; }
  .biz-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--biz-border); }
  .biz-card-head h2 { font-size: clamp(12px, .9cqw, 16px); font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--biz-text); }
  .biz-count-badge { font-size: clamp(11px, .8cqw, 13px); font-weight: 800; padding: 3px 11px; border-radius: 20px; background: rgba(88,214,201,.18); color: var(--biz-teal); }

  .biz-empty-msg { text-align: center; color: var(--biz-text); font-size: clamp(12px, 1cqw, 16px); font-weight: 700; padding: 40px 0; }

  /* ── Meta individual editável no cabeçalho do card (mesmo padrão do BI da Produção) ── */
  .biz-head-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
  .biz-meta-label { font-size: clamp(11px, .85cqw, 14px); font-weight: 700; color: var(--biz-muted); }
  .biz-meta-label strong { color: var(--biz-text); font-variant-numeric: tabular-nums; }
  .biz-meta-btn { width: 24px; height: 24px; border-radius: 6px; border: 1px solid var(--biz-border); background: transparent; color: var(--biz-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .biz-meta-btn:hover { background: rgba(255,255,255,.06); color: var(--biz-text); }
  .biz-meta-form { display: none; align-items: center; gap: 6px; }
  .biz-meta-form.open { display: flex; }
  .biz-meta-form input { width: 90px; background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 6px; color: var(--biz-text); font: inherit; font-size: 12px; padding: 4px 8px; }
  .biz-meta-save, .biz-meta-cancel { font: inherit; font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 6px; cursor: pointer; }
  .biz-meta-save { background: var(--biz-teal-2); color: #05070a; border: 0; }
  .biz-meta-cancel { background: transparent; color: var(--biz-muted); border: 1px solid var(--biz-border); }
  body.biz-fs-fallback .biz-meta-btn, body.biz-fs-fallback .biz-meta-form { display: none !important; }

  /* ── Posição no ranking do dia e indicador "produzindo agora" ── */
  /* OP ativa no card: uma linha, com reticências se a descrição for longa */
  .biz-fu-op { display: flex; align-items: center; justify-content: center; gap: 7px; min-width: 0; color: var(--biz-teal); }
  .biz-fu-op .biz-op-live-dot { flex: 0 0 auto; }
  .biz-fu-op-txt { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .biz-fu-op-txt strong { font-weight: 900; }
  .biz-fu-op-mais { flex: 0 0 auto; font-size: .8em; font-weight: 800; padding: 1px 7px; border-radius: 20px; background: rgba(88,214,201,.15); }
  .biz-fu-op-off { color: var(--biz-muted); }

  /* ── Grade de funcionários: um card por funcionário, ocupando 100% da
     célula da grade (largura e altura) — o anel de progresso cresce pra
     preencher todo o espaço vertical sobrando, em vez de ficar pequeno e
     centralizado numa área grande vazia. ── */
  .biz-func-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr)); grid-auto-rows: 1fr; gap: 20px; flex: 1 1 auto; min-height: 760px; }

  .biz-fu-card { border: 1px solid var(--biz-border); border-radius: 14px; padding: 20px 22px; background: var(--biz-card2); display: flex; flex-direction: column; height: 100%; min-width: 0; min-height: 0; transition: border-color .15s, background .2s, box-shadow .2s; }
  .biz-fu-card:hover { border-color: rgba(88,214,201,.35); }

  /* ── Poucos funcionários: o card vira duas colunas — anel à esquerda e um
     painel de detalhamento à direita (OPs e centros de trabalho do dia),
     aproveitando o espaço que antes ficava vazio. ── */
  .biz-fu-card.has-detail { flex-direction: row; align-items: stretch; gap: 26px; padding: 22px 26px; }
  .biz-fu-card.has-detail .biz-fu-left { flex: 0 0 44%; min-width: 0; display: flex; flex-direction: column; }
  .biz-fu-left { display: contents; }

  .biz-fu-detail { display: none; }
  .biz-fu-card.has-detail .biz-fu-detail {
    display: flex; flex-direction: column; gap: 18px; flex: 1 1 0; min-width: 0;
    border-left: 1px solid var(--biz-border); padding-left: 26px; overflow: hidden;
  }
  .biz-fu-detail-sec { display: flex; flex-direction: column; min-height: 0; }
  .biz-fu-detail-sec h4 { font-size: clamp(10px, .8cqw, 14px); font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--biz-teal); margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid var(--biz-border); }
  .biz-detail-list { display: flex; flex-direction: column; gap: 2px; overflow: hidden; }
  .biz-detail-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 7px 2px; font-size: clamp(12px, 1.1cqw, 18px); font-weight: 700; border-bottom: 1px solid rgba(255,255,255,.07); }
  .biz-detail-row:last-child { border-bottom: 0; }
  .biz-detail-row span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--biz-text); }
  .biz-detail-row strong { flex: 0 0 auto; color: var(--biz-teal); font-weight: 800; font-variant-numeric: tabular-nums; }
  .biz-detail-empty { font-size: clamp(12px, 1cqw, 16px); font-weight: 700; color: var(--biz-muted); padding: 6px 2px; }

  .biz-op-row span { flex: 1 1 auto; }
  .biz-op-live { flex: 0 0 auto; font-style: normal; font-size: clamp(10px, .8cqw, 13px); font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--biz-teal); display: inline-flex; align-items: center; gap: 5px; }
  .biz-op-live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--biz-teal); box-shadow: 0 0 0 0 rgba(88,214,201,.6); animation: bizPulse 1.8s infinite; }

  .biz-fu-head { text-align: center; flex: 0 0 auto; }
  /* nome sempre em uma linha: cards com nomes longos não podem ter anel menor */
  .biz-fu-name { font-size: clamp(18px, 1.7cqw, 32px); font-weight: 800; color: var(--biz-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .biz-fu-sub { font-size: clamp(12px, 1cqw, 17px); font-weight: 700; color: var(--biz-text); margin-top: 8px; }

  /* O SVG do anel é sempre quadrado (aspect-ratio) e cresce até o limite do
     espaço disponível (altura OU largura, o que for menor) — assim ele
     realmente ocupa a área que sobra, em vez de um tamanho fixo em px. */
  .biz-fu-ring-wrap { flex: 1 1 auto; min-height: 0; display: flex; padding: 6px 0; }
  .biz-fu-ring-shape { margin: auto; aspect-ratio: 1 / 1; height: 100%; max-width: 100%; }
  .biz-fu-ring-shape svg { width: 100%; height: 100%; display: block; overflow: visible; }

  .biz-fu-foot { flex: 0 0 auto; text-align: center; }
  .biz-fu-nums { font-size: clamp(13px, 1.1cqw, 20px); font-weight: 700; color: var(--biz-muted); }
  .biz-fu-nums strong { font-size: clamp(26px, 2.8cqw, 50px); color: white; font-weight: 900; font-variant-numeric: tabular-nums; }
  .biz-fu-msg { margin-top: 8px; font-size: clamp(15px, 1.4cqw, 24px); font-weight: 800; }
  .biz-fu-msg.msg-hit    { color: #7db3ff; }
  .biz-fu-msg.msg-behind { color: var(--biz-text); font-weight: 700; }

  /* ── Funcionário que bateu a meta diária: destaque azul no card inteiro ── */
  .biz-fu-card.biz-fu-hit { border-color: rgba(45,106,255,.5); background: rgba(45,106,255,.1); box-shadow: 0 0 0 1px rgba(45,106,255,.3), 0 0 32px -8px rgba(45,106,255,.35); }
  .biz-fu-card.biz-fu-hit .biz-fu-name { color: #7db3ff; }

  /* ── Topbar desta página: badge "Ao vivo" + hora + botões de texto não
     cabem numa única linha em telas de celular — mesma regra do BI da
     Produção. ── */
  .biz-btn-short { display: none; }
  @media (max-width: 768px) {
    .topbar { flex-wrap: wrap; row-gap: 8px; }
    .topbar-left { width: 100%; }
    .topbar-actions { width: 100%; flex-wrap: wrap; row-gap: 8px; column-gap: 8px; }
    .last-update { display: none; }
    /* Linha 1: ao vivo + data. Linha 2: os três botões dividindo a largura */
    .biz-date-filter { margin-left: auto; }
    .topbar-actions .btn-secondary {
      flex: 1 1 0; min-width: 0; justify-content: center;
      padding: 8px 10px; font-size: 12px; white-space: nowrap; overflow: hidden;
    }
    .biz-btn-long { display: none; }
    .biz-btn-short { display: inline; }
  }

  /* ── Celular (fora da tela cheia): em vez de espremer tudo na altura da
     tela, vira uma lista rolável — um funcionário por linha, com anel de tamanho
     fixo e o detalhamento (OPs/centros de trabalho) abaixo dele. JS: biApplyScale. ── */
  body.bi-mobile .content { overflow-y: auto !important; overflow-x: hidden !important; display: block !important; -webkit-overflow-scrolling: touch; }
  body.bi-mobile #bizDashboard { width: 100% !important; min-width: 0; min-height: 0; padding: 12px; transform: none !important; }
  body.bi-mobile .biz-card { padding: 14px; }
  body.bi-mobile .biz-func-grid { grid-template-columns: 1fr !important; grid-auto-rows: auto; min-height: 0; gap: 14px; }
  body.bi-mobile .biz-fu-card,
  body.bi-mobile .biz-fu-card.has-detail { flex-direction: column; height: auto; padding: 16px; gap: 14px; }
  body.bi-mobile .biz-fu-card .biz-fu-left { display: flex; flex-direction: column; flex: none; }
  body.bi-mobile .biz-fu-ring-wrap { flex: none; }
  body.bi-mobile .biz-fu-ring-shape { width: 150px; height: 150px; }
  body.bi-mobile .biz-fu-name { font-size: 20px; }
  body.bi-mobile .biz-fu-sub { font-size: 13px; }
  body.bi-mobile .biz-fu-nums strong { font-size: 28px; }
  body.bi-mobile .biz-fu-msg { font-size: 15px; }
  body.bi-mobile .biz-fu-card .biz-fu-detail {
    display: flex; flex-direction: column; gap: 14px;
    border-left: 0; padding-left: 0; border-top: 1px solid var(--biz-border); padding-top: 12px; overflow: visible;
  }
  body.bi-mobile .biz-detail-list { overflow: visible; }
  body.bi-mobile .biz-fu-detail-sec h4 { font-size: 11px; }
  body.bi-mobile .biz-detail-row { font-size: 13px; }
  body.bi-mobile .biz-detail-empty { font-size: 13px; }
  body.bi-mobile .biz-op-live { font-size: 10px; }

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
  @media (max-width: 768px) {
    body.biz-fs-fallback .biz-fs-exit { display: flex; }
  }

  /* ── Topbar mais legível de longe (o painel fica numa TV) ── */
  .page-title h1 { font-size: 22px; font-weight: 800; }
  .page-title p { font-size: 14px; font-weight: 600; color: var(--biz-text); }
  .biz-live { font-size: 13px !important; font-weight: 800 !important; }
  .last-update { font-size: 14px; font-weight: 700; }

  /* ── Filtro de data discreto: parece parte da topbar, some quando é hoje ── */
  .biz-date-filter { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: var(--biz-muted); }
  .biz-date-filter input[type="date"] {
    background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 8px;
    color: var(--biz-text); font: inherit; font-size: 12px; padding: 4px 8px; color-scheme: dark;
  }
  .biz-date-filter input[type="date"]:hover { border-color: rgba(88,214,201,.35); }
  .biz-date-nav {
    display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px;
    background: var(--biz-card2); border: 1px solid var(--biz-border); border-radius: 8px;
    color: var(--biz-text); cursor: pointer; padding: 0;
  }
  .biz-date-nav:hover:not(:disabled) { border-color: rgba(88,214,201,.35); color: var(--biz-teal); }
  .biz-date-nav:disabled { opacity: .35; cursor: default; }
  .biz-date-reset { color: var(--biz-teal); text-decoration: none; font-weight: 600; }
  .biz-date-reset[hidden] { display: none; }

  /* ── Indicador "ao vivo" ── */
  .biz-live { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--biz-teal); }
  .biz-live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--biz-teal); box-shadow: 0 0 0 0 rgba(88,214,201,.6); animation: bizPulse 1.8s infinite; }
  @keyframes bizPulse {
    0%   { box-shadow: 0 0 0 0 rgba(88,214,201,.55); }
    70%  { box-shadow: 0 0 0 7px rgba(88,214,201,0); }
    100% { box-shadow: 0 0 0 0 rgba(88,214,201,0); }
  }

  /* ── Modo tela cheia: esconde sidebar/topbar, dando mais espaço real pro
     JS (biApplyScale) escalar o painel — mesma técnica do BI da Produção. ── */
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
          <p>Produção de cada funcionário no dia, comparada com a meta diária individual — atualização automática</p>
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

        <!-- Produção individual — um card por funcionário que apontou no dia, vs meta diária
             individual (a mesma para todos, editável aqui no lápis) -->
        <div class="biz-card" style="flex:1 1 auto; display:flex; flex-direction:column; min-height:0;">
          <div class="biz-card-head">
            <h2>Produção Individual <span id="biDiaTitulo"><?= biiEscape($payload['dia_label']) ?></span></h2>
            <div class="biz-head-right">
              <span class="biz-meta-label">Meta individual: <strong id="biMetaTxt"><?= number_format((float) $payload['meta'], 0, ',', '.') ?></strong></span>
              <button type="button" class="biz-meta-btn" id="biMetaEditBtn" title="Editar meta individual" aria-label="Editar meta individual">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
              </button>
              <form class="biz-meta-form" id="biMetaForm">
                <input type="number" min="0" step="1" id="biMetaInput" value="<?= (int) $payload['meta'] ?>">
                <button type="submit" class="biz-meta-save">Salvar</button>
                <button type="button" class="biz-meta-cancel" id="biMetaCancel">Cancelar</button>
              </form>
              <span class="biz-count-badge" id="biFuncsCount">0</span>
            </div>
          </div>
          <div class="biz-func-grid" id="biFuncsGrid"></div>
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

// ── Anel de progresso em círculo cheio (em vez do semicírculo do BI da
// Produção): ocupa toda a área quadrada disponível no card e fica mais fácil
// de ler à distância. Vira azul e ganha um brilho quando bate a meta — um
// retorno visual imediato que funciona como incentivo à produção. ──────────
function biRenderGauge(svg, pct, hit) {
  const clamped = Math.max(0, Math.min(100, pct));
  const gradId = 'biGrad' + Math.random().toString(36).slice(2);
  const r = 82, cx = 100, cy = 100, sw = 26;
  const circ = 2 * Math.PI * r;
  const dash = circ * (clamped / 100);
  const colorFrom = hit ? '#2d6aff' : '#3aa7ff';
  const colorTo   = hit ? '#58d6c9' : '#58d6c9';

  const fgCircle = clamped > 0
    ? `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="url(#${gradId})" stroke-width="${sw}"
         stroke-linecap="round" stroke-dasharray="${dash.toFixed(1)} ${(circ - dash).toFixed(1)}"
         transform="rotate(-90 ${cx} ${cy})" ${hit ? 'filter="url(#' + gradId + 'Glow)"' : ''}/>`
    : '';

  svg.setAttribute('viewBox', '0 0 200 200');
  svg.innerHTML = `
    <defs>
      <linearGradient id="${gradId}" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="${colorFrom}"/>
        <stop offset="100%" stop-color="${colorTo}"/>
      </linearGradient>
      <filter id="${gradId}Glow" x="-60%" y="-60%" width="220%" height="220%">
        <feGaussianBlur stdDeviation="5" result="blur"/>
        <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="${sw}"/>
    ${fgCircle}
    <text x="${cx}" y="${cy}" text-anchor="middle" dominant-baseline="central" fill="#eef2f7" font-size="48" font-weight="900">${biFmt(pct, 0)}%</text>
  `;
}

// ── Nº de colunas da grade: testa cada combinação colunas x linhas e fica com
// a que deixa o anel de progresso maior no espaço real disponível — numa TV
// 16:9 com 8 funcionários vira 4x2, num monitor mais quadrado 3x3, etc. ─────
let biColsAtual = 0;
function biFuncsCols(n) {
  if (n <= 1) return 1;
  const wrap = document.querySelector('.content');
  const W = (wrap ? wrap.clientWidth : 0) - 60;    // padding do painel e do card
  const H = (wrap ? wrap.clientHeight : 0) - 110;  // padding + cabeçalho do card
  if (W <= 0 || H <= 0) return Math.ceil(Math.sqrt(n));
  const TEXTO_H = 150;  // nome + subtítulo + "Produzido x Meta" de cada card
  let melhor = 1, melhorAnel = -Infinity;
  for (let c = 1; c <= n; c++) {
    const r = Math.ceil(n / c);
    const cw = (W - (c - 1) * 20) / c;
    const ch = (H - (r - 1) * 20) / r;
    // Com até 2 colunas o anel divide a largura com o detalhamento (44%)
    const anel = Math.min(c <= 2 ? cw * 0.44 : cw - 44, ch - TEXTO_H);
    if (anel > melhorAnel + 1) { melhorAnel = anel; melhor = c; }
  }
  return melhor;
}

// ── Produção individual — um card por funcionário (ranking), vs meta ────────
function biRenderFuncs(funcs) {
  document.getElementById('biFuncsCount').textContent = funcs.length;
  const grid = document.getElementById('biFuncsGrid');
  if (!funcs.length) {
    grid.style.gridTemplateColumns = '1fr';
    grid.innerHTML = '<div class="biz-empty-msg">Ninguém produziu ' + (biDiaLabel === 'HOJE' ? 'hoje' : 'em ' + biEsc(biDiaLabel)) + '.</div>';
    return;
  }
  const cols = biFuncsCols(funcs.length);
  biColsAtual = cols;
  const mobileLista = biMqMobile.matches && !biIsKiosk();

  // Última linha incompleta (ex.: 7 funcionários em 4 colunas = 4 + 3): os
  // cards dela se esticam pra preencher a largura toda. A grade passa a ter
  // MMC(colunas, sobra) trilhas — cada card normal ocupa total/colunas
  // trilhas e cada card da última linha total/sobra. No celular (lista de
  // uma coluna) não se aplica: span criaria colunas implícitas.
  const sobra = mobileLista ? 0 : funcs.length % cols;
  const mdc = (a, b) => (b ? mdc(b, a % b) : a);
  const trilhas = sobra ? (cols * sobra) / mdc(cols, sobra) : cols;
  const spanNormal = trilhas / cols;
  const spanUltima = sobra ? trilhas / sobra : spanNormal;
  const inicioUltima = funcs.length - sobra;
  grid.style.gridTemplateColumns = `repeat(${trilhas}, 1fr)`;

  // Até 2 colunas de cards (ou seja, até 4 funcionários) os cards são largos
  // o bastante pra mostrar o painel de detalhamento ao lado do anel; com mais
  // colunas ficaria apertado demais.
  // No celular (lista de uma coluna) o detalhamento vai abaixo do anel, então
  // cabe sempre, qualquer que seja a quantidade de funcionários.
  const comDetalhe = cols <= 2 || mobileLista;
  const meta = biMetaAtual;
  grid.innerHTML = funcs.map((f, i) => {
    const hit = meta > 0 && f.qtd_produzida >= meta;
    const msg = biFuncMensagem(f.qtd_produzida, hit, meta) || 'Produzido x Meta';
    const span = mobileLista ? '' : ` style="grid-column: span ${i >= inicioUltima ? spanUltima : spanNormal}"`;
    return `
    <div class="biz-fu-card${hit ? ' biz-fu-hit' : ''}${comDetalhe ? ' has-detail' : ''}"${span}>
      <div class="biz-fu-left">
        <div class="biz-fu-head">
          <div class="biz-fu-name">${biEsc(f.nome)}</div>
        </div>
        <div class="biz-fu-ring-wrap"><div class="biz-fu-ring-shape"><svg class="biz-gauge"></svg></div></div>
        <div class="biz-fu-foot">
          <div class="biz-fu-msg${hit ? ' msg-hit' : ' msg-behind'}">${msg}</div>
          <div class="biz-fu-nums"> <strong>${biFmt(f.qtd_produzida, 0)}</strong> / <strong>${biFmt(meta, 0)}</strong></div>
          <div class="biz-fu-sub">${biFuncOpAtiva(f)}</div>
        </div>
      </div>
      ${comDetalhe ? biFuncDetalhe(f) : ''}
    </div>
  `;
  }).join('');

  const svgs = grid.querySelectorAll('.biz-gauge');
  funcs.forEach((f, i) => {
    const pct = meta > 0 ? (f.qtd_produzida / meta) * 100 : 0;
    const hit = meta > 0 && f.qtd_produzida >= meta;
    biRenderGauge(svgs[i], pct, hit);
  });
}

// ── OP ativa do funcionário (apontamento em aberto), sempre visível no card.
// ops_detalhe já vem com as OPs em produção primeiro. ──────────────────────
function biFuncOpAtiva(f) {
  const ativas = (f.ops_detalhe || []).filter(o => o.em_producao);
  if (!ativas.length) {
    return '<div class="biz-fu-op biz-fu-op-off">Nenhuma O.P ativa</div>';
  }
  const o = ativas[0];
  const mais = ativas.length > 1 ? ` <span class="biz-fu-op-mais">+${ativas.length - 1}</span>` : '';
  return `<div class="biz-fu-op">
    <span class="biz-op-live-dot"></span>
    <span class="biz-fu-op-txt"><strong>O.P ${o.op}</strong>${o.cliente ? ' - ' + biEsc(o.cliente) : ''}</span>${mais}
  </div>`;
}

// ── Painel de detalhamento do card (só quando há espaço): OPs e centros de
// trabalho em que o funcionário apontou no dia. ────────────────────────────
function biFuncLista(itens) {
  if (!itens || !itens.length) {
    return '<div class="biz-detail-empty">Sem apontamentos.</div>';
  }
  return '<div class="biz-detail-list">' + itens.map(it => `
    <div class="biz-detail-row"><span>${biEsc(it.nome)}</span><strong>${biFmt(it.qtd, 0)}</strong></div>
  `).join('') + '</div>';
}

function biFuncOps(ops) {
  if (!ops || !ops.length) {
    return '<div class="biz-detail-empty">Nenhuma OP apontada.</div>';
  }
  return '<div class="biz-detail-list">' + ops.map(o => {
    const nome = 'O.P ' + o.op + (o.cliente ? ' - ' + o.cliente : (o.descricao ? ' - ' + o.descricao : ''));
    const tag = o.em_producao
      ? '<em class="biz-op-live"><span class="biz-op-live-dot"></span>em produção</em>'
      : '';
    return `<div class="biz-detail-row biz-op-row">
      <span>${biEsc(nome)}</span>${tag}<strong>${biFmt(o.qtd, 0)}</strong>
    </div>`;
  }).join('') + '</div>';
}

function biFuncDetalhe(f) {
  return `
    <div class="biz-fu-detail">
      <div class="biz-fu-detail-sec">
        <h4>OPs apontadas</h4>
        ${biFuncOps(f.ops_detalhe)}
      </div>
      <div class="biz-fu-detail-sec">
        <h4>Centros de trabalho</h4>
        ${biFuncLista(f.centros_detalhe)}
      </div>
    </div>
  `;
}

// ── Mensagem de incentivo: quanto falta pra bater a meta, ou o quanto passou
// dela — dá um retorno mais direto do que só o número da meta. ──────────────
function biFuncMensagem(qtdProduzida, hit, meta) {
  if (meta <= 0) return '';
  if (hit) {
    const acima = qtdProduzida - meta;
    return acima > 0 ? `Meta batida — ${biFmt(acima, 0)} acima!` : 'Meta batida!';
  }
  const falta = meta - qtdProduzida;
  return `Faltam ${biFmt(falta, 0)} para a meta`;
}

// ── Editar meta individual (lápis no cabeçalho) ──────────────────────────────
const biMetaForm = document.getElementById('biMetaForm');
const biMetaInput = document.getElementById('biMetaInput');
document.getElementById('biMetaEditBtn').addEventListener('click', () => {
  biMetaInput.value = Math.round(biMetaAtual);
  biMetaForm.classList.add('open');
  biMetaInput.focus();
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
      if (data.ok) {
        biMetaAtual = Number(data.meta);
        document.getElementById('biMetaTxt').textContent = biFmt(biMetaAtual, 0);
        biRenderFuncs(biUltimosFuncs);
        biApplyScale();
      }
      biMetaForm.classList.remove('open');
    })
    .catch(() => { biMetaForm.classList.remove('open'); });
});

// ── Ajusta #bizDashboard ao espaço real de .content: no celular vira lista
// rolável; no resto (inclusive tela cheia/TV) ocupa exatamente a área visível.
function biApplyScale() {
  const stage = document.getElementById('bizDashboard');
  const wrap = stage.parentElement; // .content
  const availW = wrap.clientWidth;
  const availH = wrap.clientHeight;
  if (!availW || !availH) return;

  const kiosk = biIsKiosk();

  // Celular fora da tela cheia: lista rolável, sem escala nem altura fixa
  // (não zera o scroll — a atualização a cada 15s não pode pular pro topo).
  const mobile = !kiosk && biMqMobile.matches;
  document.body.classList.toggle('bi-mobile', mobile);
  if (mobile) {
    document.body.classList.remove('bi-fit');
    stage.style.transform = 'none';
    stage.style.width = '';
    stage.style.height = '';
    return;
  }

  // Com tela cheia ou sem: sem transform. O painel ocupa exatamente o espaço
  // visível (abaixo da topbar, ou a tela inteira na tela cheia) e a
  // grade/anéis encolhem pra todos os funcionários caberem sem rolagem nem
  // corte — com muitos funcionários, escalar pra "cobrir" cortava as linhas
  // de baixo.
  document.body.classList.add('bi-fit');
  stage.style.transform = 'none';
  stage.style.marginBottom = '';
  stage.style.width = availW + 'px';
  stage.style.height = availH + 'px';
  wrap.scrollTop = 0;

  // O formato da área mudou (entrou/saiu da tela cheia, redimensionou):
  // recalcula o nº de colunas que deixa os anéis maiores.
  if (biUltimosFuncs.length && biFuncsCols(biUltimosFuncs.length) !== biColsAtual) {
    biRenderFuncs(biUltimosFuncs);
  }
}
window.addEventListener('resize', biApplyScale);
// Ao cruzar o breakpoint (girar o celular, redimensionar), o card muda de
// formato (detalhamento sempre visível no celular) — redesenha.
biMqMobile.addEventListener('change', () => {
  biRenderFuncs(biUltimosFuncs);
  biApplyScale();
});

function biRenderAll(data) {
  biMetaAtual = Number(data.meta || 0);
  if (data.dia_label) biDiaLabel = data.dia_label;
  biUltimosFuncs = data.funcionarios || [];
  biRenderFuncs(biUltimosFuncs);

  const upd = document.getElementById('biUpdatedAt');
  if (upd) upd.textContent = data.atualizado_em || '';
  // Não sobrescreve a meta enquanto o usuário está editando
  if (!biMetaForm.classList.contains('open')) {
    document.getElementById('biMetaTxt').textContent = biFmt(biMetaAtual, 0);
  }

  // A quantidade de funcionários pode mudar a altura natural do painel a cada
  // atualização — reescalar garante que continue cobrindo certinho.
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

// ── Tela cheia (mesma técnica do BI da Produção: Fullscreen API + fallback
// via classe CSS, pra funcionar mesmo se o navegador bloquear a API nativa) ──
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
  // No celular o card muda de formato entre lista e painel de TV
  if (biMqMobile.matches) biRenderFuncs(biUltimosFuncs);
  requestAnimationFrame(biApplyScale);
  // A transição da API nativa de tela cheia (animação do SO) pode demorar
  // mais que um frame — reaplica de novo um pouco depois pra pegar o
  // tamanho final da janela.
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
    // Dispara nas duas pontas: ao SAIR (inclusive via Esc, sem passar pelo
    // botão) e ao ENTRAR — a troca de dimensões da API nativa só termina
    // depois do clique, então precisa recalcular a escala aqui também, e não
    // só uma vez (via requestAnimationFrame) logo no clique.
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
