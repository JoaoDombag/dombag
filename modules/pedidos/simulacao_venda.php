<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Agenda de Produção + Simulação de Capacidade
//  Mostra a agenda atual por máquina/dia e quantos produtos cabem
//  em cada slot disponível. Verifica MySQL (local) e PostgreSQL (ERP).
// ══════════════════════════════════════════════════════════════════════════════

// ── AJAX: simular novo pedido ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(simAgenda($_POST));
    exit;
}

// ── Engine: simular encaixe do pedido na fila atual ──────────────────────────
function simAgenda(array $input): array
{
    $tipo         = strtoupper(trim($input['tipo'] ?? ''));
    $impressao    = strtoupper(trim($input['impressao'] ?? 'NAO'));
    $valvulado    = strtoupper(trim($input['valvulado'] ?? 'NAO'));
    $maq_imp      = trim($input['maq_impressao'] ?? '');
    $frente_verso = strtoupper(trim($input['frente_verso'] ?? 'NAO')) === 'SIM';
    $qtd          = max(1.0, (float) str_replace(',', '.', $input['qtd'] ?? '0'));
    $prazo        = trim($input['prazo'] ?? '');

    try {
        $maquinas = pcpGetMaquinas();
        $pedidos  = pcpGetPedidosMySQL();
        $plan     = pcpCalcPlanejamento($pedidos, $maquinas);
    } catch (Throwable $e) {
        return ['erro' => 'Erro ao carregar agenda: ' . $e->getMessage()];
    }

    // Fluxo de máquinas do novo pedido
    $p = [
        'tipo'          => $tipo,
        'impressao'     => $impressao,
        'valvulado'     => $valvulado,
        'maq_impressao' => $maq_imp,
        'produto'       => '',
        'grupo'         => '',
    ];
    $fluxo = pcpGetFluxo($p);

    if (empty($fluxo)) {
        return ['erro' => 'Selecione o tipo de produto.'];
    }

    // Carga acumulada por máquina a partir do plano calculado
    // (dayUsed: [maq|data] => horas já alocadas)
    $dayUsed = [];
    foreach ($plan['prog'] as $pr) {
        $dataISO = DateTime::createFromFormat('d/m/Y', $pr['data'])?->format('Y-m-d') ?? $pr['data'];
        $key = $pr['maquina'] . '|' . $dataISO;
        $dayUsed[$key] = ($dayUsed[$key] ?? 0.0) + (float)($pr['horas'] ?? 0);
    }

    $hoje = date('Y-m-d');

    // Ponteiro de cada máquina: avança até ficar livre considerando carga existente
    $maqSt = [];
    foreach ($fluxo as $mq) {
        $m = $maquinas[$mq] ?? null;
        if (!$m || $m['horas_dia'] <= 0) continue;
        $cap = $m['horas_dia'] * $m['qtd'];

        $dt    = new DateTime($hoje);
        $usado = 0.0;
        // Avança pela carga existente
        $dtMax = new DateTime($hoje);
        $dtMax->modify('+180 days');
        while ($dt <= $dtMax) {
            $k    = $mq . '|' . $dt->format('Y-m-d');
            $hDia = $dayUsed[$k] ?? 0.0;
            if ($hDia < $cap - 0.0001) {
                $usado = $hDia;
                break;
            }
            $dt->modify('+1 day');
        }
        $maqSt[$mq] = ['dt' => $dt, 'usado' => $usado];
    }

    // Agenda o novo pedido com dependência entre etapas
    $etapaAnteriorFim = new DateTime($hoje);
    $etapas = [];
    $hrsTotais = 0.0;

    foreach ($fluxo as $mq) {
        $m = $maquinas[$mq] ?? null;
        if (!$m || $m['horas_dia'] <= 0 || $m['velocidade'] <= 0) continue;

        $cap      = $m['horas_dia'] * $m['qtd'];
        $effQtd   = ($frente_verso && $mq === $maq_imp) ? $qtd * 2.0 : $qtd;
        $horas    = round($effQtd / ($m['velocidade'] * 60.0), 4);
        if ($horas <= 0) continue;

        // Avança ponteiro para respeitar dependência da etapa anterior
        if ($maqSt[$mq]['dt'] < $etapaAnteriorFim) {
            $maqSt[$mq]['dt']   = clone $etapaAnteriorFim;
            $maqSt[$mq]['usado'] = 0.0;
        }

        $st     = &$maqSt[$mq];
        $dtIni  = clone $st['dt'];
        $hLeft  = $horas;
        $disp   = max(0.0, $cap - $st['usado']);

        if ($hLeft <= $disp + 0.0001) {
            $dtFim = clone $st['dt'];
            $st['usado'] += $hLeft;
            if ($st['usado'] >= $cap - 0.0001) {
                $st['usado'] = 0.0;
                $st['dt']->modify('+1 day');
            }
        } else {
            $hLeft -= $disp;
            $st['usado'] = 0.0;
            $st['dt']->modify('+1 day');
            while ($hLeft > $cap - 0.0001) {
                $hLeft -= $cap;
                $st['dt']->modify('+1 day');
            }
            $dtFim = clone $st['dt'];
            $st['usado'] = $hLeft;
            if ($st['usado'] >= $cap - 0.0001) {
                $st['usado'] = 0.0;
                $st['dt']->modify('+1 day');
            }
        }
        unset($st);

        $etapaAnteriorFim = clone $dtFim;
        $hrsTotais += $horas;

        // Capacidade disponível no dia de início desta etapa
        $kIni        = $mq . '|' . $dtIni->format('Y-m-d');
        $hUsadaIni   = $dayUsed[$kIni] ?? 0.0;
        $hDispIni    = max(0.0, $cap - $hUsadaIni);
        $unidDisp    = (int) floor($hDispIni * $m['velocidade'] * 60.0);
        $unidCapDia  = (int) floor($cap * $m['velocidade'] * 60.0);

        $etapas[] = [
            'maquina'         => $mq,
            'horas'           => round($horas, 2),
            'inicio'          => $dtIni->format('d/m/Y'),
            'inicio_iso'      => $dtIni->format('Y-m-d'),
            'termino'         => $dtFim->format('d/m/Y'),
            'termino_iso'     => $dtFim->format('Y-m-d'),
            'cap_dia_unid'    => $unidCapDia,
            'disp_unid'       => $unidDisp,
            'pct_ocupado'     => $cap > 0 ? round(($hUsadaIni / $cap) * 100) : 0,
        ];
    }

    if (empty($etapas)) {
        return ['erro' => 'Nenhuma máquina com velocidade cadastrada para este fluxo.'];
    }

    $dtTermino = $etapaAnteriorFim;
    $primeiraData = $etapas[0]['inicio'];
    $primeiraDataISO = $etapas[0]['inicio_iso'];

    // Capacidade do gargalo na primeira data
    $bottleneck = $etapas[0];
    foreach ($etapas as $et) {
        if ($et['disp_unid'] < $bottleneck['disp_unid']) $bottleneck = $et;
    }

    // Veredicto prazo
    $dtHoje   = new DateTime($hoje);
    $diasFab  = (int) $dtHoje->diff($dtTermino)->format('%r%a');
    $viavel   = 'SEM_PRAZO';
    $msg      = "Produção concluída em $diasFab dia(s).";
    $folgaDias = null;

    if ($prazo !== '') {
        try {
            $dtPrazo  = new DateTime($prazo);
            $folgaDias = (int) $dtTermino->diff($dtPrazo)->format('%r%a');
            if ($dtTermino <= $dtPrazo) {
                $viavel = $folgaDias >= 3 ? 'OK' : 'RISCO';
                $msg = $folgaDias >= 3
                    ? "Viável com $folgaDias dia(s) de folga."
                    : "Apertado — apenas $folgaDias dia(s) de folga.";
            } else {
                $viavel = 'INVIAVEL';
                $msg = "Inviável — término " . abs($folgaDias) . " dia(s) após o prazo.";
            }
        } catch (Throwable) {}
    }

    return [
        'ok'               => true,
        'viavel'           => $viavel,
        'msg'              => $msg,
        'primeira_data'    => $primeiraData,
        'primeira_iso'     => $primeiraDataISO,
        'termino'          => $dtTermino->format('d/m/Y'),
        'termino_iso'      => $dtTermino->format('Y-m-d'),
        'prazo'            => $prazo ? (new DateTime($prazo))->format('d/m/Y') : null,
        'folga_dias'       => $folgaDias,
        'dias_fabricacao'  => $diasFab,
        'hrs_totais'       => round($hrsTotais, 2),
        'qtd'              => $qtd,
        'tipo'             => $tipo,
        'cap_gargalo'      => $bottleneck['disp_unid'],
        'maq_gargalo'      => $bottleneck['maquina'],
        'etapas'           => $etapas,
        'fluxo'            => array_column($etapas, 'maquina'),
    ];
}

