<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ── Export CSV (antes de qualquer output HTML) ────────────────────────────────
$exportCsv = (!empty($_GET['export']) && $_GET['export'] === 'csv');

// ── Filtros ───────────────────────────────────────────────────────────────────
$filtro_codigo     = trim((string) ($_GET['codigo']      ?? ''));
$filtro_produto    = trim((string) ($_GET['produto']     ?? ''));   // descrição
$filtro_local      = trim((string) ($_GET['local']       ?? ''));   // '7', '2' ou ''
$filtro_bloqueio   = trim((string) ($_GET['bloqueio']    ?? ''));   // 'SIM', 'NAO' ou ''
$filtro_grupo      = trim((string) ($_GET['grupo']       ?? ''));
$filtro_qtd_status = trim((string) ($_GET['qtd_status']  ?? ''));   // 'positivo','negativo','alerta'
$filtro_qtd_abaixo = isset($_GET['qtd_abaixo']) && $_GET['qtd_abaixo'] !== ''
                     ? (float)$_GET['qtd_abaixo'] : null;

$erp_ok  = true;
$erp_msg = '';
$rows    = [];

// ── Monta filtro de local dinamicamente ───────────────────────────────────────
$locais_sql = match($filtro_local) {
    '7'     => 'AND ELC.ELC_CODIGO IN (7)',
    '2'     => 'AND ELC.ELC_CODIGO IN (2)',
    default => 'AND ELC.ELC_CODIGO IN (7, 2)',
};

