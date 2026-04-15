<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Pedidos ERP Yzidro
//  SQL idêntico ao Consulta de Vendas (VW_VENDAS, mesmos filtros).
//  Compara pedidos do PostgreSQL com o MySQL local.
//  "Importar Vendas" → servidor busca itens no ERP e grava no MySQL.
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();

require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function yzEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function yzSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function yzFmtDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function yzFmtMoney($value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function yzBaseUrl(): string
{
    return '/yzidro/pedidos';
}

// ── Classifica produto pelo nome ──────────────────────────────────────────────
function classificarProduto(string $nome): array
{
    $n = strtoupper($nome);

    $tipo = '';
    if (str_contains($n, 'SACARIA') || str_contains($n, 'SACO')) {
        $tipo = 'SACARIA';
    } elseif (str_contains($n, 'BIG BAG') || str_contains($n, 'BIGBAG') || preg_match('/\bBAG\b/', $n)) {
        $tipo = 'BAG';
    }

    $travado = str_contains($n, 'TRAVADO') ? 1 : 0;
    $impressao = (preg_match('/C[\/O]?M IMPRESS/i', $n) || str_contains($n, 'C/ IMPRESS')) ? 1 : 0;
    if (preg_match('/S[\/E]?M IMPRESS/i', $n) || str_contains($n, 'S/ IMPRESS')) {
        $impressao = 0;
    }

    // Valvulado
    $valvulado = str_contains($n, 'VALVULADO') ? 1 : 0;

    // Comprimento: extrai das dimensões (WxLxH para bag, WxL para sacaria)
    $comprimento = null;
    if ($tipo === 'BAG') {
        if (preg_match('/\b\d+\s*[X]\s*\d+\s*[X]\s*(\d+)/i', $n, $m)) {
            $comprimento = (float) $m[1];
        }
    } elseif ($tipo === 'SACARIA') {
        if (preg_match('/\b\d+\s*[X]\s*(\d+)/i', $n, $m)) {
            $comprimento = (float) $m[1];
        }
    }

    // Máquina de impressão
    $maqImpressao = '';
    if ($impressao) {
        if ($tipo === 'BAG') {
            $maqImpressao = 'Impressao Bag';
        } elseif ($tipo === 'SACARIA') {
            $maqImpressao = (str_contains($n, 'LAMINADA') || str_contains($n, ' LAM ') || str_contains($n, 'CILINDRO'))
                ? 'Flexo'
                : 'Carimbadeira Sacaria';
        }
    }

    // Fluxo de produção
    $fluxo = '';
    if ($tipo === 'BAG') {
        $fluxo = $impressao
            ? 'Corte Bag > Impressao Bag > Costura Bag > Analise Bag'
            : 'Corte Bag > Costura Bag > Analise Bag';
    } elseif ($tipo === 'SACARIA') {
        if ($impressao) {
            $base = $maqImpressao === 'Flexo'
                ? 'Flexo > Corte+Costura Sacaria'
                : 'Carimbadeira Sacaria > Corte+Costura Sacaria';
            $fluxo = $valvulado === 'SIM' ? $base . ' > Valvuladeira' : $base;
        } else {
            $fluxo = $valvulado === 'SIM'
                ? 'Corte+Costura Sacaria > Valvuladeira'
                : 'Corte+Costura Sacaria';
        }
    }

    $categoria = match(true) {
        $tipo === 'BAG' && !$travado && !$impressao => 'Bag sem impressão',
        $tipo === 'BAG' && !$travado && $impressao => 'Bag com impressão',
        $tipo === 'BAG' && $travado && !$impressao => 'Bag travado sem impressão',
        $tipo === 'BAG' && $travado && $impressao => 'Bag travado com impressão',
        $tipo === 'SACARIA' && !$impressao => 'Sacaria sem impressão',
        $tipo === 'SACARIA' && $impressao => 'Sacaria com impressão',
        default => 'Não classificado',
    };

    return compact('tipo', 'travado', 'impressao', 'valvulado', 'comprimento', 'maqImpressao', 'fluxo', 'categoria');
}

// ── Lista de pedidos — SQL IDÊNTICO ao Consulta de Vendas ─────────────────────
function yzFetchVendas($pg, array $filters): array
{
    $params = [];
    $extras = [];

    if ($filters['emissao_ini'] !== '') {
        $params[] = $filters['emissao_ini'];
        $extras[] = 'VV.DATAEMISSAO >= $' . count($params);
    }
    if ($filters['emissao_fim'] !== '') {
        $params[] = $filters['emissao_fim'];
        $extras[] = 'VV.DATAEMISSAO <= $' . count($params);
    }
    if ($filters['fatura_ini'] !== '') {
        $params[] = $filters['fatura_ini'];
        $extras[] = 'VV.DATAFATURA >= $' . count($params);
    }
    if ($filters['fatura_fim'] !== '') {
        $params[] = $filters['fatura_fim'];
        $extras[] = 'VV.DATAFATURA <= $' . count($params);
    }
    if ($filters['pedido'] !== '') {
        $params[] = '%' . $filters['pedido'] . '%';
        $extras[] = 'VV.PEDIDO::TEXT ILIKE $' . count($params);
    }
    if ($filters['cliente'] !== '') {
        $params[] = '%' . $filters['cliente'] . '%';
        $extras[] = '(VV.NOME_FANTASIA ILIKE $' . count($params)
                  . ' OR VV.CLIENTE ILIKE $' . count($params) . ')';
    }
    if ($filters['representante'] !== '') {
        $params[] = '%' . $filters['representante'] . '%';
        $extras[] = 'CAST(VV.VENDEDOR_VENDA AS VARCHAR) ILIKE $' . count($params);
    }

    $where = "VV.COD_GRUPO IN (6, 24, 5)
          AND VV.OPERACAO_PEDIDO = 'V'
          AND VV.COD_ESTADO IN ('9')
          AND VV.EMP_CODIGO = 1";

    if ($extras) {
        $where .= "\n          AND " . implode("\n          AND ", $extras);
    }

    $sql = "
        SELECT VV.PEDIDO::TEXT                                                  AS pedido
              ,CAST(IIF(COALESCE(MIN(VV.NOME_FANTASIA),'') = ''
                       ,MIN(VV.CLIENTE)
                       ,MIN(VV.NOME_FANTASIA)) AS VARCHAR(80))                  AS cliente
              ,COALESCE(CAST(MIN(VV.VENDEDOR_VENDA) AS VARCHAR(64)), '')        AS representante
              ,TO_CHAR(MIN(VV.DATAEMISSAO), 'YYYY-MM-DD')                       AS dataemissao
              ,TO_CHAR(MAX(VV.DATAFATURA),  'YYYY-MM-DD')                       AS datafatura
              ,COALESCE(SUM(VV.TOTAL_PRODUTO), 0)                               AS total_pedido
              ,COUNT(*)                                                         AS qtd_itens
              ,CASE WHEN BOOL_OR(VV.OBS ILIKE '%Urgente%') THEN 1 ELSE 0 END  AS tem_urgente
              ,MIN(VV.QTD)                                                     AS min_qtd
              ,MAX(VV.QTD)                                                     AS max_qtd
        FROM VW_VENDAS VV
        WHERE {$where}
        GROUP BY VV.PEDIDO
        ORDER BY MIN(VV.DATAEMISSAO) DESC, VV.PEDIDO DESC
    ";

    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar as vendas do ERP: ' . pg_last_error($pg));
    }

    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $rows[] = $row;
    }
    pg_free_result($res);
    return $rows;
}