// ── Carga da página ───────────────────────────────────────────────────────────
$maquinas  = [];
$plan      = ['pedidos' => [], 'fila' => [], 'prog' => [], 'maquinas' => []];
$erroLoad  = '';
$pgOk      = false;
$mysqlOk   = false;
$fonteMsg  = '';

try {
    $maquinas = pcpGetMaquinas();
    $mysqlOk  = true;
} catch (Throwable $e) {
    $erroLoad = 'Erro ao carregar máquinas: ' . $e->getMessage();
}

// Verifica PostgreSQL
try {
    $pg = pcpGetPG();
    if ($pg) {
        $pgOk = true;
        pg_close($pg);
    }
} catch (Throwable) {}

// Carrega pedidos e plano
try {
    $pedidos = pcpGetPedidosMySQL();
    $plan    = pcpCalcPlanejamento($pedidos, $maquinas);
    $fonteMsg = 'MySQL local' . ($pgOk ? ' + ERP PostgreSQL disponível' : ' (PostgreSQL offline)');
} catch (Throwable $e) {
    $erroLoad = 'Erro ao calcular agenda: ' . $e->getMessage();
    $plan = ['pedidos' => [], 'fila' => [], 'prog' => [], 'maquinas' => []];
}

// ── Monta agenda: [maq][data_iso] = {horas_usadas, cap_dia, unidades_usadas, unidades_livres} ──
$agendaDias = [];
$hoje = date('Y-m-d');
for ($i = 0; $i < 21; $i++) {
    $agendaDias[] = date('Y-m-d', strtotime("+$i days"));
}

