<?php
declare(strict_types=1);

// Regras do Agendamento na TV que não dependem de banco.
// Rodar com: php tests/pcp/agendamento_tv_test.php

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require_once __DIR__ . '/../../modules/pcp/agendamento_tv_dados.php';

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

function card(array $campos): array
{
    return $campos + [
        'dia' => '2026-11-04', 'op' => 3187, 'tipo' => 'BAG', 'pedido' => '3829', 'cliente' => 'Cliente',
        'plan' => 100.0, 'real' => 0.0, 'aberto' => 0.0, 'status' => 'agendado', 'tarde' => false,
    ];
}

$hoje = '2026-11-04';

// Texto do card: mesmas regras de htmlCard
confere('futuro mostra previsto', agTvCard(card(['dia' => '2026-11-05']), $hoje)['qtd'], '100 bags prev.');
confere('hoje sem produção mostra previsto', agTvCard(card([]), $hoje)['qtd'], '100 bags prev.');
confere('hoje com produção mostra real de previsto', agTvCard(card(['real' => 40.0]), $hoje)['qtd'], '40 bags de 100');
confere('passado sem produção mostra zero real', agTvCard(card(['dia' => '2026-11-03']), $hoje)['qtd'], '0 bags de 100');
confere('sacaria usa a unidade certa e milhar', agTvCard(card(['tipo' => 'SACARIA', 'plan' => 16000.0]), $hoje)['qtd'], '16.000 sacarias prev.');
confere('barra limitada a 100%', agTvCard(card(['real' => 300.0]), $hoje)['pct'], 100);
confere('sem pedido usa a OP', agTvCard(card(['pedido' => '']), $hoje)['pedido'], 'OP 3187');
confere('avisos', agTvCard(card(['aberto' => 5.0, 'tarde' => true]), $hoje)['avisos'], ['5 em aberto no ERP', 'Depois da entrega']);

// Produção sem agendamento
$livre = ['plan' => 0.0, 'status' => 'produzido'];
confere('com leitura mostra a quantidade', agTvCard(card($livre + ['real' => 50.0]), $hoje)['qtd'], '50 bags');
confere('parada hoje fica cheia', agTvCard(card($livre + ['real' => 50.0]), $hoje)['pct'], 100);
confere('ativa não fica cheia', agTvCard(card($livre + ['real' => 50.0, 'agora' => true]), $hoje)['pct'], 0);
confere('só fechamento mostra apontamento finalizado', agTvCard(card($livre + ['tipo' => 'SACARIA', 'aberto' => 5000.0]), $hoje)['qtd'], 'Apontamento finalizado');
confere('só fechamento sem aviso', agTvCard(card($livre + ['tipo' => 'SACARIA', 'aberto' => 5000.0, 'op_feito' => 0.0, 'op_total' => 5000.0]), $hoje)['avisos'], []);
confere('só fechamento com total da OP', agTvCard(card($livre + ['tipo' => 'SACARIA', 'aberto' => 5000.0, 'op_feito' => 0.0, 'op_total' => 5000.0]), $hoje)['qtd'], 'Apontamento finalizado');
confere('produzindo agora com fechamento em aberto', agTvCard(card($livre + ['tipo' => 'SACARIA', 'aberto' => 5000.0, 'agora' => true]), $hoje)['qtd'], '5.000 sacarias em aberto');
confere('ativa sem leitura mostra o tipo', agTvCard(card($livre + ['agora' => true]), $hoje)['qtd'], 'Big Bag');
confere('card comum não está ativo', agTvCard(card([]), $hoje)['agora'], false);

// OPs com apontamento aberto
$info = [3187 => ['pedido' => '3829', 'cliente' => 'A'], 4000 => ['pedido' => '4001', 'cliente' => 'B']];
$ativa = static fn (int $op, string $tipo = 'BAG') => ['op' => $op, 'tipo' => $tipo];
$comAtivas = agTvComAtivas([card([]), card(['dia' => '2026-11-03', 'plan' => 10.0])], [$ativa(3187), $ativa(4000), $ativa(9999)], $info, $hoje);
confere('card de hoje marcado', $comAtivas[0]['agora'] ?? false, true);
confere('dia passado não marcado', isset($comAtivas[1]['agora']), false);
confere('ativa sem card ganha um hoje', [$comAtivas[2]['op'], $comAtivas[2]['dia'], $comAtivas[2]['agora']], [4000, $hoje, true]);
confere('OP de outra empresa fica fora', count($comAtivas), 3);
confere('bag ativo não marca a sacaria', agTvComAtivas([card(['tipo' => 'SACARIA'])], [$ativa(3187)], $info, $hoje)[0]['agora'] ?? false, false);
confere('sacaria ativa é marcada', agTvComAtivas([card(['tipo' => 'SACARIA'])], [$ativa(3187, 'SACARIA')], $info, $hoje)[0]['agora'] ?? false, true);
$sacAtiva = agTvComAtivas([], [$ativa(4000, 'SACARIA')], $info, $hoje);
confere('sacaria ativa sem card ganha um', [$sacAtiva[0]['tipo'], agTvCard($sacAtiva[0], $hoje)['qtd']], ['SACARIA', 'Sacaria']);

