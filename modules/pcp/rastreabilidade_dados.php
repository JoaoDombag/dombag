<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Rastreabilidade: consultas no ERP Yzidro. Somente leitura.
//
//  NSU → OP_ITENS_ATIVIDADES_COMPONENTES: uma linha por leitura da etiqueta,
//        com funcionário, horário e OP. OIAC_NSU_LIDOS guarda as
//        etiquetas lidas em etapas seguintes da mesma peça.
//  Lote → OP_ITENS_CONSUMIDOS liga lote à OP. O ERP NÃO registra qual rolo
//        foi em qual bag: o lote de um bag é "os lotes consumidos pela OP".
//  Nota de compra → primeiro ANDAMENTO_ESTOQUE do lote com CMP_CODIGO.
//  Produzido → bag: apontado na análise (mesma regra do Relatório de Defeitos,
//        que define as funções rd*); sacaria: OP_ITENS_PRODUZIDOS confirmado,
//        porque não tem apontamento. Sacaria também não tem defeito no ERP.
//  Lote do produto final → OP_ITENS_PRODUZIDOS.LT_CODIGO: nasce no fechamento,
//        por OP e produto. O ERP não liga bag a lote final; em entrega parcial
//        as leituras seguem na OP original e os lotes saem na OP-saldo.
// ══════════════════════════════════════════════════════════════

const RT_LIMITE_BUSCA = 50;
const RT_BAGS_PAGINA  = 500;
const RT_CAMPOS = ['nsu' => 'NSU', 'op' => 'O.P', 'pedido' => 'Pedido', 'lote' => 'Lote'];

