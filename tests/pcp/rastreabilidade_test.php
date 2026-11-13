<?php
declare(strict_types=1);

// Regras da ficha da OP na Rastreabilidade que não dependem de banco.
// Rodar com: php tests/pcp/rastreabilidade_test.php

require_once __DIR__ . '/../../modules/pcp/producao_tipo.php';
require_once __DIR__ . '/../../modules/pcp/rastreabilidade_dados.php';

$falhas = 0;
function confere(string $nome, $obtido, $esperado): void
{
    global $falhas;
    if ($obtido === $esperado) {
        echo "  ok  {$nome}\n";
        return;
    }
    $falhas++;
    echo "  FALHOU  {$nome}: obtido " . var_export($obtido, true) . ', esperado ' . var_export($esperado, true) . "\n";
}

function item(string $pro, int $grp, string $pedido): array
{
    return ['pro' => $pro, 'grp_codigo' => (string) $grp, 'qtd_pedido' => $pedido, 'produto' => "Produto {$pro}"];
}

// Bag: produzido vem do apontamento, mesmo com fechamento diferente (entrega parcial)
$r = rtProducaoOp(
    [item('1', 6, '15840')],
    [['pro' => '1', 'qtd' => '12761.000']],
    [['pro' => '1', 'status' => 'F', 'qtd' => '2660.0000']],
    [['pro' => '1', 'com_defeito' => '130', 'corrigidos' => '120', 'pendentes' => '8', 'descartados' => '2']]
);
confere('bag usa o apontado', $r['tipos']['bag']['produzido'], 12761.0);
confere('bag guarda o fechado', $r['tipos']['bag']['fechado'], 2660.0);
confere('bag soma defeitos', $r['tipos']['bag']['com_defeito'], 130.0);
confere('OP só de bag não tem sacaria', isset($r['tipos']['sacaria']), false);

// Sacaria: sem apontamento; só F é produzido, E fica em aberto
$r = rtProducaoOp(
    [item('2', 5, '16000')],
    [],
    [['pro' => '2', 'status' => 'F', 'qtd' => '6000'], ['pro' => '2', 'status' => 'E', 'qtd' => '10000']],
    []
);
confere('sacaria usa o confirmado', $r['tipos']['sacaria']['produzido'], 6000.0);
confere('sacaria separa o em aberto', $r['tipos']['sacaria']['aberto'], 10000.0);

// Sacaria com Corte apontado: vale o apontamento, não o fechamento
$r = rtProducaoOp(
    [item('2', 5, '16000')],
    [['pro' => '2', 'qtd' => '7300']],
    [['pro' => '2', 'status' => 'F', 'qtd' => '16000']],
    []
);
confere('sacaria com Corte usa o apontado', $r['tipos']['sacaria']['produzido'], 7300.0);
confere('sacaria com Corte ainda mostra o fechado', $r['tipos']['sacaria']['fechado'], 16000.0);

// OP com vários produtos: cada linha com o seu número; bag e sacaria não se somam
$r = rtProducaoOp(
    [item('1', 6, '300'), item('3', 6, '300'), item('2', 5, '1000')],
    [['pro' => '1', 'qtd' => '295'], ['pro' => '3', 'qtd' => '300']],
    [['pro' => '2', 'status' => 'F', 'qtd' => '1000']],
    [['pro' => '3', 'com_defeito' => '3', 'corrigidos' => '3', 'pendentes' => null, 'descartados' => null]]
);
confere('linha do primeiro produto', $r['linhas'][0]['produzido'], 295.0);
confere('defeito fica no produto certo', [$r['linhas'][0]['com_defeito'], $r['linhas'][1]['com_defeito']], [0.0, 3.0]);
confere('total de bags', $r['tipos']['bag']['produzido'], 595.0);
confere('total de sacarias separado', $r['tipos']['sacaria']['produzido'], 1000.0);
confere('filtro sem linhas vira zero', $r['tipos']['bag']['pendentes'], 0.0);

// Lote do produto final: por produto, sem repetir e ignorando fechamento sem lote
$lotes = rtLotesFinais([
    ['pro' => '1', 'lt_codigo' => '10', 'lote' => 'A'],
    ['pro' => '1', 'lt_codigo' => '10', 'lote' => 'A'],
    ['pro' => '1', 'lt_codigo' => '11', 'lote' => 'B'],
    ['pro' => '2', 'lt_codigo' => '12', 'lote' => ''],
    ['pro' => '3', 'lt_codigo' => null, 'lote' => null],
]);
confere('junta lotes do mesmo produto', $lotes[1], 'A, B');
confere('sem descrição usa o código', $lotes[2], '12');
confere('fechamento sem lote fica de fora', isset($lotes[3]), false);

echo $falhas ? "\n{$falhas} falha(s)\n" : "\nTudo certo\n";
exit($falhas ? 1 : 0);