$sql = "
SELECT P.PRO_CODIGO
      ,P.PRO_CODIGO_OR
      ,P.PRO_DESCRICAO
      ,P.PRO_UNIDADE
      ,GP.GRP_CODIGO
      ,GP.GRP_DESCRICAO
      ,SB.SUB_DESCRICAO
      ,(GP.GRP_DESCRICAO || ' > ' || SB.SUB_DESCRICAO)::VARCHAR(70)          AS GRUPO_SUBGRUPO
      ,ELC.ELC_DESCRICAO
      ,COALESCE(ELS.ELS_UP_SALDO,      0)::NUMERIC(19,4)                      AS UP_SALDO
      ,COALESCE(ELS.ELS_UP_RESERVADO,  0)::NUMERIC(19,4)                      AS UP_RESERVADO
      ,COALESCE(ELS.ELS_UP_DISPONIVEL, 0)::NUMERIC(19,4)                      AS UP_DISPONIVEL
      ,COALESCE(P.PRO_ESTOQUE_MINIMO,  0)::NUMERIC(18,4)                      AS PRO_ESTOQUE_MINIMO
      ,COALESCE(P.PRO_PRECO_CUSTO,     0)::NUMERIC(18,4)                      AS PRO_PRECO_CUSTO
      ,COALESCE(P.PRO_PRECO_VENDA,     0)::NUMERIC(18,4)                      AS PRO_PRECO_VENDA
      ,IIF(P.PRO_PRECO_VENDA = 0.00
          ,0::FLOAT
          ,(((P.PRO_PRECO_VENDA - P.PRO_PRECO_CUSTO) /
             P.PRO_PRECO_VENDA) * 100))::NUMERIC(18,2)                        AS MARGEM_BRUTA
      ,COALESCE(ELS.ELS_UP_SALDO * P.PRO_PRECO_CUSTO, 0)::NUMERIC(18,2)      AS TOTAL_CUSTO
      ,COALESCE(ELS.ELS_UP_SALDO * P.PRO_PRECO_VENDA, 0)::NUMERIC(18,2)      AS TOTAL_VENDA
      ,IIF(PXE.PXE_BLOQUEIO = 'Y','SIM'::CHAR(3),'NAO'::CHAR(3))             AS PRO_BLOQUEIO
      ,P.PRO_LOCALIZACAO
      ,CASE
         WHEN ELS.ELC_CODIGO  = 1 AND ME.ULTIMO_RECEBIMENTO IS NOT NULL THEN ME.ULTIMO_RECEBIMENTO
         WHEN ELS.ELC_CODIGO != 1 AND DPL.MOVIMENTA_ESTOQUE = TRUE     THEN DPL.ULTIMO_RECEBIMENTO
       END                                                                     AS ULTIMO_RECEBIMENTO

  FROM      PRODUTO               P
 INNER JOIN PRODUTO_X_EMPRESA     PXE  ON PXE.PRO_CODIGO = P.PRO_CODIGO
                                      AND PXE.EMP_CODIGO  = 1
 INNER JOIN EMPRESA               EMP  ON TRUE
  LEFT JOIN ESTOQUE_LOCAIS        ELC  ON ELC.EMP_CODIGO  = EMP.EMP_CODIGO
  LEFT JOIN ESTOQUE_LOCAIS_SALDO  ELS  ON ELS.PRO_CODIGO  = P.PRO_CODIGO
                                      AND ELS.ELC_CODIGO  = ELC.ELC_CODIGO
  LEFT JOIN NUM_CLASSIFICACAO     NC   ON NC.NC_CODIGO    = P.NC_CODIGO
  LEFT JOIN GRUPOS                GP   ON GP.GRP_CODIGO   = P.GRP_CODIGO
  LEFT JOIN SUB_GRUPO             SB   ON SB.SUB_CODIGO   = P.SUB_CODIGO
  LEFT JOIN MARCA                 MA   ON MA.MA_CODIGO    = P.MA_CODIGO
  LEFT JOIN MARK_UP               M    ON M.MAR_CODIGO    = P.MAR_CODIGO

  LEFT JOIN (
      SELECT IC.PRO_CODIGO,
             MAX(C.CMP_DATA_RECEBIMENTO) AS ULTIMO_RECEBIMENTO
        FROM COMPRA C
       INNER JOIN ITENS_COMPRA  IC  ON IC.CMP_CODIGO = C.CMP_CODIGO
       INNER JOIN TIPO_OPERACAO TO2 ON TO2.TO_CODIGO = C.TO_CODIGO
       INNER JOIN TIPO_DOCUMENTO TD ON TD.TDC_CODIGO = C.TDC_CODIGO
       WHERE TO2.TO_MOVIMENTAR_ESTOQUE_BOOL = TRUE
         AND TD.TDC_CODIGO <> 33
       GROUP BY IC.PRO_CODIGO
  ) ME ON ME.PRO_CODIGO = ELS.PRO_CODIGO

  LEFT JOIN (
      SELECT IC.PRO_CODIGO
            ,C.ELC_CODIGO
            ,TO2.TO_MOVIMENTAR_ESTOQUE_BOOL AS MOVIMENTA_ESTOQUE
            ,MAX(CMP_DATA_RECEBIMENTO)       AS ULTIMO_RECEBIMENTO
        FROM COMPRA C
       INNER JOIN ITENS_COMPRA  IC  ON IC.CMP_CODIGO = C.CMP_CODIGO
       INNER JOIN TIPO_OPERACAO TO2 ON TO2.TO_CODIGO = C.TO_CODIGO
       INNER JOIN TIPO_DOCUMENTO TD ON TD.TDC_CODIGO = C.TDC_CODIGO
       WHERE TO2.TO_MOVIMENTAR_ESTOQUE_BOOL = TRUE
       GROUP BY IC.PRO_CODIGO, C.ELC_CODIGO, TO2.TO_MOVIMENTAR_ESTOQUE_BOOL
  ) DPL ON DPL.PRO_CODIGO = ELS.PRO_CODIGO
       AND DPL.ELC_CODIGO  = ELS.ELC_CODIGO

 WHERE TRUE
   AND EMP.EMP_CODIGO  = 1
   $locais_sql
   AND P.GRP_CODIGO IN (20, 4, 23, 7)

 ORDER BY GP.GRP_DESCRICAO, P.PRO_DESCRICAO
";

try {
    $pg = dbPG();
    if (!$pg) throw new RuntimeException('Sem conexão com o ERP.');
    $res = pg_query($pg, $sql);
    if (!$res) throw new RuntimeException('Erro na consulta: ' . pg_last_error($pg));
    while ($r = pg_fetch_assoc($res)) $rows[] = $r;
    pg_free_result($res);
    pg_close($pg);
} catch (Throwable $e) {
    $erp_ok  = false;
    $erp_msg = $e->getMessage();
}

// ── Grupos disponíveis (extraídos antes dos filtros PHP) ──────────────────────
$gruposMap = [];
foreach ($rows as $r) {
    $cod = (string)($r['grp_codigo'] ?? '');
    $desc = (string)($r['grp_descricao'] ?? '');
    if ($cod !== '' && $desc !== '') $gruposMap[$cod] = $desc;
}
asort($gruposMap);

