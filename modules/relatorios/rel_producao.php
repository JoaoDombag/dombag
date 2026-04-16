<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════
//  DOMBAG — Produção (dados reais + cards por máquina)
// ══════════════════════════════════════════════════

// Data selecionada (padrão = hoje)
$data_sel = (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data']))
    ? $_GET['data']
    : date('Y-m-d');
$data_sel_fmt = date('d/m/Y', strtotime($data_sel));

$db_error = '';
$kpis_hoje = ['total_und' => 0, 'apontamentos' => 0, 'maquinas_ativas' => 0, 'funcionarios' => 0];
$grafico_diario = [];
$grafico_mensal = [];
$relatorio_maq = [];
$todas_maquinas = [];
$horas_labels = [];
$maquinas_labels = [];
$por_maquina_hora = []; // [maq_codigo => [hora => qtd]]

try {
    $pdo = dbPDO();

    // ── 1. KPIs de hoje ─────────────────────────
    // total_und conta apenas máquinas com maq_conta_producao=1 (evita duplicidade)
    $row = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN COALESCE(m.maq_conta_producao,1)=1 THEN pd.pd_quantidade ELSE 0 END), 0) AS total_und,
            COUNT(*)                                  AS apontamentos,
            COUNT(DISTINCT pd.maq_codigo)             AS maquinas_ativas,
            COUNT(DISTINCT pd.pd_funcionario)         AS funcionarios
        FROM producao_diaria pd
        LEFT JOIN maquinas m ON m.maq_codigo = pd.maq_codigo
        WHERE pd.pd_data = '$data_sel'
    ")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $kpis_hoje = $row;
    }

    // ── 2. Gráfico diário (global por hora/máquina) ──
    $rows = $pdo->query("
        SELECT
            DATE_FORMAT(pd_horario_ini, '%H:%i')  AS hora,
            pd.maq_codigo,
            maq.maq_descricao,
            SUM(pd_quantidade)                    AS qtd
        FROM producao_diaria pd
        LEFT JOIN maquinas maq ON maq.maq_codigo = pd.maq_codigo
        WHERE pd_data = '$data_sel'
        GROUP BY DATE_FORMAT(pd_horario_ini, '%H:%i'), pd.maq_codigo
        ORDER BY hora, pd.maq_codigo
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $horas_labels[$r['hora']] = true;
        $maquinas_labels[$r['maq_descricao']] = true;
        $grafico_diario[$r['hora']][$r['maq_descricao']] = (float) $r['qtd'];
        // também guarda por máquina separado
        $por_maquina_hora[$r['maq_codigo']][$r['hora']] = (float) $r['qtd'];
    }
    $horas_labels = array_keys($horas_labels);
    $maquinas_labels = array_keys($maquinas_labels);

    // ── 3. Gráfico mensal ────────────────────────
    $rows = $pdo->query("
        SELECT pd_data, SUM(CASE WHEN COALESCE(m.maq_conta_producao,1)=1 THEN pd.pd_quantidade ELSE 0 END) AS qtd
        FROM producao_diaria pd
        LEFT JOIN maquinas m ON m.maq_codigo = pd.maq_codigo
        WHERE YEAR(pd_data) = YEAR('$data_sel')
          AND MONTH(pd_data) = MONTH('$data_sel')
        GROUP BY pd_data
        ORDER BY pd_data
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $grafico_mensal[$r['pd_data']] = (float) $r['qtd'];
    }

    // ── 4. Relatório por máquina (hoje) ──────────
    $relatorio_maq = $pdo->query("
        SELECT
            pd.maq_codigo,
            m.maq_descricao,
            d.dp_descricao,
            COALESCE(m.maq_qtde, 1)                                          AS qtde_maquinas,
            COALESCE(m.maq_horas_dia, 8)                                     AS horas_dia,
            COALESCE(m.maq_producao_min, 0)                                  AS prod_min_cadastro,
            SUM(pd.pd_quantidade)                                            AS total_und,
            COUNT(*)                                                         AS apontamentos,
            ROUND(
                SUM(pd.pd_quantidade) /
                NULLIF(SUM(TIMESTAMPDIFF(MINUTE,
                    CONCAT('2000-01-01 ', pd.pd_horario_ini),
                    CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini, '2000-01-02', '2000-01-01'), ' ', pd.pd_horario_fim)
                )), 0)
            , 4)                                                             AS prod_min_real,
            MIN(DATE_FORMAT(pd.pd_horario_ini, '%H:%i'))                     AS hora_inicio,
            MAX(DATE_FORMAT(pd.pd_horario_fim, '%H:%i'))                     AS hora_fim,
            GROUP_CONCAT(DISTINCT pd.pd_funcionario
                ORDER BY pd.pd_funcionario SEPARATOR ', ')                   AS funcionarios,
            GROUP_CONCAT(DISTINCT pd.pd_pedido
                ORDER BY pd.pd_pedido SEPARATOR ', ')                        AS pedidos,
            SUM(TIMESTAMPDIFF(MINUTE,
                CONCAT('2000-01-01 ', pd.pd_horario_ini),
                CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini, '2000-01-02', '2000-01-01'), ' ', pd.pd_horario_fim)
            ))                                                               AS minutos_trabalhados
        FROM producao_diaria pd
        LEFT JOIN maquinas m ON m.maq_codigo = pd.maq_codigo
        LEFT JOIN departamentos d ON d.dp_codigo = m.dp_codigo
        WHERE pd.pd_data = '$data_sel'
        GROUP BY pd.maq_codigo, m.maq_descricao, d.dp_descricao,
                 m.maq_qtde, m.maq_horas_dia, m.maq_producao_min
        ORDER BY total_und DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Adiciona dados de horas por hora para cada máquina
    foreach ($relatorio_maq as &$maq) {
        $cod = $maq['maq_codigo'];
        $maq['horas_por_hora'] = $por_maquina_hora[$cod] ?? [];
        // capacidade diária em unidades
        $cap_und = (float) $maq['qtde_maquinas'] * (float) $maq['prod_min_cadastro'] * 60.0 * (float) $maq['horas_dia'];
        $maq['cap_dia_und'] = $cap_und;
        $maq['pct_cap'] = $cap_und > 0
            ? min(100, round((float) $maq['total_und'] / $cap_und * 100, 1))
            : null;
        // esperado pelo tempo efetivamente trabalhado
        $maq['esp_tempo'] = (float) $maq['prod_min_cadastro'] * (float) $maq['minutos_trabalhados'];
    }
    unset($maq);

    // KPI globais: meta do dia e eficiência
    $total_meta_dia = array_sum(array_column($relatorio_maq, 'cap_dia_und'));

    // ── 5. Cadastro completo de máquinas ─────────
    $todas_maquinas = $pdo->query('
        SELECT maq_descricao, maq_qtde, maq_producao_min, maq_horas_dia,
               ROUND(maq_qtde * maq_producao_min * 60 * maq_horas_dia, 0) AS cap_dia
        FROM maquinas ORDER BY maq_descricao
    ')->fetchAll(PDO::FETCH_ASSOC);

    // ── 6. Pedidos finalizados no dia ─────────────
    $pedidos_finalizados = $pdo->query("
        SELECT
            v.ven_codigo_yzidro                                               AS pedido,
            COALESCE(v.ven_fantasia, v.ven_cliente)                           AS cliente,
            v.ven_entrega,
            GROUP_CONCAT(DISTINCT p.pro_descricao
                ORDER BY p.pro_descricao SEPARATOR ' / ')                     AS produtos,
            SUM(iv.iv_qtde)                                                   AS total_qtde,
            COUNT(iv.iv_codigo)                                               AS total_itens
        FROM itens_vendas iv
        JOIN vendas v  ON v.ven_codigo  = iv.ven_codigo
        JOIN produtos p ON p.pro_codigo = iv.pro_codigo
        WHERE iv.iv_status = 'Finalizado'
          AND DATE(iv.iv_atualizado_em) = '$data_sel'
        GROUP BY v.ven_codigo, v.ven_codigo_yzidro, v.ven_cliente, v.ven_fantasia, v.ven_entrega
        ORDER BY v.ven_codigo_yzidro
    ")->fetchAll(PDO::FETCH_ASSOC);

    $total_finalizados = array_sum(array_column($pedidos_finalizados, 'total_qtde'));

    // ── 7. Pedidos finalizados no mês ─────────────
    $pedidos_finalizados_mes = $pdo->query("
        SELECT
            v.ven_codigo_yzidro                                               AS pedido,
            COALESCE(v.ven_fantasia, v.ven_cliente)                           AS cliente,
            v.ven_entrega,
            DATE(iv.iv_atualizado_em)                                         AS data_finalizacao,
            GROUP_CONCAT(DISTINCT p.pro_descricao
                ORDER BY p.pro_descricao SEPARATOR ' / ')                     AS produtos,
            SUM(iv.iv_qtde)                                                   AS total_qtde,
            COUNT(iv.iv_codigo)                                               AS total_itens
        FROM itens_vendas iv
        JOIN vendas v  ON v.ven_codigo  = iv.ven_codigo
        JOIN produtos p ON p.pro_codigo = iv.pro_codigo
        WHERE iv.iv_status = 'Finalizado'
          AND YEAR(iv.iv_atualizado_em)  = YEAR('$data_sel')
          AND MONTH(iv.iv_atualizado_em) = MONTH('$data_sel')
        GROUP BY v.ven_codigo, v.ven_codigo_yzidro, v.ven_cliente, v.ven_fantasia,
                 v.ven_entrega, DATE(iv.iv_atualizado_em)
        ORDER BY data_finalizacao DESC, v.ven_codigo_yzidro
    ")->fetchAll(PDO::FETCH_ASSOC);

    $total_finalizados_mes = array_sum(array_column($pedidos_finalizados_mes, 'total_qtde'));

} catch (PDOException $e) {
    $db_error = 'Erro no banco de dados: ' . $e->getMessage();
}

function jz($v)
{
    return json_encode($v, JSON_UNESCAPED_UNICODE);
}

$js_kpis = jz($kpis_hoje);
$js_diario = jz($grafico_diario);
$js_horas = jz($horas_labels);
$js_maquinas = jz($maquinas_labels);
$js_mensal = jz($grafico_mensal);
$js_relatorio = jz($relatorio_maq);
$js_todas_maq  = jz($todas_maquinas);
$js_total_meta = json_encode((float) $total_meta_dia);
$js_ped_fin       = jz($pedidos_finalizados);
$js_total_fin     = json_encode((float) $total_finalizados);
$js_ped_fin_mes   = jz($pedidos_finalizados_mes);
$js_total_fin_mes = json_encode((float) $total_finalizados_mes);
$js_data_hoje = json_encode($data_sel_fmt);
$js_data_sel = json_encode($data_sel);
$js_mes_label = json_encode(date('m/Y'));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Produção | DOMBAG</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}

/* Topbar */
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
.toggle-btn{width:36px;height:36px;min-width:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s;}
.toggle-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;white-space:nowrap;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;white-space:nowrap;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.btn-primary{background:var(--blue-accent);color:white;border:none;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:background .15s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.btn-primary:hover{background:var(--blue-light);}
.btn-teal{background:rgba(0,201,167,.15);color:var(--teal);border:1px solid rgba(0,201,167,.3);padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:6px;}
.btn-teal:hover{background:rgba(0,201,167,.25);}
.icon-btn{width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s;}
.icon-btn:hover{background:var(--card-hover);color:var(--text-primary);}

/* Content */
.content{flex:1;overflow:hidden;padding:24px;display:flex;flex-direction:column;}

/* Alert */
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);border-radius:8px;padding:10px 16px;font-size:12.5px;display:flex;align-items:center;gap:8px;margin-bottom:18px;}

