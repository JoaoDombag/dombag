<?php
// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Dashboard
//  ERP:   PostgreSQL Yzidro  (vendas, pedidos)
//  Local: MySQL dombag       (produção, máquinas)
// ══════════════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ── Variáveis iniciais ────────────────────────────────────────────────
$erp_ok               = false;
$kpi_pedidos_mes      = 0;
$kpi_faturado_mes     = 0.0;
$kpi_pedidos_hoje     = 0;
$kpi_ticket_medio     = 0.0;
$kpi_mes_ant          = 0;
$kpi_pendentes_importar = 0;
$kpi_finalizados      = 0;
$kpi_a_produzir       = 0;
$ultimas_vendas       = [];
$prod_7dias           = [];
$top_maquinas         = [];
$vendas_grupo         = [];
$fin_pagar_total      = 0.0;
$fin_pagar_vencido    = 0.0;
$fin_receber_total    = 0.0;
$fin_receber_vencido  = 0.0;

// ── Preferências de widgets do utilizador ─────────────────────────────
$_dash_uid = (int)($_SESSION['usu_codigo'] ?? 0);
try {
    $_dash_pdo_w = dbPDO();
    $_dash_val   = $_dash_pdo_w->prepare("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = :k");
    $_dash_val->execute([':k' => 'dashboard_widgets_' . $_dash_uid]);
    $_dash_raw   = $_dash_val->fetchColumn();
    $dashWidgets = $_dash_raw !== false ? (json_decode($_dash_raw, true) ?? []) : null;
} catch (Throwable) {
    $dashWidgets = null;
}
// null = sem preferência salva; será preenchido com padrão após carregar permissões
$_dash_widgets_default = [
    'kpi_erp','kpi_local','kpi_faturamento',
    'ultimas_vendas','maquinas_hoje',
    'vendas_grupo','prod_7dias',
    'financeiro','trello_geral','trello_atividade',
];

// ── Permissões e menus activos (para filtrar widgets) ─────────────────
$_dash_admin       = false;
$_dash_perms       = [];
$_dash_desativados = [];
try {
    $_pdo_p = dbPDO();
    $_st_u  = $_pdo_p->prepare('SELECT USU_ADMIN, GRU_CODIGO FROM USUARIOS WHERE USU_CODIGO = :c');
    $_st_u->execute([':c' => $_dash_uid]);
    $_row_u = $_st_u->fetch(PDO::FETCH_ASSOC);
    if ($_row_u) {
        $_dash_admin = !empty($_row_u['USU_ADMIN']);
        if (!$_dash_admin && !empty($_row_u['GRU_CODIGO'])) {
            $_st_p = $_pdo_p->prepare('SELECT PAC_PAGINA FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :g');
            $_st_p->execute([':g' => (int)$_row_u['GRU_CODIGO']]);
            $_dash_perms = array_column($_st_p->fetchAll(PDO::FETCH_ASSOC), 'PAC_PAGINA');
        }
    }
    if ($v = $_pdo_p->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'sidebar_menus_desativados'")->fetchColumn()) {
        $_dash_desativados = json_decode($v, true) ?? [];
    }
} catch (Throwable) {}

// Se o usuário nunca personalizou, exibe todos os widgets que ele tem permissão
if ($dashWidgets === null) {
    $dashWidgets = $_dash_widgets_default;
}

// Mapa: widget → [página_requerida, grupo_de_menu]
$_widget_perm_map = [
    'kpi_erp'          => ['/financeiro',  'financeiro'],
    'kpi_local'        => ['/pcp/kanban',  'producao'],
    'kpi_faturamento'  => ['/financeiro',  'financeiro'],
    'ultimas_vendas'   => ['/financeiro',  'financeiro'],
    'maquinas_hoje'    => ['/pcp/producao','producao'],
    'vendas_grupo'     => ['/financeiro',  'financeiro'],
    'prod_7dias'       => ['/pcp/producao','producao'],
    'trello_geral'     => ['/trello',      'trello'],
    'trello_atividade' => ['/trello',      'trello'],
    'financeiro'       => ['/financeiro',  'financeiro'],
];

function dashW(string $key): bool {
    global $dashWidgets, $_dash_admin, $_dash_perms, $_dash_desativados, $_widget_perm_map;
    if (!in_array($key, $dashWidgets, true)) return false;
    if (isset($_widget_perm_map[$key])) {
        [$page, $menu] = $_widget_perm_map[$key];
        // Menu desativado aplica-se a todos, incluindo admins
        if (in_array($menu, $_dash_desativados, true)) return false;
        // Permissão de página: só verifica para não-admins
        if (!$_dash_admin && !in_array($page, $_dash_perms, true)) return false;
    }
    return true;
}

// ── Board Trello padrão ───────────────────────────────────────────────
$trello_default_board = '';
try {
    $pdo_tmp = dbPDO();
    $st_tk   = $pdo_tmp->prepare(
        "SELECT PAR_CHAVE, PAR_VALOR FROM PARAMETROS
         WHERE PAR_CHAVE IN ('trello_api_key','trello_token','trello_default_board')"
    );
    $st_tk->execute();
    $trello_params = [];
    foreach ($st_tk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $trello_params[$r['PAR_CHAVE']] = trim($r['PAR_VALOR']);
    }
    $tk = $trello_params['trello_api_key'] ?? '';
    $tt = $trello_params['trello_token']   ?? '';
    if (!empty($trello_params['trello_default_board'])) {
        $trello_default_board = $trello_params['trello_default_board'];
    } elseif ($tk && $tt) {
        $ch_tmp = curl_init(
            'https://api.trello.com/1/members/me/boards?fields=id,name&filter=open'
            . '&key=' . urlencode($tk) . '&token=' . urlencode($tt)
        );
        curl_setopt_array($ch_tmp, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true]);
        $res_tmp  = curl_exec($ch_tmp);
        $code_tmp = curl_getinfo($ch_tmp, CURLINFO_HTTP_CODE);
        if ($code_tmp === 200 && $boards_tmp = json_decode($res_tmp, true)) {
            $trello_default_board = $boards_tmp[0]['id'] ?? '';
        }
    }
} catch (Throwable) {}

