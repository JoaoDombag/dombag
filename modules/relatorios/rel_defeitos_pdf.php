<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/relatorios/rel_defeitos_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Relatório de Defeitos — versão para impressão/PDF
//  Sem op/pro: relatório do período. Com op e pro: detalhe da OP.
//  Regras de contagem e consultas: rel_defeitos_dados.php
// ══════════════════════════════════════════════════════════════

$filters = rdFiltros();
$op      = (int) ($_GET['op'] ?? 0);
$pro     = (int) ($_GET['pro'] ?? 0);
$detalhe = $op > 0 && $pro > 0;

$pg      = pcpGetPG();
$error   = '';
$porOp   = [];
$porTipo = [];
$pecas   = [];

if (!$pg) {
    $error = 'Falha ao conectar no ERP.';
} else {
    try {
        $porOp = rdFetchPorOp($pg, $filters);
        if ($detalhe) {
            $porOp = array_values(array_filter($porOp, fn($r) => (int) $r['op'] === $op && (int) $r['pro'] === $pro));
            $pecas = rdFetchDetalheOp($pg, $filters, $op, $pro);
        } else {
            $porTipo = rdFetchPorTipo($pg, $filters);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    pg_close($pg);
}

$totalProduzidos  = array_sum(array_map(fn($r) => (float) $r['produzidos'], $porOp));
$totalComDefeito  = array_sum(array_map(fn($r) => (float) $r['com_defeito'], $porOp));
$totalCorrigidos  = array_sum(array_map(fn($r) => (float) $r['corrigidos'], $porOp));
$totalDescartados = array_sum(array_map(fn($r) => (float) $r['descartados'], $porOp));
$totalPendentes   = array_sum(array_map(fn($r) => (float) $r['pendentes'], $porOp));
$totalTipos       = array_sum(array_map(fn($r) => (float) $r['qtd'], $porTipo));

$periodo  = date('d/m/Y', strtotime($filters['data_ini'])) . ' a ' . date('d/m/Y', strtotime($filters['data_fim']));
$geradoEm = date('d/m/Y H:i');
$titulo   = $detalhe ? 'Detalhamento de Defeitos — O.P ' . $op : 'Relatório de Defeitos';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title><?= rdEscape($titulo) ?> — PDF</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 10px; color: #111; background: #fff; padding: 16px 20px; }

.header { display: flex; justify-content: space-between; align-items: flex-start;
          border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 12px; }
.header-title { font-size: 16px; font-weight: bold; }
.header-sub   { font-size: 10px; color: #555; margin-top: 3px; }
.header-meta  { text-align: right; font-size: 9.5px; color: #555; }

.tot-row { display: flex; gap: 10px; margin-bottom: 14px; }
.tot { flex: 1; border: 1px solid #ddd; border-radius: 4px; padding: 8px 10px; }
.tot-lbl { font-size: 8.5px; color: #666; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
.tot-val { font-size: 14px; font-weight: bold; }
.tot-sub { font-size: 8.5px; color: #666; margin-top: 2px; }

h2 { font-size: 11px; margin: 14px 0 6px; }
table { width: 100%; border-collapse: collapse; font-size: 9px; }
thead th { background: #f0f0f0; border: 1px solid #bbb; padding: 5px 6px; text-align: left; font-size: 8.5px;
           text-transform: uppercase; letter-spacing: .03em; }
tbody td { border: 1px solid #ddd; padding: 4px 6px; }
tbody tr:nth-child(even) td { background: #fafafa; }
tfoot td { border: 1px solid #bbb; padding: 5px 6px; font-weight: bold; background: #f0f0f0; }
.num { text-align: right; }
.nowrap { white-space: nowrap; }
.empty { padding: 20px; text-align: center; color: #666; }

@media print {
  body { padding: 0; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
}
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="header-title"><?= rdEscape($titulo) ?></div>
    <div class="header-sub">Período: <?= rdEscape($periodo) ?></div>
    <?php if ($detalhe && $porOp): ?>
      <div class="header-sub"><?= rdEscape($porOp[0]['cliente']) ?> · <?= rdEscape($porOp[0]['produto'] ?? '—') ?></div>
    <?php endif; ?>
  </div>
  <div class="header-meta">
    Gerado em <?= rdEscape($geradoEm) ?><br>
    <?= rdEscape(usuNome()) ?>
  </div>
</div>

<?php if ($error !== ''): ?>
  <p style="color:#c00;font-weight:bold;margin-bottom:12px;">Erro: <?= rdEscape($error) ?></p>
<?php endif; ?>

<div class="tot-row">
  <div class="tot"><div class="tot-lbl">Bags Produzidos</div><div class="tot-val"><?= rdQty($totalProduzidos) ?></div></div>
  <div class="tot"><div class="tot-lbl">Com defeito</div><div class="tot-val"><?= rdQty($totalComDefeito) ?></div><div class="tot-sub"><?= rdPct($totalComDefeito, $totalProduzidos) ?> dos produzidos</div></div>
  <div class="tot"><div class="tot-lbl">Corrigidos</div><div class="tot-val"><?= rdQty($totalCorrigidos) ?></div><div class="tot-sub"><?= rdPct($totalCorrigidos, $totalComDefeito) ?> dos defeitos</div></div>
  <div class="tot"><div class="tot-lbl">Descartados</div><div class="tot-val"><?= rdQty($totalDescartados) ?></div><div class="tot-sub"><?= rdPct($totalDescartados, $totalComDefeito) ?> dos defeitos</div></div>
  <div class="tot"><div class="tot-lbl">Pendentes</div><div class="tot-val"><?= rdQty($totalPendentes) ?></div><div class="tot-sub"><?= rdPct($totalPendentes, $totalComDefeito) ?> dos defeitos</div></div>
</div>

<?php if ($detalhe): ?>

  <h2>Peças com defeito</h2>
  <table>
    <thead>
      <tr>
        <th>NSU</th>
        <th>Situação</th>
        <th>Defeito</th>
        <th>Data</th>
        <th>Gerado por</th>
        <th>Apontado por</th>
        <th>Corrigido por</th>
        <th>Corrigido em</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$pecas): ?>
        <tr><td colspan="8" class="empty">Nenhum defeito nesta O.P no período.</td></tr>
      <?php else: foreach ($pecas as $peca): foreach ($peca['defeitos'] as $d): ?>
        <tr>
          <td class="nowrap"><?= rdEscape($peca['nsu']) ?></td>
          <td class="nowrap"><?= rdEscape($peca['situacao']) ?></td>
          <td><?= rdEscape($d['tipo']) ?></td>
          <td class="nowrap"><?= rdEscape($d['data']) ?></td>
          <td><?= rdEscape($d['gerado_por']) ?></td>
          <td><?= rdEscape($d['apontado_por']) ?></td>
          <td><?= rdEscape($d['corrigido_por'] !== '' ? $d['corrigido_por'] : '—') ?></td>
          <td class="nowrap"><?= rdEscape($d['corrigido_em'] !== '' ? $d['corrigido_em'] : '—') ?></td>
        </tr>
      <?php endforeach; endforeach; endif; ?>
    </tbody>
  </table>

<?php else: ?>

  <h2>Totais por O.P</h2>
  <table>
    <thead>
      <tr>
        <th>O.P</th>
        <th>Cliente</th>
        <th>Produto</th>
        <th class="num">Bags Produzidos</th>
        <th class="num">Com defeito</th>
        <th class="num">Corrigidos</th>
        <th class="num">Descartados</th>
        <th class="num">Pendentes</th>
        <th class="num">% defeito</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$porOp): ?>
        <tr><td colspan="9" class="empty">Nenhuma produção ou defeito no período informado.</td></tr>
      <?php else: foreach ($porOp as $row): ?>
        <tr>
          <td><?= rdEscape($row['op']) ?></td>
          <td><?= rdEscape($row['cliente'] !== '' ? $row['cliente'] : '—') ?></td>
          <td><?= rdEscape($row['produto'] ?? '—') ?></td>
          <td class="num"><?= rdQty($row['produzidos']) ?></td>
          <td class="num"><?= rdQty($row['com_defeito']) ?></td>
          <td class="num"><?= rdQty($row['corrigidos']) ?></td>
          <td class="num"><?= rdQty($row['descartados']) ?></td>
          <td class="num"><?= rdQty($row['pendentes']) ?></td>
          <td class="num"><?= rdPct((float) $row['com_defeito'], (float) $row['produzidos']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if ($porOp): ?>
    <tfoot>
      <tr>
        <td colspan="3" class="num">Total</td>
        <td class="num"><?= rdQty($totalProduzidos) ?></td>
        <td class="num"><?= rdQty($totalComDefeito) ?></td>
        <td class="num"><?= rdQty($totalCorrigidos) ?></td>
        <td class="num"><?= rdQty($totalDescartados) ?></td>
        <td class="num"><?= rdQty($totalPendentes) ?></td>
        <td class="num"><?= rdPct($totalComDefeito, $totalProduzidos) ?></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <?php if ($porTipo): ?>
    <h2>Apontamentos por tipo</h2>
    <table style="width:50%;">
      <thead>
        <tr>
          <th>Tipo</th>
          <th class="num">Qtd</th>
          <th class="num">%</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($porTipo as $row): ?>
          <tr>
            <td><?= rdEscape($row['tipo']) ?></td>
            <td class="num"><?= rdQty($row['qtd']) ?></td>
            <td class="num"><?= rdPct((float) $row['qtd'], $totalTipos) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="num">Total</td>
          <td class="num"><?= rdQty($totalTipos) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>

<?php endif; ?>

<script>window.print();</script>
</body>
</html>