/* ── Abas ── */
.tabs-bar{display:flex;align-items:center;gap:4px;margin-bottom:0;border-bottom:1px solid var(--border);padding-bottom:0;flex-shrink:0;}
.tab-btn{padding:8px 18px;border:none;background:transparent;color:var(--text-muted);font-family:'Segoe UI',sans-serif;font-size:13px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s,border-color .15s;}
.tab-btn:hover{color:var(--text-primary);}
.tab-btn.active{color:#7db3ff;border-bottom-color:var(--blue-light);font-weight:600;}
.tab-content{display:none;}
.tab-content.active{display:flex;flex-direction:column;flex:1;min-height:0;overflow-y:auto;padding-top:22px;}

/* ── Gráficos globais ── */
.charts-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:0;}
.chart-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.chart-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.chart-title{font-size:13px;font-weight:600;}
.chart-sub{font-size:11px;color:var(--text-muted);}
.chart-body{padding:16px 16px 12px;}
.chart-empty{text-align:center;padding:48px 20px;color:var(--text-muted);font-size:12.5px;opacity:.6;}
.chart-export-btn{background:transparent;border:1px solid var(--border);color:var(--text-muted);padding:4px 10px;border-radius:6px;font-size:11px;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px;}
.chart-export-btn:hover{background:var(--card-hover);color:var(--text-primary);}