// ── Dados MySQL ───────────────────────────────────────────────────────
try {
    $pdo = dbPDO();

    // Garante colunas adicionadas às MAQUINAS após a criação inicial da tabela
    foreach ([
        "ALTER TABLE MAQUINAS ADD COLUMN maq_qtde NUMERIC(6,2) NOT NULL DEFAULT 1",
        "ALTER TABLE MAQUINAS ADD COLUMN maq_producao_min NUMERIC(10,4) NOT NULL DEFAULT 0",
        "ALTER TABLE MAQUINAS ADD COLUMN maq_horas_dia NUMERIC(5,2) NOT NULL DEFAULT 8",
        "ALTER TABLE MAQUINAS ADD COLUMN maq_conta_producao TINYINT(1) NOT NULL DEFAULT 1",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (Throwable) {}
    }

    $kpi_finalizados = (int)$pdo->query(
        "SELECT COALESCE(SUM(iv_qtde),0) FROM ITENS_VENDAS WHERE iv_status='Finalizado'"
    )->fetchColumn();

    $kpi_a_produzir = (int)$pdo->query(
        "SELECT COALESCE(SUM(iv_qtde),0) FROM ITENS_VENDAS WHERE iv_status='Pendente de produção'"
    )->fetchColumn();

    $rows = $pdo->query('
        SELECT pd.pd_data,
               SUM(CASE WHEN COALESCE(m.maq_conta_producao,1)=1 THEN pd.pd_quantidade ELSE 0 END) AS qtd
        FROM PRODUCAO_DIARIA pd
        LEFT JOIN MAQUINAS m ON m.maq_codigo = pd.maq_codigo
        WHERE pd.pd_data >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY pd.pd_data
        ORDER BY pd.pd_data ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $prod_7dias[$r['pd_data']] = (int)$r['qtd'];
    }

    $top_maquinas = $pdo->query('
        SELECT m.maq_descricao, SUM(pd.pd_quantidade) AS total
        FROM PRODUCAO_DIARIA pd
        INNER JOIN MAQUINAS m ON m.maq_codigo = pd.maq_codigo
        WHERE pd.pd_data = CURDATE()
        GROUP BY pd.maq_codigo, m.maq_descricao
        ORDER BY total DESC
        LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    // financeiro widget: load from ERP if available, fallback to local tables
    if (dashW('financeiro')) {
        $fin_pagar_total   = 0.0; $fin_pagar_vencido   = 0.0;
        $fin_receber_total = 0.0; $fin_receber_vencido = 0.0;
        $fin_from_erp = false;
        $pgFin = dbPG();
        if ($pgFin) {
            $hj = date('Y-m-d');
            $resFin = pg_query($pgFin,
                "SELECT
                    COALESCE(SUM(COALESCE(CP_SALDO_MP,0)),0) AS total,
                    COALESCE(SUM(CASE WHEN CP_VENCIMENTO < '$hj' THEN COALESCE(CP_SALDO_MP,0) ELSE 0 END),0) AS vencido
                 FROM CONTAS_PAGAR
                 WHERE CP_TIPO='D' AND CP_VINCULO IS NULL AND EMP_CODIGO=1
                   AND COALESCE(CP_SALDO_MP,0) > 0"
            );
            if ($resFin && $rowFin = pg_fetch_assoc($resFin)) {
                $fin_pagar_total   = (float)$rowFin['total'];
                $fin_pagar_vencido = (float)$rowFin['vencido'];
                $fin_from_erp = true;
            }
            $resFin = pg_query($pgFin,
                "SELECT
                    COALESCE(SUM(COALESCE(CR_SALDO_PARCELA_MP,0)),0) AS total,
                    COALESCE(SUM(CASE WHEN CR_VENCIMENTO < '$hj' THEN COALESCE(CR_SALDO_PARCELA_MP,0) ELSE 0 END),0) AS vencido
                 FROM CONTAS_RECEBER
                 WHERE CR_TIPO='C' AND CR_VINCULO IS NULL AND EMP_CODIGO=1
                   AND COALESCE(CR_SALDO_PARCELA_MP,0) > 0"
            );
            if ($resFin && $rowFin = pg_fetch_assoc($resFin)) {
                $fin_receber_total   = (float)$rowFin['total'];
                $fin_receber_vencido = (float)$rowFin['vencido'];
            }
        }
        if (!$fin_from_erp) {
            // fallback: local MySQL tables
            $r = $pdo->query(
                "SELECT COALESCE(SUM(valor - COALESCE(valor_pago,0)),0) AS total,
                        COALESCE(SUM(CASE WHEN status='VENCIDO' THEN valor - COALESCE(valor_pago,0) ELSE 0 END),0) AS vencido
                 FROM fin_contas_pagar WHERE status IN ('ABERTO','PARCIAL','VENCIDO')"
            )->fetch(PDO::FETCH_ASSOC);
            $fin_pagar_total   = (float)($r['total']   ?? 0);
            $fin_pagar_vencido = (float)($r['vencido'] ?? 0);

            $r = $pdo->query(
                "SELECT COALESCE(SUM(valor - COALESCE(valor_pago,0)),0) AS total,
                        COALESCE(SUM(CASE WHEN status='VENCIDO' THEN valor - COALESCE(valor_pago,0) ELSE 0 END),0) AS vencido
                 FROM fin_contas_receber WHERE status IN ('ABERTO','PARCIAL','VENCIDO')"
            )->fetch(PDO::FETCH_ASSOC);
            $fin_receber_total   = (float)($r['total']   ?? 0);
            $fin_receber_vencido = (float)($r['vencido'] ?? 0);
        }
    }
} catch (PDOException) {}

// ── Dados ERP (PostgreSQL) ────────────────────────────────────────────
$pg = dbPG();
if ($pg) {
    $erp_ok      = true;
    $mes_ini     = date('Y-m-01');
    $mes_fim     = date('Y-m-t');
    $mes_ant_ini = date('Y-m-01', strtotime('-1 month'));
    $mes_ant_fim = date('Y-m-t',  strtotime('-1 month'));
    $hoje        = date('Y-m-d');

    $res = pg_query($pg, "
        SELECT COUNT(DISTINCT PEDIDO) AS qtd_ped, COALESCE(SUM(TOTAL_PRODUTO),0) AS faturado
        FROM VW_VENDAS
        WHERE COD_GRUPO IN (6,24,5) AND OPERACAO_PEDIDO='V'
          AND COD_ESTADO IN ('9') AND EMP_CODIGO=1
          AND DATAFATURA BETWEEN '$mes_ini' AND '$mes_fim'
    ");
    if ($res && $row = pg_fetch_assoc($res)) {
        $kpi_pedidos_mes  = (int)$row['qtd_ped'];
        $kpi_faturado_mes = (float)$row['faturado'];
    }

    $res = pg_query($pg, "
        SELECT COUNT(DISTINCT PEDIDO) AS qtd
        FROM VW_VENDAS
        WHERE COD_GRUPO IN (6,24,5) AND OPERACAO_PEDIDO='V'
          AND COD_ESTADO IN ('9') AND EMP_CODIGO=1
          AND DATAFATURA = '$hoje'
    ");
    if ($res && $row = pg_fetch_assoc($res)) {
        $kpi_pedidos_hoje = (int)$row['qtd'];
    }

    $kpi_ticket_medio = $kpi_pedidos_mes > 0
        ? round($kpi_faturado_mes / $kpi_pedidos_mes, 2) : 0;

    $res = pg_query($pg, "
        SELECT COUNT(DISTINCT PEDIDO) AS qtd
        FROM VW_VENDAS
        WHERE COD_GRUPO IN (6,24,5) AND OPERACAO_PEDIDO='V'
          AND COD_ESTADO IN ('9') AND EMP_CODIGO=1
          AND DATAFATURA BETWEEN '$mes_ant_ini' AND '$mes_ant_fim'
    ");
    if ($res && $row = pg_fetch_assoc($res)) {
        $kpi_mes_ant = (int)$row['qtd'];
    }

    $res = pg_query($pg, "
        SELECT VV.PEDIDO::TEXT AS pedido,
               CAST(IIF(COALESCE(MIN(VV.NOME_FANTASIA),'') = '', MIN(VV.CLIENTE), MIN(VV.NOME_FANTASIA)) AS VARCHAR(80)) AS cliente,
               TO_CHAR(MAX(VV.DATAFATURA), 'DD/MM/YYYY') AS data,
               COUNT(*) AS qtd_itens,
               COALESCE(SUM(VV.TOTAL_PRODUTO), 0) AS total,
               COALESCE(MIN(VV.GRUPO_CLIENTES)::VARCHAR, '') AS grupo
        FROM VW_VENDAS VV
        WHERE VV.COD_GRUPO IN (6,24,5) AND VV.OPERACAO_PEDIDO='V'
          AND VV.COD_ESTADO IN ('9') AND VV.EMP_CODIGO=1
        GROUP BY VV.PEDIDO
        ORDER BY MIN(VV.DATAEMISSAO) DESC, VV.PEDIDO DESC
        LIMIT 5
    ");
    if ($res) {
        while ($row = pg_fetch_assoc($res)) $ultimas_vendas[] = $row;
    }

    $res = pg_query($pg, "
        SELECT COALESCE(GRUPO_CLIENTES::VARCHAR,'Outros') AS grupo,
               COUNT(DISTINCT PEDIDO) AS qtd,
               SUM(TOTAL_PRODUTO)     AS total
        FROM VW_VENDAS
        WHERE COD_GRUPO IN (6,24,5) AND OPERACAO_PEDIDO='V'
          AND COD_ESTADO IN ('9') AND EMP_CODIGO=1
          AND DATAFATURA BETWEEN '$mes_ini' AND '$mes_fim'
        GROUP BY GRUPO_CLIENTES
        ORDER BY total DESC
        LIMIT 5
    ");
    if ($res) {
        while ($row = pg_fetch_assoc($res)) $vendas_grupo[] = $row;
    }

    $janela_import = date('Y-m-d', strtotime('-90 days'));
    $res = pg_query($pg, "
        SELECT DISTINCT PEDIDO::TEXT AS pedido
        FROM VW_VENDAS
        WHERE COD_GRUPO IN (6,24,5) AND OPERACAO_PEDIDO='V'
          AND COD_ESTADO IN ('9') AND EMP_CODIGO=1
          AND DATAFATURA >= '$janela_import'
    ");
    if ($res) {
        $pedidos_erp_lista = pg_fetch_all_columns($res, 0) ?: [];
        if (!empty($pedidos_erp_lista) && isset($pdo)) {
            $ph  = implode(',', array_fill(0, count($pedidos_erp_lista), '?'));
            $st  = $pdo->prepare("SELECT COUNT(DISTINCT ven_codigo_yzidro) FROM VENDAS WHERE ven_codigo_yzidro IN ($ph)");
            $st->execute($pedidos_erp_lista);
            $kpi_pendentes_importar = max(0, count($pedidos_erp_lista) - (int)$st->fetchColumn());
        }
    }

    pg_close($pg);
}

// ── Helpers ───────────────────────────────────────────────────────────
function fmtMoeda(float $v): string { return 'R$ ' . number_format($v, 0, ',', '.'); }
function fmtNum(int $v): string     { return number_format($v, 0, ',', '.'); }
function variacao(int $atual, int $ant): string {
    if ($ant <= 0) return '';
    $pct = round(($atual - $ant) / $ant * 100, 1);
    $cor  = $pct >= 0 ? 'up' : 'down';
    $seta = $pct >= 0 ? '↑' : '↓';
    return "<span class=\"$cor\">$seta " . abs($pct) . '%</span>';
}

// ── Dados JS para gráficos ────────────────────────────────────────────
$dias_labels = [];
$dias_values = [];
for ($i = 6; $i >= 0; $i--) {
    $dt           = date('Y-m-d', strtotime("-$i days"));
    $dias_labels[] = date('d/m', strtotime("-$i days"));
    $dias_values[] = $prod_7dias[$dt] ?? 0;
}
$js_dias_lbl     = json_encode($dias_labels);
$js_dias_val     = json_encode($dias_values);
$js_grupo        = json_encode($vendas_grupo);
$max_prod_maq    = !empty($top_maquinas) ? max(array_column($top_maquinas, 'total')) : 1;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | DOMBAG</title>
  <link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    /* ── Layout do dashboard ───────────────────────── */
    .content           { display:flex; flex-direction:column; gap:16px; overflow-y:auto; overflow-x:hidden; }
    .dash-grid         { display:grid; gap:16px; align-items:stretch; }
    .dash-grid > div   { display:flex; flex-direction:column; }
    .dash-grid > div > .panel { flex:1; }
    @media(max-width:1100px) { .dash-grid { grid-template-columns:1fr !important; } }

    /* ── KPI grid ──────────────────────────────────── */
    .kpi-grid{
        display:grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
    @media(max-width:1400px) { .kpi-grid { grid-template-columns:repeat(3,1fr); } }
    @media(max-width:1100px) { .kpi-grid { grid-template-columns:repeat(2,1fr); } }
    @media(max-width:768px)  { .kpi-grid { grid-template-columns:repeat(2,1fr); } }
    @media(max-width:480px)  { .kpi-grid { grid-template-columns:1fr; } .kpi-faturado { font-size:18px !important; } }

    /* ── Gráfico ───────────────────────────────────── */
    .chart-wrap { position:relative; height:180px; padding:0 16px 12px; }
    @media(max-width:768px) { .chart-wrap { height:140px; } }

    /* ── Barras horizontais (máquinas / grupos) ────── */
    .maq-bar-row   { display:flex; flex-direction:column; gap:10px; padding:16px 20px; }
    .maq-bar-item  { display:flex; flex-direction:column; gap:4px; }
    .maq-bar-label { display:flex; justify-content:space-between; align-items:center; }
    .maq-bar-name  { font-size:12.5px; font-weight:500; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px; }
    .maq-bar-val   { font-size:12px; font-weight:700; color:#7db3ff; white-space:nowrap; }
    .maq-bar-bg    { height:6px; background:rgba(255,255,255,.06); border-radius:3px; overflow:hidden; }
    .maq-bar-fill  { height:100%; background:linear-gradient(90deg,#1e4fc9,#2d6aff); border-radius:3px; transition:width .6s ease; }
    @media(max-width:768px) { .maq-bar-name { max-width:none; } }

    /* ── Últimas vendas ────────────────────────────── */
    .venda-row   { display:flex; align-items:center; gap:12px; padding:10px 20px; border-bottom:1px solid var(--border); }
    .venda-row:last-child { border-bottom:none; }
    .venda-left  { display:flex; align-items:center; gap:7px; flex-shrink:0; }
    .venda-num   { font-weight:700; color:#7db3ff; font-size:12.5px; white-space:nowrap; }
    .venda-mid      { flex:1; min-width:0; }
    .venda-cliente  { font-size:12.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .venda-right { display:flex; flex-direction:column; align-items:flex-end; gap:2px; flex-shrink:0; }
    .venda-qty   { font-size:12px; font-weight:600; color:var(--teal); white-space:nowrap; }
    .venda-data  { font-size:11px; color:var(--text-muted); white-space:nowrap; }
    .grupo-pill  { font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; background:rgba(45,106,255,.1); color:#7db3ff; white-space:nowrap; }

    /* ── Atalhos Rápidos ───────────────────────────── */

    /* ── Misc ──────────────────────────────────────── */
    .erp-status    { display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--text-muted); }
    .erp-dot       { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .erp-dot.on    { background:var(--teal); box-shadow:0 0 6px var(--teal); }
    .erp-dot.off   { background:var(--red); }
    .kpi-faturado  { font-size:22px !important; letter-spacing:-1px !important; }
    .empty-row     { text-align:center; padding:32px 20px; color:var(--text-muted); font-size:13px; }
    .greeting      { font-size:11.5px; color:var(--text-muted); }
    .greeting strong { color:var(--text-primary); }

    /* ── Trello widget inner grid ──────────────────── */
    .trello-inner-grid { display:grid; grid-template-columns:1fr 180px; gap:0; align-items:start; }
    @media(max-width:768px) { .trello-inner-grid { grid-template-columns:1fr; } .trello-inner-grid > div:last-child { border-left:none !important; border-top:1px solid var(--border); } }
  </style>
</head>
<body>
<div class="app-wrapper">

  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">

    <!-- ── Topbar ───────────────────────────────────── -->
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Painel de Controle</h1>
          <p id="topbar-date"></p>
        </div>
      </div>
      <div class="topbar-actions">
        <div class="erp-status">
          <span class="erp-dot <?= $erp_ok ? 'on' : 'off' ?>"></span>
          ERP <?= $erp_ok ? 'conectado' : 'offline' ?>
        </div>
        <a href="/dashboard/widgets" class="btn-secondary" title="Personalizar dashboard"
           style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:12px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <rect x="3"  y="3"  width="7" height="7" rx="1"/>
            <rect x="14" y="3"  width="7" height="7" rx="1"/>
            <rect x="3"  y="14" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/>
          </svg>
          Widgets
        </a>
        <span class="greeting">Olá, <strong><?= htmlspecialchars(usuNome()) ?></strong></span>
        <a href="/logout" class="icon-btn" title="Sair" style="text-decoration:none;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </a>
      </div>
    </header>

    <?php
    // ════════════════════════════════════════════════════════════════════
    // Pré-renderiza cada widget em buffer para respeitar a ordem salva
    // ════════════════════════════════════════════════════════════════════
    $_w = [];

    // ── KPIs (grupo único renderizado uma vez) ────────────────────────
    if (dashW('kpi_erp') || dashW('kpi_local') || dashW('kpi_faturamento')) {
        ob_start(); ?>
        <div class="kpi-grid">

          <?php if (dashW('kpi_erp')): ?>
          <div class="kpi-card blue">
            <div class="kpi-header">
              <span class="kpi-label">Pedidos do Mês</span>
              <div class="kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                  <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
              </div>
            </div>
            <div class="kpi-value"><?= fmtNum($kpi_pedidos_mes) ?></div>
            <div class="kpi-footer">
              <?= variacao($kpi_pedidos_mes, $kpi_mes_ant) ?> vs. mês anterior
              &nbsp;<span class="source-badge src-erp">ERP</span>
            </div>
          </div>

          <div class="kpi-card teal">
            <div class="kpi-header">
              <span class="kpi-label">Pedidos Hoje</span>
              <div class="kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                  <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
              </div>
            </div>
            <div class="kpi-value"><?= fmtNum($kpi_pedidos_hoje) ?></div>
            <div class="kpi-footer">faturados hoje &nbsp;<span class="source-badge src-erp">ERP</span></div>
          </div>

          <div class="kpi-card <?= $kpi_pendentes_importar > 0 ? 'amber' : 'teal' ?>">
            <div class="kpi-header">
              <span class="kpi-label">A Importar do ERP</span>
              <div class="kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/>
                  <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/>
                </svg>
              </div>
            </div>
            <div class="kpi-value"><?= fmtNum($kpi_pendentes_importar) ?></div>
            <div class="kpi-footer">
              <?php if ($kpi_pendentes_importar > 0): ?>
                pedido<?= $kpi_pendentes_importar !== 1 ? 's' : '' ?>
                &nbsp;<a href="/yzidro/pedidos" style="color:var(--amber);font-weight:700;text-decoration:none;">Importar →</a>
              <?php elseif ($erp_ok): ?>
                <span class="up">✓ Tudo importado</span>
              <?php else: ?>
                <span class="source-badge src-off">ERP offline</span>
              <?php endif; ?>
              &nbsp;<span class="source-badge src-erp">ERP</span>
            </div>
          </div>
          <?php endif; ?>

          <?php if (dashW('kpi_local')): ?>
          <div class="kpi-card teal">
            <div class="kpi-header">
              <span class="kpi-label">Itens Finalizados</span>
              <div class="kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                  <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
              </div>
            </div>
            <div class="kpi-value"><?= fmtNum($kpi_finalizados) ?></div>
            <div class="kpi-footer">
              unidades finalizadas
              &nbsp;<a href="/pcp/kanban" style="color:var(--teal);font-weight:600;text-decoration:none;">Ver →</a>
              &nbsp;<span class="source-badge src-local">Local</span>
            </div>
          </div>

          <div class="kpi-card amber">
            <div class="kpi-header">
              <span class="kpi-label">Pendente de Produção</span>
              <div class="kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <circle cx="12" cy="12" r="10"/>
                  <polyline points="12 6 12 12 16 14"/>
                </svg>
              </div>
            </div>
            <div class="kpi-value"><?= fmtNum($kpi_a_produzir) ?></div>
            <div class="kpi-footer">
              unidades a produzir
              &nbsp;<a href="/pcp/kanban" style="color:var(--amber);font-weight:600;text-decoration:none;">Ver →</a>
              &nbsp;<span class="source-badge src-local">Local</span>
            </div>
          </div>
          <?php endif; ?>

          <?php if (dashW('kpi_faturamento')): ?>
          <div class="kpi-card blue">
            <div class="kpi-header">
              <span class="kpi-label">Faturamento do Mês</span>
              <div class="kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <line x1="12" y1="1" x2="12" y2="23"/>
                  <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
              </div>
            </div>
            <div class="kpi-value kpi-faturado"><?= fmtMoeda($kpi_faturado_mes) ?></div>
            <div class="kpi-footer">receita bruta do mês &nbsp;<span class="source-badge src-erp">ERP</span></div>
          </div>

          <div class="kpi-card teal">
            <div class="kpi-header">
              <span class="kpi-label">Ticket Médio</span>
              <div class="kpi-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                  <line x1="7" y1="7" x2="7.01" y2="7"/>
                </svg>
              </div>
            </div>
            <div class="kpi-value"><?= fmtMoeda($kpi_ticket_medio) ?></div>
            <div class="kpi-footer">por pedido no mês &nbsp;<span class="source-badge src-erp">ERP</span></div>
          </div>
          <?php endif; ?>

        </div>
        <?php
        $_kpi = ob_get_clean();
        foreach (['kpi_erp', 'kpi_local', 'kpi_faturamento'] as $_k) {
            if (dashW($_k)) { $_w[$_k] = $_kpi; break; }
        }
    }

    // ── Últimas Vendas ────────────────────────────────────────────────
    if (dashW('ultimas_vendas')) {
        ob_start(); ?>
        <div class="panel">
          <div class="panel-header">
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="panel-title">Últimas Vendas</span>
              <span class="source-badge src-erp">ERP</span>
            </div>
            <a class="panel-action" href="/vendas">Ver todas →</a>
          </div>
          <?php if (empty($ultimas_vendas)): ?>
          <div class="empty-row"><?= $erp_ok ? 'Nenhuma venda encontrada.' : '⚠ ERP offline — sem dados de vendas.' ?></div>
          <?php else: ?>
          <?php foreach ($ultimas_vendas as $v): ?>
          <div class="venda-row">
            <div class="venda-left">
              <span class="venda-num"><?= htmlspecialchars($v['pedido']) ?></span>
              <?php if ($v['grupo']): ?><span class="grupo-pill"><?= htmlspecialchars($v['grupo']) ?></span><?php endif; ?>
            </div>
            <div class="venda-mid">
              <div class="venda-cliente" title="<?= htmlspecialchars($v['cliente']) ?>"><?= htmlspecialchars(mb_substr($v['cliente'], 0, 35)) ?><?= mb_strlen($v['cliente']) > 35 ? '…' : '' ?></div>
            </div>
            <div class="venda-right">
              <span class="venda-qty"><?= number_format((int)$v['qtd_itens'], 0, ',', '.') ?> it.</span>
              <span class="venda-data"><?= htmlspecialchars($v['data']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php $_w['ultimas_vendas'] = ob_get_clean();
    }

    // ── Produção por Máquina — Hoje ───────────────────────────────────
    if (dashW('maquinas_hoje')) {
        ob_start(); ?>
        <div class="panel">
          <div class="panel-header">
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="panel-title">Produção por Máquina — Hoje</span>
              <span class="source-badge src-local">Local</span>
            </div>
            <a class="panel-action" href="/pcp/quadro">Quadro →</a>
          </div>
          <?php if (empty($top_maquinas)): ?>
          <div class="empty-row">Nenhum apontamento hoje.</div>
          <?php else: ?>
          <div class="maq-bar-row">
            <?php foreach ($top_maquinas as $mq):
                $pct = $max_prod_maq > 0 ? round($mq['total'] / $max_prod_maq * 100) : 0; ?>
            <div class="maq-bar-item">
              <div class="maq-bar-label">
                <span class="maq-bar-name" title="<?= htmlspecialchars($mq['maq_descricao']) ?>"><?= htmlspecialchars($mq['maq_descricao']) ?></span>
                <span class="maq-bar-val"><?= number_format((int)$mq['total'],0,',','.') ?> un</span>
              </div>
              <div class="maq-bar-bg"><div class="maq-bar-fill" style="width:<?= $pct ?>%;"></div></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php $_w['maquinas_hoje'] = ob_get_clean();
    }

    // ── Vendas por Grupo — Mês ────────────────────────────────────────
    if (dashW('vendas_grupo')) {
        ob_start(); ?>
        <div class="panel">
          <div class="panel-header">
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="panel-title">Vendas por Grupo — Mês</span>
              <span class="source-badge src-erp">ERP</span>
            </div>
            <a class="panel-action" href="/vendas">Ver →</a>
          </div>
          <?php if (empty($vendas_grupo)): ?>
          <div class="empty-row"><?= $erp_ok ? 'Nenhum dado disponível.' : '⚠ ERP offline.' ?></div>
          <?php else: $max_grupo_qtd = max(array_column($vendas_grupo,'qtd')); ?>
          <div class="maq-bar-row">
            <?php foreach ($vendas_grupo as $g):
                $pct = $max_grupo_qtd > 0 ? round($g['qtd'] / $max_grupo_qtd * 100) : 0; ?>
            <div class="maq-bar-item">
              <div class="maq-bar-label">
                <span class="maq-bar-name" title="<?= htmlspecialchars($g['grupo']) ?>"><?= htmlspecialchars($g['grupo']) ?></span>
                <span class="maq-bar-val"><?= (int)$g['qtd'] ?> ped.</span>
              </div>
              <div class="maq-bar-bg"><div class="maq-bar-fill" style="width:<?= $pct ?>%;background:linear-gradient(90deg,#1a6945,#00c9a7);"></div></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php $_w['vendas_grupo'] = ob_get_clean();
    }

    // ── Produção — Últimos 7 Dias ─────────────────────────────────────
    if (dashW('prod_7dias')) {
        ob_start(); ?>
        <div class="panel">
          <div class="panel-header">
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="panel-title">Produção — Últimos 7 Dias</span>
              <span class="source-badge src-local">Local</span>
            </div>
            <a class="panel-action" href="/pcp/producao">Apontamentos →</a>
          </div>
          <div class="chart-wrap"><canvas id="chartProd"></canvas></div>
        </div>
        <?php $_w['prod_7dias'] = ob_get_clean();
    }

    // ── Financeiro ────────────────────────────────────────────────────
    if (dashW('financeiro')) {
        ob_start(); ?>
        <div class="dash-grid" style="grid-template-columns:1fr 1fr;">
          <div class="panel">
            <div class="panel-header">
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="panel-title">Contas a Pagar</span>
                <?php if ($fin_from_erp): ?>
                <span class="source-badge src-erp">ERP</span>
                <?php else: ?>
                <span class="source-badge src-local">Local</span>
                <?php endif; ?>
              </div>
              <a class="panel-action" href="/financeiro/pagar">Ver →</a>
            </div>
            <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:12.5px;color:var(--text-muted);">Total em aberto</span>
                <span style="font-size:16px;font-weight:700;color:var(--text-primary);"><?= fmtMoeda($fin_pagar_total) ?></span>
              </div>
              <?php if ($fin_pagar_vencido > 0): ?>
              <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:8px;padding:10px 14px;">
                <span style="font-size:12px;color:var(--red);">Vencidos</span>
                <span style="font-size:14px;font-weight:700;color:var(--red);"><?= fmtMoeda($fin_pagar_vencido) ?></span>
              </div>
              <?php else: ?>
              <div style="font-size:12px;color:var(--teal);">✓ Nenhum vencido</div>
              <?php endif; ?>
            </div>
          </div>
          <div class="panel">
            <div class="panel-header">
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="panel-title">Contas a Receber</span>
                <?php if ($fin_from_erp): ?>
                <span class="source-badge src-erp">ERP</span>
                <?php else: ?>
                <span class="source-badge src-local">Local</span>
                <?php endif; ?>
              </div>
              <a class="panel-action" href="/financeiro/receber">Ver →</a>
            </div>
            <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:12.5px;color:var(--text-muted);">Total a receber</span>
                <span style="font-size:16px;font-weight:700;color:var(--teal);"><?= fmtMoeda($fin_receber_total) ?></span>
              </div>
              <?php if ($fin_receber_vencido > 0): ?>
              <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:8px;padding:10px 14px;">
                <span style="font-size:12px;color:var(--amber);">Vencidos</span>
                <span style="font-size:14px;font-weight:700;color:var(--amber);"><?= fmtMoeda($fin_receber_vencido) ?></span>
              </div>
              <?php else: ?>
              <div style="font-size:12px;color:var(--teal);">✓ Nenhum vencido</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php $_w['financeiro'] = ob_get_clean();
    }

    // ── Trello — Visão Geral ──────────────────────────────────────────
    if (dashW('trello_geral')) {
        ob_start(); ?>
        <div id="trello-geral-wrap" style="display:flex;flex-direction:column;">
          <div class="panel" style="flex:1;"><div style="padding:20px;color:var(--text-muted);font-size:13px;">Carregando…</div></div>
        </div>
        <?php $_w['trello_geral'] = ob_get_clean();
    }

    // ── Trello — Atividade ────────────────────────────────────────────
    if (dashW('trello_atividade')) {
        ob_start(); ?>
        <div id="trello-atividade-wrap" style="display:flex;flex-direction:column;">
          <div class="panel" style="flex:1;"><div style="padding:20px;color:var(--text-muted);font-size:13px;">Carregando…</div></div>
        </div>
        <?php $_w['trello_atividade'] = ob_get_clean();
    }

    // ── Renderiza em ordem com pareamento automático ──────────────────
    $_pairs = [
        'ultimas_vendas'   => ['maquinas_hoje',    '1fr 340px'],
        'maquinas_hoje'    => ['ultimas_vendas',   '340px 1fr'],
        'vendas_grupo'     => ['prod_7dias',       '320px 1fr'],
        'prod_7dias'       => ['vendas_grupo',     '1fr 320px'],
        'trello_geral'     => ['trello_atividade', '7fr 3fr'],
        'trello_atividade' => ['trello_geral',     '3fr 7fr'],
    ];
    $_active = array_values(array_filter($dashWidgets, fn($k) => isset($_w[$k])));
    $_skip   = [];
    ?>

    <div class="content">
    <?php
    for ($_i = 0; $_i < count($_active); $_i++) {
        $_key  = $_active[$_i];
        if (in_array($_key, $_skip, true)) continue;
        $_next = $_active[$_i + 1] ?? null;
        $_pair = $_pairs[$_key] ?? null;
        if ($_pair && $_next === $_pair[0]) {
            echo '<div class="dash-grid" style="grid-template-columns:' . $_pair[1] . ';">';
            echo $_w[$_key];
            echo $_w[$_next];
            echo '</div>';
            $_skip[] = $_next;
        } else {
            echo $_w[$_key];
        }
    }
    ?>
    </div><!-- /content -->

  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
// ── Data no topbar ─────────────────────────────────────────────────────
(function () {
  const dias  = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
  const meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  const d = new Date();
  document.getElementById('topbar-date').textContent =
    dias[d.getDay()] + ', ' + d.getDate() + ' de ' + meses[d.getMonth()] + ' de ' + d.getFullYear();
})();

// ── Trello Dashboard ───────────────────────────────────────────────────
(function () {
  const API     = '/modules/trello/api.php';
  const boardId = <?= json_encode($trello_default_board) ?>;
  let _bid   = boardId;
  let _bname = '';

  const wrapGeral = document.getElementById('trello-geral-wrap');
  const wrapAtiv  = document.getElementById('trello-atividade-wrap');
  if (!wrapGeral && !wrapAtiv) return;

  if (!boardId) {
    fetch(API + '?action=my_boards').then(r => r.json()).then(boards => {
      if (Array.isArray(boards) && boards.length) {
        _bid   = boards[0].id;
        _bname = boards[0].name;
        init();
      } else {
        setErr('Trello não configurado.');
      }
    }).catch(() => setErr('Trello não configurado.'));
    return;
  }
  init();

  function init() {
    if (wrapGeral) loadGeral();
    if (wrapAtiv)  loadAtividade();
  }

  // ── Visão Geral ───────────────────────────────────────────────────
  function loadGeral() {
    fetch(API + '?action=board_stats&boardId=' + encodeURIComponent(_bid))
      .then(r => r.json())
      .then(d => {
        if (d.error) { setErr('Trello: ' + d.error, wrapGeral); return; }

        const overdueColor = d.overdue   > 0 ? 'var(--red)'   : 'var(--teal)';
        const todayColor   = d.due_today > 0 ? 'var(--amber)' : 'var(--text-muted)';

        let bars = '';
        (d.lists || []).forEach(l => {
          const w = d.total > 0 ? Math.round(l.count / d.total * 100) : 0;
          bars += `
            <div class="maq-bar-item">
              <div class="maq-bar-label">
                <span class="maq-bar-name">${escH(l.name)}</span>
                <span class="maq-bar-val">${l.count}</span>
              </div>
              <div class="maq-bar-bg">
                <div class="maq-bar-fill" style="width:${w}%;background:linear-gradient(90deg,#5a3fc0,#9b6dff);"></div>
              </div>
            </div>`;
        });

        wrapGeral.innerHTML = `
          <div class="panel" style="flex:1;">
            <div class="panel-header">
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="panel-title">${_bname ? escH(_bname) : 'Trello'} — Visão Geral</span>
                <span class="source-badge" style="background:rgba(155,109,255,.12);color:#9b6dff;">Trello</span>
              </div>
              <a class="panel-action" href="/trello?board=${encodeURIComponent(_bid)}">Ver board →</a>
            </div>
            <div class="trello-inner-grid">
              <div class="maq-bar-row">
                ${bars || '<p style="color:var(--text-muted);font-size:13px;">Nenhum card aberto.</p>'}
              </div>
              <div style="display:flex;flex-direction:column;border-left:1px solid var(--border);">
                <div style="padding:10px 14px;border-bottom:1px solid var(--border);">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;">Total</div>
                  <div style="font-size:18px;font-weight:700;">${d.total}</div>
                  <div style="font-size:11px;color:var(--text-muted);">cards em aberto</div>
                </div>
                <div style="padding:10px 14px;border-bottom:1px solid var(--border);">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;">Vencidos</div>
                  <div style="font-size:18px;font-weight:700;color:${overdueColor};">${d.overdue}</div>
                  <div style="font-size:11px;color:var(--text-muted);">${d.overdue > 0 ? 'prazo ultrapassado' : 'nenhum atrasado'}</div>
                </div>
                <div style="padding:10px 14px;">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;">Vencem Hoje</div>
                  <div style="font-size:18px;font-weight:700;color:${todayColor};">${d.due_today}</div>
                  <div style="font-size:11px;color:var(--text-muted);">com prazo hoje</div>
                </div>
              </div>
            </div>
          </div>`;
      })
      .catch(() => setErr('Erro ao carregar.', wrapGeral));
  }

  // ── Atividade ─────────────────────────────────────────────────────
  function loadAtividade() {
    fetch(API + '?action=board_actions&boardId=' + encodeURIComponent(_bid) + '&limit=30')
      .then(r => r.json())
      .then(actions => {
        let body = '';
        if (!Array.isArray(actions) || !actions.length) {
          body = '<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">Nenhum movimento registrado.</div>';
        } else {
          let rows = '';
          actions.forEach(a => {
            rows += `
              <div style="display:flex;align-items:flex-start;gap:12px;padding:11px 20px;border-bottom:1px solid var(--border);">
                <div style="margin-top:3px;flex-shrink:0;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#9b6dff" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="9 18 15 12 9 6"/>
                  </svg>
                </div>
                <div style="flex:1;min-width:0;">
                  <div style="font-size:13px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                       title="${escH(a.card_name)}">${escH(a.card_name)}</div>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                    <span style="color:var(--text-primary);">${escH(a.list_before)}</span>
                    <span style="margin:0 5px;color:#9b6dff;">→</span>
                    <span style="color:var(--teal);">${escH(a.list_after)}</span>
                  </div>
                  ${a.membro ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">por ${escH(a.membro)}</div>` : ''}
                </div>
                <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;">${tempoAtras(a.date)}</div>
              </div>`;
          });
          body = `<div style="overflow-y:auto;max-height:240px;">${rows}</div>`;
        }

        wrapAtiv.innerHTML = `
          <div class="panel" style="flex:1;">
            <div class="panel-header">
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="panel-title">${_bname ? escH(_bname) : 'Trello'} — Atividade</span>
                <span class="source-badge" style="background:rgba(155,109,255,.12);color:#9b6dff;">Trello</span>
              </div>
              <a class="panel-action" href="/trello?board=${encodeURIComponent(_bid)}">Ver board →</a>
            </div>
            ${body}
          </div>`;
      })
      .catch(() => setErr('Erro ao carregar atividade.', wrapAtiv));
  }

  // ── Helpers ───────────────────────────────────────────────────────
  function setErr(msg, wrap) {
    const targets = wrap ? [wrap] : [wrapGeral, wrapAtiv].filter(Boolean);
    targets.forEach(el => {
      el.innerHTML = `<div class="panel"><div style="padding:20px;color:var(--text-muted);font-size:13px;">${escH(msg)}</div></div>`;
    });
  }
  function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function tempoAtras(iso) {
    const min = Math.round((Date.now() - new Date(iso)) / 60000);
    if (min < 1)  return 'agora';
    if (min < 60) return `há ${min} min`;
    const h = Math.floor(min / 60);
    if (h  < 24) return `há ${h}h`;
    const d = Math.floor(h / 24);
    return d === 1 ? 'ontem' : `há ${d} dias`;
  }
})();

// ── Gráfico produção 7 dias ────────────────────────────────────────────
(function () {
  const ctx = document.getElementById('chartProd');
  if (!ctx) return;

  const labels = <?= $js_dias_lbl ?>;
  const values = <?= $js_dias_val ?>;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Unidades produzidas',
        data: values,
        backgroundColor: labels.map((_l, i) => i === labels.length - 1 ? 'rgba(0,201,167,.85)' : 'rgba(45,106,255,.45)'),
        borderColor:     labels.map((_l, i) => i === labels.length - 1 ? '#00c9a7' : '#2d6aff'),
        borderWidth: 1,
        borderRadius: 5,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#152845',
          titleColor: '#e8edf5',
          bodyColor: '#7a90b0',
          callbacks: { label: ctx => ' ' + Number(ctx.raw).toLocaleString('pt-BR') + ' unidades' }
        }
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#7a90b0', font: { size: 11 } } },
        y: {
          grid: { color: 'rgba(255,255,255,.05)' },
          ticks: { color: '#7a90b0', font: { size: 11 }, callback: v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v }
        }
      }
    }
  });
})();
</script>
</body>
</html>
