<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Relatório de Estoque Futuro
//  Fonte: ERP PostgreSQL (VW_COMPRAS + PEDIDO + ITENS_PEDIDO)
// ══════════════════════════════════════════════════════════════════════════════

$filtro_produto = trim((string) ($_GET['produto'] ?? ''));
$filtro_sit     = trim((string) ($_GET['situacao'] ?? ''));

$erp_ok  = true;
$erp_msg = '';
$rows    = [];

$sql = "
WITH ESTOQUE_BASE AS (
    SELECT
        VC.COD_PRODUTO,
        MAX(VC.PRODUTO) AS PRODUTO,
        CAST(MAX(VC.ESTOQUE_ATUAL_UN1)     AS NUMERIC(18,3)) AS ESTOQUE_ATUAL,
        CAST(MAX(VC.ESTOQUE_RESERVADO_UN1) AS NUMERIC(18,3)) AS ESTOQUE_RESERVADO
    FROM VW_COMPRAS VC
    WHERE VC.EMP_CODIGO IN (1)
      AND VC.COD_GRUPO IN (4, 7, 14, 20, 23)
    GROUP BY VC.COD_PRODUTO
),
ULTIMO_PRECO AS (
    SELECT
        X.COD_PRODUTO,
        CAST(X.VALOR_UNITARIO_ATUAL AS NUMERIC(18,2)) AS VALOR_UNITARIO_ATUAL
    FROM (
        SELECT
            VC.COD_PRODUTO,
            COALESCE(
                NULLIF(VC.PRECO_COMPRA, 0),
                NULLIF(VC.VALOR_UNIT, 0),
                NULLIF(VC.VALOR_UNIT_DESCONTADO, 0)
            ) AS VALOR_UNITARIO_ATUAL,
            VC.DATAEMISSAO,
            VC.COD_COMPRA,
            ROW_NUMBER() OVER (
                PARTITION BY VC.COD_PRODUTO
                ORDER BY VC.DATAEMISSAO DESC NULLS LAST,
                         VC.COD_COMPRA   DESC NULLS LAST
            ) AS RN
        FROM VW_COMPRAS VC
        WHERE VC.EMP_CODIGO IN (1)
          AND VC.COD_GRUPO IN (4, 7, 14, 20, 23)
          AND COALESCE(
                NULLIF(VC.PRECO_COMPRA, 0),
                NULLIF(VC.VALOR_UNIT, 0),
                NULLIF(VC.VALOR_UNIT_DESCONTADO, 0)
              ) IS NOT NULL
    ) X
    WHERE X.RN = 1
),
PE_PENDENTE AS (
    SELECT
        X.COD_PRODUTO,
        CAST(SUM(X.SALDO_QTD) AS NUMERIC(18,3)) AS QTD_PEDIDA_SALDO,
        CAST(
            SUM(X.SALDO_QTD * X.VALOR_UNITARIO_ITEM)
            / NULLIF(SUM(X.SALDO_QTD), 0)
            AS NUMERIC(18,2)
        ) AS VALOR_UNITARIO_FUTURO
    FROM (
        SELECT
            IP.PRO_CODIGO AS COD_PRODUTO,
            (IP.ITE_QTD - IP.ITE_QTD_ENTREGUE) AS SALDO_QTD,
            COALESCE(NULLIF(IP.ITE_VALOR_UNIT, 0), 0) AS VALOR_UNITARIO_ITEM
        FROM PEDIDO PE
        INNER JOIN ITENS_PEDIDO IP ON IP.PE_CODIGO = PE.PE_CODIGO
        WHERE PE.PE_STATUS IN (1, 2)
          AND PE.EMP_CODIGO IN (1)
          AND PE.PE_DATA >= DATE_TRUNC('year', CURRENT_DATE)
          AND PE.PE_DATA <  DATE_TRUNC('year', CURRENT_DATE) + INTERVAL '1 year'
          AND (IP.ITE_QTD - IP.ITE_QTD_ENTREGUE) > 0
    ) X
    GROUP BY X.COD_PRODUTO
)
SELECT
    EB.COD_PRODUTO                                                         AS cod_produto,
    EB.PRODUTO::VARCHAR(100)                                               AS produto,
    EB.ESTOQUE_ATUAL                                                       AS estoque_atual,
    COALESCE(PP.QTD_PEDIDA_SALDO, 0)                                      AS qtd_a_receber,
    EB.ESTOQUE_RESERVADO                                                   AS estoque_reservado,
    CAST(
        EB.ESTOQUE_ATUAL + COALESCE(PP.QTD_PEDIDA_SALDO, 0) - EB.ESTOQUE_RESERVADO
        AS NUMERIC(18,3)
    )                                                                      AS estoque_futuro,
    COALESCE(UP.VALOR_UNITARIO_ATUAL, 0)                                   AS valor_unit_atual,
    COALESCE(PP.VALOR_UNITARIO_FUTURO, 0)                                  AS valor_unit_futuro,
    CAST(
        CASE
            WHEN COALESCE(UP.VALOR_UNITARIO_ATUAL,0)>0 AND COALESCE(PP.VALOR_UNITARIO_FUTURO,0)>0
                THEN (UP.VALOR_UNITARIO_ATUAL + PP.VALOR_UNITARIO_FUTURO) / 2.0
            WHEN COALESCE(UP.VALOR_UNITARIO_ATUAL,0)>0
                THEN UP.VALOR_UNITARIO_ATUAL
            ELSE COALESCE(PP.VALOR_UNITARIO_FUTURO, 0)
        END AS NUMERIC(18,2)
    )                                                                      AS media_valor_unit,
    CAST(EB.ESTOQUE_ATUAL * COALESCE(UP.VALOR_UNITARIO_ATUAL,0) AS NUMERIC(18,2))
                                                                           AS valor_total_atual,
    CAST(
        (EB.ESTOQUE_ATUAL + COALESCE(PP.QTD_PEDIDA_SALDO,0) - EB.ESTOQUE_RESERVADO)
        * COALESCE(PP.VALOR_UNITARIO_FUTURO,0)
        AS NUMERIC(18,2)
    )                                                                      AS valor_total_futuro,
    CASE
        WHEN (EB.ESTOQUE_ATUAL + COALESCE(PP.QTD_PEDIDA_SALDO,0) - EB.ESTOQUE_RESERVADO) < 0
            THEN 'Negativo'
        ELSE 'OK'
    END                                                                    AS situacao