// ── Filtros PHP ───────────────────────────────────────────────────────────────
if ($filtro_codigo !== '') {
    $needle = mb_strtolower($filtro_codigo);
    $rows = array_values(array_filter($rows, fn($r) =>
        str_contains(mb_strtolower((string)($r['pro_codigo'] ?? '')), $needle) ||
        str_contains(mb_strtolower((string)($r['pro_codigo_or'] ?? '')), $needle)
    ));
}
if ($filtro_produto !== '') {
    $needle = mb_strtolower($filtro_produto);
    $rows = array_values(array_filter($rows, fn($r) =>
        str_contains(mb_strtolower((string)($r['pro_descricao'] ?? '')), $needle)
    ));
}
if ($filtro_bloqueio !== '' && in_array($filtro_bloqueio, ['SIM', 'NAO'], true)) {
    $rows = array_values(array_filter($rows, fn($r) => $r['pro_bloqueio'] === $filtro_bloqueio));
}
if ($filtro_grupo !== '') {
    $rows = array_values(array_filter($rows, fn($r) =>
        (string)($r['grp_codigo'] ?? '') === $filtro_grupo
    ));
}
if ($filtro_qtd_status !== '') {
    $rows = array_values(array_filter($rows, function($r) use ($filtro_qtd_status) {
        $saldo = (float)$r['up_saldo'];
        $disp  = (float)$r['up_disponivel'];
        $min   = (float)$r['pro_estoque_minimo'];
        return match($filtro_qtd_status) {
            'negativo' => $saldo <= 0,
            'positivo' => $saldo > 0,
            'alerta'   => $min > 0 && $disp < $min,
            default    => true,
        };
    }));
}
if ($filtro_qtd_abaixo !== null && $filtro_qtd_abaixo >= 0) {
    $rows = array_values(array_filter($rows, fn($r) =>
        (float)$r['up_disponivel'] < $filtro_qtd_abaixo
    ));
}

// ── KPIs ─────────────────────────────────────────────────────────────────────
$totalProdutos   = count($rows);
$totalBloqueados = count(array_filter($rows, fn($r) => $r['pro_bloqueio'] === 'SIM'));
$totalAbaixoMin  = count(array_filter($rows, fn($r) =>
    (float)$r['up_disponivel'] < (float)$r['pro_estoque_minimo'] && (float)$r['pro_estoque_minimo'] > 0
));
$valorTotalCusto = array_sum(array_column($rows, 'total_custo'));
$valorTotalVenda = array_sum(array_column($rows, 'total_venda'));

// ── Export CSV ────────────────────────────────────────────────────────────────
if ($exportCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="posicao_estoque_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Código','Cód.OR','Descrição','Unidade','Grupo/Subgrupo','Local','Saldo','Reservado','Disponível','Est.Mínimo','Custo Unit.','Preço Venda','Margem %','Total Custo','Total Venda','Ult.Recebimento','Situação'], ';');
    foreach ($rows as $r) {
        $bloq = $r['pro_bloqueio'] === 'SIM';
        $sal  = (float)$r['up_saldo'];
        $disp = (float)$r['up_disponivel'];
        $min  = (float)$r['pro_estoque_minimo'];
        $sit  = $bloq ? 'Bloqueado' : ($sal <= 0 ? 'Zerado' : ($min > 0 && $disp < $min ? 'Abaixo Mín.' : 'OK'));
        $ultRec = $r['ultimo_recebimento'] ?? '';
        if ($ultRec) { $dt = date_create($ultRec); $ultRec = $dt ? date_format($dt, 'd/m/Y') : ''; }
        fputcsv($out, [
            $r['pro_codigo'], $r['pro_codigo_or'] ?? '', $r['pro_descricao'], $r['pro_unidade'],
            $r['grupo_subgrupo'], $r['elc_descricao'] ?? '',
            str_replace('.', ',', $r['up_saldo']),
            str_replace('.', ',', $r['up_reservado']),
            str_replace('.', ',', $r['up_disponivel']),
            str_replace('.', ',', $r['pro_estoque_minimo']),
            str_replace('.', ',', $r['pro_preco_custo']),
            str_replace('.', ',', $r['pro_preco_venda']),
            str_replace('.', ',', $r['margem_bruta']),
            str_replace('.', ',', $r['total_custo']),
            str_replace('.', ',', $r['total_venda']),
            $ultRec, $sit,
        ], ';');
    }
    fclose($out);
    exit;
}

