<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Relatório Completo de Produção por Funcionário (PDF)
//  Fonte: PostgreSQL ERP Yzidro (OP_ITENS_ATIVIDADES_APONTADAS e correlatas)
// ══════════════════════════════════════════════════════════════

function pfpSanitizeDate(?string $value, string $default): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $default;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : $default;
}

function pfpFmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function pfpSecondsToHms(float $seconds): string
{
    $seconds = (int) round($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function pfpEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ── CTE base compartilhada (apontamentos do período + tempos) ────────────────
function pfpBuildBaseCte(string $dataIni, string $dataFim): array
{
    $sql = "
        WITH APONT AS (
           SELECT IAA.OIAP_CODIGO, IAA.FU_CODIGO, IAA.CT_CODIGO, IAA.PROD_CODIGO, IAA.PRO_CODIGO
                 ,IAA.OIAP_QTD_PRODUZIDA, IAA.OIAP_DATA_HORA_INICIO
             FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
            WHERE NOT IAA.OIAP_EXCLUIDO
              AND IAA.OIAP_DATA_HORA_INICIO::date >= $1
              AND IAA.OIAP_DATA_HORA_INICIO::date <= $2
        ),
        TEMPO AS (
           SELECT OIAL.OIAP_CODIGO
                 ,SUM(CASE WHEN TRIM(OIAL.OIAL_STATUS) = 'EP'
                           THEN GREATEST(COALESCE(OIAL.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - OIAL.OIAL_DATA_HORA_INICIO, interval '0')
                           ELSE interval '0' END)                              AS PRODUTIVO
                 ,SUM(CASE WHEN TRIM(OIAL.OIAL_STATUS) = 'P' AND (OIAL.MPP_CODIGO IS NULL OR OIAL.MPP_CODIGO NOT IN (1, 2))
                           THEN GREATEST(COALESCE(OIAL.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - OIAL.OIAL_DATA_HORA_INICIO, interval '0')
                           ELSE interval '0' END)                              AS PAUSADO
                 ,COUNT(CASE WHEN TRIM(OIAL.OIAL_STATUS) = 'P' AND (OIAL.MPP_CODIGO IS NULL OR OIAL.MPP_CODIGO NOT IN (1, 2)) THEN 1 END) AS PAUSAS
             FROM OP_ITENS_ATIVIDADES_LOGS OIAL
            INNER JOIN APONT ON APONT.OIAP_CODIGO = OIAL.OIAP_CODIGO
            WHERE NOT OIAL.OIAL_EXCLUIDO
            GROUP BY OIAL.OIAP_CODIGO
        )
    ";
    return [$sql, [$dataIni, $dataFim]];
}

function pfpFetchRanking($pg, string $dataIni, string $dataFim): array
{
    [$baseSql, $params] = pfpBuildBaseCte($dataIni, $dataFim);
    $sql = "
        {$baseSql}
        SELECT F.FU_CODIGO, F.FU_NOME
              ,COUNT(DISTINCT A.OIAP_CODIGO)                                   AS QTD_APONTAMENTOS
              ,COUNT(DISTINCT (A.PROD_CODIGO, A.PRO_CODIGO))                   AS QTD_OPS
              ,COUNT(DISTINCT A.CT_CODIGO)                                     AS QTD_MAQUINAS
              ,COALESCE(SUM(A.OIAP_QTD_PRODUZIDA), 0)                         AS QTD_PRODUZIDA
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PRODUTIVO)), 0)              AS SEG_PRODUTIVO
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PAUSADO)), 0)                AS SEG_PAUSADO
              ,COALESCE(SUM(T.PAUSAS), 0)                                     AS QTD_PAUSAS
          FROM APONT A
         INNER JOIN FUNCIONARIO F ON F.FU_CODIGO = A.FU_CODIGO
          LEFT JOIN TEMPO T ON T.OIAP_CODIGO = A.OIAP_CODIGO
         GROUP BY F.FU_CODIGO, F.FU_NOME
         ORDER BY QTD_PRODUZIDA DESC
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar a produção por funcionário: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Detalhamento diário de todos os funcionários, em uma única consulta ──────
function pfpFetchDetalheTodos($pg, string $dataIni, string $dataFim): array
{
    [$baseSql, $params] = pfpBuildBaseCte($dataIni, $dataFim);
    $sql = "
        {$baseSql}
        SELECT A.FU_CODIGO, F.FU_NOME, A.OIAP_DATA_HORA_INICIO::date AS DIA
              ,COUNT(DISTINCT A.OIAP_CODIGO)                                   AS QTD_APONTAMENTOS
              ,COALESCE(SUM(A.OIAP_QTD_PRODUZIDA), 0)                         AS QTD_PRODUZIDA
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PRODUTIVO)), 0)              AS SEG_PRODUTIVO
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PAUSADO)), 0)                AS SEG_PAUSADO
          FROM APONT A
         INNER JOIN FUNCIONARIO F ON F.FU_CODIGO = A.FU_CODIGO
          LEFT JOIN TEMPO T ON T.OIAP_CODIGO = A.OIAP_CODIGO
         GROUP BY A.FU_CODIGO, F.FU_NOME, A.OIAP_DATA_HORA_INICIO::date
         ORDER BY F.FU_NOME, DIA
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);

    $porFuncionario = [];
    foreach ($rows as $r) {
        $porFuncionario[(int) $r['fu_codigo']][] = $r;
    }
    return $porFuncionario;
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
$dataIni = pfpSanitizeDate($_GET['data_ini'] ?? null, date('Y-m-d', strtotime('-30 days')));
$dataFim = pfpSanitizeDate($_GET['data_fim'] ?? null, date('Y-m-d'));
if ($dataIni > $dataFim) {
    [$dataIni, $dataFim] = [$dataFim, $dataIni];
}

