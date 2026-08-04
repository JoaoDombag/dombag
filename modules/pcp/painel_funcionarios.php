<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Painel de Produção por Funcionário
//  Fonte: PostgreSQL ERP Yzidro (OP_ITENS_ATIVIDADES_APONTADAS e correlatas)
//  Somente leitura.
// ══════════════════════════════════════════════════════════════

function pfEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pfSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function pfFmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function pfFmtDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function pfSecondsToHms(float $seconds): string
{
    $seconds = (int) round($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function pfBaseUrl(): string
{
    return '/pcp/painel-funcionarios';
}

// ── Listas para os combos ────────────────────────────────────────────────────
function pfFetchOperadores($pg): array
{
    $res = @pg_query($pg, 'SELECT FU_CODIGO, FU_NOME FROM FUNCIONARIO ORDER BY FU_NOME');
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function pfFetchCentrosTrabalho($pg): array
{
    $res = @pg_query($pg, 'SELECT CT_CODIGO, CT_DESCRICAO FROM CENTRO_TRABALHO ORDER BY CT_DESCRICAO');
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function pfFetchAtividades($pg): array
{
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

// ── Monta a CTE base (apontamentos filtrados + tempos por apontamento) ───────
function pfBuildBaseCte(array $filters): array
{
    $params = [];
    $extras = [];

    if ($filters['data_ini'] !== '') {
        $params[] = $filters['data_ini'];
        $extras[] = 'IAA.OIAP_DATA_HORA_INICIO::date >= $' . count($params);
    }
    if ($filters['data_fim'] !== '') {
        $params[] = $filters['data_fim'];
        $extras[] = 'IAA.OIAP_DATA_HORA_INICIO::date <= $' . count($params);
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

    $where = 'NOT IAA.OIAP_EXCLUIDO';
    if ($extras) {
        $where .= "\n          AND " . implode("\n          AND ", $extras);
    }

    $sql = "
        WITH APONT AS (
           SELECT IAA.OIAP_CODIGO, IAA.OIAP_STATUS, IAA.FU_CODIGO, IAA.CT_CODIGO, IAA.PROD_CODIGO, IAA.PRO_CODIGO, IAA.CLI_CODIGO
                 ,IAA.OIAP_QTD_PRODUZIDA, IAA.OIAP_DATA_HORA_INICIO, IAA.OIAP_DATA_HORA_FIM
             FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
            WHERE {$where}
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

    return [$sql, $params];
}

// ── Ranking por funcionário ───────────────────────────────────────────────────
function pfFetchRanking($pg, array $filters): array
{
    [$baseSql, $params] = pfBuildBaseCte($filters);
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
         LIMIT 300
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar a produção por funcionário: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Produção agregada por dia (todos os funcionários filtrados) ─────────────
function pfFetchPorDia($pg, array $filters): array
{
    [$baseSql, $params] = pfBuildBaseCte($filters);
    $sql = "
        {$baseSql}
        SELECT A.OIAP_DATA_HORA_INICIO::date                                   AS DIA
              ,COALESCE(SUM(A.OIAP_QTD_PRODUZIDA), 0)                         AS QTD_PRODUZIDA
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PRODUTIVO)), 0)              AS SEG_PRODUTIVO
          FROM APONT A
          LEFT JOIN TEMPO T ON T.OIAP_CODIGO = A.OIAP_CODIGO
         GROUP BY A.OIAP_DATA_HORA_INICIO::date
         ORDER BY DIA
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Detalhe diário de um funcionário (para o painel lateral) ─────────────────
function pfFetchDetalheFuncionario($pg, array $filters, int $fuCodigo): array
{
    $filtersFu = $filters;
    $filtersFu['fu_codigo'] = (string) $fuCodigo;
    [$baseSql, $params] = pfBuildBaseCte($filtersFu);
    $sql = "
        {$baseSql}
        SELECT A.OIAP_DATA_HORA_INICIO::date                                   AS DIA
              ,COUNT(DISTINCT A.OIAP_CODIGO)                                   AS QTD_APONTAMENTOS
              ,COALESCE(SUM(A.OIAP_QTD_PRODUZIDA), 0)                         AS QTD_PRODUZIDA
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PRODUTIVO)), 0)              AS SEG_PRODUTIVO
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PAUSADO)), 0)                AS SEG_PAUSADO
          FROM APONT A
          LEFT JOIN TEMPO T ON T.OIAP_CODIGO = A.OIAP_CODIGO
         GROUP BY A.OIAP_DATA_HORA_INICIO::date
         ORDER BY DIA DESC
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Grid de apontamentos individuais (aba "Apontamentos") ────────────────────
function pfFetchApontamentos($pg, array $filters): array
{
    [$baseSql, $params] = pfBuildBaseCte($filters);
    $sql = "
        {$baseSql}
        SELECT A.OIAP_CODIGO, A.OIAP_STATUS, A.OIAP_DATA_HORA_INICIO, A.OIAP_DATA_HORA_FIM, A.OIAP_QTD_PRODUZIDA
              ,F.FU_CODIGO, F.FU_NOME
              ,CT.CT_CODIGO, CT.CT_DESCRICAO
              ,A.PROD_CODIGO, C.CLI_NOME, P.PRO_CODIGO, P.PRO_DESCRICAO
              ,COALESCE(EXTRACT(EPOCH FROM T.PRODUTIVO), 0)                    AS SEG_PRODUTIVO
              ,COALESCE(EXTRACT(EPOCH FROM T.PAUSADO), 0)                      AS SEG_PAUSADO
              ,COALESCE(T.PAUSAS, 0)                                           AS QTD_PAUSAS
          FROM APONT A
         INNER JOIN FUNCIONARIO     F  ON F.FU_CODIGO  = A.FU_CODIGO
         INNER JOIN CENTRO_TRABALHO CT ON CT.CT_CODIGO = A.CT_CODIGO
         INNER JOIN PRODUTO         P  ON P.PRO_CODIGO = A.PRO_CODIGO
         INNER JOIN CLIENTES        C  ON C.CLI_CODIGO = A.CLI_CODIGO
          LEFT JOIN TEMPO T ON T.OIAP_CODIGO = A.OIAP_CODIGO
         ORDER BY A.OIAP_DATA_HORA_INICIO DESC
         LIMIT 500
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar os apontamentos: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Grid de defeitos (aba "Defeitos") ─────────────────────────────────────────
function pfFetchDefeitos($pg, array $filters): array
{
    $params = [];
    $extras = [];

    if ($filters['data_ini'] !== '') {
        $params[] = $filters['data_ini'];
        $extras[] = 'OIAD.OIAD_DATA_HORA::date >= $' . count($params);
    }
    if ($filters['data_fim'] !== '') {
        $params[] = $filters['data_fim'];
        $extras[] = 'OIAD.OIAD_DATA_HORA::date <= $' . count($params);
    }
    if ($filters['fu_codigo'] !== '') {
        $params[] = (int) $filters['fu_codigo'];
        $n = count($params);
        $extras[] = "(OIAD.FU_CODIGO_APONTOU = \${$n} OR OIAD.FU_CODIGO_GEROU = \${$n} OR OIAD.FU_CODIGO_CORRIGIU = \${$n})";
    }

    $where = $extras ? ('WHERE ' . implode("\n          AND ", $extras)) : '';

    $sql = "
        SELECT OIAD.OIAD_CODIGO, OIAD.OIAD_NSU, OIAD.OIAD_QUANTIDADE, OIAD.OIAD_DATA_HORA
              ,OIAD.OIAD_DATA_HORA_CORRECAO_INICIO, OIAD.OIAD_DATA_HORA_CORRECAO_FIM
              ,DF.DF_CODIGO, DF.DF_DESCRICAO
              ,F.FU_NOME  AS APONTADO_POR
              ,F2.FU_NOME AS GERADO_POR
              ,F3.FU_NOME AS CORRIGIDO_POR
              ,IAA.PROD_CODIGO, P.PRO_CODIGO, P.PRO_DESCRICAO
          FROM OP_ITENS_ATIVIDADES_DEFEITOS OIAD
         INNER JOIN DEFEITOS_FABRICACAO DF ON DF.DF_CODIGO = OIAD.DF_CODIGO
         INNER JOIN FUNCIONARIO F  ON F.FU_CODIGO  = OIAD.FU_CODIGO_APONTOU
         INNER JOIN FUNCIONARIO F2 ON F2.FU_CODIGO = OIAD.FU_CODIGO_GEROU
          LEFT JOIN FUNCIONARIO F3 ON F3.FU_CODIGO = OIAD.FU_CODIGO_CORRIGIU
         INNER JOIN OP_ITENS_ATIVIDADES_APONTADAS IAA ON IAA.OIAP_CODIGO = OIAD.OIAP_CODIGO
         INNER JOIN PRODUTO P ON P.PRO_CODIGO = IAA.PRO_CODIGO
         {$where}
         ORDER BY OIAD.OIAD_DATA_HORA DESC
         LIMIT 500
    ";
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar os defeitos: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function pfStatusLabel(string $status): array
{
    $map = [
        'A'  => ['Em Andamento', 'info'],
        'EP' => ['Em Produção', 'ok'],
        'P'  => ['Pausado', 'warn'],
        'F'  => ['Finalizado', 'info'],
        'C'  => ['Cancelado', 'danger'],
    ];
    return $map[trim($status)] ?? [$status ?: '—', 'info'];
}

// ── Totais agregados a partir de um ranking já calculado ─────────────────────
function pfComputeTotais(array $ranking): array
{
    $totalQtdProduzida = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['qtd_produzida'], 0.0);
    $totalSegProdutivo = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['seg_produtivo'], 0.0);
    $totalSegPausado = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['seg_pausado'], 0.0);
    $totalPausas = array_reduce($ranking, fn ($c, $r) => $c + (int) $r['qtd_pausas'], 0);
    $totalApontamentos = array_reduce($ranking, fn ($c, $r) => $c + (int) $r['qtd_apontamentos'], 0);

    return [
        'funcionarios'       => count($ranking),
        'qtd_produzida'      => $totalQtdProduzida,
        'seg_produtivo'      => $totalSegProdutivo,
        'seg_pausado'        => $totalSegPausado,
        'pausas'             => $totalPausas,
        'apontamentos'       => $totalApontamentos,
        'media_geral_hora'   => $totalSegProdutivo > 0 ? $totalQtdProduzida / ($totalSegProdutivo / 3600) : 0.0,
    ];
}