/* ── Cards de máquina ── */
.maq-cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:18px;}
.maq-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border-color .2s,transform .2s;}
.maq-card:hover{border-color:rgba(45,106,255,.25);transform:translateY(-2px);}

/* Card header */
.maq-card-head{padding:14px 18px 12px;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;}
.maq-card-name{font-size:14px;font-weight:700;line-height:1.2;}
.maq-card-meta{display:flex;align-items:center;gap:6px;margin-top:4px;flex-wrap:wrap;}
.badge-depto{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:rgba(167,139,250,.12);color:var(--purple);}
.badge-qtde{font-size:10px;font-weight:600;color:var(--text-muted);}
.badge-horario{font-size:10.5px;font-weight:600;color:var(--text-muted);display:inline-flex;align-items:center;gap:3px;}
.perf-pill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;}
.perf-ok{background:rgba(0,201,167,.12);color:var(--teal);}
.perf-low{background:rgba(239,68,68,.1);color:var(--red);}
.perf-na{background:rgba(255,255,255,.06);color:var(--text-muted);}

/* Card stats row */
.maq-card-stats{display:grid;grid-template-columns:repeat(5,1fr);border-top:1px solid var(--border);border-bottom:1px solid var(--border);}
.card-stat.highlight{background:rgba(0,201,167,.04);}
.card-stat{padding:11px 14px;border-right:1px solid var(--border);text-align:center;}
.card-stat:last-child{border-right:none;}
.card-stat-val{font-size:16px;font-weight:700;letter-spacing:-.5px;line-height:1;}
.card-stat-lbl{font-size:9.5px;color:var(--text-muted);margin-top:3px;text-transform:uppercase;letter-spacing:.5px;}

/* Capacidade gauge */
.maq-card-cap{padding:12px 18px 10px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);}
.cap-label{font-size:10.5px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;}
.cap-bar-bg{flex:1;height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;}
.cap-bar-fill{height:100%;border-radius:3px;transition:width .6s ease;}
.cap-pct{font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0;min-width:36px;text-align:right;}

/* Mini gráfico */
.maq-card-chart{padding:10px 14px 14px;}
.maq-card-chart-title{font-size:10px;font-weight:700;letter-spacing:.6px;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;}
.maq-chart-wrap{position:relative;height:100px;width:100%;}

/* Rodapé do card */
.maq-card-footer{padding:10px 18px;display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;border-top:1px solid var(--border);background:rgba(0,0,0,.08);}
.footer-item{display:flex;flex-direction:column;gap:2px;min-width:0;}
.footer-item label{font-size:9.5px;font-weight:700;letter-spacing:.6px;color:var(--text-muted);text-transform:uppercase;}
.footer-item span{font-size:11.5px;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;}

/* Tabela relatório */
.report-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;flex:1;min-height:0;}
.report-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;flex-shrink:0;}
.report-head h2{font-size:14px;font-weight:600;}
.report-table{width:100%;border-collapse:collapse;}
.report-table th{padding:9px 16px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:#112240;white-space:nowrap;position:sticky;top:0;z-index:2;}
.report-table td{padding:11px 16px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);vertical-align:middle;}
.report-table tbody tr:last-child td{border-bottom:none;}
.report-table tfoot td{border-top:2px solid var(--border);border-bottom:none;}
.report-table tbody tr:hover td{background:rgba(255,255,255,.025);}
.num-big{font-size:15px;font-weight:700;font-variant-numeric:tabular-nums;}
.td-muted{color:var(--text-muted);font-size:12px;}
.pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;}
.pill-teal{background:rgba(0,201,167,.1);color:var(--teal);}
.pill-red{background:rgba(239,68,68,.1);color:var(--red);}
.pill-muted{background:rgba(255,255,255,.06);color:var(--text-muted);}
.empty-report{text-align:center;padding:56px 20px;color:var(--text-muted);font-size:13px;opacity:.7;}

/* Empty state cards */
.empty-cards{text-align:center;padding:64px 20px;color:var(--text-muted);}
.empty-cards svg{display:block;margin:0 auto 14px;opacity:.2;}
.empty-cards p{font-size:13px;}

