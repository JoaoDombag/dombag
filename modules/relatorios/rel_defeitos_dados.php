<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Dados do Relatório de Defeitos (ERP Yzidro)
//  Fonte: OP_ITENS_ATIVIDADES_DEFEITOS + DEFEITOS_FABRICACAO +
//         OP_ITENS_ATIVIDADES_APONTADAS (PostgreSQL). Somente leitura.
//  Usado pela página e pela versão para impressão/PDF.
//
//  Conta por peça (NSU): a mesma peça pode ter vários apontamentos de
//  defeito, mas entra uma vez só, com uma situação atual:
//    Descartada = tem defeito que não permite correção ("SEM CORREÇÃO")
//    Pendente   = tem defeito ainda sem correção finalizada
//    Corrigida  = todos os defeitos corrigidos
//  A peça entra no período pela data do seu PRIMEIRO defeito, então cada
//  peça pertence a um único período, mesmo que seja corrigida depois.
//  Produzidos = apontado na etapa em que os defeitos são lançados
//  (ATV ANALISAR BIG BAG); somar todas as etapas contaria a peça repetida.
// ══════════════════════════════════════════════════════════════

const RD_ATP_ANALISE = 7;

function rdEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rdSanitizeDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}

function rdFmtDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function rdQty($value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function rdPct(float $parte, float $total): string
{
    return $total > 0 ? number_format($parte / $total * 100, 1, ',', '.') . '%' : '—';
}

function rdBaseUrl(): string
{
    return '/relatorios/defeitos';
}

// Padrão: mês corrente
function rdFiltros(): array
{
    return [
        'data_ini' => rdSanitizeDate($_GET['data_ini'] ?? '') ?: date('Y-m-01'),
        'data_fim' => rdSanitizeDate($_GET['data_fim'] ?? '') ?: date('Y-m-d'),
    ];
}

// ── Apontamentos de defeito e situação atual de cada peça ($1 e $2 = período) ─
function rdBaseCte(): string
{
    return "
        WITH DEF AS (
           SELECT A.PROD_CODIGO, A.PRO_CODIGO
                 ,COALESCE(NULLIF(TRIM(O.OIAD_NSU), ''), 'ID' || O.OIAD_CODIGO) AS PECA
                 ,O.OIAD_QUANTIDADE, O.OIAD_DATA_HORA, O.OIAD_DATA_HORA_CORRECAO_FIM
                 ,O.FU_CODIGO_GEROU, O.FU_CODIGO_APONTOU, O.FU_CODIGO_CORRIGIU
                 ,DF.DF_DESCRICAO, TRIM(DF.DF_PERMITE_CORRECAO) <> 'N' AS CORRIGIVEL
             FROM OP_ITENS_ATIVIDADES_DEFEITOS O
            INNER JOIN DEFEITOS_FABRICACAO DF ON DF.DF_CODIGO = O.DF_CODIGO
            INNER JOIN OP_ITENS_ATIVIDADES_APONTADAS A ON A.OIAP_CODIGO = O.OIAP_CODIGO
            WHERE NOT A.OIAP_EXCLUIDO
        ),
        PECAS AS (
           SELECT PROD_CODIGO, PRO_CODIGO, PECA
                 ,MIN(OIAD_DATA_HORA)                                           AS PRIMEIRO
                 ,MAX(OIAD_QUANTIDADE)                                          AS QTD
                 ,BOOL_OR(NOT CORRIGIVEL)                                       AS DESCARTADA
                 ,BOOL_OR(CORRIGIVEL AND OIAD_DATA_HORA_CORRECAO_FIM IS NULL)   AS PENDENTE
             FROM DEF
            GROUP BY PROD_CODIGO, PRO_CODIGO, PECA
           HAVING MIN(OIAD_DATA_HORA)::date BETWEEN \$1::date AND \$2::date
        )
    ";
}

// ── Totais por OP: produzidos + peças com defeito, por situação ──────────────
function rdFetchPorOp($pg, array $filters): array
{
    $atp = RD_ATP_ANALISE;
    $sql = rdBaseCte() . ",
        PROD AS (
           SELECT PROD_CODIGO, PRO_CODIGO, SUM(OIAP_QTD_PRODUZIDA) AS PRODUZIDOS
             FROM OP_ITENS_ATIVIDADES_APONTADAS
            WHERE NOT OIAP_EXCLUIDO
              AND TRIM(OIAP_STATUS) <> 'C'
              AND ATP_CODIGO = {$atp}
              AND OIAP_DATA_HORA_INICIO::date BETWEEN \$1::date AND \$2::date
            GROUP BY PROD_CODIGO, PRO_CODIGO
        ),
        DEF_OP AS (
           SELECT PROD_CODIGO, PRO_CODIGO
                 ,SUM(QTD)                                                      AS COM_DEFEITO
                 ,SUM(QTD) FILTER (WHERE DESCARTADA)                            AS DESCARTADOS
                 ,SUM(QTD) FILTER (WHERE PENDENTE AND NOT DESCARTADA)           AS PENDENTES
                 ,SUM(QTD) FILTER (WHERE NOT PENDENTE AND NOT DESCARTADA)       AS CORRIGIDOS
             FROM PECAS
            GROUP BY PROD_CODIGO, PRO_CODIGO
        )
        SELECT COALESCE(PD.PROD_CODIGO, D.PROD_CODIGO)                          AS OP
              ,COALESCE(PD.PRO_CODIGO, D.PRO_CODIGO)                            AS PRO
              ,COALESCE(NULLIF(TRIM(C.CLI_NOME_FANTASIA), ''), C.CLI_NOME, '')  AS CLIENTE
              ,P.PRO_DESCRICAO                                                  AS PRODUTO
              ,COALESCE(PD.PRODUZIDOS, 0)                                       AS PRODUZIDOS
              ,COALESCE(D.COM_DEFEITO, 0)                                       AS COM_DEFEITO
              ,COALESCE(D.CORRIGIDOS, 0)                                        AS CORRIGIDOS
              ,COALESCE(D.DESCARTADOS, 0)                                       AS DESCARTADOS
              ,COALESCE(D.PENDENTES, 0)                                         AS PENDENTES
          FROM PROD PD
          FULL JOIN DEF_OP D ON D.PROD_CODIGO = PD.PROD_CODIGO AND D.PRO_CODIGO = PD.PRO_CODIGO
          LEFT JOIN PRODUTO P ON P.PRO_CODIGO = COALESCE(PD.PRO_CODIGO, D.PRO_CODIGO)
          LEFT JOIN PRODUCAO PR ON PR.PROD_CODIGO = COALESCE(PD.PROD_CODIGO, D.PROD_CODIGO)
          LEFT JOIN CLIENTES C ON C.CLI_CODIGO = PR.CLI_CODIGO
         ORDER BY COM_DEFEITO DESC, PRODUZIDOS DESC, OP DESC
    ";
    $res = @pg_query_params($pg, $sql, [$filters['data_ini'], $filters['data_fim']]);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar os defeitos por OP: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Apontamentos por tipo de defeito, das peças do período ───────────────────
function rdFetchPorTipo($pg, array $filters): array
{
    $sql = rdBaseCte() . "
        SELECT D.DF_DESCRICAO AS TIPO, SUM(D.OIAD_QUANTIDADE) AS QTD
          FROM DEF D
         INNER JOIN PECAS P ON P.PROD_CODIGO = D.PROD_CODIGO AND P.PRO_CODIGO = D.PRO_CODIGO AND P.PECA = D.PECA
         GROUP BY D.DF_DESCRICAO
         ORDER BY QTD DESC, TIPO
    ";
    $res = @pg_query_params($pg, $sql, [$filters['data_ini'], $filters['data_fim']]);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar os tipos de defeito: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

// ── Detalhe de uma OP: peças do período, cada uma com seus defeitos ──────────
function rdFetchDetalheOp($pg, array $filters, int $op, int $pro): array
{
    $sql = rdBaseCte() . "
        SELECT D.PECA, P.DESCARTADA, P.PENDENTE
              ,D.DF_DESCRICAO AS TIPO, D.OIAD_QUANTIDADE AS QTD
              ,D.OIAD_DATA_HORA AS DATA_HORA, D.OIAD_DATA_HORA_CORRECAO_FIM AS CORRECAO_FIM
              ,F1.FU_NOME AS GERADO_POR, F2.FU_NOME AS APONTADO_POR, F3.FU_NOME AS CORRIGIDO_POR
          FROM DEF D
         INNER JOIN PECAS P ON P.PROD_CODIGO = D.PROD_CODIGO AND P.PRO_CODIGO = D.PRO_CODIGO AND P.PECA = D.PECA
          LEFT JOIN FUNCIONARIO F1 ON F1.FU_CODIGO = D.FU_CODIGO_GEROU
          LEFT JOIN FUNCIONARIO F2 ON F2.FU_CODIGO = D.FU_CODIGO_APONTOU
          LEFT JOIN FUNCIONARIO F3 ON F3.FU_CODIGO = D.FU_CODIGO_CORRIGIU
         WHERE D.PROD_CODIGO = \$3 AND D.PRO_CODIGO = \$4
         ORDER BY P.PRIMEIRO DESC, D.PECA, D.OIAD_DATA_HORA
    ";
    $res = @pg_query_params($pg, $sql, [$filters['data_ini'], $filters['data_fim'], $op, $pro]);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar o detalhe da OP: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);

    $pecas = [];
    foreach ($rows as $r) {
        $pecas[$r['peca']] ??= [
            // Apontamentos antigos não têm NSU: PECA vira 'ID' + código do apontamento
            'nsu'      => preg_match('/^ID\d+$/', $r['peca']) ? 'Sem NSU' : $r['peca'],
            'situacao' => $r['descartada'] === 't' ? 'Descartada' : ($r['pendente'] === 't' ? 'Pendente' : 'Corrigida'),
            'defeitos' => [],
        ];
        $corrigido = $r['correcao_fim'] !== null;
        $pecas[$r['peca']]['defeitos'][] = [
            'tipo'          => $r['tipo'] . ((int) $r['qtd'] > 1 ? ' (' . (int) $r['qtd'] . ' peças)' : ''),
            'data'          => rdFmtDateTime($r['data_hora']),
            'gerado_por'    => $r['gerado_por'] ?? '—',
            'apontado_por'  => $r['apontado_por'] ?? '—',
            'corrigido_por' => $corrigido ? ($r['corrigido_por'] ?? '—') : '',
            'corrigido_em'  => $corrigido ? rdFmtDateTime($r['correcao_fim']) : '',
        ];
    }
    return array_values($pecas);
}
