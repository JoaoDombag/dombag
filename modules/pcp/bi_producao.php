<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — BI de Produção (painel para TV, leitura em tempo real)
//  Fonte: PostgreSQL ERP Yzidro (somente leitura) + meta local (MySQL)
// ══════════════════════════════════════════════════════════════

const BI_META_CHAVE = 'meta_producao';

const BI_MESES = [
    1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
    7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
];

function biEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function biFetchMeta(PDO $pdo): float
{
    try {
        $v = $pdo->query('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = ' . $pdo->quote(BI_META_CHAVE))->fetchColumn();
        return $v !== false ? (float) $v : 0.0;
    } catch (Throwable) {
        return 0.0;
    }
}

function biSaveMeta(PDO $pdo, float $valor): void
{
    $stmt = $pdo->prepare('
        INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE PAR_VALOR = VALUES(PAR_VALOR)
    ');
    $stmt->execute([':k' => BI_META_CHAVE, ':v' => (string) $valor]);
}

// ── OPs ativas: total de bags (qtd. do pedido) x quantidade lida (produzida) ──
function biFetchOpsAtivas($pg): array
{
    $sql = "
        WITH OPS_ATIVAS AS (
            SELECT DISTINCT IAA.PROD_CODIGO, IAA.PRO_CODIGO, IAA.CLI_CODIGO
              FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
             WHERE NOT IAA.OIAP_EXCLUIDO
               AND IAA.OIAP_DATA_HORA_FIM IS NULL
        )
        SELECT OA.PROD_CODIGO
              ,OA.PRO_CODIGO
              ,P.PRO_DESCRICAO
              ,C.CLI_NOME
              ,VOP.QTD_PEDIDO AS OP_QTD_PEDIDO
              ,COALESCE((
                  SELECT SUM(IAA2.OIAP_QTD_PRODUZIDA)
                    FROM OP_ITENS_ATIVIDADES_APONTADAS IAA2
                   WHERE IAA2.PROD_CODIGO = OA.PROD_CODIGO
                     AND IAA2.PRO_CODIGO  = OA.PRO_CODIGO
                     AND NOT IAA2.OIAP_EXCLUIDO
                     AND TRIM(IAA2.OIAP_STATUS) <> 'C'
              ), 0) AS OP_QTD_PRODUZIDA_TOTAL
          FROM OPS_ATIVAS OA
          LEFT JOIN PRODUTO           P   ON P.PRO_CODIGO     = OA.PRO_CODIGO
          LEFT JOIN CLIENTES          C   ON C.CLI_CODIGO     = OA.CLI_CODIGO
          LEFT JOIN VW_ORDEM_PRODUCAO VOP ON VOP.COD_PRODUCAO  = OA.PROD_CODIGO AND VOP.COD_PRODUTO = OA.PRO_CODIGO
         ORDER BY OA.PROD_CODIGO
    ";
    $res = @pg_query($pg, $sql);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Produção válida (não cancelada) do mês/ano informado ─────────────────────
function biFetchProducaoMes($pg, int $ano, int $mes): float
{
    $sql = "
        SELECT COALESCE(SUM(OIAP_QTD_PRODUZIDA), 0) AS QTD
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO
           AND TRIM(OIAP_STATUS) <> 'C'
           AND EXTRACT(YEAR FROM OIAP_DATA_HORA_INICIO) = $1
           AND EXTRACT(MONTH FROM OIAP_DATA_HORA_INICIO) = $2
    ";
    $res = @pg_query_params($pg, $sql, [$ano, $mes]);
    if (!$res) {
        return 0.0;
    }
    $row = pg_fetch_assoc($res) ?: [];
    pg_free_result($res);
    return (float) ($row['qtd'] ?? 0);
}

// ── Total produzido (não cancelado) por mês, no ano informado ────────────────
function biFetchMonthly($pg, int $ano): array
{
    $sql = "
        SELECT EXTRACT(MONTH FROM OIAP_DATA_HORA_INICIO)::int AS MES,
               COALESCE(SUM(OIAP_QTD_PRODUZIDA), 0) AS QTD
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO
           AND TRIM(OIAP_STATUS) <> 'C'
           AND EXTRACT(YEAR FROM OIAP_DATA_HORA_INICIO) = $1
         GROUP BY MES
    ";
    $res = @pg_query_params($pg, $sql, [$ano]);
    $porMes = array_fill(1, 12, 0.0);
    if (!$res) {
        return $porMes;
    }
    foreach (pg_fetch_all($res) ?: [] as $row) {
        $porMes[(int) $row['mes']] = (float) $row['qtd'];
    }
    pg_free_result($res);
    return $porMes;
}

// ── Horas produtivas no mês/ano informado (para calcular o ritmo bags/hora) ──
function biFetchHorasProdutivasMes($pg, int $ano, int $mes): float
{
    $sql = "
        WITH APONT AS (
            SELECT OIAP_CODIGO
              FROM OP_ITENS_ATIVIDADES_APONTADAS
             WHERE NOT OIAP_EXCLUIDO
               AND EXTRACT(YEAR FROM OIAP_DATA_HORA_INICIO) = $1
               AND EXTRACT(MONTH FROM OIAP_DATA_HORA_INICIO) = $2
        )
        SELECT COALESCE(SUM(
                   CASE WHEN TRIM(L.OIAL_STATUS) = 'EP'
                        THEN EXTRACT(EPOCH FROM GREATEST(COALESCE(L.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - L.OIAL_DATA_HORA_INICIO, interval '0'))
                        ELSE 0 END
               ), 0) AS SEG_PRODUTIVO
          FROM OP_ITENS_ATIVIDADES_LOGS L
         INNER JOIN APONT ON APONT.OIAP_CODIGO = L.OIAP_CODIGO
         WHERE NOT L.OIAL_EXCLUIDO
    ";
    $res = @pg_query_params($pg, $sql, [$ano, $mes]);
    if (!$res) {
        return 0.0;
    }
    $row = pg_fetch_assoc($res) ?: [];
    pg_free_result($res);
    return (float) ($row['seg_produtivo'] ?? 0) / 3600;
}

// ── Indicadores agregados do dia (funcionários, produção, tempos, pausas) ────
function biFetchIndicadoresHoje($pg): array
{
    $vazio = ['funcionarios' => 0, 'apontamentos' => 0, 'qtd_produzida' => 0.0, 'seg_produtivo' => 0.0, 'seg_pausado' => 0.0, 'pausas' => 0, 'media_hora' => 0.0];

    $sql = "
        WITH APONT AS (
            SELECT OIAP_CODIGO, FU_CODIGO, OIAP_QTD_PRODUZIDA
              FROM OP_ITENS_ATIVIDADES_APONTADAS
             WHERE NOT OIAP_EXCLUIDO
               AND TRIM(OIAP_STATUS) <> 'C'
               AND OIAP_DATA_HORA_INICIO::date = CURRENT_DATE
        ),
        TEMPO AS (
            SELECT L.OIAP_CODIGO
                  ,SUM(CASE WHEN TRIM(L.OIAL_STATUS) = 'EP'
                            THEN GREATEST(COALESCE(L.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - L.OIAL_DATA_HORA_INICIO, interval '0')
                            ELSE interval '0' END) AS PRODUTIVO
                  ,SUM(CASE WHEN TRIM(L.OIAL_STATUS) = 'P' AND (L.MPP_CODIGO IS NULL OR L.MPP_CODIGO NOT IN (1, 2))
                            THEN GREATEST(COALESCE(L.OIAL_DATA_HORA_FIM, CURRENT_TIMESTAMP) - L.OIAL_DATA_HORA_INICIO, interval '0')
                            ELSE interval '0' END) AS PAUSADO
                  ,COUNT(CASE WHEN TRIM(L.OIAL_STATUS) = 'P' AND (L.MPP_CODIGO IS NULL OR L.MPP_CODIGO NOT IN (1, 2)) THEN 1 END) AS PAUSAS
              FROM OP_ITENS_ATIVIDADES_LOGS L
             INNER JOIN APONT ON APONT.OIAP_CODIGO = L.OIAP_CODIGO
             WHERE NOT L.OIAL_EXCLUIDO
             GROUP BY L.OIAP_CODIGO
        )
        SELECT COUNT(DISTINCT A.FU_CODIGO)                        AS FUNCIONARIOS
              ,COUNT(DISTINCT A.OIAP_CODIGO)                      AS APONTAMENTOS
              ,COALESCE(SUM(A.OIAP_QTD_PRODUZIDA), 0)             AS QTD_PRODUZIDA
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PRODUTIVO)), 0)  AS SEG_PRODUTIVO
              ,COALESCE(SUM(EXTRACT(EPOCH FROM T.PAUSADO)), 0)    AS SEG_PAUSADO
              ,COALESCE(SUM(T.PAUSAS), 0)                         AS QTD_PAUSAS
          FROM APONT A
          LEFT JOIN TEMPO T ON T.OIAP_CODIGO = A.OIAP_CODIGO
    ";
    $res = @pg_query($pg, $sql);
    if (!$res) {
        return $vazio;
    }
    $row = pg_fetch_assoc($res) ?: [];
    pg_free_result($res);
    if (!$row) {
        return $vazio;
    }

    $segProdutivo = (float) ($row['seg_produtivo'] ?? 0);
    $qtdProduzida = (float) ($row['qtd_produzida'] ?? 0);

    return [
        'funcionarios'   => (int) ($row['funcionarios'] ?? 0),
        'apontamentos'   => (int) ($row['apontamentos'] ?? 0),
        'qtd_produzida'  => $qtdProduzida,
        'seg_produtivo'  => $segProdutivo,
        'seg_pausado'    => (float) ($row['seg_pausado'] ?? 0),
        'pausas'         => (int) ($row['qtd_pausas'] ?? 0),
        'media_hora'     => $segProdutivo > 0 ? $qtdProduzida / ($segProdutivo / 3600) : 0.0,
    ];
}

// ── Células cadastradas e seus centros de trabalho (MySQL local) ─────────────
function biFetchCelulas(PDO $pdo): array
{
    try {
        $celulas = $pdo->query('SELECT CEL_CODIGO, CEL_NOME FROM CELULA_PRODUCAO ORDER BY CEL_NOME')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $st = $pdo->prepare('SELECT CT_CODIGO FROM CELULA_CENTRO_TRABALHO WHERE CEL_CODIGO = :c');
    foreach ($celulas as &$c) {
        $st->execute([':c' => (int) $c['CEL_CODIGO']]);
        $c['centros'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    unset($c);
    return $celulas;
}

// ── Quantidade produzida hoje, por centro de trabalho ────────────────────────
function biFetchCentrosHoje($pg): array
{
    $sql = "
        SELECT CT_CODIGO, FU_CODIGO, COALESCE(SUM(OIAP_QTD_PRODUZIDA), 0) AS QTD_PRODUZIDA
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO
           AND TRIM(OIAP_STATUS) <> 'C'
           AND OIAP_DATA_HORA_INICIO::date = CURRENT_DATE
         GROUP BY CT_CODIGO, FU_CODIGO
    ";
    $res = @pg_query($pg, $sql);
    if (!$res) {
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Produção de hoje agrupada por célula (soma dos centros de trabalho) ──────
function biFetchProducaoPorCelula(PDO $pdo, array $centrosHoje): array
{
    $celulas = biFetchCelulas($pdo);
    if (!$celulas) {
        return [];
    }
    $qtdPorCt   = [];
    $funcsPorCt = [];
    foreach ($centrosHoje as $f) {
        $ct = (int) $f['ct_codigo'];
        $qtdPorCt[$ct] = ($qtdPorCt[$ct] ?? 0.0) + (float) $f['qtd_produzida'];
        if ($f['fu_codigo'] !== null && $f['fu_codigo'] !== '') {
            $funcsPorCt[$ct][(int) $f['fu_codigo']] = true;
        }
    }

    $resultado = [];
    foreach ($celulas as $c) {
        $total = 0.0;
        $funcs = [];
        foreach ($c['centros'] as $ct) {
            $total += $qtdPorCt[$ct] ?? 0.0;
            $funcs += $funcsPorCt[$ct] ?? [];
        }
        $resultado[] = [
            'cel_codigo' => (int) $c['CEL_CODIGO'],
            'cel_nome'   => $c['CEL_NOME'],
            'qtd_produzida' => $total,
            'qtd_centros' => count($c['centros']),
            'qtd_funcionarios' => count($funcs),
        ];
    }
    usort($resultado, static fn ($a, $b) => $b['qtd_produzida'] <=> $a['qtd_produzida']);
    return $resultado;
}

function biBuildPayload($pg, PDO $pdo): array
{
    $anoAtual = (int) date('Y');
    $mesAtual = (int) date('n');

    $mesProduzido = biFetchProducaoMes($pg, $anoAtual, $mesAtual);
    $horasProdutivas = biFetchHorasProdutivasMes($pg, $anoAtual, $mesAtual);
    $centrosHoje = biFetchCentrosHoje($pg);

    return [
        'ops_ativas'        => biFetchOpsAtivas($pg),
        'mes_produzido'     => $mesProduzido,
        'meta'              => biFetchMeta($pdo),
        'mes_nome'          => ucfirst(BI_MESES[$mesAtual]),
        'media_bags_hora'   => $horasProdutivas > 0 ? round($mesProduzido / $horasProdutivas, 1) : 0.0,
        'celulas'           => biFetchProducaoPorCelula($pdo, $centrosHoje),
        'mensal'            => array_values(biFetchMonthly($pg, $anoAtual)),
        'meses'             => array_values(BI_MESES),
        'indicadores'       => biFetchIndicadoresHoje($pg),
        'atualizado_em'     => date('H:i:s'),
    ];
}

$pdo = dbPDO();
$pg  = dbPG();

// ── AJAX: salvar meta ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_meta') {
    header('Content-Type: application/json; charset=utf-8');
    $valor = (float) str_replace(',', '.', (string) ($_POST['meta'] ?? '0'));
    if ($valor < 0) {
        $valor = 0.0;
    }
    try {
        biSaveMeta($pdo, $valor);
        echo json_encode(['ok' => true, 'meta' => $valor]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: atualizar dados (polling em tempo real) ─────────────────────────────
if (($_GET['action'] ?? '') === 'refresh') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).']);
        exit;
    }
    try {
        echo json_encode(biBuildPayload($pg, $pdo), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$error = '';
$payload = [
    'ops_ativas' => [], 'mes_produzido' => 0, 'meta' => 0, 'mes_nome' => ucfirst(BI_MESES[(int) date('n')]),
    'media_bags_hora' => 0, 'celulas' => [], 'mensal' => array_fill(0, 12, 0), 'meses' => array_values(BI_MESES),
    'indicadores' => ['funcionarios' => 0, 'apontamentos' => 0, 'qtd_produzida' => 0.0, 'seg_produtivo' => 0.0, 'seg_pausado' => 0.0, 'pausas' => 0, 'media_hora' => 0.0],
    'atualizado_em' => date('H:i:s'),
];
if (!$pg) {
    $error = 'Não foi possível conectar ao banco de dados do ERP (PostgreSQL).';
} else {
    try {
        $payload = biBuildPayload($pg, $pdo);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="escuro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BI da Produção | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  :root {
    --biz-bg: #05070a;
    --biz-card: #0d1117;
    --biz-card2: #10161d;
    --biz-border: rgba(255,255,255,.09);
    --biz-orange: #f0a638;
    --biz-teal: #58d6c9;
    --biz-teal-2: #34b6a8;
    --biz-text: #eef2f7;
    --biz-muted: #8fa0b3;
    /* Largura de referência do painel: o #bizDashboard é sempre desenhado
       nessa largura fixa e depois escalado (JS: biApplyScale) pra caber no
       espaço real disponível — é isso que garante a mesma aparência em
       qualquer tamanho de tela, só maior ou menor. */
    --bi-ref-w: 1800px;
  }

  /* Painel de TV/kiosk: em vez de reorganizar colunas por breakpoint, o
     #bizDashboard é desenhado num tamanho fixo de referência (ver mais
     abaixo) e o JS (biApplyScale) calcula um único fator de escala pra
     caber no espaço disponível, aplicado via transform:scale() — a
     aparência fica idêntica em qualquer tamanho de tela, só menor/maior.
     .content vira o "palco": centraliza o painel (align-items/justify-
     content:center) e esconde qualquer sobra (overflow:hidden) — como o
     fator de escala é o mínimo entre largura e altura disponíveis, o
     painel escalado sempre cabe inteiro, então o corte nunca deveria
     realmente acontecer na prática (é só uma rede de segurança). */
  .content { display: flex; align-items: center; justify-content: center; overflow: hidden; min-width: 0; padding: 0; }
  /* unified_admin.css muda .app-wrapper/.main/.content pra layout de página
     normal (altura automática, rolagem) abaixo de 768px de viewport — bom
     pro resto do sistema, mas quebraria a escala (que depende de .content
     ter uma altura real definida pra calcular o fator). Como aqui a escala
     deve valer em QUALQUER tamanho de tela, essas regras (com !important,
     por isso repetidas aqui com !important também) ficam desligadas nesta
     página específica. */
  @media (max-width: 768px) {
    .app-wrapper { height: 100vh !important; overflow: hidden !important; flex-direction: row !important; }
    .main { height: 100vh !important; overflow: hidden !important; }
    .content { padding: 0 !important; overflow: hidden !important; height: 100vh !important; flex: 1 1 auto !important; display: flex !important; }
  }

  /* Widgets crescem/encolhem para sempre preencher o painel, acompanhando
     o tamanho real da janela (não só a largura). min-height:0 é o que
     permite encolher de verdade — não se aplica mais aqui: o painel agora
     tem largura fixa de referência (--bi-ref-w) e altura natural (soma do
     conteúdo), sem flex-grow disputando espaço; é tudo escalado de uma vez
     via transform:scale() em vez de cada widget crescer/encolher sozinho. */
  #bizDashboard {
    width: var(--bi-ref-w);
    display: flex; flex-direction: column; gap: 16px;
    background: var(--biz-bg); border-width: 0; border-radius: 0;
    padding: 26px 28px; color: var(--biz-text); font-family: 'Segoe UI', sans-serif;
    box-shadow: none; box-sizing: border-box;
    flex-shrink: 0; transform-origin: center center;
  }
  .biz-title { text-align: center; font-size: 21px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--biz-orange); margin: 2px 0 20px; }

  .biz-toprow { display: grid; grid-template-columns: 1.3fr 1fr; gap: 16px; align-items: stretch; }
  .biz-toprow.biz-toprow-single { grid-template-columns: 1fr; }
  .biz-toprow > .biz-card { min-width: 0; display: flex; flex-direction: column; }

  /* ── Indicadores do dia (KPIs) — mini-cards compactos, no mesmo estilo denso
     do restante do painel (evita repetir o .kpi-card do admin padrão, que é
     alto demais pra um painel de TV com várias seções na mesma tela). ── */
  .biz-kpi-mini-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
  .biz-kpi-mini { border-left: 3px solid var(--biz-teal); background: var(--biz-card2); border-radius: 8px; padding: 12px; min-width: 0; }
  .biz-kpi-mini.k-teal  { border-left-color: var(--biz-teal); }
  .biz-kpi-mini.k-blue  { border-left-color: #3aa7ff; }
  .biz-kpi-mini.k-amber { border-left-color: var(--biz-orange); }
  .biz-kpi-mini.k-red   { border-left-color: #f0645f; }
  .biz-kpi-mini-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--biz-muted); overflow-wrap: anywhere; }
  .biz-kpi-mini-value { font-size: 22px; font-weight: 700; color: var(--biz-text); line-height: 1.2; margin-top: 3px; font-variant-numeric: tabular-nums; }
  .biz-kpi-mini-foot { font-size: 10px; color: var(--biz-muted); margin-top: 2px; }

  .biz-card { background: var(--biz-card); border: 1px solid var(--biz-border); border-radius: 14px; padding: 16px 20px 18px; box-shadow: 0 10px 24px -16px rgba(0,0,0,.5); min-width: 0; }
  .biz-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--biz-border); }
  .biz-card-head h2 { font-size: 12.5px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--biz-muted); }
  .biz-count-badge { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: rgba(88,214,201,.12); color: var(--biz-teal); }

  /* ── Widgets opcionais escondidos pela configuração do painel ── */
  .biz-widget-hidden { display: none !important; }

  /* ── Gráfico: Total Produzido por Mês (opcional) ── */
  .biz-chart-card { background: var(--biz-card); border: 1px solid var(--biz-border); border-radius: 14px; padding: 16px 20px 6px; box-shadow: 0 10px 24px -16px rgba(0,0,0,.5); display: flex; flex-direction: column; }
  .biz-chart-card .biz-card-head { margin-bottom: 4px; }
  .biz-chart-wrap { width: 100%; height: 160px; }
  .biz-chart-wrap svg { width: 100%; height: 100%; display: block; }
  .biz-chart-axis { font-size: 11.5px; fill: var(--biz-muted); font-weight: 400; }
  .biz-chart-value { font-size: 11.5px; fill: var(--biz-text); font-weight: 500; }

  /* ── Botão e modal de personalização do painel ── */
  .biz-settings-btn { display: flex; align-items: center; gap: 7px; }
  .biz-widget-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 500;
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity .2s;
  }
  .biz-widget-modal-overlay.open { opacity: 1; pointer-events: all; }
  .biz-widget-modal {
    background: var(--biz-card); border: 1px solid var(--biz-border); border-radius: 14px;
    padding: 22px 24px; width: 420px; max-width: calc(100vw - 32px); color: var(--biz-text);
    font-family: 'Segoe UI', sans-serif; box-shadow: 0 20px 60px rgba(0,0,0,.5);
  }
  .biz-widget-modal h3 { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
  .biz-widget-modal p { font-size: 12px; color: var(--biz-muted); margin-bottom: 14px; }
  .biz-widget-option {
    display: flex; align-items: center; gap: 10px; padding: 9px 4px; border-bottom: 1px solid var(--biz-border);
    font-size: 13px; cursor: pointer;
  }
  .biz-widget-option:last-of-type { border-bottom: none; }
  .biz-widget-option input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--biz-teal); }
  .biz-widget-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
  .biz-widget-modal-actions button {
    height: 32px; padding: 0 14px; font-size: 12.5px; font-weight: 500; border-radius: 7px; border: none; cursor: pointer; font-family: inherit;
  }
  .biz-widget-save { background: var(--biz-teal); color: #04231c; }
  .biz-widget-cancel { background: transparent; color: var(--biz-muted); border: 1px solid var(--biz-border) !important; }

  /* ── OPs ativas: grade de cards. O nº de colunas é calculado em JS
     (biOpsCols, em função da quantidade de OPs — ver biRenderOps) pra manter
     no máximo 3 linhas: 1–3 OPs = 1 coluna, 4–6 = 2 colunas, 7–9 = 3
     colunas, e assim por diante. Sem scroll: se ainda assim não coubesse
     tudo no espaço do card, a grade corta (overflow:hidden) e mostra um
     traço "==" + degradê em vez de rolar ou vazar por cima do próximo card. */
  /* grid-auto-rows: minmax(min-content, 1fr) — nunca encolhe abaixo do
     conteúdo (min-content), mas estica (1fr) pra preencher o espaço sobrando
     quando há poucas linhas; numa tela menor, o próprio espaço disponível
     encolhe, então o "1fr" naturalmente diminui a altura das linhas junto. */
  .biz-ops-list { display: grid; grid-template-columns: 1fr; grid-auto-rows: min-content; gap: 10px; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
  .biz-op-item { display: flex; flex-direction: column; justify-content: center; min-width: 0; border: 1px solid var(--biz-border); border-radius: 10px; padding: 14px; background: var(--biz-card2); transition: border-color .15s; }
  .biz-op-item:hover { border-color: rgba(88,214,201,.35); }
  .biz-op-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 4px 8px; margin-bottom: 6px; }
  .biz-op-name { font-size: 15px; font-weight: 600; color: var(--biz-text); overflow-wrap: anywhere; }
  .biz-op-sub { font-size: 12.5px; color: var(--biz-muted); margin-top: 1px; }
  .biz-op-pct { font-size: 15px; font-weight: 700; color: var(--biz-teal); flex-shrink: 0; }
  .biz-op-pct.over { color: var(--biz-orange); }
  .biz-op-bar { height: 9px; background: rgba(255,255,255,.07); border-radius: 5px; overflow: hidden; }
  .biz-op-fill { height: 100%; border-radius: 5px; background: linear-gradient(90deg,#3aa7ff,#58d6c9); transition: width .3s ease; }
  .biz-op-fill.over { background: linear-gradient(90deg,#f0a638,#fbbf24); }
  .biz-op-nums { margin-top: 5px; font-size: 11px; color: var(--biz-muted); text-align: right; }
  .biz-op-nums strong { color: var(--biz-text); font-weight: 600; }
  .biz-empty-msg { text-align: center; color: var(--biz-muted); font-size: 13px; padding: 40px 0; }

  /* ── Mês x Meta: centraliza o corpo do card (fora do cabeçalho) ── */
  .biz-meta-body { flex: 1 1 auto; min-height: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: center; }
  .biz-meta-btn { width: 22px; height: 22px; border-radius: 6px; border: 1px solid var(--biz-border); background: transparent; color: var(--biz-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .biz-meta-btn:hover { background: rgba(255,255,255,.06); color: var(--biz-text); }
  .biz-gauge-svg-wrap { width: 70%; max-width: 420px; min-width: 140px; margin: 4px auto 0; }
  .biz-gauge-svg-wrap svg { width: 100%; height: auto; display: block; }
  .biz-gauge-scale { display: flex; justify-content: space-between; font-size: 11px; color: var(--biz-muted); padding: 0 10px; margin-top: -6px; }
  .biz-meta-hint { font-size: 11px; color: var(--biz-muted); margin-top: 8px; font-weight: 400; text-align: center; }
  .biz-meta-form { display: none; align-items: center; justify-content: center; gap: 8px; margin-top: 10px; }
  .biz-meta-form.open { display: flex; }
  .biz-meta-form input { height: 30px; width: 110px; padding: 0 8px; font-size: 13px; font-weight: 400; font-family: 'Segoe UI', sans-serif; border-radius: 7px; border: 1px solid var(--biz-border); background: var(--biz-card2); color: var(--biz-text); }
  .biz-meta-form button { height: 30px; padding: 0 10px; font-size: 12px; font-weight: 500; border-radius: 7px; border: none; cursor: pointer; }
  .biz-meta-save { background: var(--biz-teal); color: #04231c; }
  .biz-meta-cancel { background: transparent; color: var(--biz-muted); border: 1px solid var(--biz-border) !important; }

  /* ── Painel de funcionários (visão simples) ── */
  .biz-func-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); grid-auto-rows: 1fr; gap: 12px; flex: 1 1 auto; }
  .biz-func-card { border: 1px solid var(--biz-border); border-radius: 10px; padding: 18px; background: var(--biz-card2); display: flex; align-items: center; justify-content: space-between; gap: 8px; transition: border-color .15s; min-width: 0; }
  .biz-func-card:hover { border-color: rgba(88,214,201,.35); }
  .biz-func-name { font-size: 13px; font-weight: 600; color: var(--biz-text); overflow-wrap: anywhere; }
  .biz-func-qtd { font-size: 20px; font-weight: 700; color: var(--biz-teal); font-variant-numeric: tabular-nums; }

  /* ── Topbar desta página: badge "Ao vivo" + hora + 2 botões de texto não
     cabem numa única linha em telas de celular; o topbar padrão não quebra
     linha (flex-shrink:0 nos topbar-actions), o que empurrava os botões
     para fora da viewport e espremia o título. ── */
  @media (max-width: 640px) {
    .topbar { flex-wrap: wrap; row-gap: 8px; }
    .topbar-actions { flex-wrap: wrap; row-gap: 6px; }
    .last-update { display: none; }
  }
  @media (max-width: 360px) {
    .biz-settings-btn span, .biz-fullscreen-btn span { display: none; }
  }

  .biz-fullscreen-btn { display: flex; align-items: center; gap: 7px; }

  /* ── Indicador "ao vivo" ── */
  .biz-live { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--biz-teal); }
  .biz-live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--biz-teal); box-shadow: 0 0 0 0 rgba(88,214,201,.6); animation: bizPulse 1.8s infinite; }
  @keyframes bizPulse {
    0%   { box-shadow: 0 0 0 0 rgba(88,214,201,.55); }
    70%  { box-shadow: 0 0 0 7px rgba(88,214,201,0); }
    100% { box-shadow: 0 0 0 0 rgba(88,214,201,0); }
  }

  /* ── Modo tela cheia: esconde sidebar/topbar, dando mais espaço real pro
     JS (biApplyScale) escalar o painel — a aparência em si não muda em
     tela cheia, só o fator de escala calculado (mais espaço disponível
     geralmente = escala maior). */
  html:fullscreen .sidebar, body.biz-fs-fallback .sidebar,
  html:fullscreen .topbar,  body.biz-fs-fallback .topbar { display: none !important; }
  html:fullscreen .biz-settings-btn, body.biz-fs-fallback .biz-settings-btn { display: none !important; }

  html:fullscreen .app-wrapper, body.biz-fs-fallback .app-wrapper { height: 100vh !important; overflow: hidden; }
  html:fullscreen .main,        body.biz-fs-fallback .main        { height: 100vh !important; overflow: hidden; }
  html:fullscreen .content,     body.biz-fs-fallback .content     { height: 100vh; padding: 0; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>BI da Produção</h1>
          <p>Leitura em tempo real dos apontamentos — atualização automática</p>
        </div>
      </div>
      <div class="topbar-actions">
        <span class="biz-live"><span class="biz-live-dot"></span>Ao vivo</span>
        <span class="last-update">Atualizado às <span id="biUpdatedAt"><?= biEscape($payload['atualizado_em']) ?></span></span>
        <button type="button" class="btn-secondary biz-settings-btn" id="biSettingsBtn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          <span>Personalizar Painel</span>
        </button>
        <button type="button" class="btn-secondary biz-fullscreen-btn" id="biFullscreenBtn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
          <span id="biFullscreenLabel">Tela cheia</span>
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= biEscape($error) ?>
        </div>
      <?php endif; ?>

      <div id="bizDashboard">

        <!-- Indicadores do dia -->
        <div class="biz-card" data-widget="indicadores">
          <div class="biz-card-head">
            <h2>Indicadores do Dia</h2>
          </div>
          <div class="biz-kpi-mini-grid">
            <div class="biz-kpi-mini k-blue">
              <div class="biz-kpi-mini-label">Funcionários</div>
              <div class="biz-kpi-mini-value" id="biKpiFuncionarios">0</div>
              <div class="biz-kpi-mini-foot">com apontamentos</div>
            </div>
            <div class="biz-kpi-mini k-teal">
              <div class="biz-kpi-mini-label">Qtd. Produzida</div>
              <div class="biz-kpi-mini-value" id="biKpiQtdProduzida">0</div>
              <div class="biz-kpi-mini-foot">total no dia</div>
            </div>
            <div class="biz-kpi-mini k-blue">
              <div class="biz-kpi-mini-label">Média/Hora (geral)</div>
              <div class="biz-kpi-mini-value" id="biKpiMediaHora">0</div>
              <div class="biz-kpi-mini-foot">unid. / hora produtiva</div>
            </div>
            <div class="biz-kpi-mini k-amber">
              <div class="biz-kpi-mini-label">Horas Produtivas</div>
              <div class="biz-kpi-mini-value" id="biKpiHorasProdutivas">0h</div>
              <div class="biz-kpi-mini-foot"><span id="biKpiHorasPausadas">0</span>h pausadas</div>
            </div>
            <div class="biz-kpi-mini k-red">
              <div class="biz-kpi-mini-label">Pausas</div>
              <div class="biz-kpi-mini-value" id="biKpiPausas">0</div>
              <div class="biz-kpi-mini-foot"><span id="biKpiApontamentos">0</span> apontamentos</div>
            </div>
          </div>
        </div>

        <!-- Gráfico: Total Produzido por Mês (opcional) -->
        <div class="biz-chart-card" data-widget="grafico_mensal">
          <div class="biz-card-head">
            <h2>Total Produzido por Mês (<?= (int) date('Y') ?>)</h2>
          </div>
          <div class="biz-chart-wrap"><svg id="biChartSvg" viewBox="0 0 1200 360" preserveAspectRatio="xMidYMid meet"></svg></div>
        </div>

        <div class="biz-toprow" id="biToprow">

          <!-- OPs ativas: total de bags x quantos lidos -->
          <div class="biz-card" data-widget="ops_ativas">
            <div class="biz-card-head">
              <h2>OPs Ativas — Bags Lidos</h2>
              <span class="biz-count-badge" id="biOpsCount">0</span>
            </div>
            <div class="biz-ops-list" id="biOpsList"></div>
          </div>

          <!-- Produção do mês x meta -->
          <div class="biz-card" data-widget="meta_mes">
            <div class="biz-card-head">
              <h2 id="biMesTitulo">Produção do Mês</h2>
              <button type="button" class="biz-meta-btn" id="biMetaEditBtn" title="Editar meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
              </button>
            </div>
            <div class="biz-meta-body">
              <div class="biz-gauge-svg-wrap"><svg id="biGaugeMeta" viewBox="0 0 300 175"></svg></div>
              <div class="biz-gauge-scale"><span>0,00%</span><span>100,00%</span></div>
              <div class="biz-meta-hint">Produzido: <strong id="biMesProduzidoTxt">0</strong> / Meta: <span id="biMetaAtualTxt">0</span> unidades</div>
              <div class="biz-meta-hint">Ritmo: <strong id="biMediaHoraTxt">0</strong> bags/hora (média do mês)</div>
              <form class="biz-meta-form" id="biMetaForm">
                <input type="number" min="0" step="1" id="biMetaInput" value="<?= biEscape((string) (int) $payload['meta']) ?>">
                <button type="submit" class="biz-meta-save">Salvar</button>
                <button type="button" class="biz-meta-cancel" id="biMetaCancel">Cancelar</button>
              </form>
            </div>
          </div>

        </div>

        <!-- Produção por Célula — soma dos funcionários de cada célula -->
        <div class="biz-card" id="biCelulasCard" data-widget="celulas" style="display:none;">
          <div class="biz-card-head">
            <h2>Produção por Célula Hoje</h2>
            <span class="biz-count-badge" id="biCelulasCount">0</span>
          </div>
          <div class="biz-func-grid" id="biCelulasGrid"></div>
        </div>

      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- Modal: Personalizar Painel (escolher quais widgets aparecem) -->
<div class="biz-widget-modal-overlay" id="biWidgetModalOverlay">
  <div class="biz-widget-modal">
    <h3>Personalizar Painel</h3>
    <p>Escolha o que deve aparecer no BI. A preferência fica salva neste navegador.</p>
    <div id="biWidgetOptions"></div>
    <div class="biz-widget-modal-actions">
      <button type="button" class="biz-widget-cancel" id="biWidgetCancelBtn">Cancelar</button>
      <button type="button" class="biz-widget-save" id="biWidgetSaveBtn">Salvar</button>
    </div>
  </div>
</div>

<script>
function biFmt(n, casas) {
  return Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: casas || 0, maximumFractionDigits: casas || 0 });
}
function biEsc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
let biMetaAtual = <?= (float) $payload['meta'] ?>;

// ── Gauge em semicírculo (SVG com pathLength normalizado 0-100) ──────────────
function biRenderGauge(svgId, pct, colorFrom, colorTo) {
  const svg = document.getElementById(svgId);
  const clamped = Math.max(0, Math.min(100, pct));
  const gradId = svgId + 'Grad';
  const d = 'M 30 155 A 120 120 0 0 1 270 155';

  const fgPath = clamped > 0
    ? `<path d="${d}" fill="none" stroke="url(#${gradId})" stroke-width="18" stroke-linecap="round" pathLength="100" stroke-dasharray="${clamped} ${100 - clamped}"/>`
    : '';

  svg.innerHTML = `
    <defs>
      <linearGradient id="${gradId}" x1="0" y1="0" x2="1" y2="0">
        <stop offset="0%" stop-color="${colorFrom}"/>
        <stop offset="100%" stop-color="${colorTo}"/>
      </linearGradient>
    </defs>
    <path d="${d}" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="18" stroke-linecap="round" pathLength="100"/>
    ${fgPath}
    <text x="150" y="135" text-anchor="middle" fill="#eef2f7" font-size="28" font-weight="600">${pct === null ? '—' : biFmt(pct, 2) + '%'}</text>
  `;
}

// ── Gráfico de linha (SVG desenhado via JS, sem libs externas) ───────────────
let biLastChartData = null;

function biRenderChart(meses, valores) {
  biLastChartData = { meses, valores };
  const svg = document.getElementById('biChartSvg');
  const wrap = svg.parentElement;
  // O viewBox acompanha o tamanho real do contêiner (em vez de um 1200x360
  // fixo) pra não sobrar "letterbox" nas laterais quando a proporção do
  // painel é bem mais larga/baixa que 1200:360 — antes disso o gráfico
  // ocupava só uma faixa central e desperdiçava boa parte da largura.
  const W = Math.max(200, wrap.clientWidth || 1200);
  const H = Math.max(120, wrap.clientHeight || 260);
  const ML = 30, MR = 30, MT = Math.round(H * 0.12), MB = Math.round(H * 0.16);
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  const n = valores.length;
  const max = Math.max(1, ...valores);

  if (!valores.some(v => v > 0)) {
    svg.innerHTML = `<text x="${W/2}" y="${H/2}" text-anchor="middle" class="biz-empty-msg" fill="#8fa0b3" font-size="14">Sem dados de produção para o período.</text>`;
    return;
  }

  const stepX = (W - ML - MR) / (n - 1);
  const points = valores.map((v, i) => {
    const x = ML + i * stepX;
    const y = MT + (H - MT - MB) * (1 - v / max);
    return { x, y, v };
  });

  const linePath = points.map((p, i) => (i === 0 ? 'M' : 'L') + p.x.toFixed(1) + ',' + p.y.toFixed(1)).join(' ');
  const areaPath = linePath + ` L${points[n-1].x.toFixed(1)},${(H-MB).toFixed(1)} L${points[0].x.toFixed(1)},${(H-MB).toFixed(1)} Z`;

  let svgHtml = `
    <defs>
      <linearGradient id="biAreaGrad" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#58d6c9" stop-opacity="0.35"/>
        <stop offset="100%" stop-color="#58d6c9" stop-opacity="0"/>
      </linearGradient>
    </defs>
    <path d="${areaPath}" fill="url(#biAreaGrad)" stroke="none"/>
    <path d="${linePath}" fill="none" stroke="#58d6c9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
  `;

  points.forEach((p, i) => {
    const labelY = Math.max(14, p.y - 14);
    svgHtml += `<circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="3.5" fill="#0d1117" stroke="#58d6c9" stroke-width="2"/>`;
    svgHtml += `<text x="${p.x.toFixed(1)}" y="${labelY.toFixed(1)}" text-anchor="middle" class="biz-chart-value">${biFmt(p.v, 0)}</text>`;
    svgHtml += `<text x="${p.x.toFixed(1)}" y="${H - MB + 22}" text-anchor="middle" class="biz-chart-axis">${meses[i]}</text>`;
  });

  svg.innerHTML = svgHtml;
}

// ── OPs ativas: colunas da grade em função da quantidade (máx. 3 linhas) ─────
// 1-3 OPs -> 1 coluna (1, 2 ou 3 linhas); 4-6 -> 2 colunas (2 ou 3 linhas);
// 7-9 -> 3 colunas; e assim por diante, sempre arredondando pra cima.
function biOpsCols(n) {
  return n <= 0 ? 1 : Math.ceil(n / 3);
}

// ── OPs ativas: barra de progresso (bags lidos x total do pedido) ────────────
function biRenderOps(ops) {
  document.getElementById('biOpsCount').textContent = ops.length;
  const el = document.getElementById('biOpsList');
  el.style.gridTemplateColumns = `repeat(${biOpsCols(ops.length)}, 1fr)`;
  if (!ops.length) {
    el.innerHTML = '<div class="biz-empty-msg">Nenhuma OP em produção no momento.</div>';
    return;
  }
  el.innerHTML = ops.map(op => {
    const produzido = Number(op.op_qtd_produzida_total || 0);
    const pedido = Number(op.op_qtd_pedido || 0);
    const temPedido = pedido > 0;
    const pct = temPedido ? Math.round((produzido / pedido) * 100) : 0;
    const over = pct > 100;
    const fillPct = Math.min(100, pct);
    return `
      <div class="biz-op-item">
        <div class="biz-op-head">
          <div>
            <div class="biz-op-name">${biEsc(op.pro_descricao || op.pro_codigo || '—')}</div>
            <div class="biz-op-sub">OP ${biEsc(op.prod_codigo ?? '—')} · ${biEsc(op.cli_nome || '—')}</div>
          </div>
          <div class="biz-op-pct${over ? ' over' : ''}">${temPedido ? pct + '%' : '—'}</div>
        </div>
        <div class="biz-op-bar"><div class="biz-op-fill${over ? ' over' : ''}" style="width:${fillPct}%"></div></div>
        <div class="biz-op-nums">Lido: <strong>${produzido.toLocaleString('pt-BR')}</strong> / Total: <strong>${temPedido ? pedido.toLocaleString('pt-BR') : '—'}</strong></div>
      </div>
    `;
  }).join('');
}

// ── Produção por célula — soma dos funcionários de cada célula ───────────────
function biRenderCelulas(celulas) {
  const card = document.getElementById('biCelulasCard');
  if (!celulas.length) {
    card.style.display = 'none';
    return;
  }
  card.style.display = '';
  document.getElementById('biCelulasCount').textContent = celulas.length;
  document.getElementById('biCelulasGrid').innerHTML = celulas.map(c => `
    <div class="biz-func-card">
      <div>
        <div class="biz-func-name">${biEsc(c.cel_nome)}</div>
        <div class="biz-op-sub">${c.qtd_funcionarios} funcionário(s) hoje</div>
      </div>
      <div class="biz-func-qtd">${Number(c.qtd_produzida || 0).toLocaleString('pt-BR')}</div>
    </div>
  `).join('');
}

function biRenderIndicadores(ind) {
  ind = ind || {};
  document.getElementById('biKpiFuncionarios').textContent = biFmt(ind.funcionarios, 0);
  document.getElementById('biKpiQtdProduzida').textContent = biFmt(ind.qtd_produzida, 0);
  document.getElementById('biKpiMediaHora').textContent = biFmt(ind.media_hora, 1);
  document.getElementById('biKpiHorasProdutivas').textContent = biFmt((ind.seg_produtivo || 0) / 3600, 1) + 'h';
  document.getElementById('biKpiHorasPausadas').textContent = biFmt((ind.seg_pausado || 0) / 3600, 1);
  document.getElementById('biKpiPausas').textContent = biFmt(ind.pausas, 0);
  document.getElementById('biKpiApontamentos').textContent = biFmt(ind.apontamentos, 0);
}

// ── Painel de TV: escala #bizDashboard (desenhado em largura fixa --bi-ref-w)
// pra sempre cobrir 100% do espaço real de .content (sem sobrar margem),
// mantendo a mesma aparência em qualquer tamanho de tela — o eixo que sobra
// é cortado (.content tem overflow:hidden).
// offsetWidth/offsetHeight são o tamanho de LAYOUT (sem transform), então
// funcionam como "tamanho natural" mesmo com a escala já aplicada antes.
const BI_SCALE_MIN = 0.4;
const BI_SCALE_MAX = 1.6;

// Faixa de ajuste permitida pra largura do palco em torno de --bi-ref-w (na
// prática, min/max-width em px calculados a partir da variável CSS).
const BI_REF_W = 1800;
const BI_REF_W_MIN = BI_REF_W * 0.8;
const BI_REF_W_MAX = BI_REF_W * 1.25;

function biApplyScale() {
  const stage = document.getElementById('bizDashboard');
  const wrap = stage.parentElement; // .content
  const availW = wrap.clientWidth;
  const availH = wrap.clientHeight;
  if (!availW || !availH) return;

  // 1) mede a altura natural na largura de referência (sem distorcer nada).
  stage.style.width = '';
  const baseH = stage.offsetHeight;
  if (!baseH) return;

  // 2) largura "ideal" pra bater com a proporção da tela disponível, dentro
  // de um limite (±20%/25%) — sem isso, a folga sobra toda de um lado só
  // (ex.: telas largas deixam margem lateral grande e margem vertical quase
  // zero); ajustando a largura do palco pra mesma proporção da tela, o
  // encaixe (fitScale) fecha nos dois eixos ao mesmo tempo, e a margem de
  // cima fica igual à das laterais.
  const idealW = baseH * (availW / availH);
  const stageW = Math.max(BI_REF_W_MIN, Math.min(BI_REF_W_MAX, idealW));
  stage.style.width = stageW + 'px';

  const natW = stage.offsetWidth;
  const natH = stage.offsetHeight;
  if (!natW || !natH) return;

  let scale = Math.max(availW / natW, availH / natH);
  scale = Math.max(BI_SCALE_MIN, Math.min(BI_SCALE_MAX, scale));
  stage.style.transform = `scale(${scale})`;
}
window.addEventListener('resize', biApplyScale);

function biRenderAll(data) {
  biRenderChart(data.meses || [], data.mensal || []);
  biRenderOps(data.ops_ativas || []);
  biRenderCelulas(data.celulas || []);
  biRenderIndicadores(data.indicadores);

  document.getElementById('biMesTitulo').textContent = 'Produção de ' + (data.mes_nome || '');
  document.getElementById('biMesProduzidoTxt').textContent = biFmt(data.mes_produzido, 0);
  document.getElementById('biMediaHoraTxt').textContent = biFmt(data.media_bags_hora, 1);

  if (!document.getElementById('biMetaForm').classList.contains('open')) {
    document.getElementById('biMetaAtualTxt').textContent = biFmt(data.meta, 0);
  }
  biMetaAtual = Number(data.meta || 0);

  const pct = biMetaAtual > 0 ? Math.round((data.mes_produzido / biMetaAtual) * 100) : 0;
  biRenderGauge('biGaugeMeta', biMetaAtual > 0 ? pct : 0, '#3aa7ff', '#58d6c9');

  const upd = document.getElementById('biUpdatedAt');
  if (upd) upd.textContent = data.atualizado_em || '';

  // A quantidade de OPs/células pode mudar a altura natural do painel a
  // cada atualização — reescalar garante que continue cabendo certinho.
  biApplyScale();
}

const BI_INITIAL = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;
biRenderAll(BI_INITIAL);

// ── Atualização periódica (tempo real) ────────────────────────────────────────
function biRefresh() {
  fetch('?action=refresh', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (data.error) return;
      biRenderAll(data);
    })
    .catch(() => {});
}
setInterval(biRefresh, 15000);

// ── Edição da meta ────────────────────────────────────────────────────────────
const biMetaTxtEl = document.getElementById('biMetaAtualTxt');
const biMetaForm = document.getElementById('biMetaForm');
const biMetaInput = document.getElementById('biMetaInput');

document.getElementById('biMetaEditBtn').addEventListener('click', () => {
  biMetaInput.value = Math.round(biMetaAtual);
  biMetaForm.classList.add('open');
  biMetaInput.focus();
  biMetaInput.select();
});
document.getElementById('biMetaCancel').addEventListener('click', () => {
  biMetaForm.classList.remove('open');
});
biMetaForm.addEventListener('submit', (e) => {
  e.preventDefault();
  const valor = Math.max(0, Number(biMetaInput.value || 0));
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=save_meta&meta=' + encodeURIComponent(valor),
  })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        biMetaAtual = data.meta;
        biMetaTxtEl.textContent = biFmt(data.meta, 0);
        biRefresh();
      }
      biMetaForm.classList.remove('open');
    })
    .catch(() => { biMetaForm.classList.remove('open'); });
});

