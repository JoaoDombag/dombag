<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Tipo de produto (bag x sacaria) nos números de produção
//
//  Apontamentos do ERP (OP_ITENS_ATIVIDADES_APONTADAS): desde 11/11/2026 a
//  fábrica aponta várias etapas por peça (impressão, costuras, corte...).
//  Somar todas as etapas conta a mesma peça mais de uma vez, então peça
//  pronta é só o apontamento da etapa final de cada tipo:
//    Bag: Análise (ATP 7), a última etapa, onde também entram os defeitos.
//    Sacaria: Corte (ATP 14). OP de sacaria sem Corte apontado segue pelo
//    fechamento (OP_ITENS_PRODUZIDOS), como antes dos apontamentos.
//  As outras etapas aparecem nas telas por setor (Tempo Real, Painel de
//  Funcionários, Painel de OPs, BI Individual), cada uma com a sua unidade.
//  PRODUCAO_DIARIA (MySQL) é lançada por máquina e junta os dois tipos:
//  aqui o tipo vem da máquina que produziu.
// ══════════════════════════════════════════════════════════════

const PROD_TIPO_BAG     = 'BAG';
const PROD_TIPO_SACARIA = 'SACARIA';
const PROD_GRP_BAG      = 6; // GRUPOS.GRP_CODIGO no ERP
const PROD_GRP_SACARIA  = 5;
const PROD_ATP_BAG_PRONTO     = 7;  // ATV ANALISAR BIG BAG
const PROD_ATP_SACARIA_PRONTA = 14; // ATV CORTAR SACARIA
const PROD_UNIDADE = [PROD_TIPO_BAG => 'bags', PROD_TIPO_SACARIA => 'sacarias'];

// Ordem das atividades na fábrica, para telas que mostram uma por vez
// (bag: impressão, cortes, costuras e Análise; depois sacaria). Fora da lista vai ao fim.
const PROD_ATP_ORDEM = [4, 1, 2, 3, 5, 6, 18, 8, 11, 12, 9, 10, 7, 13, 14, 15, 16, 17];

function prodAtpOrdem(int $atp): int
{
    $i = array_search($atp, PROD_ATP_ORDEM, true);
    return $i === false ? 100 + $atp : $i;
}

// Filtro SQL do apontamento que conta peça pronta. $a = alias da tabela de apontamentos.
function prodProntoSql(string $a = 'A'): string
{
    return "{$a}.ATP_CODIGO IN (" . PROD_ATP_BAG_PRONTO . ', ' . PROD_ATP_SACARIA_PRONTA . ')';
}

// Tipo (BAG/SACARIA) pelo grupo do produto. $p = alias de PRODUTO; NULL = outro grupo.
function prodTipoGrupoSql(string $p = 'P'): string
{
    return "CASE {$p}.GRP_CODIGO WHEN " . PROD_GRP_BAG . " THEN '" . PROD_TIPO_BAG . "'
                                 WHEN " . PROD_GRP_SACARIA . " THEN '" . PROD_TIPO_SACARIA . "' END";
}