@media(max-width:1100px){.charts-row{grid-template-columns:repeat(2,1fr);}.kpi-row{grid-template-columns:repeat(3,1fr);}}
@media(max-width:700px){.kpi-row{grid-template-columns:1fr 1fr;}.maq-cards-grid{grid-template-columns:1fr;}}
@media(max-width:480px){.kpi-row{grid-template-columns:1fr;}.charts-row{grid-template-columns:1fr;}.report-panel{overflow-x:auto;}}
@media print{
  body{background:white;color:#111;overflow:auto;height:auto;}
  .app-wrapper{display:block;}.sidebar,.topbar,.tabs-bar,.btn-primary,.btn-teal,.icon-btn,.chart-export-btn{display:none!important;}
  .main{display:block;}.content{overflow:visible;padding:0;}
  .report-panel,.maq-card{border:1px solid #ccc;}
  .report-table th{background:#f0f4ff;color:#333;}.report-table td{color:#111;}
}
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
        <div class="page-title">
          <h1>Produção</h1>
          <p id="topbar-date"></p>
        </div>
      </div>
      <div class="topbar-actions">
        <!-- Seletor de data -->
        <form method="GET" style="display:flex;align-items:center;gap:6px;">
          <label style="font-size:11px;font-weight:700;letter-spacing:.5px;color:var(--text-muted);text-transform:uppercase;white-space:nowrap;">Data</label>
          <input type="date" name="data" value="<?= htmlspecialchars($data_sel) ?>"
            style="height:36px;padding:0 10px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-family:inherit;font-size:13px;outline:none;cursor:pointer;"
            onchange="this.form.submit()">
          <button type="submit" style="display:none;"></button>
        </form>
        <button class="icon-btn" title="Atualizar" onclick="location.reload()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        </button>
        <button class="btn-primary" onclick="window.open('/pcp/pdf?data='+DATA_SEL,'_blank')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Exportar PDF
        </button>
      </div>
    </header>

    <div class="content" id="contentArea">

      <?php if ($db_error): ?>
      <div class="alert-err">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($db_error) ?>
      </div>
      <?php endif; ?>

      <!-- Abas -->
      <div class="tabs-bar">
        <button class="tab-btn active" onclick="switchTab('cards')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Por Máquina
        </button>
        <button class="tab-btn" onclick="switchTab('graficos')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Gráficos Globais
        </button>
        <button class="tab-btn" onclick="switchTab('tabela')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
          Tabela Comparativa
        </button>
        <button class="tab-btn" onclick="switchTab('finalizados')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>
          Pedidos Finalizados
        </button>
      </div>

      <!-- TAB: Cards por máquina -->
      <div class="tab-content active" id="tab-cards">
        <div class="maq-cards-grid" id="maqCardsGrid"></div>
      </div>

      <!-- TAB: Gráficos globais -->
      <div class="tab-content" id="tab-graficos">
        <div class="charts-row" id="chartsRow">
          <div class="chart-panel" id="panelDiario">
            <div class="chart-head">
              <div>
                <div class="chart-title">Produção de Hoje por Hora</div>
                <div class="chart-sub" id="subtitleDiario"></div>
              </div>
              <button class="chart-export-btn" onclick="exportarCanvas('chartDiario','producao_diaria')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>PNG
              </button>
            </div>
            <div class="chart-body" id="bodyDiario"><canvas id="chartDiario" height="220"></canvas></div>
          </div>
          <div class="chart-panel" id="panelMensal">
            <div class="chart-head">
              <div>
                <div class="chart-title">Produção Mensal</div>
                <div class="chart-sub" id="subtitleMensal"></div>
              </div>
              <button class="chart-export-btn" onclick="exportarCanvas('chartMensal','producao_mensal')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>PNG
              </button>
            </div>
            <div class="chart-body" id="bodyMensal"><canvas id="chartMensal" height="220"></canvas></div>
          </div>
        </div>
      </div>

      <!-- TAB: Pedidos finalizados -->
      <div class="tab-content" id="tab-finalizados">
        <div class="report-panel" id="finalizadosPanel">
          <div class="report-head">
            <h2>Pedidos Finalizados</h2>
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:11.5px;color:var(--text-muted);" id="finalizadosDate"></span>
              <div style="display:flex;border:1px solid var(--border);border-radius:7px;overflow:hidden;">
                <button id="fin-btn-dia" onclick="renderFinalizados('dia')"
                  style="padding:5px 14px;font-size:12px;font-weight:600;font-family:inherit;border:none;cursor:pointer;background:var(--blue-accent);color:#fff;transition:all .15s;">Dia</button>
                <button id="fin-btn-mes" onclick="renderFinalizados('mes')"
                  style="padding:5px 14px;font-size:12px;font-weight:600;font-family:inherit;border:none;cursor:pointer;background:transparent;color:var(--text-muted);transition:all .15s;">Mês</button>
              </div>
            </div>
          </div>
          <div id="finalizadosBody"></div>
        </div>
      </div>

      <!-- TAB: Tabela comparativa -->
      <div class="tab-content" id="tab-tabela">
        <div class="report-panel" id="reportPanel">
          <div class="report-head">
            <h2>Comparativo por Máquina — Esperado vs Real</h2>
            <span style="font-size:11.5px;color:var(--text-muted);" id="reportDate"></span>
          </div>
          <div id="reportBody"></div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Modal exportação -->
<div id="exportModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:999;align-items:center;justify-content:center;">
  <div style="background:#152845;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:28px 32px;text-align:center;min-width:280px;">
    <div style="font-size:14px;font-weight:600;margin-bottom:8px;">Gerando relatório…</div>
    <div style="font-size:12px;color:var(--text-muted);">Aguarde um momento</div>
  </div>
</div>

<script>
// ── Dados PHP ────────────────────────────────────
const KPIS       = <?= $js_kpis ?>;
const DIARIO     = <?= $js_diario ?>;
const HORAS      = <?= $js_horas ?>;
const MAQS       = <?= $js_maquinas ?>;
const MENSAL     = <?= $js_mensal ?>;
const RELATORIO  = <?= $js_relatorio ?>;
const TODAS_MAQS  = <?= $js_todas_maq ?>;
const TOTAL_META  = <?= $js_total_meta ?>;
const PED_FIN         = <?= $js_ped_fin ?>;
const TOTAL_FIN       = <?= $js_total_fin ?>;
const PED_FIN_MES     = <?= $js_ped_fin_mes ?>;
const TOTAL_FIN_MES   = <?= $js_total_fin_mes ?>;
const DATA_HOJE   = <?= $js_data_hoje ?>;
const DATA_SEL   = <?= $js_data_sel ?>;
const MES_LABEL  = <?= $js_mes_label ?>;

// Paleta de cores — uma cor por máquina (consistente em todos os gráficos)
const PALETTE = [
  '#2d6aff','#00c9a7','#f59e0b','#ef4444','#a78bfa',
  '#38bdf8','#fb7185','#34d399','#fbbf24','#60a5fa','#f472b6','#4ade80'
];

// ── Topbar date ──────────────────────────────────
(function(){
  const dias  =['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
  const meses =['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  const d = new Date();
  document.getElementById('topbar-date').textContent =
    dias[d.getDay()] + ', ' + d.getDate() + ' de ' + meses[d.getMonth()] + ' de ' + d.getFullYear();
  document.getElementById('reportDate').textContent  = DATA_HOJE;
  document.getElementById('subtitleDiario').textContent = 'Apontamentos do dia ' + DATA_HOJE;
  document.getElementById('subtitleMensal').textContent = 'Acumulado de ' + MES_LABEL;
})();

// ── Abas ─────────────────────────────────────────
function switchTab(id) {
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  event.currentTarget.classList.add('active');
  // Inicializa gráficos globais na primeira vez que a aba abre
  if (id === 'graficos' && !window._graficosInit) { window._graficosInit=true; initGraficosGlobais(); }
}

// ── Cards por máquina ─────────────────────────────
(function buildCards(){
  const grid = document.getElementById('maqCardsGrid');

  if (!RELATORIO.length) {
    grid.innerHTML = `<div class="empty-cards" style="grid-column:1/-1;">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
        <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
        <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
      </svg>
      <p>Nenhum apontamento registrado hoje.<br>Faça registros em Produção Diária para ver os cards.</p>
    </div>`;
    return;
  }

  RELATORIO.forEach((r, idx) => {
    const cor       = PALETTE[idx % PALETTE.length];
    const real      = parseFloat(r.prod_min_real)     || 0;
    const cad       = parseFloat(r.prod_min_cadastro)  || 0;
    const delta     = cad > 0 ? ((real - cad) / cad * 100) : null;
    const pct       = r.pct_cap;
    const capUnd    = parseFloat(r.cap_dia_und) || 0;
    const und       = parseFloat(r.total_und)  || 0;
    const minTrab   = parseInt(r.minutos_trabalhados) || 0;
    const horasTrab = (minTrab / 60).toFixed(1);
    const cardId    = 'card-' + r.maq_codigo;
    const chartId   = 'mc-' + r.maq_codigo;

    // Pill de performance
    let perfHtml = '';
    if (delta === null) {
      perfHtml = `<span class="perf-pill perf-na">Sem cadastro</span>`;
    } else if (delta >= 0) {
      perfHtml = `<span class="perf-pill perf-ok">▲ +${delta.toFixed(1)}%</span>`;
    } else {
      perfHtml = `<span class="perf-pill perf-low">▼ ${delta.toFixed(1)}%</span>`;
    }

    // Barra de capacidade
    let capHtml = '';
    if (pct !== null) {
      const capColor = pct >= 90 ? 'var(--teal)' : pct >= 60 ? 'var(--amber)' : 'var(--red)';
      capHtml = `
        <div class="maq-card-cap">
          <span class="cap-label">Capacidade utilizada</span>
          <div class="cap-bar-bg"><div class="cap-bar-fill" style="width:${pct}%;background:${capColor};"></div></div>
          <span class="cap-pct" style="color:${capColor};">${pct}%</span>
        </div>`;
    } else if (capUnd === 0) {
      capHtml = `
        <div class="maq-card-cap">
          <span class="cap-label" style="color:var(--amber);">⚠ Velocidade não cadastrada</span>
        </div>`;
    }

    const horaIni = r.hora_inicio || '—';
    const horaFim = r.hora_fim    || '—';

    const card = document.createElement('div');
    card.className = 'maq-card';
    card.id = cardId;
    card.innerHTML = `
      <!-- Header -->
      <div class="maq-card-head" style="border-top:3px solid ${cor};">
        <div>
          <div class="maq-card-name">${r.maq_descricao}</div>
          <div class="maq-card-meta">
            ${r.dp_descricao ? `<span class="badge-depto">${r.dp_descricao}</span>` : ''}
            <span class="badge-qtde">${r.qtde_maquinas} máq. · ${r.horas_dia}h/dia</span>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
          ${perfHtml}
          <span class="badge-horario">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            ${horaIni} – ${horaFim}
          </span>
        </div>
      </div>

      <!-- Stats -->
      <div class="maq-card-stats">
        <div class="card-stat highlight">
          <div class="card-stat-val" style="color:${cor};">${und.toLocaleString('pt-BR')}</div>
          <div class="card-stat-lbl">Real</div>
        </div>
        <div class="card-stat">
          <div class="card-stat-val" style="color:var(--text-muted);">${capUnd > 0 ? Math.round(capUnd).toLocaleString('pt-BR') : '—'}</div>
          <div class="card-stat-lbl">Meta dia</div>
        </div>
        <div class="card-stat">
          <div class="card-stat-val" style="color:var(--text-muted);">${(parseFloat(r.esp_tempo)||0) > 0 ? Math.round(parseFloat(r.esp_tempo)).toLocaleString('pt-BR') : '—'}</div>
          <div class="card-stat-lbl">Esp. tempo</div>
        </div>
        <div class="card-stat">
          <div class="card-stat-val">${real > 0 ? real.toFixed(2) : '—'}</div>
          <div class="card-stat-lbl">Prod/min real</div>
        </div>
        <div class="card-stat">
          <div class="card-stat-val" style="color:var(--text-muted);">${cad > 0 ? cad.toFixed(2) : '—'}</div>
          <div class="card-stat-lbl">Prod/min cad.</div>
        </div>
      </div>

      <!-- Capacidade -->
      ${capHtml}

      <!-- Mini chart -->
      <div class="maq-card-chart">
        <div class="maq-card-chart-title">Produção por hora</div>
        <div class="maq-chart-wrap"><canvas id="${chartId}"></canvas></div>
      </div>

      <!-- Footer: operadores e pedidos -->
      <div class="maq-card-footer">
        <div class="footer-item" style="flex:1;min-width:80px;">
          <label>Operadores</label>
          <span title="${r.funcionarios||''}">${r.funcionarios || '—'}</span>
        </div>
        <div class="footer-item" style="flex:1;min-width:80px;">
          <label>Pedidos / OPs</label>
          <span title="${r.pedidos||''}">${r.pedidos || '—'}</span>
        </div>
        <div class="footer-item">
          <label>Tempo trabalhado</label>
          <span>${horasTrab}h (${minTrab} min)</span>
        </div>
      </div>
    `;
    grid.appendChild(card);

    // Mini gráfico após o card ser inserido no DOM
    requestAnimationFrame(() => buildMiniChart(chartId, r.horas_por_hora, cor));
  });
})();

function buildMiniChart(canvasId, horasPorHora, cor) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  const horas = Object.keys(horasPorHora).sort();
  const dados = horas.map(h => horasPorHora[h] || 0);

  if (!horas.length) {
    canvas.parentElement.style.display = 'none';
    canvas.parentElement.insertAdjacentHTML('afterend',
      '<div class="chart-empty" style="padding:16px 0;text-align:center;font-size:11.5px;color:var(--text-muted);">Sem dados por hora</div>');
    return;
  }

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: horas,
      datasets: [{
        data: dados,
        backgroundColor: cor + '66',
        borderColor: cor,
        borderWidth: 1.5,
        borderRadius: 3,
        hoverBackgroundColor: cor + 'aa',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 400 },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1a3a6a',
          titleColor: '#e8edf5',
          bodyColor: '#b0c4de',
          callbacks: {
            label: ctx => ' ' + Number(ctx.raw).toLocaleString('pt-BR') + ' un'
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: '#7a90b0', font: { size: 9 }, maxRotation: 0 }
        },
        y: {
          grid: { color: 'rgba(255,255,255,.04)' },
          ticks: { color: '#7a90b0', font: { size: 9 },
            callback: v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v }
        }
      }
    }
  });
}