// ── Itens de um pedido (exibição + campos de importação) ─────────────────────
function yzFetchItensPedido($pg, string $pedido): array
{
    $sql = "
        SELECT
            VV.PEDIDO::TEXT                                         AS pedido,
            VV.COD_PRODUTO::TEXT                                    AS cod_produto,
            TO_CHAR(VV.DATAEMISSAO, 'DD/MM/YYYY')                   AS dataemissao,
            TO_CHAR(VV.DATAFATURA,  'DD/MM/YYYY')                   AS datafatura,
            TO_CHAR(VV.DATAEMISSAO, 'YYYY-MM-DD')                   AS dataemissao_iso,
            TO_CHAR(VV.DATAFATURA,  'YYYY-MM-DD')                   AS datafatura_iso,
            COALESCE(CAST(VV.PRODUTO AS VARCHAR(120)), '')           AS produto,
            COALESCE(CAST(VV.GRUPO_CLIENTES AS VARCHAR(64)), '')     AS grupo,
            COALESCE(VV.QTD, 0)                                     AS qtd,
            COALESCE(VV.UNIDADE, '')                                AS unidade,
            COALESCE(CAST(VV.VALOR_UNIT AS FLOAT), 0)               AS valor_unit,
            COALESCE(VV.TOTAL_PRODUTO, 0)                           AS total_produto,
            COALESCE(VV.STATUS_PEDIDO::TEXT, 'Pendente')            AS status_pedido,
            COALESCE(CAST(VV.SEGMENTO AS VARCHAR(64)), '')          AS segmento,
            COALESCE(CAST(VV.OBS AS TEXT), '')                      AS obs,
            COALESCE(VV.NOME_FANTASIA, '')                          AS nome_fantasia,
            COALESCE(VV.CLIENTE, '')                                AS cliente_nome,
            COALESCE(VV.CNPJ_CPF, '')                               AS cnpj_cpf,
            COALESCE(CAST(VV.VENDEDOR_VENDA AS VARCHAR(64)), '')    AS vendedor_venda,
            COALESCE(CAST(VV.UF AS CHAR(2)), '')                    AS uf,
            COALESCE(CAST(VV.CIDADE AS VARCHAR(37)), '')            AS cidade,
            COALESCE(VV.TOTAL_PEDIDO, 0)                            AS total_pedido_item
        FROM VW_VENDAS VV
        WHERE VV.PEDIDO::TEXT = \$1
          AND VV.COD_GRUPO IN (6, 24, 5) 
          AND VV.OPERACAO_PEDIDO = 'V'
          AND VV.COD_ESTADO IN ('9')
          AND VV.EMP_CODIGO = 1
        ORDER BY VV.PRODUTO
    ";

    $res = @pg_query_params($pg, $sql, [$pedido]);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar os itens do pedido: ' . pg_last_error($pg));
    }

    $items = [];
    while ($row = pg_fetch_assoc($res)) {
        $clf = classificarProduto($row['produto']);
        $row['categoria'] = $clf['categoria'];
        $items[] = $row;
    }
    pg_free_result($res);
    return $items;
}