// Nome da etapa para tela: "ATV COSTURA ALÇA" vira "Costura alça".
function prodEtapaNome(?string $descricao): string
{
    $d = trim((string) preg_replace('/^\s*ATV\s+/iu', '', (string) $descricao));
    if ($d === '') {
        return '';
    }
    $d = mb_strtolower($d, 'UTF-8');
    return mb_strtoupper(mb_substr($d, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($d, 1, null, 'UTF-8');
}

// Unidade por tipo, com fallback neutro
function prodUnidade(?string $tipo): string
{
    return PROD_UNIDADE[$tipo] ?? 'unidades';
}

// ── Produção por pessoa (Painel de Funcionários e PDF) ───────────────────────
// Colunas extras para o CTE de apontamentos (alias IAA): grupo do produto e etapa.
function prodApontExtrasSql(string $a = 'IAA'): string
{
    return ",(SELECT PX.GRP_CODIGO FROM PRODUTO PX WHERE PX.PRO_CODIGO = {$a}.PRO_CODIGO) AS GRP_CODIGO
                 ,{$a}.ATP_CODIGO
                 ,(SELECT TRIM(AX.ATP_DESCRICAO) FROM ATIVIDADE_PRODUCAO AX WHERE AX.ATP_CODIGO = {$a}.ATP_CODIGO) AS ETAPA";
}

// Somas por tipo e peças prontas para um GROUP BY sobre o CTE (alias A) e TEMPO (alias T).
function prodSomasPessoaSql(string $a = 'A', string $t = 'T'): string
{
    $q = "{$a}.OIAP_QTD_PRODUZIDA";
    return ",COALESCE(SUM({$q}) FILTER (WHERE {$a}.GRP_CODIGO = " . PROD_GRP_BAG . "), 0)     AS QTD_BAG
              ,COALESCE(SUM({$q}) FILTER (WHERE {$a}.GRP_CODIGO = " . PROD_GRP_SACARIA . "), 0) AS QTD_SAC
              ,COALESCE(SUM({$q}) FILTER (WHERE {$a}.ATP_CODIGO = " . PROD_ATP_BAG_PRONTO . "), 0)     AS QTD_BAG_PRONTO
              ,COALESCE(SUM({$q}) FILTER (WHERE {$a}.ATP_CODIGO = " . PROD_ATP_SACARIA_PRONTA . "), 0) AS QTD_SAC_PRONTA
              ,COALESCE(SUM(EXTRACT(EPOCH FROM {$t}.PRODUTIVO)) FILTER (WHERE {$a}.ATP_CODIGO = " . PROD_ATP_BAG_PRONTO . "), 0) AS SEG_BAG_PRONTO
              ,COUNT(*) FILTER (WHERE {$a}.GRP_CODIGO = " . PROD_GRP_SACARIA . ")                 AS APONT_SAC
              ,COUNT(*) FILTER (WHERE {$a}.GRP_CODIGO = " . PROD_GRP_BAG . ")                     AS APONT_BAG
              ,STRING_AGG(DISTINCT {$a}.ETAPA, '|')                                AS ETAPAS
              ,STRING_AGG(DISTINCT {$a}.ETAPA, '|') FILTER (WHERE {$a}.ATP_CODIGO <> " . PROD_ATP_BAG_PRONTO . ") AS ETAPAS_OUTRAS";
}

// Linha de pessoa (ou dia) com as somas acima. Produzido = só bags analisados
// (Análise); o que a pessoa fez em outras etapas fica à parte, em "outros",
// porque costura, impressão ou corte não são bag pronto.
function prodPessoaUnidade(array $r): array
{
    $bag = (float) ($r['qtd_bag'] ?? 0);
    $sac = (float) ($r['qtd_sac'] ?? 0);
    $analisados = (float) ($r['qtd_bag_pronto'] ?? 0);
    $r['qtd_produzida'] = $analisados;
    $r['seg_produzido'] = (float) ($r['seg_bag_pronto'] ?? 0);   // horas da Análise, para a média por hora
    $r['unidade']       = 'bags analisados';
    $r['outros_bag']    = max(0.0, $bag - $analisados);
    $r['outros_sac']    = $sac;
    $partes = [];
    if ($r['outros_bag'] > 0) {
        $partes[] = number_format($r['outros_bag'], 0, ',', '.') . ' bags';
    }
    if ($sac > 0) {
        $partes[] = number_format($sac, 0, ',', '.') . ' sacarias';
    }
    $r['outros_txt'] = implode(' e ', $partes);
    $nomes = static function (?string $lista): string {
        $e = array_filter(array_map('prodEtapaNome', explode('|', (string) $lista)));
        sort($e);
        return implode(', ', $e);
    };
    $r['etapas']        = $nomes($r['etapas'] ?? '');
    $r['outras_etapas'] = $nomes($r['etapas_outras'] ?? '');
    return $r;
}

// Quem mais analisou primeiro; depois quem só trabalhou em outras etapas
function prodOrdenaPessoas(array $linhas): array
{
    usort($linhas, static fn ($x, $y) => ((float) $y['qtd_produzida'] <=> (float) $x['qtd_produzida'])
        ?: ((float) ($y['outros_bag'] ?? 0) <=> (float) ($x['outros_bag'] ?? 0))
        ?: ((float) ($y['outros_sac'] ?? 0) <=> (float) ($x['outros_sac'] ?? 0)));
    return $linhas;
}

// Totais de peças prontas de um ranking por pessoa
function prodTotaisProntos(array $linhas): array
{
    $bag = array_sum(array_map(static fn ($r) => (float) ($r['qtd_bag_pronto'] ?? 0), $linhas));
    $seg = array_sum(array_map(static fn ($r) => (float) ($r['seg_bag_pronto'] ?? 0), $linhas));
    return [
        'bag_pronto' => $bag,
        'sac_pronta' => array_sum(array_map(static fn ($r) => (float) ($r['qtd_sac_pronta'] ?? 0), $linhas)),
        'bags_hora'  => $seg > 0 ? $bag / ($seg / 3600) : 0.0,  // por hora produtiva na Análise
    ];
}

// Produção pronta por dia, OP e tipo (bag e sacaria). $cond recebe as colunas
// de data e de OP e devolve o filtro SQL. Colunas: dia, op, tipo, qtd (pronta),
// aberto (sacaria lançada no fechamento e ainda não confirmada) e apont
// (apontamentos de qualquer etapa no dia: indica que a produção começou).
function prodProntaSql(callable $cond): string
{
    $grpSac  = PROD_GRP_SACARIA;
    $atpCort = PROD_ATP_SACARIA_PRONTA;
    return "
        SELECT A.OIAP_DATA_HORA_INICIO::date AS dia, A.PROD_CODIGO AS op, " . prodTipoGrupoSql('P') . " AS tipo,
               COALESCE(SUM(A.OIAP_QTD_PRODUZIDA) FILTER (WHERE " . prodProntoSql('A') . "), 0) AS qtd,
               0 AS aberto, COUNT(*) AS apont
          FROM OP_ITENS_ATIVIDADES_APONTADAS A
          JOIN PRODUTO P ON P.PRO_CODIGO = A.PRO_CODIGO
         WHERE NOT A.OIAP_EXCLUIDO
           AND TRIM(A.OIAP_STATUS) <> 'C'
           AND P.GRP_CODIGO IN (" . PROD_GRP_BAG . ", {$grpSac})
           AND " . $cond('A.OIAP_DATA_HORA_INICIO', 'A.PROD_CODIGO') . "
         GROUP BY 1, 2, 3
        UNION ALL
        SELECT I.OIP_DATA_INICIAL::date, I.PROD_CODIGO, '" . PROD_TIPO_SACARIA . "',
               COALESCE(SUM(I.OIP_QTD) FILTER (WHERE TRIM(I.OIP_STATUS) = 'F'), 0),
               COALESCE(SUM(I.OIP_QTD) FILTER (WHERE TRIM(I.OIP_STATUS) = 'E'), 0),
               0
          FROM OP_ITENS_PRODUZIDOS I
          JOIN PRODUTO P ON P.PRO_CODIGO = I.PRO_CODIGO
         WHERE P.GRP_CODIGO = {$grpSac}
           AND " . $cond('I.OIP_DATA_INICIAL', 'I.PROD_CODIGO') . "
           AND NOT EXISTS (SELECT 1 FROM OP_ITENS_ATIVIDADES_APONTADAS C
                            WHERE C.PROD_CODIGO = I.PROD_CODIGO AND C.ATP_CODIGO = {$atpCort}
                              AND NOT C.OIAP_EXCLUIDO AND TRIM(C.OIAP_STATUS) <> 'C')
         GROUP BY 1, 2
    ";
}

// Expressão SQL (MySQL) com o tipo da máquina; NULL = não identificado
function prodTipoMaquinaSql(string $alias = 'm'): string
{
    $d = "{$alias}.MAQ_DESCRICAO";
    return "CASE WHEN {$d} LIKE '%Bag%' THEN '" . PROD_TIPO_BAG . "'
                 WHEN {$d} LIKE '%Sacaria%' OR {$d} LIKE 'Flexo%' OR {$d} LIKE 'Valvuladeira%' THEN '" . PROD_TIPO_SACARIA . "'
            END";
}

// Soma total_und das máquinas por tipo: ['bag' => x, 'sacaria' => y, 'outros' => z]
function prodSomaPorTipo(array $maquinas): array
{
    $t = ['bag' => 0.0, 'sacaria' => 0.0, 'outros' => 0.0];
    foreach ($maquinas as $m) {
        $chave = match ($m['tipo'] ?? null) {
            PROD_TIPO_BAG     => 'bag',
            PROD_TIPO_SACARIA => 'sacaria',
            default           => 'outros',
        };
        $t[$chave] += (float) $m['total_und'];
    }
    return $t;
}