// ── Gráficos globais (inicializados ao abrir a aba) ──
function initGraficosGlobais() {
  // Gráfico diário
  (function(){
    const ctx = document.getElementById('chartDiario');
    if (!HORAS.length) {
      document.getElementById('bodyDiario').innerHTML = '<div class="chart-empty">Nenhum apontamento hoje.</div>';
      return;
    }
    const datasets = MAQS.map((maq, i) => ({
      label: maq,
      data: HORAS.map(h => DIARIO[h]?.[maq] ?? 0),
      backgroundColor: PALETTE[i % PALETTE.length] + 'cc',
      borderColor: PALETTE[i % PALETTE.length],
      borderWidth: 1, borderRadius: 3,
    }));
    new Chart(ctx, {
      type: 'bar', data: { labels: HORAS, datasets },
      options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position:'bottom', labels:{ color:'#7a90b0', font:{size:11}, padding:12, boxWidth:12 } },
          tooltip: { backgroundColor:'#1a3a6a', titleColor:'#e8edf5', bodyColor:'#b0c4de',
            callbacks: { label: ctx => ' '+ctx.dataset.label+': '+ctx.raw.toLocaleString('pt-BR')+' un' } } },
        scales: {
          x:{ stacked:true, grid:{color:'rgba(255,255,255,.04)'}, ticks:{color:'#7a90b0',font:{size:11}} },
          y:{ stacked:true, grid:{color:'rgba(255,255,255,.06)'}, ticks:{color:'#7a90b0',font:{size:11},callback:v=>v.toLocaleString('pt-BR')} }
        }
      }
    });
  })();

  // Gráfico mensal
  (function(){
    const ctx  = document.getElementById('chartMensal');
    const dias  = Object.keys(MENSAL);
    const qtds  = Object.values(MENSAL);
    if (!dias.length) {
      document.getElementById('bodyMensal').innerHTML = '<div class="chart-empty">Nenhum dado neste mês.</div>';
      return;
    }
    const labels = dias.map(d => { const p=d.split('-'); return p[2]+'/'+p[1]; });
    const acum   = qtds.reduce((acc,v,i)=>{ acc.push((acc[i-1]||0)+v); return acc; }, []);
    new Chart(ctx, {
      type:'line', data:{ labels, datasets:[
        { label:'Produção diária', data:qtds, yAxisID:'y',
          backgroundColor:'rgba(45,106,255,.15)', borderColor:'#2d6aff',
          borderWidth:2, fill:true, tension:0.35, pointRadius:4, pointHoverRadius:6, pointBackgroundColor:'#2d6aff' },
        { label:'Acumulado', data:acum, yAxisID:'y2',
          backgroundColor:'transparent', borderColor:'rgba(0,201,167,.7)',
          borderWidth:1.5, borderDash:[4,4], fill:false, tension:0.35,
          pointRadius:2, pointHoverRadius:5, pointBackgroundColor:'#00c9a7' }
      ]},
      options:{ responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{position:'bottom',labels:{color:'#7a90b0',font:{size:11},padding:12,boxWidth:12}},
          tooltip:{backgroundColor:'#1a3a6a',titleColor:'#e8edf5',bodyColor:'#b0c4de',
            callbacks:{label:ctx=>' '+ctx.dataset.label+': '+Number(ctx.raw).toLocaleString('pt-BR')+' un'}} },
        scales:{
          x:{ grid:{color:'rgba(255,255,255,.04)'}, ticks:{color:'#7a90b0',font:{size:11}} },
          y:{ position:'left', grid:{color:'rgba(255,255,255,.06)'},ticks:{color:'#7a90b0',font:{size:11},callback:v=>v.toLocaleString('pt-BR')} },
          y2:{position:'right',grid:{drawOnChartArea:false},ticks:{color:'rgba(0,201,167,.6)',font:{size:10},callback:v=>v.toLocaleString('pt-BR')}}
        }
      }
    });
  })();
}

