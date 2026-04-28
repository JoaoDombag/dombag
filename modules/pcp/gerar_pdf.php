<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ── Data selecionada (passada via ?data= ou padrão = ontem) ───────────────────
$data_sel = (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data']))
    ? $_GET['data']
    : date('Y-m-d', strtotime('-1 day'));

$data_fmt = date('d/m/Y', strtotime($data_sel));
$dia_semana = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][date('w', strtotime($data_sel))];

// ── Banco ─────────────────────────────────────────────────────────────────────
try {
    $pdo = dbPDO();

    // Garante colunas adicionadas às MAQUINAS após a criação inicial da tabela
    foreach ([
        "ALTER TABLE MAQUINAS ADD COLUMN maq_qtde NUMERIC(6,2) NOT NULL DEFAULT 1",
        "ALTER TABLE MAQUINAS ADD COLUMN maq_producao_min NUMERIC(10,4) NOT NULL DEFAULT 0",
        "ALTER TABLE MAQUINAS ADD COLUMN maq_horas_dia NUMERIC(5,2) NOT NULL DEFAULT 8",
        "ALTER TABLE MAQUINAS ADD COLUMN maq_conta_producao TINYINT(1) NOT NULL DEFAULT 1",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (PDOException) {}
    }

    // 1. KPIs gerais do dia
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(pd.pd_quantidade), 0)                                   AS total_und,
            COUNT(*)                                                              AS apontamentos,
            COUNT(DISTINCT pd.maq_codigo)                                        AS maquinas_ativas,
            COUNT(DISTINCT pd.pd_funcionario)                                    AS funcionarios,
            SUM(TIMESTAMPDIFF(MINUTE,
                CONCAT('2000-01-01 ',pd.pd_horario_ini),
                CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini,'2000-01-02','2000-01-01'),' ',pd.pd_horario_fim)))  AS total_minutos,
            MIN(DATE_FORMAT(pd.pd_horario_ini,'%H:%i'))                          AS primeiro_turno,
            MAX(DATE_FORMAT(pd.pd_horario_fim,'%H:%i'))                          AS ultimo_turno
        FROM PRODUCAO_DIARIA pd
        WHERE pd.pd_data = :data
    ");
    $stmt->execute([':data' => $data_sel]);
    $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Comparativo dia anterior
    $data_ant = date('Y-m-d', strtotime($data_sel . ' -1 day'));
    $stmt_ant = $pdo->prepare('SELECT COALESCE(SUM(pd_quantidade),0) FROM PRODUCAO_DIARIA WHERE pd_data = :d');
    $stmt_ant->execute([':d' => $data_ant]);
    $total_ant = (float) $stmt_ant->fetchColumn();

    // 3. Produção por hora (global)
    $stmt_hora = $pdo->prepare("
        SELECT DATE_FORMAT(pd.pd_horario_ini,'%H:%i') AS hora,
               SUM(pd.pd_quantidade)                  AS qtd
        FROM PRODUCAO_DIARIA pd
        WHERE pd.pd_data = :data
        GROUP BY DATE_FORMAT(pd.pd_horario_ini,'%H:%i')
        ORDER BY hora
    ");
    $stmt_hora->execute([':data' => $data_sel]);
    $por_hora = $stmt_hora->fetchAll(PDO::FETCH_ASSOC);

    // 4. Resumo por máquina
    $stmt_maq = $pdo->prepare("
        SELECT
            pd.maq_codigo,
            m.maq_descricao,
            d.dp_descricao,
            COALESCE(m.maq_qtde,1)          AS qtde_maquinas,
            COALESCE(m.maq_horas_dia,8)     AS horas_dia,
            COALESCE(m.maq_producao_min,0)  AS prod_min_cad,
            SUM(pd.pd_quantidade)           AS total_und,
            COUNT(*)                        AS apontamentos,
            SUM(TIMESTAMPDIFF(MINUTE,
                CONCAT('2000-01-01 ',pd.pd_horario_ini),
                CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini,'2000-01-02','2000-01-01'),' ',pd.pd_horario_fim))) AS total_min,
            ROUND(SUM(pd.pd_quantidade) /
                NULLIF(SUM(TIMESTAMPDIFF(MINUTE,
                    CONCAT('2000-01-01 ',pd.pd_horario_ini),
                    CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini,'2000-01-02','2000-01-01'),' ',pd.pd_horario_fim))),0),4) AS prod_min_real,
            MIN(DATE_FORMAT(pd.pd_horario_ini,'%H:%i'))              AS hora_ini,
            MAX(DATE_FORMAT(pd.pd_horario_fim,'%H:%i'))              AS hora_fim,
            GROUP_CONCAT(DISTINCT pd.pd_funcionario
                ORDER BY pd.pd_funcionario SEPARATOR ', ')           AS funcionarios,
            GROUP_CONCAT(DISTINCT pd.pd_pedido
                ORDER BY pd.pd_pedido SEPARATOR ', ')                AS pedidos
        FROM PRODUCAO_DIARIA pd
        LEFT JOIN MAQUINAS m ON m.maq_codigo = pd.maq_codigo
        LEFT JOIN DEPARTAMENTOS d ON d.dp_codigo = m.dp_codigo
        WHERE pd.pd_data = :data
        GROUP BY pd.maq_codigo, m.maq_descricao, d.dp_descricao,
                 m.maq_qtde, m.maq_horas_dia, m.maq_producao_min
        ORDER BY total_und DESC
    ");
    $stmt_maq->execute([':data' => $data_sel]);
    $maquinas = $stmt_maq->fetchAll(PDO::FETCH_ASSOC);

    // Meta total do dia (soma das capacidades das máquinas)
    $total_meta_dia = 0;
    foreach ($maquinas as $m) {
        $total_meta_dia += (float) $m['qtde_maquinas'] * (float) $m['prod_min_cad'] * 60 * (float) $m['horas_dia'];
    }
    $efic_global = $total_meta_dia > 0
        ? min(999, round((float) ($kpi['total_und'] ?? 0) / $total_meta_dia * 100, 1))
        : null;

    // 5. Apontamentos individuais (para detalhe por máquina)
    $stmt_det = $pdo->prepare("
        SELECT
            pd.maq_codigo,
            DATE_FORMAT(pd.pd_horario_ini,'%H:%i')  AS ini,
            DATE_FORMAT(pd.pd_horario_fim,'%H:%i')  AS fim,
            pd.pd_quantidade                         AS qtd,
            pd.pd_funcionario                        AS func,
            pd.pd_pedido                             AS pedido,
            pd.pd_comentario                         AS obs,
            TIMESTAMPDIFF(MINUTE,
                CONCAT('2000-01-01 ',pd.pd_horario_ini),
                CONCAT(IF(pd.pd_horario_fim < pd.pd_horario_ini,'2000-01-02','2000-01-01'),' ',pd.pd_horario_fim))  AS minutos
        FROM PRODUCAO_DIARIA pd
        WHERE pd.pd_data = :data
        ORDER BY pd.maq_codigo, pd.pd_horario_ini
    ");
    $stmt_det->execute([':data' => $data_sel]);
    $det_raw = $stmt_det->fetchAll(PDO::FETCH_ASSOC);
    $detalhes = [];
    foreach ($det_raw as $r) {
        $detalhes[$r['maq_codigo']][] = $r;
    }

} catch (Exception $e) {
    die('Erro: ' . htmlspecialchars($e->getMessage()));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtMin(int $m): string
{
    return floor($m / 60) . 'h ' . str_pad($m % 60, 2, '0', STR_PAD_LEFT) . 'min';
}
function fmtN(float $n): string
{
    return number_format($n, 0, ',', '.');
}
function capClr(float $p): string
{
    return $p >= 90 ? '#059669' : ($p >= 60 ? '#d97706' : '#dc2626');
}

$total_und = (float) ($kpi['total_und'] ?? 0);
$total_apt = (int) ($kpi['apontamentos'] ?? 0);
$maq_ativas = (int) ($kpi['maquinas_ativas'] ?? 0);
$total_min_dia = (int) ($kpi['total_minutos'] ?? 0);
$variacao = $total_ant > 0 ? (($total_und - $total_ant) / $total_ant * 100) : null;
$max_hora_qtd = !empty($por_hora) ? max(array_column($por_hora, 'qtd')) : 1;

// ── HTML ──────────────────────────────────────────────────────────────────────
ob_start();
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 14mm 12mm 12mm 12mm; }
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: Helvetica, Arial, sans-serif;
  font-size: 8.5px;
  color: #0f172a;
  background: #f1f5f9;
  line-height: 1.35;
}

