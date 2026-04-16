<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/fin_schema.php';

$hoje = date('Y-m-d');

// ── Contas a Receber ERP Yzidro (PostgreSQL) ─────────────────────────────────
$yzAno    = (int)($_GET['yz_ano']    ?? date('Y'));
$yzStatus = trim((string)($_GET['yz_status'] ?? ''));
$yzQ      = trim((string)($_GET['yz_q']      ?? ''));

$yzRows   = [];
$yzErp    = '';
$yzTotals = ['a_receber' => 0.0, 'recebido' => 0.0, 'vencido' => 0.0];

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';
    $pg = dbPG();
    if ($pg) {
        $params = [
            date('Y-m-d', mktime(0, 0, 0, 1,  1, $yzAno)),
            date('Y-m-d', mktime(0, 0, 0, 12, 31, $yzAno)),
        ];
        $extras = [];
        if ($yzStatus !== '') {
            if ($yzStatus === 'Recebido') {
                $extras[] = "COALESCE(CR.CR_SALDO_PARCELA_MP, 0) <= 0";
            } else {
                $extras[] = "COALESCE(CR.CR_SALDO_PARCELA_MP, 0) > 0";
            }
        }
        if ($yzQ !== '') {
            $params[] = '%' . $yzQ . '%';
            $idx       = count($params);
            $extras[]  = "(UPPER(CL.CLI_NOME) ILIKE \${$idx} OR UPPER(CL.CLI_NOME_FANTASIA) ILIKE \${$idx} OR UPPER(CR.CR_OPERACAO) ILIKE \${$idx} OR UPPER(CR.CR_NUMERACAO_DFE::TEXT) ILIKE \${$idx})";
        }
        $extraWhere = $extras ? 'AND ' . implode(' AND ', $extras) : '';

        $sql = "
            WITH BAIXA AS (
                SELECT BR.CR_CODIGO_CR                                    AS CR_CODIGO,
                       SUM(COALESCE(BR.BXR_TROCO_MP, 0))::NUMERIC(18,2)  AS BXR_TROCO_MP
                  FROM BAIXA_CRECEBER BR
                 INNER JOIN CONTAS_RECEBER CR2 ON CR2.CR_CODIGO = BR.CR_CODIGO_CR
                 WHERE CR2.CR_VENCIMENTO >= \$1
                   AND CR2.CR_VENCIMENTO <= \$2
                   AND CR2.EMP_CODIGO = 1
                 GROUP BY BR.CR_CODIGO_CR
            )
            SELECT CR.CR_CODIGO,
                   CR.CR_OPERACAO                                                      AS descricao_operacao,
                   COALESCE(CR.CR_COMPLEMENTO, '')                                    AS complemento,
                   TO_CHAR(CR.CR_DATA_EMISSAO, 'YYYY-MM-DD')                         AS emissao,
                   TO_CHAR(CR.CR_VENCIMENTO,   'YYYY-MM-DD')                         AS vencimento,
                   CASE WHEN COALESCE(CR.CR_TOTAL_PARCELA, 0) > 0
                        THEN CR.CR_NUM_PARCELA::TEXT || '/' || CR.CR_TOTAL_PARCELA::TEXT
                        ELSE COALESCE(CR.CR_NUM_PARCELA::TEXT, '1') END               AS parc,
                   CAST(COALESCE(CR.CR_VALOR_PARCELA_MP,  0) AS NUMERIC(18,2))       AS valor_parcela,
                   CAST(COALESCE(CR.CR_VALOR_RECEBIDO_MP, 0) AS NUMERIC(18,2))       AS valor_baixa,
                   CAST(COALESCE(CR.CR_SALDO_PARCELA_MP,  0) AS NUMERIC(18,2))       AS saldo_parcela,
                   COALESCE(CL.CLI_NOME_FANTASIA, '')                                 AS nome_fantasia,
                   COALESCE(CL.CLI_NOME, '')                                          AS nome_cliente,
                   COALESCE(CL.CLI_CNPJ_CPF, '')                                     AS cnpj_cliente,
                   COALESCE(CL.CLI_CIDADE, '')                                        AS cidade,
                   COALESCE(ES.DESCRICAO, '')                                         AS estado,
                   COALESCE(RE.RE_NOME, '')                                           AS representante,
                   COALESCE(PC.CO_ESTRUTURA || ' - ' || PC.CO_DESCRICAO, '')         AS plano_contas,
                   COALESCE(CC.CC_DESCRICAO, '')                                      AS centro_custo,
                   COALESCE(CR.CR_NUMERACAO_DFE::TEXT, '')                           AS numero_dfe,
                   COALESCE(CR.CR_SERIE_DFE::TEXT, '')                               AS serie_dfe,
                   CASE WHEN COALESCE(CR.CR_SALDO_PARCELA_MP, 0) <= 0 THEN 'Recebido'
                        ELSE 'A Receber' END                                          AS status_desc
            FROM CONTAS_RECEBER CR
            INNER JOIN EMPRESA        E  ON E.EMP_CODIGO    = CR.EMP_CODIGO
            INNER JOIN CLIENTES       CL ON CL.CLI_CODIGO   = CR.CLI_CODIGO
            INNER JOIN ESTADO         ES ON ES.ES_CODIGO    = CL.ES_CODIGO
            INNER JOIN PLANO_CONTAS   PC ON PC.CO_CODIGO    = CR.CO_CODIGO
             LEFT JOIN CENTROCUSTO    CC ON CC.CC_CODIGO    = CR.CC_CODIGO
             LEFT JOIN VENDAS          V ON V.VEN_COD_PEDIDO = CR.VEN_COD_PEDIDO
             LEFT JOIN BAIXA          BR ON BR.CR_CODIGO    = CR.CR_CODIGO
             LEFT JOIN REPRESENTANTES RE ON RE.RE_CODIGO    = COALESCE(V.RE_CODIGO, CL.RE_CODIGO)
            WHERE CR.CR_TIPO     = 'C'
              AND CR.CR_VINCULO  IS NULL
              AND CR.EMP_CODIGO  = 1
              AND CR.CR_VENCIMENTO >= \$1
              AND CR.CR_VENCIMENTO <= \$2
              {$extraWhere}
            GROUP BY CR.CR_CODIGO, CL.CLI_NOME, CL.CLI_NOME_FANTASIA, CL.CLI_CNPJ_CPF,
                     CL.CLI_CIDADE, ES.DESCRICAO, RE.RE_NOME, PC.CO_ESTRUTURA, PC.CO_DESCRICAO,
                     CC.CC_DESCRICAO, BR.BXR_TROCO_MP
            ORDER BY CR.CR_VENCIMENTO ASC, CR.CR_CODIGO ASC
        ";

        $res = @pg_query_params($pg, $sql, $params);
        if ($res) {
            while ($row = pg_fetch_assoc($res)) {
                $yzRows[] = $row;
                $val   = (float)$row['valor_parcela'];
                $saldo = (float)$row['saldo_parcela'];
                $venc  = $row['vencimento'];
                $st    = $row['status_desc'];
                if ($st === 'Recebido')    $yzTotals['recebido']  += $val;
                elseif ($venc < $hoje)     $yzTotals['vencido']   += $saldo;
                else                       $yzTotals['a_receber'] += $saldo;
            }
            pg_free_result($res);
        } else {
            $yzErp = pg_last_error($pg);
        }
        pg_close($pg);
    } else {
        $yzErp = 'Sem conexão com o ERP Yzidro (PostgreSQL).';
    }
} catch (Throwable $e) {
    $yzErp = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contas a Receber | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.sum-bar { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.sum-chip { display:inline-flex; flex-direction:column; gap:3px; padding:14px 18px; border-radius:12px; border:1px solid var(--border); background:rgba(255,255,255,.025); min-width:140px; }
.sum-chip-val { font-size:17px; font-weight:800; }
.sum-chip-lbl { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }

.filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:14px 16px; border-bottom:1px solid var(--border); }
.filter-bar .field { min-width:120px; }
.filter-bar .field label { font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px; }
.filter-bar input, .filter-bar select { height:34px; font-size:12px; }

.fin-tbl { width:100%; border-collapse:collapse; }
.fin-tbl td { padding:11px 14px; font-size:12.5px; border-bottom:1px solid var(--border); color:var(--text-primary); vertical-align:middle; }
.fin-tbl tbody tr:hover td { background:rgba(255,255,255,.025); }
.fin-tbl .vencido-row td { background:rgba(239,68,68,.03); }

.yz-st-areceber   { background:rgba(45,106,255,.1);   color:#7db3ff;      padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.yz-st-recebido   { background:rgba(16,185,129,.12);  color:#10b981;      padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.yz-st-vencido    { background:rgba(239,68,68,.12);   color:var(--red);   padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.alert-error-sm { padding:10px 14px; border-radius:8px; font-size:12.5px; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.2); color:var(--red); margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.empty-state { padding:60px 20px; text-align:center; color:var(--text-muted); font-size:14px; }
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">

<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Contas a Receber</h1>
      <p>Relatório de recebimentos — ERP Yzidro.</p>
    </div>
  </div>
</header>

<div class="content">

  <?php if ($yzErp !== ''): ?>
    <div class="alert-error-sm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= finH($yzErp) ?>
    </div>
  <?php else: ?>

  <!-- Totais -->
  <div class="sum-bar">
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:#7db3ff;"><?= finMoney($yzTotals['a_receber']) ?></div>
      <div class="sum-chip-lbl">A Receber</div>
    </div>
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:var(--red);"><?= finMoney($yzTotals['vencido']) ?></div>
      <div class="sum-chip-lbl">Vencido</div>
    </div>
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:#10b981;"><?= finMoney($yzTotals['recebido']) ?></div>
      <div class="sum-chip-lbl">Recebido</div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="panel" style="margin-bottom:16px;">
    <form method="GET" action="">
      <div class="filter-bar">
        <div class="field">
          <label>Ano</label>
          <select name="yz_ano">
            <?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?>
              <option value="<?= $y ?>" <?= $yzAno === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="yz_status">
            <option value="">Todos</option>
            <?php foreach (['A Receber','Recebido'] as $st): ?>
              <option value="<?= $st ?>" <?= $yzStatus === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="min-width:220px;">
          <label>Cliente / Descrição / NF</label>
          <input type="text" name="yz_q" value="<?= finH($yzQ) ?>" placeholder="Buscar…">
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
          <button type="submit" class="btn-primary" style="height:34px;">Filtrar</button>
          <a href="/financeiro/receber" class="btn-secondary" style="height:34px;display:inline-flex;align-items:center;">Limpar</a>
        </div>
      </div>
    </form>
  </div>

  <!-- Tabela -->
  <div class="panel-table">
    <div class="panel-header">
      <span class="panel-title">Lançamentos — <?= $yzAno ?></span>
      <span class="count-badge"><?= count($yzRows) ?></span>
    </div>
    <?php if (empty($yzRows)): ?>
      <div class="empty-state">Nenhum lançamento encontrado para o período/filtros informados.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="fin-tbl">
        <thead>
          <tr>
            <th>NF / Doc.</th>
            <th>Descrição / Complemento</th>
            <th>Cliente</th>
            <th>Representante</th>
            <th>Plano de Contas</th>
            <th>Parcela</th>
            <th>Emissão</th>
            <th>Vencimento</th>
            <th style="text-align:right">Valor</th>
            <th style="text-align:right">Recebido</th>
            <th style="text-align:right">Saldo</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($yzRows as $r):
          $st        = $r['status_desc'];
          $vencDt    = $r['vencimento'];
          $atrasado  = ($st === 'A Receber') && $vencDt < $hoje;
          $stEffective = $atrasado ? 'Vencido' : $st;
          $stClass   = match($stEffective) {
              'Recebido' => 'yz-st-recebido',
              'Vencido'  => 'yz-st-vencido',
              default    => 'yz-st-areceber',
          };
          $cliente = $r['nome_fantasia'] ?: $r['nome_cliente'];
        ?>
          <tr class="<?= $atrasado ? 'vencido-row' : '' ?>">
            <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;">
              <?= $r['numero_dfe'] && $r['numero_dfe'] !== '0' ? finH($r['numero_dfe']) . ($r['serie_dfe'] ? '-' . finH($r['serie_dfe']) : '') : '—' ?>
            </td>
            <td>
              <div style="font-weight:600;"><?= finH($r['descricao_operacao'] ?: '—') ?></div>
              <?php if ($r['complemento']): ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= finH(mb_strimwidth($r['complemento'], 0, 70, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;">
              <div style="font-weight:600;"><?= finH($cliente) ?></div>
              <?php if ($r['cnpj_cliente']): ?><div style="font-size:10.5px;color:var(--text-muted);"><?= finH($r['cnpj_cliente']) ?></div><?php endif; ?>
              <?php if ($r['cidade'] || $r['estado']): ?><div style="font-size:10.5px;color:var(--text-muted);"><?= finH(trim($r['cidade'] . ' - ' . $r['estado'], ' -')) ?></div><?php endif; ?>
            </td>
            <td style="font-size:11.5px;"><?= finH($r['representante'] ?: '—') ?></td>
            <td style="font-size:11px;color:var(--text-muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= finH($r['plano_contas']) ?>"><?= finH($r['plano_contas'] ?: '—') ?></td>
            <td style="font-size:12px;text-align:center;white-space:nowrap;"><?= finH($r['parc']) ?></td>
            <td style="white-space:nowrap;font-size:12px;"><?= $r['emissao']   ? date('d/m/Y', strtotime($r['emissao']))   : '—' ?></td>
            <td style="white-space:nowrap;<?= $atrasado ? 'color:var(--red);font-weight:700;' : '' ?>">
              <?= $r['vencimento'] ? date('d/m/Y', strtotime($r['vencimento'])) : '—' ?>
            </td>
            <td style="text-align:right;white-space:nowrap;font-weight:700;"><?= finMoney((float)$r['valor_parcela']) ?></td>
            <td style="text-align:right;white-space:nowrap;color:<?= (float)$r['valor_baixa'] > 0 ? '#10b981' : 'var(--text-muted)' ?>;">
              <?= (float)$r['valor_baixa'] > 0 ? finMoney((float)$r['valor_baixa']) : '—' ?>
            </td>
            <td style="text-align:right;white-space:nowrap;font-weight:700;<?= $atrasado ? 'color:var(--red);' : '' ?>"><?= finMoney((float)$r['saldo_parcela']) ?></td>
            <td><span class="<?= $stClass ?>"><?= finH($stEffective) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>

</div>
</div>
</div>
</body>
</html>
