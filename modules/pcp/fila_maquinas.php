<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════════════════
//  DOMBAG — Fila de Máquinas (PCP)
//  Fonte: MySQL local (pedidos importados, status Pendente/Produção)
//  Sacaria c/ impressão: altura 70–110 → Flexo (máx 2/dia); demais → Carimbadeira
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

$erp_ok = true;
$erp_msg = '';

try {
    $maquinas = pcpGetMaquinas();
    $pedidos = pcpGetPedidosMySQL();
    $plan = pcpCalcPlanejamento($pedidos, $maquinas);
} catch (Exception $e) {
    $erp_ok = false;
    $erp_msg = 'Erro: ' . $e->getMessage();
    $plan = ['pedidos' => [], 'fila' => [], 'prog' => [], 'maquinas' => []];
}

$FILA = json_encode($plan['fila'], JSON_UNESCAPED_UNICODE);
$MAQUINAS = json_encode($plan['maquinas'], JSON_UNESCAPED_UNICODE);
$maquinasComFila = array_values(array_unique(array_column($plan['fila'], 'maquina')));
$MAQUINAS_UNICAS = json_encode($maquinasComFila, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fila de Máquinas | DOMBAG</title>
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
.content{flex:1;overflow:hidden;padding:24px;display:flex;flex-direction:column;}
.sidebar{width:var(--sidebar-w);min-width:var(--sidebar-w);background:var(--blue-mid);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden;transition:width var(--transition),min-width var(--transition);position:relative;z-index:100;}
.sidebar.collapsed{width:var(--sidebar-w-col);min-width:var(--sidebar-w-col);}
.alert-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:10px 16px;font-size:12.5px;color:var(--amber);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.period-bar{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.period-bar span{font-size:12px;color:var(--text-muted);}
.period-bar input[type=date]{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 10px;height:34px;font-family:'Segoe UI',sans-serif;font-size:12.5px;color:var(--text-primary);outline:none;}
.btn-refresh{padding:0 14px;height:34px;background:var(--blue-accent);border:none;border-radius:8px;color:#fff;font-family:'Segoe UI',sans-serif;font-size:12.5px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;}
.btn-refresh:hover{background:var(--blue-light);}
/* Page CSS - idêntico ao original */
.maq-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px;}
.maq-tab{padding:7px 16px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:12.5px;font-weight:500;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:all .15s;white-space:nowrap;}
.maq-tab:hover{background:var(--card-hover);color:var(--text-primary);}
.maq-tab.active{background:rgba(45,106,255,.2);color:#7db3ff;border-color:rgba(45,106,255,.4);}
.fila-layout{display:grid;grid-template-columns:1fr 260px;gap:16px;min-width:0;flex:1;min-height:0;}
.fila-layout>*{min-width:0;}
.orders-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;min-height:0;}
.orders-panel .panel-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.panel-title{font-size:13px;font-weight:600;}
.fila-table{width:100%;border-collapse:collapse;}
.fila-table th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:#112240;white-space:nowrap;position:sticky;top:0;z-index:2;}
.fila-table td{padding:11px 14px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);white-space:nowrap;}
.fila-table tr:last-child td{border-bottom:none;}
.fila-table tbody tr:hover td{background:rgba(255,255,255,.03);}
.seq-badge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(45,106,255,.2);color:#7db3ff;font-size:10px;font-weight:700;}
.pill-risco{background:rgba(245,158,11,.12);color:var(--amber);display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.pill-ok-f{background:rgba(0,201,167,.1);color:var(--teal);display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.pill-risco::before,.pill-ok-f::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;}
.maq-info-panel{display:flex;flex-direction:column;gap:12px;}
.maq-info-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;}
.maq-info-title{font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-muted);margin-bottom:12px;}
.maq-info-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border);font-size:12.5px;}
.maq-info-row:last-child{border-bottom:none;}
.maq-info-row label{color:var(--text-muted);font-size:11.5px;}
.bar-mini-bg{height:4px;background:rgba(255,255,255,.06);border-radius:2px;overflow:hidden;margin-top:8px;}
.bar-mini-fill{height:100%;border-radius:2px;}
.empty-fila{text-align:center;padding:48px 20px;color:var(--text-muted);font-size:13px;}
@media(max-width:1100px){.fila-layout{grid-template-columns:1fr;}}
@media(max-width:768px){.maq-tabs{gap:4px;}}
@media(max-width:480px){.maq-tab{padding:6px 10px;font-size:12px;}}
</style>

