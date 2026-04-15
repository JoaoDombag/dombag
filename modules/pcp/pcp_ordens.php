<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/login/auth.php';
// ══════════════════════════════════════════════════════════════
//  DOMBAG — Ordens de Produção (PCP)
//  Fonte: ERP PostgreSQL (mesma query de pedidos_importados.php)
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/pcp/pcp_engine.php';

$dataIni = $_GET['data_ini'] ?? date('Y-m-01', strtotime('-6 months'));
$dataFim = $_GET['data_fim'] ?? date('Y-m-t',  strtotime('+3 months'));
$erp_ok  = true; $erp_msg = '';

try {
    $maquinas = pcpGetMaquinas();
    $pedidos  = pcpGetPedidosERP($dataIni, $dataFim);
    if (!$pedidos && !$maquinas) { $erp_ok = false; $erp_msg = 'Sem conexão com o ERP ou sem máquinas cadastradas.'; }
    $plan = pcpCalcPlanejamento($pedidos, $maquinas);
} catch (Exception $e) {
    $erp_ok = false; $erp_msg = 'Erro: '.$e->getMessage();
    $plan = ['pedidos'=>[],'fila'=>[],'prog'=>[],'maquinas'=>[]];
}

$pedidosJS = array_map(function($p){unset($p['entrega_dt'],$p['fluxo_arr'],$p['hrs_por_maq']);return $p;},$plan['pedidos']);
$PEDIDOS   = json_encode($pedidosJS, JSON_UNESCAPED_UNICODE);
$MAQUINAS  = json_encode($plan['maquinas'], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PCP — Ordens | DOMBAG</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
.toggle-btn{width:36px;height:36px;min-width:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s;flex-shrink:0;}
.toggle-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;white-space:nowrap;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;white-space:nowrap;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.icon-btn{width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s;flex-shrink:0;}
.icon-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.content{flex:1;overflow-y:auto;padding:24px;}
.content::-webkit-scrollbar{width:4px;}
.content::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}
.sidebar{width:var(--sidebar-w);min-width:var(--sidebar-w);background:var(--blue-mid);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden;transition:width var(--transition),min-width var(--transition);position:relative;z-index:100;}
.sidebar.collapsed{width:var(--sidebar-w-col);min-width:var(--sidebar-w-col);}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600;}
.status-pill::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;}
.status-pill.ok{background:rgba(0,201,167,.12);color:var(--teal);}
.status-pill.warn{background:rgba(245,158,11,.12);color:var(--amber);}
.status-pill.danger{background:rgba(239,68,68,.12);color:var(--red);}
.alert-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:10px 16px;font-size:12.5px;color:var(--amber);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.period-bar{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.period-bar span{font-size:12px;color:var(--text-muted);}
.period-bar input[type=date]{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 10px;height:34px;font-family:'Segoe UI',sans-serif;font-size:12.5px;color:var(--text-primary);outline:none;}
.btn-refresh{padding:0 14px;height:34px;background:var(--blue-accent);border:none;border-radius:8px;color:#fff;font-family:'Segoe UI',sans-serif;font-size:12.5px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;}
.btn-refresh:hover{background:var(--blue-light);}
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
.kpi-mini{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;padding:13px 16px;display:flex;align-items:center;gap:12px;}
.kpi-mini-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.kpi-mini.c-blue .kpi-mini-icon{background:rgba(45,106,255,.15);color:#7db3ff;}
.kpi-mini.c-teal .kpi-mini-icon{background:rgba(0,201,167,.12);color:var(--teal);}
.kpi-mini.c-amb  .kpi-mini-icon{background:rgba(245,158,11,.12);color:var(--amber);}
.kpi-mini.c-red  .kpi-mini-icon{background:rgba(239,68,68,.12);color:var(--red);}
.kpi-mini-val{font-size:20px;font-weight:700;letter-spacing:-.8px;line-height:1;}
.kpi-mini-lbl{font-size:11px;color:var(--text-muted);margin-top:2px;}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
.toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:8px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 12px;height:36px;transition:border-color .15s;}
.search-box:focus-within{border-color:rgba(45,106,255,.5);}
.search-box svg{color:var(--text-muted);flex-shrink:0;}
.search-box input{background:transparent;border:none;outline:none;font-family:'Segoe UI',sans-serif;font-size:13px;color:var(--text-primary);width:200px;}
.search-box input::placeholder{color:var(--text-muted);}
.filter-select{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 10px;height:36px;font-family:'Segoe UI',sans-serif;font-size:12.5px;color:var(--text-primary);cursor:pointer;outline:none;}
.filter-select option{background:#112240;}
.pcp-layout{display:grid;grid-template-columns:1fr 290px;gap:16px;min-width:0;}
.pcp-layout>*{min-width:0;}
.orders-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.panel-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.panel-title{font-size:13px;font-weight:600;}
.pcp-table{width:100%;border-collapse:collapse;}
.pcp-table th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:rgba(0,0,0,.1);white-space:nowrap;cursor:pointer;user-select:none;}
.pcp-table th:hover{color:var(--text-primary);}
.pcp-table td{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);white-space:nowrap;}
.pcp-table tr:last-child td{border-bottom:none;}
.pcp-table tbody tr{cursor:pointer;}
.pcp-table tbody tr:hover td{background:rgba(255,255,255,.03);}
.pcp-table tbody tr.selected td{background:rgba(45,106,255,.1)!important;}
.order-num{font-weight:700;color:#7db3ff;font-size:12px;}
.sort-icon{margin-left:3px;opacity:.4;font-size:9px;}
.sort-icon.active{opacity:1;color:var(--blue-light);}
.pagination{display:flex;align-items:center;justify-content:space-between;padding:10px 18px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted);}
.page-btns{display:flex;align-items:center;gap:4px;}
.page-btn{min-width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:12px;font-family:'Segoe UI',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0 6px;transition:all .15s;}
.page-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.page-btn.active{background:rgba(45,106,255,.2);color:#7db3ff;border-color:rgba(45,106,255,.4);}
.page-btn:disabled{opacity:.35;cursor:default;pointer-events:none;}
.detail-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;}
.detail-panel .panel-header{padding:13px 18px;border-bottom:1px solid var(--border);}
.detail-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 20px;text-align:center;color:var(--text-muted);}
.detail-empty svg{opacity:.2;margin-bottom:10px;}
.detail-body{padding:16px 18px;overflow-y:auto;flex:1;}
.detail-body::-webkit-scrollbar{width:3px;}
.detail-body::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
.det-num{font-size:18px;font-weight:700;color:#7db3ff;letter-spacing:-.5px;}
.det-section{margin-bottom:14px;}
.det-title{font-size:9.5px;font-weight:700;letter-spacing:1.2px;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;}
.det-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;}
.det-item label{font-size:10.5px;color:var(--text-muted);display:block;margin-bottom:2px;}
.det-item span{font-size:12.5px;font-weight:500;}
.fluxo-step{display:flex;align-items:center;gap:8px;font-size:12px;padding:6px 10px;background:rgba(255,255,255,.03);border-radius:6px;border:1px solid var(--border);margin-bottom:4px;}
.fluxo-num{width:18px;height:18px;border-radius:50%;background:rgba(45,106,255,.2);color:#7db3ff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.fluxo-info{flex:1;}.fluxo-maq{font-weight:600;}.fluxo-dates{font-size:10.5px;color:var(--text-muted);}
.pill-risco{background:rgba(245,158,11,.12);color:var(--amber);padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;margin-left:4px;}
.pill-ok-f{background:rgba(0,201,167,.1);color:var(--teal);padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;margin-left:4px;}
.maq-side{display:flex;flex-direction:column;gap:10px;}
.maq-side-card{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:11px 14px;}
.maq-side-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.maq-side-name{font-size:12px;font-weight:600;}
.maq-bar-bg{height:4px;background:rgba(255,255,255,.06);border-radius:2px;overflow:hidden;}
.maq-bar-fill{height:100%;border-radius:2px;}
@media(max-width:1100px){.pcp-layout{grid-template-columns:1fr;}.kpi-strip{grid-template-columns:repeat(2,1fr);}}
@media(max-width:480px){.kpi-strip{grid-template-columns:1fr;}.toolbar-left{flex-direction:column;align-items:stretch;}.search-box input{width:100%;}}
</style>

<link rel="stylesheet" href="/public/css/unified_admin.css">
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'].'/shared/sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
<div class="page-title"><h1>Ordens de Produção</h1><p id="topbar-date"></p></div>
      </div>
      <div class="topbar-actions">
        <button class="icon-btn" id="btnExport" title="Exportar CSV">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </button>
      </div>
    </header>
    <div class="content">

      <?php if (!$erp_ok): ?>
      <div class="alert-warn">⚠ <?= htmlspecialchars($erp_msg) ?></div>
      <?php endif; ?>

      <!-- Período -->
      <form method="GET">
        <div class="period-bar">
          <span>Período ERP:</span>
          <input type="date" name="data_ini" value="<?= $dataIni ?>">
          <span>até</span>
          <input type="date" name="data_fim" value="<?= $dataFim ?>">
          <button type="submit" class="btn-refresh">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            Atualizar
          </button>
          <span style="color:var(--teal);font-size:11.5px;"><?= count($plan['pedidos']) ?> pedido(s) do ERP</span>
        </div>
      </form>

      <!-- KPIs -->
      <div class="kpi-strip">
        <div class="kpi-mini c-blue"><div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></div><div><div class="kpi-mini-val" id="k-total">—</div><div class="kpi-mini-lbl">Total de Ordens</div></div></div>
        <div class="kpi-mini c-teal"><div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div><div><div class="kpi-mini-val" id="k-ok">—</div><div class="kpi-mini-lbl">No Prazo</div></div></div>
        <div class="kpi-mini c-amb"><div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><div><div class="kpi-mini-val" id="k-pend">—</div><div class="kpi-mini-lbl">Pendentes</div></div></div>
        <div class="kpi-mini c-red"><div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div><div><div class="kpi-mini-val" id="k-late">—</div><div class="kpi-mini-lbl">Atrasados</div></div></div>
      </div>

      <!-- Toolbar filtros -->
      <div class="toolbar">
        <div class="toolbar-left">
          <div class="search-box">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" placeholder="Nº, cliente, produto…">
          </div>
          <select class="filter-select" id="fStatus">
            <option value="">Todos os status</option>
            <option value="OK">No Prazo</option>
            <option value="ATRASADO">Atrasado</option>
            <option value="RISCO">Risco</option>
            <option value="PENDENTE">Pendente</option>
          </select>
          <select class="filter-select" id="fTipo">
            <option value="">Todos os tipos</option>
            <option value="BAG">BAG</option>
            <option value="SACARIA">SACARIA</option>
          </select>
          <select class="filter-select" id="fGrupo">
            <option value="">Todos os grupos</option>
            <?php
            $grupos = array_unique(array_filter(array_column($plan['pedidos'],'grupo')));
            sort($grupos);
            foreach ($grupos as $g) echo '<option value="'.htmlspecialchars($g).'">'.htmlspecialchars($g).'</option>';
            ?>
          </select>
        </div>
        <span style="font-size:11.5px;color:var(--text-muted);" id="rCount"></span>
      </div>

      <div class="pcp-layout">
        <div class="orders-panel">
          <div class="panel-header"><span class="panel-title">Ordens</span><span style="font-size:11.5px;color:var(--text-muted);" id="rPanel"></span></div>
          <div style="overflow-x:auto;">
            <table class="pcp-table">
              <thead><tr>
                <th data-col="prioridade">Prior. <span class="sort-icon active" id="s-prioridade">↑</span></th>
                <th data-col="num">Pedido <span class="sort-icon" id="s-num">↕</span></th>
                <th>Produto</th><th>Cliente</th>
                <th data-col="entrega">Entrega <span class="sort-icon" id="s-entrega">↕</span></th>
                <th data-col="qtd">Qtd <span class="sort-icon" id="s-qtd">↕</span></th>
                <th>Tipo</th><th>Status</th><th>Gargalo</th>
              </tr></thead>
              <tbody id="tbody"></tbody>
            </table>
          </div>
          <div id="emptyState" style="display:none;text-align:center;padding:40px 20px;color:var(--text-muted);"><p style="font-size:13px;">Nenhuma ordem encontrada.</p></div>
          <div class="pagination"><span id="pInfo"></span><div class="page-btns" id="pBtns"></div></div>
        </div>

        <div style="display:flex;flex-direction:column;gap:12px;">
          <div class="detail-panel" id="detPanel" style="min-height:320px;">
            <div class="panel-header"><span class="panel-title">Detalhes</span></div>
            <div class="detail-empty" id="detEmpty">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <p>Clique em uma ordem</p>
            </div>
            <div class="detail-body" id="detBody" style="display:none;"></div>
          </div>
          <div class="orders-panel">
            <div class="panel-header"><span class="panel-title">Carga de Máquinas</span></div>
            <div style="padding:12px 14px;"><div class="maq-side" id="maqSide"></div></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const PEDIDOS  = <?= $PEDIDOS  ?>;
const MAQUINAS = <?= $MAQUINAS ?>;

const PER=12;let page=1,sortCol='prioridade',sortAsc=true,filtered=[...PEDIDOS],selIdx=null;
function pillSt(s){if(s==='OK')return'<span class="status-pill ok">No Prazo</span>';if(s==='ATRASADO')return'<span class="status-pill danger">Atrasado</span>';if(s==='RISCO')return'<span class="status-pill warn">Risco</span>';if(s==='PENDENTE')return'<span class="status-pill warn">Pendente</span>';return'—';}
function tipoPill(v){if(v==='BAG')return'<span style="background:rgba(45,106,255,.12);color:#7db3ff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">BAG</span>';if(v==='SACARIA')return'<span style="background:rgba(0,201,167,.1);color:var(--teal);padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">SAC</span>';return'—';}
(function(){const dias=['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],meses=['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'],d=new Date();document.getElementById('topbar-date').textContent=dias[d.getDay()]+', '+d.getDate()+' de '+meses[d.getMonth()]+' de '+d.getFullYear();})();

function kpis(){document.getElementById('k-total').textContent=PEDIDOS.length;document.getElementById('k-ok').textContent=PEDIDOS.filter(p=>p.status_prazo==='OK').length;document.getElementById('k-pend').textContent=PEDIDOS.filter(p=>p.status_prazo==='PENDENTE').length;document.getElementById('k-late').textContent=PEDIDOS.filter(p=>p.status_prazo==='ATRASADO').length;}

function applyFilters(){const q=document.getElementById('searchInput').value.toLowerCase(),fs=document.getElementById('fStatus').value,ft=document.getElementById('fTipo').value,fg=document.getElementById('fGrupo').value;filtered=PEDIDOS.filter(p=>{if(q&&!p.num.includes(q)&&!p.cliente.toLowerCase().includes(q)&&!p.produto.toLowerCase().includes(q))return false;if(fs&&p.status_prazo!==fs)return false;if(ft&&p.tipo!==ft)return false;if(fg&&p.grupo!==fg)return false;return true;});filtered.sort((a,b)=>{let va=a[sortCol]??'',vb=b[sortCol]??'';if(sortCol==='entrega'){const pa=va.split('/').reverse().join(''),pb=vb.split('/').reverse().join('');return sortAsc?pa.localeCompare(pb):pb.localeCompare(pa);}if(va<vb)return sortAsc?-1:1;if(va>vb)return sortAsc?1:-1;return 0;});page=1;render();}

function render(){const tot=filtered.length,start=(page-1)*PER,slice=filtered.slice(start,start+PER);document.getElementById('rCount').textContent=tot+' ordem(ns)';document.getElementById('rPanel').textContent=tot+' ordem(ns)';const tbody=document.getElementById('tbody'),empty=document.getElementById('emptyState');if(!tot){tbody.innerHTML='';empty.style.display='block';document.getElementById('pInfo').textContent='';document.getElementById('pBtns').innerHTML='';return;}empty.style.display='none';tbody.innerHTML=slice.map(p=>{const ri=PEDIDOS.indexOf(p);return`<tr class="${ri===selIdx?'selected':''}" onclick="selectOrder(${ri})"><td style="font-weight:700;color:var(--text-muted);">${p.prioridade||'—'}</td><td><span class="order-num">${p.num}</span></td><td title="${p.produto}">${p.produto.length>38?p.produto.slice(0,38)+'…':p.produto}</td><td title="${p.cliente}">${(p.fantasia||p.cliente).slice(0,22)}</td><td>${p.entrega||'—'}</td><td>${(p.qtd||0).toLocaleString('pt-BR')}</td><td>${tipoPill(p.tipo)}</td><td>${pillSt(p.status_prazo)}</td><td style="font-size:11px;color:var(--text-muted);">${(p.gargalo||'—').slice(0,22)}</td></tr>`;}).join('');const totalP=Math.ceil(tot/PER),s=start+1,e=Math.min(start+PER,tot);document.getElementById('pInfo').textContent=`${s}–${e} de ${tot}`;const pb=document.getElementById('pBtns');pb.innerHTML='';const mk=(l,pg,dis,act)=>{const b=document.createElement('button');b.className='page-btn'+(act?' active':'');b.textContent=l;b.disabled=dis;if(!dis&&pg)b.onclick=()=>{page=pg;render();};return b;};pb.appendChild(mk('←',page-1,page===1,false));for(let p2=1;p2<=totalP;p2++){if(totalP>6&&p2>2&&p2<totalP-1&&Math.abs(p2-page)>1){if(p2===3||p2===totalP-2){const ss=document.createElement('span');ss.textContent='…';ss.style.cssText='padding:0 4px;color:var(--text-muted);font-size:12px;line-height:28px;';pb.appendChild(ss);}continue;}pb.appendChild(mk(p2,p2,false,p2===page));}pb.appendChild(mk('→',page+1,page===totalP,false));}

function selectOrder(idx){selIdx=idx;render();const p=PEDIDOS[idx];document.getElementById('detEmpty').style.display='none';const body=document.getElementById('detBody');body.style.display='block';const filaHTML=p.fila&&p.fila.length?p.fila.sort((a,b)=>a.seq-b.seq).map(f=>`<div class="fluxo-step"><div class="fluxo-num">${f.seq}</div><div class="fluxo-info"><div class="fluxo-maq">${f.maquina}</div><div class="fluxo-dates">${f.inicio}→${f.termino} · ${f.horas}h<span class="${f.status==='RISCO'?'pill-risco':'pill-ok-f'}">${f.status}</span></div></div></div>`).join(''):p.fluxo?`<div style="font-size:12px;color:var(--text-muted);line-height:1.6;">${p.fluxo.split('>').map(s=>`<span style="display:inline-block;background:rgba(255,255,255,.04);padding:2px 8px;border-radius:4px;margin:2px;">${s.trim()}</span>`).join('<span style="color:var(--blue-light);margin:0 2px;">→</span>')}</div>`:'<p style="font-size:12px;color:var(--text-muted);">Sem fluxo definido.</p>';body.innerHTML=`<div class="det-num">${p.num}</div><div style="font-size:13px;font-weight:500;margin-top:2px;">${p.fantasia||p.cliente}</div><div style="margin:10px 0;">${pillSt(p.status_prazo)}</div><div class="det-section"><div class="det-title">Dados</div><div class="det-grid"><div class="det-item"><label>Entrega</label><span>${p.entrega||'—'}</span></div><div class="det-item"><label>Quantidade</label><span>${(p.qtd||0).toLocaleString('pt-BR')}</span></div><div class="det-item"><label>Tipo</label><span>${p.tipo||'—'}</span></div><div class="det-item"><label>Prioridade</label><span>${p.prioridade}</span></div><div class="det-item"><label>Hrs Totais</label><span>${p.hrs_totais||0}h</span></div><div class="det-item"><label>Gargalo</label><span style="color:var(--red);font-size:11.5px;">${p.gargalo||'—'}</span></div></div></div><div class="det-section"><div class="det-title">Fluxo de Produção</div>${filaHTML}</div>${p.pendencia?`<div class="det-section"><div style="font-size:12px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:7px;padding:8px 12px;color:var(--amber);">⚠ ${p.pendencia}</div></div>`:''}`;} 

document.querySelectorAll('.pcp-table th[data-col]').forEach(th=>{th.addEventListener('click',()=>{const col=th.dataset.col;sortAsc=sortCol===col?!sortAsc:true;sortCol=col;document.querySelectorAll('.sort-icon').forEach(i=>{i.classList.remove('active');i.textContent='↕';});const ic=document.getElementById('s-'+col);if(ic){ic.classList.add('active');ic.textContent=sortAsc?'↑':'↓';}applyFilters();});});
['searchInput','fStatus','fTipo','fGrupo'].forEach(id=>{document.getElementById(id).addEventListener(id==='searchInput'?'input':'change',applyFilters);});
document.getElementById('btnExport').addEventListener('click',()=>{const cols=['num','produto','cliente','entrega','qtd','tipo','impressao','status_prazo','gargalo','hrs_totais','prioridade'],hdr=['Pedido','Produto','Cliente','Entrega','Qtd','Tipo','Impressão','Status','Gargalo','Hrs Totais','Prioridade'],rows=[hdr,...filtered.map(p=>cols.map(c=>(p[c]||'').toString().replace(/,/g,' ')))];const a=document.createElement('a');a.href='data:text/csv;charset=utf-8,\uFEFF'+encodeURIComponent(rows.map(r=>r.join(',')).join('\n'));a.download='pcp_ordens.csv';a.click();});

const maxD=Math.max(...MAQUINAS.map(m=>m.dias_demanda),1);
document.getElementById('maqSide').innerHTML=MAQUINAS.filter(m=>m.carga_total>0).map(m=>{const pct=Math.min(100,(m.dias_demanda/maxD)*100);const isR=m.dias_demanda>20,isA=m.dias_demanda>8&&!isR;const bc=isR?'var(--red)':isA?'var(--amber)':'var(--teal)';const dc=isR?'color:var(--red)':isA?'color:var(--amber)':'';return`<div class="maq-side-card"><div class="maq-side-top"><span class="maq-side-name">${m.nome}</span><span style="${dc};font-size:11px;">${m.dias_demanda}d · ${m.obs}</span></div><div class="maq-bar-bg"><div class="maq-bar-fill" style="width:${pct}%;background:${bc}"></div></div></div>`;}).join('');

kpis();
applyFilters();
</script>
</body>
</html>
