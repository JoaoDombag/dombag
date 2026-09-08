<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Histórico de Faturamento (ERP Yzidro)
//  Fonte: PRODUCAO + VENDAS + CLIENTES + REPRESENTANTES (PostgreSQL)
//  Conexão: 004703consulta
//
//  Mostra quais pedidos "saíram" da produção dentro do intervalo
//  do filtro — pela PRODUCAO.DATA_FINALIZA (uma linha por pedido,
//  data de saída = maior DATA_FINALIZA entre as OPs do pedido).
//  Sem valores monetários.
// ══════════════════════════════════════════════════════════════

function hfEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hfSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function hfFmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function hfQty($value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function hfBaseUrl(): string
{
    return '/relatorios/historico-faturamento';
}

// ── Classificação Bag / Sacaria pelo nome do produto ────────────────────────
function hfTipoProduto(string $nome): string
{
    $n = strtoupper($nome);
    if (str_contains($n, 'SACARIA')) {
        return 'SACARIA';
    }
    if (str_contains($n, 'BIG BAG') || str_contains($n, 'BIGBAG') || preg_match('/\bBAG\b/', $n)) {
        return 'BAG';
    }
    return '';
}

// ── Itens dos pedidos (VW_VENDAS) para classificar Bag/Sacaria ──────────────
// Retorna: [ pedido => ['bag' => qtd, 'sac' => qtd, 'outros' => qtd] ]
function hfFetchItensPorPedido($pg, array $pedidoNums): array
{
    $pedidoNums = array_values(array_unique(array_filter($pedidoNums, fn($v) => $v !== '')));
    if (!$pedidoNums) {
        return [];
    }

    $ph  = implode(',', array_map(fn($i) => '$' . ($i + 1), array_keys($pedidoNums)));
    $sql = "
        SELECT VV.PEDIDO::TEXT               AS pedido
              ,COALESCE(VV.PRODUTO::VARCHAR, '') AS produto
              ,COALESCE(VV.QTD, 0)           AS qtd
        FROM VW_VENDAS VV
        WHERE VV.EMP_CODIGO = 1
          AND VV.OPERACAO_PEDIDO = 'V'
          AND VV.PEDIDO::TEXT IN ({$ph})
    ";

    $res = @pg_query_params($pg, $sql, $pedidoNums);
    if (!$res) {
        return [];
    }

    $map = [];
    while ($r = pg_fetch_assoc($res)) {
        $ped = (string) $r['pedido'];
        $qtd = (float) $r['qtd'];
        $map[$ped] ??= ['bag' => 0.0, 'sac' => 0.0, 'outros' => 0.0];
        $tipo = hfTipoProduto((string) $r['produto']);
        if ($tipo === 'BAG') {
            $map[$ped]['bag'] += $qtd;
        } elseif ($tipo === 'SACARIA') {
            $map[$ped]['sac'] += $qtd;
        } else {
            $map[$ped]['outros'] += $qtd;
        }
    }
    pg_free_result($res);
    return $map;
}

// ── WHERE base + filtros ────────────────────────────────────────────────────
function hfBuildWhere(array $filters, array &$params): string
{
    $extras = [];

    // "Saiu na data" = OP finalizada dentro do intervalo (PRODUCAO.DATA_FINALIZA)
    $params[] = $filters['data_ini'];
    $extras[] = 'PR.DATA_FINALIZA >= $' . count($params);
    $params[] = $filters['data_fim'];
    $extras[] = 'PR.DATA_FINALIZA <= $' . count($params);

    if ($filters['pedido'] !== '') {
        $params[] = '%' . $filters['pedido'] . '%';
        $extras[] = 'PR.VENDA_REF::TEXT ILIKE $' . count($params);
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

    return "PR.EMP_CODIGO = 1
          AND TRIM(PR.VENDA_REF) ~ '^[0-9]+$'
          AND " . implode("\n          AND ", $extras);
}

// ── Lista de pedidos que saíram da produção no período ──────────────────────
// Uma linha por pedido; data de saída = maior DATA_FINALIZA entre as OPs.
// VENDA_REF é texto livre no ERP (às vezes vazio ou com lixo tipo "AMOSTRA"),
// por isso o filtro/limpeza acima antes de convertê-lo para o pedido numérico.
function hfFetchPedidos($pg, array $filters): array
{
    $params = [];
    $where  = hfBuildWhere($filters, $params);

    $sql = "
        SELECT
             TRIM(PR.VENDA_REF)                                                   AS pedido
            ,TO_CHAR(MAX(PR.DATA_FINALIZA), 'YYYY-MM-DD')                         AS data_finaliza
            ,MIN(COALESCE(LPAD(V.VEN_NUMERO_DFE::VARCHAR, 9, '0'), ''))           AS numeracao
            ,MIN(COALESCE(LPAD(V.VEN_SERIE_DFE, 3, '0'), ''))                     AS serie
            ,MIN(COALESCE(V.VEN_STATUS, 'V'))                                     AS status
            ,MIN(COALESCE(V.VEN_QUANTIDADE, 0))                                   AS quantidade
            ,MIN(IIF(COALESCE(C.CLI_NOME_FANTASIA, '') = ''
                    ,COALESCE(C.CLI_NOME, '')
                    ,C.CLI_NOME_FANTASIA))                                        AS cliente_fantasia
            ,MIN(COALESCE(C.CLI_CIDADE, ''))                                      AS cidade
            ,MIN(COALESCE(R.RE_NOME, ''))                                         AS representante
        FROM      PRODUCAO       PR
        LEFT JOIN VENDAS         V ON V.VEN_COD_PEDIDO = TRIM(PR.VENDA_REF)::INTEGER AND V.EMP_CODIGO = 1
        LEFT JOIN CLIENTES       C ON C.CLI_CODIGO     = COALESCE(V.CLI_CODIGO, PR.CLI_CODIGO)
        LEFT JOIN REPRESENTANTES R ON R.RE_CODIGO      = V.RE_CODIGO
        WHERE {$where}
        GROUP BY TRIM(PR.VENDA_REF)
        ORDER BY data_finaliza DESC, pedido DESC
    ";

    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar a produção do ERP: ' . pg_last_error($pg));
    }

    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $row['quantidade'] = ((string) $row['status'] === 'D' ? -1 : 1) * (float) $row['quantidade'];
        $rows[] = $row;
    }
    pg_free_result($res);
    return $rows;
}