// ── Tela cheia ─────────────────────────────────────────────────────────────────
// Usa a Fullscreen API nativa (com prefixos para navegadores antigos/TVs) e, em
// paralelo, uma classe CSS de reforço — assim o modo "tela cheia" (esconder menu
// e ampliar o painel) funciona mesmo se o navegador bloquear a API nativa.
const biFsBtn = document.getElementById('biFullscreenBtn');
const biFsLabel = document.getElementById('biFullscreenLabel');

function biFsElement() {
  return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
}
function biRequestFs(el) {
  const fn = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
  return fn ? fn.call(el) : Promise.reject(new Error('Fullscreen API indisponível'));
}
function biExitFs() {
  const fn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
  return fn ? fn.call(document) : Promise.reject(new Error('Fullscreen API indisponível'));
}
function biSetFsState(active) {
  document.body.classList.toggle('biz-fs-fallback', active);
  biFsLabel.textContent = active ? 'Sair da tela cheia' : 'Tela cheia';
  biReflowChart();
}

// Redesenha o gráfico mensal com as dimensões reais do contêiner sempre que
// elas mudam (redimensionar janela, entrar/sair da tela cheia, mostrar o
// widget depois de escondido) — o viewBox do SVG é calculado a partir do
// tamanho do contêiner em biRenderChart, então precisa ser refeito.
function biReflowChart() {
  if (biLastChartData) requestAnimationFrame(() => biRenderChart(biLastChartData.meses, biLastChartData.valores));
  requestAnimationFrame(biApplyScale);
}
window.addEventListener('resize', biReflowChart);