$pg = dbPG();
if (!$pg) {
    die('Erro: não foi possível conectar ao banco de dados do ERP (PostgreSQL).');
}

try {
    $ranking = pfpFetchRanking($pg, $dataIni, $dataFim);
    $detalheDias = pfpFetchDetalheTodos($pg, $dataIni, $dataFim);
} catch (Throwable $e) {
    die('Erro: ' . htmlspecialchars($e->getMessage()));
}

// ── Totais ────────────────────────────────────────────────────────────────────
$totalFuncionarios = count($ranking);
$totalQtdProduzida = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['qtd_produzida'], 0.0);
$totalSegProdutivo = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['seg_produtivo'], 0.0);
$totalSegPausado = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['seg_pausado'], 0.0);
$totalPausas = array_reduce($ranking, fn ($c, $r) => $c + (int) $r['qtd_pausas'], 0);
$totalApontamentos = array_reduce($ranking, fn ($c, $r) => $c + (int) $r['qtd_apontamentos'], 0);
$mediaGeralHora = $totalSegProdutivo > 0 ? $totalQtdProduzida / ($totalSegProdutivo / 3600) : 0.0;
$maiorQtdProduzida = $ranking ? max(array_column($ranking, 'qtd_produzida')) : 0;

// ── HTML ──────────────────────────────────────────────────────────────────────
ob_start();
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 14mm 12mm 12mm 12mm; }
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Helvetica, Arial, sans-serif; color: #1a2438; font-size: 10.5px; }

