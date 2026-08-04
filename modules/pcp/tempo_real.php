<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Acompanhar Produção em Tempo Real
//  Fonte: PostgreSQL ERP Yzidro (OP_ITENS_ATIVIDADES_APONTADAS e correlatas)
//  Somente leitura. Uma "máquina" aqui corresponde a um CENTRO_TRABALHO.
// ══════════════════════════════════════════════════════════════

function trFetchMaquinasAtivas($pg): array
{
    $sql = "
        SELECT DISTINCT ON (CT.CT_CODIGO)
               IAA.OIAP_CODIGO
              ,IAA.OIAP_STATUS
              ,CT.CT_CODIGO
              ,CT.CT_DESCRICAO
              ,F.FU_CODIGO
              ,F.FU_NOME
              ,IAA.PROD_CODIGO
              ,C.CLI_CODIGO
              ,C.CLI_NOME
              ,P.PRO_CODIGO
              ,P.PRO_DESCRICAO
              ,IAA.OIAP_DATA_HORA_INICIO
              ,IAA.OIAP_QTD_PRODUZIDA
              ,IAA.ATP_CODIGO
              ,ATP.ATP_TIPO_APONTAMENTO
              ,(SELECT COUNT(*)
                  FROM OP_ITENS_ATIVIDADES_LOGS L
                 WHERE L.OIAP_CODIGO = IAA.OIAP_CODIGO
                   AND NOT L.OIAL_EXCLUIDO
                   AND TRIM(L.OIAL_STATUS) = 'P'
                   AND (L.MPP_CODIGO IS NULL OR L.MPP_CODIGO NOT IN (1, 2)))     AS QTD_PAUSAS
              ,TO_CHAR(COALESCE((
                  SELECT SUM(GREATEST(COALESCE(L.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - L.OIAL_DATA_HORA_INICIO, interval '0'))
                    FROM OP_ITENS_ATIVIDADES_LOGS L
                   WHERE L.OIAP_CODIGO = IAA.OIAP_CODIGO
                     AND NOT L.OIAL_EXCLUIDO
                     AND TRIM(L.OIAL_STATUS) = 'EP'
                ), interval '0'), 'HH24:MI:SS')                                AS TEMPO_PRODUTIVO
              ,TO_CHAR(COALESCE((
                  SELECT SUM(GREATEST(COALESCE(L.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - L.OIAL_DATA_HORA_INICIO, interval '0'))
                    FROM OP_ITENS_ATIVIDADES_LOGS L
                   WHERE L.OIAP_CODIGO = IAA.OIAP_CODIGO
                     AND NOT L.OIAL_EXCLUIDO
                     AND TRIM(L.OIAL_STATUS) = 'P'
                     AND (L.MPP_CODIGO IS NULL OR L.MPP_CODIGO NOT IN (1, 2))
                ), interval '0'), 'HH24:MI:SS')                                AS TEMPO_PAUSA
              ,VOP.QTD_PEDIDO                                                  AS OP_QTD_PEDIDO
              ,(SELECT COALESCE(SUM(IAA2.OIAP_QTD_PRODUZIDA), 0)
                  FROM OP_ITENS_ATIVIDADES_APONTADAS IAA2
                 WHERE IAA2.PROD_CODIGO = IAA.PROD_CODIGO
                   AND IAA2.PRO_CODIGO  = IAA.PRO_CODIGO
                   AND NOT IAA2.OIAP_EXCLUIDO)                                 AS OP_QTD_PRODUZIDA_TOTAL
          FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
         INNER JOIN ATIVIDADE_PRODUCAO ATP ON ATP.ATP_CODIGO = IAA.ATP_CODIGO
         INNER JOIN CENTRO_TRABALHO   CT  ON CT.CT_CODIGO   = IAA.CT_CODIGO
         INNER JOIN FUNCIONARIO       F   ON F.FU_CODIGO    = IAA.FU_CODIGO
         INNER JOIN PRODUTO           P   ON P.PRO_CODIGO   = IAA.PRO_CODIGO
         INNER JOIN CLIENTES          C   ON C.CLI_CODIGO   = IAA.CLI_CODIGO
          LEFT JOIN VW_ORDEM_PRODUCAO VOP ON VOP.COD_PRODUCAO = IAA.PROD_CODIGO AND VOP.COD_PRODUTO = IAA.PRO_CODIGO
         WHERE NOT IAA.OIAP_EXCLUIDO
           AND IAA.OIAP_DATA_HORA_FIM IS NULL
         ORDER BY CT.CT_CODIGO, IAA.OIAP_DATA_HORA_INICIO DESC
    ";

    $res = @pg_query($pg, $sql);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar as máquinas ativas: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function trFetchTodosCentros($pg): array
{
    $res = @pg_query($pg, 'SELECT CT_CODIGO, CT_DESCRICAO FROM CENTRO_TRABALHO ORDER BY CT_DESCRICAO');
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function trFetchUltimoApontamentoCentro($pg, int $ctCodigo): ?array
{
    $sql = "
        SELECT IAA.OIAP_CODIGO
              ,IAA.OIAP_STATUS
              ,F.FU_CODIGO
              ,F.FU_NOME
              ,IAA.PROD_CODIGO
              ,C.CLI_CODIGO
              ,C.CLI_NOME
              ,P.PRO_CODIGO
              ,P.PRO_DESCRICAO
              ,IAA.OIAP_DATA_HORA_INICIO
              ,IAA.OIAP_DATA_HORA_FIM
              ,IAA.OIAP_QTD_PRODUZIDA
          FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
         INNER JOIN FUNCIONARIO F ON F.FU_CODIGO  = IAA.FU_CODIGO
         INNER JOIN PRODUTO     P ON P.PRO_CODIGO = IAA.PRO_CODIGO
         INNER JOIN CLIENTES    C ON C.CLI_CODIGO = IAA.CLI_CODIGO
         WHERE NOT IAA.OIAP_EXCLUIDO
           AND IAA.CT_CODIGO = $1
         ORDER BY IAA.OIAP_DATA_HORA_INICIO DESC
         LIMIT 1
    ";
    $res = @pg_query_params($pg, $sql, [$ctCodigo]);
    if (!$res) {
        return null;
    }
    $row = pg_fetch_assoc($res) ?: null;
    pg_free_result($res);
    return $row ?: null;
}

function trStatusLabel(string $status): array
{
    $status = trim($status);
    $map = [
        'A'  => ['Em Andamento', 'info'],
        'EP' => ['Em Produção', 'ok'],
        'P'  => ['Pausado', 'warn'],
        'F'  => ['Finalizado', 'info'],
        'C'  => ['Cancelado', 'danger'],
    ];
    return $map[$status] ?? [$status ?: '—', 'info'];
}

function trEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ── Conexão ERP ───────────────────────────────────────────────────────────────
$pg = dbPG();

// ── AJAX: atualização periódica dos grids ──────────────────────────────────────
if (($_GET['action'] ?? '') === 'refresh') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    try {
        $ativas = trFetchMaquinasAtivas($pg);
        $todos = trFetchTodosCentros($pg);
        $codigosAtivos = array_map(static fn ($r) => (int) $r['ct_codigo'], $ativas);
        $paradas = array_values(array_filter($todos, static fn ($c) => !in_array((int) $c['ct_codigo'], $codigosAtivos, true)));
        echo json_encode(['ativas' => $ativas, 'paradas' => $paradas, 'ts' => date('H:i:s')], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: detalhe de máquina parada (último apontamento, se houver) ───────────
if (($_GET['action'] ?? '') === 'detalhe_centro') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    $ct = (int) ($_GET['ct'] ?? 0);
    try {
        $ultimo = $ct > 0 ? trFetchUltimoApontamentoCentro($pg, $ct) : null;
        echo json_encode(['ultimo' => $ultimo], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Carga inicial da página ────────────────────────────────────────────────────
$error = '';
$ativas = [];
$paradas = [];

if (!$pg) {
    $error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    try {
        $ativas = trFetchMaquinasAtivas($pg);
        $todos = trFetchTodosCentros($pg);
        $codigosAtivos = array_map(static fn ($r) => (int) $r['ct_codigo'], $ativas);
        $paradas = array_values(array_filter($todos, static fn ($c) => !in_array((int) $c['ct_codigo'], $codigosAtivos, true)));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$jsAtivas = json_encode($ativas, JSON_UNESCAPED_UNICODE);
$jsParadas = json_encode($paradas, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acompanhar Produção em Tempo Real | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .tr-grids { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
  @media (max-width: 1100px) { .tr-grids { grid-template-columns: 1fr; } }

  .tr-panel-title { display: flex; align-items: center; gap: 8px; }
  .tr-count-badge { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 20px; }
  .tr-count-badge.ok   { background: rgba(0,201,167,.12); color: var(--teal); }
  .tr-count-badge.danger { background: rgba(239,68,68,.12); color: var(--red); }

  .tr-row { cursor: pointer; transition: background .12s; }
  .tr-row:hover { background: var(--card-hover); }
  .tr-row.selected { background: rgba(45,106,255,.1); }

  .tr-ct-name { font-weight: 600; }
  .tr-ct-code { color: var(--text-muted); font-size: 11px; }
  .tr-op-time { font-variant-numeric: tabular-nums; color: var(--teal); font-weight: 600; }

  .tr-empty-row td { text-align: center; padding: 28px 16px; color: var(--text-muted); font-size: 12.5px; }

  .last-update { font-size: .72rem; color: var(--text-muted); }
  .last-update span { color: var(--teal); font-weight: 600; }

  /* ── Painel lateral (drawer) ─────────────────────────────────────────────── */
  .tr-drawer-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.55);
    opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 300;
  }
  .tr-drawer-overlay.open { opacity: 1; pointer-events: all; }

  .tr-drawer {
    position: fixed; top: 0; right: 0; height: 100vh; width: 400px; max-width: calc(100vw - 24px);
    background: #0d1e3a; border-left: 1px solid var(--border);
    box-shadow: -12px 0 40px rgba(0,0,0,.45);
    transform: translateX(100%); transition: transform .25s ease;
    z-index: 301; display: flex; flex-direction: column;
  }
  .tr-drawer.open { transform: translateX(0); }

  .tr-drawer-head {
    padding: 18px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-shrink: 0;
  }
  .tr-drawer-title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
  .tr-drawer-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
  .tr-drawer-close {
    width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border);
    background: transparent; color: var(--text-muted); cursor: pointer;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  }
  .tr-drawer-close:hover { background: var(--card-hover); color: var(--text-primary); }

  .tr-drawer-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
  .tr-drawer-empty { text-align: center; padding: 40px 10px; color: var(--text-muted); font-size: 12.5px; }

  .tr-field-group { margin-bottom: 18px; }
  .tr-field-group-title {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-muted); margin-bottom: 8px;
  }
  .tr-field-row {
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    padding: 8px 0; border-bottom: 1px solid var(--border);
  }
  .tr-field-row:last-child { border-bottom: none; }
  .tr-field-label { font-size: 12px; color: var(--text-muted); }
  .tr-field-value { font-size: 12.5px; font-weight: 600; color: var(--text-primary); text-align: right; }

  .tr-live-timer {
    background: rgba(0,201,167,.08); border: 1px solid rgba(0,201,167,.25); border-radius: var(--radius);
    padding: 14px 16px; text-align: center; margin-bottom: 18px;
  }
  .tr-live-timer-val { font-size: 26px; font-weight: 700; color: var(--teal); font-variant-numeric: tabular-nums; }
  .tr-live-timer-lbl { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }

  .tr-progress { margin-bottom: 18px; }
  .tr-progress-header { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 6px; }
  .tr-progress-title { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
  .tr-progress-pct { font-size: 13px; font-weight: 700; color: var(--teal); }
  .tr-progress-pct.over { color: var(--amber); }
  .tr-progress-bar { height: 8px; background: rgba(255,255,255,.07); border-radius: 4px; overflow: hidden; }
  .tr-progress-fill { height: 100%; border-radius: 4px; background: linear-gradient(90deg,#00c9a7,#00e5bf); transition: width .3s ease; }
  .tr-progress-fill.over { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
  .tr-progress-nums { margin-top: 6px; font-size: 11.5px; color: var(--text-muted); text-align: right; }
  .tr-progress-nums strong { color: var(--text-primary); font-weight: 600; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Acompanhar Produção em Tempo Real</h1>
          <p>Dados do ERP — atualização automática</p>
        </div>
      </div>
      <div class="topbar-actions">
        <span class="last-update" id="lastUpdateTs">Atualizado às <span>--:--:--</span></span>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= trEscape($error) ?>
        </div>
      <?php endif; ?>

      <div class="tr-grids">
        <!-- Máquinas Ativas -->
        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title tr-panel-title">
              Máquinas Ativas
              <span class="tr-count-badge ok" id="countAtivas">0</span>
            </span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Centro de Trabalho</th>
                  <th>Status</th>
                  <th>Operador</th>
                  <th>Cód. OP</th>
                  <th>Cliente</th>
                  <th class="td-right">Tempo</th>
                </tr>
              </thead>
              <tbody id="tbodyAtivas"></tbody>
            </table>
          </div>
        </div>

        <!-- Máquinas Paradas -->
        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title tr-panel-title">
              Máquinas Paradas
              <span class="tr-count-badge danger" id="countParadas">0</span>
            </span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Centro de Trabalho</th>
                  <th class="td-right">Código</th>
                </tr>
              </thead>
              <tbody id="tbodyParadas"></tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- Painel lateral de detalhes -->
<div class="tr-drawer-overlay" id="trDrawerOverlay"></div>
<div class="tr-drawer" id="trDrawer">
  <div class="tr-drawer-head">
    <div>
      <div class="tr-drawer-title" id="trDrawerTitle">—</div>
      <div class="tr-drawer-sub" id="trDrawerSub">—</div>
    </div>
    <button type="button" class="tr-drawer-close" id="trDrawerClose" title="Fechar">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="tr-drawer-body" id="trDrawerBody"></div>
</div>

<script>
let ATIVAS = <?= $jsAtivas ?: '[]' ?>;
let PARADAS = <?= $jsParadas ?: '[]' ?>;
let selecionado = null; // { tipo: 'ativa'|'parada', codigo: ct_codigo }

const STATUS_MAP = {
  A:  ['Em Andamento', 'info'],
  EP: ['Em Produção', 'ok'],
  P:  ['Pausado', 'warn'],
  F:  ['Finalizado', 'info'],
  C:  ['Cancelado', 'danger'],
};

function trFmtDateTime(v) {
  if (!v) return '—';
  const d = new Date(v.replace(' ', 'T'));
  if (isNaN(d.getTime())) return v;
  return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function trFmtElapsed(inicio) {
  if (!inicio) return '—';
  const d = new Date(inicio.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '—';
  let secs = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
  const h = String(Math.floor(secs / 3600)).padStart(2, '0');
  const m = String(Math.floor((secs % 3600) / 60)).padStart(2, '0');
  const s = String(secs % 60).padStart(2, '0');
  return `${h}:${m}:${s}`;
}

function trStatusPill(status) {
  const [label, cls] = STATUS_MAP[(status || '').trim()] || [status || '—', 'info'];
  return `<span class="status-pill ${cls}">${label}</span>`;
}

function trEsc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ── Renderização dos grids ──────────────────────────────────────────────────
function renderGrids() {
  document.getElementById('countAtivas').textContent = ATIVAS.length;
  document.getElementById('countParadas').textContent = PARADAS.length;

  const tbAtivas = document.getElementById('tbodyAtivas');
  if (!ATIVAS.length) {
    tbAtivas.innerHTML = '<tr class="tr-empty-row"><td colspan="6">Nenhuma máquina em produção no momento.</td></tr>';
  } else {
    tbAtivas.innerHTML = ATIVAS.map(m => `
      <tr class="tr-row${selecionado && selecionado.tipo === 'ativa' && selecionado.codigo === +m.ct_codigo ? ' selected' : ''}" data-ct="${m.ct_codigo}">
        <td>
          <div class="tr-ct-name">${trEsc(m.ct_descricao)}</div>
          <div class="tr-ct-code">Cód. ${trEsc(m.ct_codigo)}</div>
        </td>
        <td>${trStatusPill(m.oiap_status)}</td>
        <td>${trEsc(m.fu_nome)}</td>
        <td>${trEsc(m.prod_codigo ?? '—')}</td>
        <td>${trEsc(m.cli_nome)}</td>
        <td class="td-right tr-op-time" data-inicio="${trEsc(m.oiap_data_hora_inicio)}">${trFmtElapsed(m.oiap_data_hora_inicio)}</td>
      </tr>
    `).join('');
  }

  const tbParadas = document.getElementById('tbodyParadas');
  if (!PARADAS.length) {
    tbParadas.innerHTML = '<tr class="tr-empty-row"><td colspan="2">Nenhuma máquina parada no momento.</td></tr>';
  } else {
    tbParadas.innerHTML = PARADAS.map(c => `
      <tr class="tr-row${selecionado && selecionado.tipo === 'parada' && selecionado.codigo === +c.ct_codigo ? ' selected' : ''}" data-ct="${c.ct_codigo}">
        <td class="tr-ct-name">${trEsc(c.ct_descricao)}</td>
        <td class="td-right tr-ct-code">${trEsc(c.ct_codigo)}</td>
      </tr>
    `).join('');
  }

  tbAtivas.querySelectorAll('.tr-row').forEach(row => {
    row.addEventListener('click', () => abrirDetalheAtiva(+row.dataset.ct));
  });
  tbParadas.querySelectorAll('.tr-row').forEach(row => {
    row.addEventListener('click', () => abrirDetalheParada(+row.dataset.ct));
  });
}

// ── Timer ao vivo das máquinas ativas (tick a cada segundo) ────────────────
function tickTimers() {
  document.querySelectorAll('.tr-op-time[data-inicio]').forEach(el => {
    el.textContent = trFmtElapsed(el.dataset.inicio);
  });
  const drawerTimer = document.getElementById('trLiveTimerVal');
  if (drawerTimer && drawerTimer.dataset.inicio) {
    drawerTimer.textContent = trFmtElapsed(drawerTimer.dataset.inicio);
  }
}
setInterval(tickTimers, 1000);

// ── Barra de progresso: quantidade lida (produzida) vs. quantidade do pedido ──
function trProgressBarHtml(qtdProduzida, qtdPedido) {
  const produzida = Number(qtdProduzida || 0);
  const pedido = Number(qtdPedido || 0);
  if (!pedido) {
    return `
    <div class="tr-progress">
      <div class="tr-progress-header">
        <span class="tr-progress-title">Progresso da OP</span>
      </div>
      <div class="tr-progress-nums">Quantidade do pedido não encontrada para esta OP — produzido: <strong>${produzida.toLocaleString('pt-BR')}</strong></div>
    </div>`;
  }
  const pct = Math.round((produzida / pedido) * 100);
  const over = pct > 100;
  const fillPct = Math.min(100, pct);
  return `
    <div class="tr-progress">
      <div class="tr-progress-header">
        <span class="tr-progress-title">Progresso da OP</span>
        <span class="tr-progress-pct${over ? ' over' : ''}">${pct}%</span>
      </div>
      <div class="tr-progress-bar"><div class="tr-progress-fill${over ? ' over' : ''}" style="width:${fillPct}%"></div></div>
      <div class="tr-progress-nums">Lido (OP completa): <strong>${produzida.toLocaleString('pt-BR')}</strong> / Pedido: <strong>${pedido.toLocaleString('pt-BR')}</strong></div>
    </div>`;
}

// ── Painel lateral: máquina ativa ───────────────────────────────────────────
function abrirDetalheAtiva(ctCodigo) {
  const m = ATIVAS.find(x => +x.ct_codigo === ctCodigo);
  if (!m) return;
  selecionado = { tipo: 'ativa', codigo: ctCodigo };
  renderGrids();

  document.getElementById('trDrawerTitle').textContent = m.ct_descricao;
  document.getElementById('trDrawerSub').textContent = `Centro de Trabalho ${m.ct_codigo}`;

  document.getElementById('trDrawerBody').innerHTML = `
    <div class="tr-live-timer">
      <div class="tr-live-timer-val" id="trLiveTimerVal" data-inicio="${trEsc(m.oiap_data_hora_inicio)}">${trFmtElapsed(m.oiap_data_hora_inicio)}</div>
      <div class="tr-live-timer-lbl">Tempo desde o início do apontamento</div>
    </div>

    ${trProgressBarHtml(m.op_qtd_produzida_total, m.op_qtd_pedido)}

    <div class="tr-field-group">
      <div class="tr-field-group-title">Apontamento</div>
      <div class="tr-field-row"><span class="tr-field-label">Status</span><span class="tr-field-value">${trStatusPill(m.oiap_status)}</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Código do Apontamento</span><span class="tr-field-value">${trEsc(m.oiap_codigo)}</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Início</span><span class="tr-field-value">${trFmtDateTime(m.oiap_data_hora_inicio)}</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Tipo de Apontamento</span><span class="tr-field-value">${trEsc(m.atp_tipo_apontamento || '—')}</span></div>
    </div>

    <div class="tr-field-group">
      <div class="tr-field-group-title">Ordem de Produção</div>
      <div class="tr-field-row"><span class="tr-field-label">Cód. OP</span><span class="tr-field-value">${trEsc(m.prod_codigo ?? '—')}</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Cliente</span><span class="tr-field-value">${trEsc(m.cli_nome)} (${trEsc(m.cli_codigo)})</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Produto</span><span class="tr-field-value">${trEsc(m.pro_descricao)} (${trEsc(m.pro_codigo)})</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Qtd. Produzida (neste apontamento)</span><span class="tr-field-value">${Number(m.oiap_qtd_produzida || 0).toLocaleString('pt-BR')}</span></div>
    </div>

    <div class="tr-field-group">
      <div class="tr-field-group-title">Operador</div>
      <div class="tr-field-row"><span class="tr-field-label">Nome</span><span class="tr-field-value">${trEsc(m.fu_nome)}</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Código</span><span class="tr-field-value">${trEsc(m.fu_codigo)}</span></div>
    </div>

    <div class="tr-field-group">
      <div class="tr-field-group-title">Tempos</div>
      <div class="tr-field-row"><span class="tr-field-label">Tempo Produtivo</span><span class="tr-field-value">${trEsc(m.tempo_produtivo || '00:00:00')}</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Tempo Pausado</span><span class="tr-field-value">${trEsc(m.tempo_pausa || '00:00:00')}</span></div>
      <div class="tr-field-row"><span class="tr-field-label">Qtd. Pausas</span><span class="tr-field-value">${trEsc(m.qtd_pausas ?? 0)}</span></div>
    </div>
  `;

  abrirDrawer();
}

// ── Painel lateral: máquina parada ──────────────────────────────────────────
function abrirDetalheParada(ctCodigo) {
  const c = PARADAS.find(x => +x.ct_codigo === ctCodigo);
  if (!c) return;
  selecionado = { tipo: 'parada', codigo: ctCodigo };
  renderGrids();

  document.getElementById('trDrawerTitle').textContent = c.ct_descricao;
  document.getElementById('trDrawerSub').textContent = `Centro de Trabalho ${c.ct_codigo}`;
  document.getElementById('trDrawerBody').innerHTML = '<div class="tr-drawer-empty">Carregando...</div>';
  abrirDrawer();

  fetch(`?action=detalhe_centro&ct=${ctCodigo}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (selecionado?.tipo !== 'parada' || selecionado?.codigo !== ctCodigo) return; // usuário já trocou de seleção
      const u = data.ultimo;
      if (!u) {
        document.getElementById('trDrawerBody').innerHTML = '<div class="tr-drawer-empty">Nenhum apontamento encontrado para este centro de trabalho.</div>';
        return;
      }
      document.getElementById('trDrawerBody').innerHTML = `
        <div class="tr-field-group">
          <div class="tr-field-group-title">Máquina parada — última atividade</div>
          <div class="tr-field-row"><span class="tr-field-label">Status</span><span class="tr-field-value">${trStatusPill(u.oiap_status)}</span></div>
          <div class="tr-field-row"><span class="tr-field-label">Início</span><span class="tr-field-value">${trFmtDateTime(u.oiap_data_hora_inicio)}</span></div>
          <div class="tr-field-row"><span class="tr-field-label">Fim</span><span class="tr-field-value">${trFmtDateTime(u.oiap_data_hora_fim)}</span></div>
        </div>
        <div class="tr-field-group">
          <div class="tr-field-group-title">Ordem de Produção</div>
          <div class="tr-field-row"><span class="tr-field-label">Cód. OP</span><span class="tr-field-value">${trEsc(u.prod_codigo ?? '—')}</span></div>
          <div class="tr-field-row"><span class="tr-field-label">Cliente</span><span class="tr-field-value">${trEsc(u.cli_nome)} (${trEsc(u.cli_codigo)})</span></div>
          <div class="tr-field-row"><span class="tr-field-label">Produto</span><span class="tr-field-value">${trEsc(u.pro_descricao)} (${trEsc(u.pro_codigo)})</span></div>
          <div class="tr-field-row"><span class="tr-field-label">Qtd. Produzida</span><span class="tr-field-value">${Number(u.oiap_qtd_produzida || 0).toLocaleString('pt-BR')}</span></div>
        </div>
        <div class="tr-field-group">
          <div class="tr-field-group-title">Operador</div>
          <div class="tr-field-row"><span class="tr-field-label">Nome</span><span class="tr-field-value">${trEsc(u.fu_nome)}</span></div>
          <div class="tr-field-row"><span class="tr-field-label">Código</span><span class="tr-field-value">${trEsc(u.fu_codigo)}</span></div>
        </div>
      `;
    })
    .catch(() => {
      document.getElementById('trDrawerBody').innerHTML = '<div class="tr-drawer-empty">Erro ao carregar os dados.</div>';
    });
}

function abrirDrawer() {
  document.getElementById('trDrawer').classList.add('open');
  document.getElementById('trDrawerOverlay').classList.add('open');
}
function fecharDrawer() {
  document.getElementById('trDrawer').classList.remove('open');
  document.getElementById('trDrawerOverlay').classList.remove('open');
  selecionado = null;
  renderGrids();
}
document.getElementById('trDrawerClose').addEventListener('click', fecharDrawer);
document.getElementById('trDrawerOverlay').addEventListener('click', fecharDrawer);
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharDrawer(); });

// ── Atualização periódica ────────────────────────────────────────────────────
function atualizarDados() {
  fetch('?action=refresh', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (data.error) return;
      ATIVAS = data.ativas || [];
      PARADAS = data.paradas || [];
      renderGrids();

      // Mantém o painel lateral em sincronia se a máquina selecionada ainda existir
      if (selecionado?.tipo === 'ativa') {
        if (ATIVAS.some(m => +m.ct_codigo === selecionado.codigo)) {
          abrirDetalheAtiva(selecionado.codigo);
        } else {
          fecharDrawer();
        }
      }

      const ts = data.ts || new Date().toLocaleTimeString('pt-BR');
      document.querySelector('#lastUpdateTs span').textContent = ts;
    })
    .catch(() => {});
}
setInterval(atualizarDados, 15000);

// ── Init ───────────────────────────────────────────────────────────────────────
renderGrids();
document.querySelector('#lastUpdateTs span').textContent = new Date().toLocaleTimeString('pt-BR');
</script>
</body>
</html>
