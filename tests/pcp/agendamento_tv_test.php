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
confere('sacaria usa a unidade certa e milhar', agTvCard(card(['tipo' => 'SACARIA', 'plan' => 16000.0]), $hoje)['qtd'], '16.000 sac. prev.');
confere('barra limitada a 100%', agTvCard(card(['real' => 300.0]), $hoje)['pct'], 100);
confere('sem pedido usa a OP', agTvCard(card(['pedido' => '']), $hoje)['pedido'], 'OP 3187');
confere('avisos', agTvCard(card(['aberto' => 5.0, 'tarde' => true]), $hoje)['avisos'], ['5 em aberto no ERP', 'Depois da entrega']);

// Produção sem agendamento
$livre = ['plan' => 0.0, 'status' => 'produzido'];
confere('com leitura mostra a quantidade', agTvCard(card($livre + ['real' => 50.0]), $hoje)['qtd'], '50 bags s/ agenda');
confere('parada hoje fica cheia', agTvCard(card($livre + ['real' => 50.0]), $hoje)['pct'], 100);
confere('ativa não fica cheia', agTvCard(card($livre + ['real' => 50.0, 'agora' => true]), $hoje)['pct'], 0);
confere('sacaria em aberto', agTvCard(card($livre + ['tipo' => 'SACARIA', 'aberto' => 5000.0]), $hoje)['qtd'], '5.000 sac. em aberto');
confere('em aberto não repete no aviso', agTvCard(card($livre + ['tipo' => 'SACARIA', 'aberto' => 5000.0]), $hoje)['avisos'], []);
confere('ativa sem leitura', agTvCard(card($livre + ['agora' => true]), $hoje)['qtd'], 'Sem agenda');
confere('card comum não está ativo', agTvCard(card([]), $hoje)['agora'], false);

// OPs com apontamento aberto
$info = [3187 => ['pedido' => '3829', 'cliente' => 'A'], 4000 => ['pedido' => '4001', 'cliente' => 'B']];
$comAtivas = agTvComAtivas([card([]), card(['dia' => '2026-11-03', 'plan' => 10.0])], [3187, 4000, 9999], $info, $hoje);
confere('card de hoje marcado', $comAtivas[0]['agora'] ?? false, true);
confere('dia passado não marcado', isset($comAtivas[1]['agora']), false);
confere('ativa sem card ganha um hoje', [$comAtivas[2]['op'], $comAtivas[2]['dia'], $comAtivas[2]['agora']], [4000, $hoje, true]);
confere('OP de outra empresa fica fora', count($comAtivas), 3);
confere('sacaria não é marcada', agTvComAtivas([card(['tipo' => 'SACARIA'])], [3187], $info, $hoje)[0]['agora'] ?? false, false);

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
confere('produção sem agendamento aparece hoje', count($diaHoje['cards']), 4);
confere('bag antes de sacaria, maior primeiro', array_column($diaHoje['cards'], 'qtd'), ['300 bags prev.', '100 bags prev.', '50 bags s/ agenda', '2.000 sac. prev.']);
confere('soma do dia usa o agendado (bag)', $diaHoje['soma_bag'], '400');
confere('soma do dia usa o agendado (sacaria)', $diaHoje['soma_sac'], '2.000');
confere('dia de outro mês', $p['semanas'][0][0]['outro_mes'], true);
confere('sábado é fim de semana', $p['semanas'][0][5]['fds'], true);
confere('tem agenda', $p['tem_agenda'], true);

$soProducao = agTvPainel($base + ['cards' => [card(['plan' => 0.0, 'real' => 9.0])]]);
confere('só produção não conta como agendado', $soProducao['tem_agenda'], false);
$ontem = agTvPainel($base + ['cards' => [card(['dia' => '2026-11-03', 'plan' => 0.0, 'real' => 9.0])]]);
confere('produção sem agendamento de outro dia fica fora', $ontem['semanas'][1][1]['cards'], []);

