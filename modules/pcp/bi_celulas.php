<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — BI de Células (produção de hoje x meta diária, painel para TV)
//  Fonte: PostgreSQL ERP Yzidro (somente leitura) + meta local (MySQL)
// ══════════════════════════════════════════════════════════════

const BIC_META_CHAVE = 'meta_diaria_celula';

function bicEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bicFetchMeta(PDO $pdo): float
{
    try {
        $v = $pdo->query('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = ' . $pdo->quote(BIC_META_CHAVE))->fetchColumn();
        return $v !== false ? (float) $v : 0.0;
    } catch (Throwable) {
        return 0.0;
    }
}

// ── Células cadastradas e seus funcionários (MySQL local) ────────────────────
function bicFetchCelulas(PDO $pdo): array
{
    try {
        $celulas = $pdo->query('SELECT CEL_CODIGO, CEL_NOME FROM CELULA_PRODUCAO ORDER BY CEL_NOME')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $st = $pdo->prepare('SELECT FU_CODIGO FROM CELULA_FUNCIONARIO WHERE CEL_CODIGO = :c');
    foreach ($celulas as &$c) {
        $st->execute([':c' => (int) $c['CEL_CODIGO']]);
        $c['funcionarios'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    unset($c);
    return $celulas;
}

// ── Quantidade produzida hoje, por funcionário ───────────────────────────────
function bicFetchFuncionariosHoje($pg): array
{
    $sql = "
        SELECT FU_CODIGO, COALESCE(SUM(OIAP_QTD_PRODUZIDA), 0) AS QTD_PRODUZIDA
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO
           AND TRIM(OIAP_STATUS) <> 'C'
           AND OIAP_DATA_HORA_INICIO::date = CURRENT_DATE
         GROUP BY FU_CODIGO
    ";
    $res = @pg_query($pg, $sql);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Produção de hoje agrupada por célula (soma dos funcionários da célula) ───
function bicFetchProducaoPorCelula(PDO $pdo, $pg): array
{
    $celulas = bicFetchCelulas($pdo);
    if (!$celulas) {
        return [];
    }
    $qtdPorFu = [];
    foreach (bicFetchFuncionariosHoje($pg) as $f) {
        $qtdPorFu[(int) $f['fu_codigo']] = (float) $f['qtd_produzida'];
    }

    $resultado = [];
    foreach ($celulas as $c) {
        $total = 0.0;
        foreach ($c['funcionarios'] as $fu) {
            $total += $qtdPorFu[$fu] ?? 0.0;
        }
        $resultado[] = [
            'cel_codigo'       => (int) $c['CEL_CODIGO'],
            'cel_nome'         => $c['CEL_NOME'],
            'qtd_produzida'    => $total,
            'qtd_funcionarios' => count($c['funcionarios']),
        ];
    }
    return $resultado;
}

function bicBuildPayload($pg, PDO $pdo): array
{
    return [
        'meta'          => bicFetchMeta($pdo),
        'celulas'       => bicFetchProducaoPorCelula($pdo, $pg),
        'atualizado_em' => date('H:i:s'),
    ];
}

$pdo = dbPDO();
$pg  = dbPG();

// ── AJAX: atualizar dados (polling em tempo real) ─────────────────────────────
if (($_GET['action'] ?? '') === 'refresh') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    try {
        echo json_encode(bicBuildPayload($pg, $pdo), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$error = '';
$payload = ['meta' => 0, 'celulas' => [], 'atualizado_em' => date('H:i:s')];
if (!$pg) {
    $error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    try {
        $payload = bicBuildPayload($pg, $pdo);
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
<title>BI Células | DOMBAG</title>
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
  .content { display: flex; align-items: center; justify-content: center; overflow: hidden; min-width: 0; padding: 0; }
  @media (max-width: 768px) {
    .app-wrapper { height: 100vh !important; overflow: hidden !important; flex-direction: row !important; }
    .main { height: 100vh !important; overflow: hidden !important; }
    .content { padding: 0 !important; overflow: hidden !important; height: 100vh !important; flex: 1 1 auto !important; display: flex !important; }
  }

  #bizDashboard {
    width: var(--bi-ref-w);
    min-height: 1000px;
    display: flex; flex-direction: column; gap: 16px;
    background: var(--biz-bg); border-width: 0; border-radius: 0;
    padding: 26px 28px; color: var(--biz-text); font-family: 'Segoe UI', sans-serif;
    box-shadow: none; box-sizing: border-box;
    flex-shrink: 0; transform-origin: center center;
  }

  .biz-card { background: var(--biz-card); border: 1px solid var(--biz-border); border-radius: 14px; padding: 16px 20px 18px; box-shadow: 0 10px 24px -16px rgba(0,0,0,.5); min-width: 0; }
  .biz-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--biz-border); }
  .biz-card-head h2 { font-size: 12.5px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--biz-muted); }
  .biz-count-badge { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: rgba(88,214,201,.12); color: var(--biz-teal); }

  .biz-empty-msg { text-align: center; color: var(--biz-muted); font-size: 13px; padding: 40px 0; }

  /* ── Grade de células: um card por célula, ocupando 100% da célula da
     grade (largura e altura) — o anel de progresso cresce pra preencher
     todo o espaço vertical sobrando, em vez de ficar pequeno e centralizado
     numa área grande vazia. ── */
  .biz-func-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); grid-auto-rows: 1fr; gap: 20px; flex: 1 1 auto; min-height: 760px; }

  .biz-cel-card { border: 1px solid var(--biz-border); border-radius: 14px; padding: 20px 22px; background: var(--biz-card2); display: flex; flex-direction: column; height: 100%; min-width: 0; min-height: 0; transition: border-color .15s, background .2s, box-shadow .2s; }
  .biz-cel-card:hover { border-color: rgba(88,214,201,.35); }

  .biz-cel-head { text-align: center; flex: 0 0 auto; }
  .biz-cel-name { font-size: clamp(18px, 1.4vw, 26px); font-weight: 700; color: var(--biz-text); overflow-wrap: anywhere; }
  .biz-cel-sub { font-size: 14px; color: var(--biz-muted); margin-top: 2px; }

  /* O SVG do anel é sempre quadrado (aspect-ratio) e cresce até o limite do
     espaço disponível (altura OU largura, o que for menor) — assim ele
     realmente ocupa a área que sobra, em vez de um tamanho fixo em px. */
  .biz-cel-ring-wrap { flex: 1 1 auto; min-height: 0; display: flex; padding: 6px 0; }
  .biz-cel-ring-shape { margin: auto; aspect-ratio: 1 / 1; height: 100%; max-width: 100%; }
  .biz-cel-ring-shape svg { width: 100%; height: 100%; display: block; overflow: visible; }

  .biz-cel-foot { flex: 0 0 auto; text-align: center; }
  .biz-cel-nums { font-size: 15px; color: var(--biz-muted); }
  .biz-cel-nums strong { color: var(--biz-text); font-weight: 700; font-variant-numeric: tabular-nums; }
  .biz-cel-msg { margin-top: 6px; font-size: 14.5px; font-weight: 700; }
  .biz-cel-msg.msg-hit    { color: #7db3ff; }
  .biz-cel-msg.msg-behind { color: var(--biz-muted); font-weight: 500; }

  /* ── Célula que bateu a meta diária: destaque azul no card inteiro ── */
  .biz-cel-card.biz-cel-hit { border-color: rgba(45,106,255,.5); background: rgba(45,106,255,.1); box-shadow: 0 0 0 1px rgba(45,106,255,.3), 0 0 32px -8px rgba(45,106,255,.35); }
  .biz-cel-card.biz-cel-hit .biz-cel-name { color: #7db3ff; }

  /* ── Topbar desta página: badge "Ao vivo" + hora + botões de texto não
     cabem numa única linha em telas de celular — mesma regra do BI da
     Produção. ── */
  @media (max-width: 640px) {
    .topbar { flex-wrap: wrap; row-gap: 8px; }
    .topbar-actions { flex-wrap: wrap; row-gap: 6px; }
    .last-update { display: none; }
  }
  @media (max-width: 360px) {
    .biz-fullscreen-btn span { display: none; }
  }

  .biz-fullscreen-btn { display: flex; align-items: center; gap: 7px; }

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

  html:fullscreen .app-wrapper, body.biz-fs-fallback .app-wrapper { height: 100vh !important; overflow: hidden; }
  html:fullscreen .main,        body.biz-fs-fallback .main        { height: 100vh !important; overflow: hidden; }
  html:fullscreen .content,     body.biz-fs-fallback .content     { height: 100vh; padding: 0; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>BI Células</h1>
          <p>Produção de hoje por célula, comparada com a meta diária — atualização automática</p>
        </div>
      </div>
      <div class="topbar-actions">
        <span class="biz-live"><span class="biz-live-dot"></span>Ao vivo</span>
        <span class="last-update">Atualizado às <span id="biUpdatedAt"><?= bicEscape($payload['atualizado_em']) ?></span></span>
        <a href="/pcp/celulas" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Cadastro de Células
        </a>
        <a href="/pcp/bi" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
          BI da Produção
        </a>
        <button type="button" class="btn-secondary biz-fullscreen-btn" id="biFullscreenBtn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
          <span id="biFullscreenLabel">Tela cheia</span>
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= bicEscape($error) ?>
        </div>
      <?php endif; ?>

      <div id="bizDashboard">

        <!-- Produção por Célula — soma dos funcionários de cada célula, vs meta diária
             (a meta é definida em Cadastro de Células, não aqui — este painel é só leitura) -->
        <div class="biz-card" style="flex:1 1 auto; display:flex; flex-direction:column; min-height:0;">
          <div class="biz-card-head">
            <h2>Produção por Célula Hoje</h2>
            <span class="biz-count-badge" id="biCelulasCount">0</span>
          </div>
          <div class="biz-func-grid" id="biCelulasGrid"></div>
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

// ── Anel de progresso em círculo cheio (em vez do semicírculo do BI da
// Produção): ocupa toda a área quadrada disponível no card e fica mais fácil
// de ler à distância. Vira azul e ganha um brilho quando bate a meta — um
// retorno visual imediato que funciona como incentivo à produção. ──────────
function biRenderGauge(svg, pct, hit) {
  const clamped = Math.max(0, Math.min(100, pct));
  const gradId = 'biGrad' + Math.random().toString(36).slice(2);
  const r = 84, cx = 100, cy = 100, sw = 20;
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
    <text x="${cx}" y="${cy - 4}" text-anchor="middle" fill="#eef2f7" font-size="42" font-weight="800">${biFmt(pct, 0)}%</text>
    <text x="${cx}" y="${cy + 26}" text-anchor="middle" fill="#8fa0b3" font-size="13" font-weight="600" letter-spacing="1">HOJE</text>
  `;
}

// ── Produção por célula: nº de colunas da grade em função da quantidade,
// pra formar sempre um bloco o mais "quadrado" possível (ex.: 4 células =
// 2x2, 6 = 3x2, 9 = 3x3) em vez de uma única linha de cards estreitos. ──────
function biCelulasCols(n) {
  return n <= 1 ? 1 : Math.ceil(Math.sqrt(n));
}

// ── Produção por célula — soma dos funcionários de cada célula, vs meta ──────
function biRenderCelulas(celulas) {
  document.getElementById('biCelulasCount').textContent = celulas.length;
  const grid = document.getElementById('biCelulasGrid');
  if (!celulas.length) {
    grid.innerHTML = '<div class="biz-empty-msg">Nenhuma célula cadastrada. Crie células em Cadastro de Células.</div>';
    return;
  }
  grid.style.gridTemplateColumns = `repeat(${biCelulasCols(celulas.length)}, 1fr)`;
  grid.innerHTML = celulas.map(c => {
    const hit = biMetaAtual > 0 && c.qtd_produzida >= biMetaAtual;
    const msg = biCelulaMensagem(c.qtd_produzida, hit);
    return `
    <div class="biz-cel-card${hit ? ' biz-cel-hit' : ''}">
      <div class="biz-cel-head">
        <div class="biz-cel-name">${biEsc(c.cel_nome)}</div>
        <div class="biz-cel-sub">${c.qtd_funcionarios} funcionário(s)</div>
      </div>
      <div class="biz-cel-ring-wrap"><div class="biz-cel-ring-shape"><svg class="biz-gauge"></svg></div></div>
      <div class="biz-cel-foot">
        <div class="biz-cel-nums">Produzido <strong>${biFmt(c.qtd_produzida, 0)}</strong> / Meta <strong>${biFmt(biMetaAtual, 0)}</strong></div>
        ${msg ? `<div class="biz-cel-msg ${hit ? 'msg-hit' : 'msg-behind'}">${msg}</div>` : ''}
      </div>
    </div>
  `;
  }).join('');

  const svgs = grid.querySelectorAll('.biz-gauge');
  celulas.forEach((c, i) => {
    const pct = biMetaAtual > 0 ? (c.qtd_produzida / biMetaAtual) * 100 : 0;
    const hit = biMetaAtual > 0 && c.qtd_produzida >= biMetaAtual;
    biRenderGauge(svgs[i], pct, hit);
  });
}

// ── Mensagem de incentivo: quanto falta pra bater a meta, ou o quanto passou
// dela — dá um retorno mais direto do que só o número da meta. ──────────────
function biCelulaMensagem(qtdProduzida, hit) {
  if (biMetaAtual <= 0) return '';
  if (hit) {
    const acima = qtdProduzida - biMetaAtual;
    return acima > 0 ? `Meta batida — ${biFmt(acima, 0)} acima!` : 'Meta batida!';
  }
  const falta = biMetaAtual - qtdProduzida;
  return `Faltam ${biFmt(falta, 0)} para a meta`;
}

// ── Painel de TV: escala #bizDashboard (largura fixa --bi-ref-w) pra sempre
// cobrir 100% do espaço real de .content — mesma técnica do BI da Produção
// (biApplyScale): o eixo que sobra é cortado (.content tem overflow:hidden).
// offsetWidth/offsetHeight são o tamanho de LAYOUT (sem transform), então
// funcionam como "tamanho natural" mesmo já escalado. ───────────────────────
const BI_SCALE_MIN = 0.4;
const BI_SCALE_MAX = 3.5;
const BI_REF_W = 1800;
const BI_REF_W_MIN = BI_REF_W * 0.8;
const BI_REF_W_MAX = BI_REF_W * 1.25;

function biApplyScale() {
  const stage = document.getElementById('bizDashboard');
  const wrap = stage.parentElement; // .content
  const availW = wrap.clientWidth;
  const availH = wrap.clientHeight;
  if (!availW || !availH) return;

  stage.style.width = '';
  const baseH = stage.offsetHeight;
  if (!baseH) return;

  const idealW = baseH * (availW / availH);
  const stageW = Math.max(BI_REF_W_MIN, Math.min(BI_REF_W_MAX, idealW));
  stage.style.width = stageW + 'px';

  const natW = stage.offsetWidth;
  const natH = stage.offsetHeight;
  if (!natW || !natH) return;

  let scale = Math.max(availW / natW, availH / natH);
  scale = Math.max(BI_SCALE_MIN, Math.min(BI_SCALE_MAX, scale));
  stage.style.transform = `scale(${scale})`;
}
window.addEventListener('resize', biApplyScale);

function biRenderAll(data) {
  biMetaAtual = Number(data.meta || 0);
  biRenderCelulas(data.celulas || []);

  const upd = document.getElementById('biUpdatedAt');
  if (upd) upd.textContent = data.atualizado_em || '';

  // A quantidade de células pode mudar a altura natural do painel a cada
  // atualização — reescalar garante que continue cobrindo certinho.
  biApplyScale();
}

const BI_INITIAL = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;
biRenderAll(BI_INITIAL);

// ── Atualização periódica (tempo real) ────────────────────────────────────────
function biRefresh() {
  fetch('?action=refresh', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
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
  requestAnimationFrame(biApplyScale);
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
['fullscreenchange', 'webkitfullscreenchange', 'MSFullscreenChange'].forEach(ev => {
  document.addEventListener(ev, () => {
    if (!biFsElement()) biSetFsState(false);
  });
});
</script>
</body>
</html>