// Andamento da OP nos cards produzindo agora
$infoAnd = [3187 => ['tipos' => ['BAG' => ['qtd' => 1000.0], 'SACARIA' => ['qtd' => 0.0]]]];
$prodAnd = [['op' => 3187, 'tipo' => 'BAG', 'qtd' => 200.0], ['op' => 3187, 'tipo' => 'BAG', 'qtd' => 67.0],
            ['op' => 3187, 'tipo' => 'SACARIA', 'qtd' => 50.0]];
$and = agTvComAndamento([card(['op' => 3187, 'agora' => true, 'plan' => 100.0, 'real' => 20.0]),
                         card(['op' => 3187]),
                         card(['op' => 3187, 'tipo' => 'SACARIA', 'agora' => true, 'plan' => 0.0])], $prodAnd, $infoAnd, $hoje);
confere('produzindo mostra produzido / a produzir', agTvCard($and[0], $hoje)['qtd'], '267 / 1.000 bags');
confere('barra segue o andamento da OP', round(agTvCard($and[0], $hoje)['pct'], 1), 26.7);
confere('parado não muda', agTvCard($and[1], $hoje)['qtd'], '100 bags prev.');
confere('sem quantidade na OP não muda', agTvCard($and[2], $hoje)['qtd'], 'Sacaria');
$parada = agTvComAndamento([card(['op' => 3187, 'plan' => 0.0, 'real' => 2.0, 'status' => 'produzido']),
                            card(['op' => 3187, 'plan' => 0.0, 'aberto' => 500.0, 'status' => 'produzido'])], $prodAnd, $infoAnd, $hoje);
confere('sem agenda e parada também mostra o total', agTvCard($parada[0], $hoje)['qtd'], '267 / 1.000 bags');
confere('sem agenda e parada não fica cheia', round(agTvCard($parada[0], $hoje)['pct'], 1), 26.7);
confere('em aberto vira aviso', [agTvCard($parada[1], $hoje)['qtd'], agTvCard($parada[1], $hoje)['avisos']], ['267 / 1.000 bags', ['500 em aberto no ERP']]);
confere('outro dia sem agenda não muda', isset(agTvComAndamento([card(['op' => 3187, 'dia' => '2026-11-03', 'plan' => 0.0])], $prodAnd, $infoAnd, $hoje)[0]['op_total']), false);
confere('OP sem produção começa do zero', agTvCard(agTvComAndamento([card(['op' => 3187, 'agora' => true])], [], $infoAnd, $hoje)[0], $hoje)['qtd'], '0 / 1.000 bags');

// Grade: novembro/2026 cobre 6 semanas completas
$cards = [
    card(['plan' => 100.0]),
    card(['plan' => 300.0]),
    card(['tipo' => 'SACARIA', 'plan' => 2000.0]),
    card(['plan' => 0.0, 'real' => 50.0, 'status' => 'produzido']),
];
[$ini, $fim] = agGrade('2026-11');
$base = ['mes' => '2026-11', 'hoje' => $hoje, 'ini' => $ini, 'fim' => $fim];
$p = agTvPainel($base + ['cards' => $cards]);

confere('6 semanas', count($p['semanas']), 6);
confere('toda semana tem 7 dias', array_unique(array_map('count', $p['semanas'])), [7]);
confere('título do mês', $p['mes_titulo'], 'Novembro de 2026');

