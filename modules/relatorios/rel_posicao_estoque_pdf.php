<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

$filtro_codigo     = trim((string) ($_GET['codigo']      ?? ''));
$filtro_produto    = trim((string) ($_GET['produto']     ?? ''));
$filtro_local      = trim((string) ($_GET['local']       ?? ''));
$filtro_bloqueio   = trim((string) ($_GET['bloqueio']    ?? ''));
$filtro_grupo      = trim((string) ($_GET['grupo']       ?? ''));
$filtro_qtd_status = trim((string) ($_GET['qtd_status']  ?? ''));
$filtro_qtd_abaixo = isset($_GET['qtd_abaixo']) && $_GET['qtd_abaixo'] !== ''
                     ? (float)$_GET['qtd_abaixo'] : null;

$locais_sql = match($filtro_local) {
    '7'     => 'AND ELC.ELC_CODIGO IN (7)',
    '2'     => 'AND ELC.ELC_CODIGO IN (2)',
    default => 'AND ELC.ELC_CODIGO IN (7, 2)',
};

$erp_ok  = true;
$erp_msg = '';
$rows    = [];

$sql = "
SELECT P.PRO_CODIGO, P.PRO_CODIGO_OR, P.PRO_DESCRICAO, P.PRO_UNIDADE
      ,GP.GRP_CODIGO, GP.GRP_DESCRICAO, SB.SUB_DESCRICAO
      ,(GP.GRP_DESCRICAO || ' > ' || SB.SUB_DESCRICAO)::VARCHAR(70) AS GRUPO_SUBGRUPO
      ,ELC.ELC_DESCRICAO
      ,COALESCE(ELS.ELS_UP_SALDO,      0)::NUMERIC(19,4) AS UP_SALDO
      ,COALESCE(ELS.ELS_UP_RESERVADO,  0)::NUMERIC(19,4) AS UP_RESERVADO
      ,COALESCE(ELS.ELS_UP_DISPONIVEL, 0)::NUMERIC(19,4) AS UP_DISPONIVEL
      ,COALESCE(P.PRO_ESTOQUE_MINIMO,  0)::NUMERIC(18,4) AS PRO_ESTOQUE_MINIMO
      ,COALESCE(P.PRO_PRECO_CUSTO,     0)::NUMERIC(18,4) AS PRO_PRECO_CUSTO
      ,COALESCE(P.PRO_PRECO_VENDA,     0)::NUMERIC(18,4) AS PRO_PRECO_VENDA
      ,IIF(P.PRO_PRECO_VENDA = 0.00, 0::FLOAT,
           (((P.PRO_PRECO_VENDA - P.PRO_PRECO_CUSTO) / P.PRO_PRECO_VENDA) * 100))::NUMERIC(18,2) AS MARGEM_BRUTA
      ,COALESCE(ELS.ELS_UP_SALDO * P.PRO_PRECO_CUSTO, 0)::NUMERIC(18,2) AS TOTAL_CUSTO
      ,COALESCE(ELS.ELS_UP_SALDO * P.PRO_PRECO_VENDA, 0)::NUMERIC(18,2) AS TOTAL_VENDA
      ,IIF(PXE.PXE_BLOQUEIO = 'Y','SIM'::CHAR(3),'NAO'::CHAR(3)) AS PRO_BLOQUEIO
      ,CASE
         WHEN ELS.ELC_CODIGO  = 1 AND ME.ULTIMO_RECEBIMENTO IS NOT NULL THEN ME.ULTIMO_RECEBIMENTO
         WHEN ELS.ELC_CODIGO != 1 AND DPL.MOVIMENTA_ESTOQUE = TRUE     THEN DPL.ULTIMO_RECEBIMENTO
       END AS ULTIMO_RECEBIMENTO
  FROM      PRODUTO               P
 INNER JOIN PRODUTO_X_EMPRESA     PXE  ON PXE.PRO_CODIGO = P.PRO_CODIGO AND PXE.EMP_CODIGO = 1
 INNER JOIN EMPRESA               EMP  ON TRUE
  LEFT JOIN ESTOQUE_LOCAIS        ELC  ON ELC.EMP_CODIGO = EMP.EMP_CODIGO
  LEFT JOIN ESTOQUE_LOCAIS_SALDO  ELS  ON ELS.PRO_CODIGO = P.PRO_CODIGO AND ELS.ELC_CODIGO = ELC.ELC_CODIGO
  LEFT JOIN GRUPOS                GP   ON GP.GRP_CODIGO  = P.GRP_CODIGO
  LEFT JOIN SUB_GRUPO             SB   ON SB.SUB_CODIGO  = P.SUB_CODIGO
  LEFT JOIN (
      SELECT IC.PRO_CODIGO, MAX(C.CMP_DATA_RECEBIMENTO) AS ULTIMO_RECEBIMENTO
        FROM COMPRA C
       INNER JOIN ITENS_COMPRA  IC  ON IC.CMP_CODIGO = C.CMP_CODIGO
       INNER JOIN TIPO_OPERACAO TO2 ON TO2.TO_CODIGO = C.TO_CODIGO
       INNER JOIN TIPO_DOCUMENTO TD ON TD.TDC_CODIGO = C.TDC_CODIGO
       WHERE TO2.TO_MOVIMENTAR_ESTOQUE_BOOL = TRUE AND TD.TDC_CODIGO <> 33
       GROUP BY IC.PRO_CODIGO
  ) ME ON ME.PRO_CODIGO = ELS.PRO_CODIGO
  LEFT JOIN (
      SELECT IC.PRO_CODIGO, C.ELC_CODIGO, TO2.TO_MOVIMENTAR_ESTOQUE_BOOL AS MOVIMENTA_ESTOQUE
            ,MAX(CMP_DATA_RECEBIMENTO) AS ULTIMO_RECEBIMENTO
        FROM COMPRA C
       INNER JOIN ITENS_COMPRA  IC  ON IC.CMP_CODIGO = C.CMP_CODIGO
       INNER JOIN TIPO_OPERACAO TO2 ON TO2.TO_CODIGO = C.TO_CODIGO
       INNER JOIN TIPO_DOCUMENTO TD ON TD.TDC_CODIGO = C.TDC_CODIGO
       WHERE TO2.TO_MOVIMENTAR_ESTOQUE_BOOL = TRUE
       GROUP BY IC.PRO_CODIGO, C.ELC_CODIGO, TO2.TO_MOVIMENTAR_ESTOQUE_BOOL
  ) DPL ON DPL.PRO_CODIGO = ELS.PRO_CODIGO AND DPL.ELC_CODIGO = ELS.ELC_CODIGO
 WHERE TRUE AND EMP.EMP_CODIGO = 1 $locais_sql AND P.GRP_CODIGO IN (20, 4, 23, 7)
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