/* Banner superior */
.top-banner {
  width: 100%;
  display: table;
  background: #0f172a;
  padding: 11px 14px;
  margin-bottom: 0;
  border-radius: 8px 8px 0 0;
}
.tb-l, .tb-r { display: table-cell; vertical-align: middle; }
.tb-r { text-align: right; }
.logo {
  font-size: 24px;
  font-weight: 900;
  color: #ffffff;
  letter-spacing: -.6px;
  line-height: 1;
}
.logo span { color: #3b82f6; }
.logo-sub {
  font-size: 6.5px;
  color: #64748b;
  letter-spacing: 1.3px;
  text-transform: uppercase;
  margin-top: 2px;
}
.banner-date {
  color: #ffffff;
  font-size: 14px;
  font-weight: 800;
  letter-spacing: -.3px;
  line-height: 1.1;
}
.banner-sub {
  font-size: 7px;
  color: #64748b;
  margin-top: 3px;
  letter-spacing: .3px;
}

/* Sub-header */
.sub-hdr {
  width: 100%;
  display: table;
  background: #1e3a8a;
  padding: 6px 14px;
  margin-bottom: 14px;
  border-radius: 0 0 6px 6px;
}
.sh-l, .sh-r { display: table-cell; vertical-align: middle; }
.sh-r { text-align: right; }
.doc-title {
  font-size: 8px;
  font-weight: 800;
  color: #bfdbfe;
  text-transform: uppercase;
  letter-spacing: 1.2px;
}
.meta-inline {
  font-size: 6.8px;
  color: #93c5fd;
}

.summary-row {
  width: 100%;
  display: table;
  border-collapse: separate;
  border-spacing: 6px 0;
  margin-bottom: 13px;
}
.summary-cell { display: table-cell; width: 25%; }
.summary-box {
  border: 1px solid #e2e8f0;
  border-left: 3px solid #2563eb;
  background: #ffffff;
  border-radius: 7px;
  padding: 7px 9px;
}
.summary-lbl {
  font-size: 6.4px;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .55px;
  font-weight: 700;
  margin-bottom: 2px;
}
.summary-val {
  font-size: 9.2px;
  font-weight: 800;
  color: #0f172a;
}

/* KPIs */
.kpi-row {
  display: table;
  width: 100%;
  border-collapse: separate;
  border-spacing: 7px 0;
  margin-bottom: 14px;
}
.kpi-cell { display: table-cell; width: 16.66%; }
.kpi-box {
  position: relative;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 11px 9px;
  background: #ffffff;
  overflow: hidden;
}
.kpi-box::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 4px;
}
.kpi-box::after {
  content: '';
  position: absolute;
  right: -18px;
  bottom: -18px;
  width: 58px;
  height: 58px;
  border-radius: 999px;
  background: rgba(59,130,246,.06);
}
.k-blue   { background: #eff6ff; border-color: #bfdbfe; }
.k-teal   { background: #f0fdf4; border-color: #bbf7d0; }
.k-amber  { background: #fffbeb; border-color: #fde68a; }
.k-purple { background: #faf5ff; border-color: #e9d5ff; }
.k-green  { background: #f0fdf4; border-color: #bbf7d0; }
.k-red    { background: #fef2f2; border-color: #fecaca; }
.k-blue::before   { background: #2563eb; }
.k-teal::before   { background: #0f766e; }
.k-amber::before  { background: #d97706; }
.k-purple::before { background: #7c3aed; }
.k-green::before  { background: #16a34a; }
.k-red::before    { background: #dc2626; }
.kpi-lbl {
  position: relative;
  z-index: 2;
  font-size: 6.7px;
  text-transform: uppercase;
  letter-spacing: .7px;
  color: #64748b;
  font-weight: 700;
  margin-bottom: 5px;
}
.kpi-val {
  position: relative;
  z-index: 2;
  font-size: 17px;
  font-weight: 900;
  letter-spacing: -.7px;
  color: #0f172a;
  line-height: 1.02;
}
.kpi-sub {
  position: relative;
  z-index: 2;
  font-size: 6.8px;
  color: #94a3b8;
  margin-top: 4px;
}

/* Section */
.sec {
  margin: 15px 0 8px;
  padding: 7px 10px 7px 13px;
  border-left: 4px solid #3b82f6;
  background: #1e3a8a;
  border-radius: 5px;
  font-size: 7.5px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .9px;
  color: #ffffff;
}

/* Tabela por hora */
.tbl, .sum-tbl, .det {
  width: 100%;
  border-collapse: collapse;
}
.tbl { margin-bottom: 2px; }
.tbl th {
  padding: 6px 8px;
  background: #0f172a;
  border: 1px solid #0f172a;
  color: #ffffff;
  font-size: 6.8px;
  text-transform: uppercase;
  letter-spacing: .55px;
  font-weight: 800;
  text-align: center;
}
.tbl td {
  padding: 5px 7px;
  border: 1px solid #e2e8f0;
  font-size: 8px;
  background: #ffffff;
}
.tbl tbody tr:nth-child(even) td { background: #f8fafc; }
.tbl .c { text-align: center; }
.tbl .r { text-align: right; font-weight: 700; }

.bar-bg {
  display: inline-block;
  width: 92px;
  height: 8px;
  border-radius: 999px;
  background: #dbeafe;
  overflow: hidden;
  vertical-align: middle;
}
.bar-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #2563eb;
}

/* Tabela resumo máquinas */
.sum-tbl {
  margin-bottom: 2px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  overflow: hidden;
}
.sum-tbl th {
  padding: 7px 8px;
  background: #0f172a;
  border: 1px solid #0f172a;
  color: #ffffff;
  font-size: 6.7px;
  text-transform: uppercase;
  letter-spacing: .55px;
  font-weight: 800;
}
.sum-tbl td {
  padding: 5px 7px;
  border: 1px solid #e2e8f0;
  font-size: 7.8px;
  vertical-align: middle;
  background: #ffffff;
}
.sum-tbl tbody tr:nth-child(even) td { background: #f8fafc; }
.sum-tbl .r { text-align: right; font-weight: 700; }
.sum-tbl .c { text-align: center; }
.sum-tbl .maq-n { font-weight: 800; color: #1e3a8a; }
.sum-tbl .dp { font-size: 6.9px; color: #64748b; }
.sum-tbl .tot-r td {
  background: #0f172a !important;
  color: #ffffff;
  font-weight: 800;
}
.ok  { color: #059669; font-weight: 800; }
.bad { color: #dc2626; font-weight: 800; }
.na  { color: #94a3b8; }
.cap-bg {
  display: inline-block;
  width: 46px;
  height: 6px;
  background: #e2e8f0;
  border-radius: 999px;
  vertical-align: middle;
  overflow: hidden;
  margin-right: 3px;
}
.cap-fill { display: block; height: 100%; border-radius: 999px; }

/* Blocos por máquina */
.blk {
  margin-bottom: 12px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  overflow: hidden;
  page-break-inside: avoid;
  background: #ffffff;
}
.blk-hd {
  width: 100%;
  display: table;
  padding: 9px 13px;
  background: #0f172a;
  color: #ffffff;
}
.blk-hd-l, .blk-hd-r { display: table-cell; vertical-align: middle; }
.blk-hd-r { text-align: right; }
.blk-name {
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: -.2px;
}
.blk-dep {
  display: inline-block;
  padding: 2px 7px;
  margin-left: 6px;
  border-radius: 999px;
  background: #2563eb;
  font-size: 6.7px;
  font-weight: 700;
}
.blk-meta {
  font-size: 6.8px;
  color: #94a3b8;
}

.blk-kpis {
  width: 100%;
  display: table;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fbff;
}
.bk {
  display: table-cell;
  width: 16.66%;
  padding: 7px 8px;
  text-align: center;
  border-right: 1px solid #e2e8f0;
}
.bk:last-child { border-right: none; }
.bk-lbl {
  font-size: 6.2px;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 700;
  margin-bottom: 3px;
}
.bk-val {
  font-size: 12px;
  font-weight: 900;
  color: #0f172a;
  line-height: 1.05;
}
.bk-sub {
  font-size: 6.3px;
  color: #94a3b8;
  margin-top: 2px;
}

/* Tabela detalhe */
.det th {
  padding: 6px 8px;
  background: #1e293b;
  border-bottom: 1px solid #1e293b;
  color: #e2e8f0;
  font-size: 6.6px;
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 800;
}
.det td {
  padding: 5px 8px;
  border-bottom: 1px solid #edf2f7;
  font-size: 7.4px;
  vertical-align: middle;
  background: #ffffff;
}
.det tbody tr:nth-child(even) td { background: #f8fbff; }
.det tr:last-child td { border-bottom: none; }
.det .r { text-align: right; }
.det .c { text-align: center; }
.det .qv {
  font-size: 8.9px;
  font-weight: 900;
  color: #1e3a8a;
}
.det .obs { color: #64748b; font-style: italic; }
.blk-ft {
  padding: 6px 13px;
  border-top: 2px solid #2563eb;
  background: #eff6ff;
  color: #1e3a8a;
  font-size: 7px;
}
.empty-state {
  text-align: center;
  padding: 38px 0;
  color: #94a3b8;
  font-size: 10.5px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #fafcff;
}

/* Rodapé */
.footer {
  width: 100%;
  display: table;
  margin-top: 18px;
  padding-top: 8px;
  border-top: 2px solid #1e3a8a;
}
.footer-l, .footer-r {
  display: table-cell;
  font-size: 7px;
  color: #64748b;
}
.footer-r { text-align: right; }
</style>
</head>
<body>

<!-- ─ BANNER ────────────────────────────────────────────────────────────── -->
<div class="top-banner">
  <div class="tb-l">
    <div class="logo">DOM<span>BAG</span></div>
    <div class="logo-sub">Sistema de Gestão Industrial</div>
  </div>
  <div class="tb-r">
    <div class="banner-date"><?= $dia_semana ?>, <?= $data_fmt ?></div>
    <div class="banner-sub">Relatório de Produção Diária</div>
  </div>
</div>
<div class="sub-hdr">
  <div class="sh-l">
    <span class="doc-title">Producao Diaria — Dom Bag Ltda</span>
  </div>
  <div class="sh-r">
    <span class="meta-inline">Gerado em <?= date('d/m/Y') ?> às <?= date('H:i') ?> &nbsp;·&nbsp; Sistema DOMBAG</span>
  </div>
</div>

<div class="summary-row">
  <div class="summary-cell">
    <div class="summary-box" style="border-left-color:#2563eb;">
      <div class="summary-lbl">Referência</div>
      <div class="summary-val"><?= $dia_semana ?>, <?= $data_fmt ?></div>
    </div>
  </div>
  <div class="summary-cell">
    <div class="summary-box" style="border-left-color:#7c3aed;">
      <div class="summary-lbl">Janela do Turno</div>
      <div class="summary-val"><?= $kpi['primeiro_turno'] ?? '—' ?> até <?= $kpi['ultimo_turno'] ?? '—' ?></div>
    </div>
  </div>
  <div class="summary-cell">
    <div class="summary-box" style="border-left-color:#0f766e;">
      <div class="summary-lbl">Funcionários</div>
      <div class="summary-val"><?= (int) ($kpi['funcionarios'] ?? 0) ?> operador(es)</div>
    </div>
  </div>
  <div class="summary-cell">
    <div class="summary-box" style="border-left-color:<?= $total_und > 0 ? '#16a34a' : '#dc2626' ?>;">
      <div class="summary-lbl">Status do Dia</div>
      <div class="summary-val" style="color:<?= $total_und > 0 ? '#16a34a' : '#dc2626' ?>;"><?= $total_und > 0 ? 'Producao registrada' : 'Sem producao apontada' ?></div>
    </div>
  </div>
</div>

<!-- ─ KPIs DO DIA ─────────────────────────────────────────────────────────── -->
<?php
$dif_meta = $total_meta_dia > 0 ? ($total_und - $total_meta_dia) : null;
$efic_clr = $efic_global === null ? '#64748b' : ($efic_global >= 90 ? '#059669' : ($efic_global >= 60 ? '#d97706' : '#dc2626'));
$efic_klass = $efic_global === null ? 'k-blue' : ($efic_global >= 90 ? 'k-green' : ($efic_global >= 60 ? 'k-amber' : 'k-red'));
?>
<div class="kpi-row">
  <div class="kpi-cell">
    <div class="kpi-box k-teal">
      <div class="kpi-lbl">Total Produzido</div>
      <div class="kpi-val"><?= fmtN($total_und) ?></div>
      <div class="kpi-sub">unidades no dia</div>
    </div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-box k-blue">
      <div class="kpi-lbl">Meta do Dia</div>
      <div class="kpi-val"><?= $total_meta_dia > 0 ? fmtN($total_meta_dia) : '—' ?></div>
      <div class="kpi-sub">capacidade total</div>
    </div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-box <?= $efic_klass ?>">
      <div class="kpi-lbl">Eficiência Global</div>
      <div class="kpi-val" style="color:<?= $efic_clr ?>; font-size:15px;"><?= $efic_global !== null ? number_format($efic_global, 1, ',', '.') . '%' : '—' ?></div>
      <div class="kpi-sub"><?= $dif_meta !== null ? ($dif_meta >= 0 ? '+' : '') . fmtN($dif_meta) . ' un vs meta' : 'sem meta cadastrada' ?></div>
    </div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-box k-amber">
      <div class="kpi-lbl">Máquinas Ativas</div>
      <div class="kpi-val"><?= $maq_ativas ?></div>
      <div class="kpi-sub"><?= $total_apt ?> apontamentos</div>
    </div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-box k-purple">
      <div class="kpi-lbl">Tempo Total</div>
      <div class="kpi-val"><?= $total_min_dia > 0 ? floor($total_min_dia / 60) . 'h' . str_pad($total_min_dia % 60, 2, '0', STR_PAD_LEFT) : '—' ?></div>
      <div class="kpi-sub"><?= $total_min_dia > 0 ? fmtMin($total_min_dia) : '' ?></div>
    </div>
  </div>
  <div class="kpi-cell">
    <?php if ($variacao !== null): $vc = $variacao >= 0 ? 'k-green' : 'k-red'; ?>
    <div class="kpi-box <?= $vc ?>">
      <div class="kpi-lbl">Var. Dia Anterior</div>
      <div class="kpi-val" style="color:<?= $variacao >= 0 ? '#059669' : '#dc2626' ?>; font-size:15px;">
        <?= ($variacao >= 0 ? '+' : '') . number_format($variacao, 1, ',', '.') ?>%
      </div>
      <div class="kpi-sub">ontem: <?= fmtN($total_ant) ?> un</div>
    </div>
    <?php else: ?>
    <div class="kpi-box k-blue">
      <div class="kpi-lbl">Operadores</div>
      <div class="kpi-val"><?= (int) ($kpi['funcionarios'] ?? 0) ?></div>
      <div class="kpi-sub"><?= $kpi['primeiro_turno'] ?? '—' ?> – <?= $kpi['ultimo_turno'] ?? '—' ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ─ PRODUÇÃO POR HORA ───────────────────────────────────────────────────── -->
<?php if (!empty($por_hora)): ?>
<div class="sec">Distribuição por Turno / Hora</div>
<table class="tbl">
  <thead>
    <tr>
      <th>Hora</th>
      <th>Unidades</th>
      <th style="width:110px;">Distribuição</th>
      <th>% do Dia</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($por_hora as $h):
        $q = (float) $h['qtd'];
        $pct_h = $total_und > 0 ? $q / $total_und * 100 : 0;
        $bw = $max_hora_qtd > 0 ? round($q / $max_hora_qtd * 88) : 0;
        ?>
    <tr>
      <td style="font-weight:700;"><?= $h['hora'] ?></td>
      <td class="r" style="font-weight:800;color:#1e3a8a;"><?= fmtN($q) ?></td>
      <td class="c">
        <span class="bar-bg"><span class="bar-fill" style="width:<?= $bw ?>px;"></span></span>
      </td>
      <td class="c"><?= number_format($pct_h, 1, ',', '.') ?>%</td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- ─ RESUMO POR MÁQUINA ──────────────────────────────────────────────────── -->
<?php if (!empty($maquinas)): ?>
<div class="sec">Resumo por Máquina</div>
<table class="sum-tbl">
  <thead>
    <tr>
      <th>Máquina</th>
      <th>Depto</th>
      <th class="r">Real</th>
      <th class="r">Meta Dia</th>
      <th class="r">Esp. Tempo</th>
      <th class="c">vs Meta</th>
      <th class="r">Prod/min Real</th>
      <th class="r">Prod/min Cad.</th>
      <th class="c">Cap.</th>
      <th class="c">Horário</th>
      <th>Pedidos</th>
      <th>Operadores</th>
    </tr>
  </thead>
  <tbody>
    <?php $soma_und = 0;
    $soma_min = 0;
    $soma_meta = 0;
    $soma_esp  = 0;
    foreach ($maquinas as $m):
        $und = (float) $m['total_und'];
        $min = (int) $m['total_min'];
        $real = (float) $m['prod_min_real'];
        $cad = (float) $m['prod_min_cad'];
        $soma_und += $und;
        $soma_min += $min;
        $cap_max = (float) $m['qtde_maquinas'] * $cad * 60 * (float) $m['horas_dia'];
        $esp_tempo = $cad * $min;
        $soma_meta += $cap_max;
        $soma_esp  += $esp_tempo;
        $pct_cap = $cap_max > 0 ? min(100, round($und / $cap_max * 100, 1)) : null;
        $delta = $cad > 0 ? (($real - $cad) / $cad * 100) : null;
        $dif_m = $cap_max > 0 ? ($und - $cap_max) : null;
        ?>
    <tr>
      <td class="maq-n"><?= htmlspecialchars($m['maq_descricao']) ?></td>
      <td class="dp"><?= htmlspecialchars($m['dp_descricao'] ?? '—') ?></td>
      <td class="r" style="font-weight:800;color:#1e3a8a;"><?= fmtN($und) ?></td>
      <td class="r" style="color:#64748b;"><?= $cap_max > 0 ? fmtN($cap_max) : '—' ?></td>
      <td class="r" style="color:#64748b;"><?= $esp_tempo > 0 ? fmtN($esp_tempo) : '—' ?></td>
      <td class="c">
        <?php if ($dif_m === null): ?><span class="na">—</span>
        <?php elseif ($dif_m >= 0): ?><span class="ok">+<?= fmtN($dif_m) ?></span>
        <?php else: ?><span class="bad"><?= fmtN($dif_m) ?></span>
        <?php endif; ?>
      </td>
      <td class="r"><?= $real > 0 ? number_format($real, 2, ',', '.') : '—' ?></td>
      <td class="r" style="color:#94a3b8;"><?= $cad > 0 ? number_format($cad, 2, ',', '.') : '—' ?></td>
      <td class="c">
        <?php if ($pct_cap !== null): ?>
          <span class="cap-bg"><span class="cap-fill" style="width:<?= round($pct_cap) ?>%;background:<?= capClr($pct_cap) ?>;"></span></span><?= number_format($pct_cap, 1, ',', '.')?>%
        <?php else: ?><span class="na">—</span><?php endif; ?>
      </td>
      <td class="c"><?= htmlspecialchars(($m['hora_ini'] ?? '—') . ' – ' . ($m['hora_fim'] ?? '—')) ?></td>
      <td style="font-size:7px;"><?= htmlspecialchars(mb_substr($m['pedidos'] ?? '—', 0, 25)) ?></td>
      <td style="font-size:7px;"><?= htmlspecialchars(mb_substr($m['funcionarios'] ?? '—', 0, 30)) ?></td>
    </tr>
    <?php endforeach;
    $dif_total = $soma_meta > 0 ? ($soma_und - $soma_meta) : null;
    $efic_r = $soma_meta > 0 ? min(999, round($soma_und / $soma_meta * 100, 1)) : null;
    ?>
    <tr class="tot-r">
      <td colspan="2" style="text-align:right;">TOTAL GERAL</td>
      <td class="r"><?= fmtN($soma_und) ?> un</td>
      <td class="r"><?= $soma_meta > 0 ? fmtN($soma_meta) : '—' ?></td>
      <td class="r"><?= $soma_esp > 0 ? fmtN($soma_esp) : '—' ?></td>
      <td class="c">
        <?php if ($dif_total !== null): ?>
          <?= $dif_total >= 0 ? '+' : '' ?><?= fmtN($dif_total) ?> un
        <?php else: ?>—<?php endif; ?>
      </td>
      <td colspan="2" style="font-size:7px; text-align:center;">
        Eficiência: <?= $efic_r !== null ? number_format($efic_r, 1, ',', '.') . '%' : '—' ?>
      </td>
      <td colspan="4"></td>
    </tr>
  </tbody>
</table>
<?php endif; ?>

<!-- ─ DETALHE POR MÁQUINA ────────────────────────────────────────────────── -->
<div class="sec" style="margin-top:18px;">Detalhamento por Máquina — Apontamentos Individuais</div>

<?php foreach ($maquinas as $m):
    $und = (float) $m['total_und'];
    $min = (int) $m['total_min'];
    $real = (float) $m['prod_min_real'];
    $cad = (float) $m['prod_min_cad'];
    $cap_max = (float) $m['qtde_maquinas'] * $cad * 60 * (float) $m['horas_dia'];
    $esp_t   = $cad * $min;
    $pct_cap = $cap_max > 0 ? min(100, round($und / $cap_max * 100, 1)) : null;
    $delta = $cad > 0 ? (($real - $cad) / $cad * 100) : null;
    $dif_blk = $cap_max > 0 ? ($und - $cap_max) : null;
    $linhas = $detalhes[$m['maq_codigo']] ?? [];
    ?>
<div class="blk">

  <!-- Cabeçalho do bloco -->
  <div class="blk-hd">
    <div class="blk-hd-l">
      <span class="blk-name"><?= htmlspecialchars($m['maq_descricao']) ?></span>
      <?php if (!empty($m['dp_descricao'])): ?>
        <span class="blk-dep"><?= htmlspecialchars($m['dp_descricao']) ?></span>
      <?php endif; ?>
    </div>
    <div class="blk-hd-r">
      <span class="blk-meta">
        Turno: <?= htmlspecialchars(($m['hora_ini'] ?? '—') . ' – ' . ($m['hora_fim'] ?? '—')) ?>
        &nbsp;·&nbsp; <?= $m['qtde_maquinas'] ?> máq. · <?= $m['horas_dia'] ?>h/dia
      </span>
    </div>
  </div>

  <!-- KPIs inline do bloco -->
  <div class="blk-kpis">
    <div class="bk" style="background:#f0fdf4;">
      <div class="bk-lbl">Real</div>
      <div class="bk-val" style="color:#0f766e;"><?= fmtN($und) ?></div>
      <div class="bk-sub">unidades produzidas</div>
    </div>
    <div class="bk">
      <div class="bk-lbl">Meta Dia</div>
      <div class="bk-val" style="font-size:10px;color:#1e3a8a;"><?= $cap_max > 0 ? fmtN($cap_max) : '—' ?></div>
      <div class="bk-sub">capacidade máx.</div>
    </div>
    <div class="bk">
      <div class="bk-lbl">Esp. no Tempo</div>
      <div class="bk-val" style="font-size:10px;color:#64748b;"><?= $esp_t > 0 ? fmtN($esp_t) : '—' ?></div>
      <div class="bk-sub">pelo tempo trab.</div>
    </div>
    <div class="bk">
      <div class="bk-lbl">vs Meta</div>
      <div class="bk-val" style="font-size:10px; color:<?= $dif_blk === null ? '#94a3b8' : ($dif_blk >= 0 ? '#059669' : '#dc2626') ?>;">
        <?= $dif_blk === null ? '—' : ($dif_blk >= 0 ? '+' : '') . fmtN($dif_blk) ?>
      </div>
      <div class="bk-sub">diferença un</div>
    </div>
    <div class="bk">
      <div class="bk-lbl">Prod/min Real</div>
      <div class="bk-val"><?= $real > 0 ? number_format($real, 2, ',', '.') : '—' ?></div>
      <div class="bk-sub">cad: <?= $cad > 0 ? number_format($cad, 2, ',', '.') : '—' ?></div>
    </div>
    <div class="bk">
      <div class="bk-lbl">Cap. Utilizada</div>
      <div class="bk-val" style="font-size:10px; color:<?= $pct_cap !== null ? capClr($pct_cap) : '#94a3b8' ?>;">
        <?= $pct_cap !== null ? number_format($pct_cap, 1, ',', '.') . '%' : '—' ?>
      </div>
      <div class="bk-sub"><?= $delta !== null ? ($delta >= 0 ? '+' : '') . number_format($delta, 1, ',', '.') . '% perf.' : 'sem velocidade' ?></div>
    </div>
  </div>

  <!-- Apontamentos individuais -->
  <?php if (!empty($linhas)): ?>
  <table class="det">
    <thead>
      <tr>
        <th>Início</th>
        <th>Fim</th>
        <th class="r">Quantidade</th>
        <th>Pedido / OP</th>
        <th>Funcionário</th>
        <th class="c">Duração</th>
        <th>Observação</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($linhas as $l): ?>
      <tr>
        <td style="font-weight:700;"><?= $l['ini'] ?></td>
        <td><?= $l['fim'] ?></td>
        <td class="r qv"><?= fmtN((float) $l['qtd']) ?></td>
        <td><?= htmlspecialchars($l['pedido'] ?: '—') ?></td>
        <td><?= htmlspecialchars($l['func'] ?: '—') ?></td>
        <td class="c"><?= (int) $l['minutos'] > 0 ? (int) $l['minutos'] . 'min' : '—' ?></td>
        <td class="obs"><?= htmlspecialchars(mb_substr($l['obs'] ?? '', 0, 50)) . (mb_strlen($l['obs'] ?? '') > 50 ? '…' : '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="padding:8px 10px; font-size:7.5px; color:#94a3b8; font-style:italic;">Nenhum apontamento individual encontrado.</p>
  <?php endif; ?>

  <!-- Rodapé do bloco -->
  <div class="blk-ft">
    <strong>Operadores:</strong> <?= htmlspecialchars($m['funcionarios'] ?: '—') ?>
    &nbsp;&nbsp;·&nbsp;&nbsp;
    <strong>Pedidos / OPs:</strong> <?= htmlspecialchars($m['pedidos'] ?: '—') ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($maquinas)): ?>
<div class="empty-state">
  Nenhum apontamento de produção registrado em <?= $data_fmt ?>.
</div>
<?php endif; ?>

<!-- ─ RODAPÉ ──────────────────────────────────────────────────────────────── -->
<div class="footer">
  <div class="footer-l">DOMBAG Ltda — Relatório de Produção Diária</div>
  <div class="footer-r">Referência: <?= $data_fmt ?> &nbsp;·&nbsp; Gerado em <?= date('d/m/Y \à\s H:i') ?></div>
</div>

</body>
</html>
<?php
    $html = ob_get_clean();

// ── Renderiza PDF ─────────────────────────────────────────────────────────────
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');
$options->set('isPhpEnabled', false);

$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();

$nome = 'DOMBAG_Producao_' . str_replace('-', '', $data_sel) . '.pdf';
$pdf->stream($nome, ['Attachment' => false]);