// ── Tabela comparativa ────────────────────────────
(function(){
  const el = document.getElementById('reportBody');
  if (!RELATORIO.length) {
    el.innerHTML = '<div class="empty-report">Nenhum apontamento registrado hoje.</div>';
    return;
  }

  let somaReal = 0, somaMeta = 0, somaEsp = 0;

  let html = `<div class="table-wrap"><table class="report-table">
    <thead><tr>
      <th>Máquina</th><th>Depto</th>
      <th style="text-align:right;">Real</th>
      <th style="text-align:right;">Meta dia</th>
      <th style="text-align:right;">Esp. tempo</th>
      <th style="text-align:center;">vs Meta</th>
      <th style="text-align:right;">Prod/min Real</th>
      <th style="text-align:right;">Prod/min Cad.</th>
      <th style="text-align:center;">Cap. utilizada</th>
      <th>Horário</th><th>Apontamentos</th><th>Pedidos</th><th>Operadores</th>
    </tr></thead><tbody>`;

  RELATORIO.forEach((r,idx) => {
    const cor    = PALETTE[idx % PALETTE.length];
    const realPm = parseFloat(r.prod_min_real)    || 0;
    const cad    = parseFloat(r.prod_min_cadastro) || 0;
    const und    = parseFloat(r.total_und)         || 0;
    const meta   = parseFloat(r.cap_dia_und)       || 0;
    const esp    = parseFloat(r.esp_tempo)         || 0;
    const pct    = r.pct_cap;

    somaReal += und;
    somaMeta += meta;
    somaEsp  += esp;

    // Diferença real vs meta
    const difMeta = meta > 0 ? (und - meta) : null;
    const difHtml = difMeta === null
      ? '<span class="pill pill-muted">—</span>'
      : difMeta >= 0
        ? `<span class="pill pill-teal">+${Math.round(difMeta).toLocaleString('pt-BR')}</span>`
        : `<span class="pill pill-red">${Math.round(difMeta).toLocaleString('pt-BR')}</span>`;

    const capHtml = pct !== null
      ? `<div style="display:flex;align-items:center;gap:6px;">
           <div style="flex:1;height:4px;background:rgba(255,255,255,.06);border-radius:2px;overflow:hidden;min-width:60px;">
             <div style="height:100%;width:${pct}%;background:${pct>=90?'var(--teal)':pct>=60?'var(--amber)':'var(--red)'};border-radius:2px;"></div>
           </div>
           <span style="font-size:11px;font-weight:700;">${pct}%</span>
         </div>`
      : '<span class="pill pill-muted">—</span>';

    html += `<tr>
      <td><strong style="color:${cor};">${r.maq_descricao}</strong></td>
      <td class="td-muted">${r.dp_descricao||'—'}</td>
      <td style="text-align:right;"><span class="num-big">${und.toLocaleString('pt-BR')}</span></td>
      <td style="text-align:right;" class="td-muted">${meta > 0 ? Math.round(meta).toLocaleString('pt-BR') : '—'}</td>
      <td style="text-align:right;" class="td-muted">${esp > 0 ? Math.round(esp).toLocaleString('pt-BR') : '—'}</td>
      <td style="text-align:center;">${difHtml}</td>
      <td style="text-align:right;" class="td-muted">${realPm>0?realPm.toFixed(2)+' un/min':'—'}</td>
      <td style="text-align:right;" class="td-muted">${cad>0?cad.toFixed(2)+' un/min':'—'}</td>
      <td style="min-width:140px;">${capHtml}</td>
      <td class="td-muted">${(r.hora_inicio||'—')+' – '+(r.hora_fim||'—')}</td>
      <td style="text-align:right;" class="td-muted">${r.apontamentos}</td>
      <td class="td-muted" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;" title="${r.pedidos||''}">${r.pedidos||'—'}</td>
      <td class="td-muted" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;" title="${r.funcionarios||''}">${r.funcionarios||'—'}</td>
    </tr>`;
  });

  // Linha de totais
  const difTotal = somaMeta > 0 ? somaReal - somaMeta : null;
  const difTotalHtml = difTotal === null
    ? '—'
    : (difTotal >= 0
      ? `<span class="pill pill-teal">+${Math.round(difTotal).toLocaleString('pt-BR')}</span>`
      : `<span class="pill pill-red">${Math.round(difTotal).toLocaleString('pt-BR')}</span>`);
  const eficTotal = somaMeta > 0 ? Math.min(999,(somaReal/somaMeta*100)).toFixed(1)+'%' : '—';

  html += `</tbody><tfoot><tr style="background:rgba(0,0,0,.15);">
    <td colspan="2" style="padding:11px 16px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;">TOTAL GERAL</td>
    <td style="text-align:right;padding:11px 16px;"><span class="num-big" style="font-size:17px;">${Math.round(somaReal).toLocaleString('pt-BR')}</span></td>
    <td style="text-align:right;padding:11px 16px;" class="td-muted">${somaMeta > 0 ? Math.round(somaMeta).toLocaleString('pt-BR') : '—'}</td>
    <td style="text-align:right;padding:11px 16px;" class="td-muted">${somaEsp > 0 ? Math.round(somaEsp).toLocaleString('pt-BR') : '—'}</td>
    <td style="text-align:center;padding:11px 16px;">${difTotalHtml}</td>
    <td colspan="3" style="padding:11px 16px;font-size:12px;color:var(--text-muted);">Eficiência global: <strong style="color:${somaMeta>0&&somaReal/somaMeta>=.9?'var(--teal)':somaMeta>0&&somaReal/somaMeta>=.6?'var(--amber)':'var(--red)'}">${eficTotal}</strong></td>
    <td colspan="4"></td>
  </tr></tfoot></table></div>`;

  el.innerHTML = html;
})();

