<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Análise de Apontamento de Atividades
//  Fonte: PostgreSQL ERP Yzidro (OP_ITENS_ATIVIDADES_APONTADAS e correlatas)
//  Somente leitura.
// ══════════════════════════════════════════════════════════════

function rabEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rabSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function rabFmtDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function rabBaseUrl(): string
{
    return '/relatorios/analise-bag';
}

function rabStatusLabel(string $status): string
{
    $status = trim($status);
    $map = [
        'A'  => ['Em Andamento', 'info'],
        'EP' => ['Em Produção', 'info'],
        'P'  => ['Pausado', 'warn'],
        'F'  => ['Finalizado', 'ok'],
        'C'  => ['Cancelado', 'danger'],
    ];
    if (isset($map[$status])) {
        return '<span class="status-pill ' . $map[$status][1] . '">' . rabEscape($map[$status][0]) . '</span>';
    }
    return '<span class="status-pill">' . rabEscape($status ?: '—') . '</span>';
}

function rabRenderNsu(?string $nsu): string
{
    $nsu = trim((string) $nsu);
    if ($nsu === '') {
        return '<span class="td-muted">—</span>';
    }
    $itens = array_values(array_filter(array_map('trim', explode(',', $nsu)), fn ($v) => $v !== ''));
    if (count($itens) <= 1) {
        return '<span class="td-muted">' . rabEscape($nsu) . '</span>';
    }
    return '<button type="button" class="rab-nsu-toggle" data-nsu="' . rabEscape(json_encode($itens, JSON_UNESCAPED_UNICODE)) . '" onclick="rabToggleNsu(this)">'
         . count($itens) . ' códigos <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>'
         . '</button>';
}

