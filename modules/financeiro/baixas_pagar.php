<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/fin_schema.php';

// ── Baixas (Lançamentos Pagos) ERP Yzidro (PostgreSQL) ───────────────────────
$yzbAno  = (int)($_GET['yzb_ano'] ?? date('Y'));
$yzbQ    = trim((string)($_GET['yzb_q'] ?? ''));

$yzbRows   = [];
$yzbErp    = '';
$yzbTotals = ['pago' => 0.0, 'desconto' => 0.0, 'acrescimo' => 0.0];

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';
    $pg = dbPG();
    if ($pg) {
        $p2 = [
            date('Y-m-d', mktime(0, 0, 0, 1,  1, $yzbAno)),
            date('Y-m-d', mktime(0, 0, 0, 12, 31, $yzbAno)),
        ];
        $ex2 = [];
        if ($yzbQ !== '') {
            $p2[]  = '%' . $yzbQ . '%';
            $idx   = count($p2);
            $ex2[] = "(UPPER(F.FOR_NOME) ILIKE \${$idx} OR UPPER(F.FOR_NOMEFANTASIA) ILIKE \${$idx} OR UPPER(CP.CP_OPERACAO) ILIKE \${$idx} OR UPPER(CP.CP_NUM_DOCUMENTO) ILIKE \${$idx})";
        }
        $exWhere2 = $ex2 ? 'AND ' . implode(' AND ', $ex2) : '';

        $sql2 = "
            SELECT B.CP_CODIGO                                                         AS cod_baixa,
                   B.CP_CODIGO_CP                                                      AS cod_lancamento,
                   CP.CP_OPERACAO                                                      AS descricao_operacao,
                   CASE WHEN COALESCE(CP.CP_TOTAL_PARCELA,0) > 0
                        THEN CP.CP_NUM_PARCELA::TEXT||'/'||CP.CP_TOTAL_PARCELA::TEXT
                        ELSE COALESCE(CP.CP_NUM_PARCELA::TEXT,'1') END                 AS parc,
                   COALESCE(CP.CP_NUM_DOCUMENTO, '')                                   AS num_documento,
                   TO_CHAR(CP.CP_DATA_EMISSAO,   'YYYY-MM-DD')                        AS data_emissao,
                   TO_CHAR(CP.CP_VENCIMENTO,      'YYYY-MM-DD')                        AS data_venc,
                   TO_CHAR(B.BCP_DATA_PAGTO,      'YYYY-MM-DD')                        AS data_baixa,
                   COALESCE(F.FOR_NOME, '')                                            AS fornecedor,
                   COALESCE(F.FOR_NOMEFANTASIA, '')                                    AS nome_fantasia,
                   COALESCE(F.FOR_CNPJ, '')                                            AS cnpj_fornecedor,
                   COALESCE(TP.TP_DESCRICAO, '')                                       AS tipo_pagamento,
                   COALESCE(CP.CP_COMPLEMENTO, '')                                     AS historico,
                   COALESCE(PC.CO_ESTRUTURA||' - '||PC.CO_DESCRICAO, '')              AS plano_contas,
                   COALESCE(CC.CC_DESCRICAO, '')                                       AS centro_custo,
                   COALESCE(CAST(BC.BAN_NOME||' - '||CB.CB_CONTA AS VARCHAR(67)),'') AS conta_destinada,
                   CAST(COALESCE(CP.CP_VALOR_PARCELA_MP, 0) AS NUMERIC(18,2))         AS valor_parcela,
                   CAST(COALESCE(CP.CP_ACRECIMO_MP,      0) AS NUMERIC(18,2))         AS acrescimo,
                   CAST(COALESCE(CP.CP_ABATIMENTO_MP,    0) AS NUMERIC(18,2))         AS desconto,
                   CAST(COALESCE(CP.CP_VALOR_TOTAL_MP,   0) AS NUMERIC(18,2))         AS total_parcela,
                   CAST(COALESCE(B.BCP_VALOR_MP,         0) AS NUMERIC(18,2))         AS valor_pago,
                   CAST(COALESCE(B.BCP_TROCO_MP,         0) AS NUMERIC(18,2))         AS troco,
                   CAST(DATEDIFF('DAY', CP.CP_DATA_EMISSAO, B.BCP_DATA_VENCIMENTO) AS FLOAT) AS dias_pagto
              FROM BAIXA_CPAGAR          B
             INNER JOIN CONTAS_PAGAR    CP ON CP.CP_CODIGO  = B.CP_CODIGO_CP
             INNER JOIN EMPRESA          E ON E.EMP_CODIGO  = CP.EMP_CODIGO
             INNER JOIN CONTAS          CB ON CB.CB_CODIGO  = B.CB_CODIGO
             INNER JOIN PLANO_CONTAS    PC ON PC.CO_CODIGO  = B.CO_CODIGO
             INNER JOIN AGENCIA          A ON A.AG_CODIGO   = CB.AG_CODIGO
             INNER JOIN BANCOS          BC ON BC.BAN_CODIGO = A.BAN_CODIGO
             INNER JOIN FORNECEDOR       F ON F.FOR_CODIGO  = CP.FOR_CODIGO
              LEFT JOIN CENTROCUSTO     CC ON CC.CC_CODIGO  = CP.CC_CODIGO
              LEFT JOIN TIPO_PAGAMENTO  TP ON TP.TP_CODIGO  = CP.TP_CODIGO
             WHERE CP.CP_VINCULO IS NULL
               AND CP.EMP_CODIGO = 1
               AND CP.CP_DATA_EMISSAO >= \$1
               AND CP.CP_DATA_EMISSAO <= \$2
               {$exWhere2}
             GROUP BY B.CP_CODIGO, CP.CP_OPERACAO, CP.CP_TOTAL_PARCELA, CP.CP_NUM_PARCELA,
                      CP.CP_NUM_DOCUMENTO, CP.CP_DATA_EMISSAO, CP.CP_VENCIMENTO,
                      B.BCP_DATA_PAGTO, B.BCP_DATA_VENCIMENTO,
                      F.FOR_CODIGO, F.FOR_NOME, F.FOR_NOMEFANTASIA, F.FOR_CNPJ,
                      TP.TP_DESCRICAO, CP.CP_COMPLEMENTO, PC.CO_ESTRUTURA, PC.CO_DESCRICAO,
                      CC.CC_DESCRICAO, BC.BAN_NOME, CB.CB_CONTA,
                      CP.CP_VALOR_PARCELA_MP, CP.CP_ACRECIMO_MP, CP.CP_ABATIMENTO_MP,
                      CP.CP_VALOR_TOTAL_MP, B.BCP_VALOR_MP, B.BCP_TROCO_MP
             ORDER BY B.BCP_DATA_PAGTO DESC, B.CP_CODIGO DESC
        ";

        $res2 = @pg_query_params($pg, $sql2, $p2);
        if ($res2) {
            while ($row = pg_fetch_assoc($res2)) {
                $yzbRows[] = $row;
                $yzbTotals['pago']      += (float)$row['valor_pago'];
                $yzbTotals['desconto']  += (float)$row['desconto'];
                $yzbTotals['acrescimo'] += (float)$row['acrescimo'];
            }
            pg_free_result($res2);
        } else {
            $yzbErp = pg_last_error($pg);
        }
        pg_close($pg);
    } else {
        $yzbErp = 'Sem conexão com o ERP Yzidro (PostgreSQL).';
    }
} catch (Throwable $e) {
    $yzbErp = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Baixas a Pagar | DOMBAG</title>
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
.alert-error-sm { padding:10px 14px; border-radius:8px; font-size:12.5px; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.2); color:var(--red); margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.dias-badge { display:inline-flex; padding:2px 8px; border-radius:6px; font-size:10.5px; font-weight:700; }
.dias-ok    { background:rgba(16,185,129,.1);  color:#10b981; }
.dias-tarde { background:rgba(239,68,68,.1);   color:var(--red); }
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
      <h1>Baixas a Pagar</h1>
      <p>Histórico de pagamentos realizados — ERP Yzidro.</p>
    </div>
  </div>
</header>

<div class="content">

  <?php if ($yzbErp !== ''): ?>
    <div class="alert-error-sm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= finH($yzbErp) ?>
    </div>
  <?php else: ?>

  <!-- Totais -->
  <div class="sum-bar">
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:#10b981;"><?= finMoney($yzbTotals['pago']) ?></div>
      <div class="sum-chip-lbl">Total pago</div>
    </div>
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:var(--red);"><?= finMoney($yzbTotals['desconto']) ?></div>
      <div class="sum-chip-lbl">Descontos obtidos</div>
    </div>
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:var(--amber);"><?= finMoney($yzbTotals['acrescimo']) ?></div>
      <div class="sum-chip-lbl">Acréscimos pagos</div>
    </div>
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:var(--text-muted);"><?= number_format(count($yzbRows), 0, ',', '.') ?></div>
      <div class="sum-chip-lbl">Baixas encontradas</div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="panel" style="margin-bottom:16px;">
    <form method="GET" action="">
      <div class="filter-bar">
        <div class="field">
          <label>Ano emissão</label>
          <select name="yzb_ano">
            <?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?>
              <option value="<?= $y ?>" <?= $yzbAno === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="field" style="min-width:220px;">
          <label>Fornecedor / Descrição / Doc.</label>
          <input type="text" name="yzb_q" value="<?= finH($yzbQ) ?>" placeholder="Buscar…">
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
          <button type="submit" class="btn-primary" style="height:34px;">Filtrar</button>
          <a href="/financeiro/baixas-pagar" class="btn-secondary" style="height:34px;display:inline-flex;align-items:center;">Limpar</a>
        </div>
      </div>
    </form>
  </div>

  <!-- Tabela -->
  <div class="panel-table">
    <div class="panel-header">
      <span class="panel-title">Baixas de pagamento — emissão <?= $yzbAno ?></span>
      <span class="count-badge"><?= count($yzbRows) ?></span>
    </div>
    <?php if (empty($yzbRows)): ?>
      <div class="empty-state">Nenhuma baixa encontrada para o período/filtros informados.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="fin-tbl">
        <thead>
          <tr>
            <th>Doc.</th>
            <th>Descrição / Histórico</th>
            <th>Fornecedor</th>
            <th>Plano de Contas</th>
            <th>Tipo Pgto.</th>
            <th>Conta</th>
            <th>Parc.</th>
            <th>Emissão</th>
            <th>Vencimento</th>
            <th>Data Pagto.</th>
            <th style="text-align:right">Valor</th>
            <th style="text-align:right">Desconto</th>
            <th style="text-align:right">Acréscimo</th>
            <th style="text-align:right">Pago</th>
            <th>Prazo</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($yzbRows as $r):
          $fornecedor = $r['nome_fantasia'] ?: $r['fornecedor'];
          $dias       = $r['dias_pagto'] !== null ? (int)$r['dias_pagto'] : null;
          $diasClass  = ($dias !== null && $dias > 0) ? 'dias-tarde' : 'dias-ok';
          $diasLabel  = $dias !== null ? abs($dias) . 'd' . ($dias > 0 ? ' atraso' : ' adiant.') : '—';
        ?>
          <tr>
            <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;"><?= finH($r['num_documento'] ?: '—') ?></td>
            <td>
              <div style="font-weight:600;"><?= finH($r['descricao_operacao'] ?: '—') ?></div>
              <?php if ($r['historico']): ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= finH(mb_strimwidth($r['historico'], 0, 70, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;">
              <div style="font-weight:600;"><?= finH($fornecedor) ?></div>
              <?php if ($r['cnpj_fornecedor']): ?><div style="font-size:10.5px;color:var(--text-muted);"><?= finH($r['cnpj_fornecedor']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:11px;color:var(--text-muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= finH($r['plano_contas']) ?>"><?= finH($r['plano_contas'] ?: '—') ?></td>
            <td style="font-size:11.5px;white-space:nowrap;"><?= finH($r['tipo_pagamento'] ?: '—') ?></td>
            <td style="font-size:11px;color:var(--text-muted);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= finH($r['conta_destinada']) ?>"><?= finH($r['conta_destinada'] ?: '—') ?></td>
            <td style="font-size:12px;text-align:center;white-space:nowrap;"><?= finH($r['parc']) ?></td>
            <td style="white-space:nowrap;font-size:12px;"><?= $r['data_emissao'] ? date('d/m/Y', strtotime($r['data_emissao'])) : '—' ?></td>
            <td style="white-space:nowrap;font-size:12px;"><?= $r['data_venc']   ? date('d/m/Y', strtotime($r['data_venc']))   : '—' ?></td>
            <td style="white-space:nowrap;font-weight:600;"><?= $r['data_baixa'] ? date('d/m/Y', strtotime($r['data_baixa'])) : '—' ?></td>
            <td style="text-align:right;white-space:nowrap;"><?= finMoney((float)$r['valor_parcela']) ?></td>
            <td style="text-align:right;white-space:nowrap;color:<?= (float)$r['desconto']  > 0 ? '#10b981'       : 'var(--text-muted)' ?>;"><?= (float)$r['desconto']  > 0 ? finMoney((float)$r['desconto'])  : '—' ?></td>
            <td style="text-align:right;white-space:nowrap;color:<?= (float)$r['acrescimo'] > 0 ? 'var(--amber)' : 'var(--text-muted)' ?>;"><?= (float)$r['acrescimo'] > 0 ? finMoney((float)$r['acrescimo']) : '—' ?></td>
            <td style="text-align:right;white-space:nowrap;font-weight:700;color:#10b981;"><?= finMoney((float)$r['valor_pago']) ?></td>
            <td><?= $dias !== null ? '<span class="dias-badge ' . $diasClass . '">' . $diasLabel . '</span>' : '—' ?></td>
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