FROM ESTOQUE_BASE EB
LEFT JOIN PE_PENDENTE   PP ON PP.COD_PRODUTO = EB.COD_PRODUTO
LEFT JOIN ULTIMO_PRECO  UP ON UP.COD_PRODUTO = EB.COD_PRODUTO
ORDER BY EB.PRODUTO
";

try {
    $pg = dbPG();
    if (!$pg) throw new RuntimeException('Sem conexão com o ERP.');

    $res = pg_query($pg, $sql);
    if (!$res) throw new RuntimeException('Erro na consulta: ' . pg_last_error($pg));

    while ($r = pg_fetch_assoc($res)) {
        $rows[] = $r;
    }
    pg_free_result($res);
    pg_close($pg);
} catch (Throwable $e) {
    $erp_ok  = false;
    $erp_msg = $e->getMessage();
}

// ── Filtros client-side (aplicados no PHP para KPIs corretos) ─────────────────
$rowsFiltrados = $rows;
if ($filtro_produto !== '') {
    $needle = mb_strtolower($filtro_produto);
    $rowsFiltrados = array_filter($rowsFiltrados, fn($r) =>
        str_contains(mb_strtolower((string)($r['produto'] ?? '')), $needle)
    );
}
if ($filtro_sit !== '' && in_array($filtro_sit, ['OK', 'Negativo'], true)) {
    $rowsFiltrados = array_filter($rowsFiltrados, fn($r) => $r['situacao'] === $filtro_sit);
}
$rowsFiltrados = array_values($rowsFiltrados);

// ── KPIs ──────────────────────────────────────────────────────────────────────
$totalProdutos   = count($rowsFiltrados);
$totalNegativos  = count(array_filter($rowsFiltrados, fn($r) => $r['situacao'] === 'Negativo'));
$totalAReceber   = array_sum(array_column($rowsFiltrados, 'qtd_a_receber'));
$valorTotalAtual = array_sum(array_column($rowsFiltrados, 'valor_total_atual'));
$valorTotalFuturo= array_sum(array_column($rowsFiltrados, 'valor_total_futuro'));