// ── Listas para os combos (carregadas do banco) ─────────────────────────────
function rabFetchOperadores($pg): array
{
    $res = @pg_query($pg, 'SELECT FU_CODIGO, FU_NOME FROM FUNCIONARIO ORDER BY FU_NOME');
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function rabFetchCentrosTrabalho($pg): array
{
    $res = @pg_query($pg, 'SELECT CT_CODIGO, CT_DESCRICAO FROM CENTRO_TRABALHO ORDER BY CT_DESCRICAO');
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function rabFetchAtividades($pg): array
{
    // A tabela ATIVIDADE_PRODUCAO não tem uma coluna de descrição confirmada;
    // tenta algumas variações e cai para exibir apenas o código.
    foreach (['ATP_DESCRICAO', 'ATP_ATIVIDADE', 'ATP_NOME'] as $col) {
        $res = @pg_query($pg, "SELECT ATP_CODIGO, {$col} AS ATP_LABEL FROM ATIVIDADE_PRODUCAO ORDER BY {$col}");
        if ($res) {
            $rows = pg_fetch_all($res) ?: [];
            pg_free_result($res);
            return $rows;
        }
    }
    $res = @pg_query($pg, 'SELECT ATP_CODIGO, ATP_TIPO_APONTAMENTO AS ATP_LABEL FROM ATIVIDADE_PRODUCAO ORDER BY ATP_CODIGO');
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Consulta principal ───────────────────────────────────────────────────────
function rabFetchApontamentos($pg, array $filters): array
{
    $params = [];
    $extras = [];

    if ($filters['data_ini'] !== '') {
        $params[] = $filters['data_ini'];
        $extras[] = "IAA.OIAP_DATA_HORA_INICIO::DATE >= $" . count($params);
    }
    if ($filters['data_fim'] !== '') {
        $params[] = $filters['data_fim'];
        $extras[] = "IAA.OIAP_DATA_HORA_INICIO::DATE <= $" . count($params);
    }
    if ($filters['cod_op'] !== '') {
        $params[] = (int) $filters['cod_op'];
        $extras[] = 'IAA.PROD_CODIGO = $' . count($params);
    }
    if ($filters['fu_codigo'] !== '') {
        $params[] = (int) $filters['fu_codigo'];
        $extras[] = 'IAA.FU_CODIGO = $' . count($params);
    }
    if ($filters['ct_codigo'] !== '') {
        $params[] = (int) $filters['ct_codigo'];
        $extras[] = 'IAA.CT_CODIGO = $' . count($params);
    }
    if ($filters['atp_codigo'] !== '') {
        $params[] = (int) $filters['atp_codigo'];
        $extras[] = 'IAA.ATP_CODIGO = $' . count($params);
    }
    if ($filters['cli_codigo'] !== '') {
        $params[] = (int) $filters['cli_codigo'];
        $extras[] = 'IAA.CLI_CODIGO = $' . count($params);
    }

    $whereIaa = 'NOT IAA.OIAP_EXCLUIDO';
    if ($extras) {
        $whereIaa .= "\n          AND " . implode("\n          AND ", $extras);
    }

    $outerExtras = [];
    if ($filters['cliente'] !== '') {
        $params[] = '%' . $filters['cliente'] . '%';
        $outerExtras[] = 'C.CLI_NOME ILIKE $' . count($params);
    }
    if ($filters['produto'] !== '') {
        $params[] = '%' . $filters['produto'] . '%';
        $outerExtras[] = 'P.PRO_DESCRICAO ILIKE $' . count($params);
    }
    if ($filters['nsu'] !== '') {
        $params[] = '%' . $filters['nsu'] . '%';
        $outerExtras[] = 'EXISTS (
            SELECT 1 FROM OP_ITENS_ATIVIDADES_COMPONENTES OIAC2
             WHERE OIAC2.OIAP_CODIGO = IAA.OIAP_CODIGO
               AND NOT OIAC2.OIAC_EXCLUIDO
               AND OIAC2.OIAC_NSU ILIKE $' . count($params) . '
        )';
    }
    $whereOuter = '';
    if ($outerExtras) {
        $whereOuter = "\n  AND " . implode("\n  AND ", $outerExtras);
    }

    $sql = "
WITH ITENS_ATIVIDADES_APONTADAS AS (
   SELECT IAA.OIAP_CODIGO
         ,IAA.OIAP_STATUS
         ,IAA.FU_CODIGO
         ,IAA.CT_CODIGO
         ,IAA.PROD_CODIGO
         ,IAA.CLI_CODIGO
         ,IAA.PRO_CODIGO
         ,IAA.OIAP_DATA_HORA_INICIO
         ,IAA.OIAP_DATA_HORA_FIM
         ,IAA.OIAP_QTD_PRODUZIDA
         ,IAA.ATP_CODIGO
         ,ATP.ATP_TIPO_APONTAMENTO
     FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
    INNER JOIN ATIVIDADE_PRODUCAO       ATP ON ATP.ATP_CODIGO   = IAA.ATP_CODIGO
    WHERE {$whereIaa}
),
OP_TEMPO_OPERACAO AS (
   SELECT OIAL.OIAP_CODIGO
         ,COUNT(CASE WHEN TRIM(OIAL.OIAL_STATUS) = 'P' AND (OIAL.MPP_CODIGO IS NULL OR OIAL.MPP_CODIGO NOT IN (1, 2)) THEN 1 END) AS QTD_PAUSAS
         ,TO_CHAR(COALESCE(SUM(CASE
                                 WHEN TRIM(OIAL.OIAL_STATUS) = 'EP' THEN
                                   GREATEST(COALESCE(OIAL.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - OIAL.OIAL_DATA_HORA_INICIO, interval '0')
                                 ELSE interval '0'
                               END), interval '0'), 'HH24:MI:SS') AS TEMPO_PRODUTIVO
         ,TO_CHAR(COALESCE(SUM(CASE
                                 WHEN TRIM(OIAL.OIAL_STATUS) = 'P' AND (OIAL.MPP_CODIGO IS NULL OR OIAL.MPP_CODIGO NOT IN (1, 2)) THEN
                                   GREATEST(COALESCE(OIAL.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - OIAL.OIAL_DATA_HORA_INICIO, interval '0')
                                 ELSE interval '0'
                               END), interval '0'), 'HH24:MI:SS') AS TEMPO_PAUSADO
     FROM OP_ITENS_ATIVIDADES_LOGS OIAL
    INNER JOIN ITENS_ATIVIDADES_APONTADAS IAA ON IAA.OIAP_CODIGO = OIAL.OIAP_CODIGO
    WHERE NOT OIAL.OIAL_EXCLUIDO
    GROUP BY OIAL.OIAP_CODIGO
),
COMPONENTES_AGRUPADOS AS (
   SELECT OIAC.OIAP_CODIGO
         ,STRING_AGG(DISTINCT OIAC.OIAC_NSU, ', ') AS NSU
     FROM OP_ITENS_ATIVIDADES_COMPONENTES OIAC
    INNER JOIN ITENS_ATIVIDADES_APONTADAS IAA ON IAA.OIAP_CODIGO = OIAC.OIAP_CODIGO
    WHERE NOT OIAC.OIAC_EXCLUIDO
    GROUP BY OIAC.OIAP_CODIGO
),
ETAPAS_AGRUPADAS AS (
   SELECT DISTINCT OIE.EP_CODIGO
         ,OIA.ATP_CODIGO
         ,OIA.OIA_ATIVIDADE
         ,STRING_AGG(DISTINCT OIE.OIE_ETAPA, ', ') AS OIE_ETAPA
     FROM ITENS_COMPOSICAO_PRODUCAO ICP
     LEFT JOIN OP_ITENS_ETAPAS      OIE ON OIE.ICP_CODIGO = ICP.ICP_CODIGO
     LEFT JOIN OP_ITENS_ATIVIDADES  OIA ON OIA.OIE_CODIGO = OIE.OIE_CODIGO
    WHERE EXISTS (
        SELECT 1
          FROM ITENS_ATIVIDADES_APONTADAS IAA
         WHERE IAA.ATP_CODIGO  = OIA.ATP_CODIGO
           AND IAA.PROD_CODIGO = ICP.PROD_CODIGO
           AND IAA.PRO_CODIGO  = ICP.ITE_CODIGO
    )
    GROUP BY OIE.EP_CODIGO, OIA.ATP_CODIGO, OIA.OIA_ATIVIDADE
)
SELECT IAA.OIAP_CODIGO
     ,IAA.OIAP_STATUS
     ,F.FU_CODIGO
     ,F.FU_NOME
     ,CT.CT_CODIGO
     ,CT.CT_DESCRICAO
     ,IAA.PROD_CODIGO
     ,C.CLI_CODIGO
     ,C.CLI_NOME
     ,P.PRO_CODIGO
     ,P.PRO_DESCRICAO
     ,IAA.OIAP_DATA_HORA_INICIO
     ,IAA.OIAP_DATA_HORA_FIM
     ,IAA.OIAP_QTD_PRODUZIDA
     ,IAA.ATP_CODIGO
     ,CAST(COALESCE(OTO.QTD_PAUSAS, 0) AS INT)    AS QTD_PAUSAS
     ,COALESCE(OTO.TEMPO_PRODUTIVO, '00:00:00')   AS TEMPO_PRODUTIVO
     ,COALESCE(OTO.TEMPO_PAUSADO, '00:00:00')     AS TEMPO_PAUSA
     ,IAA.ATP_TIPO_APONTAMENTO
     ,ETA.OIA_ATIVIDADE                           AS OIA_ATIVIDADE
     ,ETA.OIE_ETAPA                               AS OIE_ETAPA
     ,COMP.NSU                                    AS NSU
 FROM ITENS_ATIVIDADES_APONTADAS IAA
INNER JOIN PRODUTO                     P ON P.PRO_CODIGO      = IAA.PRO_CODIGO
INNER JOIN CLIENTES                    C ON C.CLI_CODIGO      = IAA.CLI_CODIGO
INNER JOIN CENTRO_TRABALHO            CT ON CT.CT_CODIGO      = IAA.CT_CODIGO
INNER JOIN FUNCIONARIO                 F ON F.FU_CODIGO       = IAA.FU_CODIGO
 LEFT JOIN ETAPAS_AGRUPADAS          ETA ON ETA.ATP_CODIGO    = IAA.ATP_CODIGO
 LEFT JOIN OP_TEMPO_OPERACAO         OTO ON OTO.OIAP_CODIGO   = IAA.OIAP_CODIGO
 LEFT JOIN COMPONENTES_AGRUPADOS    COMP ON COMP.OIAP_CODIGO  = IAA.OIAP_CODIGO
WHERE TRUE{$whereOuter}
ORDER BY IAA.OIAP_DATA_HORA_INICIO DESC
LIMIT 500
";

    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar os apontamentos: ' . pg_last_error($pg));
    }

    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $rows[] = $row;
    }
    pg_free_result($res);
    return $rows;
}

// ── Filtros da requisição ─────────────────────────────────────────────────────
$filters = [
    'data_ini'   => rabSanitizeDate($_GET['data_ini'] ?? date('Y-m-d')),
    'data_fim'   => rabSanitizeDate($_GET['data_fim'] ?? ''),
    'cod_op'     => trim((string) ($_GET['cod_op'] ?? '')),
    'fu_codigo'  => trim((string) ($_GET['fu_codigo'] ?? '')),
    'ct_codigo'  => trim((string) ($_GET['ct_codigo'] ?? '')),
    'atp_codigo' => trim((string) ($_GET['atp_codigo'] ?? '')),
    'cli_codigo' => trim((string) ($_GET['cli_codigo'] ?? '')),
    'cliente'    => trim((string) ($_GET['cliente'] ?? '')),
    'produto'    => trim((string) ($_GET['produto'] ?? '')),
    'nsu'        => trim((string) ($_GET['nsu'] ?? '')),
];

// ── Conexão ERP ───────────────────────────────────────────────────────────────
$pg = dbPG();
$error = '';
$apontamentos = [];
$operadores = [];
$centrosTrabalho = [];
$atividades = [];

if (!$pg) {
    $error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    try {
        $apontamentos = rabFetchApontamentos($pg, $filters);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $operadores = rabFetchOperadores($pg);
    $centrosTrabalho = rabFetchCentrosTrabalho($pg);
    $atividades = rabFetchAtividades($pg);
}

$activeFiltersCount = count(array_filter($filters, fn ($v) => $v !== ''));
$totalRegistros = count($apontamentos);
$totalQtdProduzida = array_reduce($apontamentos, fn ($c, $r) => $c + (float) ($r['oiap_qtd_produzida'] ?? 0), 0.0);
$totalPausas = array_reduce($apontamentos, fn ($c, $r) => $c + (int) ($r['qtd_pausas'] ?? 0), 0);

// ── Agregações para os gráficos ───────────────────────────────────────────────
function rabTimeToSeconds(?string $hms): int
{
    $hms = trim((string) $hms);
    if (!preg_match('/^(\d+):(\d{2}):(\d{2})$/', $hms, $m)) {
        return 0;
    }
    return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
}

function rabSecondsToHms(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

$porCentroTrabalho = [];
$porOperador = [];
$porStatus = [];
$porDia = [];
$segundosProdutivos = 0;
$segundosPausados = 0;

foreach ($apontamentos as $r) {
    $qtd = (float) ($r['oiap_qtd_produzida'] ?? 0);

    $ct = $r['ct_descricao'] ?? '—';
    $porCentroTrabalho[$ct] = ($porCentroTrabalho[$ct] ?? 0) + $qtd;

    $op = $r['fu_nome'] ?? '—';
    $porOperador[$op] = ($porOperador[$op] ?? 0) + $qtd;

    $stLabel = trim((string) ($r['oiap_status'] ?? '')) ?: '—';
    $porStatus[$stLabel] = ($porStatus[$stLabel] ?? 0) + 1;

    $dia = substr((string) ($r['oiap_data_hora_inicio'] ?? ''), 0, 10);
    if ($dia !== '') {
        $porDia[$dia] = ($porDia[$dia] ?? 0) + $qtd;
    }

    $segundosProdutivos += rabTimeToSeconds($r['tempo_produtivo'] ?? '');
    $segundosPausados   += rabTimeToSeconds($r['tempo_pausa'] ?? '');
}

arsort($porCentroTrabalho);
$porCentroTrabalho = array_slice($porCentroTrabalho, 0, 12, true);
arsort($porOperador);
$porOperador = array_slice($porOperador, 0, 12, true);
ksort($porDia);

$tempoProdutivoTotal = rabSecondsToHms($segundosProdutivos);
$tempoPausadoTotal   = rabSecondsToHms($segundosPausados);

function jz($v)
{
    return json_encode($v, JSON_UNESCAPED_UNICODE);
}

$js_por_ct       = jz($porCentroTrabalho);
$js_por_operador = jz($porOperador);
$js_por_status    = jz($porStatus);
$js_por_dia       = jz($porDia);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Análise de Apontamento de Atividades | DOMBAG</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .kpi-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }

  .kpi-panel { margin-bottom: 20px; flex-shrink: 0; }
  .kpi-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; user-select: none; margin-bottom: 10px; }
  .kpi-panel-head h2 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
  .kpi-panel-toggle { width: 28px; height: 28px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s; flex-shrink: 0; }
  .kpi-panel-toggle:hover { background: var(--card-hover); color: var(--text-primary); }
  .kpi-panel-toggle svg { transition: transform .2s ease; }
  .kpi-panel.collapsed .kpi-panel-toggle svg { transform: rotate(-90deg); }
  .kpi-panel-body { overflow: hidden; max-height: 200px; opacity: 1; transition: max-height .25s ease, opacity .2s ease, margin .25s ease; }
  .kpi-panel.collapsed .kpi-panel-body { max-height: 0; opacity: 0; }

  .filter-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; margin-bottom: 16px; flex-shrink: 0; }
  .filter-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; user-select: none; }
  .filter-card-head h2 { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
  .filter-count-badge { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: rgba(45,106,255,.12); color: #7db3ff; }
  .filter-toggle-btn { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s, transform .2s; flex-shrink: 0; }
  .filter-toggle-btn:hover { background: var(--card-hover); color: var(--text-primary); }
  .filter-toggle-btn svg { transition: transform .2s ease; }
  .filter-card.collapsed .filter-toggle-btn svg { transform: rotate(-90deg); }
  .filter-card-body { overflow: hidden; max-height: 400px; opacity: 1; margin-top: 16px; transition: max-height .25s ease, opacity .2s ease, margin .25s ease; }
  .filter-card.collapsed .filter-card-body { max-height: 0; opacity: 0; margin-top: 0; }
  .filter-grid {
    display: grid;
    grid-template-columns: 140px minmax(200px,1fr) minmax(200px,1fr) minmax(200px,1fr) 150px 150px 130px minmax(220px,1fr) minmax(200px,1fr) 200px;
    gap: 16px;
    align-items: end;
  }
  .filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
  .filter-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
  .filter-field input, .filter-field select { height: 36px; padding: 0 10px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; width: 100%; min-width: 0; }
  .filter-field select option { background: var(--blue-mid); }
  .filter-actions { margin-top: 16px; display: flex; gap: 12px; }

  .rab-cod { color: var(--text-muted); font-size: 11.5px; }

  /* NSU: botão compacto que abre um popover flutuante (fixed) com a lista completa */
  .rab-nsu-toggle { display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 999px; border: 1px solid var(--border); background: rgba(255,255,255,.03); color: var(--text-muted); font-size: 11px; font-weight: 600; font-family: 'Segoe UI', sans-serif; cursor: pointer; white-space: nowrap; transition: background .15s, color .15s; }
  .rab-nsu-toggle:hover { background: var(--card-hover); color: var(--text-primary); }
  .rab-nsu-toggle svg { transition: transform .2s ease; flex-shrink: 0; }
  .rab-nsu-toggle.open svg { transform: rotate(180deg); }
  .rab-nsu-toggle.open { background: rgba(45,106,255,.12); border-color: rgba(79,123,255,.35); color: #7db3ff; }
  .rab-nsu-popover { display: none; position: fixed; z-index: 200; min-width: 160px; max-width: 260px; max-height: 240px; overflow-y: auto; background: #152845; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; box-shadow: 0 12px 32px rgba(0,0,0,.45); padding: 8px; }
  .rab-nsu-popover.open { display: block; }
  .rab-nsu-item { display: block; padding: 5px 8px; border-radius: 6px; font-size: 12px; color: var(--text-primary); word-break: break-all; }
  .rab-nsu-item:hover { background: rgba(255,255,255,.05); }

  .alert-error { padding: 12px 16px; border-radius: var(--radius); background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2); color: var(--red); font-size: 13px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

  /* Abas: cada aba ocupa o espaço restante da viewport e rola por dentro,
     mantendo a grade com tamanho fixo e scroll vertical próprio. */
  .tabs-bar { display: flex; align-items: center; gap: 4px; border-bottom: 1px solid var(--border); padding-bottom: 0; margin-bottom: 18px; flex-shrink: 0; }
  .tab-btn { padding: 8px 18px; border: none; background: transparent; color: var(--text-muted); font-family: 'Segoe UI', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .15s, border-color .15s; }
  .tab-btn:hover { color: var(--text-primary); }
  .tab-btn.active { color: #7db3ff; border-bottom-color: var(--blue-light); font-weight: 600; }
  .tab-content { display: none; }
  .tab-content.active { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow-y: auto; }

  /* Gráficos */
  .charts-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
  .chart-panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .chart-head { padding: 14px 20px; border-bottom: 1px solid var(--border); }
  .chart-title { font-size: 13px; font-weight: 600; }
  .chart-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
  .chart-body { padding: 16px 16px 12px; }
  .chart-empty { text-align: center; padding: 48px 20px; color: var(--text-muted); font-size: 12.5px; opacity: .6; }

  @media (max-width: 1400px) { .filter-grid { grid-template-columns: repeat(4, 1fr); } }
  @media (max-width: 1100px) { .charts-row { grid-template-columns: 1fr; } }
  @media (max-width: 900px) { .kpi-grid-3 { grid-template-columns: 1fr; } .filter-grid { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Análise de Apontamento de Atividades</h1>
          <p>Dados do ERP — somente leitura</p>
        </div>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= rabEscape($error) ?>
        </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-panel" id="kpiPanelTop">
        <div class="kpi-panel-head" id="kpiPanelTopHead">
          <h2>Indicadores</h2>
          <button type="button" class="kpi-panel-toggle" id="kpiPanelTopToggle" title="Minimizar indicadores">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
        </div>
        <div class="kpi-panel-body">
        <div class="kpi-grid-3">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Apontamentos</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalRegistros, 0, ',', '.') ?></div>
          <div class="kpi-footer">no período filtrado</div>
        </div>
        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Qtd. produzida</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalQtdProduzida, 0, ',', '.') ?></div>
          <div class="kpi-footer">soma dos apontamentos listados</div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-header">
            <span class="kpi-label">Pausas</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalPausas, 0, ',', '.') ?></div>
          <div class="kpi-footer">total de pausas registradas</div>
        </div>
        </div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="filter-card" id="filterCard">
        <div class="filter-card-head" id="filterCardHead">
          <h2>
            Filtros
            <?php if ($activeFiltersCount > 0): ?>
              <span class="filter-count-badge"><?= $activeFiltersCount ?> ativo<?= $activeFiltersCount !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
          </h2>
          <button type="button" class="filter-toggle-btn" id="filterToggleBtn" title="Minimizar filtros">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
        </div>
        <div class="filter-card-body" id="filterCardBody">
        <form method="get" action="<?= rabEscape(rabBaseUrl()) ?>">
          <div class="filter-grid">

            <div class="filter-field">
              <label for="cod_op">Cód. OP</label>
              <input type="number" id="cod_op" name="cod_op" value="<?= rabEscape($filters['cod_op']) ?>" placeholder="Ex.: 1234">
            </div>

            <div class="filter-field">
              <label for="fu_codigo">Operador</label>
              <select id="fu_codigo" name="fu_codigo">
                <option value="">Todos</option>
                <?php foreach ($operadores as $op): ?>
                  <option value="<?= rabEscape($op['fu_codigo']) ?>" <?= $filters['fu_codigo'] === (string) $op['fu_codigo'] ? 'selected' : '' ?>>
                    <?= rabEscape($op['fu_codigo'] . ' — ' . $op['fu_nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-field">
              <label for="ct_codigo">Centro de Trabalho</label>
              <select id="ct_codigo" name="ct_codigo">
                <option value="">Todos</option>
                <?php foreach ($centrosTrabalho as $ct): ?>
                  <option value="<?= rabEscape($ct['ct_codigo']) ?>" <?= $filters['ct_codigo'] === (string) $ct['ct_codigo'] ? 'selected' : '' ?>>
                    <?= rabEscape($ct['ct_codigo'] . ' — ' . $ct['ct_descricao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-field">
              <label for="atp_codigo">Atividade de Produção</label>
              <select id="atp_codigo" name="atp_codigo">
                <option value="">Todas</option>
                <?php foreach ($atividades as $atp): ?>
                  <option value="<?= rabEscape($atp['atp_codigo']) ?>" <?= $filters['atp_codigo'] === (string) $atp['atp_codigo'] ? 'selected' : '' ?>>
                    <?= rabEscape($atp['atp_codigo'] . ' — ' . ($atp['atp_label'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-field">
              <label for="data_ini">Data inicial</label>
              <input type="date" id="data_ini" name="data_ini" value="<?= rabEscape($filters['data_ini']) ?>">
            </div>

            <div class="filter-field">
              <label for="data_fim">Data final</label>
              <input type="date" id="data_fim" name="data_fim" value="<?= rabEscape($filters['data_fim']) ?>">
            </div>

            <div class="filter-field">
              <label for="cli_codigo">Cód. Cliente</label>
              <input type="number" id="cli_codigo" name="cli_codigo" value="<?= rabEscape($filters['cli_codigo']) ?>" placeholder="Ex.: 500">
            </div>

            <div class="filter-field">
              <label for="cliente">Razão Social</label>
              <input type="text" id="cliente" name="cliente" value="<?= rabEscape($filters['cliente']) ?>" placeholder="Nome do cliente">
            </div>

            <div class="filter-field">
              <label for="produto">Descrição do Produto</label>
              <input type="text" id="produto" name="produto" value="<?= rabEscape($filters['produto']) ?>" placeholder="Ex.: Big Bag">
            </div>

            <div class="filter-field">
              <label for="nsu">NSU</label>
              <input type="text" id="nsu" name="nsu" value="<?= rabEscape($filters['nsu']) ?>" placeholder="Número do NSU">
            </div>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-refresh">Filtrar</button>
            <a href="<?= rabEscape(rabBaseUrl()) ?>" class="icon-btn" title="Limpar filtros" style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
          </div>
        </form>
        </div>
      </div>

      <!-- Abas -->
      <div class="tabs-bar">
        <button class="tab-btn active" onclick="rabSwitchTab('graficos', this)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Gráficos
        </button>
        <button class="tab-btn" onclick="rabSwitchTab('pesquisa', this)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Pesquisa
        </button>
      </div>

      <!-- TAB: Gráficos -->
      <div class="tab-content active" id="tab-graficos">
        <div class="kpi-panel" id="kpiPanelGraficos">
          <div class="kpi-panel-head" id="kpiPanelGraficosHead">
            <h2>Indicadores</h2>
            <button type="button" class="kpi-panel-toggle" id="kpiPanelGraficosToggle" title="Minimizar indicadores">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </div>
          <div class="kpi-panel-body">
        <div class="kpi-grid-3">
          <div class="kpi-card blue">
            <div class="kpi-header">
              <span class="kpi-label">Tempo produtivo total</span>
              <div class="kpi-icon">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              </div>
            </div>
            <div class="kpi-value"><?= rabEscape($tempoProdutivoTotal) ?></div>
            <div class="kpi-footer">soma do período filtrado</div>
          </div>
          <div class="kpi-card amber">
            <div class="kpi-header">
              <span class="kpi-label">Tempo pausado total</span>
              <div class="kpi-icon">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
              </div>
            </div>
            <div class="kpi-value"><?= rabEscape($tempoPausadoTotal) ?></div>
            <div class="kpi-footer">soma do período filtrado</div>
          </div>
          <div class="kpi-card teal">
            <div class="kpi-header">
              <span class="kpi-label">Qtd. produzida</span>
              <div class="kpi-icon">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg>
              </div>
            </div>
            <div class="kpi-value"><?= number_format($totalQtdProduzida, 0, ',', '.') ?></div>
            <div class="kpi-footer">soma dos apontamentos listados</div>
          </div>
        </div>
          </div>
        </div>

        <div class="charts-row">
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Qtd. Produzida por Centro de Trabalho</div>
              <div class="chart-sub">Top 12 centros no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyCT"><canvas id="chartCT" height="220"></canvas></div>
          </div>
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Qtd. Produzida por Operador</div>
              <div class="chart-sub">Top 12 operadores no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyOperador"><canvas id="chartOperador" height="220"></canvas></div>
          </div>
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Apontamentos por Status</div>
              <div class="chart-sub">Distribuição no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyStatus"><canvas id="chartStatus" height="220"></canvas></div>
          </div>
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Qtd. Produzida por Dia</div>
              <div class="chart-sub">Evolução no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyDia"><canvas id="chartDia" height="220"></canvas></div>
          </div>
        </div>
      </div>

      <!-- TAB: Pesquisa -->
      <div class="tab-content" id="tab-pesquisa">
        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title">Apontamentos de Atividades</span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <div class="rab-table-wrap table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Código</th>
                  <th>Operador</th>
                  <th>Centro de Trabalho</th>
                  <th>Etapas</th>
                  <th>Atividade Produção</th>
                  <th>Cód. OP</th>
                  <th>Cliente</th>
                  <th>Produto</th>
                  <th>Data Início</th>
                  <th>Data Fim</th>
                  <th class="td-right">Qtd. Produzida</th>
                  <th class="td-right">Pausas</th>
                  <th class="td-right">Tempo Produtivo</th>
                  <th class="td-right">Tempo Pausa</th>
                  <th>Tipo Apontamento</th>
                  <th>NSUs</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$apontamentos): ?>
                  <tr>
                    <td colspan="17">
                      <div class="td-muted" style="padding:24px;text-align:center;">Nenhum apontamento encontrado com os filtros informados.</div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($apontamentos as $row): ?>
                    <tr>
                      <td><?= rabStatusLabel((string) ($row['oiap_status'] ?? '')) ?></td>
                      <td class="rab-cod"><?= rabEscape($row['oiap_codigo'] ?? '—') ?></td>
                      <td><?= rabEscape($row['fu_nome'] ?? '—') ?> <span class="rab-cod">(<?= rabEscape($row['fu_codigo'] ?? '—') ?>)</span></td>
                      <td><?= rabEscape($row['ct_descricao'] ?? '—') ?> <span class="rab-cod">(<?= rabEscape($row['ct_codigo'] ?? '—') ?>)</span></td>
                      <td class="td-muted"><?= rabEscape($row['oie_etapa'] ?? '—') ?></td>
                      <td><?= rabEscape($row['oia_atividade'] ?? '—') ?></td>
                      <td class="rab-cod"><?= rabEscape($row['prod_codigo'] ?? '—') ?></td>
                      <td><?= rabEscape($row['cli_nome'] ?? '—') ?> <span class="rab-cod">(<?= rabEscape($row['cli_codigo'] ?? '—') ?>)</span></td>
                      <td><?= rabEscape($row['pro_descricao'] ?? '—') ?> <span class="rab-cod">(<?= rabEscape($row['pro_codigo'] ?? '—') ?>)</span></td>
                      <td class="td-nowrap"><?= rabEscape(rabFmtDateTime($row['oiap_data_hora_inicio'] ?? '')) ?></td>
                      <td class="td-nowrap"><?= rabEscape(rabFmtDateTime($row['oiap_data_hora_fim'] ?? '')) ?></td>
                      <td class="td-right"><?= number_format((float) ($row['oiap_qtd_produzida'] ?? 0), 0, ',', '.') ?></td>
                      <td class="td-right"><?= (int) ($row['qtd_pausas'] ?? 0) ?></td>
                      <td class="td-right"><?= rabEscape($row['tempo_produtivo'] ?? '—') ?></td>
                      <td class="td-right"><?= rabEscape($row['tempo_pausa'] ?? '—') ?></td>
                      <td><?= rabEscape($row['atp_tipo_apontamento'] ?? '—') ?></td>
                      <td><?= rabRenderNsu($row['nsu'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
const rabNsuPopover = document.createElement('div');
rabNsuPopover.className = 'rab-nsu-popover';
document.body.appendChild(rabNsuPopover);
let rabNsuActiveBtn = null;

function rabCloseNsuPopover() {
  rabNsuPopover.classList.remove('open');
  if (rabNsuActiveBtn) rabNsuActiveBtn.classList.remove('open');
  rabNsuActiveBtn = null;
}

function rabToggleNsu(btn) {
  if (rabNsuActiveBtn === btn) { rabCloseNsuPopover(); return; }
  rabCloseNsuPopover();

  let itens = [];
  try { itens = JSON.parse(btn.dataset.nsu || '[]'); } catch (e) { itens = []; }
  rabNsuPopover.innerHTML = itens.map(v => `<span class="rab-nsu-item">${v.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`).join('');

  btn.classList.add('open');
  rabNsuActiveBtn = btn;
  rabNsuPopover.classList.add('open');

  const rect = btn.getBoundingClientRect();
  const popRect = rabNsuPopover.getBoundingClientRect();
  let left = Math.min(rect.left, window.innerWidth - popRect.width - 10);
  left = Math.max(10, left);
  let top = rect.bottom + 6;
  if (top + popRect.height > window.innerHeight - 10) {
    top = rect.top - popRect.height - 6;
  }
  rabNsuPopover.style.left = left + 'px';
  rabNsuPopover.style.top = top + 'px';
}

document.addEventListener('click', function (e) {
  if (!e.target.closest('.rab-nsu-toggle') && !e.target.closest('.rab-nsu-popover')) {
    rabCloseNsuPopover();
  }
});
document.addEventListener('scroll', rabCloseNsuPopover, true);

function rabSetupCollapsible(cardId, headId, toggleId, storageKey, expandedLabel, collapsedLabel) {
  const card = document.getElementById(cardId);
  const head = document.getElementById(headId);
  const toggle = document.getElementById(toggleId);
  if (!card || !head || !toggle) return;

  function setCollapsed(collapsed) {
    card.classList.toggle('collapsed', collapsed);
    toggle.setAttribute('title', collapsed ? expandedLabel : collapsedLabel);
    localStorage.setItem(storageKey, collapsed ? '1' : '0');
  }

  setCollapsed(localStorage.getItem(storageKey) === '1');
  head.addEventListener('click', () => setCollapsed(!card.classList.contains('collapsed')));
}

rabSetupCollapsible('filterCard', 'filterCardHead', 'filterToggleBtn', 'rab_filtros_colapsados', 'Expandir filtros', 'Minimizar filtros');
rabSetupCollapsible('kpiPanelTop', 'kpiPanelTopHead', 'kpiPanelTopToggle', 'rab_kpi_top_colapsado', 'Expandir indicadores', 'Minimizar indicadores');
rabSetupCollapsible('kpiPanelGraficos', 'kpiPanelGraficosHead', 'kpiPanelGraficosToggle', 'rab_kpi_graficos_colapsado', 'Expandir indicadores', 'Minimizar indicadores');

const POR_CT       = <?= $js_por_ct ?>;
const POR_OPERADOR = <?= $js_por_operador ?>;
const POR_STATUS   = <?= $js_por_status ?>;
const POR_DIA      = <?= $js_por_dia ?>;

const RAB_PALETTE = ['#2d6aff','#00c9a7','#f59e0b','#ef4444','#a78bfa','#38bdf8','#fb7185','#34d399','#fbbf24','#60a5fa','#f472b6','#4ade80'];

function rabSwitchTab(id, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}

function rabBarChart(canvasId, bodyId, dataObj, label) {
  const labels = Object.keys(dataObj);
  const values = Object.values(dataObj);
  if (!labels.length) {
    document.getElementById(bodyId).innerHTML = '<div class="chart-empty">Sem dados para exibir.</div>';
    return;
  }
  new Chart(document.getElementById(canvasId), {
    type: 'bar',
    data: { labels, datasets: [{ label, data: values, backgroundColor: '#2d6affcc', borderColor: '#2d6aff', borderWidth: 1, borderRadius: 4 }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { backgroundColor: '#1a3a6a', titleColor: '#e8edf5', bodyColor: '#b0c4de',
          callbacks: { label: ctx => ' ' + Number(ctx.raw).toLocaleString('pt-BR') } } },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#7a90b0', font: { size: 10 }, maxRotation: 40, minRotation: 0 } },
        y: { grid: { color: 'rgba(255,255,255,.06)' }, ticks: { color: '#7a90b0', font: { size: 10 }, callback: v => v.toLocaleString('pt-BR') } }
      }
    }
  });
}

(function () {
  rabBarChart('chartCT', 'bodyCT', POR_CT, 'Qtd. produzida');
  rabBarChart('chartOperador', 'bodyOperador', POR_OPERADOR, 'Qtd. produzida');

  // Status (doughnut)
  (function () {
    const labels = Object.keys(POR_STATUS);
    const values = Object.values(POR_STATUS);
    if (!labels.length) {
      document.getElementById('bodyStatus').innerHTML = '<div class="chart-empty">Sem dados para exibir.</div>';
      return;
    }
    new Chart(document.getElementById('chartStatus'), {
      type: 'doughnut',
      data: { labels, datasets: [{ data: values, backgroundColor: labels.map((_, i) => RAB_PALETTE[i % RAB_PALETTE.length]) }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { color: '#7a90b0', font: { size: 11 }, padding: 12, boxWidth: 12 } },
          tooltip: { backgroundColor: '#1a3a6a', titleColor: '#e8edf5', bodyColor: '#b0c4de' } }
      }
    });
  })();

  // Por dia (linha)
  (function () {
    const dias = Object.keys(POR_DIA);
    const valores = Object.values(POR_DIA);
    if (!dias.length) {
      document.getElementById('bodyDia').innerHTML = '<div class="chart-empty">Sem dados para exibir.</div>';
      return;
    }
    const labels = dias.map(d => { const p = d.split('-'); return p[2] + '/' + p[1]; });
    new Chart(document.getElementById('chartDia'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'Qtd. produzida', data: valores,
        backgroundColor: 'rgba(45,106,255,.15)', borderColor: '#2d6aff', borderWidth: 2,
        fill: true, tension: 0.35, pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: '#2d6aff' }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
          tooltip: { backgroundColor: '#1a3a6a', titleColor: '#e8edf5', bodyColor: '#b0c4de',
            callbacks: { label: ctx => ' ' + Number(ctx.raw).toLocaleString('pt-BR') } } },
        scales: {
          x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#7a90b0', font: { size: 10 } } },
          y: { grid: { color: 'rgba(255,255,255,.06)' }, ticks: { color: '#7a90b0', font: { size: 10 }, callback: v => v.toLocaleString('pt-BR') } }
        }
      }
    });
  })();
})();
</script>
</body>
</html>