function rtE($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rtFmtData(?string $value, bool $hora = true): string
{
    $ts = $value ? strtotime($value) : false;
    return $ts ? date($hora ? 'd/m/Y H:i:s' : 'd/m/Y', $ts) : '—';
}

function rtQtd($value): string
{
    $n = (float) $value;
    return number_format($n, fmod($n, 1.0) == 0.0 ? 0 : 2, ',', '.');
}

function rtQuery($pg, string $sql, array $params = []): array
{
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar o ERP: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Busca: consulta só o campo escolhido ─────────────────────────────────────
function rtBuscar($pg, string $termo, string $campo): array
{
    $res = ['nsu' => [], 'op' => [], 'lote' => []];
    $like = '%' . addcslashes($termo, '%_\\') . '%';
    $lim  = RT_LIMITE_BUSCA + 1;

    if ($campo === 'nsu') {
        $res['nsu'] = rtQuery($pg, "
            SELECT TRIM(C.OIAC_NSU) AS NSU, MIN(C.PROD_CODIGO) AS OP, MAX(C.OIAC_DATA_HORA) AS DATA_HORA
                  ,MAX(P.PRO_DESCRICAO) AS PRODUTO
              FROM OP_ITENS_ATIVIDADES_COMPONENTES C
              LEFT JOIN PRODUTO P ON P.PRO_CODIGO = C.PRO_CODIGO
             WHERE NOT C.OIAC_EXCLUIDO AND TRIM(C.OIAC_NSU) <> '' AND C.OIAC_NSU ILIKE \$1
             GROUP BY 1
             ORDER BY (UPPER(TRIM(C.OIAC_NSU)) = UPPER(\$2)) DESC, 3 DESC
             LIMIT {$lim}
        ", [$like, $termo]);
    } elseif ($campo === 'op' || $campo === 'pedido') {
        // Comparação em texto evita estouro de inteiro com número muito grande
        $coluna = $campo === 'op' ? 'PR.PROD_CODIGO::TEXT' : 'TRIM(PR.VENDA_REF)';
        $res['op'] = rtQuery($pg, "
            SELECT PR.PROD_CODIGO AS OP, TRIM(PR.VENDA_REF) AS PEDIDO, PR.PROD_DATA AS DATA
                  ,COALESCE(NULLIF(TRIM(C.CLI_NOME_FANTASIA), ''), C.CLI_NOME, '') AS CLIENTE
              FROM PRODUCAO PR
              LEFT JOIN CLIENTES C ON C.CLI_CODIGO = PR.CLI_CODIGO
             WHERE {$coluna} = \$1
             ORDER BY PR.PROD_CODIGO DESC
             LIMIT {$lim}
        ", [$termo]);
    } elseif ($campo === 'lote') {
        $res['lote'] = rtQuery($pg, "
            SELECT L.LT_CODIGO, TRIM(L.LT_DESCRICAO) AS LOTE, P.PRO_DESCRICAO AS MATERIAL
                  ,(SELECT COUNT(DISTINCT O.PROD_CODIGO) FROM OP_ITENS_CONSUMIDOS O WHERE O.LT_CODIGO = L.LT_CODIGO) AS OPS
              FROM LOTE L
              LEFT JOIN PRODUTO P ON P.PRO_CODIGO = L.PRO_CODIGO
             WHERE L.LT_CODIGO::TEXT = \$2 OR L.LT_DESCRICAO ILIKE \$1 OR L.LT_CODBARRA ILIKE \$1
             ORDER BY (TRIM(L.LT_DESCRICAO) = \$2 OR L.LT_CODIGO::TEXT = \$2) DESC, L.LT_CODIGO DESC
             LIMIT {$lim}
        ", [$like, $termo]);
    }
    return $res;
}

// ── Lotes consumidos por uma OP (com $pro: só os do produto daquele bag) ─────
function rtLotesOp($pg, int $op, ?int $pro = null): array
{
    $params = [$op];
    $filtro = '';
    if ($pro !== null) {
        $params[] = $pro;
        $filtro = 'AND I.PRO_CODIGO_ACABADO = $2';
    }
    return rtQuery($pg, "
        SELECT I.LT_CODIGO, TRIM(L.LT_DESCRICAO) AS LOTE, P.PRO_DESCRICAO AS MATERIAL, P.PRO_UNIDADE AS UNIDADE
              ,L.LT_FABRICACAO AS FABRICACAO, SUM(I.OIC_QTD) AS QTD, MIN(I.OIC_DATA_INICIO) AS DATA
              ,COALESCE(NULLIF(TRIM(F.FOR_NOMEFANTASIA), ''), F.FOR_NOME) AS FORNECEDOR
              ,(SELECT CMP.NUMERO_FORNECEDOR FROM ANDAMENTO_ESTOQUE AE
                 INNER JOIN COMPRA CMP ON CMP.CMP_CODIGO = AE.CMP_CODIGO
                 WHERE AE.LT_CODIGO = I.LT_CODIGO ORDER BY AE.AN_DATA LIMIT 1) AS NOTA
          FROM OP_ITENS_CONSUMIDOS I
          LEFT JOIN LOTE L       ON L.LT_CODIGO = I.LT_CODIGO
          LEFT JOIN PRODUTO P    ON P.PRO_CODIGO = I.PRO_CODIGO
          LEFT JOIN FORNECEDOR F ON F.FOR_CODIGO = L.FOR_CODIGO
         WHERE I.PROD_CODIGO = \$1 {$filtro}
         GROUP BY I.LT_CODIGO, L.LT_DESCRICAO, P.PRO_DESCRICAO, P.PRO_UNIDADE, L.LT_FABRICACAO, F.FOR_NOMEFANTASIA, F.FOR_NOME
         ORDER BY (P.PRO_DESCRICAO ILIKE 'TEC%') DESC, P.PRO_DESCRICAO, 2
    ", $params);
}

// ── Itens da OP: cliente, produto, pedido e datas (uma linha por produto) ────
function rtItensOp($pg, int $op): array
{
    return rtQuery($pg, "
        SELECT V.COD_PRODUCAO AS OP, V.COD_PRODUTO AS PRO, V.PRODUTO, V.CLIENTE, TRIM(V.VENDA_REF) AS PEDIDO
              ,V.LOTE_CLIENTE, V.DATA_EMISSAO, V.DATA_ENTREGA, V.DATA_FINALIZA, V.QTD_PEDIDO
              ,(SELECT P.GRP_CODIGO FROM PRODUTO P WHERE P.PRO_CODIGO = V.COD_PRODUTO) AS GRP_CODIGO
          FROM VW_ORDEM_PRODUCAO V
         WHERE V.COD_PRODUCAO = \$1
         ORDER BY V.PRODUTO
    ", [$op]);
}

// ── Fechamentos da OP: quantidade e lote do produto final ────────────────────
function rtProduzidoOp($pg, int $op): array
{
    return rtQuery($pg, "
        SELECT I.OIP_QTD AS QTD, I.OIP_DATA_INICIAL AS DATA, TRIM(L.LT_DESCRICAO) AS LOTE, I.LT_CODIGO
              ,P.PRO_DESCRICAO AS PRODUTO, I.PRO_CODIGO AS PRO, TRIM(I.OIP_STATUS) AS STATUS
          FROM OP_ITENS_PRODUZIDOS I
          LEFT JOIN LOTE L    ON L.LT_CODIGO = I.LT_CODIGO
          LEFT JOIN PRODUTO P ON P.PRO_CODIGO = I.PRO_CODIGO
         WHERE I.PROD_CODIGO = \$1
         ORDER BY I.OIP_DATA_INICIAL
    ", [$op]);
}

// Lotes do produto final por produto: [pro => 'lote1, lote2']
function rtLotesFinais(array $produzido): array
{
    $lotes = [];
    foreach ($produzido as $p) {
        if ($p['lt_codigo'] !== null) {
            $lotes[$p['pro']][$p['lote'] ?: $p['lt_codigo']] = true;
        }
    }
    return array_map(fn ($l) => implode(', ', array_keys($l)), $lotes);
}

// ── Ficha do bag: leituras, defeitos e lotes ─────────────────────────────────
function rtFichaBag($pg, string $nsu): ?array
{
    $leituras = rtQuery($pg, "
        SELECT C.OIAC_DATA_HORA AS DATA_HORA, C.PROD_CODIGO AS OP, C.PRO_CODIGO AS PRO
              ,F.FU_NOME AS FUNCIONARIO, AT.OIA_ATIVIDADE AS ATIVIDADE
          FROM OP_ITENS_ATIVIDADES_COMPONENTES C
          LEFT JOIN FUNCIONARIO F          ON F.FU_CODIGO = C.FU_CODIGO
          LEFT JOIN OP_ITENS_ATIVIDADES AT ON AT.OIA_CODIGO = C.OIA_CODIGO
         WHERE NOT C.OIAC_EXCLUIDO
           AND (TRIM(C.OIAC_NSU) = \$1 OR \$1 = ANY (C.OIAC_NSU_LIDOS))
         ORDER BY C.OIAC_DATA_HORA
    ", [$nsu]);
    if (!$leituras) {
        return null;
    }
    $ultima = end($leituras);
    $op  = (int) $ultima['op'];
    $pro = (int) $ultima['pro'];

    $defeitos = rtQuery($pg, "
        SELECT DF.DF_DESCRICAO AS TIPO, TRIM(DF.DF_PERMITE_CORRECAO) <> 'N' AS CORRIGIVEL
              ,O.OIAD_DATA_HORA AS DATA_HORA, O.OIAD_DATA_HORA_CORRECAO_FIM AS CORRECAO_FIM
              ,F1.FU_NOME AS GERADO_POR, F2.FU_NOME AS APONTADO_POR, F3.FU_NOME AS CORRIGIDO_POR
          FROM OP_ITENS_ATIVIDADES_DEFEITOS O
         INNER JOIN DEFEITOS_FABRICACAO DF ON DF.DF_CODIGO = O.DF_CODIGO
          LEFT JOIN FUNCIONARIO F1 ON F1.FU_CODIGO = O.FU_CODIGO_GEROU
          LEFT JOIN FUNCIONARIO F2 ON F2.FU_CODIGO = O.FU_CODIGO_APONTOU
          LEFT JOIN FUNCIONARIO F3 ON F3.FU_CODIGO = O.FU_CODIGO_CORRIGIU
         WHERE TRIM(O.OIAD_NSU) = \$1
         ORDER BY O.OIAD_DATA_HORA
    ", [$nsu]);

    $item = null;
    foreach (rtItensOp($pg, $op) as $i) {
        if ((int) $i['pro'] === $pro) {
            $item = $i;
        }
    }
    return [
        'nsu'      => $nsu,
        'leituras' => $leituras,
        'ultima'   => $ultima,
        'item'     => $item,
        'defeitos' => $defeitos,
        'situacao' => rtSituacao($defeitos),
        'lotes'    => rtLotesOp($pg, $op, $pro),
        'lote_final' => rtLotesFinais(rtProduzidoOp($pg, $op))[$pro] ?? null,
    ];
}

// Mesma regra do Relatório de Defeitos: Descartada > Pendente > Corrigida
function rtSituacao(array $defeitos): string
{
    if (!$defeitos) {
        return 'Sem defeito';
    }
    $pendente = false;
    foreach ($defeitos as $d) {
        if ($d['corrigivel'] !== 't') {
            return 'Descartada';
        }
        $pendente = $pendente || $d['correcao_fim'] === null;
    }
    return $pendente ? 'Pendente' : 'Corrigida';
}

// ── Produção e defeitos da OP por produto, com totais por tipo ───────────────
// Não soma bag com sacaria: uma OP com os dois tem um total para cada.
function rtProducaoOp(array $itens, array $apontado, array $produzido, array $defeitos): array
{
    $apontado = array_column($apontado, 'qtd', 'pro');
    $defeitos = array_column($defeitos, null, 'pro');
    $linhas = [];
    $tipos  = [];
    foreach ($itens as $i) {
        $pro     = $i['pro'];
        $sacaria = (int) ($i['grp_codigo'] ?? 0) === PROD_GRP_SACARIA;
        $fechado = $aberto = 0.0;
        foreach ($produzido as $p) {
            if ($p['pro'] === $pro && $p['status'] === 'F') {
                $fechado += (float) $p['qtd'];
            } elseif ($p['pro'] === $pro && $p['status'] === 'E') {
                $aberto += (float) $p['qtd'];
            }
        }
        $d = $defeitos[$pro] ?? [];
        $numeros = [
            'pedido'      => (float) $i['qtd_pedido'],
            'produzido'   => $sacaria ? $fechado : (float) ($apontado[$pro] ?? 0),
            'fechado'     => $fechado,
            'aberto'      => $aberto,
            'com_defeito' => (float) ($d['com_defeito'] ?? 0),
            'corrigidos'  => (float) ($d['corrigidos'] ?? 0),
            'pendentes'   => (float) ($d['pendentes'] ?? 0),
            'descartados' => (float) ($d['descartados'] ?? 0),
        ];
        $linhas[] = ['pro' => $pro, 'produto' => $i['produto'], 'sacaria' => $sacaria] + $numeros;

        $tipo = $sacaria ? 'sacaria' : 'bag';
        foreach ($numeros as $campo => $valor) {
            $tipos[$tipo][$campo] = ($tipos[$tipo][$campo] ?? 0.0) + $valor;
        }
    }
    return ['linhas' => $linhas, 'tipos' => $tipos];
}

// ── Ficha da OP: itens, quem produziu, lotes e bags ──────────────────────────
function rtFichaOp($pg, int $op, int $limite): ?array
{
    $itens = rtItensOp($pg, $op);
    if (!$itens) {
        return null;
    }
    $porPessoa = rtQuery($pg, "
        SELECT F.FU_NOME AS FUNCIONARIO, COUNT(DISTINCT TRIM(C.OIAC_NSU)) AS BAGS
              ,MIN(C.OIAC_DATA_HORA) AS PRIMEIRA, MAX(C.OIAC_DATA_HORA) AS ULTIMA
          FROM OP_ITENS_ATIVIDADES_COMPONENTES C
          LEFT JOIN FUNCIONARIO F ON F.FU_CODIGO = C.FU_CODIGO
         WHERE NOT C.OIAC_EXCLUIDO AND C.PROD_CODIGO = \$1 AND TRIM(C.OIAC_NSU) <> ''
         GROUP BY F.FU_NOME
         ORDER BY BAGS DESC, F.FU_NOME
    ", [$op]);

    $bags = rtQuery($pg, "
        SELECT TRIM(C.OIAC_NSU) AS NSU, C.OIAC_DATA_HORA AS DATA_HORA, F.FU_NOME AS FUNCIONARIO
              ,C.PRO_CODIGO AS PRO, P.PRO_DESCRICAO AS PRODUTO
              ,(SELECT COUNT(*) FROM OP_ITENS_ATIVIDADES_DEFEITOS O WHERE TRIM(O.OIAD_NSU) = TRIM(C.OIAC_NSU)) AS DEFEITOS
          FROM OP_ITENS_ATIVIDADES_COMPONENTES C
          LEFT JOIN FUNCIONARIO F ON F.FU_CODIGO = C.FU_CODIGO
          LEFT JOIN PRODUTO P     ON P.PRO_CODIGO = C.PRO_CODIGO
         WHERE NOT C.OIAC_EXCLUIDO AND C.PROD_CODIGO = \$1 AND TRIM(C.OIAC_NSU) <> ''
         ORDER BY C.OIAC_DATA_HORA DESC
         LIMIT {$limite}
    ", [$op]);

    $produzido = rtProduzidoOp($pg, $op);

    $apontado = rtQuery($pg, "
        SELECT PRO_CODIGO AS PRO, SUM(OIAP_QTD_PRODUZIDA) AS QTD
          FROM OP_ITENS_ATIVIDADES_APONTADAS
         WHERE NOT OIAP_EXCLUIDO AND TRIM(OIAP_STATUS) <> 'C'
           AND ATP_CODIGO = " . RD_ATP_ANALISE . " AND PROD_CODIGO = \$1
         GROUP BY PRO_CODIGO
    ", [$op]);

    // rdBaseCte pede um período ($1 e $2): aqui vale a vida toda da OP
    $defeitos = rtQuery($pg, rdBaseCte() . "
        SELECT PRO_CODIGO AS PRO, SUM(QTD) AS COM_DEFEITO
              ,SUM(QTD) FILTER (WHERE DESCARTADA)                      AS DESCARTADOS
              ,SUM(QTD) FILTER (WHERE PENDENTE AND NOT DESCARTADA)     AS PENDENTES
              ,SUM(QTD) FILTER (WHERE NOT PENDENTE AND NOT DESCARTADA) AS CORRIGIDOS
          FROM PECAS
         WHERE PROD_CODIGO = \$3
         GROUP BY PRO_CODIGO
    ", ['-infinity', 'infinity', $op]);

    $defeitosTipo = rtQuery($pg, rdBaseCte() . "
        SELECT DF_DESCRICAO AS TIPO, SUM(OIAD_QUANTIDADE) AS QTD
          FROM DEF
         WHERE PROD_CODIGO = \$3
         GROUP BY DF_DESCRICAO
         ORDER BY QTD DESC, TIPO
    ", ['-infinity', 'infinity', $op]);

    return [
        'itens'      => $itens,
        'producao'   => rtProducaoOp($itens, $apontado, $produzido, $defeitos),
        'defeitos_tipo' => $defeitosTipo,
        'por_pessoa' => $porPessoa,
        'total_bags' => array_sum(array_map(fn ($r) => (int) $r['bags'], $porPessoa)),
        'bags'       => $bags,
        'produzido'  => $produzido,
        'lotes_finais' => rtLotesFinais($produzido),
        'lotes'      => rtLotesOp($pg, $op),
    ];
}

// ── Ficha do lote: origem, OPs que consumiram/produziram e bags dessas OPs ───
function rtFichaLote($pg, int $lt): ?array
{
    $rows = rtQuery($pg, "
        SELECT L.LT_CODIGO, TRIM(L.LT_DESCRICAO) AS LOTE, L.LT_FABRICACAO AS FABRICACAO, L.LT_VALIDADE AS VALIDADE
              ,P.PRO_DESCRICAO AS MATERIAL, P.PRO_UNIDADE AS UNIDADE
              ,COALESCE(NULLIF(TRIM(F.FOR_NOMEFANTASIA), ''), F.FOR_NOME) AS FORNECEDOR
              ,N.NUMERO_FORNECEDOR AS NOTA, N.CMP_DATA_RECEBIMENTO AS RECEBIMENTO
          FROM LOTE L
          LEFT JOIN PRODUTO P    ON P.PRO_CODIGO = L.PRO_CODIGO
          LEFT JOIN FORNECEDOR F ON F.FOR_CODIGO = L.FOR_CODIGO
          LEFT JOIN LATERAL (
               SELECT CMP.NUMERO_FORNECEDOR, CMP.CMP_DATA_RECEBIMENTO
                 FROM ANDAMENTO_ESTOQUE AE
                INNER JOIN COMPRA CMP ON CMP.CMP_CODIGO = AE.CMP_CODIGO
                WHERE AE.LT_CODIGO = L.LT_CODIGO
                ORDER BY AE.AN_DATA LIMIT 1
          ) N ON TRUE
         WHERE L.LT_CODIGO = \$1
    ", [$lt]);
    if (!$rows) {
        return null;
    }

    $consumo = rtQuery($pg, "
        SELECT I.PROD_CODIGO AS OP, P.PRO_DESCRICAO AS PRODUTO, SUM(I.OIC_QTD) AS QTD, MIN(I.OIC_DATA_INICIO) AS DATA
              ,COALESCE(NULLIF(TRIM(C.CLI_NOME_FANTASIA), ''), C.CLI_NOME, '') AS CLIENTE, TRIM(PR.VENDA_REF) AS PEDIDO
              ,(SELECT COUNT(DISTINCT TRIM(B.OIAC_NSU)) FROM OP_ITENS_ATIVIDADES_COMPONENTES B
                 WHERE B.PROD_CODIGO = I.PROD_CODIGO AND B.PRO_CODIGO = I.PRO_CODIGO_ACABADO
                   AND NOT B.OIAC_EXCLUIDO AND TRIM(B.OIAC_NSU) <> '') AS BAGS
          FROM OP_ITENS_CONSUMIDOS I
          LEFT JOIN PRODUTO P   ON P.PRO_CODIGO = I.PRO_CODIGO_ACABADO
          LEFT JOIN PRODUCAO PR ON PR.PROD_CODIGO = I.PROD_CODIGO
          LEFT JOIN CLIENTES C  ON C.CLI_CODIGO = PR.CLI_CODIGO
         WHERE I.LT_CODIGO = \$1
         GROUP BY I.PROD_CODIGO, I.PRO_CODIGO_ACABADO, P.PRO_DESCRICAO, C.CLI_NOME_FANTASIA, C.CLI_NOME, PR.VENDA_REF
         ORDER BY DATA DESC
    ", [$lt]);

    return ['lote' => $rows[0], 'consumo' => $consumo];
}