<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
<div class="page-title"><h1>Fila de Máquinas</h1><p id="topbar-date"></p></div>
      </div>
      <div class="topbar-actions">
        <a href="/pcp/planejamento" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Planejamento
        </a>
        <button class="icon-btn" id="btnExport" title="Exportar CSV">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </button>
      </div>
    </header>
    <div class="content">

      <?php if (!$erp_ok): ?>
      <div class="alert-warn">&#9888; <?= htmlspecialchars($erp_msg) ?></div>
      <?php endif; ?>

      <!-- Regras de alocação de máquina -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
        <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text-muted);margin-bottom:10px;">Sacaria com impressão — alocação por altura</div>
          <div style="display:flex;flex-direction:column;gap:6px;font-size:12.5px;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--teal);flex-shrink:0;"></span>
              <span><strong>Altura &lt; 70 cm</strong> → Carimbadeira Sacaria</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#a78bfa;flex-shrink:0;"></span>
              <span><strong>Altura 70–110 cm</strong> → Flexo</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--teal);flex-shrink:0;"></span>
              <span><strong>Altura &gt; 110 cm</strong> → Carimbadeira Sacaria</span>
            </div>
          </div>
        </div>
        <div style="background:rgba(167,139,250,.06);border:1px solid rgba(167,139,250,.25);border-radius:var(--radius);padding:14px 18px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#a78bfa;margin-bottom:10px;">Flexo — restrição de setup</div>
          <div style="font-size:12.5px;line-height:1.7;color:var(--text-primary);">
            <strong>Máximo 2 pedidos por dia</strong><br>
            <span style="color:var(--text-muted);">Setup exige muito tempo. O planejador distribui automaticamente pedidos extras para o dia seguinte.</span>
          </div>
          <div style="margin-top:10px;font-size:11.5px;color:var(--text-muted);">
            Itens na fila hoje: <strong style="color:#a78bfa;"><?= count(array_filter($plan['fila'], fn ($f) => $f['maquina'] === 'Flexo')) ?></strong>
          </div>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <span style="color:var(--teal);font-size:12px;"><?= count($plan['fila']) ?> item(ns) na fila</span>
        <span style="color:var(--text-muted);font-size:12px;">·</span>
        <span style="color:var(--text-muted);font-size:12px;">Fonte: pedidos importados (status Pendente de produção + Em Produção)</span>
        <a href="<?= $_SERVER['REQUEST_URI'] ?>" class="btn-refresh" style="margin-left:auto;height:32px;padding:0 14px;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Atualizar
        </a>
      </div>

      <div class="maq-tabs" id="maqTabs"></div>
      <div class="fila-layout">
        <div class="orders-panel">
          <div class="panel-header">
            <span class="panel-title" id="filaTitle">Fila</span>
            <span style="font-size:11.5px;color:var(--text-muted);" id="filaCount"></span>
          </div>
          <div class="table-wrap">
            <table class="fila-table">
              <thead><tr><th>Seq</th><th>Pedido</th><th>Produto</th><th>Cliente</th><th>Entrega</th><th>Qtd</th><th>Horas</th><th>Início</th><th>Término</th><th>Status</th></tr></thead>
              <tbody id="filaBody"></tbody>
            </table>
          </div>
          <div id="filaEmpty" style="display:none;" class="empty-fila">Nenhum item na fila para esta máquina.</div>
        </div>
        <div class="maq-info-panel" id="maqInfoPanel"></div>
      </div>

    </div>
  </div>
</div>

<script>
const FILA           = <?= $FILA ?>;
const MAQUINAS       = <?= $MAQUINAS ?>;
const MAQUINAS_UNICAS= <?= $MAQUINAS_UNICAS ?>;
let activeMaq = MAQUINAS_UNICAS[0] || '';