// Inicializa todos os slots como vazios
$agendaGrid = [];
foreach (array_keys($maquinas) as $mq) {
    $m   = $maquinas[$mq];
    $cap = $m['horas_dia'] * $m['qtd'];
    foreach ($agendaDias as $d) {
        $agendaGrid[$mq][$d] = [
            'cap_h'       => $cap,
            'usadas_h'    => 0.0,
            'vel'         => $m['velocidade'],
            'unid_cap'    => (int) floor($cap * $m['velocidade'] * 60.0),
            'unid_usadas' => 0,
            'unid_livres' => (int) floor($cap * $m['velocidade'] * 60.0),
            'pct'         => 0,
            'pedidos'     => [],
        ];
    }
}

// Preenche com programação calculada
foreach ($plan['prog'] as $pr) {
    $dtObj = DateTime::createFromFormat('d/m/Y', $pr['data']);
    if (!$dtObj) continue;
    $dISO = $dtObj->format('Y-m-d');
    $mq   = $pr['maquina'];
    if (!isset($agendaGrid[$mq][$dISO])) continue;

    $slot = &$agendaGrid[$mq][$dISO];
    $slot['usadas_h']    += (float)($pr['horas'] ?? 0);
    $hUsadasH             = $slot['usadas_h'];
    $vel                  = $slot['vel'];
    $cap                  = $slot['cap_h'];
    $slot['unid_usadas']  = (int) floor($hUsadasH * $vel * 60.0);
    $slot['unid_livres']  = (int) max(0, floor(($cap - $hUsadasH) * $vel * 60.0));
    $slot['pct']          = $cap > 0 ? (int) min(100, round(($hUsadasH / $cap) * 100)) : 0;
    $slot['pedidos'][]    = $pr['pedido'] ?? '';
    unset($slot);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agenda de Produção | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ── Layout ────────────────────────────────────────────────── */
.page-cols{display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start;}
@media(max-width:960px){.page-cols{grid-template-columns:1fr;}}

/* ── Painel form ────────────────────────────────────────────── */
.form-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;position:sticky;top:20px;}
.form-panel-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.form-panel-head h2{font-size:14px;font-weight:700;}
.form-panel-head p{font-size:11px;color:var(--text-muted);margin-top:1px;}
.form-body{padding:18px 20px;display:flex;flex-direction:column;gap:14px;}