// ── Pedidos Finalizados ───────────────────────────
function renderFinalizados(modo) {
  // Toggle visual dos botões
  const btnDia = document.getElementById('fin-btn-dia');
  const btnMes = document.getElementById('fin-btn-mes');
  if (modo === 'dia') {
    btnDia.style.background = 'var(--blue-accent)'; btnDia.style.color = '#fff';
    btnMes.style.background = 'transparent';         btnMes.style.color = 'var(--text-muted)';
  } else {
    btnMes.style.background = 'var(--blue-accent)'; btnMes.style.color = '#fff';
    btnDia.style.background = 'transparent';         btnDia.style.color = 'var(--text-muted)';
  }

  const dados  = modo === 'dia' ? PED_FIN     : PED_FIN_MES;
  const total  = modo === 'dia' ? TOTAL_FIN   : TOTAL_FIN_MES;
  const label  = modo === 'dia' ? DATA_HOJE   : MES_LABEL;
  const el     = document.getElementById('finalizadosBody');

  document.getElementById('finalizadosDate').textContent = label;

  if (!dados.length) {
    el.innerHTML = '<div class="empty-report">Nenhum pedido finalizado em ' + label + '.</div>';
    return;
  }

  const comDataFin = modo === 'mes';

  let html = `<div class="table-wrap"><table class="report-table"><thead><tr>
    ${comDataFin ? '<th>Finalizado em</th>' : ''}
    <th>Pedido</th>
    <th>Cliente</th>
    <th>Entrega</th>
    <th>Produtos</th>
    <th style="text-align:right;">Itens</th>
    <th style="text-align:right;">Qtde (venda)</th>
  </tr></thead><tbody>`;

  dados.forEach(r => {
    const entrega  = r.ven_entrega
      ? r.ven_entrega.split('-').reverse().join('/')
      : '—';
    const dataFin  = r.data_finalizacao
      ? r.data_finalizacao.split('-').reverse().join('/')
      : '—';
    html += `<tr>
      ${comDataFin ? `<td class="td-muted">${dataFin}</td>` : ''}
      <td><strong>${r.pedido || '—'}</strong></td>
      <td>${r.cliente || '—'}</td>
      <td class="td-muted">${entrega}</td>
      <td class="td-muted" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;" title="${r.produtos||''}">${r.produtos || '—'}</td>
      <td style="text-align:right;" class="td-muted">${r.total_itens}</td>
      <td style="text-align:right;"><span class="num-big" style="color:var(--teal);">${Number(r.total_qtde).toLocaleString('pt-BR')}</span></td>
    </tr>`;
  });

  const colspan = comDataFin ? 6 : 5;
  html += `</tbody><tfoot><tr>
    <td colspan="${colspan}" style="padding:11px 16px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Total</td>
    <td style="text-align:right;padding:11px 16px;"><span class="num-big" style="font-size:18px;color:var(--teal);">${Number(total).toLocaleString('pt-BR')}</span></td>
  </tr></tfoot></table></div>`;

  el.innerHTML = html;
}

