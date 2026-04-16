<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

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
    while ($r = pg_fetch_assoc($res)) $rows[] = $r;
    pg_free_result($res);
    pg_close($pg);
} catch (Throwable $e) {
    $erp_ok  = false;
    $erp_msg = $e->getMessage();
}

// Filtros
if ($filtro_produto !== '') {
    $needle = mb_strtolower($filtro_produto);
    $rows = array_values(array_filter($rows, fn($r) =>
        str_contains(mb_strtolower((string)($r['produto'] ?? '')), $needle)
    ));
}
if ($filtro_sit !== '' && in_array($filtro_sit, ['OK', 'Negativo'], true)) {
    $rows = array_values(array_filter($rows, fn($r) => $r['situacao'] === $filtro_sit));
}

// KPIs
$totalProdutos    = count($rows);
$totalNegativos   = count(array_filter($rows, fn($r) => $r['situacao'] === 'Negativo'));
$totalAReceber    = array_sum(array_column($rows, 'qtd_a_receber'));
$valorTotalAtual  = array_sum(array_column($rows, 'valor_total_atual'));
$valorTotalFuturo = array_sum(array_column($rows, 'valor_total_futuro'));

function pdfFmt(float $v, int $dec = 3): string {
    return number_format($v, $dec, ',', '.');
}
function pdfMoney(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
function pdfEsc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$geradoEm = date('d/m/Y H:i');
$filtroDesc = [];
if ($filtro_produto !== '') $filtroDesc[] = 'Produto: "' . $filtro_produto . '"';
if ($filtro_sit !== '')     $filtroDesc[] = 'Situação: ' . $filtro_sit;
$filtroStr = $filtroDesc ? implode(' · ', $filtroDesc) : 'Todos os produtos';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Estoque Futuro — PDF</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: Arial, sans-serif;
    font-size: 10px;
    color: #111;
    background: #fff;
    padding: 16px 20px;
}

/* ── Cabeçalho ── */
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #111;
    padding-bottom: 10px;
    margin-bottom: 12px;
}
.header-title { font-size: 16px; font-weight: bold; }
.header-sub   { font-size: 10px; color: #555; margin-top: 3px; }
.header-meta  { text-align: right; font-size: 9.5px; color: #555; }

/* ── KPIs ── */
.kpi-row {
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
}
.kpi {
    flex: 1;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 8px 10px;
}
.kpi-lbl { font-size: 8.5px; color: #666; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
.kpi-val { font-size: 14px; font-weight: bold; }

/* ── Tabela ── */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9px;
}
th {
    background: #1a1a2e;
    color: #fff;
    padding: 5px 7px;
    text-align: left;
    font-size: 8.5px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .04em;
    white-space: nowrap;
}
th.num { text-align: right; }
td {
    padding: 4px 7px;
    border-bottom: 1px solid #e5e5e5;
    vertical-align: middle;
    white-space: nowrap;
}
td.num { text-align: right; }
tbody tr:nth-child(even) td { background: #f8f8f8; }

/* Linha negativa */
tr.negativo td {
    color: #c00 !important;
    background: #fff0f0 !important;
    font-weight: bold;
}

/* ── Rodapé ── */
.footer {
    margin-top: 14px;
    border-top: 1px solid #ccc;
    padding-top: 6px;
    font-size: 8.5px;
    color: #888;
    display: flex;
    justify-content: space-between;
}

@media print {
    body { padding: 0; }
    @page { margin: 12mm 10mm; size: A4 landscape; }
}
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="header-title">DOMBAG — Relatório de Estoque Futuro</div>
    <div class="header-sub">Estoque atual + pedidos pendentes − reservado · Ano corrente · ERP Yzidro</div>
    <div class="header-sub" style="margin-top:3px;">Filtros: <?= pdfEsc($filtroStr) ?></div>
  </div>
  <div class="header-meta">
    Gerado em: <?= $geradoEm ?><br>
    <?= $totalProdutos ?> produto(s)
    <?php if ($totalNegativos > 0): ?>
    · <span style="color:#c00;font-weight:bold;"><?= $totalNegativos ?> negativo(s)</span>
    <?php endif; ?>
  </div>
</div>

<!-- KPIs -->
<div class="kpi-row">
  <div class="kpi">
    <div class="kpi-lbl">Produtos</div>
    <div class="kpi-val"><?= number_format($totalProdutos, 0, ',', '.') ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-lbl">Estoque Negativo</div>
    <div class="kpi-val" style="color:#c00;"><?= number_format($totalNegativos, 0, ',', '.') ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-lbl">Total a Receber (un)</div>
    <div class="kpi-val"><?= pdfFmt((float)$totalAReceber, 0) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-lbl">Valor Estoque Atual</div>
    <div class="kpi-val"><?= pdfMoney((float)$valorTotalAtual) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-lbl">Valor Estoque Futuro</div>
    <div class="kpi-val"><?= pdfMoney((float)$valorTotalFuturo) ?></div>
  </div>
</div>

<?php if (!$erp_ok): ?>
  <p style="color:#c00;font-weight:bold;margin-bottom:12px;">Erro: <?= pdfEsc($erp_msg) ?></p>
<?php elseif (empty($rows)): ?>
  <p style="color:#555;margin-bottom:12px;">Nenhum produto encontrado para os filtros informados.</p>
<?php else: ?>

<table>
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
  <tbody>
  <?php foreach ($rows as $r):
    $futuro   = (float)$r['estoque_futuro'];
    $negativo = $futuro < 0;
  ?>
    <tr class="<?= $negativo ? 'negativo' : '' ?>">
      <td><?= pdfEsc($r['cod_produto']) ?></td>
      <td><?= pdfEsc($r['produto']) ?></td>
      <td class="num"><?= pdfFmt((float)$r['estoque_atual']) ?></td>
      <td class="num"><?= pdfFmt((float)$r['qtd_a_receber']) ?></td>
      <td class="num"><?= pdfFmt((float)$r['estoque_reservado']) ?></td>
      <td class="num"><?= pdfFmt($futuro) ?></td>
      <td class="num"><?= pdfMoney((float)$r['valor_unit_atual']) ?></td>
      <td class="num"><?= pdfMoney((float)$r['valor_unit_futuro']) ?></td>
      <td class="num"><?= pdfMoney((float)$r['media_valor_unit']) ?></td>
      <td class="num"><?= pdfMoney((float)$r['valor_total_atual']) ?></td>
      <td class="num"><?= pdfMoney((float)$r['valor_total_futuro']) ?></td>
      <td><?= $negativo ? 'Negativo' : 'OK' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php endif; ?>

<div class="footer">
  <span>DOMBAG · Relatório de Estoque Futuro · ERP Yzidro (PostgreSQL)</span>
  <span>Gerado em <?= $geradoEm ?></span>
</div>

<script>window.print();</script>
</body>
</html>
