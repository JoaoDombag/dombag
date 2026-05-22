<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor de Máquinas | DOMBAG</title>
    <link rel="stylesheet" href="/public/css/unified_admin.css">
    <style>
        /* ── Tabs ─────────────────────────────────────────────────────────────── */
        .monitor-tabs {
            display: flex;
            gap: 4px;
            background: #112240;
            border-radius: 12px 12px 0 0;
            padding: 8px 8px 0;
            border-bottom: 2px solid rgba(30,79,201,.35);
        }
        .monitor-tab-btn {
            padding: 10px 24px;
            border: none;
            background: transparent;
            color: rgba(255,255,255,.5);
            font-size: .9rem;
            font-weight: 600;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            transition: background .2s, color .2s;
            letter-spacing: .02em;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .monitor-tab-btn.active  { background: #1e4fc9; color: #fff; }
        .monitor-tab-btn:not(.active):hover { background: rgba(255,255,255,.07); color: rgba(255,255,255,.8); }
        .monitor-tab-pane { display: none; }
        .monitor-tab-pane.active { display: block; }

        /* ── Status colors ────────────────────────────────────────────────────── */
        .status-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; flex-shrink:0; }
        .sd-rodando    { background:#00c9a7; box-shadow:0 0 5px #00c9a7aa; }
        .sd-parado     { background:#e63757; }
        .sd-setup      { background:#f8a100; }
        .sd-manutencao { background:#a78bfa; }

        /* ══════════════════════════════════════════════════════════════════════ */
        /* ABA 1 — PLANTA (vista superior)                                        */
        /* ══════════════════════════════════════════════════════════════════════ */
        .planta-wrapper {
            background: #0a1628;
            border-radius: 0 0 12px 12px;
            padding: 16px;
            box-sizing: border-box;
            width: 100%;
            overflow-x: hidden;
        }

        .factory-building {
            width: 100%;
            box-sizing: border-box;
            border: 3px solid rgba(100,160,255,.35);
            border-radius: 6px;
            background: #0d1e3a;
            padding: 4px;
            position: relative;
            margin-top: 14px;
        }

        .factory-label {
            position: absolute;
            top: -13px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e4fc9;
            color: #fff;
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: 2px 14px;
            border-radius: 20px;
            white-space: nowrap;
        }

        /* Grid interno — 3 colunas no desktop, adapta em telas menores */
        .factory-inner {
            display: grid;
            grid-template-columns: repeat(var(--factory-cols, 3), 1fr);
            gap: 4px;
            background: rgba(30,79,201,.1);
            border-radius: 3px;
            padding: 4px;
            width: 100%;
            box-sizing: border-box;
        }

        /* Zona / setor */
        .factory-zone {
            background: rgba(17,34,64,.95);
            border-radius: 4px;
            border: 1px solid rgba(30,79,201,.2);
            padding: 10px;
            box-sizing: border-box;
        }
        /* span controlado via JS com style inline para se adaptar ao --factory-cols */
        .factory-zone.span2 { grid-column: span 2; }
        .factory-zone.span3 { grid-column: span 3; }

        .zone-header {
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: rgba(255,255,255,.3);
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid rgba(255,255,255,.07);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .zone-header::before {
            content: '';
            width: 3px; height: 10px;
            background: #1e4fc9;
            border-radius: 2px;
            flex-shrink: 0;
        }

        /* Grid de máquinas — sempre preenche a zona inteira */
        .zone-machines {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            gap: 6px;
        }

        /* Machine box — ocupa o espaço da célula do grid */
        .maq-box {
            background: linear-gradient(160deg, #152a50 0%, #0f1e38 100%);
            border: 1.5px solid rgba(30,79,201,.3);
            border-radius: 6px;
            padding: 8px 8px 7px;
            cursor: pointer;
            transition: border-color .18s, transform .15s, box-shadow .18s;
            position: relative;
            overflow: hidden;
            width: 100%;
            box-sizing: border-box;
            min-width: 0;
        }
        .maq-box::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 4px 4px 0 0;
        }
        .maq-box.st-rodando::before    { background: #00c9a7; }
        .maq-box.st-parado::before     { background: #e63757; }
        .maq-box.st-setup::before      { background: #f8a100; }
        .maq-box.st-manutencao::before { background: #a78bfa; }

        .maq-box:hover { transform: translateY(-2px); z-index: 5; }
        .maq-box.st-rodando:hover    { border-color: #00c9a7; box-shadow: 0 4px 18px rgba(0,201,167,.2); }
        .maq-box.st-parado:hover     { border-color: #e63757; box-shadow: 0 4px 18px rgba(230,55,87,.2); }
        .maq-box.st-setup:hover      { border-color: #f8a100; box-shadow: 0 4px 18px rgba(248,161,0,.2); }
        .maq-box.st-manutencao:hover { border-color: #a78bfa; box-shadow: 0 4px 18px rgba(167,139,250,.2); }

        .maq-box-name {
            font-size: .66rem;
            font-weight: 700;
            color: rgba(255,255,255,.8);
            line-height: 1.3;
            margin-bottom: 6px;
            padding-top: 2px;
            word-break: break-word;
        }
        .maq-box-status {
            display: flex;
            align-items: center;
            font-size: .63rem;
            color: rgba(255,255,255,.45);
            margin-bottom: 5px;
        }
        .maq-box-bar {
            height: 3px;
            background: rgba(255,255,255,.07);
            border-radius: 2px;
            overflow: hidden;
        }
        .maq-box-bar-fill { height: 100%; border-radius: 2px; }
        .fill-rodando    { background: linear-gradient(90deg,#00c9a7,#00e5bf); }
        .fill-parado     { background: #e63757; }
        .fill-setup      { background: #f8a100; }
        .fill-manutencao { background: #a78bfa; }

        /* Corredor — span ajustado via JS */
        .factory-corridor {
            background: rgba(0,0,0,.2);
            border-radius: 2px;
            height: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .factory-corridor::after {
            content: '';
            display: block;
            width: 70%;
            height: 1px;
            background: repeating-linear-gradient(90deg, rgba(255,255,255,.12) 0, rgba(255,255,255,.12) 6px, transparent 6px, transparent 12px);
        }

        /* Tooltip */
        .maq-tooltip {
            display: none;
            position: fixed;
            z-index: 9999;
            background: #0d1e3a;
            border: 1px solid rgba(30,79,201,.5);
            border-radius: 10px;
            padding: 14px 16px;
            min-width: 250px;
            max-width: 290px;
            box-shadow: 0 12px 40px rgba(0,0,0,.65);
            pointer-events: none;
        }
        .maq-tooltip.visible { display: block; }
        .tt-title {
            font-size: .8rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(255,255,255,.07);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .tt-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            gap: 10px;
        }
        .tt-label { font-size: .7rem; color: rgba(255,255,255,.4); white-space: nowrap; }
        .tt-value { font-size: .75rem; font-weight: 600; color: rgba(255,255,255,.85); text-align: right; }
        .tt-progress { margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,.06); }
        .tt-prog-header {
            display: flex;
            justify-content: space-between;
            font-size: .68rem;
            color: rgba(255,255,255,.38);
            margin-bottom: 4px;
        }
        .tt-prog-bar { height: 7px; background: rgba(255,255,255,.07); border-radius: 4px; overflow: hidden; margin-bottom: 7px; }
        .tt-prog-fill { height: 100%; border-radius: 4px; }

        /* Legenda */
        .planta-legend {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,.06);
        }
        .legend-item { display: flex; align-items: center; gap: 6px; font-size: .73rem; color: rgba(255,255,255,.45); }

        /* ══════════════════════════════════════════════════════════════════════ */
        /* ABA 2 — BI                                                             */
        /* ══════════════════════════════════════════════════════════════════════ */
        .bi-wrapper {
            background: #0a1628;
            border-radius: 0 0 12px 12px;
            padding: 16px;
            box-sizing: border-box;
            width: 100%;
            overflow-x: hidden;
        }
        .bi-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 16px;
            width: 100%;
        }
        .bi-sum-card {
            background: #112240;
            border-radius: 10px;
            padding: 12px 10px;
            border: 1px solid rgba(30,79,201,.2);
            text-align: center;
            min-width: 0;
        }
        .bi-sum-val {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px;
        }
        .bi-sum-label { font-size: .65rem; color: rgba(255,255,255,.38); text-transform: uppercase; letter-spacing: .04em; }
        .c-rodando    { color: #00c9a7; }
        .c-parado     { color: #e63757; }
        .c-setup      { color: #f8a100; }
        .c-manutencao { color: #a78bfa; }

        /* Grid de cards: 3 colunas no desktop, adapta para baixo */
        .bi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            width: 100%;
        }
        .bi-card {
            background: #112240;
            border: 1px solid rgba(30,79,201,.2);
            border-radius: 10px;
            padding: 14px;
            border-top: 3px solid transparent;
            transition: transform .15s;
            min-width: 0;
            box-sizing: border-box;
        }
        .bi-card:hover { transform: translateY(-2px); }
        .bi-card.st-rodando    { border-top-color: #00c9a7; }
        .bi-card.st-parado     { border-top-color: #e63757; }
        .bi-card.st-setup      { border-top-color: #f8a100; }
        .bi-card.st-manutencao { border-top-color: #a78bfa; }

        .bi-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            gap: 6px;
        }
        .bi-card-name { font-size: .78rem; font-weight: 700; color: #fff; line-height: 1.3; min-width: 0; }
        .bi-badge {
            font-size: .63rem;
            font-weight: 700;
            padding: 3px 7px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .badge-rodando    { background: rgba(0,201,167,.15);  color: #00c9a7; }
        .badge-parado     { background: rgba(230,55,87,.15);  color: #e63757; }
        .badge-setup      { background: rgba(248,161,0,.15);  color: #f8a100; }
        .badge-manutencao { background: rgba(167,139,250,.15);color: #a78bfa; }

        .bi-kpis {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 10px;
        }
        .bi-kpi {
            background: rgba(255,255,255,.04);
            border-radius: 6px;
            padding: 8px 9px;
            min-width: 0;
        }
        .bi-kpi-val   { font-size: 1rem; font-weight: 700; color: #fff; line-height: 1; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bi-kpi-label { font-size: .6rem; color: rgba(255,255,255,.35); text-transform: uppercase; letter-spacing: .03em; }

        .bi-prog-header {
            display: flex;
            justify-content: space-between;
            font-size: .65rem;
            color: rgba(255,255,255,.38);
            margin-bottom: 4px;
            gap: 4px;
            flex-wrap: wrap;
        }
        .bi-prog-bar {
            height: 7px;
            background: rgba(255,255,255,.07);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 7px;
        }
        .bi-prog-fill { height: 100%; border-radius: 4px; }

        .bi-card-footer {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(255,255,255,.06);
            font-size: .65rem;
            color: rgba(255,255,255,.38);
        }
        .bi-card-footer strong { color: rgba(255,255,255,.7); font-weight: 600; }

        .bi-parado-msg {
            text-align: center;
            padding: 8px 0 4px;
            color: rgba(255,255,255,.25);
            font-size: .75rem;
        }

        /* Área scrollável — padrão do layout deste projeto */
        .monitor-scroll {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            -webkit-overflow-scrolling: touch;
        }

        /* Last update */
        .last-update { font-size: .72rem; color: rgba(255,255,255,.3); }
        .last-update span { color: #00c9a7; font-weight: 600; }

        /* ══════════════════════════════════════════════════════════════════════ */
        /* RESPONSIVO                                                              */
        /* ══════════════════════════════════════════════════════════════════════ */

        /* Tablet largo (sidebar aberta ~240px + conteúdo ~860px) */
        @media (max-width: 1100px) {
            .bi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* Tablet / mobile */
        @media (max-width: 700px) {
            .bi-grid    { grid-template-columns: 1fr; }
            .bi-summary { grid-template-columns: repeat(2, 1fr); }
            .monitor-tab-btn { padding: 9px 14px; font-size: .8rem; }
            .planta-wrapper, .bi-wrapper { padding: 12px; }
        }

        /* Mobile pequeno */
        @media (max-width: 420px) {
            .monitor-tab-btn { padding: 8px 10px; font-size: .75rem; }
            .bi-sum-val { font-size: 1.3rem; }
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
                    <h1>Monitor de Máquinas</h1>
                    <p>Acompanhamento em tempo real da produção</p>
                </div>
            </div>
            <div class="topbar-actions">
                <span class="last-update" id="last-update-ts">Atualizado às <span>--:--:--</span></span>
            </div>
        </header>

        <div class="content">
            <!-- Tabs -->
            <div class="monitor-tabs">
                <button class="monitor-tab-btn active" data-tab="planta">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Planta da Fábrica
                </button>
                <button class="monitor-tab-btn" data-tab="bi">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    BI por Máquina
                </button>
            </div>

            <!-- Área com scroll — cresce para preencher o espaço e scrolla -->
            <div class="monitor-scroll">

                <!-- ── ABA 1: PLANTA ─────────────────────────────────────────── -->
                <div class="monitor-tab-pane active" id="tab-planta">
                    <div class="planta-wrapper">
                        <div class="factory-building">
                            <div class="factory-label">Fábrica DOMBAG</div>
                            <div class="factory-inner" id="factory-inner"></div>
                        </div>
                        <div class="planta-legend">
                            <div class="legend-item"><span class="status-dot sd-rodando"></span> Rodando</div>
                            <div class="legend-item"><span class="status-dot sd-parado"></span> Parado</div>
                            <div class="legend-item"><span class="status-dot sd-setup"></span> Setup</div>
                            <div class="legend-item"><span class="status-dot sd-manutencao"></span> Manutenção</div>
                        </div>
                    </div>
                </div>

                <!-- ── ABA 2: BI ──────────────────────────────────────────────── -->
                <div class="monitor-tab-pane" id="tab-bi">
                    <div class="bi-wrapper">
                        <div class="bi-summary" id="bi-summary"></div>
                        <div class="bi-grid"    id="bi-grid"></div>
                    </div>
                </div>

            </div><!-- /monitor-scroll -->
        </div>
    </div>
</div>

<!-- Tooltip global -->
<div class="maq-tooltip" id="maq-tooltip"></div>

<script>
// ══════════════════════════════════════════════════════════════════════════════
//  DADOS FICTÍCIOS
// ══════════════════════════════════════════════════════════════════════════════
const MAQUINAS = [
    { id:1,  nome:'CARIMBADEIRA BAG',       area:'Carimbadeiras',   status:'rodando',    horaInicio:'06:15', op:'OP-2847', prodOP:4320,  metaOP:12000, prodDia:7850,  metaDia:14400, un:'un' },
    { id:2,  nome:'CARIMBADEIRA SACARIA',   area:'Carimbadeiras',   status:'setup',      horaInicio:'07:40', op:'OP-2851', prodOP:0,     metaOP:8500,  prodDia:5200,  metaDia:12000, un:'un' },
    { id:3,  nome:'CORTE ALÇA 1',           area:'Corte de Alça',   status:'rodando',    horaInicio:'06:00', op:'OP-2839', prodOP:9100,  metaOP:15000, prodDia:11300, metaDia:18000, un:'un' },
    { id:4,  nome:'CORTE ALÇA 2',           area:'Corte de Alça',   status:'rodando',    horaInicio:'06:00', op:'OP-2840', prodOP:7600,  metaOP:15000, prodDia:9400,  metaDia:18000, un:'un' },
    { id:5,  nome:'CORTE BAG 1',            area:'Corte de Bag',    status:'rodando',    horaInicio:'06:00', op:'OP-2842', prodOP:5800,  metaOP:10000, prodDia:8700,  metaDia:12000, un:'un' },
    { id:6,  nome:'CORTE BAG 2',            area:'Corte de Bag',    status:'parado',     horaInicio:'06:00', op:'OP-2843', prodOP:2100,  metaOP:10000, prodDia:3200,  metaDia:12000, un:'un' },
    { id:7,  nome:'CORTE BAG 3',            area:'Corte de Bag',    status:'rodando',    horaInicio:'06:30', op:'OP-2845', prodOP:6400,  metaOP:9500,  prodDia:6400,  metaDia:12000, un:'un' },
    { id:8,  nome:'CORTE SACARIA 1 (SANFONA)', area:'Corte de Sacaria', status:'rodando', horaInicio:'06:00', op:'OP-2833', prodOP:18500, metaOP:25000, prodDia:22000, metaDia:30000, un:'un' },
    { id:9,  nome:'CORTE SACARIA 2',        area:'Corte de Sacaria', status:'manutencao', horaInicio:'—',    op:'—',      prodOP:0,     metaOP:25000, prodDia:8400,  metaDia:30000, un:'un' },
    { id:10, nome:'CORTE SACARIA 3 (LINER)',area:'Corte de Sacaria', status:'rodando',   horaInicio:'06:00', op:'OP-2836', prodOP:14200, metaOP:20000, prodDia:17800, metaDia:24000, un:'un' },
    { id:11, nome:'IMPRESSORA FLEXOGRÁFICA',area:'Carimbadeiras',    status:'rodando',   horaInicio:'06:00', op:'OP-2828', prodOP:31000, metaOP:50000, prodDia:31000, metaDia:60000, un:'m²' },
    { id:12, nome:'VÁLVULADEIRA',           area:'Outros',           status:'rodando',   horaInicio:'06:00', op:'OP-2855', prodOP:3200,  metaOP:6000,  prodDia:4100,  metaDia:7200,  un:'un' },
];

// Layout da planta
// span3 = zona de corredor ou zona que ocupa a linha toda
// span: colunas no grid de 3 colunas (desktop)
const ZONAS_LAYOUT = [
    { area: 'Carimbadeiras',    span: 2 },
    { area: 'Outros',           span: 1 },
    { corridor: true },
    { area: 'Corte de Alça',    span: 1 },
    { area: 'Corte de Bag',     span: 2 },
    { corridor: true },
    { area: 'Corte de Sacaria', span: 3 },
];

// ── Helpers ────────────────────────────────────────────────────────────────────
const pct    = (v, m) => m > 0 ? Math.min(100, Math.round(v / m * 100)) : 0;
const fmt    = n => n.toLocaleString('pt-BR');
const stLbl  = s => ({ rodando:'Rodando', parado:'Parado', setup:'Setup', manutencao:'Manutenção' }[s] || s);
const stCol  = s => ({ rodando:'#00c9a7', parado:'#e63757', setup:'#f8a100', manutencao:'#a78bfa'  }[s] || '#fff');

// ══════════════════════════════════════════════════════════════════════════════
//  ABA 1 — PLANTA
// ══════════════════════════════════════════════════════════════════════════════

// Ajusta spans das zonas e corredores conforme largura atual do container
function applyGridCols() {
    const container = document.getElementById('factory-inner');
    if (!container) return;
    const w = container.offsetWidth;

    // Decide quantas colunas usar
    let cols;
    if (w >= 760) cols = 3;
    else if (w >= 460) cols = 2;
    else cols = 1;

    container.style.setProperty('--factory-cols', cols);

    // Atualiza grid-column de cada zona e corredor
    container.querySelectorAll('.factory-zone').forEach(el => {
        const desiredSpan = parseInt(el.dataset.span) || 1;
        const effectiveSpan = Math.min(desiredSpan, cols);
        el.style.gridColumn = `span ${effectiveSpan}`;
    });
    container.querySelectorAll('[data-corridor]').forEach(el => {
        el.style.gridColumn = `span ${cols}`;
    });
}

function buildPlanta() {
    const container = document.getElementById('factory-inner');
    const byArea = {};
    MAQUINAS.forEach(m => { (byArea[m.area] = byArea[m.area] || []).push(m); });

    let html = '';
    ZONAS_LAYOUT.forEach(z => {
        if (z.corridor) {
            html += `<div class="factory-corridor" data-corridor="1"></div>`;
            return;
        }
        const maquinas = byArea[z.area] || [];
        html += `<div class="factory-zone" data-span="${z.span}">
            <div class="zone-header">${z.area}</div>
            <div class="zone-machines">`;
        maquinas.forEach(m => {
            const p = pct(m.prodOP, m.metaOP);
            html += `
            <div class="maq-box st-${m.status}" data-id="${m.id}">
                <div class="maq-box-name">MAQ ${m.nome}</div>
                <div class="maq-box-status">
                    <span class="status-dot sd-${m.status}"></span>
                    <span>${stLbl(m.status)}</span>
                </div>
                <div class="maq-box-bar">
                    <div class="maq-box-bar-fill fill-${m.status}" style="width:${p}%"></div>
                </div>
            </div>`;
        });
        html += `</div></div>`;
    });

    container.innerHTML = html;
    applyGridCols();

    // Ajusta colunas quando o container redimensiona
    new ResizeObserver(applyGridCols).observe(container);

    // Tooltip
    const tooltip = document.getElementById('maq-tooltip');
    container.querySelectorAll('.maq-box').forEach(box => {
        box.addEventListener('mouseenter', e => {
            const m = MAQUINAS.find(x => x.id === +box.dataset.id);
            if (!m) return;
            const pOP  = pct(m.prodOP,  m.metaOP);
            const pDia = pct(m.prodDia, m.metaDia);
            const cor  = stCol(m.status);
            tooltip.innerHTML = `
                <div class="tt-title">
                    <span class="status-dot sd-${m.status}"></span>
                    MAQ ${m.nome}
                </div>
                <div class="tt-row"><span class="tt-label">Status</span><span class="tt-value" style="color:${cor}">${stLbl(m.status)}</span></div>
                <div class="tt-row"><span class="tt-label">Hora início</span><span class="tt-value">${m.horaInicio}</span></div>
                <div class="tt-row"><span class="tt-label">OP</span><span class="tt-value">${m.op}</span></div>
                <div class="tt-row"><span class="tt-label">Produzido (OP)</span><span class="tt-value">${fmt(m.prodOP)} / ${fmt(m.metaOP)} ${m.un}</span></div>
                <div class="tt-row"><span class="tt-label">Produzido (Dia)</span><span class="tt-value">${fmt(m.prodDia)} / ${fmt(m.metaDia)} ${m.un}</span></div>
                <div class="tt-progress">
                    <div class="tt-prog-header"><span>Progresso OP</span><span style="color:${cor}">${pOP}%</span></div>
                    <div class="tt-prog-bar"><div class="tt-prog-fill fill-${m.status}" style="width:${pOP}%"></div></div>
                    <div class="tt-prog-header"><span>Progresso Dia</span><span style="color:#5b8af5">${pDia}%</span></div>
                    <div class="tt-prog-bar"><div class="tt-prog-fill" style="width:${pDia}%;background:linear-gradient(90deg,#1e4fc9,#5b8af5)"></div></div>
                </div>`;
            tooltip.classList.add('visible');
            moveTooltip(e);
        });
        box.addEventListener('mousemove', moveTooltip);
        box.addEventListener('mouseleave', () => tooltip.classList.remove('visible'));
    });
}

function moveTooltip(e) {
    const tt = document.getElementById('maq-tooltip');
    const W = window.innerWidth, H = window.innerHeight;
    const TW = tt.offsetWidth || 260, TH = tt.offsetHeight || 250;
    let x = e.clientX + 16, y = e.clientY + 16;
    if (x + TW > W - 10) x = e.clientX - TW - 16;
    if (y + TH > H - 10) y = e.clientY - TH - 16;
    tt.style.left = x + 'px';
    tt.style.top  = y + 'px';
}

// ══════════════════════════════════════════════════════════════════════════════
//  ABA 2 — BI
// ══════════════════════════════════════════════════════════════════════════════
function buildBI() {
    const counts = { rodando:0, parado:0, setup:0, manutencao:0 };
    MAQUINAS.forEach(m => counts[m.status]++);

    document.getElementById('bi-summary').innerHTML = `
        <div class="bi-sum-card"><div class="bi-sum-val c-rodando">${counts.rodando}</div><div class="bi-sum-label">Rodando</div></div>
        <div class="bi-sum-card"><div class="bi-sum-val c-parado">${counts.parado}</div><div class="bi-sum-label">Paradas</div></div>
        <div class="bi-sum-card"><div class="bi-sum-val c-setup">${counts.setup}</div><div class="bi-sum-label">Em Setup</div></div>
        <div class="bi-sum-card"><div class="bi-sum-val c-manutencao">${counts.manutencao}</div><div class="bi-sum-label">Manutenção</div></div>`;

    document.getElementById('bi-grid').innerHTML = MAQUINAS.map(m => {
        const pOP  = pct(m.prodOP,  m.metaOP);
        const pDia = pct(m.prodDia, m.metaDia);
        const cor  = stCol(m.status);
        const inactive = (m.status === 'parado' || m.status === 'manutencao') && m.prodOP === 0;
        return `
        <div class="bi-card st-${m.status}">
            <div class="bi-card-head">
                <div class="bi-card-name">MAQ ${m.nome}</div>
                <span class="bi-badge badge-${m.status}">${stLbl(m.status)}</span>
            </div>
            ${inactive ? `<div class="bi-parado-msg">${m.status==='manutencao'?'🔧 Em manutenção':'⏸ Máquina parada'}</div>` : ''}
            <div class="bi-kpis">
                <div class="bi-kpi"><div class="bi-kpi-val">${m.horaInicio}</div><div class="bi-kpi-label">Hora início</div></div>
                <div class="bi-kpi"><div class="bi-kpi-val" style="color:${cor}">${pOP}%</div><div class="bi-kpi-label">Progresso OP</div></div>
                <div class="bi-kpi"><div class="bi-kpi-val">${fmt(m.prodOP)}</div><div class="bi-kpi-label">Prod. OP (${m.un})</div></div>
                <div class="bi-kpi"><div class="bi-kpi-val">${fmt(m.prodDia)}</div><div class="bi-kpi-label">Prod. Dia (${m.un})</div></div>
            </div>
            <div class="bi-prog-header"><span>Progresso da OP</span><span style="color:${cor}">${pOP}% · ${fmt(m.prodOP)} / ${fmt(m.metaOP)}</span></div>
            <div class="bi-prog-bar"><div class="bi-prog-fill fill-${m.status}" style="width:${pOP}%"></div></div>
            <div class="bi-prog-header"><span>Progresso do Dia</span><span style="color:#5b8af5">${pDia}% · ${fmt(m.prodDia)} / ${fmt(m.metaDia)}</span></div>
            <div class="bi-prog-bar"><div class="bi-prog-fill" style="width:${pDia}%;background:linear-gradient(90deg,#1e4fc9,#5b8af5)"></div></div>
            <div class="bi-card-footer">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                OP: <strong>${m.op}</strong>
                &nbsp;&nbsp;Meta dia: <strong>${fmt(m.metaDia)} ${m.un}</strong>
            </div>
        </div>`;
    }).join('');
}

// ── Tabs ───────────────────────────────────────────────────────────────────────
document.querySelectorAll('.monitor-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.monitor-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.monitor-tab-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

// ── Relógio ────────────────────────────────────────────────────────────────────
function tick() {
    const el = document.querySelector('#last-update-ts span');
    if (el) el.textContent = new Date().toLocaleTimeString('pt-BR');
}
setInterval(tick, 1000);
tick();

// ── Init ───────────────────────────────────────────────────────────────────────
buildPlanta();
buildBI();
</script>
</body>
</html>