// ── URL helpers ───────────────────────────────────────────────────────────────
function rUrl(array $extra = [], array $remove = []): string {
    $p = $_GET;
    foreach ($remove as $k) unset($p[$k]);
    foreach ($extra as $k => $v) $p[$k] = $v;
    return '?' . http_build_query($p);
}
function fmt(float $v, int $dec = 3): string { return number_format($v, $dec, ',', '.'); }
function money(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$temFiltro = $filtro_codigo || $filtro_produto || $filtro_local || $filtro_bloqueio
          || $filtro_grupo  || $filtro_qtd_status || $filtro_qtd_abaixo !== null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Posição de Estoque | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.content{overflow:hidden;}

/* ── Topbar do relatório ── */
.rel-topbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 0;flex-shrink:0;}
.rel-topbar input,.rel-topbar select{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 10px;height:36px;font-family:inherit;font-size:12.5px;color:var(--text-primary);outline:none;transition:border-color .15s;}
.rel-topbar input:focus,.rel-topbar select:focus{border-color:rgba(45,106,255,.5);}
.rel-topbar input[type=number]{-moz-appearance:textfield;}
.rel-topbar input[type=number]::-webkit-outer-spin-button,
.rel-topbar input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;}
.filter-group{display:flex;align-items:center;gap:5px;}
.filter-group label{font-size:11px;color:var(--text-muted);white-space:nowrap;}
.btn-filtrar{height:36px;padding:0 16px;background:var(--blue-accent);border:none;border-radius:8px;color:#fff;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.btn-filtrar:hover{background:var(--blue-light);}
.btn-limpar{height:36px;padding:0 12px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--text-muted);font-family:inherit;font-size:12.5px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
.btn-limpar:hover{background:var(--card-hover);}
.btn-secondary{height:36px;padding:0 14px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--text-muted);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn-secondary:hover{background:var(--card-hover);color:var(--text-primary);}
.btn-export{height:36px;padding:0 14px;background:rgba(0,201,167,.1);border:1px solid rgba(0,201,167,.25);border-radius:8px;color:var(--teal);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none;margin-left:auto;}
.btn-export:hover{background:rgba(0,201,167,.2);}
.sep-filtro{width:1px;height:24px;background:var(--border);margin:0 2px;}

/* ── KPIs ── */
.kpi-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;flex-shrink:0;margin-bottom:14px;}
@media(max-width:1100px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}