biFsBtn.addEventListener('click', () => {
  const active = document.body.classList.contains('biz-fs-fallback');
  if (!active) {
    biSetFsState(true);
    biRequestFs(document.documentElement).catch(() => {});
  } else {
    biSetFsState(false);
    if (biFsElement()) biExitFs().catch(() => {});
  }
});
['fullscreenchange', 'webkitfullscreenchange', 'MSFullscreenChange'].forEach(ev => {
  document.addEventListener(ev, () => {
    if (!biFsElement()) biSetFsState(false);
  });
});

// ── Personalizar Painel: escolher quais widgets aparecem (salvo no navegador) ──
const BI_WIDGET_CATALOG = [
  { id: 'indicadores',    label: 'Indicadores do Dia',             default: true },
  { id: 'ops_ativas',     label: 'OPs Ativas — Bags Lidos',        default: true },
  { id: 'meta_mes',       label: 'Produção do Mês x Meta',         default: true },
  { id: 'celulas',        label: 'Produção por Célula',            default: true },
  { id: 'grafico_mensal', label: 'Gráfico Mensal (linha)',         default: false },
];
const BI_WIDGET_STORAGE_KEY = 'bi_widget_config';

function biLoadWidgetConfig() {
  const config = {};
  BI_WIDGET_CATALOG.forEach(w => { config[w.id] = w.default; });
  try {
    const saved = JSON.parse(localStorage.getItem(BI_WIDGET_STORAGE_KEY) || '{}');
    Object.assign(config, saved);
  } catch (e) {}
  return config;
}