// Filtros PHP
if ($filtro_codigo !== '') {
    $n = mb_strtolower($filtro_codigo);
    $rows = array_values(array_filter($rows, fn($r) =>
        str_contains(mb_strtolower((string)($r['pro_codigo'] ?? '')), $n) ||
        str_contains(mb_strtolower((string)($r['pro_codigo_or'] ?? '')), $n)
    ));
}
if ($filtro_produto !== '') {
    $n = mb_strtolower($filtro_produto);
    $rows = array_values(array_filter($rows, fn($r) =>
        str_contains(mb_strtolower((string)($r['pro_descricao'] ?? '')), $n)
    ));
}
if ($filtro_bloqueio !== '' && in_array($filtro_bloqueio, ['SIM','NAO'], true))
    $rows = array_values(array_filter($rows, fn($r) => $r['pro_bloqueio'] === $filtro_bloqueio));
if ($filtro_grupo !== '')
    $rows = array_values(array_filter($rows, fn($r) => (string)($r['grp_codigo'] ?? '') === $filtro_grupo));
if ($filtro_qtd_status !== '') {
    $rows = array_values(array_filter($rows, function($r) use ($filtro_qtd_status) {
        $sal = (float)$r['up_saldo']; $disp = (float)$r['up_disponivel']; $min = (float)$r['pro_estoque_minimo'];
        return match($filtro_qtd_status) {
            'negativo' => $sal <= 0, 'positivo' => $sal > 0,
            'alerta'   => $min > 0 && $disp < $min, default => true,
        };
    }));
}
if ($filtro_qtd_abaixo !== null)
    $rows = array_values(array_filter($rows, fn($r) => (float)$r['up_disponivel'] < $filtro_qtd_abaixo));