// Inicializa na visão diária
renderFinalizados('dia');

// ── Exportar canvas individual ────────────────────
function exportarCanvas(canvasId, nome) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const off = document.createElement('canvas');
  off.width = canvas.width; off.height = canvas.height;
  const ctx2 = off.getContext('2d');
  ctx2.fillStyle = '#0a1628';
  ctx2.fillRect(0,0,off.width,off.height);
  ctx2.drawImage(canvas,0,0);
  const link = document.createElement('a');
  link.download = nome+'_'+DATA_HOJE.replace(/\//g,'-')+'.png';
  link.href = off.toDataURL('image/png'); link.click();
}

// ── Exportar gráficos (html2canvas) ──────────────
async function exportarGraficos() {
  // Abre aba de gráficos se não estiver lá
  if (!window._graficosInit) { switchTab('graficos'); await new Promise(r=>setTimeout(r,500)); }
  const modal = document.getElementById('exportModal');
  modal.style.display = 'flex';
  try {
    const el = document.getElementById('chartsRow');
    const canvas = await html2canvas(el, {backgroundColor:'#0a1628',scale:2,useCORS:true,logging:false});
    const link = document.createElement('a');
    link.download = 'graficos_producao_'+DATA_HOJE.replace(/\//g,'-')+'.png';
    link.href = canvas.toDataURL('image/png'); link.click();
  } catch(e) { alert('Erro ao exportar: '+e.message); }
  modal.style.display = 'none';
}

</script>
</body>
</html>