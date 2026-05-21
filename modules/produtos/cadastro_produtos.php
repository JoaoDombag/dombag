<?php
// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Produtos: Consulta / Listagem
//  Rota: /produtos
//  Arquivo: modules/produtos/cadastro_produtos.php
// ══════════════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

// ══════════════════════════════════════════════════════════════════════
//  AJAX — Excluir produto
// ══════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $_POST['action'] ?? '';

    try {
        $db = pcpGetPDO();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($action === 'excluir') {
            $id = (int) ($body['pro_codigo'] ?? 0);
            if (!$id) {
                throw new Exception('ID inválido.');
            }
            $check = $db->prepare('SELECT COUNT(*) FROM ITENS_VENDAS WHERE PRO_CODIGO = ?');
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception('Produto possui itens de venda vinculados e não pode ser excluído.');
            }
            $db->prepare('DELETE FROM PRODUTOS WHERE PRO_CODIGO = ?')->execute([$id]);
            echo json_encode(['success' => true, 'msg' => 'Produto excluído com sucesso.']);
            exit;
        }

        throw new Exception('Ação desconhecida.');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  Busca produtos do banco
// ══════════════════════════════════════════════════════════════════════
$produtos    = [];
$db_error    = '';
$produtos_js = '[]';

try {
    $db = pcpGetPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $st = $db->query("
        SELECT
            PRO_CODIGO AS pro_codigo,
            PRO_CODIGO_YZ AS pro_codigo_yz,
            PRO_DESCRICAO AS pro_descricao,
            COALESCE(PRO_FLUXO, '')         AS pro_fluxo,
            COALESCE(PRO_TIPO, '')          AS pro_tipo,
            COALESCE(PRO_IMPRESSAO, 'NAO')  AS pro_impressao,
            COALESCE(PRO_VALVULADO, 'NAO')  AS pro_valvulado,
            COALESCE(PRO_COMPRIMENTO, 0)    AS pro_comprimento,
            COALESCE(PRO_MAQ_IMPRESSAO, '') AS pro_maq_impressao,
            (SELECT COUNT(*) FROM ITENS_VENDAS iv WHERE iv.PRO_CODIGO = p.PRO_CODIGO) AS total_itens
        FROM PRODUTOS p
        ORDER BY PRO_DESCRICAO
    ");
    $produtos    = $st->fetchAll(PDO::FETCH_ASSOC);
    $produtos_js = json_encode($produtos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$total_prod = count($produtos);
$total_bags = count(array_filter($produtos, fn($p) => stripos($p['pro_tipo'], 'BAG') !== false));
$total_sacs = count(array_filter($produtos, fn($p) => stripos($p['pro_tipo'], 'SACARIA') !== false));
$total_imp  = count(array_filter($produtos, fn($p) => ($p['pro_impressao'] ?? '') === 'SIM'));
$total_vinc = count(array_filter($produtos, fn($p) => (int)($p['total_itens'] ?? 0) > 0));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Produtos | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}

/* Topbar */
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;white-space:nowrap;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.btn-primary{background:var(--blue-accent);color:white;border:none;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:6px;white-space:nowrap;text-decoration:none;}
.btn-primary:hover{background:var(--blue-light);}
.icon-btn{width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;}
.icon-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.content{flex:1;overflow:hidden;padding:24px;display:flex;flex-direction:column;}

/* KPIs */
.stat-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.stat-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;padding:13px 16px;display:flex;align-items:center;gap:12px;transition:.2s;}
.stat-card:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.1);}
.stat-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-card.c-blue .stat-icon{background:rgba(45,106,255,.15);color:#7db3ff;}
.stat-card.c-teal .stat-icon{background:rgba(0,201,167,.12);color:var(--teal);}
.stat-card.c-amb  .stat-icon{background:rgba(245,158,11,.12);color:var(--amber);}
.stat-card.c-red  .stat-icon{background:rgba(239,68,68,.12);color:var(--red);}
.stat-card.c-grn  .stat-icon{background:rgba(34,197,94,.12);color:var(--green);}
.stat-val{font-size:22px;font-weight:700;letter-spacing:-.6px;line-height:1;}
.stat-lbl{font-size:11px;color:var(--text-muted);margin-top:2px;}

/* Toolbar */
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap;}
.toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:8px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 12px;height:36px;transition:.15s;}
.search-box:focus-within{border-color:rgba(45,106,255,.5);}
.search-box svg{color:var(--text-muted);flex-shrink:0;}
.search-box input{background:transparent;border:none;outline:none;font-family:'Segoe UI',sans-serif;font-size:13px;color:var(--text-primary);width:240px;}
.search-box input::placeholder{color:var(--text-muted);}
.filter-select{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 10px;height:36px;font-family:'Segoe UI',sans-serif;font-size:12.5px;color:var(--text-primary);cursor:pointer;outline:none;}
.filter-select option{background:#112240;}
.results-count{font-size:11.5px;color:var(--text-muted);}

/* Tabela */
.orders-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;flex:1;min-height:0;display:flex;flex-direction:column;}
.orders-panel .panel-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.panel-title{font-size:13px;font-weight:600;}
.table-wrap{overflow:auto;flex:1;}
.prod-table{width:100%;border-collapse:collapse;}
.prod-table th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:#112240;white-space:nowrap;cursor:pointer;user-select:none;position:sticky;top:0;z-index:2;}
.prod-table th:hover{color:var(--text-primary);}
.prod-table td{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);white-space:nowrap;}
.prod-table tr:last-child td{border-bottom:none;}
.prod-table tbody tr:hover td{background:rgba(255,255,255,.03);}
.sort-icon{margin-left:3px;opacity:.4;font-size:9px;}
.sort-icon.active{opacity:1;color:var(--blue-light);}
.bool-yes{background:rgba(0,201,167,.1);color:var(--teal);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.bool-no{background:rgba(255,255,255,.06);color:var(--text-muted);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.tipo-bag{background:rgba(45,106,255,.12);color:#7db3ff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.tipo-sac{background:rgba(0,201,167,.1);color:var(--teal);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.fluxo-text{font-size:11.5px;color:var(--text-muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;display:block;}
.action-btns{display:flex;gap:5px;justify-content:center;}
.btn-edit{background:rgba(45,106,255,.15);color:#7db3ff;border:none;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
.btn-edit:hover{background:rgba(45,106,255,.3);}
.btn-del{background:rgba(239,68,68,.12);color:var(--red);border:none;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;}
.btn-del:hover{background:rgba(239,68,68,.25);}
.vinc-badge{background:rgba(0,201,167,.08);color:var(--teal);font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;}
.db-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:12.5px;color:#ef4444;display:flex;align-items:center;gap:8px;}

@media(max-width:1100px){.stat-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:640px){.search-box input{width:140px;}}
@media(max-width:480px){.stat-strip{grid-template-columns:1fr 1fr;}.search-box input{width:auto;flex:1;}}
</style>
</head>
<body>
<div class="app-wrapper">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-left">
      <div class="page-title">
        <h1>Produtos</h1>
        <p><?= $total_prod ?> produto(s) cadastrado(s)</p>
      </div>
    </div>
    <div class="topbar-actions">
      <button class="icon-btn" id="btnExport" title="Exportar CSV">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      </button>
      <a href="/produtos/cadastro" class="btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo Produto
      </a>
    </div>
  </header>

  <div class="content">

    <?php if ($db_error): ?>
    <div class="db-error">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Erro ao conectar ao banco: <?= htmlspecialchars($db_error) ?>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="stat-strip">
      <div class="stat-card c-blue">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
        <div><div class="stat-val"><?= $total_prod ?></div><div class="stat-lbl">Total de Produtos</div></div>
      </div>
      <div class="stat-card c-teal">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div>
        <div><div class="stat-val"><?= $total_bags ?></div><div class="stat-lbl">Big Bags</div></div>
      </div>
      <div class="stat-card c-amb">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
        <div><div class="stat-val"><?= $total_sacs ?></div><div class="stat-lbl">Sacarias</div></div>
      </div>
      <div class="stat-card c-red">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg></div>
        <div><div class="stat-val"><?= $total_imp ?></div><div class="stat-lbl">Com Impressão</div></div>
      </div>
      <div class="stat-card c-grn">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div><div class="stat-val"><?= $total_vinc ?></div><div class="stat-lbl">Vinculados a Pedidos</div></div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-box">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="searchInput" placeholder="Buscar produto, máquina ou fluxo…">
        </div>
        <select class="filter-select" id="filterTipo">
          <option value="">Todos os tipos</option>
          <option value="BAG">BAG</option>
          <option value="SACARIA">SACARIA</option>
        </select>
        <select class="filter-select" id="filterImp">
          <option value="">Impressão (todos)</option>
          <option value="SIM">Com impressão</option>
          <option value="NAO">Sem impressão</option>
        </select>
        <select class="filter-select" id="filterValv">
          <option value="">Valvulado (todos)</option>
          <option value="SIM">Valvulado</option>
          <option value="NAO">Não valvulado</option>
        </select>
      </div>
      <span class="results-count" id="resultsCount"></span>
    </div>

    <!-- Tabela -->
    <div class="orders-panel">
      <div class="panel-header">
        <span class="panel-title">Produtos cadastrados</span>
        <span class="results-count" id="resultsPanel"></span>
      </div>
      <div class="table-wrap">
        <table class="prod-table">
          <thead><tr>
            <th data-col="pro_descricao">Produto <span class="sort-icon active" id="s-pro_descricao">↑</span></th>
            <th data-col="pro_tipo">Tipo <span class="sort-icon" id="s-pro_tipo">↕</span></th>
            <th data-col="pro_comprimento">Comp. (cm) <span class="sort-icon" id="s-pro_comprimento">↕</span></th>
            <th>Impressão</th>
            <th>Valvulado</th>
            <th>Máq. Impressão</th>
            <th>Fluxo de Produção</th>
            <th>Pedidos</th>
            <th>Cód. YZ</th>
            <th style="text-align:center;">Ações</th>
          </tr></thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>
      <div id="emptyState" style="display:none;text-align:center;padding:50px 20px;color:var(--text-muted);">
        <p style="font-size:13px;">Nenhum produto encontrado.</p>
        <a href="/produtos/cadastro" style="margin-top:12px;display:inline-block;background:var(--blue-accent);color:white;border:none;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;">+ Cadastrar primeiro produto</a>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
let PRODUTOS = <?= $produtos_js ?>;
let sortCol = 'pro_descricao', sortAsc = true, filtered = [];

function boolPill(v) {
  return v === 'SIM'
    ? '<span class="bool-yes">Sim</span>'
    : '<span class="bool-no">Não</span>';
}
function tipoPill(v) {
  if (!v) return '<span style="color:var(--text-muted);font-size:11px;">—</span>';
  return v === 'BAG'
    ? '<span class="tipo-bag">BAG</span>'
    : '<span class="tipo-sac">SACARIA</span>';
}

function applyFilters() {
  const q  = document.getElementById('searchInput').value.toLowerCase();
  const ti = document.getElementById('filterTipo').value;
  const im = document.getElementById('filterImp').value;
  const va = document.getElementById('filterValv').value;

  filtered = PRODUTOS.filter(p => {
    if (q && !(p.pro_descricao||'').toLowerCase().includes(q)
           && !(p.pro_maq_impressao||'').toLowerCase().includes(q)
           && !(p.pro_fluxo||'').toLowerCase().includes(q)) return false;
    if (ti && p.pro_tipo !== ti) return false;
    if (im && p.pro_impressao !== im) return false;
    if (va && p.pro_valvulado !== va) return false;
    return true;
  });

  filtered.sort((a, b) => {
    let va2 = a[sortCol] ?? '', vb2 = b[sortCol] ?? '';
    if (sortCol === 'pro_comprimento') { va2 = parseFloat(va2)||0; vb2 = parseFloat(vb2)||0; }
    if (va2 < vb2) return sortAsc ? -1 :  1;
    if (va2 > vb2) return sortAsc ?  1 : -1;
    return 0;
  });

  render();
}

function render() {
  const tot   = filtered.length;
  const tbody = document.getElementById('tbody');
  const empty = document.getElementById('emptyState');
  document.getElementById('resultsCount').textContent = tot + ' produto(s)';
  document.getElementById('resultsPanel').textContent = tot + ' produto(s)';

  if (!tot) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
  empty.style.display = 'none';

  tbody.innerHTML = filtered.map(p => {
    const desc = (p.pro_descricao||'');
    const descDisplay = desc.length > 48 ? desc.slice(0, 48) + '…' : desc;
    const vinc = parseInt(p.total_itens||0);
    const pedidosBadge = vinc > 0
      ? `<span class="vinc-badge">${vinc} item(ns)</span>`
      : '<span style="color:var(--text-muted);font-size:11px;">—</span>';
    const delBtn = vinc === 0
      ? `<button class="btn-del" onclick="excluirProduto(${p.pro_codigo},'${desc.replace(/'/g,"\\'")}')">&#x1F5D1;</button>`
      : '';
    return `<tr>
      <td title="${desc}" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;">${descDisplay||'—'}</td>
      <td>${tipoPill(p.pro_tipo)}</td>
      <td style="text-align:right;">${p.pro_comprimento > 0 ? parseFloat(p.pro_comprimento).toLocaleString('pt-BR') : '—'}</td>
      <td>${boolPill(p.pro_impressao)}</td>
      <td>${boolPill(p.pro_valvulado)}</td>
      <td>${p.pro_maq_impressao || '—'}</td>
      <td><span class="fluxo-text" title="${p.pro_fluxo||''}">${p.pro_fluxo || '—'}</span></td>
      <td>${pedidosBadge}</td>
      <td style="color:var(--text-muted);font-size:11.5px;">${p.pro_codigo_yz || '—'}</td>
      <td>
        <div class="action-btns">
          <a class="btn-edit" href="/produtos/cadastro?id=${p.pro_codigo}">&#x270F; Editar</a>
          ${delBtn}
        </div>
      </td>
    </tr>`;
  }).join('');
}

// Sort por coluna
document.querySelectorAll('.prod-table th[data-col]').forEach(th => {
  th.addEventListener('click', () => {
    const col = th.dataset.col;
    sortAsc = sortCol === col ? !sortAsc : true;
    sortCol = col;
    document.querySelectorAll('.sort-icon').forEach(i => { i.classList.remove('active'); i.textContent = '↕'; });
    const ic = document.getElementById('s-' + col);
    if (ic) { ic.classList.add('active'); ic.textContent = sortAsc ? '↑' : '↓'; }
    applyFilters();
  });
});

document.getElementById('searchInput').addEventListener('input', applyFilters);
['filterTipo','filterImp','filterValv'].forEach(id => document.getElementById(id).addEventListener('change', applyFilters));

// Excluir via AJAX
async function excluirProduto(id, nome) {
  if (!confirm(`Excluir o produto:\n"${nome}"?\n\nEsta ação não pode ser desfeita.`)) return;
  try {
    const resp = await fetch(window.location.href, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ action: 'excluir', pro_codigo: id })
    });
    const data = await resp.json();
    if (data.success) {
      window.location.reload();
    } else {
      alert('Erro: ' + data.msg);
    }
  } catch (e) {
    alert('Erro de comunicação: ' + e.message);
  }
}

// Export CSV
document.getElementById('btnExport').addEventListener('click', () => {
  const cols = ['pro_codigo','pro_codigo_yz','pro_descricao','pro_tipo','pro_comprimento','pro_impressao','pro_valvulado','pro_maq_impressao','pro_fluxo','total_itens'];
  const hdr  = ['ID','Cód. YZ','Produto','Tipo','Comp.(cm)','Impressão','Valvulado','Máq. Impressão','Fluxo','Qtd Pedidos'];
  const rows = [hdr, ...filtered.map(p => cols.map(c => (p[c]||'').toString().replace(/;/g,' ')))];
  const csv  = rows.map(r => r.join(';')).join('\n');
  const a    = document.createElement('a');
  a.href     = 'data:text/csv;charset=utf-8,﻿' + encodeURIComponent(csv);
  a.download = 'produtos.csv';
  a.click();
});

applyFilters();
</script>
</body>
</html>