// Início da produção e saída
$comMarco = agTvPainel($base + ['cards' => [], 'marcos' => ['2026-11-04' => [
    ['marco' => 'inicio', 'pedido' => '3829', 'cliente' => 'Cliente', 'op' => 3187],
    ['marco' => 'saida', 'pedido' => '', 'cliente' => 'Cliente', 'op' => 3187],
]]]);
confere('marcos no dia', array_column($comMarco['semanas'][1][2]['marcos'], 'texto'), ['Início 3829 Cliente', 'Saída OP 3187 Cliente']);
confere('dia sem marco', $comMarco['semanas'][1][3]['marcos'], []);
confere('marco não conta como agendado', $comMarco['tem_agenda'], false);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE PCP_PROGRAMACAO (PRG_CODIGO INT, DATA_INICIO_PRODUCAO TEXT, DATA_SAIDA TEXT)');
$db->exec('INSERT INTO PCP_PROGRAMACAO (PRG_CODIGO) VALUES (1)');
$datas = static fn () => $db->query('SELECT DATA_INICIO_PRODUCAO, DATA_SAIDA FROM PCP_PROGRAMACAO')->fetch(PDO::FETCH_NUM);
$recusa = static function (int $prg, string $ini, string $sai) use ($db): string {
    try {
        agMarcarDatas($db, $prg, $ini, $sai);
        return 'aceitou';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
agMarcarDatas($db, 1, '2026-11-02', '2026-11-20');
confere('grava as duas datas', $datas(), ['2026-11-02', '2026-11-20']);
agMarcarDatas($db, 1, '2026-11-02', '');
confere('campo vazio apaga a data', $datas(), ['2026-11-02', null]);
confere('saída antes do início', $recusa(1, '2026-11-20', '2026-11-02'), 'A saída não pode ser antes do início da produção.');
confere('data inválida', $recusa(1, '2026-13-01', ''), 'Início da produção: data inválida.');
confere('pedido inexistente', $recusa(2, '', ''), 'Pedido não encontrado na Reunião de Planejamento.');

// agMontar sem ERP: o pedido encerrado some dos dias à frente, mas mantém os passados
$db->exec("DROP TABLE PCP_PROGRAMACAO");
$db->exec("CREATE TABLE PCP_PROGRAMACAO (PRG_CODIGO INT, PROD_CODIGO INT, VENDA_REF TEXT, CLI_NOME TEXT,
    DATA_ENTREGA_ESPERADA TEXT, PRG_FINALIZADO INT, DATA_INICIO_PRODUCAO TEXT, DATA_SAIDA TEXT)");
$db->exec("CREATE TABLE PCP_AGENDA (AGD_CODIGO INT, PRG_CODIGO INT, PROD_CODIGO INT, AGD_TIPO TEXT,
    AGD_DATA TEXT, AGD_QTD REAL, AGD_USUARIO TEXT, AGD_REALIZADO REAL, AGD_REALIZADO_POR TEXT)");
$mesTeste  = date('Y-m');
$diaAntes  = date('Y-m-d', strtotime('-1 day'));
$diaDepois = date('Y-m-d', strtotime('+1 day'));
$ins = $db->prepare('INSERT INTO PCP_PROGRAMACAO VALUES (?, ?, ?, ?, NULL, ?, ?, ?)');
$ins->execute([1, 3187, '3829', 'Ativo', 0, $diaAntes, $diaDepois]);
$ins->execute([2, 3188, '3830', 'Encerrado', 1, $diaAntes, $diaDepois]);
$db->exec("INSERT INTO PCP_AGENDA VALUES (1, 1, 3187, 'BAG', '{$diaDepois}', 10, 'x', NULL, NULL)");
$m = agMontar($db, null, $mesTeste);
confere('início dos dois pedidos', array_column($m['marcos'][$diaAntes], 'pedido'), ['3829', '3830']);
confere('saída futura só do ativo', array_column($m['marcos'][$diaDepois], 'pedido'), ['3829']);
confere('card leva as datas do pedido', [$m['cards'][0]['inicio'], $m['cards'][0]['saida']], [$diaAntes, $diaDepois]);

// Demonstração: precisa se identificar e cobrir os estados do calendário
$demo = agTvDemo(date('Y-m'));
$demoCards = array_merge(...array_column(array_merge(...$demo['semanas']), 'cards'));
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