// ── Filtros implícitos da Visão Simples (independentes do form de filtros) ───
function pfSimplesFiltros(string $periodo, ?string $mes = null): array
{
    $hoje = date('Y-m-d');

    if ($periodo !== 'mes') {
        return ['data_ini' => $hoje, 'data_fim' => $hoje, 'fu_codigo' => '', 'ct_codigo' => '', 'atp_codigo' => ''];
    }

    $mesDt = ($mes && preg_match('/^\d{4}-\d{2}$/', $mes)) ? DateTime::createFromFormat('Y-m-d', $mes . '-01') : null;
    if (!$mesDt) {
        $mesDt = new DateTime(date('Y-m-01'));
    }
    $ini = $mesDt->format('Y-m-01');
    $fimMes = $mesDt->format('Y-m-t');
    $fim = $fimMes > $hoje ? $hoje : $fimMes;

    return ['data_ini' => $ini, 'data_fim' => $fim, 'fu_codigo' => '', 'ct_codigo' => '', 'atp_codigo' => ''];
}

// ── Filtros da requisição ────────────────────────────────────────────────────
$filters = [
    'data_ini'   => pfSanitizeDate($_GET['data_ini'] ?? date('Y-m-d')),
    'data_fim'   => pfSanitizeDate($_GET['data_fim'] ?? date('Y-m-d')),
    'fu_codigo'  => trim((string) ($_GET['fu_codigo'] ?? '')),
    'ct_codigo'  => trim((string) ($_GET['ct_codigo'] ?? '')),
    'atp_codigo' => trim((string) ($_GET['atp_codigo'] ?? '')),
];

$pg = dbPG();