$totalProdutos   = count($rows);
$totalBloqueados = count(array_filter($rows, fn($r) => $r['pro_bloqueio'] === 'SIM'));
$totalAbaixoMin  = count(array_filter($rows, fn($r) =>
    (float)$r['up_disponivel'] < (float)$r['pro_estoque_minimo'] && (float)$r['pro_estoque_minimo'] > 0));
$valorTotalCusto = array_sum(array_column($rows, 'total_custo'));
$valorTotalVenda = array_sum(array_column($rows, 'total_venda'));

function pdfFmt(float $v, int $d = 3): string { return number_format($v, $d, ',', '.'); }
function pdfMoney(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function pdfEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$geradoEm = date('d/m/Y H:i');
$filtros = array_filter([
    $filtro_codigo   ? 'Código: ' . $filtro_codigo   : '',
    $filtro_produto  ? 'Produto: ' . $filtro_produto  : '',
    $filtro_local    ? 'Local: ' . ($filtro_local==='7'?'Estoque':'Fábrica') : '',
    $filtro_bloqueio ? 'Bloqueio: ' . $filtro_bloqueio : '',
    $filtro_grupo    ? 'Grupo: ' . $filtro_grupo      : '',
]);
$filtroStr = $filtros ? implode(' · ', $filtros) : 'Todos os produtos';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Posição de Estoque — PDF</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:Arial,sans-serif; font-size:9px; color:#111; background:#fff; padding:16px 20px; }

.header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #111; padding-bottom:10px; margin-bottom:12px; }
.header-title { font-size:15px; font-weight:bold; }
.header-sub   { font-size:9.5px; color:#555; margin-top:3px; }
.header-meta  { text-align:right; font-size:9px; color:#555; }

.kpi-row { display:flex; gap:10px; margin-bottom:14px; }
.kpi { flex:1; border:1px solid #ddd; border-radius:4px; padding:7px 10px; }
.kpi-lbl { font-size:8px; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
.kpi-val { font-size:13px; font-weight:bold; }

table { width:100%; border-collapse:collapse; font-size:8px; }
th { background:#1a1a2e; color:#fff; padding:4px 6px; text-align:left; font-size:7.5px; font-weight:bold; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
th.r { text-align:right; }
td { padding:3px 6px; border-bottom:1px solid #e5e5e5; vertical-align:middle; white-space:nowrap; }
td.r { text-align:right; }
tbody tr:nth-child(even) td { background:#f8f8f8; }
tr.zerado td    { color:#c00 !important; background:#fff0f0 !important; }
tr.abaixo td    { color:#b45309 !important; background:#fffbeb !important; }
tr.bloqueado td { opacity:.45; }

.badge { display:inline-block; padding:1px 5px; border-radius:8px; font-size:7.5px; font-weight:bold; }
.badge-ok  { background:#d1fae5; color:#065f46; }
.badge-neg { background:#fee2e2; color:#991b1b; }
.badge-amb { background:#fef3c7; color:#92400e; }
.badge-blq { background:#f3f4f6; color:#6b7280; }

.footer { margin-top:14px; border-top:1px solid #ccc; padding-top:6px; font-size:8px; color:#888; display:flex; justify-content:space-between; }

@media print {
    body { padding:0; }
    @page { margin:10mm 8mm; size:A4 landscape; }
}
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="header-title">DOMBAG — Posição de Estoque</div>
    <div class="header-sub">Saldo atual por local · Grupos 4, 7, 20, 23 · ERP Yzidro</div>
    <div class="header-sub" style="margin-top:3px;">Filtros: <?= pdfEsc($filtroStr) ?></div>
  </div>
  <div class="header-meta">
    Gerado em: <?= $geradoEm ?><br>
    <?= $totalProdutos ?> produto(s)
    <?php if ($totalAbaixoMin > 0): ?>
    · <span style="color:#b45309;font-weight:bold;"><?= $totalAbaixoMin ?> abaixo do mínimo</span>
    <?php endif; ?>
    <?php if ($totalBloqueados > 0): ?>
    · <span style="color:#c00;font-weight:bold;"><?= $totalBloqueados ?> bloqueado(s)</span>
    <?php endif; ?>
  </div>
</div>

<div class="kpi-row">
  <div class="kpi"><div class="kpi-lbl">Produtos</div><div class="kpi-val"><?= number_format($totalProdutos, 0, ',', '.') ?></div></div>
  <div class="kpi"><div class="kpi-lbl">Abaixo do Mínimo</div><div class="kpi-val" style="color:#b45309;"><?= number_format($totalAbaixoMin, 0, ',', '.') ?></div></div>
  <div class="kpi"><div class="kpi-lbl">Bloqueados</div><div class="kpi-val" style="color:#c00;"><?= number_format($totalBloqueados, 0, ',', '.') ?></div></div>
  <div class="kpi"><div class="kpi-lbl">Total Custo</div><div class="kpi-val"><?= pdfMoney($valorTotalCusto) ?></div></div>
  <div class="kpi"><div class="kpi-lbl">Total Venda</div><div class="kpi-val"><?= pdfMoney($valorTotalVenda) ?></div></div>
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
      <th>Cód. OR</th>
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
  <tbody>
  <?php foreach ($rows as $r):
    $disponivel = (float)$r['up_disponivel'];
    $saldo      = (float)$r['up_saldo'];
    $estMin     = (float)$r['pro_estoque_minimo'];
    $bloqueado  = $r['pro_bloqueio'] === 'SIM';
    $zerado     = $saldo <= 0 && !$bloqueado;
    $abaixoMin  = !$zerado && !$bloqueado && $estMin > 0 && $disponivel < $estMin;
    $trClass    = $bloqueado ? 'bloqueado' : ($zerado ? 'zerado' : ($abaixoMin ? 'abaixo' : ''));
    $ultRec     = $r['ultimo_recebimento'] ?? null;
    if ($ultRec) { $dt = date_create($ultRec); $ultRec = $dt ? date_format($dt, 'd/m/Y') : '—'; }
  ?>
  <tr class="<?= $trClass ?>">
    <td style="font-weight:bold;"><?= pdfEsc($r['pro_codigo']) ?></td>
    <td style="color:#555;"><?= pdfEsc($r['pro_codigo_or'] ?? '') ?></td>
    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?= pdfEsc($r['pro_descricao']) ?>">
      <?= pdfEsc(mb_substr($r['pro_descricao'], 0, 35)) ?><?= mb_strlen($r['pro_descricao']) > 35 ? '…' : '' ?>
    </td>
    <td><?= pdfEsc($r['pro_unidade']) ?></td>
    <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;color:#555;" title="<?= pdfEsc($r['grupo_subgrupo']) ?>">
      <?= pdfEsc(mb_substr($r['grupo_subgrupo'], 0, 25)) ?><?= mb_strlen($r['grupo_subgrupo']) > 25 ? '…' : '' ?>
    </td>
    <td><?= pdfEsc($r['elc_descricao'] ?? '—') ?></td>
    <td class="r"><?= pdfFmt($saldo) ?></td>
    <td class="r"><?= pdfFmt((float)$r['up_reservado']) ?></td>
    <td class="r"><?= pdfFmt($disponivel) ?></td>
    <td class="r"><?= $estMin > 0 ? pdfFmt($estMin) : '—' ?></td>
    <td class="r"><?= pdfMoney((float)$r['pro_preco_custo']) ?></td>
    <td class="r"><?= pdfMoney((float)$r['pro_preco_venda']) ?></td>
    <td class="r"><?= pdfFmt((float)$r['margem_bruta'], 2) ?>%</td>
    <td class="r"><?= pdfMoney((float)$r['total_custo']) ?></td>
    <td class="r"><?= pdfMoney((float)$r['total_venda']) ?></td>
    <td style="color:#555;"><?= pdfEsc($ultRec ?: '—') ?></td>
    <td>
      <?php if ($bloqueado): ?><span class="badge badge-blq">Bloqueado</span>
      <?php elseif ($zerado): ?><span class="badge badge-neg">Zerado</span>
      <?php elseif ($abaixoMin): ?><span class="badge badge-amb">Abaixo Mín.</span>
      <?php else: ?><span class="badge badge-ok">OK</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php endif; ?>

<div class="footer">
  <span>DOMBAG · Posição de Estoque · ERP Yzidro (PostgreSQL)</span>
  <span>Gerado em <?= $geradoEm ?></span>
</div>

<script>window.print();</script>
</body>
</html>
