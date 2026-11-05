<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Tipo de produto (bag x sacaria) nos números de produção
//
//  Apontamentos do ERP (OP_ITENS_ATIVIDADES_APONTADAS): só existem para
//  bag, então telas que somam apontamentos mostram "bags".
//  PRODUCAO_DIARIA (MySQL) é lançada por máquina e junta os dois tipos:
//  aqui o tipo vem da máquina que produziu.
// ══════════════════════════════════════════════════════════════

const PROD_TIPO_BAG     = 'BAG';
const PROD_TIPO_SACARIA = 'SACARIA';
const PROD_GRP_SACARIA  = 5; // GRUPOS.GRP_CODIGO no ERP

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
