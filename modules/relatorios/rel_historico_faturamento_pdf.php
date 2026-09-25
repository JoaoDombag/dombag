<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Histórico de Faturamento — versão para impressão/PDF
//  Fonte: VENDAS + CLIENTES + REPRESENTANTES (PostgreSQL, leitura)
//  EST_CODIGO = 14 (finalizado), exclui VEN_MODELO_VENDA em (2,3,9,10,11,12)
// ══════════════════════════════════════════════════════════════

function hfpEsc($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function hfpDate(?string $v): string
{
    $v = trim((string) $v);
    if ($v === '') {
        return '—';
    }
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : $v;
}

function hfpQty($v): string
{
    return number_format((float) $v, 0, ',', '.');
}

function hfpMoney($v): string
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}

function hfpSanitizeDate(?string $v): string
{
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return $dt ? $dt->format('Y-m-d') : '';
}

function hfpTipoProduto(string $nome): string
{
    $n = strtoupper($nome);
    if (str_contains($n, 'SACARIA') || str_contains($n, 'SACO')) {
        return 'SACARIA';
    }
    if (str_contains($n, 'BIG BAG') || str_contains($n, 'BIGBAG') || preg_match('/\bBAG\b/', $n)) {
        return 'BAG';
    }
    return '';
}

$tipoFiltro = strtolower(trim((string) ($_GET['tipo'] ?? '')));
if (!in_array($tipoFiltro, ['bag', 'sacaria'], true)) {
    $tipoFiltro = '';
}

$filters = [
    'data_ini'      => hfpSanitizeDate($_GET['data_ini'] ?? '') ?: date('Y-m-01'),
    'data_fim'      => hfpSanitizeDate($_GET['data_fim'] ?? '') ?: date('Y-m-t'),
    'pedido'        => trim((string) ($_GET['pedido'] ?? '')),
    'cliente'       => trim((string) ($_GET['cliente'] ?? '')),
    'representante' => trim((string) ($_GET['representante'] ?? '')),
    'tipo'          => $tipoFiltro,
];

$error   = '';
$pedidos = [];