// ── Filtros da requisição (padrão = mês corrente) ───────────────────────────
$tipoFiltro = strtolower(trim((string) ($_GET['tipo'] ?? '')));
if (!in_array($tipoFiltro, ['bag', 'sacaria'], true)) {
    $tipoFiltro = '';
}

$filters = [
    'data_ini'      => hfSanitizeDate($_GET['data_ini'] ?? '') ?: date('Y-m-01'),
    'data_fim'      => hfSanitizeDate($_GET['data_fim'] ?? '') ?: date('Y-m-t'),
    'pedido'        => trim((string) ($_GET['pedido'] ?? '')),
    'cliente'       => trim((string) ($_GET['cliente'] ?? '')),
    'representante' => trim((string) ($_GET['representante'] ?? '')),
    'tipo'          => $tipoFiltro,
];

// ── Conexão ERP ────────────────────────────────────────────────────────────
$pg      = pcpGetPG();
$error   = '';
$pedidos = [];

if (!$pg) {
    $error = 'Falha ao conectar no ERP.';
} else {
    try {
        $pedidos = hfFetchPedidos($pg, $filters);

        // Classificação Bag/Sacaria por pedido (via itens da VW_VENDAS)
        $itensMap = hfFetchItensPorPedido($pg, array_column($pedidos, 'pedido'));
        foreach ($pedidos as &$row) {
            $ag = $itensMap[$row['pedido']] ?? ['bag' => 0.0, 'sac' => 0.0, 'outros' => 0.0];
            $sinal = (($row['status'] ?? 'V') === 'D') ? -1 : 1;
            $row['qtd_bag'] = $ag['bag'] * $sinal;
            $row['qtd_sac'] = $ag['sac'] * $sinal;
            $itemTotal      = $ag['bag'] + $ag['sac'] + $ag['outros'];
            // usa o total dos itens quando disponível; senão o total do cabeçalho
            $row['quantidade'] = $itemTotal > 0 ? $itemTotal * $sinal : (float) $row['quantidade'];
            $row['tipo'] = match (true) {
                $ag['bag'] > 0 && $ag['sac'] > 0 => 'Bag + Sacaria',
                $ag['bag'] > 0                    => 'Bag',
                $ag['sac'] > 0                    => 'Sacaria',
                default                          => '—',
            };
        }
        unset($row);

        // Filtro Bag/Sacaria
        if ($tipoFiltro === 'bag') {
            $pedidos = array_values(array_filter($pedidos, fn($r) => abs($r['qtd_bag']) > 0));
        } elseif ($tipoFiltro === 'sacaria') {
            $pedidos = array_values(array_filter($pedidos, fn($r) => abs($r['qtd_sac']) > 0));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    pg_close($pg);
}

$totalPedidos  = count($pedidos);
$totalPecas    = array_sum(array_map(fn($r) => (float) ($r['quantidade'] ?? 0), $pedidos));
$totalBag      = array_sum(array_map(fn($r) => (float) ($r['qtd_bag'] ?? 0), $pedidos));
$totalSacaria  = array_sum(array_map(fn($r) => (float) ($r['qtd_sac'] ?? 0), $pedidos));

$qsPdf = http_build_query(array_filter([
    'data_ini'      => $filters['data_ini'],
    'data_fim'      => $filters['data_fim'],
    'pedido'        => $filters['pedido'],
    'cliente'       => $filters['cliente'],
    'representante' => $filters['representante'],
    'tipo'          => $filters['tipo'],
], fn($v) => $v !== ''));
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Faturamento | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .kpi-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(220px, 320px)); gap: 14px; margin-bottom: 20px; }

  .filter-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 16px 18px; margin-bottom: 16px;
  }
  .filter-grid {
    display: grid;
    grid-template-columns: 150px 150px 150px 180px minmax(220px, 1fr) minmax(200px, 1fr);
    gap: 16px; align-items: end;
  }
  .filter-field { display: flex; flex-direction: column; gap: 6px; }
  .filter-field label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
  }
  .filter-field input, .filter-field select {
    height: 36px; padding: 0 10px; font-size: 12.5px; width: 100%; min-width: 0;
    font-family: 'Segoe UI', sans-serif;
  }
  .filter-field select option { background: var(--blue-mid); }

  .totalizador {
    display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;
  }
  .tot-card {
    flex: 1; min-width: 150px;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 12px 16px;
  }
  .tot-card.tot-main { border-color: rgba(79,123,255,.35); background: rgba(36,83,212,.08); }
  .tot-lbl { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
  .tot-val { font-size: 20px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
  .filter-actions { margin-top: 16px; display: flex; gap: 12px; }

  .hf-right  { text-align: right; white-space: nowrap; }
  .hf-nowrap { white-space: nowrap; }
  .hf-num    { text-align: right; color: var(--text-muted); font-size: 12px; }
  .hf-pedido-col  { font-weight: 700; color: #7db3ff; white-space: nowrap; }
  .hf-cliente-col { min-width: 200px; font-weight: 500; }
  .hf-doc { font-variant-numeric: tabular-nums; white-space: nowrap; }

  .hf-badge-d {
    display: inline-block; padding: 1px 7px; border-radius: 999px;
    font-size: 10.5px; font-weight: 700; background: rgba(239,68,68,.12); color: var(--red);
  }

  .hf-empty-state { padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px; }
  .alert-error {
    padding: 12px 16px; border-radius: var(--radius); background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2); color: var(--red); font-size: 13px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
  }

  @media (max-width: 900px) {
    .kpi-grid-2 { grid-template-columns: 1fr; }
    .filter-grid { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Histórico de Faturamento</h1>
          <p>Pedidos que saíram da produção no período — pela data de finalização da OP. Somente leitura.</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn-secondary" id="btnPdf">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Gerar PDF
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= hfEscape($error) ?>
        </div>
      <?php endif; ?>

      <!-- Filtros -->
      <div class="filter-card">
        <form method="get" action="<?= hfEscape(hfBaseUrl()) ?>">
          <div class="filter-grid">
            <div class="filter-field">
              <label for="data_ini">Saída inicial</label>
              <input type="date" id="data_ini" name="data_ini" value="<?= hfEscape($filters['data_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="data_fim">Saída final</label>
              <input type="date" id="data_fim" name="data_fim" value="<?= hfEscape($filters['data_fim']) ?>">
            </div>
            <div class="filter-field">
              <label for="pedido">Pedido</label>
              <input type="text" id="pedido" name="pedido" value="<?= hfEscape($filters['pedido']) ?>" placeholder="Ex.: 2587">
            </div>
            <div class="filter-field">
              <label for="cliente">Cliente</label>
              <input type="text" id="cliente" name="cliente" value="<?= hfEscape($filters['cliente']) ?>" placeholder="Nome do cliente">
            </div>
            <div class="filter-field">
              <label for="representante">Representante</label>
              <input type="text" id="representante" name="representante" value="<?= hfEscape($filters['representante']) ?>" placeholder="Nome do representante">
            </div>
            <div class="filter-field">
              <label for="tipo">Tipo</label>
              <select id="tipo" name="tipo">
                <option value="">Bag e Sacaria</option>
                <option value="bag"     <?= $filters['tipo'] === 'bag' ? 'selected' : '' ?>>Somente Bag</option>
                <option value="sacaria" <?= $filters['tipo'] === 'sacaria' ? 'selected' : '' ?>>Somente Sacaria</option>
              </select>
            </div>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-refresh">Filtrar</button>
            <a href="<?= hfEscape(hfBaseUrl()) ?>" class="icon-btn" title="Limpar filtros" style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
          </div>
        </form>
      </div>

      <!-- Totalizador de saída -->
      <div class="totalizador">
        <div class="tot-card tot-main">
          <div class="tot-lbl">Saída total</div>
          <div class="tot-val"><?= hfQty($totalPecas) ?> <span style="font-size:12px;font-weight:500;color:var(--text-muted);">peças</span></div>
        </div>
        <div class="tot-card">
          <div class="tot-lbl">Bag</div>
          <div class="tot-val"><?= hfQty($totalBag) ?></div>
        </div>
        <div class="tot-card">
          <div class="tot-lbl">Sacaria</div>
          <div class="tot-val"><?= hfQty($totalSacaria) ?></div>
        </div>
        <div class="tot-card">
          <div class="tot-lbl">Pedidos</div>
          <div class="tot-val"><?= hfQty($totalPedidos) ?></div>
        </div>
      </div>

      <!-- Pedidos -->
      <div class="panel-table">
        <div class="panel-header">
          <span class="panel-title">Pedidos que saíram da produção</span>
          <span class="source-badge src-erp">ERP</span>
        </div>
        <div class="table-wrap">
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
                <th class="hf-right">Bag</th>
                <th class="hf-right">Sacaria</th>
                <th class="hf-right">Peças</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pedidos): ?>
                <tr><td colspan="10"><div class="hf-empty-state">Nenhum pedido finalizado com os filtros informados.</div></td></tr>
              <?php else: foreach ($pedidos as $row):
                  $isDev = ($row['status'] ?? 'V') === 'D';
                  $doc   = trim(($row['numeracao'] ?? '') . ($row['serie'] ? ' / ' . $row['serie'] : ''));
                  ?>
                <tr>
                  <td class="hf-nowrap"><?= hfEscape(hfFmtDate($row['data_finaliza'] ?? '')) ?></td>
                  <td class="hf-pedido-col">
                    <?= hfEscape($row['pedido'] ?? '—') ?>
                    <?php if ($isDev): ?><span class="hf-badge-d" title="Devolução">DEV</span><?php endif; ?>
                  </td>
                  <td class="hf-doc"><?= $doc !== '' ? hfEscape($doc) : '—' ?></td>
                  <td class="hf-cliente-col"><?= hfEscape($row['cliente_fantasia'] !== '' ? $row['cliente_fantasia'] : '—') ?></td>
                  <td class="hf-nowrap"><?= hfEscape($row['cidade'] !== '' ? $row['cidade'] : '—') ?></td>
                  <td class="hf-nowrap"><?= hfEscape($row['representante'] !== '' ? $row['representante'] : '—') ?></td>
                  <td class="hf-nowrap"><?= hfEscape($row['tipo'] ?? '—') ?></td>
                  <td class="hf-num"><?= ((float) ($row['qtd_bag'] ?? 0)) != 0.0 ? hfQty($row['qtd_bag']) : '—' ?></td>
                  <td class="hf-num"><?= ((float) ($row['qtd_sac'] ?? 0)) != 0.0 ? hfQty($row['qtd_sac']) : '—' ?></td>
                  <td class="hf-num"><?= hfQty($row['quantidade'] ?? 0) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
            <?php if ($pedidos): ?>
            <tfoot>
              <tr>
                <td colspan="6" style="text-align:right;font-weight:700;border-top:2px solid var(--border);">Total</td>
                <td style="border-top:2px solid var(--border);"></td>
                <td class="hf-right" style="font-weight:700;border-top:2px solid var(--border);"><?= hfQty($totalBag) ?></td>
                <td class="hf-right" style="font-weight:700;border-top:2px solid var(--border);"><?= hfQty($totalSacaria) ?></td>
                <td class="hf-right" style="font-weight:700;border-top:2px solid var(--border);"><?= hfQty($totalPecas) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
  const PDF_QS = <?= json_encode($qsPdf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.getElementById('btnPdf').addEventListener('click', () => {
    window.open('/relatorios/historico-faturamento/pdf' + (PDF_QS ? '?' + PDF_QS : ''), '_blank');
  });
</script>
</body>
</html>