// ── AJAX: atualizar prazo de produção de um pedido importado ─────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'set_prazo') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $venCod = (int) ($_GET['ven_codigo'] ?? 0);
        $prazo  = trim((string) ($_GET['prazo'] ?? ''));
        if (!$venCod) {
            throw new RuntimeException('Pedido não informado.');
        }
        $prazoVal = $prazo === '' ? null : max(1, (int) $prazo);
        $st = $pdo->prepare('UPDATE vendas SET ven_prazo_dias = ? WHERE ven_codigo = ?');
        $st->execute([$prazoVal, $venCod]);
        echo json_encode(['ok' => true, 'prazo' => $prazoVal]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: atualizar status de um pedido importado ────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'set_status') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pedido = trim((string) ($_GET['pedido'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        if ($pedido === '') {
            throw new RuntimeException('Pedido não informado.');
        }
        $allowed = ['Urgente', 'Normal', 'Em produção', 'Concluído', ''];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Status inválido.');
        }
        $st = $pdo->prepare('UPDATE vendas SET ven_status = ? WHERE ven_codigo_yzidro = ?');
        $st->execute([$status === '' ? null : $status, $pedido]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: itens de um pedido (exibição) ───────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'itens') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pedido = trim((string) ($_GET['pedido'] ?? ''));
        if ($pedido === '') {
            throw new RuntimeException('Pedido não informado.');
        }
        $pg = dbPG();
        if (!$pg) {
            throw new RuntimeException('Sem conexão com o ERP.');
        }
        $itens = yzFetchItensPedido($pg, $pedido);
        pg_close($pg);
        echo json_encode([
            'ok' => true,
            'itens' => $itens,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: importar pedidos selecionados ───────────────────────────────────────
$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    set_time_limit(0);
    ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');
    $pg = null;
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $pedidos = array_values(array_filter(
            array_map('trim', $body['pedidos'] ?? []),
            fn ($p) => $p !== ''
        ));

        if (empty($pedidos)) {
            echo json_encode(['ok' => false, 'msg' => 'Nenhum pedido selecionado.']);
            exit;
        }

        $pg = dbPG();
        if (!$pg) {
            echo json_encode(['ok' => false, 'msg' => 'Sem conexão com o ERP.']);
            exit;
        }

        $stmtVen = $pdo->prepare("
            INSERT INTO vendas (ven_codigo_yzidro,ven_cliente,ven_fantasia,ven_cnpj,
                ven_representante,ven_uf,ven_cidade,ven_emissao,ven_entrega,
                ven_total,ven_segmento,ven_grupo_clientes,ven_obs,ven_status)
            VALUES (:yz,:cli,:fan,:cnpj,:rep,:uf,:cid,:emi,:ent,:tot,:seg,:grp,:obs,:sts)
            ON DUPLICATE KEY UPDATE
                ven_cliente=VALUES(ven_cliente), ven_entrega=VALUES(ven_entrega),
                ven_total=VALUES(ven_total),
                ven_obs=VALUES(ven_obs),
                ven_status=IF(VALUES(ven_status)='Urgente' AND ven_status IS NULL,'Urgente',ven_status)
        ");
        $stmtPro = $pdo->prepare('
            INSERT INTO produtos (pro_codigo_yz,pro_descricao,pro_tipo,pro_travado,pro_impressao,
                pro_valvulado,pro_comprimento,pro_maq_impressao,pro_fluxo)
            VALUES (:yz,:desc,:tipo,:trav,:imp,:valv,:comp,:maq,:flx)
            ON DUPLICATE KEY UPDATE
                pro_descricao=VALUES(pro_descricao),
                pro_tipo=VALUES(pro_tipo),
                pro_travado=VALUES(pro_travado),
                pro_impressao=VALUES(pro_impressao),
                pro_valvulado=VALUES(pro_valvulado),
                pro_comprimento=VALUES(pro_comprimento),
                pro_maq_impressao=VALUES(pro_maq_impressao),
                pro_fluxo=VALUES(pro_fluxo)
        ');
        $stmtIv = $pdo->prepare('
            INSERT INTO itens_vendas (ven_codigo,pro_codigo,iv_qtde,iv_vlr_unit,iv_total,iv_unidade)
            VALUES (:ven,:pro,:qtd,:vlu,:tot,:uni)
            ON DUPLICATE KEY UPDATE iv_qtde=VALUES(iv_qtde), iv_total=VALUES(iv_total)
        ');
        $getFindV = $pdo->prepare('SELECT ven_codigo FROM vendas WHERE ven_codigo_yzidro=:yz');
        $getFindP = $pdo->prepare('SELECT pro_codigo FROM produtos WHERE pro_codigo_yz=:yz');

        // Garante coluna ven_prazo_dias
        try {
            $chkCol = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $chkCol->execute(['vendas', 'ven_prazo_dias']);
            if (!(int) $chkCol->fetchColumn()) {
                $pdo->exec('ALTER TABLE vendas ADD COLUMN ven_prazo_dias INT NULL AFTER ven_status');
            }
        } catch (Throwable) {}

        $pdo->beginTransaction();
        $novos = $atualizados = 0;

        foreach ($pedidos as $numPed) {
            $itens = yzFetchItensPedido($pg, (string) $numPed);
            if (empty($itens)) {
                continue;
            }

            $primeiro = $itens[0];
            $obsTexto = implode(' ', array_filter(array_column($itens, 'obs')));
            $isUrgente = stripos($obsTexto, 'urgente') !== false;

            $entrega = null;
            if (!empty($primeiro['datafatura_iso'])) {
                $dt = DateTime::createFromFormat('Y-m-d', $primeiro['datafatura_iso']);
                if ($dt) {
                    $entrega = $dt->format('Y-m-d');
                }
            }
            $emissao = null;
            if (!empty($primeiro['dataemissao_iso'])) {
                $dt = DateTime::createFromFormat('Y-m-d', $primeiro['dataemissao_iso']);
                if ($dt) {
                    $emissao = $dt->format('Y-m-d');
                }
            }

            $getFindV->execute([':yz' => $numPed]);
            $existeVen = $getFindV->fetchColumn();

            $stmtVen->execute([
                ':yz' => $numPed,
                ':cli' => $primeiro['cliente_nome'],
                ':fan' => $primeiro['nome_fantasia'],
                ':cnpj' => $primeiro['cnpj_cpf'],
                ':rep' => $primeiro['vendedor_venda'],
                ':uf' => $primeiro['uf'],
                ':cid' => $primeiro['cidade'],
                ':emi' => $emissao,
                ':ent' => $entrega,
                ':tot' => (float) ($primeiro['total_pedido_item'] ?? 0),
                ':seg' => $primeiro['segmento'],
                ':grp' => $primeiro['grupo'],
                ':obs' => $obsTexto ?: null,
                ':sts' => $isUrgente ? 'Urgente' : null,
            ]);

            $getFindV->execute([':yz' => $numPed]);
            $venCod = (int) $getFindV->fetchColumn();

            $itensPcp = [];
            foreach ($itens as $item) {
                $codProd = $item['cod_produto'];
                $descProd = $item['produto'];
                $clf = classificarProduto($descProd);

                $stmtPro->execute([
                    ':yz' => $codProd,
                    ':desc' => $descProd,
                    ':tipo' => $clf['tipo'],
                    ':trav' => $clf['travado'],
                    ':imp' => $clf['impressao'],
                    ':valv' => $clf['valvulado'],
                    ':comp' => $clf['comprimento'],
                    ':maq' => $clf['maqImpressao'],
                    ':flx' => $clf['fluxo'],
                ]);
                $getFindP->execute([':yz' => $codProd]);
                $proCod = (int) $getFindP->fetchColumn();

                $stmtIv->execute([
                    ':ven' => $venCod,
                    ':pro' => $proCod,
                    ':qtd' => (float) ($item['qtd'] ?? 0),
                    ':vlu' => (float) ($item['valor_unit'] ?? 0),
                    ':tot' => (float) ($item['total_produto'] ?? 0),
                    ':uni' => $item['unidade'],
                ]);

                // Prepara dados para cálculo do prazo
                $itensPcp[] = [
                    'tipo'          => $clf['tipo'],
                    'impressao'     => $clf['impressao'],
                    'valvulado'     => $clf['valvulado'],
                    'maq_impressao' => $clf['maqImpressao'],
                    'qtd'           => (float) ($item['qtd'] ?? 0),
                ];
            }

            // Calcula e salva o prazo estimado de produção (apenas para pedidos novos)
            if (!$existeVen) {
                try {
                    $prazoDias = pcpEstimaPrazoPedido($itensPcp);
                    if ($prazoDias !== null) {
                        $stPrazo = $pdo->prepare('UPDATE vendas SET ven_prazo_dias = ? WHERE ven_codigo = ?');
                        $stPrazo->execute([$prazoDias, $venCod]);
                    }
                } catch (Throwable) {}
            }

            if (!$existeVen) {
                $novos++;
            } else {
                $atualizados++;
            }
        }

        $pdo->commit();
        pg_close($pg);
        echo json_encode([
            'ok' => true,
            'msg' => "{$novos} pedido(s) novo(s) importado(s). {$atualizados} atualizado(s).",
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pg) {
            pg_close($pg);
        }
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
// Se nenhum filtro de data for fornecido, aplica emissão dos últimos 90 dias por padrão
$defaultFatIni = isset($_GET['emissao_ini']) || isset($_GET['emissao_fim'])
                  || isset($_GET['fatura_ini']) || isset($_GET['fatura_fim'])
                  || (trim((string) ($_GET['pedido'] ?? '')) !== '')
                  || (trim((string) ($_GET['cliente'] ?? '')) !== '')
                  ? ''
                  : date('Y-m-d', strtotime('-90 days'));

$filters = [
    'emissao_ini' => yzSanitizeDate($_GET['emissao_ini'] ?? ''),
    'emissao_fim' => yzSanitizeDate($_GET['emissao_fim'] ?? ''),
    'fatura_ini' => yzSanitizeDate($_GET['fatura_ini'] ?? $defaultFatIni),
    'fatura_fim' => yzSanitizeDate($_GET['fatura_fim'] ?? ''),
    'pedido' => trim((string) ($_GET['pedido'] ?? '')),
    'cliente' => trim((string) ($_GET['cliente'] ?? '')),
    'representante' => trim((string) ($_GET['representante'] ?? '')),
];
$show_amostras = isset($_GET['amostras']);
$usingDefaultFilter = ($defaultFatIni !== '');

// ── Conexão ERP ───────────────────────────────────────────────────────────────
$pg = dbPG();
$erp_ok = (bool) $pg;
$erp_msg = '';
$vendas = [];

if ($pg) {
    try {
        $vendas = yzFetchVendas($pg, $filters);
    } catch (Throwable $e) {
        $erp_msg = $e->getMessage();
        $erp_ok = false;
    }
    pg_close($pg);
} else {
    $erp_msg = 'Sem conexão com o ERP Yzidro (PostgreSQL).';
}

// ── Quais pedidos já estão no MySQL ──────────────────────────────────────────
$yz_ids_mysql = [];
if (!empty($vendas)) {
    $nums = array_values(array_unique(array_column($vendas, 'pedido')));
    $ph = implode(',', array_fill(0, count($nums), '?'));
    $st = $pdo->prepare("SELECT ven_codigo_yzidro, ven_status FROM vendas WHERE ven_codigo_yzidro IN ($ph)");
    $st->execute($nums);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $mv) {
        $yz_ids_mysql[$mv['ven_codigo_yzidro']] = $mv['ven_status'];
    }
}

foreach ($vendas as &$v) {
    $v['no_mysql'] = array_key_exists($v['pedido'], $yz_ids_mysql);
    $v['ven_status'] = $yz_ids_mysql[$v['pedido']] ?? null;
}
unset($v);

if (!$show_amostras) {
    $vendas = array_values(array_filter($vendas, fn ($r) => (float) ($r['min_qtd'] ?? 0) >= 20));
}

$totalVendas = count($vendas);
$totalFaturado = array_reduce($vendas, fn ($c, $r) => $c + (float) ($r['total_pedido'] ?? 0), 0.0);
$totalNovos = count(array_filter($vendas, fn ($r) => !$r['no_mysql']));
$ticketMedio = $totalVendas > 0 ? ($totalFaturado / $totalVendas) : 0.0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pedidos ERP Yzidro | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
  /* KPIs */
  .kpi-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
  }

  /* Filtros */
  .filter-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    margin-bottom: 16px;
  }
  .filter-grid {
    display: grid;
    grid-template-columns:
      150px 150px 150px 150px
      220px
      minmax(260px, 1fr)
      minmax(240px, 1fr);
    gap: 16px;
    align-items: end;
  }
  .filter-field { display: flex; flex-direction: column; gap: 6px; }
  .filter-field label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
  }
  .filter-field input { height: 36px; padding: 0 10px; font-size: 12.5px; font-family: 'Segoe UI', sans-serif; }
  .filter-actions { margin-top: 16px; display: flex; gap: 12px; }

  /* Tabela */
  .yz-table-wrap { overflow-x: auto; }
  .yz-toggle-cell { width: 52px; text-align: center; }
  .yz-check-cell  { width: 44px; text-align: center; }
  .yz-right       { text-align: right; }
  .yz-nowrap      { white-space: nowrap; }
  .yz-money       { text-align: right; white-space: nowrap; font-weight: 600; color: var(--text-primary); }
  .yz-num         { text-align: right; color: var(--text-muted); font-size: 12px; }
  .yz-cliente-col { min-width: 220px; font-weight: 500; }
  .yz-pedido-col  { font-weight: 700; color: #7db3ff; }

  /* Botão expandir */
  .yz-toggle-btn {
    width: 28px; height: 28px; border-radius: 7px;
    border: 1px solid var(--border);
    background: transparent; color: var(--text-muted);
    cursor: pointer; font-size: 17px; line-height: 1;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
  }
  .yz-toggle-btn:hover { background: var(--card-hover); color: var(--text-primary); }
  .yz-toggle-btn[aria-expanded="true"] {
    background: rgba(36,83,212,.18);
    border-color: rgba(79,123,255,.35);
    color: #7db3ff;
  }

  /* Checkbox de seleção */
  .yz-check {
    width: 16px; height: 16px; cursor: pointer;
    accent-color: var(--blue-accent);
  }

  /* Badges novo / importado */
  .badge-novo {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: rgba(0,201,167,.12); color: var(--teal);
  }
  .badge-imp {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: rgba(255,255,255,.06); color: var(--text-muted);
  }
  .badge-urgente {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: rgba(239,68,68,.15); color: var(--red);
  }
  .status-sel {
    font-size: 11px; padding: 2px 5px; border-radius: 6px; margin-top: 3px;
    border: 1px solid var(--border); background: var(--card-bg);
    color: var(--text-primary); cursor: pointer; display: block;
  }
  .status-sel.is-urgente { border-color: rgba(239,68,68,.5); color: var(--red); }

  /* Linha de detalhe */
  .yz-detail-row { display: none; }
  .yz-detail-row.open { display: table-row; }
  .yz-detail-cell {
    padding: 0 !important;
    background: rgba(0,0,0,.12) !important;
    border-bottom: 1px solid var(--border);
  }
  .yz-detail-box   { padding: 16px 20px 18px; }
  .yz-detail-head  { margin-bottom: 12px; }
  .yz-detail-head h3 { font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0 0 8px; }
  .yz-detail-chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .yz-chip {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 999px; background: var(--card-bg); border: 1px solid var(--border);
    font-size: 11.5px; font-weight: 500; color: var(--text-muted);
  }
  .yz-detail-loading, .yz-empty-state {
    padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;
  }
  .yz-detail-error {
    padding: 12px 16px; border-radius: 8px; font-size: 13px;
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2); color: var(--red);
  }

  /* Subtabela de itens */
  .yz-subtable { width: 100%; border-collapse: collapse; margin-top: 4px; }
  .yz-subtable th {
    padding: 8px 14px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-muted); border-bottom: 1px solid var(--border);
    background: rgba(0,0,0,.08); white-space: nowrap;
  }
  .yz-subtable td {
    padding: 10px 14px; font-size: 12.5px;
    border-bottom: 1px solid var(--border); color: var(--text-primary);
  }
  .yz-subtable tr:last-child td { border-bottom: none; }

  /* Categoria chip */
  .cat-chip { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 600; white-space: nowrap; }
  .cat-bag-si   { background: rgba(45,106,255,.1);  color: #7db3ff; }
  .cat-bag-ci   { background: rgba(167,139,250,.1); color: #a78bfa; }
  .cat-bag-tsi  { background: rgba(56,189,248,.1);  color: #38bdf8; }
  .cat-bag-tci  { background: rgba(251,191,36,.1);  color: #fbbf24; }
  .cat-sac-si   { background: rgba(0,201,167,.1);   color: var(--teal); }
  .cat-sac-ci   { background: rgba(239,68,68,.1);   color: var(--red); }
  .cat-nc       { background: rgba(255,255,255,.06); color: var(--text-muted); }

  .yz-obs   { max-width: 280px; line-height: 1.4; white-space: normal; color: var(--text-muted); font-size: 12px; }
  .yz-muted { color: var(--text-muted); font-size: 12px; }

  /* Tipo Amostra */
  .tipo-badge  { display:inline-flex;align-items:center;padding:2px 8px;border-radius:6px;font-size:10.5px;font-weight:700;white-space:nowrap; }
  .tipo-amostra{ background:rgba(139,92,246,.14); color:#c4b5fd; }
  .tipo-misto  { background:rgba(251,191,36,.10);  color:var(--amber); }
  .filter-amostras{font-size:10.5px;color:var(--text-muted);opacity:.45;text-decoration:none;padding:0 8px;height:36px;display:inline-flex;align-items:center;border-radius:6px;border:1px solid transparent;transition:opacity .15s,border-color .15s;white-space:nowrap;}
  .filter-amostras:hover{opacity:.85;border-color:var(--border);}
  .filter-amostras.ativo{opacity:.7;color:#c4b5fd;border-color:rgba(139,92,246,.25);}

  /* Barra de importação (sticky rodapé) */
  .import-bar {
    position: sticky; bottom: 0;
    background: var(--blue-mid); border-top: 1px solid var(--border);
    padding: 12px 18px; display: flex; align-items: center;
    justify-content: space-between; gap: 12px; flex-wrap: wrap; z-index: 10;
  }
  .import-bar-info { font-size: 12.5px; color: var(--text-muted); }
  .import-bar-info strong { color: var(--text-primary); }

  /* Erro de conexão */
  .alert-error {
    padding: 12px 16px; border-radius: var(--radius);
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2);
    color: var(--red); font-size: 13px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
  }
  .alert-ok {
    padding: 12px 16px; border-radius: var(--radius);
    background: rgba(0,201,167,.08); border: 1px solid rgba(0,201,167,.2);
    color: var(--teal); font-size: 13px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
  }

  @media (max-width: 1280px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } .kpi-grid-4 { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 900px)  { .filter-grid { grid-template-columns: 1fr 1fr; } .kpi-grid-4 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Pedidos ERP Yzidro</h1>
          <p>Comparação com MySQL · clique para ver itens · selecione para importar</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/vendas" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3v18h18"/><path d="M7 13v5"/><path d="M12 9v9"/><path d="M17 5v13"/></svg>
          Pedidos MySQL
        </a>
        <button class="btn-primary" id="btnImportar" onclick="importarSelecionados()" disabled>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Importar Vendas
        </button>
      </div>
    </header>

    <div class="content">

      <div id="alertImport" style="display:none;"></div>

      <?php if ($usingDefaultFilter ?? false): ?>
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:12.5px;color:var(--amber);display:flex;align-items:center;gap:8px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Exibindo pedidos dos últimos 90 dias. Use os filtros para ampliar o período ou
          <a href="<?= yzEscape(yzBaseUrl()) ?>?emissao_ini=2000-01-01" style="color:var(--amber);font-weight:700;text-decoration:underline;">ver todos</a>.
        </div>
      <?php endif; ?>

      <?php if ($erp_msg !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= yzEscape($erp_msg) ?>
        </div>
      <?php endif; ?>

      <!-- KPIs -->
      <div class="kpi-grid-4">
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Pedidos no ERP</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalVendas, 0, ',', '.') ?></div>
          <div class="kpi-footer">no período filtrado</div>
        </div>
        <div class="kpi-card teal">
          <div class="kpi-header">
            <span class="kpi-label">Novos (não importados)</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalNovos, 0, ',', '.') ?></div>
          <div class="kpi-footer">aguardando importação</div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-header">
            <span class="kpi-label">Já importados</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= number_format($totalVendas - $totalNovos, 0, ',', '.') ?></div>
          <div class="kpi-footer">presentes no MySQL</div>
        </div>
        <div class="kpi-card blue">
          <div class="kpi-header">
            <span class="kpi-label">Valor total</span>
            <div class="kpi-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="font-size:20px;letter-spacing:-.5px"><?= yzEscape(yzFmtMoney($totalFaturado)) ?></div>
          <div class="kpi-footer">soma dos pedidos listados</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="filter-card">
        <form method="get" action="<?= yzEscape(yzBaseUrl()) ?>">
          <div class="filter-grid">
            <div class="filter-field">
              <label for="emissao_ini">Emissão inicial</label>
              <input type="date" id="emissao_ini" name="emissao_ini" value="<?= yzEscape($filters['emissao_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="emissao_fim">Emissão final</label>
              <input type="date" id="emissao_fim" name="emissao_fim" value="<?= yzEscape($filters['emissao_fim']) ?>">
            </div>
            <div class="filter-field">
              <label for="fatura_ini">Faturamento inicial</label>
              <input type="date" id="fatura_ini" name="fatura_ini" value="<?= yzEscape($filters['fatura_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="fatura_fim">Faturamento final</label>
              <input type="date" id="fatura_fim" name="fatura_fim" value="<?= yzEscape($filters['fatura_fim']) ?>">
            </div>
            <div class="filter-field">
              <label for="pedido">Pedido</label>
              <input type="text" id="pedido" name="pedido" value="<?= yzEscape($filters['pedido']) ?>" placeholder="Ex.: 2587">
            </div>
            <div class="filter-field">
              <label for="cliente">Cliente</label>
              <input type="text" id="cliente" name="cliente" value="<?= yzEscape($filters['cliente']) ?>" placeholder="Nome do cliente">
            </div>
            <div class="filter-field">
              <label for="representante">Representante</label>
              <input type="text" id="representante" name="representante" value="<?= yzEscape($filters['representante']) ?>" placeholder="Nome do representante">
            </div>
          </div>
          <div class="filter-actions">
            <?php if ($show_amostras): ?>
            <input type="hidden" name="amostras" value="1">
            <?php endif; ?>
            <button type="submit" class="btn-refresh">Filtrar</button>
            <a href="<?= yzEscape(yzBaseUrl()) ?>" class="icon-btn" title="Limpar filtros"
               style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
            <?php
              $baseQs = array_filter([
                  'fatura_ini'    => $filters['fatura_ini']    ?? '',
                  'fatura_fim'    => $filters['fatura_fim']    ?? '',
                  'emissao_ini'   => $filters['emissao_ini']   ?? '',
                  'emissao_fim'   => $filters['emissao_fim']   ?? '',
                  'pedido'        => $filters['pedido']        ?? '',
                  'cliente'       => $filters['cliente']       ?? '',
                  'representante' => $filters['representante'] ?? '',
              ]);
              $urlAmostras    = yzBaseUrl() . '?' . http_build_query(array_merge($baseQs, ['amostras' => '1']));
              $urlSemAmostras = yzBaseUrl() . ($baseQs ? '?' . http_build_query($baseQs) : '');
            ?>
            <?php if ($show_amostras): ?>
            <a href="<?= yzEscape($urlSemAmostras) ?>" class="filter-amostras ativo" title="Ocultar amostras">amostras ✕</a>
            <?php else: ?>
            <a href="<?= yzEscape($urlAmostras) ?>" class="filter-amostras" title="Exibir amostras (< 20 un)">+ amostras</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Tabela de pedidos -->
      <div class="panel" style="padding-bottom: 4px;">
        <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div style="display:flex;align-items:center;gap:12px;">
            <span class="panel-title">Pedidos ERP</span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12.5px;color:var(--text-muted);">
            <input type="checkbox" id="chkTodos" class="yz-check" onchange="selecionarNovos(this.checked)">
            Selecionar todos os novos
          </label>
        </div>
        <div class="yz-table-wrap">
          <table>
            <thead>
              <tr>
                <th class="yz-check-cell"></th>
                <th class="yz-toggle-cell"></th>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Emissão</th>
                <th>Faturamento</th>
                <th>Representante</th>
                <th class="yz-right">Itens</th>
                <th class="yz-right">Total</th>
                <th>Tipo</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$vendas): ?>
                <tr>
                  <td colspan="10">
                    <div class="yz-empty-state">Nenhuma venda encontrada com os filtros informados.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($vendas as $row):
                    $pedido = (string) ($row['pedido'] ?? '');
                    $isNovo = !$row['no_mysql'];
                    $detailId = 'detail_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $pedido);
                    $minQtd = (float) ($row['min_qtd'] ?? 100);
                    $maxQtd = (float) ($row['max_qtd'] ?? 100);
                    // Badge só exibido quando amostras estão visíveis
                    $tipoClass = ''; $tipoLabel = '';
                    if ($show_amostras) {
                        if ($maxQtd < 20) { $tipoClass = 'tipo-amostra'; $tipoLabel = 'Amostra'; }
                        elseif ($minQtd < 20) { $tipoClass = 'tipo-misto'; $tipoLabel = 'Misto'; }
                    }
                    ?>
                  <tr>
                    <td class="yz-check-cell" onclick="event.stopPropagation()">
                      <input type="checkbox"
                        class="yz-check yz-sel"
                        data-pedido="<?= yzEscape($pedido) ?>"
                        data-novo="<?= $isNovo ? '1' : '0' ?>"
                        onchange="atualizarSelecao()">
                    </td>
                    <td class="yz-toggle-cell">
                      <button
                        type="button"
                        class="yz-toggle-btn"
                        data-pedido="<?= yzEscape($pedido) ?>"
                        data-detail-id="<?= yzEscape($detailId) ?>"
                        data-cliente="<?= yzEscape($row['cliente'] ?? '—') ?>"
                        data-rep="<?= yzEscape($row['representante'] ?? '—') ?>"
                        data-total="<?= yzEscape(yzFmtMoney($row['total_pedido'] ?? 0)) ?>"
                        aria-expanded="false"
                        title="Ver itens"
                      >+</button>
                    </td>
                    <td class="yz-pedido-col"><?= yzEscape($pedido) ?></td>
                    <td class="yz-cliente-col"><?= yzEscape($row['cliente'] ?? '—') ?></td>
                    <td class="yz-nowrap"><?= yzEscape(yzFmtDate($row['dataemissao'] ?? '')) ?></td>
                    <td class="yz-nowrap"><?= yzEscape(yzFmtDate($row['datafatura'] ?? '')) ?></td>
                    <td class="yz-nowrap"><?= yzEscape($row['representante'] ?? '—') ?></td>
                    <td class="yz-num"><?= (int) ($row['qtd_itens'] ?? 0) ?></td>
                    <td class="yz-money"><?= yzEscape(yzFmtMoney($row['total_pedido'] ?? 0)) ?></td>
                    <td><?= $tipoLabel ? '<span class="tipo-badge ' . $tipoClass . '">' . $tipoLabel . '</span>' : '' ?></td>
                    <td>
                      <?php if ($isNovo): ?>
                        <span class="badge-novo">&#9889; Novo</span>
                        <?php if (($row['tem_urgente'] ?? '0') == '1'): ?>
                          <span class="badge-urgente">&#9888; Urgente</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="badge-imp">&#10003; Importado</span>
                        <?php
                              $curStatus = $row['ven_status'] ?? '';
                          $selClass = $curStatus === 'Urgente' ? ' is-urgente' : '';
                          ?>
                        <select class="status-sel<?= $selClass ?>"
                          data-pedido="<?= yzEscape($pedido) ?>"
                          data-prev="<?= yzEscape($curStatus) ?>"
                          onchange="setStatus(this)">
                          <option value="" <?= $curStatus === '' || $curStatus === null ? 'selected' : '' ?>>—</option>
                          <option value="Urgente" <?= $curStatus === 'Urgente' ? 'selected' : '' ?>>⚠ Urgente</option>
                          <option value="Normal" <?= $curStatus === 'Normal' ? 'selected' : '' ?>>Normal</option>
                          <option value="Em produção" <?= $curStatus === 'Em produção' ? 'selected' : '' ?>>Em produção</option>
                          <option value="Concluído" <?= $curStatus === 'Concluído' ? 'selected' : '' ?>>Concluído</option>
                        </select>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <tr id="<?= yzEscape($detailId) ?>" class="yz-detail-row">
                    <td colspan="10" class="yz-detail-cell">
                      <div class="yz-detail-box">
                        <div class="yz-detail-head">
                          <h3>Itens do pedido <?= yzEscape($pedido) ?></h3>
                          <div class="yz-detail-chips">
                            <span class="yz-chip"><?= yzEscape($row['cliente'] ?? '—') ?></span>
                            <span class="yz-chip"><?= yzEscape($row['representante'] ?? '—') ?></span>
                            <span class="yz-chip"><?= yzEscape(yzFmtMoney($row['total_pedido'] ?? 0)) ?></span>
                          </div>
                        </div>
                        <div class="yz-detail-content">
                          <div class="yz-detail-loading">Carregando itens…</div>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Barra de importação (sticky rodapé) -->
        <div class="import-bar" id="importBar" style="display:none;">
          <div class="import-bar-info">
            <strong id="selCount">0</strong> pedido(s) selecionado(s)
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn-secondary" onclick="desmarcarTodos()">Desmarcar</button>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
  const baseUrl      = <?= json_encode(yzBaseUrl(), JSON_UNESCAPED_SLASHES) ?>;
  const currentParams = new URLSearchParams(window.location.search);

  // ── Formatação ──────────────────────────────────────────────────────────────
  function moneyBR(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }
  function qtyBR(v) {
    return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  }
  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  // ── Categoria → CSS class ───────────────────────────────────────────────────
  function catClass(cat) {
    const map = {
      'Bag sem impressão':          'cat-bag-si',
      'Bag com impressão':          'cat-bag-ci',
      'Bag travado sem impressão':  'cat-bag-tsi',
      'Bag travado com impressão':  'cat-bag-tci',
      'Sacaria sem impressão':      'cat-sac-si',
      'Sacaria com impressão':      'cat-sac-ci',
    };
    return map[cat] || 'cat-nc';
  }

  // ── Subtabela de itens ──────────────────────────────────────────────────────
  function buildItemsTable(items) {
    if (!Array.isArray(items) || !items.length) {
      return '<div class="yz-empty-state">Nenhum item encontrado para este pedido.</div>';
    }

    const rows = items.map(item => `
      <tr>
        <td>
          <strong>${escHtml(item.produto || '—')}</strong>
          ${item.segmento ? `<br><span class="yz-muted">${escHtml(item.segmento)}</span>` : ''}
        </td>
        <td><span class="cat-chip ${catClass(item.categoria)}">${escHtml(item.categoria || '—')}</span></td>
        <td class="yz-right">${qtyBR(item.qtd)} ${escHtml(item.unidade || '')}</td>
        <td class="yz-right yz-nowrap">${moneyBR(item.valor_unit)}</td>
        <td class="yz-right yz-nowrap"><strong>${moneyBR(item.total_produto)}</strong></td>
        <td>${escHtml(item.status_pedido || '—')}</td>
        <td class="yz-obs">${escHtml(item.obs || '—')}</td>
      </tr>
    `).join('');

    return `
      <table class="yz-subtable">
        <thead>
          <tr>
            <th>Produto</th>
            <th>Categoria PCP</th>
            <th class="yz-right">Qtd.</th>
            <th class="yz-right">Valor unit.</th>
            <th class="yz-right">Total</th>
            <th>Status</th>
            <th>Obs.</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  // ── Expandir / recolher itens ───────────────────────────────────────────────
  async function toggleOrder(button) {
    const detailId  = button.dataset.detailId;
    const pedido    = button.dataset.pedido;
    const detailRow = document.getElementById(detailId);
    if (!detailRow) return;

    const willOpen = !detailRow.classList.contains('open');

    document.querySelectorAll('.yz-detail-row.open').forEach(row => {
      if (row !== detailRow) row.classList.remove('open');
    });
    document.querySelectorAll('.yz-toggle-btn[aria-expanded="true"]').forEach(btn => {
      if (btn !== button) {
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = '+';
      }
    });

    if (!willOpen) {
      detailRow.classList.remove('open');
      button.setAttribute('aria-expanded', 'false');
      button.textContent = '+';
      return;
    }

    detailRow.classList.add('open');
    button.setAttribute('aria-expanded', 'true');
    button.textContent = '−';

    const container = detailRow.querySelector('.yz-detail-content');
    if (container.dataset.loaded === '1') return;

    container.innerHTML = '<div class="yz-detail-loading">Carregando itens…</div>';

    const qs = new URLSearchParams(currentParams);
    qs.set('ajax', 'itens');
    qs.set('pedido', pedido);

    try {
      const res  = await fetch(`${baseUrl}?${qs.toString()}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.msg || 'Falha ao carregar os itens.');
      container.innerHTML  = buildItemsTable(data.itens);
      container.dataset.loaded = '1';
    } catch (err) {
      container.innerHTML = `<div class="yz-detail-error">${escHtml(err.message)}</div>`;
    }
  }

  // ── Seleção ─────────────────────────────────────────────────────────────────
  function atualizarSelecao() {
    const sels = document.querySelectorAll('.yz-sel:checked');
    const n    = sels.length;
    document.getElementById('selCount').textContent = n;
    document.getElementById('importBar').style.display = n ? 'flex' : 'none';
    const btnImp = document.getElementById('btnImportar');
    if (btnImp) btnImp.disabled = n === 0;
  }

  function selecionarNovos(checked) {
    document.querySelectorAll('.yz-sel').forEach(cb => {
      if (checked && cb.dataset.novo === '1') cb.checked = true;
      if (!checked) cb.checked = false;
    });
    atualizarSelecao();
  }

  function desmarcarTodos() {
    document.querySelectorAll('.yz-sel').forEach(cb => cb.checked = false);
    document.getElementById('chkTodos').checked = false;
    atualizarSelecao();
  }

  // ── Importar selecionados ───────────────────────────────────────────────────
  async function importarSelecionados() {
    const sels = document.querySelectorAll('.yz-sel:checked');
    if (!sels.length) return;

    const pedidos = Array.from(sels).map(cb => cb.dataset.pedido);

    const btn = document.getElementById('btnImportar');
    btn.disabled = true;
    btn.textContent = 'Importando…';

    const alertEl = document.getElementById('alertImport');
    alertEl.style.display = 'none';

    try {
      const res  = await fetch(baseUrl, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body:    JSON.stringify({ pedidos }),
      });
      const data = await res.json();
      alertEl.className   = data.ok ? 'alert alert-ok' : 'alert alert-error';
      alertEl.textContent = data.msg || (data.ok ? 'Importação concluída.' : 'Erro na importação.');
      alertEl.style.display = 'flex';
      alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

      if (data.ok) {
        setTimeout(() => location.reload(), 1800);
      }
    } catch (e) {
      alertEl.className   = 'alert alert-error';
      alertEl.textContent = 'Erro na requisição: ' + e.message;
      alertEl.style.display = 'flex';
    }

    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Importar Vendas';
  }

  // ── Alterar status de pedido importado ─────────────────────────────────────
  async function setStatus(sel) {
    const pedido = sel.dataset.pedido;
    const status = sel.value;
    const qs = new URLSearchParams({ ajax: 'set_status', pedido, status });
    try {
      const res  = await fetch(`${baseUrl}?${qs}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await res.json();
      if (!data.ok) {
        alert('Erro: ' + (data.msg || 'Falha ao atualizar status.'));
        sel.value = sel.dataset.prev || '';
      } else {
        sel.dataset.prev = status;
        sel.className = 'status-sel' + (status === 'Urgente' ? ' is-urgente' : '');
      }
    } catch (e) {
      alert('Erro: ' + e.message);
      sel.value = sel.dataset.prev || '';
    }
  }

  // ── Inicializa ──────────────────────────────────────────────────────────────
  document.querySelectorAll('.yz-toggle-btn').forEach(btn => {
    btn.addEventListener('click', () => toggleOrder(btn));
  });
</script>
</body>
</html>
