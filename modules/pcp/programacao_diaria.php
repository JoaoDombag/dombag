<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════════════════
//  DOMBAG — Programação Diária (PCP)
//  Fonte: pedidos importados (Pendente de produção + Em Produção)
//  Suporte a reprogramação manual por pedido.
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

$pdoDB = dbPDO();

// Garante tabela de agendamentos manuais
$pdoDB->exec("
    CREATE TABLE IF NOT EXISTS prog_agendamento (
        pa_pedido  VARCHAR(30) NOT NULL,
        pa_data    DATE        NOT NULL,
        pa_criado  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (pa_pedido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── AJAX: salvar reprogramação ────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'set_agenda') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pedido = trim((string) ($_GET['pedido'] ?? ''));
        $data   = trim((string) ($_GET['data']   ?? ''));
        if (!$pedido) throw new RuntimeException('Pedido não informado.');
        $dt = DateTime::createFromFormat('Y-m-d', $data);
        if (!$dt)    throw new RuntimeException('Data inválida.');
        $st = $pdoDB->prepare("
            INSERT INTO prog_agendamento (pa_pedido, pa_data)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE pa_data = VALUES(pa_data), pa_criado = NOW()
        ");
        $st->execute([$pedido, $dt->format('Y-m-d')]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: limpar reprogramação ────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'clear_agenda') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pedido = trim((string) ($_GET['pedido'] ?? ''));
        if (!$pedido) throw new RuntimeException('Pedido não informado.');
        $st = $pdoDB->prepare("DELETE FROM prog_agendamento WHERE pa_pedido = ?");
        $st->execute([$pedido]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Carrega overrides ─────────────────────────────────────────────────────────
$overrides = []; // [pedido => 'dd/mm/yyyy']
$overridesISO = []; // [pedido => 'yyyy-mm-dd']
try {
    $rowsOv = $pdoDB->query("
        SELECT pa_pedido, pa_data, DATE_FORMAT(pa_data,'%d/%m/%Y') AS pa_data_fmt
        FROM prog_agendamento
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsOv as $ov) {
        $overrides[$ov['pa_pedido']]    = $ov['pa_data_fmt'];
        $overridesISO[$ov['pa_pedido']] = $ov['pa_data'];
    }
} catch (Throwable) {}

// ── Cálculo do plano ──────────────────────────────────────────────────────────
$erp_ok  = true;
$erp_msg = '';

try {
    $maquinas = pcpGetMaquinas();
    $pedidos  = pcpGetPedidosMySQL();
    $plan     = pcpCalcPlanejamento($pedidos, $maquinas);
} catch (Exception $e) {
    $erp_ok  = false;
    $erp_msg = 'Erro: ' . $e->getMessage();
    $plan    = ['pedidos' => [], 'fila' => [], 'prog' => [], 'maquinas' => []];
}

// ── Aplica overrides: desloca todos os slots do pedido pelo offset de dias ───
if ($overrides && !empty($plan['prog'])) {
    // Encontra a data mais cedo (início) de cada pedido no prog calculado
    $pedidoStart = []; // [pedido => DateTime]
    foreach ($plan['prog'] as $pr) {
        $ped = $pr['pedido'];
        $dt  = DateTime::createFromFormat('d/m/Y', $pr['data']);
        if (!$dt) continue;
        if (!isset($pedidoStart[$ped]) || $dt < $pedidoStart[$ped]) {
            $pedidoStart[$ped] = clone $dt;
        }
    }

    foreach ($plan['prog'] as &$pr) {
        $ped = $pr['pedido'];
        if (!isset($overrides[$ped]) || !isset($pedidoStart[$ped])) continue;

        $dtTarget = DateTime::createFromFormat('d/m/Y', $overrides[$ped]);
        if (!$dtTarget) continue;

        $diff       = $pedidoStart[$ped]->diff($dtTarget);
        $offsetDays = (int) $diff->format('%r%a'); // positivo = avança, negativo = recua
        if ($offsetDays === 0) continue;

        $dtEntry = DateTime::createFromFormat('d/m/Y', $pr['data']);
        if (!$dtEntry) continue;
        $dtEntry->modify("{$offsetDays} days");

        $pr['data']   = $dtEntry->format('d/m/Y');
        $pr['manual'] = true;
    }
    unset($pr);
}

// ── Agrupa por data ───────────────────────────────────────────────────────────
$dateMap = [];
foreach ($plan['prog'] as $pr) {
    $dateMap[$pr['data']][] = $pr;
}

$datesSort = array_keys($dateMap);
usort($datesSort, function ($a, $b) {
    $da = DateTime::createFromFormat('d/m/Y', $a);
    $db = DateTime::createFromFormat('d/m/Y', $b);
    return $da <=> $db;
});
$dateMapOrdenado = [];
foreach ($datesSort as $d) {
    $dateMapOrdenado[$d] = $dateMap[$d];
}

// ── Estatísticas globais ──────────────────────────────────────────────────────
$totalHoras  = round(array_sum(array_column($plan['prog'], 'horas')), 1);
$totalLinhas = count($plan['prog']);
$totalDatas  = count($dateMapOrdenado);

// Capacidade por máquina por dia (para alerta visual de sobrecarga)
$capPorMaq = []; // [maq => horas_dia]
foreach ($maquinas as $nm => $m) {
    $capPorMaq[$nm] = (float) ($m['horas_dia'] ?? 8) * (float) ($m['qtd'] ?? 1);
}
// Carga total por (data, maq)
$cargaDiaMaq = [];
foreach ($plan['prog'] as $pr) {
    $key = $pr['data'] . '|' . $pr['maquina'];
    $cargaDiaMaq[$key] = ($cargaDiaMaq[$key] ?? 0.0) + (float) $pr['horas'];
}

$PROG      = json_encode(array_values($plan['prog']), JSON_UNESCAPED_UNICODE);
$DATES     = json_encode($datesSort, JSON_UNESCAPED_UNICODE);
$DATE_MAP  = json_encode($dateMapOrdenado, JSON_UNESCAPED_UNICODE);
$OVERRIDES = json_encode($overridesISO, JSON_UNESCAPED_UNICODE);
$CAP_MAQ   = json_encode($capPorMaq, JSON_UNESCAPED_UNICODE);
$CARGA     = json_encode($cargaDiaMaq, JSON_UNESCAPED_UNICODE);

// Realizado
$realizadoMap = [];
try {
    $rowsR = $pdoDB->query("
        SELECT DATE_FORMAT(pd_data,'%d/%m/%Y') AS data,
               pd_pedido,
               SUM(pd_quantidade) AS qtd
        FROM producao_diaria
        WHERE pd_pedido IS NOT NULL AND TRIM(pd_pedido) <> ''
        GROUP BY pd_data, pd_pedido
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsR as $r) {
        $realizadoMap[$r['data']][$r['pd_pedido']] = (float) $r['qtd'];
    }
} catch (Throwable) {}
$REALIZADO = json_encode($realizadoMap, JSON_UNESCAPED_UNICODE);

// Planejamento (pcp_planejamento): mapa por [data dd/mm/yyyy][pedido] => status
$planMap = [];
try {
    $rowsP = $pdoDB->query("
        SELECT DATE_FORMAT(pp.pp_data,'%d/%m/%Y') AS data,
               v.ven_codigo_yzidro AS pedido,
               MAX(CASE WHEN pp.pp_status='Registrado' THEN 'Registrado' ELSE 'Planejado' END) AS status
        FROM pcp_planejamento pp
        INNER JOIN itens_vendas iv ON iv.iv_codigo = pp.iv_codigo
        INNER JOIN vendas v ON v.ven_codigo = iv.ven_codigo
        GROUP BY pp.pp_data, v.ven_codigo_yzidro
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsP as $rp) {
        $planMap[$rp['data']][$rp['pedido']] = $rp['status'];
    }
} catch (Throwable) {}
$PLAN_MAP = json_encode($planMap, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Programação Diária | DOMBAG</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
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
.alert-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:10px 16px;font-size:12.5px;color:var(--amber);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.period-bar{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.period-bar span{font-size:12px;color:var(--text-muted);}
.btn-refresh{padding:0 14px;height:34px;background:var(--blue-accent);border:none;border-radius:8px;color:#fff;font-family:'Segoe UI',sans-serif;font-size:12.5px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;}
.btn-refresh:hover{background:var(--blue-light);}
.stat-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;}
.stat-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;padding:12px 16px;text-align:center;}
.stat-val{font-size:20px;font-weight:700;letter-spacing:-.5px;}
.stat-lbl{font-size:11px;color:var(--text-muted);margin-top:2px;}
.prog-layout{display:grid;grid-template-columns:220px 1fr;gap:16px;min-width:0;transition:grid-template-columns .22s cubic-bezier(.4,0,.2,1);}
.prog-layout>*{min-width:0;}
.prog-layout.cal-collapsed{grid-template-columns:36px 1fr;}
.calendar-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;height:fit-content;transition:width .22s;}
.calendar-header{padding:13px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:8px;user-select:none;}
.cal-toggle{width:24px;height:24px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s,color .15s,transform .22s;}
.cal-toggle:hover{background:rgba(45,106,255,.12);color:#7db3ff;border-color:rgba(45,106,255,.3);}
.cal-collapsed .cal-toggle{transform:rotate(180deg);}
/* Collapsed state */
.cal-collapsed .calendar-panel{cursor:pointer;}
.cal-collapsed .calendar-header{padding:10px 0;justify-content:center;border-bottom:none;}
.cal-collapsed .cal-label{display:none;}
.cal-collapsed .date-list{display:none;}
/* Active date chip shown when collapsed */
.cal-active-chip{display:none;font-size:10px;font-weight:700;color:#7db3ff;writing-mode:vertical-rl;text-orientation:mixed;transform:rotate(180deg);padding:8px 0;text-align:center;line-height:1.3;max-height:120px;overflow:hidden;text-overflow:ellipsis;}
.cal-collapsed .cal-active-chip{display:block;}
.date-list{overflow-y:auto;max-height:calc(100vh - 280px);}
.date-list::-webkit-scrollbar{width:3px;}
.date-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
.date-item{padding:10px 16px;cursor:pointer;transition:background .15s;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);font-size:13px;}
.date-item:last-child{border-bottom:none;}
.date-item:hover{background:var(--card-hover);}
.date-item.active{background:rgba(45,106,255,.15);color:#7db3ff;}
.date-badge{font-size:10px;background:rgba(255,255,255,.08);padding:2px 7px;border-radius:10px;color:var(--text-muted);}
.date-item.active .date-badge{background:rgba(45,106,255,.2);color:#7db3ff;}
.date-item.has-manual .date-badge{background:rgba(251,191,36,.15);color:var(--amber);}
.orders-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.orders-panel .panel-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;}
.panel-title{font-size:13px;font-weight:600;}
.prog-table{width:100%;border-collapse:collapse;}
.prog-table th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:rgba(0,0,0,.1);white-space:nowrap;}
.prog-table td{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);white-space:nowrap;}
.prog-table tr:last-child td{border-bottom:none;}
.prog-table tbody tr:hover td{background:rgba(255,255,255,.03);}
.prog-table tbody tr.row-manual td{background:rgba(251,191,36,.04);}
.maq-chip{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;background:rgba(45,106,255,.12);color:#7db3ff;}
.obs-apos{font-size:11px;color:var(--red);}
.obs-normal{font-size:11px;color:var(--text-muted);}
.empty-prog{text-align:center;padding:40px 20px;color:var(--text-muted);font-size:13px;}
/* Reprogramação */
.reprog-cell{display:flex;align-items:center;gap:6px;}
.reprog-date{height:28px;padding:0 7px;background:transparent;border:1px solid transparent;border-radius:6px;color:var(--text-primary);font-family:'Segoe UI',sans-serif;font-size:12px;cursor:pointer;transition:background .15s,border-color .15s;min-width:120px;}
.reprog-date:hover{background:var(--card-hover);border-color:var(--border);}
.reprog-date:focus{background:var(--card-bg);border-color:rgba(79,123,255,.5);outline:none;}
.reprog-date.is-manual{border-color:rgba(251,191,36,.4);color:var(--amber);}
.reprog-clear{width:22px;height:22px;border-radius:5px;border:1px solid transparent;background:transparent;color:var(--text-muted);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:14px;line-height:1;transition:background .15s,color .15s,border-color .15s;flex-shrink:0;padding:0;}
.reprog-clear:hover{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.3);color:var(--red);}
.reprog-clear.hidden{visibility:hidden;pointer-events:none;}
.reprog-saving{font-size:11px;min-width:14px;text-align:center;}
.manual-badge{display:inline-flex;align-items:center;padding:1px 7px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(251,191,36,.14);color:var(--amber);white-space:nowrap;margin-left:2px;}
.overload-warn{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:11px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:var(--red);}
@media(max-width:900px){.prog-layout{grid-template-columns:1fr;}.stat-strip{grid-template-columns:1fr 1fr;}}
@media(max-width:480px){.stat-strip{grid-template-columns:1fr;}}
</style>

<link rel="stylesheet" href="/public/css/unified_admin.css">
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title"><h1>Programação Diária</h1><p id="topbar-date"></p></div>
      </div>
      <div class="topbar-actions">
        <button class="icon-btn" id="btnExport" title="Exportar CSV do dia">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </button>
      </div>
    </header>
    <div class="content">

      <?php if (!$erp_ok): ?>
      <div class="alert-warn">⚠ <?= htmlspecialchars($erp_msg) ?></div>
      <?php endif; ?>

      <div class="period-bar">
        <span style="color:var(--text-muted);font-size:12px;">Fonte: pedidos importados (Pendente de produção + Em Produção)</span>
        <a href="<?= $_SERVER['REQUEST_URI'] ?>" class="btn-refresh" style="margin-left:auto;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Atualizar
        </a>
        <span style="color:var(--teal);font-size:11.5px;" id="linhasLabel"><?= $totalLinhas ?> linha(s) programada(s)</span>
      </div>

      <div class="stat-strip">
        <div class="stat-card"><div class="stat-val" style="color:#7db3ff;"><?= $totalDatas ?></div><div class="stat-lbl">Dias com produção</div></div>
        <div class="stat-card"><div class="stat-val" style="color:var(--teal);"><?= number_format($totalHoras, 1, ',', '.') ?>h</div><div class="stat-lbl">Total de horas planejadas</div></div>
        <div class="stat-card"><div class="stat-val" style="color:var(--amber);"><?= count($overrides) ?></div><div class="stat-lbl">Reprogramados manualmente</div></div>
      </div>

      <div class="prog-layout" id="progLayout">
        <!-- Calendário lateral -->
        <div class="calendar-panel" id="calPanel" onclick="handleCalClick(event)">
          <div class="calendar-header">
            <span class="cal-label">Selecionar Data</span>
            <button class="cal-toggle" id="calToggle" title="Recolher calendário" onclick="toggleCal(event)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
          </div>
          <div class="cal-active-chip" id="calActiveChip">—</div>
          <div class="date-list" id="dateList"></div>
        </div>

        <!-- Painel principal -->
        <div>
          <div class="orders-panel">
            <div class="panel-header">
              <span class="panel-title" id="progTitle">Programação</span>
              <span style="font-size:11.5px;color:var(--text-muted);" id="progCount"></span>
              <span id="overloadAlert" style="display:none;"></span>
            </div>
            <div style="overflow-x:auto;">
              <table class="prog-table">
                <thead>
                  <tr>
                    <th>Máquina</th>
                    <th>Pedido</th>
                    <th>Produto</th>
                    <th>Cliente</th>
                    <th>Entrega</th>
                    <th style="text-align:right;">Qtd.</th>
                    <th>Tipo</th>
                    <th>Horas Prog.</th>
                    <th>Realizado</th>
                    <th>Observação</th>
                    <th>Planejamento</th>
                    <th>Reprogramar</th>
                  </tr>
                </thead>
                <tbody id="progBody"></tbody>
              </table>
            </div>
            <div id="progEmpty" style="display:none;" class="empty-prog">Selecione uma data para ver a programação.</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const PROG       = <?= $PROG ?>;
const DATES      = <?= $DATES ?>;
const dateMap    = <?= $DATE_MAP ?>;
const realizado  = <?= $REALIZADO ?>;
const overrides  = <?= $OVERRIDES ?>; // {pedido: 'yyyy-mm-dd'}
const capMaq     = <?= $CAP_MAQ ?>;   // {maq: horas_dia}
const cargaDia   = <?= $CARGA ?>;     // {'data|maq': horas}
const planMap    = <?= $PLAN_MAP ?>;  // {'data dd/mm/yyyy': {pedido: status}}
const BASE_URL   = '/pcp/programacao';
let activeDate   = DATES.length ? DATES[0] : '';

// ── Calendário retrátil ───────────────────────────────────────────────────────
const CAL_KEY = 'dombag_progcal_collapsed';
let calCollapsed = localStorage.getItem(CAL_KEY) === '1';

function applyCalState() {
  const layout = document.getElementById('progLayout');
  const toggle = document.getElementById('calToggle');
  if (calCollapsed) {
    layout.classList.add('cal-collapsed');
    toggle.title = 'Expandir calendário';
  } else {
    layout.classList.remove('cal-collapsed');
    toggle.title = 'Recolher calendário';
  }
}

function toggleCal(e) {
  if (e) e.stopPropagation();
  calCollapsed = !calCollapsed;
  localStorage.setItem(CAL_KEY, calCollapsed ? '1' : '0');
  applyCalState();
}

function handleCalClick(e) {
  // Clicar no painel colapsado (exceto no toggle) expande
  if (calCollapsed) toggleCal(null);
}

applyCalState();

(function(){
  const dias=['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],
        meses=['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'],
        d=new Date();
  document.getElementById('topbar-date').textContent=dias[d.getDay()]+', '+d.getDate()+' de '+meses[d.getMonth()]+' de '+d.getFullYear();
})();

// Seleciona a data mais próxima de hoje ao carregar
(function(){
  const today=new Date().toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'});
  if(DATES.includes(today)){activeDate=today;return;}
  const now=new Date();
  const future=DATES.find(d=>{const[di,m,a]=d.split('/');return new Date(a,m-1,di)>=now;});
  if(future)activeDate=future;
})();

function fmtDateLabel(d){
  const[dia,mes,ano]=d.split('/');
  const meses=['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
  return`${dia} ${meses[parseInt(mes)-1]} ${ano}`;
}

// Converte 'dd/mm/yyyy' → 'yyyy-mm-dd'
function toISO(d){const[di,m,a]=d.split('/');return`${a}-${m}-${di}`;}
// Converte 'yyyy-mm-dd' → 'dd/mm/yyyy'
function fromISO(d){const[a,m,di]=d.split('-');return`${di}/${m}/${a}`;}

// Detecta se algum pedido em uma data tem override manual
function dateHasManual(d){
  const items=dateMap[d]||[];
  return items.some(p=>p.manual);
}

function renderDates(){
  const el=document.getElementById('dateList');
  if(!DATES.length){
    el.innerHTML='<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px;">Nenhuma data disponível</div>';
    return;
  }
  el.innerHTML=DATES.map(d=>{
    const hasManual=dateHasManual(d);
    return`<div class="date-item${d===activeDate?' active':''}${hasManual?' has-manual':''}" onclick="selectDate('${d}')">
      <span>${fmtDateLabel(d)}</span>
      <span class="date-badge">${(dateMap[d]||[]).length}</span>
    </div>`;
  }).join('');
  // Atualiza chip da data ativa (visível quando colapsado)
  const chip=document.getElementById('calActiveChip');
  if(chip) chip.textContent = activeDate ? fmtDateLabel(activeDate) : '—';
}

function selectDate(d){
  activeDate=d;
  renderDates();
  renderProg();
  // Se colapsado, expande após selecionar a data via chip
}

function fmtNum(n){return n==null?'—':new Intl.NumberFormat('pt-BR',{maximumFractionDigits:0}).format(n);}

function tipoBadge(qtd){
  return (qtd||0)<20
    ?'<span style="background:rgba(139,92,246,.14);color:#c4b5fd;font-weight:700;padding:2px 7px;border-radius:6px;font-size:10.5px;">Amostra</span>'
    :'<span style="background:rgba(16,185,129,.12);color:#34d399;font-weight:700;padding:2px 7px;border-radius:6px;font-size:10.5px;">Venda</span>';
}

// ── Verifica sobrecarga de máquinas no dia ────────────────────────────────────
function checkOverload(d){
  const items=dateMap[d]||[];
  // soma horas por máquina
  const soma={};
  items.forEach(p=>{soma[p.maquina]=(soma[p.maquina]||0)+p.horas;});
  const overloaded=Object.entries(soma).filter(([mq,h])=>capMaq[mq]&&h>capMaq[mq]+0.01);
  return overloaded;
}

// ── Render tabela do dia ──────────────────────────────────────────────────────
function renderProg(){
  const items=dateMap[activeDate]||[];
  document.getElementById('progTitle').textContent=activeDate?fmtDateLabel(activeDate):'—';
  document.getElementById('progCount').textContent=items.length+' linha(s)';

  // Alerta de sobrecarga
  const overloaded=checkOverload(activeDate);
  const alertEl=document.getElementById('overloadAlert');
  if(overloaded.length){
    alertEl.style.display='inline-flex';
    alertEl.className='overload-warn';
    alertEl.innerHTML='⚠ Sobrecarga: '+overloaded.map(([mq,h])=>`${mq} (${h.toFixed(1)}h)`).join(', ');
  }else{
    alertEl.style.display='none';
  }

  const tbody=document.getElementById('progBody');
  const empty=document.getElementById('progEmpty');
  if(!items.length){tbody.innerHTML='';empty.style.display='block';return;}
  empty.style.display='none';

  const diaReal=realizado[activeDate]||{};

  tbody.innerHTML=items.map(p=>{
    const real=diaReal[p.pedido]??null;
    let realCell='<span style="color:var(--text-muted);font-size:11px;">—</span>';
    if(real!=null){
      const cor=real>=1?'var(--teal)':'var(--amber)';
      realCell=`<span style="font-weight:700;color:${cor};">${fmtNum(real)} un</span>`;
    }
    const obsSpan=p.obs
      ?`<span class="${p.obs.toLowerCase().includes('após')||p.obs.toLowerCase().includes('apos')?'obs-apos':'obs-normal'}">${p.obs}</span>`
      :'—';

    // Data atual deste pedido em ISO para o input
    const currentDateISO = toISO(p.data);
    const isManual = !!p.manual;
    const overrideVal = overrides[p.pedido] || '';
    // Valor do input: usa override salvo, ou a data calculada atual
    const inputVal = overrideVal || currentDateISO;

    const manualBadge = isManual ? '<span class="manual-badge">manual</span>' : '';
    const clearBtnClass = overrideVal ? '' : ' hidden';

    // Planejamento badge
    const planStatus = (planMap[p.data] || {})[p.pedido] || null;
    const planCell = planStatus === 'Registrado'
      ? '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(0,201,167,.1);color:#00c9a7;">✓ Registrado</span>'
      : planStatus === 'Planejado'
        ? '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(45,106,255,.1);color:#7db3ff;">● Planejado</span>'
        : '<span style="font-size:10.5px;color:var(--text-muted);">—</span>';

    return`<tr class="${isManual?'row-manual':''}">
      <td><span class="maq-chip">${p.maquina}</span></td>
      <td><a href="/vendas?pedido=${encodeURIComponent(p.pedido)}" target="_blank" style="font-weight:700;color:#7db3ff;text-decoration:none;" title="Ver pedido ${p.pedido} nos Pedidos de Venda">${p.pedido}</a>${manualBadge}</td>
      <td title="${p.produto}">${p.produto.length>38?p.produto.slice(0,38)+'…':p.produto}</td>
      <td title="${p.cliente}">${p.cliente.slice(0,28)}</td>
      <td>${p.entrega||'—'}</td>
      <td style="text-align:right;font-weight:600;">${fmtNum(p.qtd)}</td>
      <td>${tipoBadge(p.qtd)}</td>
      <td><strong>${p.horas}h</strong></td>
      <td>${realCell}</td>
      <td>${obsSpan}</td>
      <td>${planCell}</td>
      <td>
        <div class="reprog-cell">
          <input type="date"
            class="reprog-date${isManual?' is-manual':''}"
            value="${inputVal}"
            data-pedido="${p.pedido}"
            data-original="${currentDateISO}"
            title="Mover pedido ${p.pedido} para outra data">
          <button class="reprog-clear${clearBtnClass}"
            data-pedido="${p.pedido}"
            title="Restaurar data calculada automaticamente">×</button>
          <span class="reprog-saving" id="rs-${p.pedido}"></span>
        </div>
      </td>
    </tr>`;
  }).join('');

  // Listeners dos inputs recém-renderizados
  tbody.querySelectorAll('.reprog-date').forEach(input=>{
    input.addEventListener('change', ()=>saveReprog(input));
  });
  tbody.querySelectorAll('.reprog-clear').forEach(btn=>{
    btn.addEventListener('click', ()=>clearReprog(btn));
  });
}

// ── Salvar reprogramação ──────────────────────────────────────────────────────
async function saveReprog(input){
  const pedido = input.dataset.pedido;
  const data   = input.value; // 'yyyy-mm-dd'
  const ind    = document.getElementById('rs-'+pedido);

  if(!data){return;}

  if(ind){ind.textContent='…';ind.style.color='var(--text-muted)';}

  try{
    const res  = await fetch(`${BASE_URL}?ajax=set_agenda&pedido=${encodeURIComponent(pedido)}&data=${encodeURIComponent(data)}`);
    const json = await res.json();
    if(!json.ok) throw new Error(json.msg||'Erro ao salvar.');

    // Atualiza override local e re-aplica no dateMap
    overrides[pedido] = data;
    applyOverrideLocal(pedido, data);
    renderDates();
    renderProg();

    if(ind){ind.textContent='✓';ind.style.color='var(--green)';setTimeout(()=>{if(ind)ind.textContent='';},1500);}
  }catch(err){
    if(ind){ind.textContent='✗';ind.style.color='var(--red)';setTimeout(()=>{if(ind)ind.textContent='';},2000);}
    alert('Erro ao reprogramar: '+err.message);
  }
}

// ── Limpar reprogramação (recarrega para recalcular datas automáticas) ────────
async function clearReprog(btn){
  const pedido = btn.dataset.pedido;
  const ind    = document.getElementById('rs-'+pedido);

  if(ind){ind.textContent='…';ind.style.color='var(--text-muted)';}

  try{
    const res  = await fetch(`${BASE_URL}?ajax=clear_agenda&pedido=${encodeURIComponent(pedido)}`);
    const json = await res.json();
    if(!json.ok) throw new Error(json.msg||'Erro ao limpar.');
    // Recarrega a página para recalcular a programação sem o override
    window.location.reload();
  }catch(err){
    if(ind){ind.textContent='✗';ind.style.color='var(--red)';setTimeout(()=>{if(ind)ind.textContent='';},2000);}
    alert('Erro ao restaurar: '+err.message);
  }
}

// ── Aplica/remove override no dateMap em memória sem recarregar a página ─────
function getOriginalDates(pedido){
  // Retorna array de {data: 'dd/mm/yyyy', ...} originais (sem override) para o pedido
  // Varre todas as datas no dateMap
  const all=[];
  Object.values(dateMap).forEach(arr=>arr.forEach(p=>{if(p.pedido===pedido)all.push(p);}));
  return all;
}

function applyOverrideLocal(pedido, dataISO){
  // Remove o pedido de todas as datas
  const allItems = getOriginalDates(pedido);
  if(!allItems.length) return;

  // Data de início atual do pedido (mínima)
  let minDt = null;
  allItems.forEach(p=>{
    const dt=new Date(toISO(p.data));
    if(!minDt||dt<minDt) minDt=dt;
  });

  const targetDt = new Date(dataISO);
  const diffMs   = targetDt - minDt;
  const diffDays = Math.round(diffMs/86400000);

  // Remove de todos os dias
  Object.keys(dateMap).forEach(d=>{
    dateMap[d]=dateMap[d].filter(p=>p.pedido!==pedido);
    if(!dateMap[d].length) delete dateMap[d];
  });

  // Re-insere com offset
  allItems.forEach(p=>{
    const origDt = new Date(toISO(p.data));
    origDt.setDate(origDt.getDate()+diffDays);
    const newDate = origDt.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'});
    const newEntry = Object.assign({},p,{data:newDate,manual:true});
    if(!dateMap[newDate]) dateMap[newDate]=[];
    dateMap[newDate].push(newEntry);
    // Adiciona à lista de datas se nova
    if(!DATES.includes(newDate)){
      DATES.push(newDate);
      DATES.sort((a,b)=>{const[da,ma,aa]=a.split('/');const[db,mb,ab]=b.split('/');return new Date(aa,ma-1,da)-new Date(ab,mb-1,db);});
    }
  });
}

// ── Export CSV ────────────────────────────────────────────────────────────────
document.getElementById('btnExport').addEventListener('click',()=>{
  const items=dateMap[activeDate]||[];
  const cols=['data','maquina','pedido','produto','cliente','entrega','qtd','horas','obs'];
  const hdr=['Data','Máquina','Pedido','Produto','Cliente','Entrega','Qtd.','Horas','Observação'];
  const rows=[hdr,...items.map(p=>cols.map(c=>(p[c]||'').toString().replace(/,/g,' ')))];
  const a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,\uFEFF'+encodeURIComponent(rows.map(r=>r.join(',')).join('\n'));
  a.download='programacao_'+(activeDate||'').replace(/\//g,'-')+'.csv';
  a.click();
});

// ── Render inicial ────────────────────────────────────────────────────────────
renderDates();
renderProg();
</script>
</body>
</html>