function efFmt(float $v, int $dec = 3): string {
    return number_format($v, $dec, ',', '.');
}
function efMoney(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
function efEsc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estoque Futuro | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
/* ── Layout ── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.content{flex:1;overflow-y:auto;padding:24px;}
.content::-webkit-scrollbar{width:4px;}
.content::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}

/* ── Botões ── */
.btn-primary{background:var(--blue-accent);color:#fff;border:none;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .15s;white-space:nowrap;}
.btn-primary:hover{background:var(--blue-light);}
.btn-secondary{background:transparent;color:var(--text-muted);border:1px solid var(--border);padding:8px 14px;border-radius:7px;font-size:13px;font-family:'Segoe UI',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;white-space:nowrap;}
.btn-secondary:hover{background:var(--card-hover);color:var(--text-primary);}

/* ── Alert ── */
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);border-radius:8px;padding:10px 16px;font-size:12.5px;display:flex;align-items:center;gap:8px;margin-bottom:18px;}

/* ── KPIs ── */
.kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;}
.kpi{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;position:relative;overflow:hidden;}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;}
.kpi.c-blue::before{background:linear-gradient(90deg,var(--blue-light),transparent);}
.kpi.c-teal::before{background:linear-gradient(90deg,var(--teal),transparent);}
.kpi.c-amber::before{background:linear-gradient(90deg,var(--amber),transparent);}
.kpi.c-red::before{background:linear-gradient(90deg,var(--red),transparent);}
.kpi.c-green::before{background:linear-gradient(90deg,#22c55e,transparent);}
.kpi-lbl{font-size:11px;color:var(--text-muted);font-weight:500;margin-bottom:8px;}
.kpi-val{font-size:22px;font-weight:700;letter-spacing:-1px;line-height:1;margin-bottom:3px;}
.kpi-sub{font-size:11px;color:var(--text-muted);}

/* ── Filtros ── */
.filter-bar{display:flex;align-items:flex-end;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
.filter-field{display:flex;flex-direction:column;gap:5px;}
.filter-field label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);}
.filter-field input,.filter-field select{height:34px;padding:0 10px;font-size:12.5px;font-family:'Segoe UI',sans-serif;background:var(--card-bg);border:1px solid var(--border);border-radius:7px;color:var(--text-primary);outline:none;}
.filter-field input:focus,.filter-field select:focus{border-color:rgba(79,123,255,.5);}
.filter-field input{min-width:260px;}

/* ── Tabela ── */
.panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.panel-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;}
.panel-title{font-size:13px;font-weight:600;}
.source-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:600;}
.src-pg{background:rgba(56,189,248,.1);color:#38bdf8;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.07em;text-transform:uppercase;border-bottom:1px solid var(--border);background:rgba(0,0,0,.1);white-space:nowrap;}
td{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);white-space:nowrap;}
tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:rgba(255,255,255,.03);}
.tr-negativo td{background:rgba(239,68,68,.04);}
.tr-negativo:hover td{background:rgba(239,68,68,.08)!important;}
.num{text-align:right;}
.money{text-align:right;font-weight:600;}
.col-produto{min-width:240px;font-weight:500;}
.col-codigo{color:var(--text-muted);font-size:12px;}
.sit-ok{display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;font-size:10.5px;font-weight:600;background:rgba(34,197,94,.12);color:#22c55e;}
.sit-neg{display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;font-size:10.5px;font-weight:600;background:rgba(239,68,68,.12);color:var(--red);}
.val-neg{color:var(--red);font-weight:700;}
.empty-state{text-align:center;padding:48px 20px;color:var(--text-muted);font-size:13px;}

@media(max-width:1200px){.kpi-row{grid-template-columns:repeat(3,1fr);}}
@media(max-width:800px){.kpi-row{grid-template-columns:1fr 1fr;}}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Estoque Futuro</h1>
          <p>Estoque atual + pedidos pendentes − reservado · Ano corrente · ERP</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn-secondary" id="btnCsv">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Exportar CSV
        </button>
        <button class="btn-primary" onclick="location.reload()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Atualizar
        </button>
      </div>
    </header>

    <div class="content">

      <?php if (!$erp_ok): ?>
      <div class="alert-err">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= efEsc($erp_msg) ?>
      </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-row">
        <div class="kpi c-blue">
          <div class="kpi-lbl">Produtos</div>
          <div class="kpi-val" style="color:#7db3ff;"><?= number_format($totalProdutos, 0, ',', '.') ?></div>
          <div class="kpi-sub">itens no relatório</div>
        </div>
        <div class="kpi c-red">
          <div class="kpi-lbl">Estoque Negativo</div>
          <div class="kpi-val" style="color:var(--red);"><?= number_format($totalNegativos, 0, ',', '.') ?></div>
          <div class="kpi-sub"><?= $totalProdutos > 0 ? number_format($totalNegativos / $totalProdutos * 100, 1, ',', '.') . '% do total' : '—' ?></div>
        </div>
        <div class="kpi c-amber">
          <div class="kpi-lbl">Total a Receber</div>
          <div class="kpi-val" style="color:var(--amber);font-size:18px;"><?= efFmt((float)$totalAReceber, 0) ?></div>
          <div class="kpi-sub">unidades em pedidos pendentes</div>
        </div>
        <div class="kpi c-teal">
          <div class="kpi-lbl">Valor Estoque Atual</div>
          <div class="kpi-val" style="color:var(--teal);font-size:16px;"><?= efMoney((float)$valorTotalAtual) ?></div>
          <div class="kpi-sub">preço atual × qtd atual</div>
        </div>
        <div class="kpi c-green">
          <div class="kpi-lbl">Valor Estoque Futuro</div>
          <div class="kpi-val" style="color:#22c55e;font-size:16px;"><?= efMoney((float)$valorTotalFuturo) ?></div>
          <div class="kpi-sub">preço futuro × qtd futura</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="filter-bar">
        <div class="filter-field">
          <label for="fProduto">Produto</label>
          <input type="text" id="fProduto" placeholder="Buscar produto…" value="<?= efEsc($filtro_produto) ?>">
        </div>
        <div class="filter-field">
          <label for="fSit">Situação</label>
          <select id="fSit">
            <option value="" <?= $filtro_sit === '' ? 'selected' : '' ?>>Todos</option>
            <option value="OK"      <?= $filtro_sit === 'OK'      ? 'selected' : '' ?>>OK</option>
            <option value="Negativo"<?= $filtro_sit === 'Negativo'? 'selected' : '' ?>>Negativo</option>
          </select>
        </div>
        <button class="btn-secondary" onclick="resetFiltros()">Limpar</button>
        <span id="countLabel" style="margin-left:auto;font-size:12px;color:var(--text-muted);"></span>
      </div>

      <!-- Tabela -->
      <div class="panel">
        <div class="panel-header">
          <span class="panel-title">Produtos · Estoque Futuro</span>
          <span class="source-badge src-pg">PostgreSQL · ERP</span>
        </div>
        <div class="table-wrap">
          <table id="tblEstoque">
            <thead>
              <tr>
                <th>Código</th>
                <th>Produto</th>
                <th class="num">Est. Atual</th>
                <th class="num">A Receber</th>
                <th class="num">Reservado</th>
                <th class="num">Est. Futuro</th>
                <th class="num">Vl. Unit. Atual</th>
                <th class="num">Vl. Unit. Futuro</th>
                <th class="num">Média Vl. Unit.</th>
                <th class="num">Vl. Total Atual</th>
                <th class="num">Vl. Total Futuro</th>
                <th>Situação</th>
              </tr>
            </thead>
            <tbody id="tbodyEstoque">
              <?php if (empty($rowsFiltrados)): ?>
              <tr><td colspan="12" class="empty-state">
                <?= $erp_ok ? 'Nenhum produto encontrado.' : 'Sem dados — verifique a conexão com o ERP.' ?>
              </td></tr>
              <?php else: ?>
              <?php foreach ($rowsFiltrados as $r): ?>
              <?php
                $futuro = (float) $r['estoque_futuro'];
                $trClass = $futuro < 0 ? ' class="tr-negativo"' : '';
                $futuroClass = $futuro < 0 ? ' val-neg' : '';
              ?>
              <tr<?= $trClass ?>
                data-produto="<?= efEsc(mb_strtolower($r['produto'])) ?>"
                data-sit="<?= efEsc($r['situacao']) ?>">
                <td class="col-codigo"><?= efEsc($r['cod_produto']) ?></td>
                <td class="col-produto"><?= efEsc($r['produto']) ?></td>
                <td class="num"><?= efFmt((float)$r['estoque_atual']) ?></td>
                <td class="num"><?= efFmt((float)$r['qtd_a_receber']) ?></td>
                <td class="num"><?= efFmt((float)$r['estoque_reservado']) ?></td>
                <td class="num<?= $futuroClass ?>"><?= efFmt($futuro) ?></td>
                <td class="money"><?= efMoney((float)$r['valor_unit_atual']) ?></td>
                <td class="money"><?= efMoney((float)$r['valor_unit_futuro']) ?></td>
                <td class="money"><?= efMoney((float)$r['media_valor_unit']) ?></td>
                <td class="money"><?= efMoney((float)$r['valor_total_atual']) ?></td>
                <td class="money"><?= efMoney((float)$r['valor_total_futuro']) ?></td>
                <td>
                  <?php if ($r['situacao'] === 'Negativo'): ?>
                  <span class="sit-neg">Negativo</span>
                  <?php else: ?>
                  <span class="sit-ok">OK</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
// ── Dados para CSV ────────────────────────────────────────────────────────────
const CSV_DATA = <?= json_encode(array_map(fn($r) => [
  $r['cod_produto'],
  $r['produto'],
  $r['estoque_atual'],
  $r['qtd_a_receber'],
  $r['estoque_reservado'],
  $r['estoque_futuro'],
  $r['valor_unit_atual'],
  $r['valor_unit_futuro'],
  $r['media_valor_unit'],
  $r['valor_total_atual'],
  $r['valor_total_futuro'],
  $r['situacao'],
], $rows), JSON_UNESCAPED_UNICODE) ?>;

const CSV_HEADER = ['Código','Produto','Est. Atual','A Receber','Reservado','Est. Futuro',
                    'Vl. Unit. Atual','Vl. Unit. Futuro','Média Vl. Unit.',
                    'Vl. Total Atual','Vl. Total Futuro','Situação'];

// ── Filtro client-side ────────────────────────────────────────────────────────
const fProduto = document.getElementById('fProduto');
const fSit     = document.getElementById('fSit');
const tbody    = document.getElementById('tbodyEstoque');
const countLbl = document.getElementById('countLabel');

function applyFilter(){
  const needle = fProduto.value.toLowerCase().trim();
  const sit    = fSit.value;
  let vis = 0;
  tbody.querySelectorAll('tr[data-produto]').forEach(tr=>{
    const okProd = !needle || tr.dataset.produto.includes(needle);
    const okSit  = !sit    || tr.dataset.sit === sit;
    const show   = okProd && okSit;
    tr.style.display = show ? '' : 'none';
    if(show) vis++;
  });
  countLbl.textContent = vis + ' produto(s) exibido(s)';
}

fProduto.addEventListener('input', applyFilter);
fSit.addEventListener('change', applyFilter);

function resetFiltros(){
  fProduto.value='';
  fSit.value='';
  applyFilter();
}

// ── Export CSV ────────────────────────────────────────────────────────────────
document.getElementById('btnCsv').addEventListener('click',()=>{
  // Exporta apenas linhas visíveis
  const visRows=[];
  tbody.querySelectorAll('tr[data-produto]').forEach(tr=>{
    if(tr.style.display!=='none') visRows.push(tr);
  });

  // Monta CSV com dados do PHP (mesma ordem)
  const allRows=[CSV_HEADER,...CSV_DATA];
  const filteredRows=[CSV_HEADER];

  // Mapeia produto → linha CSV
  const prodMap={};
  CSV_DATA.forEach(r=>{ prodMap[String(r[0])+'|'+String(r[1])]=r; });

  visRows.forEach(tr=>{
    const cod=tr.querySelector('.col-codigo')?.textContent?.trim()||'';
    const prod=tr.querySelector('.col-produto')?.textContent?.trim()||'';
    const key=cod+'|'+prod;
    if(prodMap[key]) filteredRows.push(prodMap[key]);
  });

  const csv=filteredRows.map(r=>r.map(v=>'"'+String(v??'').replace(/"/g,'""')+'"').join(';')).join('\n');
  const a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,\uFEFF'+encodeURIComponent(csv);
  a.download='estoque_futuro_<?= date('Y-m-d') ?>.csv';
  a.click();
});

// ── Init ──────────────────────────────────────────────────────────────────────
applyFilter();
</script>
</body>
</html>
