<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Dados do Dashboard (ERP Yzidro, somente leitura)
//  Produção = apontamentos (OP_ITENS_ATIVIDADES_APONTADAS), mesma regra do
//  BI da Produção. Saída = VENDAS finalizadas (DATA_FINALIZA), mesma regra
//  do relatório Histórico de Faturamento (EST 14, modelos de venda reais).
// ══════════════════════════════════════════════════════════════════════

// Filtro de "saída real" — idêntico ao do Histórico de Faturamento
const DASH_SAIDA_WHERE = "V.EMP_CODIGO = 1
       AND V.EST_CODIGO IN (14)
       AND V.VEN_MODELO_VENDA NOT IN (2, 3, 9, 10, 11, 12)";

function dashPgAll($pg, string $sql, array $params = []): array
{
    $res = $params ? @pg_query_params($pg, $sql, $params) : @pg_query($pg, $sql);
    if (!$res) {
        error_log('[DASHBOARD] ' . pg_last_error($pg));
        return [];
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Produção por dia (últimos N dias, só dias com apontamento) ───────────
function dashProducaoPorDia($pg, int $dias = 14): array
{
    $rows = dashPgAll($pg, "
        SELECT OIAP_DATA_HORA_INICIO::date AS dia, COALESCE(SUM(OIAP_QTD_PRODUZIDA), 0) AS qtd
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO
           AND TRIM(OIAP_STATUS) <> 'C'
           AND OIAP_DATA_HORA_INICIO >= CURRENT_DATE - $1::int
         GROUP BY 1
    ", [$dias - 1]);
    return array_column(array_map(fn ($r) => [$r['dia'], (float) $r['qtd']], $rows), 1, 0);
}

function dashProducaoMes($pg): float
{
    $r = dashPgAll($pg, "
        SELECT COALESCE(SUM(OIAP_QTD_PRODUZIDA), 0) AS qtd
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO
           AND TRIM(OIAP_STATUS) <> 'C'
           AND OIAP_DATA_HORA_INICIO >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    return (float) ($r[0]['qtd'] ?? 0);
}

// ── Quem/qual OP está produzindo agora (apontamento em aberto) ───────────
function dashProduzindoAgora($pg): array
{
    $r = dashPgAll($pg, "
        SELECT COUNT(DISTINCT FU_CODIGO) AS funcionarios, COUNT(DISTINCT PROD_CODIGO) AS ops
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO
           AND TRIM(OIAP_STATUS) <> 'C'
           AND OIAP_DATA_HORA_FIM IS NULL
    ");
    return ['funcionarios' => (int) ($r[0]['funcionarios'] ?? 0), 'ops' => (int) ($r[0]['ops'] ?? 0)];
}

// ── OPs em produção agora: lido x total do pedido (mesma base do BI) ─────
function dashOpsAtivas($pg): array
{
    $rows = dashPgAll($pg, "
        WITH OA AS (
            SELECT IAA.PROD_CODIGO, IAA.PRO_CODIGO, MIN(IAA.CLI_CODIGO) AS CLI_CODIGO,
                   COUNT(DISTINCT IAA.FU_CODIGO) AS FUNCS
              FROM OP_ITENS_ATIVIDADES_APONTADAS IAA
             WHERE NOT IAA.OIAP_EXCLUIDO
               AND TRIM(IAA.OIAP_STATUS) <> 'C'
               AND IAA.OIAP_DATA_HORA_FIM IS NULL
             GROUP BY IAA.PROD_CODIGO, IAA.PRO_CODIGO
        )
        SELECT OA.PROD_CODIGO AS op, OA.FUNCS AS funcs,
               COALESCE(P.PRO_DESCRICAO, '') AS produto,
               COALESCE(NULLIF(TRIM(C.CLI_NOME_FANTASIA), ''), C.CLI_NOME, '') AS cliente,
               COALESCE(VOP.QTD_PEDIDO, 0) AS pedido,
               COALESCE((SELECT SUM(I2.OIAP_QTD_PRODUZIDA)
                           FROM OP_ITENS_ATIVIDADES_APONTADAS I2
                          WHERE I2.PROD_CODIGO = OA.PROD_CODIGO AND I2.PRO_CODIGO = OA.PRO_CODIGO
                            AND NOT I2.OIAP_EXCLUIDO AND TRIM(I2.OIAP_STATUS) <> 'C'), 0) AS lido
          FROM OA
          LEFT JOIN PRODUTO           P   ON P.PRO_CODIGO     = OA.PRO_CODIGO
          LEFT JOIN CLIENTES          C   ON C.CLI_CODIGO     = OA.CLI_CODIGO
          LEFT JOIN VW_ORDEM_PRODUCAO VOP ON VOP.COD_PRODUCAO = OA.PROD_CODIGO AND VOP.COD_PRODUTO = OA.PRO_CODIGO
         ORDER BY OA.PROD_CODIGO
    ");
    return array_map(fn ($r) => [
        'op'      => (int) $r['op'],
        'funcs'   => (int) $r['funcs'],
        'produto' => $r['produto'],
        'cliente' => $r['cliente'],
        'pedido'  => (float) $r['pedido'],
        'lido'    => (float) $r['lido'],
    ], $rows);
}

// ── Saídas (pedidos finalizados) desde uma data, um registro por pedido ──
//  Peças vêm dos itens (VW_VENDAS.QTD), separadas em Bag e Sacaria pelo nome
//  do produto; sem itens, usa a quantidade do cabeçalho. Devolução ('D')
//  entra com sinal negativo — mesmas regras do Histórico de Faturamento.
function dashSaidasDesde($pg, string $desde): array
{
    $rows = dashPgAll($pg, "
        WITH S AS (
            SELECT V.VEN_COD_PEDIDO::TEXT AS pedido, V.DATA_FINALIZA::date AS dia, V.CLI_CODIGO,
                   CASE WHEN V.VEN_STATUS = 'D' THEN -1 ELSE 1 END AS sinal,
                   COALESCE(V.VEN_QUANTIDADE, 0) AS qtd_cab, COALESCE(V.VEN_TOTAL, 0) AS valor
              FROM VENDAS V
             WHERE " . DASH_SAIDA_WHERE . "
               AND V.DATA_FINALIZA >= $1::date
        ),
        IT AS (
            SELECT VV.PEDIDO::TEXT AS pedido,
                   SUM(COALESCE(VV.QTD, 0)) AS qtd,
                   SUM(CASE WHEN UPPER(VV.PRODUTO::TEXT) LIKE '%SACARIA%' THEN 0
                            WHEN UPPER(VV.PRODUTO::TEXT) ~ '(BIG ?BAG|\\mBAG\\M)' THEN COALESCE(VV.QTD, 0)
                            ELSE 0 END) AS bag,
                   SUM(CASE WHEN UPPER(VV.PRODUTO::TEXT) LIKE '%SACARIA%' THEN COALESCE(VV.QTD, 0) ELSE 0 END) AS sac
              FROM VW_VENDAS VV
             WHERE VV.EMP_CODIGO = 1 AND VV.OPERACAO_PEDIDO = 'V'
               AND VV.PEDIDO::TEXT IN (SELECT pedido FROM S)
             GROUP BY 1
        )
        SELECT S.pedido, S.dia, S.sinal, S.valor,
               COALESCE(NULLIF(IT.qtd, 0), S.qtd_cab) AS pecas,
               COALESCE(IT.bag, 0) AS bag, COALESCE(IT.sac, 0) AS sac,
               COALESCE(NULLIF(TRIM(C.CLI_NOME_FANTASIA), ''), C.CLI_NOME, '') AS cliente
          FROM S
          LEFT JOIN IT       ON IT.pedido   = S.pedido
          LEFT JOIN CLIENTES C ON C.CLI_CODIGO = S.CLI_CODIGO
         ORDER BY S.dia DESC, S.valor DESC
    ", [$desde]);
    return array_map(fn ($r) => [
        'pedido'  => $r['pedido'],
        'dia'     => $r['dia'],
        'devolucao' => (int) $r['sinal'] === -1,
        'cliente' => $r['cliente'],
        'pecas'   => (int) $r['sinal'] * (float) $r['pecas'],
        'bag'     => (int) $r['sinal'] * (float) $r['bag'],
        'sac'     => (int) $r['sinal'] * (float) $r['sac'],
        'valor'   => (int) $r['sinal'] * (float) $r['valor'],
    ], $rows);
}

// Soma uma lista de saídas: pedidos (sem contar devoluções), bags, sacaria, valor
function dashSomaSaidas(array $saidas): array
{
    $t = ['pedidos' => 0, 'bag' => 0.0, 'sac' => 0.0, 'pecas' => 0.0, 'valor' => 0.0];
    foreach ($saidas as $s) {
        $t['pedidos'] += $s['devolucao'] ? 0 : 1;
        $t['bag']     += $s['bag'];
        $t['sac']     += $s['sac'];
        $t['pecas']   += $s['pecas'];
        $t['valor']   += $s['valor'];
    }
    return $t;
}

// Último pedido que saiu antes de hoje (pra quando ainda não saiu nada hoje)
function dashUltimaSaida($pg): ?string
{
    $r = dashPgAll($pg, "SELECT MAX(V.DATA_FINALIZA)::date AS dia FROM VENDAS V WHERE " . DASH_SAIDA_WHERE . " AND V.DATA_FINALIZA < CURRENT_DATE");
    return $r[0]['dia'] ?? null;
}