/* Segmented control tipo */
.seg{display:flex;border:1px solid var(--border);border-radius:8px;overflow:hidden;}
.seg-btn{flex:1;padding:9px;background:transparent;border:none;color:var(--text-secondary);font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;}
.seg-btn.on{background:var(--primary,#2d6aff);color:#fff;}
.seg-sep{width:1px;background:var(--border);}

/* Toggle */
.tog-row{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:9px 14px;}
.tog-row span{font-size:13px;}
.tog{position:relative;width:38px;height:20px;}
.tog input{opacity:0;width:0;height:0;}
.tog-sl{position:absolute;inset:0;background:rgba(255,255,255,.1);border-radius:20px;cursor:pointer;transition:.2s;}
.tog-sl::before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;background:var(--text-muted);border-radius:50%;transition:.2s;}
.tog input:checked+.tog-sl{background:var(--primary,#2d6aff);}
.tog input:checked+.tog-sl::before{transform:translateX(18px);background:#fff;}

.btn-consultar{width:100%;padding:12px;background:var(--primary,#2d6aff);border:none;border-radius:8px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:opacity .15s;}
.btn-consultar:hover{opacity:.88;}
.btn-consultar.loading{opacity:.6;pointer-events:none;}
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}

/* ── Resultado ─────────────────────────────────────────────── */
.result-card{border-radius:var(--radius);padding:20px 22px;margin-bottom:18px;border:1px solid;}
.result-card.ok{background:rgba(0,201,167,.07);border-color:rgba(0,201,167,.3);}
.result-card.risco{background:rgba(245,158,11,.07);border-color:rgba(245,158,11,.3);}
.result-card.inviavel{background:rgba(239,68,68,.07);border-color:rgba(239,68,68,.3);}
.result-card.sem_prazo{background:rgba(45,106,255,.07);border-color:rgba(45,106,255,.2);}
.result-title{font-size:17px;font-weight:800;margin-bottom:4px;}
.result-msg{font-size:13px;color:var(--text-secondary);margin-bottom:16px;}

.dates-row{display:flex;flex-wrap:wrap;gap:20px;margin-bottom:18px;}
.date-block{display:flex;flex-direction:column;gap:3px;}
.date-lbl{font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text-muted);}
.date-val{font-size:20px;font-weight:800;font-family:ui-monospace,monospace;letter-spacing:-.5px;}
.date-sub{font-size:11px;color:var(--text-muted);}

.cap-box{display:flex;align-items:center;gap:10px;padding:12px 16px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;margin-bottom:16px;}
.cap-num{font-size:24px;font-weight:800;font-family:ui-monospace,monospace;}
.cap-lbl{font-size:12px;color:var(--text-secondary);line-height:1.4;}

/* Etapas timeline */
.etapas-list{display:flex;flex-direction:column;gap:0;}
.etapa-row{display:grid;grid-template-columns:180px 1fr 1fr auto;gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--border);font-size:12.5px;}
.etapa-row:last-child{border-bottom:none;}
.etapa-maq{font-weight:700;}
.etapa-datas{color:var(--text-secondary);}
.etapa-cap{font-size:11px;color:var(--text-muted);}
.ocu-bar{height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;width:80px;}
.ocu-fill{height:100%;border-radius:3px;}

/* ── Agenda grid ───────────────────────────────────────────── */
.agenda-wrap{-webkit-overflow-scrolling:touch;}
.agenda-table{border-collapse:collapse;font-size:11.5px;min-width:100%;}
.agenda-table th{padding:8px 10px;text-align:center;border-bottom:2px solid var(--border);background:var(--card-bg);white-space:nowrap;position:sticky;top:0;z-index:2;}
.agenda-table th.maq-head{text-align:left;min-width:160px;position:sticky;left:0;z-index:3;background:var(--card-bg);}
.agenda-table th.today{background:rgba(45,106,255,.08);}
.agenda-table td{padding:0;border-right:1px solid var(--border);vertical-align:middle;}
.agenda-table td.maq-cell{padding:10px 14px;font-weight:700;font-size:12px;white-space:nowrap;border-right:2px solid var(--border);position:sticky;left:0;background:var(--card-bg);z-index:1;}
.agenda-table tr:not(:last-child) td{border-bottom:1px solid var(--border);}

.slot{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:52px;height:56px;padding:4px 6px;gap:3px;cursor:default;transition:background .12s;position:relative;}
.slot:hover{background:rgba(255,255,255,.06);}
.slot-bar{width:32px;height:5px;border-radius:3px;background:rgba(255,255,255,.06);overflow:hidden;}
.slot-fill{height:100%;border-radius:3px;}
.slot-num{font-size:10px;font-family:ui-monospace,monospace;font-weight:700;}
.slot-lbl{font-size:9px;color:var(--text-muted);}

/* Cores slot */
.c0{background-color:rgba(0,201,167,.55);}   /* livre */
.c1{background-color:rgba(245,158,11,.6);}   /* médio */
.c2{background-color:rgba(239,68,68,.6);}    /* cheio */
.c3{background-color:rgba(107,114,128,.4);}  /* zero/sem vel */

/* Highlight etapa simulada */
.slot.sim-range{outline:2px solid rgba(45,106,255,.7);outline-offset:-1px;background:rgba(45,106,255,.1);}
.slot.sim-start{outline:2px solid rgba(45,106,255,1);background:rgba(45,106,255,.15);}

/* Cabeçalho dia */
.day-hd{display:flex;flex-direction:column;align-items:center;gap:1px;}
.day-dow{font-size:9px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--text-muted);}
.day-num{font-size:13px;font-weight:700;}
.day-mon{font-size:9px;color:var(--text-muted);}

.fonte-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;}
.fonte-ok{background:rgba(0,201,167,.08);color:var(--teal,#06d6a0);border:1px solid rgba(0,201,167,.2);}
.fonte-warn{background:rgba(245,158,11,.08);color:var(--amber,#fbbf24);border:1px solid rgba(245,158,11,.2);}

.legenda{display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:11px;color:var(--text-muted);padding:10px 0 4px;}
.leg-dot{width:10px;height:10px;border-radius:3px;flex-shrink:0;}

/* Tooltip */
.slot[title]{cursor:help;}
@media(max-width:768px){.page-cols{grid-template-columns:1fr;}.form-panel{position:static;}.etapa-row{grid-template-columns:1fr 1fr;gap:8px;}.agenda-wrap{overflow-x:auto;}}
@media(max-width:480px){.etapa-row{grid-template-columns:1fr;}.dates-row{flex-direction:column;gap:10px;}}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">

<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Agenda de Produção</h1>
      <p>Ocupação atual por máquina e capacidade disponível para novos pedidos</p>
    </div>
  </div>
  <div class="topbar-actions">
    <span class="fonte-badge <?= $pgOk ? 'fonte-ok' : 'fonte-warn' ?>">
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($fonteMsg) ?>
    </span>
  </div>
</header>

<div class="content">

  <?php if ($erroLoad): ?>
    <div class="alert alert-err"><?= htmlspecialchars($erroLoad) ?></div>
  <?php endif; ?>

  <div class="page-cols">

    <!-- ── Formulário ── -->
    <div class="form-panel">
      <div class="form-panel-head">
        <div>
          <h2>Simular novo pedido</h2>
          <p>Informe o produto e a quantidade para ver onde encaixa</p>
        </div>
      </div>
      <div class="form-body">

        <div class="field">
          <label>Tipo de produto</label>
          <div class="seg">
            <button type="button" class="seg-btn on" id="btnBag" onclick="setTipo('BAG')">Big Bag</button>
            <div class="seg-sep"></div>
            <button type="button" class="seg-btn" id="btnSac" onclick="setTipo('SACARIA')">Sacaria</button>
          </div>
          <input type="hidden" id="f_tipo" value="BAG">
        </div>

        <div class="field">
          <label>Quantidade *</label>
          <input type="number" id="f_qtd" class="field-input" min="1" step="1" placeholder="Ex: 500">
        </div>

        <div class="field">
          <label>Prazo desejado (opcional)</label>
          <input type="date" id="f_prazo" class="field-input" min="<?= date('Y-m-d') ?>">
        </div>

        <div class="tog-row">
          <span>Com impressão?</span>
          <label class="tog">
            <input type="checkbox" id="f_imp" onchange="toggleImp()">
            <span class="tog-sl"></span>
          </label>
        </div>

        <div id="rowMaqImp" style="display:none;">
          <div class="field">
            <label>Máquina de impressão</label>
            <select id="f_maq_imp" class="field-input" onchange="toggleFrenteVerso()">
              <option value="">— Selecione —</option>
            </select>
          </div>
        </div>

        <div id="rowFrenteVerso" style="display:none;">
          <div class="tog-row">
            <span>Frente e verso?</span>
            <label class="tog">
              <input type="checkbox" id="f_frente_verso">
              <span class="tog-sl"></span>
            </label>
          </div>
        </div>

        <div id="rowValv" style="display:none;">
          <div class="tog-row">
            <span>Valvulado?</span>
            <label class="tog">
              <input type="checkbox" id="f_valv">
              <span class="tog-sl"></span>
            </label>
          </div>
        </div>

        <button type="button" class="btn-consultar" id="btnConsultar" onclick="consultar()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
          <span id="btnTxt">Ver disponibilidade</span>
        </button>

      </div>
    </div>

    <!-- ── Coluna direita ── -->
    <div>

      <!-- Resultado simulação -->
      <div id="resultArea"></div>

      <!-- Agenda grid -->
      <div class="panel-table">
        <div class="panel-header">
          <span class="panel-title">Agenda — próximos 21 dias por máquina</span>
          <div class="legenda">
            <span class="leg-dot" style="background:rgba(0,201,167,.55);"></span>Disponível
            <span class="leg-dot" style="background:rgba(245,158,11,.6);"></span>Ocupação média
            <span class="leg-dot" style="background:rgba(239,68,68,.6);"></span>Quase cheio
            <span class="leg-dot" style="background:rgba(107,114,128,.4);"></span>Sem velocidade
          </div>
        </div>

        <div class="agenda-wrap table-wrap" style="padding:0 0 8px;">
          <table class="agenda-table" id="agendaTable">
            <thead>
              <tr>
                <th class="maq-head">Máquina</th>
                <?php foreach ($agendaDias as $d):
                  $dow = ['D','S','T','Q','Q','S','S'][(int)date('w', strtotime($d))];
                  $isToday = $d === $hoje;
                ?>
                  <th <?= $isToday ? 'class="today"' : '' ?>>
                    <div class="day-hd">
                      <span class="day-dow"><?= $dow ?></span>
                      <span class="day-num"><?= date('d', strtotime($d)) ?></span>
                      <span class="day-mon"><?= date('M', strtotime($d)) ?></span>
                    </div>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($agendaGrid as $mq => $dias): ?>
              <tr>
                <td class="maq-cell"><?= htmlspecialchars($mq) ?></td>
                <?php foreach ($agendaDias as $d):
                  $sl = $dias[$d] ?? null;
                  if (!$sl) { echo '<td><div class="slot"></div></td>'; continue; }
                  $pct = $sl['pct'];
                  $semVel = $sl['vel'] <= 0;
                  $colorClass = $semVel ? 'c3' : ($pct >= 90 ? 'c2' : ($pct >= 50 ? 'c1' : 'c0'));
                  $livres = number_format($sl['unid_livres']);
                  $usadas = number_format($sl['unid_usadas']);
                  $capDia = number_format($sl['unid_cap']);
                  $hUsadas = round($sl['usadas_h'], 1);
                  $hCap = round($sl['cap_h'], 1);
                  $peds = !empty($sl['pedidos']) ? implode(', ', array_unique(array_filter($sl['pedidos']))) : '—';
                  $ttip = "{$mq} | {$d}\nOcupação: {$pct}%\nHoras: {$hUsadas}h / {$hCap}h\nUn. usadas: {$usadas}\nUn. livres: {$livres}\nPedidos: {$peds}";
                  $isToday = $d === $hoje;
                ?>
                  <td>
                    <div class="slot"
                         data-maq="<?= htmlspecialchars($mq) ?>"
                         data-d="<?= $d ?>"
                         data-pct="<?= $pct ?>"
                         data-livres="<?= $sl['unid_livres'] ?>"
                         title="<?= htmlspecialchars($ttip) ?>"
                         style="<?= $isToday ? 'background:rgba(45,106,255,.06);' : '' ?>">
                      <div class="slot-bar"><div class="slot-fill <?= $colorClass ?>" style="width:<?= $pct ?>%;"></div></div>
                      <?php if (!$semVel): ?>
                        <span class="slot-num" style="color:<?= $pct >= 90 ? '#f87171' : ($pct >= 50 ? '#fbbf24' : '#06d6a0') ?>">
                          <?= number_format($sl['unid_livres']) ?>
                        </span>
                        <span class="slot-lbl">livres</span>
                      <?php else: ?>
                        <span class="slot-lbl">s/ vel.</span>
                      <?php endif; ?>
                    </div>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div style="padding:10px 16px;border-top:1px solid var(--border);font-size:11px;color:var(--text-muted);">
          <?= count($plan['pedidos']) ?> pedido(s) em produção · <?= count($maquinas) ?> grupo(s) de máquina
          <?php if (!empty($plan['pedidos'])): ?>
            · Dados: <?= htmlspecialchars($fonteMsg) ?>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /col direita -->
  </div><!-- /page-cols -->
</div><!-- /content -->
</div>
</div>

<script>
let tipoAtual = 'BAG';

const VCOLORS = {
  OK:        { cls:'ok',       icon:'✓', label:'Disponível',              color:'#06d6a0' },
  RISCO:     { cls:'risco',    icon:'⚠', label:'Disponível com atenção',  color:'#fbbf24' },
  INVIAVEL:  { cls:'inviavel', icon:'✗', label:'Prazo indisponível',       color:'#f87171' },
  SEM_PRAZO: { cls:'sem_prazo',icon:'ℹ', label:'Próxima disponibilidade', color:'#3b82f6' },
};

function setTipo(t) {
  tipoAtual = t;
  document.getElementById('btnBag').classList.toggle('on', t === 'BAG');
  document.getElementById('btnSac').classList.toggle('on', t === 'SACARIA');
  document.getElementById('f_tipo').value = t;
  document.getElementById('rowValv').style.display = t === 'SACARIA' ? 'block' : 'none';
  atualizarMaqImp();
}

function atualizarMaqImp() {
  const sel = document.getElementById('f_maq_imp');
  sel.innerHTML = '<option value="">— Selecione —</option>';
  if (tipoAtual === 'BAG') {
    sel.innerHTML += '<option value="Impressao Bag">Impressão Bag</option>';
  } else {
    sel.innerHTML += '<option value="Flexo">Flexo</option>';
    sel.innerHTML += '<option value="Carimbadeira Sacaria">Carimbadeira Sacaria</option>';
  }
}

function toggleImp() {
  const on = document.getElementById('f_imp').checked;
  document.getElementById('rowMaqImp').style.display = on ? 'block' : 'none';
  if (!on) document.getElementById('rowFrenteVerso').style.display = 'none';
  toggleFrenteVerso();
}

function toggleFrenteVerso() {
  const imp = document.getElementById('f_imp').checked;
  const maq = document.getElementById('f_maq_imp').value;
  const show = imp && maq === 'Impressao Bag';
  document.getElementById('rowFrenteVerso').style.display = show ? 'block' : 'none';
  if (!show) document.getElementById('f_frente_verso').checked = false;
}

atualizarMaqImp();

async function consultar() {
  const qtd   = parseFloat(document.getElementById('f_qtd').value);
  const tipo  = document.getElementById('f_tipo').value;
  const prazo = document.getElementById('f_prazo').value;
  const imp   = document.getElementById('f_imp').checked;
  const maqI  = document.getElementById('f_maq_imp').value;
  const valv  = document.getElementById('f_valv')?.checked || false;

  if (!qtd || qtd < 1) { document.getElementById('f_qtd').focus(); return; }
  if (imp && !maqI) { alert('Selecione a máquina de impressão.'); return; }

  const btn = document.getElementById('btnConsultar');
  btn.classList.add('loading');
  document.getElementById('btnTxt').textContent = 'Calculando…';

  limparHighlight();

  const fd = new FormData();
  fd.append('ajax','1');
  fd.append('tipo', tipo);
  fd.append('qtd', qtd);
  fd.append('prazo', prazo);
  fd.append('impressao', imp ? 'SIM' : 'NAO');
  fd.append('maq_impressao', imp ? maqI : '');
  fd.append('valvulado', valv ? 'SIM' : 'NAO');
  fd.append('frente_verso', (document.getElementById('f_frente_verso')?.checked && maqI === 'Impressao Bag') ? 'SIM' : 'NAO');

  try {
    const res  = await fetch(window.location.href, { method:'POST', body:fd });
    const data = await res.json();
    renderResultado(data);
  } catch(e) {
    document.getElementById('resultArea').innerHTML =
      `<div class="alert alert-err">Erro de comunicação: ${e.message}</div>`;
  }

  btn.classList.remove('loading');
  document.getElementById('btnTxt').textContent = 'Ver disponibilidade';
}

function renderResultado(d) {
  if (d.erro) {
    document.getElementById('resultArea').innerHTML =
      `<div class="alert alert-err">${d.erro}</div>`;
    return;
  }

  const v      = VCOLORS[d.viavel] || VCOLORS.SEM_PRAZO;
  const qtdFmt = d.qtd.toLocaleString('pt-BR');
  const capFmt = (d.cap_gargalo || 0).toLocaleString('pt-BR');
  const cabe   = d.cap_gargalo >= d.qtd;
  const capMsg = cabe
    ? `${capFmt} unidades disponíveis — pedido cabe em 1 dia nesta máquina`
    : `${capFmt} unidades disponíveis na data de início — ${qtdFmt} precisam de mais dias`;

  // Etapas
  let etapasHTML = `
    <div style="border-top:1px solid var(--border);margin-top:16px;padding-top:14px;">
      <div style="font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;">Etapas do agendamento</div>
      <div class="etapas-list">`;

  (d.etapas||[]).forEach(et => {
    const cor = et.pct_ocupado >= 90 ? '#f87171' : (et.pct_ocupado >= 50 ? '#fbbf24' : '#06d6a0');
    etapasHTML += `
      <div class="etapa-row">
        <span class="etapa-maq">${et.maquina}</span>
        <span class="etapa-datas">${et.inicio} → ${et.termino} · ${et.horas}h</span>
        <span class="etapa-cap">
          ${et.disp_unid.toLocaleString('pt-BR')} livres de ${et.cap_dia_unid.toLocaleString('pt-BR')} un/dia
        </span>
        <div class="ocu-bar"><div class="ocu-fill" style="width:${et.pct_ocupado}%;background:${cor};"></div></div>
      </div>`;
  });
  etapasHTML += `</div></div>`;

  document.getElementById('resultArea').innerHTML = `
    <div class="result-card ${v.cls}" style="margin-bottom:18px;">
      <div class="result-title" style="color:${v.color};">${v.icon} ${v.label}</div>
      <div class="result-msg">${d.msg}</div>

      <div class="dates-row">
        <div class="date-block">
          <span class="date-lbl">Início da produção</span>
          <span class="date-val" style="color:${v.color};">${d.primeira_data}</span>
        </div>
        <div class="date-block" style="color:var(--text-muted);font-size:20px;padding-top:18px;">→</div>
        <div class="date-block">
          <span class="date-lbl">Término previsto</span>
          <span class="date-val" style="color:${v.color};">${d.termino}</span>
          <span class="date-sub">${d.dias_fabricacao} dia(s) · ${d.hrs_totais}h</span>
        </div>
        ${d.prazo ? `
        <div class="date-block" style="color:var(--text-muted);font-size:14px;padding-top:22px;">vs</div>
        <div class="date-block">
          <span class="date-lbl">Prazo solicitado</span>
          <span class="date-val">${d.prazo}</span>
          <span class="date-sub">${d.folga_dias >= 0 ? d.folga_dias+' dia(s) folga' : Math.abs(d.folga_dias)+' dia(s) atraso'}</span>
        </div>` : ''}
      </div>

      <div class="cap-box">
        <span class="cap-num" style="color:${cabe ? '#06d6a0' : '#fbbf24'};">${capFmt}</span>
        <div class="cap-lbl">
          unidades disponíveis na máquina <strong>${d.maq_gargalo}</strong> no dia ${d.primeira_data}<br>
          <span style="color:var(--text-muted);">${capMsg}</span>
        </div>
      </div>

      ${etapasHTML}
    </div>`;

  // Destaca dias na agenda
  destacarAgenda(d.etapas || []);

  // Scroll para resultado
  document.getElementById('resultArea').scrollIntoView({ behavior:'smooth', block:'nearest' });
}

function destacarAgenda(etapas) {
  limparHighlight();
  etapas.forEach(et => {
    const maq     = et.maquina;
    const dIni    = et.inicio_iso;
    const dFim    = et.termino_iso;
    const slots   = document.querySelectorAll(`.slot[data-maq="${CSS.escape(maq)}"]`);
    slots.forEach(sl => {
      const d = sl.dataset.d;
      if (d >= dIni && d <= dFim) {
        sl.classList.add(d === dIni ? 'sim-start' : 'sim-range');
      }
    });
  });
}

function limparHighlight() {
  document.querySelectorAll('.slot.sim-range,.slot.sim-start').forEach(el => {
    el.classList.remove('sim-range','sim-start');
  });
}

// Enter para simular
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.target.matches('select,input[type="date"]')) consultar();
});
</script>
</body>
</html>