// ── AJAX: Visão Simples (hoje / mês) ──────────────────────────────────────────
if (($_GET['action'] ?? '') === 'visao_simples') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    $periodo = ($_GET['periodo'] ?? '') === 'mes' ? 'mes' : 'hoje';
    $mes = trim((string) ($_GET['mes'] ?? ''));
    try {
        $simplesRanking = pfFetchRanking($pg, pfSimplesFiltros($periodo, $mes ?: null));
        echo json_encode(['ranking' => $simplesRanking, 'totais' => pfComputeTotais($simplesRanking)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: detalhe diário de um funcionário (painel lateral) ──────────────────
if (($_GET['action'] ?? '') === 'detalhe_funcionario') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    $fu = (int) ($_GET['fu'] ?? 0);
    try {
        $dias = $fu > 0 ? pfFetchDetalheFuncionario($pg, $filters, $fu) : [];
        echo json_encode(['dias' => $dias], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Carga inicial da página ────────────────────────────────────────────────────
$error = '';
$ranking = [];
$porDia = [];
$apontamentos = [];
$defeitos = [];
$operadores = [];
$centrosTrabalho = [];
$atividades = [];

if (!$pg) {
    $error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    try {
        $ranking = pfFetchRanking($pg, $filters);
        $porDia = pfFetchPorDia($pg, $filters);
        $apontamentos = pfFetchApontamentos($pg, $filters);
        $defeitos = pfFetchDefeitos($pg, $filters);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $operadores = pfFetchOperadores($pg);
    $centrosTrabalho = pfFetchCentrosTrabalho($pg);
    $atividades = pfFetchAtividades($pg);
}

// ── Visão Simples: estado inicial (Hoje), renderizado no servidor ────────────
$simplesRankingHoje = [];
if ($pg) {
    try {
        $simplesRankingHoje = pfFetchRanking($pg, pfSimplesFiltros('hoje'));
    } catch (Throwable $e) {
        // silencioso — a aba mostra o estado vazio; o erro principal já aparece acima
    }
}
$simplesTotaisHoje = pfComputeTotais($simplesRankingHoje);

// ── Totais / KPIs (derivados do ranking, sem consulta extra) ─────────────────
$totalFuncionarios = count($ranking);
$totalQtdProduzida = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['qtd_produzida'], 0.0);
$totalSegProdutivo = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['seg_produtivo'], 0.0);
$totalSegPausado = array_reduce($ranking, fn ($c, $r) => $c + (float) $r['seg_pausado'], 0.0);
$totalPausas = array_reduce($ranking, fn ($c, $r) => $c + (int) $r['qtd_pausas'], 0);
$totalApontamentos = array_reduce($ranking, fn ($c, $r) => $c + (int) $r['qtd_apontamentos'], 0);
$mediaGeralHora = $totalSegProdutivo > 0 ? $totalQtdProduzida / ($totalSegProdutivo / 3600) : 0.0;

$activeFiltersCount = count(array_filter([
    $filters['fu_codigo'], $filters['ct_codigo'], $filters['atp_codigo'],
], fn ($v) => $v !== ''));

// ── Dados para os gráficos ────────────────────────────────────────────────────
$rankingProducao = [];
$rankingMediaHora = [];
foreach ($ranking as $r) {
    $nome = $r['fu_nome'];
    $rankingProducao[$nome] = (float) $r['qtd_produzida'];
    $segProd = (float) $r['seg_produtivo'];
    if ($segProd > 0) {
        $rankingMediaHora[$nome] = round((float) $r['qtd_produzida'] / ($segProd / 3600), 2);
    }
}
arsort($rankingProducao);
$rankingProducao = array_slice($rankingProducao, 0, 12, true);
arsort($rankingMediaHora);
$rankingMediaHora = array_slice($rankingMediaHora, 0, 12, true);

$porDiaLabels = [];
foreach ($porDia as $r) {
    $porDiaLabels[$r['dia']] = (float) $r['qtd_produzida'];
}

// ── KPIs e gráficos de Defeitos ───────────────────────────────────────────────
$totalDefeitos = count($defeitos);
$totalDefeitosQtd = array_reduce($defeitos, fn ($c, $r) => $c + (int) $r['oiad_quantidade'], 0);
$totalDefeitosCorrigidos = array_reduce($defeitos, fn ($c, $r) => $c + ((string) ($r['oiad_data_hora_correcao_fim'] ?? '') !== '' ? 1 : 0), 0);
$totalDefeitosPendentes = $totalDefeitos - $totalDefeitosCorrigidos;

$defeitosPorTipo = [];
$defeitosPorFuncionario = [];
foreach ($defeitos as $r) {
    $defeitosPorTipo[$r['df_descricao']] = ($defeitosPorTipo[$r['df_descricao']] ?? 0) + 1;
    $defeitosPorFuncionario[$r['gerado_por']] = ($defeitosPorFuncionario[$r['gerado_por']] ?? 0) + 1;
}
arsort($defeitosPorTipo);
$defeitosPorTipo = array_slice($defeitosPorTipo, 0, 12, true);
arsort($defeitosPorFuncionario);
$defeitosPorFuncionario = array_slice($defeitosPorFuncionario, 0, 12, true);

function pfJz($v)
{
    return json_encode($v, JSON_UNESCAPED_UNICODE);
}

$jsRankingProducao = pfJz($rankingProducao);
$jsRankingMediaHora = pfJz($rankingMediaHora);
$jsPorDia = pfJz($porDiaLabels);
$jsProdutivoPausado = pfJz(['Produtivo' => round($totalSegProdutivo / 3600, 2), 'Pausado' => round($totalSegPausado / 3600, 2)]);
$jsDefeitosPorTipo = pfJz($defeitosPorTipo);
$jsDefeitosPorFuncionario = pfJz($defeitosPorFuncionario);
$jsSimplesHoje = pfJz(['ranking' => $simplesRankingHoje, 'totais' => $simplesTotaisHoje]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel de Produção por Funcionário | DOMBAG</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .kpi-grid-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 20px; }
  @media (max-width: 1300px) { .kpi-grid-5 { grid-template-columns: repeat(3, 1fr); } }
  @media (max-width: 700px) { .kpi-grid-5 { grid-template-columns: repeat(2, 1fr); } }

  .kpi-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
  @media (max-width: 900px) { .kpi-grid-4 { grid-template-columns: repeat(2, 1fr); } }

  .filter-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; margin-bottom: 16px; }
  .filter-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; user-select: none; }
  .filter-card-head h2 { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
  .filter-count-badge { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: rgba(45,106,255,.12); color: #7db3ff; }
  .filter-toggle-btn { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s, transform .2s; flex-shrink: 0; }
  .filter-toggle-btn:hover { background: var(--card-hover); color: var(--text-primary); }
  .filter-toggle-btn svg { transition: transform .2s ease; }
  .filter-card.collapsed .filter-toggle-btn svg { transform: rotate(-90deg); }
  .filter-card-body { overflow: hidden; max-height: 300px; opacity: 1; margin-top: 16px; transition: max-height .25s ease, opacity .2s ease, margin .25s ease; }
  .filter-card.collapsed .filter-card-body { max-height: 0; opacity: 0; margin-top: 0; }
  .filter-grid { display: grid; grid-template-columns: 150px 150px minmax(200px,1fr) minmax(200px,1fr) minmax(200px,1fr); gap: 16px; align-items: end; }
  .filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
  .filter-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }
  .filter-field input, .filter-field select { height: 36px; padding: 0 10px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; width: 100%; min-width: 0; }
  .filter-field select option { background: var(--blue-mid); }
  .filter-actions { margin-top: 16px; display: flex; gap: 12px; }
  @media (max-width: 1200px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (max-width: 700px) { .filter-grid { grid-template-columns: 1fr 1fr; } }

  .tabs-bar { display: flex; align-items: center; gap: 4px; border-bottom: 1px solid var(--border); padding-bottom: 0; margin-bottom: 18px; flex-shrink: 0; }
  .tab-btn { padding: 8px 18px; border: none; background: transparent; color: var(--text-muted); font-family: 'Segoe UI', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .15s, border-color .15s; }
  .tab-btn:hover { color: var(--text-primary); }
  .tab-btn.active { color: #7db3ff; border-bottom-color: var(--blue-light); font-weight: 600; }
  .tab-content { display: none; }
  .tab-content.active { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow-y: auto; }

  .charts-row { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 16px; }
  .charts-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
  .chart-panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .chart-head { padding: 14px 20px; border-bottom: 1px solid var(--border); }
  .chart-title { font-size: 13px; font-weight: 600; }
  .chart-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
  .chart-body { padding: 16px 16px 12px; }
  .chart-empty { text-align: center; padding: 48px 20px; color: var(--text-muted); font-size: 12.5px; opacity: .6; }
  @media (max-width: 1100px) { .charts-row, .charts-row-2 { grid-template-columns: 1fr; } }

  .pf-row { cursor: pointer; }
  .pf-row:hover { background: var(--card-hover); }
  .pf-name { font-weight: 600; }
  .pf-pct-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
  .pf-pct-pill::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
  .pf-pct-pill.ok { background: rgba(0,201,167,.12); color: var(--teal); }
  .pf-pct-pill.warn { background: rgba(245,158,11,.12); color: var(--amber); }
  .pf-pct-pill.danger { background: rgba(239,68,68,.12); color: var(--red); }

  /* ── Visão Simples ────────────────────────────────────────────────────────── */
  .pf-simples-controls { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
  .pf-simples-toggle { display: inline-flex; gap: 4px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 10px; padding: 4px; }
  .pf-toggle-btn { padding: 8px 22px; border: none; background: transparent; color: var(--text-muted); font-family: 'Segoe UI', sans-serif; font-size: 13px; font-weight: 600; border-radius: 7px; cursor: pointer; transition: background .15s, color .15s; }
  .pf-toggle-btn:hover { color: var(--text-primary); }
  .pf-toggle-btn.active { background: var(--blue-accent, #1e4fc9); color: #fff; }
  .pf-simples-mes-input { height: 38px; padding: 0 12px; font-size: 13px; font-family: 'Segoe UI', sans-serif; border-radius: 8px; }

  .pf-simples-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
  @media (max-width: 900px) { .pf-simples-kpis { grid-template-columns: 1fr; } }
  .pf-simples-kpi { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 22px; text-align: center; }
  .pf-simples-kpi-val { font-size: 30px; font-weight: 700; color: var(--text-primary); line-height: 1; }
  .pf-simples-kpi-lbl { font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-top: 8px; }

  .pf-simples-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
  .pf-simples-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; }
  .pf-simples-card-name { font-size: 13px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; }
  .pf-simples-card-qtd { font-size: 24px; font-weight: 700; color: var(--teal); line-height: 1; }
  .pf-simples-card-lbl { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .03em; margin: 3px 0 10px; }
  .pf-simples-bar-bg { height: 6px; background: rgba(255,255,255,.07); border-radius: 3px; overflow: hidden; margin-bottom: 10px; }
  .pf-simples-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg,#00c9a7,#00e5bf); }
  .pf-simples-card-foot { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted); }
  .pf-simples-card-foot b { color: var(--text-primary); font-weight: 600; }
  .pf-simples-loading, .pf-simples-empty { text-align: center; padding: 48px 20px; color: var(--text-muted); font-size: 12.5px; }

  /* ── Painel lateral (drawer) ─────────────────────────────────────────────── */
  .pf-drawer-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 300; }
  .pf-drawer-overlay.open { opacity: 1; pointer-events: all; }
  .pf-drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 440px; max-width: calc(100vw - 24px); background: #0d1e3a; border-left: 1px solid var(--border); box-shadow: -12px 0 40px rgba(0,0,0,.45); transform: translateX(100%); transition: transform .25s ease; z-index: 301; display: flex; flex-direction: column; }
  .pf-drawer.open { transform: translateX(0); }
  .pf-drawer-head { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-shrink: 0; }
  .pf-drawer-title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
  .pf-drawer-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
  .pf-drawer-close { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .pf-drawer-close:hover { background: var(--card-hover); color: var(--text-primary); }
  .pf-drawer-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
  .pf-field-group { margin-bottom: 18px; }
  .pf-field-group-title { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 8px; }
  .pf-field-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border); }
  .pf-field-row:last-child { border-bottom: none; }
  .pf-field-label { font-size: 12px; color: var(--text-muted); }
  .pf-field-value { font-size: 12.5px; font-weight: 600; color: var(--text-primary); text-align: right; }
  .pf-mini-kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 18px; }
  .pf-mini-kpi { background: rgba(255,255,255,.04); border-radius: 8px; padding: 10px 12px; }
  .pf-mini-kpi-val { font-size: 16px; font-weight: 700; color: var(--text-primary); line-height: 1; margin-bottom: 3px; }
  .pf-mini-kpi-lbl { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .03em; }
  .pf-day-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .pf-day-table th { text-align: left; padding: 7px 8px; color: var(--text-muted); font-weight: 600; font-size: 10.5px; text-transform: uppercase; border-bottom: 1px solid var(--border); }
  .pf-day-table td { padding: 7px 8px; border-bottom: 1px solid var(--border); color: var(--text-primary); }
  .pf-day-table td.td-right, .pf-day-table th.td-right { text-align: right; }
  .pf-drawer-loading { text-align: center; padding: 40px 10px; color: var(--text-muted); font-size: 12.5px; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Painel de Produção por Funcionário</h1>
          <p>Dados do ERP — somente leitura</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button type="button" class="btn-refresh" id="btnAbrirRelatorio">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:5px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Gerar Relatório Completo
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= pfEscape($error) ?>
        </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-grid-5">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Funcionários no período</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($totalFuncionarios, 0, ',', '.') ?></div>
          <div class="kpi-footer">com apontamentos registrados</div>
        </div>
        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Qtd. Produzida</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($totalQtdProduzida, 0, ',', '.') ?></div>
          <div class="kpi-footer">total no período filtrado</div>
        </div>
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Média por Hora (geral)</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($mediaGeralHora, 1, ',', '.') ?></div>
          <div class="kpi-footer">unidades / hora produtiva</div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-header">
            <span class="kpi-label">Horas Produtivas</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($totalSegProdutivo / 3600, 1, ',', '.') ?>h</div>
          <div class="kpi-footer"><?= number_format($totalSegPausado / 3600, 1, ',', '.') ?>h pausadas</div>
        </div>
        <div class="kpi-card red">
          <div class="kpi-header">
            <span class="kpi-label">Pausas</span>
            <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg></div>
          </div>
          <div class="kpi-value"><?= number_format($totalPausas, 0, ',', '.') ?></div>
          <div class="kpi-footer"><?= number_format($totalApontamentos, 0, ',', '.') ?> apontamentos</div>
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
        <form method="get" action="<?= pfEscape(pfBaseUrl()) ?>" id="filterForm">
          <div class="filter-grid">
            <div class="filter-field">
              <label for="data_ini">Data inicial</label>
              <input type="date" id="data_ini" name="data_ini" value="<?= pfEscape($filters['data_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="data_fim">Data final</label>
              <input type="date" id="data_fim" name="data_fim" value="<?= pfEscape($filters['data_fim']) ?>">
            </div>
            <div class="filter-field">
              <label for="fu_codigo">Operador</label>
              <select id="fu_codigo" name="fu_codigo">
                <option value="">Todos</option>
                <?php foreach ($operadores as $op): ?>
                  <option value="<?= pfEscape($op['fu_codigo']) ?>" <?= $filters['fu_codigo'] === (string) $op['fu_codigo'] ? 'selected' : '' ?>>
                    <?= pfEscape($op['fu_codigo'] . ' — ' . $op['fu_nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-field">
              <label for="ct_codigo">Centro de Trabalho</label>
              <select id="ct_codigo" name="ct_codigo">
                <option value="">Todos</option>
                <?php foreach ($centrosTrabalho as $ct): ?>
                  <option value="<?= pfEscape($ct['ct_codigo']) ?>" <?= $filters['ct_codigo'] === (string) $ct['ct_codigo'] ? 'selected' : '' ?>>
                    <?= pfEscape($ct['ct_codigo'] . ' — ' . $ct['ct_descricao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-field">
              <label for="atp_codigo">Atividade de Produção</label>
              <select id="atp_codigo" name="atp_codigo">
                <option value="">Todas</option>
                <?php foreach ($atividades as $atp): ?>
                  <option value="<?= pfEscape($atp['atp_codigo']) ?>" <?= $filters['atp_codigo'] === (string) $atp['atp_codigo'] ? 'selected' : '' ?>>
                    <?= pfEscape($atp['atp_codigo'] . ' — ' . ($atp['atp_label'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-refresh">Filtrar</button>
            <a href="<?= pfEscape(pfBaseUrl()) ?>" class="icon-btn" title="Limpar filtros" style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
          </div>
        </form>
        </div>
      </div>

      <!-- Abas -->
      <div class="tabs-bar">
        <button class="tab-btn active" onclick="pfSwitchTab('simples', this)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
          Visão Simples
        </button>
        <button class="tab-btn" onclick="pfSwitchTab('graficos', this)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Gráficos
        </button>
        <button class="tab-btn" onclick="pfSwitchTab('ranking', this)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Ranking de Funcionários
        </button>
        <button class="tab-btn" onclick="pfSwitchTab('apontamentos', this)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          Apontamentos
        </button>
        <button class="tab-btn" onclick="pfSwitchTab('defeitos', this)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline;vertical-align:middle;margin-right:5px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Defeitos
        </button>
      </div>

      <!-- TAB: Visão Simples -->
      <div class="tab-content active" id="tab-simples">
        <div class="pf-simples-controls">
          <div class="pf-simples-toggle">
            <button type="button" class="pf-toggle-btn active" id="pfSimplesHojeBtn" onclick="pfCarregarSimples('hoje')">Hoje</button>
            <button type="button" class="pf-toggle-btn" id="pfSimplesMesBtn" onclick="pfCarregarSimples('mes')">Mês</button>
          </div>
          <input type="month" id="pfSimplesMesInput" class="pf-simples-mes-input" style="display:none;" value="<?= date('Y-m') ?>" max="<?= date('Y-m') ?>" onchange="pfCarregarSimples('mes')">
        </div>
        <div id="pfSimplesContent"></div>
      </div>

      <!-- TAB: Gráficos -->
      <div class="tab-content" id="tab-graficos">
        <div class="charts-row">
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Produção por Dia</div>
              <div class="chart-sub">Soma de todos os funcionários filtrados</div>
            </div>
            <div class="chart-body" id="bodyPorDia"><canvas id="chartPorDia" height="200"></canvas></div>
          </div>
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Tempo Produtivo x Pausado</div>
              <div class="chart-sub">Total de horas no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyProdPausa"><canvas id="chartProdPausa" height="200"></canvas></div>
          </div>
        </div>
        <div class="charts-row-2">
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Ranking — Quantidade Produzida</div>
              <div class="chart-sub">Top 12 funcionários no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyRankProd"><canvas id="chartRankProd" height="240"></canvas></div>
          </div>
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Ranking — Média por Hora</div>
              <div class="chart-sub">Top 12 funcionários por produtividade/hora</div>
            </div>
            <div class="chart-body" id="bodyRankHora"><canvas id="chartRankHora" height="240"></canvas></div>
          </div>
        </div>
      </div>

      <!-- TAB: Ranking -->
      <div class="tab-content" id="tab-ranking">
        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title">Produção por Funcionário</span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Operador</th>
                  <th class="td-right">Apontamentos</th>
                  <th class="td-right">OPs Distintas</th>
                  <th class="td-right">Máquinas</th>
                  <th class="td-right">Qtd. Produzida</th>
                  <th class="td-right">Tempo Produtivo</th>
                  <th class="td-right">Tempo Pausado</th>
                  <th class="td-right">Pausas</th>
                  <th class="td-right">% Produtivo</th>
                  <th class="td-right">Média/Hora</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$ranking): ?>
                  <tr>
                    <td colspan="10">
                      <div class="td-muted" style="padding:24px;text-align:center;">Nenhum apontamento encontrado com os filtros informados.</div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($ranking as $row):
                      $segProd = (float) $row['seg_produtivo'];
                      $segPausa = (float) $row['seg_pausado'];
                      $qtdProd = (float) $row['qtd_produzida'];
                      $mediaHora = $segProd > 0 ? $qtdProd / ($segProd / 3600) : null;
                      $pctProdutivo = ($segProd + $segPausa) > 0 ? round(($segProd / ($segProd + $segPausa)) * 100) : null;
                      $pctClass = $pctProdutivo === null ? 'warn' : ($pctProdutivo >= 85 ? 'ok' : ($pctProdutivo >= 60 ? 'warn' : 'danger'));
                  ?>
                    <tr class="pf-row" data-fu="<?= pfEscape($row['fu_codigo']) ?>" data-nome="<?= pfEscape($row['fu_nome']) ?>">
                      <td class="pf-name"><?= pfEscape($row['fu_nome']) ?> <span class="td-muted">(<?= pfEscape($row['fu_codigo']) ?>)</span></td>
                      <td class="td-right"><?= number_format((int) $row['qtd_apontamentos'], 0, ',', '.') ?></td>
                      <td class="td-right"><?= number_format((int) $row['qtd_ops'], 0, ',', '.') ?></td>
                      <td class="td-right"><?= number_format((int) $row['qtd_maquinas'], 0, ',', '.') ?></td>
                      <td class="td-right"><?= number_format($qtdProd, 0, ',', '.') ?></td>
                      <td class="td-right"><?= pfEscape(pfSecondsToHms($segProd)) ?></td>
                      <td class="td-right"><?= pfEscape(pfSecondsToHms($segPausa)) ?></td>
                      <td class="td-right"><?= number_format((int) $row['qtd_pausas'], 0, ',', '.') ?></td>
                      <td class="td-right"><?= $pctProdutivo === null ? '<span class="td-muted">—</span>' : '<span class="pf-pct-pill ' . $pctClass . '">' . $pctProdutivo . '%</span>' ?></td>
                      <td class="td-right"><?= $mediaHora === null ? '<span class="td-muted">—</span>' : number_format($mediaHora, 1, ',', '.') ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- TAB: Apontamentos -->
      <div class="tab-content" id="tab-apontamentos">
        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title">Apontamentos</span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Código</th>
                  <th>Operador</th>
                  <th>Centro de Trabalho</th>
                  <th>Cód. OP</th>
                  <th>Cliente</th>
                  <th>Produto</th>
                  <th>Início</th>
                  <th>Fim</th>
                  <th class="td-right">Qtd. Produzida</th>
                  <th class="td-right">T. Produtivo</th>
                  <th class="td-right">T. Pausado</th>
                  <th class="td-right">Pausas</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$apontamentos): ?>
                  <tr>
                    <td colspan="13">
                      <div class="td-muted" style="padding:24px;text-align:center;">Nenhum apontamento encontrado com os filtros informados.</div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($apontamentos as $row):
                      [$stLabel, $stClass] = pfStatusLabel((string) ($row['oiap_status'] ?? ''));
                  ?>
                    <tr>
                      <td><span class="status-pill <?= $stClass ?>"><?= pfEscape($stLabel) ?></span></td>
                      <td class="td-muted"><?= pfEscape($row['oiap_codigo'] ?? '—') ?></td>
                      <td><?= pfEscape($row['fu_nome'] ?? '—') ?></td>
                      <td><?= pfEscape($row['ct_descricao'] ?? '—') ?></td>
                      <td class="td-muted"><?= pfEscape($row['prod_codigo'] ?? '—') ?></td>
                      <td><?= pfEscape($row['cli_nome'] ?? '—') ?></td>
                      <td><?= pfEscape($row['pro_descricao'] ?? '—') ?></td>
                      <td class="td-nowrap"><?= pfEscape(pfFmtDateTime($row['oiap_data_hora_inicio'] ?? '')) ?></td>
                      <td class="td-nowrap"><?= pfEscape(pfFmtDateTime($row['oiap_data_hora_fim'] ?? '')) ?></td>
                      <td class="td-right"><?= number_format((float) ($row['oiap_qtd_produzida'] ?? 0), 0, ',', '.') ?></td>
                      <td class="td-right"><?= pfEscape(pfSecondsToHms((float) ($row['seg_produtivo'] ?? 0))) ?></td>
                      <td class="td-right"><?= pfEscape(pfSecondsToHms((float) ($row['seg_pausado'] ?? 0))) ?></td>
                      <td class="td-right"><?= number_format((int) ($row['qtd_pausas'] ?? 0), 0, ',', '.') ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- TAB: Defeitos -->
      <div class="tab-content" id="tab-defeitos">
        <div class="kpi-grid-4">
          <div class="kpi-card red">
            <div class="kpi-header">
              <span class="kpi-label">Total de Defeitos</span>
              <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
            </div>
            <div class="kpi-value"><?= number_format($totalDefeitos, 0, ',', '.') ?></div>
            <div class="kpi-footer"><?= number_format($totalDefeitosQtd, 0, ',', '.') ?> unidade(s) afetada(s)</div>
          </div>
          <div class="kpi-card teal">
            <div class="kpi-header">
              <span class="kpi-label">Corrigidos</span>
              <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>
            </div>
            <div class="kpi-value"><?= number_format($totalDefeitosCorrigidos, 0, ',', '.') ?></div>
            <div class="kpi-footer"><?= $totalDefeitos > 0 ? number_format($totalDefeitosCorrigidos / $totalDefeitos * 100, 0, ',', '.') : 0 ?>% do total</div>
          </div>
          <div class="kpi-card amber">
            <div class="kpi-header">
              <span class="kpi-label">Pendentes de Correção</span>
              <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            </div>
            <div class="kpi-value"><?= number_format($totalDefeitosPendentes, 0, ',', '.') ?></div>
            <div class="kpi-footer">ainda não corrigidos</div>
          </div>
          <div class="kpi-card blue">
            <div class="kpi-header">
              <span class="kpi-label">Tipo Mais Frequente</span>
              <div class="kpi-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></div>
            </div>
            <div class="kpi-value" style="font-size:15px;"><?= $defeitosPorTipo ? pfEscape(array_key_first($defeitosPorTipo)) : '—' ?></div>
            <div class="kpi-footer"><?= $defeitosPorTipo ? number_format(reset($defeitosPorTipo), 0, ',', '.') . ' ocorrência(s)' : '' ?></div>
          </div>
        </div>

        <div class="charts-row-2">
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Defeitos por Tipo</div>
              <div class="chart-sub">Top 12 tipos no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyDefTipo"><canvas id="chartDefTipo" height="240"></canvas></div>
          </div>
          <div class="chart-panel">
            <div class="chart-head">
              <div class="chart-title">Defeitos por Funcionário</div>
              <div class="chart-sub">Quem gerou o defeito — Top 12 no período filtrado</div>
            </div>
            <div class="chart-body" id="bodyDefFunc"><canvas id="chartDefFunc" height="240"></canvas></div>
          </div>
        </div>

        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title">Defeitos</span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Status</th>
                  <th>NSU</th>
                  <th>Tipo de Defeito</th>
                  <th>Apontado por</th>
                  <th>Gerado por</th>
                  <th>Corrigido por</th>
                  <th>Cód. OP</th>
                  <th>Produto</th>
                  <th class="td-right">Qtd.</th>
                  <th>Data/Hora</th>
                  <th>Correção Início</th>
                  <th>Correção Fim</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$defeitos): ?>
                  <tr>
                    <td colspan="12">
                      <div class="td-muted" style="padding:24px;text-align:center;">Nenhum defeito encontrado com os filtros informados.</div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($defeitos as $row):
                      $corrigido = (string) ($row['oiad_data_hora_correcao_fim'] ?? '') !== '';
                  ?>
                    <tr>
                      <td><span class="status-pill <?= $corrigido ? 'ok' : 'warn' ?>"><?= $corrigido ? 'Corrigido' : 'Pendente' ?></span></td>
                      <td class="td-muted"><?= pfEscape($row['oiad_nsu'] ?? '—') ?></td>
                      <td><?= pfEscape($row['df_descricao'] ?? '—') ?></td>
                      <td><?= pfEscape($row['apontado_por'] ?? '—') ?></td>
                      <td><?= pfEscape($row['gerado_por'] ?? '—') ?></td>
                      <td><?= pfEscape($row['corrigido_por'] ?? '—') ?></td>
                      <td class="td-muted"><?= pfEscape($row['prod_codigo'] ?? '—') ?></td>
                      <td><?= pfEscape($row['pro_descricao'] ?? '—') ?></td>
                      <td class="td-right"><?= number_format((int) ($row['oiad_quantidade'] ?? 0), 0, ',', '.') ?></td>
                      <td class="td-nowrap"><?= pfEscape(pfFmtDateTime($row['oiad_data_hora'] ?? '')) ?></td>
                      <td class="td-nowrap"><?= pfEscape(pfFmtDateTime($row['oiad_data_hora_correcao_inicio'] ?? '')) ?></td>
                      <td class="td-nowrap"><?= pfEscape(pfFmtDateTime($row['oiad_data_hora_correcao_fim'] ?? '')) ?></td>
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

<!-- Painel lateral de detalhes do funcionário -->
<div class="pf-drawer-overlay" id="pfDrawerOverlay"></div>
<div class="pf-drawer" id="pfDrawer">
  <div class="pf-drawer-head">
    <div>
      <div class="pf-drawer-title" id="pfDrawerTitle">—</div>
      <div class="pf-drawer-sub" id="pfDrawerSub">—</div>
    </div>
    <button type="button" class="pf-drawer-close" id="pfDrawerClose" title="Fechar">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="pf-drawer-body" id="pfDrawerBody"></div>
</div>

<!-- Modal: gerar relatório completo em PDF -->
<div class="modal-overlay" id="pfReportModalOverlay">
  <div class="modal-box" style="max-width:380px;">
    <h3>Gerar Relatório Completo</h3>
    <p>Selecione o período para o relatório em PDF com a produção detalhada de todos os funcionários.</p>
    <form id="pfReportForm" style="margin-top:16px;display:flex;flex-direction:column;gap:12px;">
      <div class="filter-field">
        <label for="pfReportDataIni">Data inicial</label>
        <input type="date" id="pfReportDataIni" name="data_ini" required>
      </div>
      <div class="filter-field">
        <label for="pfReportDataFim">Data final</label>
        <input type="date" id="pfReportDataFim" name="data_fim" required>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="icon-btn" id="pfReportCancelBtn" style="width:auto;padding:0 14px;font-size:13px;font-weight:600;">Cancelar</button>
        <button type="submit" class="btn-refresh">Gerar PDF</button>
      </div>
    </form>
  </div>
</div>

<script>
function pfSwitchTab(id, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}

const RANK_PRODUCAO = <?= $jsRankingProducao ?: '{}' ?>;
const RANK_HORA = <?= $jsRankingMediaHora ?: '{}' ?>;
const POR_DIA = <?= $jsPorDia ?: '{}' ?>;
const PROD_PAUSA = <?= $jsProdutivoPausado ?: '{}' ?>;
const DEF_POR_TIPO = <?= $jsDefeitosPorTipo ?: '{}' ?>;
const DEF_POR_FUNC = <?= $jsDefeitosPorFuncionario ?: '{}' ?>;
const RAB_PALETTE = ['#2d6aff','#00c9a7','#f59e0b','#ef4444','#a78bfa','#38bdf8','#fb7185','#34d399','#fbbf24','#60a5fa','#f472b6','#4ade80'];

// ── Visão Simples (Hoje / Mês) ────────────────────────────────────────────────
const SIMPLES_HOJE_INICIAL = <?= $jsSimplesHoje ?: 'null' ?>;
const SIMPLES_CACHE = {};
let pfSimplesPeriodoAtual = 'hoje';

function pfRenderSimples(data) {
  const el = document.getElementById('pfSimplesContent');
  if (!data || data.error) {
    el.innerHTML = `<div class="pf-simples-empty">${data && data.error ? pfEsc(data.error) : 'Não foi possível carregar os dados.'}</div>`;
    return;
  }
  const ranking = data.ranking || [];
  const t = data.totais || {};

  const kpisHtml = `
    <div class="pf-simples-kpis">
      <div class="pf-simples-kpi">
        <div class="pf-simples-kpi-val">${Number(t.qtd_produzida || 0).toLocaleString('pt-BR')}</div>
        <div class="pf-simples-kpi-lbl">Qtd. Produzida</div>
      </div>
      <div class="pf-simples-kpi">
        <div class="pf-simples-kpi-val">${Number(t.media_geral_hora || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}</div>
        <div class="pf-simples-kpi-lbl">Média Geral / Hora</div>
      </div>
      <div class="pf-simples-kpi">
        <div class="pf-simples-kpi-val">${Number(t.funcionarios || 0).toLocaleString('pt-BR')}</div>
        <div class="pf-simples-kpi-lbl">Funcionários</div>
      </div>
    </div>
  `;

  if (!ranking.length) {
    el.innerHTML = kpisHtml + '<div class="pf-simples-empty">Nenhum apontamento neste período.</div>';
    return;
  }

  const maiorQtd = Math.max(...ranking.map(r => Number(r.qtd_produzida || 0)), 1);
  const cardsHtml = ranking.map(r => {
    const qtd = Number(r.qtd_produzida || 0);
    const segProd = Number(r.seg_produtivo || 0);
    const mediaHora = segProd > 0 ? (qtd / (segProd / 3600)) : null;
    const pct = Math.round((qtd / maiorQtd) * 100);
    return `
      <div class="pf-simples-card">
        <div class="pf-simples-card-name">${pfEsc(r.fu_nome)}</div>
        <div class="pf-simples-card-qtd">${qtd.toLocaleString('pt-BR')}</div>
        <div class="pf-simples-card-lbl">Produzido</div>
        <div class="pf-simples-bar-bg"><div class="pf-simples-bar-fill" style="width:${pct}%"></div></div>
        <div class="pf-simples-card-foot">
          <span>Média/h: <b>${mediaHora === null ? '—' : mediaHora.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}</b></span>
          <span>Apont.: <b>${Number(r.qtd_apontamentos || 0).toLocaleString('pt-BR')}</b></span>
        </div>
      </div>
    `;
  }).join('');

  el.innerHTML = kpisHtml + `<div class="pf-simples-cards">${cardsHtml}</div>`;
}

function pfCarregarSimples(periodo) {
  const mesInput = document.getElementById('pfSimplesMesInput');
  const mes = periodo === 'mes' ? (mesInput.value || '<?= date('Y-m') ?>') : '';
  const cacheKey = periodo === 'mes' ? `mes:${mes}` : 'hoje';

  pfSimplesPeriodoAtual = cacheKey;
  document.getElementById('pfSimplesHojeBtn').classList.toggle('active', periodo === 'hoje');
  document.getElementById('pfSimplesMesBtn').classList.toggle('active', periodo === 'mes');
  mesInput.style.display = periodo === 'mes' ? '' : 'none';

  if (SIMPLES_CACHE[cacheKey]) {
    pfRenderSimples(SIMPLES_CACHE[cacheKey]);
    return;
  }

  document.getElementById('pfSimplesContent').innerHTML = '<div class="pf-simples-loading">Carregando...</div>';
  const params = new URLSearchParams({ action: 'visao_simples', periodo });
  if (mes) params.set('mes', mes);
  fetch('?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      SIMPLES_CACHE[cacheKey] = data;
      if (cacheKey === pfSimplesPeriodoAtual) pfRenderSimples(data);
    })
    .catch(() => {
      if (cacheKey === pfSimplesPeriodoAtual) {
        document.getElementById('pfSimplesContent').innerHTML = '<div class="pf-simples-empty">Erro ao carregar os dados.</div>';
      }
    });
}

if (SIMPLES_HOJE_INICIAL) {
  SIMPLES_CACHE.hoje = SIMPLES_HOJE_INICIAL;
  pfRenderSimples(SIMPLES_HOJE_INICIAL);
} else {
  pfCarregarSimples('hoje');
}

function pfBarChart(canvasId, bodyId, dataObj, label) {
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
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { backgroundColor: '#1a3a6a', titleColor: '#e8edf5', bodyColor: '#b0c4de',
          callbacks: { label: ctx => ' ' + Number(ctx.raw).toLocaleString('pt-BR') } } },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.06)' }, ticks: { color: '#7a90b0', font: { size: 10 }, callback: v => v.toLocaleString('pt-BR') } },
        y: { grid: { display: false }, ticks: { color: '#7a90b0', font: { size: 10 } } }
      }
    }
  });
}

(function () {
  pfBarChart('chartRankProd', 'bodyRankProd', RANK_PRODUCAO, 'Qtd. produzida');
  pfBarChart('chartRankHora', 'bodyRankHora', RANK_HORA, 'Unidades / hora');
  pfBarChart('chartDefTipo', 'bodyDefTipo', DEF_POR_TIPO, 'Defeitos');
  pfBarChart('chartDefFunc', 'bodyDefFunc', DEF_POR_FUNC, 'Defeitos');

  // Produção por dia (linha)
  (function () {
    const dias = Object.keys(POR_DIA);
    const valores = Object.values(POR_DIA);
    if (!dias.length) {
      document.getElementById('bodyPorDia').innerHTML = '<div class="chart-empty">Sem dados para exibir.</div>';
      return;
    }
    const labels = dias.map(d => { const p = d.split('-'); return p[2] + '/' + p[1]; });
    new Chart(document.getElementById('chartPorDia'), {
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

  // Produtivo x Pausado (doughnut)
  (function () {
    const labels = Object.keys(PROD_PAUSA);
    const values = Object.values(PROD_PAUSA);
    if (!values.some(v => v > 0)) {
      document.getElementById('bodyProdPausa').innerHTML = '<div class="chart-empty">Sem dados para exibir.</div>';
      return;
    }
    new Chart(document.getElementById('chartProdPausa'), {
      type: 'doughnut',
      data: { labels, datasets: [{ data: values, backgroundColor: ['#00c9a7', '#f59e0b'] }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { color: '#7a90b0', font: { size: 11 }, padding: 12, boxWidth: 12 } },
          tooltip: { backgroundColor: '#1a3a6a', titleColor: '#e8edf5', bodyColor: '#b0c4de',
            callbacks: { label: ctx => ' ' + Number(ctx.raw).toLocaleString('pt-BR') + 'h' } } }
      }
    });
  })();
})();

// ── Painel lateral: detalhe diário do funcionário ────────────────────────────
function pfEsc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function pfFmtDate(v) {
  if (!v) return '—';
  const d = new Date(v.replace(' ', 'T'));
  if (isNaN(d.getTime())) return v;
  return d.toLocaleDateString('pt-BR');
}
function pfSecondsToHms(totalSeconds) {
  const s = Math.max(0, Math.round(totalSeconds));
  const h = String(Math.floor(s / 3600)).padStart(2, '0');
  const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
  const sec = String(s % 60).padStart(2, '0');
  return `${h}:${m}:${sec}`;
}

function pfCurrentFilterParams() {
  const form = document.getElementById('filterForm');
  const params = new URLSearchParams(new FormData(form));
  return params;
}

function abrirDetalheFuncionario(fuCodigo, nome) {
  document.getElementById('pfDrawerTitle').textContent = nome;
  document.getElementById('pfDrawerSub').textContent = `Funcionário ${fuCodigo}`;
  document.getElementById('pfDrawerBody').innerHTML = '<div class="pf-drawer-loading">Carregando...</div>';
  abrirDrawer();

  const params = pfCurrentFilterParams();
  params.set('action', 'detalhe_funcionario');
  params.set('fu', fuCodigo);

  fetch('?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      const dias = data.dias || [];
      if (!dias.length) {
        document.getElementById('pfDrawerBody').innerHTML = '<div class="pf-drawer-loading">Nenhum apontamento neste período.</div>';
        return;
      }
      const totalQtd = dias.reduce((a, d) => a + Number(d.qtd_produzida || 0), 0);
      const totalProd = dias.reduce((a, d) => a + Number(d.seg_produtivo || 0), 0);
      const totalPausa = dias.reduce((a, d) => a + Number(d.seg_pausado || 0), 0);
      const totalApont = dias.reduce((a, d) => a + Number(d.qtd_apontamentos || 0), 0);
      const mediaHora = totalProd > 0 ? (totalQtd / (totalProd / 3600)) : 0;

      const linhas = dias.map(d => {
        const segP = Number(d.seg_produtivo || 0);
        const qtd = Number(d.qtd_produzida || 0);
        const mh = segP > 0 ? (qtd / (segP / 3600)) : null;
        return `<tr>
          <td>${pfFmtDate(d.dia)}</td>
          <td class="td-right">${Number(d.qtd_apontamentos || 0).toLocaleString('pt-BR')}</td>
          <td class="td-right">${qtd.toLocaleString('pt-BR')}</td>
          <td class="td-right">${pfSecondsToHms(segP)}</td>
          <td class="td-right">${mh === null ? '—' : mh.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}</td>
        </tr>`;
      }).join('');

      document.getElementById('pfDrawerBody').innerHTML = `
        <div class="pf-mini-kpis">
          <div class="pf-mini-kpi"><div class="pf-mini-kpi-val">${totalQtd.toLocaleString('pt-BR')}</div><div class="pf-mini-kpi-lbl">Qtd. Produzida</div></div>
          <div class="pf-mini-kpi"><div class="pf-mini-kpi-val">${mediaHora.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}</div><div class="pf-mini-kpi-lbl">Média / Hora</div></div>
          <div class="pf-mini-kpi"><div class="pf-mini-kpi-val">${pfSecondsToHms(totalProd)}</div><div class="pf-mini-kpi-lbl">Tempo Produtivo</div></div>
          <div class="pf-mini-kpi"><div class="pf-mini-kpi-val">${pfSecondsToHms(totalPausa)}</div><div class="pf-mini-kpi-lbl">Tempo Pausado</div></div>
        </div>
        <div class="pf-field-group">
          <div class="pf-field-group-title">Detalhamento Diário (${dias.length} dia${dias.length !== 1 ? 's' : ''}, ${totalApont} apontamento${totalApont !== 1 ? 's' : ''})</div>
          <div style="overflow-x:auto;">
            <table class="pf-day-table">
              <thead><tr><th>Dia</th><th class="td-right">Apont.</th><th class="td-right">Qtd.</th><th class="td-right">T. Produtivo</th><th class="td-right">Média/h</th></tr></thead>
              <tbody>${linhas}</tbody>
            </table>
          </div>
        </div>
      `;
    })
    .catch(() => {
      document.getElementById('pfDrawerBody').innerHTML = '<div class="pf-drawer-loading">Erro ao carregar os dados.</div>';
    });
}

function abrirDrawer() {
  document.getElementById('pfDrawer').classList.add('open');
  document.getElementById('pfDrawerOverlay').classList.add('open');
}
function fecharDrawer() {
  document.getElementById('pfDrawer').classList.remove('open');
  document.getElementById('pfDrawerOverlay').classList.remove('open');
}
document.getElementById('pfDrawerClose').addEventListener('click', fecharDrawer);
document.getElementById('pfDrawerOverlay').addEventListener('click', fecharDrawer);
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharDrawer(); });

document.querySelectorAll('.pf-row').forEach(row => {
  row.addEventListener('click', () => abrirDetalheFuncionario(row.dataset.fu, row.dataset.nome));
});

function pfSetupCollapsible(cardId, headId, toggleId, storageKey) {
  const card = document.getElementById(cardId);
  const head = document.getElementById(headId);
  const toggle = document.getElementById(toggleId);
  if (!card || !head || !toggle) return;
  function setCollapsed(collapsed) {
    card.classList.toggle('collapsed', collapsed);
    localStorage.setItem(storageKey, collapsed ? '1' : '0');
  }
  setCollapsed(localStorage.getItem(storageKey) === '1');
  head.addEventListener('click', () => setCollapsed(!card.classList.contains('collapsed')));
}
pfSetupCollapsible('filterCard', 'filterCardHead', 'filterToggleBtn', 'pf_filtros_colapsados');

// ── Modal: gerar relatório completo em PDF ───────────────────────────────────
(function () {
  const overlay = document.getElementById('pfReportModalOverlay');
  const form = document.getElementById('pfReportForm');
  const dataIniInput = document.getElementById('pfReportDataIni');
  const dataFimInput = document.getElementById('pfReportDataFim');
  const filterDataIni = document.getElementById('data_ini');
  const filterDataFim = document.getElementById('data_fim');

  function abrirModal() {
    dataIniInput.value = filterDataIni?.value || '<?= pfEscape($filters['data_ini']) ?>';
    dataFimInput.value = filterDataFim?.value || '<?= pfEscape($filters['data_fim']) ?>';
    overlay.classList.add('open');
  }
  function fecharModal() {
    overlay.classList.remove('open');
  }

  document.getElementById('btnAbrirRelatorio').addEventListener('click', abrirModal);
  document.getElementById('pfReportCancelBtn').addEventListener('click', fecharModal);
  overlay.addEventListener('click', e => { if (e.target === overlay) fecharModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && overlay.classList.contains('open')) fecharModal(); });

  form.addEventListener('submit', e => {
    e.preventDefault();
    if (dataIniInput.value > dataFimInput.value) {
      [dataIniInput.value, dataFimInput.value] = [dataFimInput.value, dataIniInput.value];
    }
    const params = new URLSearchParams({ data_ini: dataIniInput.value, data_fim: dataFimInput.value });
    window.open('/pcp/painel-funcionarios-pdf?' + params.toString(), '_blank');
    fecharModal();
  });
})();
</script>
</body>
</html>