(function(){const dias=['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],meses=['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'],d=new Date();document.getElementById('topbar-date').textContent=dias[d.getDay()]+', '+d.getDate()+' de '+meses[d.getMonth()]+' de '+d.getFullYear();})();

function renderTabs(){const el=document.getElementById('maqTabs');el.innerHTML=MAQUINAS_UNICAS.map(m=>`<button class="maq-tab${m===activeMaq?' active':''}" onclick="selectMaq('${m}')">${m}</button>`).join('');}

function selectMaq(maq){activeMaq=maq;renderTabs();renderFila();renderInfo();}

function tipoBadge(qtd){return qtd<20?'<span style="background:rgba(139,92,246,.14);color:#c4b5fd;font-weight:700;padding:2px 7px;border-radius:6px;font-size:10.5px;">Amostra</span>':'<span style="background:rgba(16,185,129,.12);color:#34d399;font-weight:700;padding:2px 7px;border-radius:6px;font-size:10.5px;">Venda</span>';}

function renderFila(){const items=FILA.filter(f=>f.maquina===activeMaq).sort((a,b)=>a.seq-b.seq);document.getElementById('filaTitle').textContent=activeMaq;document.getElementById('filaCount').textContent=items.length+' ordem(ns)';const tbody=document.getElementById('filaBody'),empty=document.getElementById('filaEmpty');if(!items.length){tbody.innerHTML='';empty.style.display='block';return;}empty.style.display='none';tbody.innerHTML=items.map(f=>`<tr><td><span class="seq-badge">${f.seq}</span></td><td style="font-weight:700;color:#7db3ff;">${f.pedido}</td><td title="${f.produto}">${f.produto.length>40?f.produto.slice(0,40)+'…':f.produto}</td><td title="${f.cliente}">${f.cliente.slice(0,28)}</td><td>${f.entrega||'—'}</td><td>${tipoBadge(f.qtd)}&nbsp;${f.qtd.toLocaleString('pt-BR')}</td><td>${f.horas}h</td><td>${f.inicio||'—'}</td><td>${f.termino||'—'}</td><td>${f.status==='RISCO'?'<span class="pill-risco">Risco</span>':'<span class="pill-ok-f">OK</span>'}</td></tr>`).join('');}

function renderInfo(){const m=MAQUINAS.find(x=>x.nome===activeMaq)||{};const maxD=Math.max(...MAQUINAS.map(x=>x.dias_demanda),1);const pct=m.capacidade_dia>0?Math.min(100,(m.dias_demanda/maxD)*100):0;const isRed=m.dias_demanda>20,isAmb=m.dias_demanda>8&&!isRed;const barC=isRed?'var(--red)':isAmb?'var(--amber)':'var(--teal)';const filaItems=FILA.filter(f=>f.maquina===activeMaq);const totalH=filaItems.reduce((a,f)=>a+f.horas,0);const risco=filaItems.filter(f=>f.status==='RISCO').length;const ok=filaItems.filter(f=>f.status==='OK').length;document.getElementById('maqInfoPanel').innerHTML=`<div class="maq-info-card"><div class="maq-info-title">Capacidade</div>${m.nome?`<div class="maq-info-row"><label>Qtde de máquinas</label><strong>${m.qtd}</strong></div><div class="maq-info-row"><label>Velocidade</label><strong>${m.velocidade} un/min</strong></div><div class="maq-info-row"><label>Horas/dia</label><strong>${m.horas_dia}h</strong></div><div class="maq-info-row"><label>Cap. diária total</label><strong>${m.capacidade_dia.toLocaleString('pt-BR',{maximumFractionDigits:0})}h</strong></div><div class="maq-info-row"><label>Carga total</label><strong>${m.carga_total.toLocaleString('pt-BR',{maximumFractionDigits:1})}h</strong></div><div class="maq-info-row"><label>Dias de demanda</label><strong style="${isRed?'color:var(--red)':isAmb?'color:var(--amber)':''}">${m.dias_demanda}d</strong></div><div class="bar-mini-bg"><div class="bar-mini-fill" style="width:${pct}%;background:${barC}"></div></div>`:'<p style="font-size:12px;color:var(--text-muted);">Sem dados de capacidade.</p>'}</div><div class="maq-info-card"><div class="maq-info-title">Fila Atual</div><div class="maq-info-row"><label>Total na fila</label><strong>${filaItems.length}</strong></div><div class="maq-info-row"><label>Total de horas</label><strong>${totalH.toLocaleString('pt-BR',{maximumFractionDigits:1})}h</strong></div><div class="maq-info-row"><label>Status RISCO</label><strong style="color:var(--amber);">${risco}</strong></div><div class="maq-info-row"><label>Status OK</label><strong style="color:var(--teal);">${ok}</strong></div></div>`;}

document.getElementById('btnExport').addEventListener('click',()=>{const items=FILA.filter(f=>f.maquina===activeMaq).sort((a,b)=>a.seq-b.seq);const cols=['seq','pedido','produto','cliente','entrega','qtd','horas','inicio','termino','status'];const hdr=['Seq','Pedido','Produto','Cliente','Entrega','Qtd','Horas','Início','Término','Status'];const rows=[hdr,...items.map(f=>cols.map(c=>(f[c]||'').toString().replace(/,/g,' ')))];const a=document.createElement('a');a.href='data:text/csv;charset=utf-8,\uFEFF'+encodeURIComponent(rows.map(r=>r.join(',')).join('\n'));a.download='fila_'+activeMaq.replace(/\s+/g,'_')+'.csv';a.click();});

// ── Render inicial ──────────────────────────────────────────────────────────
renderTabs();
renderFila();
renderInfo();
</script>
</body>
</html>