/* ── Tabela ── */
.panel-table{flex:1;min-height:0;}
.tbl-wrap-outer{flex:1;min-height:0;display:flex;flex-direction:column;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
thead th{background:#112240;color:var(--text-muted);padding:8px 12px;font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:2;}
thead th.r{text-align:right;}
tbody td{padding:7px 12px;border-bottom:1px solid var(--border);vertical-align:middle;white-space:nowrap;}
tbody td.r{text-align:right;}
tbody tr:hover td{background:rgba(255,255,255,.025);}

/* ── Situações ── */
tr.abaixo-min td{color:var(--amber);}
tr.zerado td{color:var(--red);}
tr.bloqueado td{opacity:.45;}

.badge-ok{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(0,201,167,.1);color:var(--teal);}
.badge-neg{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(239,68,68,.12);color:var(--red);}
.badge-amb{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(245,158,11,.12);color:var(--amber);}
.badge-blq{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(239,68,68,.08);color:var(--text-muted);}
.local-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;background:rgba(45,106,255,.1);color:#7db3ff;}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'].'/shared/sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Posição de Estoque</h1>
          <p>Saldo atual por local · Grupos 4, 7, 20, 23 · ERP Yzidro</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn-secondary" id="btnPdf" onclick="gerarPdf()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Gerar PDF
        </button>
        <a href="<?= esc(rUrl(['export' => 'csv'])) ?>" class="btn-export">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Exportar CSV
        </a>
      </div>
    </header>

    <div class="content">

      <?php if (!$erp_ok): ?>
      <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:10px 16px;font-size:12.5px;color:var(--red);margin-bottom:14px;flex-shrink:0;">
        ⚠ <?= esc($erp_msg) ?>
      </div>
      <?php endif; ?>

      <!-- Filtros -->
      <form method="GET" class="rel-topbar" id="filtros-form">

        <div class="filter-group">
          <label>Código</label>
          <input type="text" name="codigo" id="filtro-codigo" placeholder="Código…"
                 value="<?= esc($filtro_codigo) ?>" style="width:100px;">
        </div>

        <div class="filter-group">
          <label>Descrição</label>
          <input type="text" name="produto" id="filtro-nome" placeholder="Busca dinâmica…"
                 value="<?= esc($filtro_produto) ?>" style="width:210px;" autocomplete="off">
        </div>

        <div class="sep-filtro"></div>

        <select name="local" style="width:145px;">
          <option value="">Todos os locais</option>
          <option value="7" <?= $filtro_local==='7'?'selected':'' ?>>Estoque</option>
          <option value="2" <?= $filtro_local==='2'?'selected':'' ?>>Fábrica</option>
        </select>

        <select name="grupo" style="width:165px;">
          <option value="">Todos os grupos</option>
          <?php foreach ($gruposMap as $cod => $desc): ?>
          <option value="<?= esc($cod) ?>" <?= $filtro_grupo===$cod?'selected':'' ?>><?= esc($desc) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="bloqueio" style="width:135px;">
          <option value="">Ativos e bloqueados</option>
          <option value="NAO" <?= $filtro_bloqueio==='NAO'?'selected':'' ?>>Somente ativos</option>
          <option value="SIM" <?= $filtro_bloqueio==='SIM'?'selected':'' ?>>Somente bloqueados</option>
        </select>

        <div class="sep-filtro"></div>

        <select name="qtd_status" style="width:150px;">
          <option value="">Qtd: todos</option>
          <option value="positivo" <?= $filtro_qtd_status==='positivo'?'selected':'' ?>>Positivo</option>
          <option value="negativo" <?= $filtro_qtd_status==='negativo'?'selected':'' ?>>Negativo / Zero</option>
          <option value="alerta"   <?= $filtro_qtd_status==='alerta'  ?'selected':'' ?>>Alerta (abaixo mín.)</option>
        </select>

        <div class="filter-group">
          <label>Qtd abaixo de</label>
          <input type="number" name="qtd_abaixo" min="0" step="0.001" placeholder="—"
                 value="<?= $filtro_qtd_abaixo !== null ? esc((string)$filtro_qtd_abaixo) : '' ?>"
                 style="width:85px;">
        </div>

        <button type="submit" class="btn-filtrar">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Filtrar
        </button>
        <?php if ($temFiltro): ?>
        <a href="?" class="btn-limpar">✕ Limpar</a>
        <?php endif; ?>
      </form>

      <!-- KPIs -->
      <div class="kpi-strip">
        <div class="kpi-mini c-blue">
          <div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div>
          <div><div class="kpi-mini-val" id="kpi-registros"><?= number_format($totalProdutos, 0, ',', '.') ?></div><div class="kpi-mini-lbl">Registros</div></div>
        </div>
        <div class="kpi-mini c-amber">
          <div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
          <div><div class="kpi-mini-val" style="color:var(--amber);"><?= number_format($totalAbaixoMin, 0, ',', '.') ?></div><div class="kpi-mini-lbl">Abaixo do mínimo</div></div>
        </div>
        <div class="kpi-mini c-red">
          <div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div>
          <div><div class="kpi-mini-val" style="color:var(--red);"><?= number_format($totalBloqueados, 0, ',', '.') ?></div><div class="kpi-mini-lbl">Bloqueados</div></div>
        </div>
        <div class="kpi-mini c-teal">
          <div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
          <div><div class="kpi-mini-val" style="font-size:14px;"><?= money($valorTotalCusto) ?></div><div class="kpi-mini-lbl">Total Custo</div></div>
        </div>
        <div class="kpi-mini c-blue">
          <div class="kpi-mini-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
          <div><div class="kpi-mini-val" style="font-size:14px;"><?= money($valorTotalVenda) ?></div><div class="kpi-mini-lbl">Total Venda</div></div>
        </div>
      </div>

      <!-- Tabela -->
      <div class="panel-table">
        <?php if (empty($rows) && $erp_ok): ?>
        <div class="empty-state">
          <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
          <p>Nenhum produto encontrado para os filtros informados.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Código</th>
                <th>Descrição</th>
                <th>Un.</th>
                <th>Grupo / Subgrupo</th>
                <th>Local</th>
                <th class="r">Saldo</th>
                <th class="r">Reservado</th>
                <th class="r">Disponível</th>
                <th class="r">Est. Mín.</th>
                <th class="r">Custo Unit.</th>
                <th class="r">Preço Venda</th>
                <th class="r">Margem %</th>
                <th class="r">Total Custo</th>
                <th class="r">Total Venda</th>
                <th>Ult. Recebimento</th>
                <th>Situação</th>
              </tr>
            </thead>
            <tbody id="tbl-body">
            <?php foreach ($rows as $r):
                $disponivel  = (float)$r['up_disponivel'];
                $saldo       = (float)$r['up_saldo'];
                $estMin      = (float)$r['pro_estoque_minimo'];
                $bloqueado   = $r['pro_bloqueio'] === 'SIM';
                $zerado      = $saldo <= 0 && !$bloqueado;
                $abaixoMin   = !$zerado && !$bloqueado && $estMin > 0 && $disponivel < $estMin;
                $trClass     = $bloqueado ? 'bloqueado' : ($zerado ? 'zerado' : ($abaixoMin ? 'abaixo-min' : ''));
                $ultRec      = $r['ultimo_recebimento'] ?? null;
                if ($ultRec) {
                    $dt = date_create($ultRec);
                    $ultRec = $dt ? date_format($dt, 'd/m/Y') : '—';
                }
                $nomeNorm = mb_strtolower((string)($r['pro_descricao'] ?? ''));
                $codNorm  = mb_strtolower((string)($r['pro_codigo']    ?? ''));
            ?>
            <tr class="<?= $trClass ?>" data-nome="<?= esc($nomeNorm) ?>" data-cod="<?= esc($codNorm) ?>">
              <td style="font-weight:700;color:#7db3ff;"><?= esc($r['pro_codigo']) ?></td>
              <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;" title="<?= esc($r['pro_descricao']) ?>">
                <?= esc(mb_substr($r['pro_descricao'], 0, 50)) ?><?= mb_strlen($r['pro_descricao']) > 50 ? '…' : '' ?>
              </td>
              <td style="color:var(--text-muted);"><?= esc($r['pro_unidade']) ?></td>
              <td style="font-size:11.5px;color:var(--text-muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;" title="<?= esc($r['grupo_subgrupo']) ?>">
                <?= esc($r['grupo_subgrupo']) ?>
              </td>
              <td><span class="local-pill"><?= esc($r['elc_descricao'] ?? '—') ?></span></td>
              <td class="r"><?= fmt($saldo) ?></td>
              <td class="r" style="color:var(--text-muted);"><?= fmt((float)$r['up_reservado']) ?></td>
              <td class="r"><?= fmt($disponivel) ?></td>
              <td class="r" style="color:var(--text-muted);"><?= $estMin > 0 ? fmt($estMin) : '—' ?></td>
              <td class="r"><?= money((float)$r['pro_preco_custo']) ?></td>
              <td class="r"><?= money((float)$r['pro_preco_venda']) ?></td>
              <td class="r"><?= fmt((float)$r['margem_bruta'], 2) ?>%</td>
              <td class="r"><?= money((float)$r['total_custo']) ?></td>
              <td class="r"><?= money((float)$r['total_venda']) ?></td>
              <td style="color:var(--text-muted);font-size:11.5px;"><?= esc($ultRec ?: '—') ?></td>
              <td>
                <?php if ($bloqueado): ?>
                  <span class="badge-blq">Bloqueado</span>
                <?php elseif ($zerado): ?>
                  <span class="badge-neg">Zerado</span>
                <?php elseif ($abaixoMin): ?>
                  <span class="badge-amb">Abaixo Mín.</span>
                <?php else: ?>
                  <span class="badge-ok">OK</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<script>
function gerarPdf() {
  var qs = new URLSearchParams(window.location.search).toString();
  window.open('/relatorios/posicao-estoque/pdf' + (qs ? '?' + qs : ''), '_blank');
}
(function () {
  var inputNome = document.getElementById('filtro-nome');
  var tbody     = document.getElementById('tbl-body');
  var kpiEl     = document.getElementById('kpi-registros');
  if (!inputNome || !tbody) return;

  // Aplica valor inicial (caso página carregou com filtro por GET)
  if (inputNome.value.trim()) filterRows(inputNome.value);

  inputNome.addEventListener('input', function () {
    filterRows(this.value);
  });

  function filterRows(q) {
    q = q.toLowerCase().trim();
    var rows    = tbody.querySelectorAll('tr[data-nome]');
    var visible = 0;
    rows.forEach(function (tr) {
      var match = q === '' || tr.dataset.nome.includes(q);
      tr.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    if (kpiEl) kpiEl.textContent = visible.toLocaleString('pt-BR');
  }
})();
</script>
</body>
</html>