function biSaveWidgetConfig(config) {
  try { localStorage.setItem(BI_WIDGET_STORAGE_KEY, JSON.stringify(config)); } catch (e) {}
}

function biApplyWidgetConfig(config) {
  BI_WIDGET_CATALOG.forEach(w => {
    document.querySelectorAll(`[data-widget="${w.id}"]`).forEach(el => {
      el.classList.toggle('biz-widget-hidden', !config[w.id]);
    });
  });
  const toprow = document.getElementById('biToprow');
  const visiveisNoTopo = ['ops_ativas', 'meta_mes'].filter(id => config[id]).length;
  toprow.classList.toggle('biz-toprow-single', visiveisNoTopo <= 1);
}

let biWidgetConfig = biLoadWidgetConfig();
biApplyWidgetConfig(biWidgetConfig);
biReflowChart();

const biWidgetModalOverlay = document.getElementById('biWidgetModalOverlay');
const biWidgetOptionsEl = document.getElementById('biWidgetOptions');

function biRenderWidgetOptions() {
  biWidgetOptionsEl.innerHTML = BI_WIDGET_CATALOG.map(w => `
    <label class="biz-widget-option">
      <input type="checkbox" data-widget-checkbox="${w.id}" ${biWidgetConfig[w.id] ? 'checked' : ''}>
      <span>${w.label}</span>
    </label>
  `).join('');
}

document.getElementById('biSettingsBtn').addEventListener('click', () => {
  biRenderWidgetOptions();
  biWidgetModalOverlay.classList.add('open');
});
document.getElementById('biWidgetCancelBtn').addEventListener('click', () => {
  biWidgetModalOverlay.classList.remove('open');
});
biWidgetModalOverlay.addEventListener('click', (e) => {
  if (e.target === e.currentTarget) biWidgetModalOverlay.classList.remove('open');
});
document.getElementById('biWidgetSaveBtn').addEventListener('click', () => {
  const novoConfig = {};
  BI_WIDGET_CATALOG.forEach(w => {
    const cb = biWidgetOptionsEl.querySelector(`[data-widget-checkbox="${w.id}"]`);
    novoConfig[w.id] = !!(cb && cb.checked);
  });
  biWidgetConfig = novoConfig;
  biSaveWidgetConfig(biWidgetConfig);
  biApplyWidgetConfig(biWidgetConfig);
  biReflowChart();
  biWidgetModalOverlay.classList.remove('open');
});
</script>
</body>
</html>