$diaHoje = $p['semanas'][1][2];
confere('hoje está na quarta da 2a semana', [$diaHoje['num'], $diaHoje['hoje']], [4, true]);
confere('cards de hoje saem da grade', [$diaHoje['cards'], $diaHoje['no_painel']], [[], 4]);
confere('produção sem agendamento aparece no painel de hoje', count($p['lateral']['cards']), 4);
confere('bag antes de sacaria, maior primeiro', array_column($p['lateral']['cards'], 'qtd'), ['300 bags prev.', '100 bags prev.', '50 bags', '2.000 sacarias prev.']);
confere('somas do painel com unidade', [$p['lateral']['soma_bag'], $p['lateral']['soma_sac']], ['400 bags agendados', '2.000 sacarias agendadas']);
confere('título do painel', $p['lateral']['titulo'], 'Hoje · 04/11');
$comAgora = agTvPainel($base + ['cards' => [card(['plan' => 300.0]), card(['op' => 1, 'plan' => 100.0, 'agora' => true])]]);
confere('produzindo agora vem primeiro', array_column($comAgora['lateral']['cards'], 'agora'), [true, false]);
confere('outros dias continuam na grade', count(agTvPainel($base + ['cards' => [card(['dia' => '2026-11-05'])]])['semanas'][1][3]['cards']), 1);
$outroMes = agTvPainel(['mes' => '2026-12', 'hoje' => $hoje] + array_combine(['ini', 'fim'], agGrade('2026-12')) + ['cards' => []]);
confere('sem hoje na grade não há painel', $outroMes['lateral'], null);
confere('soma do dia usa o agendado (bag)', $diaHoje['soma_bag'], '400');
confere('soma do dia usa o agendado (sacaria)', $diaHoje['soma_sac'], '2.000');
confere('dia de outro mês', $p['semanas'][0][0]['outro_mes'], true);
confere('sábado é fim de semana', $p['semanas'][0][5]['fds'], true);
confere('tem agenda', $p['tem_agenda'], true);

$soProducao = agTvPainel($base + ['cards' => [card(['plan' => 0.0, 'real' => 9.0])]]);
confere('só produção não conta como agendado', $soProducao['tem_agenda'], false);
$ontem = agTvPainel($base + ['cards' => [card(['dia' => '2026-11-03', 'plan' => 0.0, 'real' => 9.0])]]);
confere('produção sem agendamento de outro dia fica fora', $ontem['semanas'][1][1]['cards'], []);

// Início da produção
$comMarco = agTvPainel($base + ['cards' => [], 'marcos' => ['2026-11-04' => [
    ['marco' => 'inicio', 'pedido' => '3829', 'cliente' => 'Cliente', 'op' => 3187],
    ['marco' => 'inicio', 'pedido' => '', 'cliente' => 'Cliente', 'op' => 3187],
]]]);
confere('marcos no dia', array_column($comMarco['semanas'][1][2]['marcos'], 'texto'), ['3829 Cliente', 'OP 3187 Cliente']);
confere('sem ERP não há situação', $comMarco['semanas'][1][2]['marcos'][0]['detalhe'], '');
confere('bag iniciada', agMarcoDetalhe(PROD_TIPO_BAG, true), 'Big Bag · Iniciada');
confere('sacaria não iniciada', agMarcoDetalhe(PROD_TIPO_SACARIA, false), 'Sacaria · Não iniciada');
confere('marco leva o tipo para a tela', agTvMarco(['marco' => 'inicio', 'pedido' => '1', 'cliente' => 'C', 'op' => 1, 'tipo' => 'SACARIA', 'iniciada' => false, 'detalhe' => 'x']),
    ['marco' => 'inicio', 'texto' => '1 C', 'rotulo' => 'Início', 'tipo' => 'SACARIA', 'iniciada' => false, 'detalhe' => 'x']);