.header { display: table; width: 100%; margin-bottom: 14px; border-bottom: 2px solid #1e4fc9; padding-bottom: 10px; }
.header-l { display: table-cell; vertical-align: bottom; }
.header-r { display: table-cell; vertical-align: bottom; text-align: right; }
.header h1 { font-size: 18px; color: #0d1e3a; }
.header p { font-size: 10.5px; color: #556; margin-top: 2px; }
.header-r .periodo { font-size: 12px; font-weight: 700; color: #1e4fc9; }
.header-r .gerado { font-size: 9px; color: #778; margin-top: 3px; }

.kpi-row { display: table; width: 100%; table-layout: fixed; margin-bottom: 16px; }
.kpi-cell { display: table-cell; padding: 10px 12px; border: 1px solid #dde3ef; background: #f6f8fc; }
.kpi-cell + .kpi-cell { border-left: none; }
.kpi-val { font-size: 15px; font-weight: 700; color: #0d1e3a; }
.kpi-lbl { font-size: 8.5px; color: #667; text-transform: uppercase; letter-spacing: .03em; margin-top: 2px; }

h2.section-title { font-size: 12px; color: #0d1e3a; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #dde3ef; }

table.rank { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
table.rank th { background: #112240; color: #fff; font-size: 8.5px; text-transform: uppercase; padding: 6px 6px; text-align: left; }
table.rank th.r, table.rank td.r { text-align: right; }
table.rank td { padding: 5px 6px; border-bottom: 1px solid #e6eaf2; font-size: 9.5px; }
table.rank tr:nth-child(even) td { background: #f8f9fc; }
.bar-wrap { display: table; width: 100%; }
.bar-cell { display: table-cell; vertical-align: middle; }
.bar-cell.track { width: 100%; padding-right: 6px; }
.bar-bg { height: 6px; background: #e6eaf2; border-radius: 3px; }
.bar-fill { height: 6px; background: #1e4fc9; border-radius: 3px; }
.bar-cell.val { white-space: nowrap; font-size: 9px; color: #556; }

.func-block { page-break-inside: avoid; margin-bottom: 14px; border: 1px solid #dde3ef; border-radius: 3px; }
.func-head { background: #eef2fa; padding: 7px 10px; display: table; width: 100%; }
.func-head .nome { display: table-cell; font-size: 11px; font-weight: 700; color: #0d1e3a; }
.func-head .stats { display: table-cell; text-align: right; font-size: 9px; color: #445; }
.func-head .stats b { color: #1e4fc9; }

table.dias { width: 100%; border-collapse: collapse; }
table.dias th { background: #f6f8fc; color: #556; font-size: 8px; text-transform: uppercase; padding: 4px 6px; text-align: left; border-bottom: 1px solid #dde3ef; }
table.dias th.r, table.dias td.r { text-align: right; }
table.dias td { padding: 4px 6px; font-size: 9px; border-bottom: 1px solid #eef1f6; }

.footer { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 8px; color: #889; display: table; width: 100%; border-top: 1px solid #dde3ef; padding-top: 4px; }
.footer-l { display: table-cell; }
.footer-r { display: table-cell; text-align: right; }

.empty { color: #889; font-size: 9.5px; padding: 10px 0; }
</style>
</head>
<body>

<div class="header">
  <div class="header-l">
    <h1>Relatório Completo de Produção por Funcionário</h1>
    <p>DOMBAG — Dados do ERP (apontamentos de atividades), somente leitura</p>
  </div>
  <div class="header-r">
    <div class="periodo"><?= pfpEscape(pfpFmtDate($dataIni)) ?> a <?= pfpEscape(pfpFmtDate($dataFim)) ?></div>
    <div class="gerado">Gerado em <?= date('d/m/Y \à\s H:i') ?></div>
  </div>
</div>

<div class="kpi-row">
  <div class="kpi-cell">
    <div class="kpi-val"><?= number_format($totalFuncionarios, 0, ',', '.') ?></div>
    <div class="kpi-lbl">Funcionários</div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-val"><?= number_format($totalQtdProduzida, 0, ',', '.') ?></div>
    <div class="kpi-lbl">Qtd. Produzida</div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-val"><?= number_format($mediaGeralHora, 1, ',', '.') ?></div>
    <div class="kpi-lbl">Média Geral / Hora</div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-val"><?= number_format($totalSegProdutivo / 3600, 1, ',', '.') ?>h</div>
    <div class="kpi-lbl">Horas Produtivas</div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-val"><?= number_format($totalSegPausado / 3600, 1, ',', '.') ?>h</div>
    <div class="kpi-lbl">Horas Pausadas</div>
  </div>
  <div class="kpi-cell">
    <div class="kpi-val"><?= number_format($totalPausas, 0, ',', '.') ?></div>
    <div class="kpi-lbl">Pausas (<?= number_format($totalApontamentos, 0, ',', '.') ?> apont.)</div>
  </div>
</div>

<h2 class="section-title">Ranking Geral</h2>
<?php if (!$ranking): ?>
  <div class="empty">Nenhum apontamento encontrado no período selecionado.</div>
<?php else: ?>
<table class="rank">
  <thead>
    <tr>
      <th>Operador</th>
      <th class="r">Apont.</th>
      <th class="r">OPs</th>
      <th class="r">Máquinas</th>
      <th class="r">Qtd. Produzida</th>
      <th class="r">T. Produtivo</th>
      <th class="r">T. Pausado</th>
      <th class="r">Pausas</th>
      <th class="r">% Produtivo</th>
      <th class="r">Média/h</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($ranking as $row):
        $segProd = (float) $row['seg_produtivo'];
        $segPausa = (float) $row['seg_pausado'];
        $qtdProd = (float) $row['qtd_produzida'];
        $mediaHora = $segProd > 0 ? $qtdProd / ($segProd / 3600) : null;
        $pctProdutivo = ($segProd + $segPausa) > 0 ? round(($segProd / ($segProd + $segPausa)) * 100) : null;
        $barPct = $maiorQtdProduzida > 0 ? round(($qtdProd / $maiorQtdProduzida) * 100) : 0;
    ?>
    <tr>
      <td><?= pfpEscape($row['fu_nome']) ?></td>
      <td class="r"><?= number_format((int) $row['qtd_apontamentos'], 0, ',', '.') ?></td>
      <td class="r"><?= number_format((int) $row['qtd_ops'], 0, ',', '.') ?></td>
      <td class="r"><?= number_format((int) $row['qtd_maquinas'], 0, ',', '.') ?></td>
      <td class="r">
        <div class="bar-wrap">
          <div class="bar-cell track"><div class="bar-bg"><div class="bar-fill" style="width:<?= $barPct ?>%"></div></div></div>
          <div class="bar-cell val"><?= number_format($qtdProd, 0, ',', '.') ?></div>
        </div>
      </td>
      <td class="r"><?= pfpEscape(pfpSecondsToHms($segProd)) ?></td>
      <td class="r"><?= pfpEscape(pfpSecondsToHms($segPausa)) ?></td>
      <td class="r"><?= number_format((int) $row['qtd_pausas'], 0, ',', '.') ?></td>
      <td class="r"><?= $pctProdutivo === null ? '—' : $pctProdutivo . '%' ?></td>
      <td class="r"><?= $mediaHora === null ? '—' : number_format($mediaHora, 1, ',', '.') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2 class="section-title">Detalhamento Diário por Funcionário</h2>
<?php foreach ($ranking as $row):
    $fuCodigo = (int) $row['fu_codigo'];
    $dias = $detalheDias[$fuCodigo] ?? [];
    $segProd = (float) $row['seg_produtivo'];
    $qtdProd = (float) $row['qtd_produzida'];
    $mediaHora = $segProd > 0 ? $qtdProd / ($segProd / 3600) : null;
?>
  <div class="func-block">
    <div class="func-head">
      <div class="nome"><?= pfpEscape($row['fu_nome']) ?> <span style="font-weight:400;color:#889;">(<?= pfpEscape($fuCodigo) ?>)</span></div>
      <div class="stats">
        Qtd.: <b><?= number_format($qtdProd, 0, ',', '.') ?></b> &nbsp;·&nbsp;
        T. Produtivo: <b><?= pfpEscape(pfpSecondsToHms($segProd)) ?></b> &nbsp;·&nbsp;
        Média/h: <b><?= $mediaHora === null ? '—' : number_format($mediaHora, 1, ',', '.') ?></b>
      </div>
    </div>
    <?php if (!$dias): ?>
      <div class="empty" style="padding:8px 10px;">Sem apontamentos detalhados.</div>
    <?php else: ?>
    <table class="dias">
      <thead>
        <tr>
          <th>Dia</th>
          <th class="r">Apont.</th>
          <th class="r">Qtd. Produzida</th>
          <th class="r">T. Produtivo</th>
          <th class="r">T. Pausado</th>
          <th class="r">Média/h</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dias as $d):
            $dSegProd = (float) $d['seg_produtivo'];
            $dQtd = (float) $d['qtd_produzida'];
            $dMediaHora = $dSegProd > 0 ? $dQtd / ($dSegProd / 3600) : null;
        ?>
        <tr>
          <td><?= pfpEscape(pfpFmtDate($d['dia'])) ?></td>
          <td class="r"><?= number_format((int) $d['qtd_apontamentos'], 0, ',', '.') ?></td>
          <td class="r"><?= number_format($dQtd, 0, ',', '.') ?></td>
          <td class="r"><?= pfpEscape(pfpSecondsToHms($dSegProd)) ?></td>
          <td class="r"><?= pfpEscape(pfpSecondsToHms((float) $d['seg_pausado'])) ?></td>
          <td class="r"><?= $dMediaHora === null ? '—' : number_format($dMediaHora, 1, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="footer">
  <div class="footer-l">DOMBAG Ltda — Relatório Completo de Produção por Funcionário</div>
  <div class="footer-r">Período: <?= pfpEscape(pfpFmtDate($dataIni)) ?> a <?= pfpEscape(pfpFmtDate($dataFim)) ?> &nbsp;·&nbsp; Gerado em <?= date('d/m/Y \à\s H:i') ?></div>
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
$pdf->setPaper('A4', 'portrait');
$pdf->render();

$nome = 'DOMBAG_Producao_Funcionarios_' . str_replace('-', '', $dataIni) . '_a_' . str_replace('-', '', $dataFim) . '.pdf';
$pdf->stream($nome, ['Attachment' => false]);