try {
    $pg = pcpGetPG();
    if (!$pg) {
        throw new RuntimeException('Sem conexão com o ERP.');
    }

    $params = [];
    $extras = [];

    $params[] = $filters['data_ini'];
    $extras[] = 'V.DATA_FINALIZA >= $' . count($params);
    $params[] = $filters['data_fim'];
    $extras[] = 'V.DATA_FINALIZA <= $' . count($params);

    if ($filters['pedido'] !== '') {
        $params[] = '%' . $filters['pedido'] . '%';
        $extras[] = 'V.VEN_COD_PEDIDO::TEXT ILIKE $' . count($params);
    }
    if ($filters['cliente'] !== '') {
        $params[] = '%' . $filters['cliente'] . '%';
        $extras[] = '(C.CLI_NOME ILIKE $' . count($params)
                  . ' OR C.CLI_NOME_FANTASIA ILIKE $' . count($params) . ')';
    }
    if ($filters['representante'] !== '') {
        $params[] = '%' . $filters['representante'] . '%';
        $extras[] = 'R.RE_NOME ILIKE $' . count($params);
    }

    $where = "V.EMP_CODIGO = 1
          AND V.EST_CODIGO IN (14)
          AND V.VEN_MODELO_VENDA NOT IN (2, 3, 9, 10, 11, 12)
          AND " . implode("\n          AND ", $extras);

    $sql = "
        SELECT DISTINCT
             V.VEN_COD_PEDIDO::TEXT                                          AS pedido
            ,TO_CHAR(V.DATA_FINALIZA, 'YYYY-MM-DD')                          AS data_finaliza
            ,COALESCE(LPAD(V.VEN_NUMERO_DFE::VARCHAR, 9, '0'), '')           AS numeracao
            ,COALESCE(LPAD(V.VEN_SERIE_DFE, 3, '0'), '')                     AS serie
            ,COALESCE(V.VEN_STATUS, 'V')                                    AS status
            ,COALESCE(V.VEN_QUANTIDADE, 0)                                   AS quantidade
            ,COALESCE(V.VEN_TOTAL, 0)                                        AS valor
            ,IIF(COALESCE(C.CLI_NOME_FANTASIA, '') = ''
                ,COALESCE(C.CLI_NOME, '')
                ,C.CLI_NOME_FANTASIA)                                        AS cliente_fantasia
            ,COALESCE(C.CLI_CIDADE, '')                                      AS cidade
            ,COALESCE(R.RE_NOME, '')                                         AS representante
        FROM      VENDAS         V
        LEFT JOIN CLIENTES       C ON C.CLI_CODIGO = V.CLI_CODIGO
        LEFT JOIN REPRESENTANTES R ON R.RE_CODIGO  = V.RE_CODIGO
        WHERE {$where}
        ORDER BY data_finaliza DESC, pedido DESC
    ";

    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar as vendas do ERP: ' . pg_last_error($pg));
    }
    while ($r = pg_fetch_assoc($res)) {
        $sinal = ((string) $r['status'] === 'D') ? -1 : 1;
        $r['quantidade'] = $sinal * (float) $r['quantidade'];
        $r['valor']      = $sinal * (float) $r['valor'];
        $pedidos[] = $r;
    }
    pg_free_result($res);

    // Classificação Bag/Sacaria via itens (VW_VENDAS)
    $nums = array_values(array_unique(array_filter(array_column($pedidos, 'pedido'), fn($v) => $v !== '')));
    $itensMap = [];
    if ($nums) {
        $ph  = implode(',', array_map(fn($i) => '$' . ($i + 1), array_keys($nums)));
        $rIt = @pg_query_params($pg, "
            SELECT VV.PEDIDO::TEXT AS pedido, COALESCE(VV.PRODUTO::VARCHAR,'') AS produto, COALESCE(VV.QTD,0) AS qtd
            FROM VW_VENDAS VV
            WHERE VV.EMP_CODIGO = 1 AND VV.OPERACAO_PEDIDO = 'V' AND VV.PEDIDO::TEXT IN ({$ph})
        ", $nums);
        if ($rIt) {
            while ($it = pg_fetch_assoc($rIt)) {
                $ped = (string) $it['pedido'];
                $q   = (float) $it['qtd'];
                $itensMap[$ped] ??= ['bag' => 0.0, 'sac' => 0.0, 'outros' => 0.0];
                $t = hfpTipoProduto((string) $it['produto']);
                if ($t === 'BAG')          $itensMap[$ped]['bag'] += $q;
                elseif ($t === 'SACARIA')  $itensMap[$ped]['sac'] += $q;
                else                       $itensMap[$ped]['outros'] += $q;
            }
            pg_free_result($rIt);
        }
    }
    pg_close($pg);

    foreach ($pedidos as &$row) {
        $ag = $itensMap[$row['pedido']] ?? ['bag' => 0.0, 'sac' => 0.0, 'outros' => 0.0];
        $sinal = (($row['status'] ?? 'V') === 'D') ? -1 : 1;
        $row['qtd_bag'] = $ag['bag'] * $sinal;
        $row['qtd_sac'] = $ag['sac'] * $sinal;
        $itemTotal = $ag['bag'] + $ag['sac'] + $ag['outros'];
        $row['quantidade'] = $itemTotal > 0 ? $itemTotal * $sinal : (float) $row['quantidade'];
        $row['tipo'] = match (true) {
            $ag['bag'] > 0 && $ag['sac'] > 0 => 'Bag + Sacaria',
            $ag['bag'] > 0                    => 'Bag',
            $ag['sac'] > 0                    => 'Sacaria',
            default                          => '—',
        };
    }
    unset($row);

    if ($tipoFiltro === 'bag') {
        $pedidos = array_values(array_filter($pedidos, fn($r) => abs($r['qtd_bag']) > 0));
    } elseif ($tipoFiltro === 'sacaria') {
        $pedidos = array_values(array_filter($pedidos, fn($r) => abs($r['qtd_sac']) > 0));
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totalPedidos = count($pedidos);
$totalPecas   = array_sum(array_map(fn($r) => (float) ($r['quantidade'] ?? 0), $pedidos));
$totalBag     = array_sum(array_map(fn($r) => (float) ($r['qtd_bag'] ?? 0), $pedidos));
$totalSacaria = array_sum(array_map(fn($r) => (float) ($r['qtd_sac'] ?? 0), $pedidos));
$totalValor   = array_sum(array_map(fn($r) => (float) ($r['valor'] ?? 0), $pedidos));
$ticketMedio  = $totalPedidos > 0 ? $totalValor / $totalPedidos : 0.0;

$geradoEm  = date('d/m/Y H:i');
$periodo   = hfpDate($filters['data_ini']) . ' a ' . hfpDate($filters['data_fim']);
$filtroDesc = [];
if ($filters['tipo'] !== '')          $filtroDesc[] = 'Tipo: ' . ($filters['tipo'] === 'bag' ? 'Somente Bag' : 'Somente Sacaria');
if ($filters['pedido'] !== '')        $filtroDesc[] = 'Pedido: "' . $filters['pedido'] . '"';
if ($filters['cliente'] !== '')       $filtroDesc[] = 'Cliente: "' . $filters['cliente'] . '"';
if ($filters['representante'] !== '') $filtroDesc[] = 'Representante: "' . $filters['representante'] . '"';
$filtroStr = $filtroDesc ? implode(' · ', $filtroDesc) : 'Sem filtros adicionais';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Histórico de Faturamento — PDF</title>
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

table { width: 100%; border-collapse: collapse; font-size: 9px; }
thead th { background: #f0f0f0; border: 1px solid #bbb; padding: 5px 6px; text-align: left; font-size: 8.5px;
           text-transform: uppercase; letter-spacing: .03em; }
tbody td { border: 1px solid #ddd; padding: 4px 6px; }
tbody tr:nth-child(even) td { background: #fafafa; }
tfoot td { border: 1px solid #bbb; padding: 5px 6px; font-weight: bold; background: #f0f0f0; }
.num { text-align: right; }
.dev { color: #c00; font-weight: bold; }
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
    <div class="header-title">Histórico de Faturamento</div>
    <div class="header-sub">Período de saída: <?= hfpEsc($periodo) ?></div>
    <div class="header-sub"><?= hfpEsc($filtroStr) ?></div>
  </div>
  <div class="header-meta">
    Gerado em <?= hfpEsc($geradoEm) ?><br>
    <?= hfpEsc(usuNome()) ?>
  </div>
</div>

<?php if ($error !== ''): ?>
  <p style="color:#c00;font-weight:bold;margin-bottom:12px;">Erro: <?= hfpEsc($error) ?></p>
<?php endif; ?>

<div class="tot-row">
  <div class="tot"><div class="tot-lbl">Saída total (peças)</div><div class="tot-val"><?= hfpQty($totalPecas) ?></div></div>
  <div class="tot"><div class="tot-lbl">Bag</div><div class="tot-val"><?= hfpQty($totalBag) ?></div></div>
  <div class="tot"><div class="tot-lbl">Sacaria</div><div class="tot-val"><?= hfpQty($totalSacaria) ?></div></div>
  <div class="tot"><div class="tot-lbl">Pedidos</div><div class="tot-val"><?= hfpQty($totalPedidos) ?></div></div>
  <div class="tot"><div class="tot-lbl">Valor total</div><div class="tot-val"><?= hfpMoney($totalValor) ?></div></div>
  <div class="tot"><div class="tot-lbl">Ticket médio</div><div class="tot-val"><?= hfpMoney($ticketMedio) ?></div></div>
</div>

<table>
  <thead>
    <tr>
      <th>Saída</th>
      <th>Pedido</th>
      <th>NF-e</th>
      <th>Cliente</th>
      <th>Cidade</th>
      <th>Representante</th>
      <th>Tipo</th>
      <th class="num">Bag</th>
      <th class="num">Sacaria</th>
      <th class="num">Peças</th>
      <th class="num">Valor</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$pedidos): ?>
      <tr><td colspan="11" class="empty">Nenhum pedido finalizado com os filtros informados.</td></tr>
    <?php else: foreach ($pedidos as $row):
        $isDev = ($row['status'] ?? 'V') === 'D';
        $doc   = trim(($row['numeracao'] ?? '') . ($row['serie'] ? ' / ' . $row['serie'] : ''));
        ?>
      <tr>
        <td><?= hfpEsc(hfpDate($row['data_finaliza'] ?? '')) ?></td>
        <td><?= hfpEsc($row['pedido'] ?? '—') ?><?= $isDev ? ' <span class="dev">(DEV)</span>' : '' ?></td>
        <td><?= $doc !== '' ? hfpEsc($doc) : '—' ?></td>
        <td><?= hfpEsc($row['cliente_fantasia'] !== '' ? $row['cliente_fantasia'] : '—') ?></td>
        <td><?= hfpEsc($row['cidade'] !== '' ? $row['cidade'] : '—') ?></td>
        <td><?= hfpEsc($row['representante'] !== '' ? $row['representante'] : '—') ?></td>
        <td><?= hfpEsc($row['tipo'] ?? '—') ?></td>
        <td class="num"><?= ((float) ($row['qtd_bag'] ?? 0)) != 0.0 ? hfpQty($row['qtd_bag']) : '—' ?></td>
        <td class="num"><?= ((float) ($row['qtd_sac'] ?? 0)) != 0.0 ? hfpQty($row['qtd_sac']) : '—' ?></td>
        <td class="num"><?= hfpQty($row['quantidade'] ?? 0) ?></td>
        <td class="num"><?= hfpMoney($row['valor'] ?? 0) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
  <?php if ($pedidos): ?>
  <tfoot>
    <tr>
      <td colspan="7" class="num">Total</td>
      <td class="num"><?= hfpQty($totalBag) ?></td>
      <td class="num"><?= hfpQty($totalSacaria) ?></td>
      <td class="num"><?= hfpQty($totalPecas) ?></td>
      <td class="num"><?= hfpMoney($totalValor) ?></td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>

<script>window.print();</script>
</body>
</html>