confere('dia sem marco', $comMarco['semanas'][1][3]['marcos'], []);
confere('marco não conta como agendado', $comMarco['tem_agenda'], false);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE PCP_PROGRAMACAO (PRG_CODIGO INT, DATA_INICIO_PRODUCAO TEXT)');
$db->exec('INSERT INTO PCP_PROGRAMACAO (PRG_CODIGO) VALUES (1)');
$datas = static fn () => $db->query('SELECT DATA_INICIO_PRODUCAO FROM PCP_PROGRAMACAO')->fetchColumn();
$recusa = static function (int $prg, string $ini) use ($db): string {
    try {
        agMarcarDatas($db, $prg, $ini);
        return 'aceitou';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
agMarcarDatas($db, 1, '2026-11-02');
confere('grava o início', $datas(), '2026-11-02');
agMarcarDatas($db, 1, '');
confere('campo vazio apaga a data', $datas(), null);
confere('data inválida', $recusa(1, '2026-13-01'), 'Início da produção: data inválida.');
confere('pedido inexistente', $recusa(2, ''), 'Pedido não encontrado na Reunião de Planejamento.');

// agMontar sem ERP: o pedido encerrado some dos dias à frente, mas mantém os passados
$db->exec("DROP TABLE PCP_PROGRAMACAO");
$db->exec("CREATE TABLE PCP_PROGRAMACAO (PRG_CODIGO INT, PROD_CODIGO INT, VENDA_REF TEXT, CLI_NOME TEXT,
    DATA_ENTREGA_ESPERADA TEXT, PRG_FINALIZADO INT, DATA_INICIO_PRODUCAO TEXT)");
$db->exec("CREATE TABLE PCP_AGENDA (AGD_CODIGO INT, PRG_CODIGO INT, PROD_CODIGO INT, AGD_TIPO TEXT,
    AGD_DATA TEXT, AGD_QTD REAL, AGD_USUARIO TEXT, AGD_REALIZADO REAL, AGD_REALIZADO_POR TEXT)");
$mesTeste  = date('Y-m');
$diaAntes  = date('Y-m-d', strtotime('-1 day'));
$diaDepois = date('Y-m-d', strtotime('+1 day'));
$ins = $db->prepare('INSERT INTO PCP_PROGRAMACAO VALUES (?, ?, ?, ?, NULL, ?, ?)');
$ins->execute([1, 3187, '3829', 'Ativo', 0, $diaAntes]);
$ins->execute([2, 3188, '3830', 'Encerrado', 1, $diaAntes]);
$ins->execute([3, 3189, '3831', 'Ativo futuro', 0, $diaDepois]);
$ins->execute([4, 3190, '3832', 'Encerrado futuro', 1, $diaDepois]);
$db->exec("INSERT INTO PCP_AGENDA VALUES (1, 1, 3187, 'BAG', '{$diaDepois}', 10, 'x', NULL, NULL)");
$m = agMontar($db, null, $mesTeste);
confere('início dos dois pedidos', array_column($m['marcos'][$diaAntes], 'pedido'), ['3829', '3830']);
confere('início futuro só do ativo', array_column($m['marcos'][$diaDepois], 'pedido'), ['3831']);
confere('sem ERP o marco fica sem tipo', [$m['marcos'][$diaDepois][0]['tipo'], $m['marcos'][$diaDepois][0]['iniciada']], [null, null]);
confere('card leva o início do pedido', $m['cards'][0]['inicio'], $diaAntes);
confere('card não traz saída', isset($m['cards'][0]['saida']), false);

// Pedido finalizado no ERP
$infoFim = [
    10 => ['cliente' => 'Cliente A', 'tipos' => ['BAG' => ['qtd' => 1.0, 'produto' => 'x']]],
    11 => ['cliente' => 'Cliente B', 'tipos' => ['SACARIA' => ['qtd' => 1.0, 'produto' => 'y']]],
    12 => ['cliente' => 'Cliente B', 'tipos' => ['BAG' => ['qtd' => 1.0, 'produto' => 'z']]],
];
$fins = agTvMarcosFinalizados([
    ['op' => 10, 'pedido' => '100', 'dia' => '2026-11-05'],
    ['op' => 11, 'pedido' => '101', 'dia' => '2026-11-05'],
    ['op' => 12, 'pedido' => '101', 'dia' => '2026-11-05'],
], $infoFim);
confere('um marcador por pedido', array_column($fins['2026-11-05'], 'pedido'), ['100', '101']);
confere('finalizado mostra o tipo', [$fins['2026-11-05'][0]['tipo'], $fins['2026-11-05'][0]['detalhe']], ['BAG', 'Big Bag']);
confere('pedido com os dois tipos', [$fins['2026-11-05'][1]['tipo'], $fins['2026-11-05'][1]['detalhe']], [null, 'Big Bag e Sacaria']);
confere('finalizado não fica pendente', $fins['2026-11-05'][1]['iniciada'], true);
confere('texto do finalizado', agTvMarco($fins['2026-11-05'][0])['texto'], '100 Cliente A');
confere('finalizado sem OP conhecida', agTvMarcosFinalizados([['op' => 99, 'pedido' => '102', 'dia' => '2026-11-05']], [])['2026-11-05'][0]['detalhe'], '');

// Demonstração: precisa se identificar e cobrir os estados do calendário
$demo = agTvDemo(date('Y-m'));
$demoCards = array_merge($demo['lateral']['cards'] ?? [], ...array_column(array_merge(...$demo['semanas']), 'cards'));
confere('demonstração se identifica', $demo['demo'], true);
confere('demonstração tem agenda', $demo['tem_agenda'], true);
confere('demonstração sem cliente ou pedido real', array_filter($demoCards, static fn ($c) => (int) $c['pedido'] < 7000), []);
confere('painel real não é demonstração', isset($p['demo']), false);

// Parâmetro do mês
confere('mês válido', agTvMesFixo('2026-11'), '2026-11');
confere('mês inexistente', agTvMesFixo('2026-13'), null);
confere('mês com SQL', agTvMesFixo("2026-11' OR 1=1"), null);
confere('sem mês', agTvMesFixo(null), null);

// Sem ERP é erro: agMontar seguiria com a produção zerada
try {
    agTvBuscar(new PDO('sqlite::memory:'), null, '2026-11');
    confere('sem ERP gera erro', 'nenhum', 'AgTvErro');
} catch (AgTvErro $e) {
    confere('sem ERP gera erro, não calendário vazio', $e->codigo, 'erp_indisponivel');
}

echo $falhas === 0 ? "\nTodos os testes passaram.\n" : "\n{$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
