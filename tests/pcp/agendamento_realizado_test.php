<?php
declare(strict_types=1);

// Previsto por dia e realizado marcado à mão, em SQLite na memória.
// Rodar com: php tests/pcp/agendamento_realizado_test.php

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require_once __DIR__ . '/../../modules/pcp/agendamento_dados.php';

function usuNome(): string
{
    return 'Teste';
}

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

function recusa(callable $acao): string
{
    try {
        $acao();
        return 'aceitou';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

$hoje   = date('Y-m-d');
$ontem  = date('Y-m-d', strtotime('-1 day'));
$anteon = date('Y-m-d', strtotime('-2 days'));
$amanha = date('Y-m-d', strtotime('+1 day'));
$slot   = static fn (string $dia, float $qtd, ?float $feito = null) =>
    ['AGD_DATA' => $dia, 'AGD_QTD' => $qtd, 'AGD_REALIZADO' => $feito];

// ── Saldo: pedido de 1000 dividido em 300/300/100 ────────────────────────
$a = agApurarSaldo([$slot($anteon, 300), $slot($ontem, 300), $slot($amanha, 100)], [], 1000.0, $hoje);
confere('nada produzido: dias passados voltam para o saldo', $a, ['produzido' => 0.0, 'previsto' => 100.0, 'saldo' => 900.0, 'atrasado' => true]);

$a = agApurarSaldo([$slot($anteon, 300, 300.0), $slot($ontem, 300, 300.0), $slot($amanha, 100)], [], 1000.0, $hoje);
confere('realizado igual ao previsto', $a, ['produzido' => 600.0, 'previsto' => 100.0, 'saldo' => 300.0, 'atrasado' => false]);

$a = agApurarSaldo([$slot($anteon, 300, 250.0), $slot($ontem, 300, 300.0), $slot($amanha, 100)], [], 1000.0, $hoje);
confere('realizou menos: a falta volta para o saldo', $a, ['produzido' => 550.0, 'previsto' => 100.0, 'saldo' => 350.0, 'atrasado' => true]);

$a = agApurarSaldo([$slot($hoje, 300, 100.0)], [], 1000.0, $hoje);
confere('hoje: só o que falta continua previsto', $a, ['produzido' => 100.0, 'previsto' => 200.0, 'saldo' => 700.0, 'atrasado' => false]);

$a = agApurarSaldo([$slot($anteon, 300, 300.0), $slot($ontem, 300, 300.0)], ['2026-01-01' => 600.0], 1000.0, $hoje);
confere('lançamento único no ERP não conta duas vezes', $a['produzido'], 600.0);

$a = agApurarSaldo([$slot($anteon, 300, 300.0)], [$anteon => 280.0, $ontem => 200.0], 1000.0, $hoje);
confere('ERP maior que o marcado prevalece no total', $a['produzido'], 480.0);

// ── Marcar o realizado ───────────────────────────────────────────────────
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE PCP_PROGRAMACAO (PRG_CODIGO INT, PROD_CODIGO INT, VENDA_REF TEXT, CLI_NOME TEXT,
    DATA_ENTREGA_ESPERADA TEXT, PRG_FINALIZADO INT, DATA_INICIO_PRODUCAO TEXT, DATA_SAIDA TEXT,
    DATA_PREVIA_PRODUCAO TEXT)");
$db->exec("CREATE TABLE PCP_AGENDA (AGD_CODIGO INT, PRG_CODIGO INT, PROD_CODIGO INT, AGD_TIPO TEXT,
    AGD_DATA TEXT, AGD_QTD REAL, AGD_USUARIO TEXT, AGD_REALIZADO REAL, AGD_REALIZADO_POR TEXT)");
$db->exec("INSERT INTO PCP_PROGRAMACAO VALUES (1, 3187, '3829', 'Cliente', NULL, 0, NULL, NULL, NULL)");
$ins = $db->prepare("INSERT INTO PCP_AGENDA VALUES (?, 1, 3187, 'BAG', ?, ?, 'x', NULL, NULL)");
$ins->execute([1, $ontem, 300]);
$ins->execute([2, $hoje, 300]);
$ins->execute([3, $amanha, 100]);
$marca = static fn (int $agd) => $db->query("SELECT AGD_REALIZADO, AGD_REALIZADO_POR FROM PCP_AGENDA WHERE AGD_CODIGO = {$agd}")->fetch(PDO::FETCH_NUM);

agMarcarRealizado($db, 1, '280');
confere('dia passado aceita realizado', $marca(1), [280.0, 'Teste']);
agMarcarRealizado($db, 1, '0');
confere('zero é marcação válida', $marca(1), [0.0, 'Teste']);
agMarcarRealizado($db, 1, '');
confere('vazio apaga', $marca(1), [null, null]);
confere('dia futuro recusa', recusa(fn () => agMarcarRealizado($db, 3, '50')), 'O realizado só pode ser marcado em hoje ou em dias passados.');
confere('negativo recusa', recusa(fn () => agMarcarRealizado($db, 1, '-1')), 'Informe a quantidade realizada, ou deixe vazio para apagar.');
confere('texto recusa', recusa(fn () => agMarcarRealizado($db, 1, 'abc')), 'Informe a quantidade realizada, ou deixe vazio para apagar.');
confere('agendamento inexistente recusa', recusa(fn () => agMarcarRealizado($db, 99, '1')), 'Este agendamento não existe mais. A tela foi atualizada.');

// ── Card do calendário ───────────────────────────────────────────────────
agMarcarRealizado($db, 1, '250');
agMarcarRealizado($db, 2, '300');
$cards = [];
foreach (agMontar($db, null, date('Y-m', strtotime($hoje)))['cards'] as $c) {
    $cards[$c['dia']] = $c;
}
if (isset($cards[$ontem])) {
    confere('dia passado abaixo do previsto', [$cards[$ontem]['real'], $cards[$ontem]['status']], [250.0, 'abaixo']);
}
confere('hoje cumprido com o marcado', [$cards[$hoje]['real'], $cards[$hoje]['erp'], $cards[$hoje]['status']], [300.0, 0.0, 'cumprido']);
if (isset($cards[$amanha])) {
    confere('dia futuro segue previsto', [$cards[$amanha]['feito'], $cards[$amanha]['status']], [null, 'agendado']);
}

// ── O realizado não some com o agendamento ───────────────────────────────
confere('remover recusa dia com realizado', recusa(fn () => agRemover($db, 2)), 'Este dia tem realizado marcado. Apague o realizado antes de remover.');
confere('mover recusa dia já realizado', recusa(fn () => agMover($db, null, agSlotAberto($db, 2), $amanha)), 'Este agendamento já foi produzido hoje.');

// ── Andamento fora do calendário ─────────────────────────────────────────
$p = ['proximo' => '2026-12-03', 'concluido' => false, 'tipos' => [
    'BAG'     => ['produzido' => 550.0, 'saldo' => 350.0, 'total' => 1000.0],
    'SACARIA' => ['produzido' => 2000.0, 'saldo' => 0.0, 'total' => 2000.0],
]];
confere('linhas por tipo', agProgressoLinhas($p), ['550 de 1.000 bags, 350 a agendar', '2.000 de 2.000 sac.']);
confere('sem andamento não gera linha', agProgressoLinhas(null), []);
confere('link no mês do próximo dia', agLinkCalendario(3187, $p), '/pcp/agendamento?mes=2026-12&busca=3187');
confere('link sem agenda fica no mês atual', agLinkCalendario(3187, null), '/pcp/agendamento?mes=' . date('Y-m') . '&busca=3187');
confere('sem ERP não há andamento', agProgresso($db, null, [1 => 3187]), []);

// ── Entrega: a mesma função grava as três datas ──────────────────────────
$entrega = static fn () => $db->query('SELECT DATA_ENTREGA_ESPERADA FROM PCP_PROGRAMACAO')->fetchColumn();
agMarcarDatas($db, 1, '', '', '2026-12-20');
confere('grava a entrega', $entrega(), '2026-12-20');
agMarcarDatas($db, 1, '', '');
confere('sem entrega mantém a data', $entrega(), '2026-12-20');
confere('entrega vazia recusa', recusa(fn () => agMarcarDatas($db, 1, '', '', '')), 'Informe a data de entrega esperada.');

// ── Alterar a OP do pedido ───────────────────────────────────────────────
$db->exec('ALTER TABLE PCP_PROGRAMACAO ADD COLUMN VEN_COD_PEDIDO TEXT');
$db->exec('ALTER TABLE PCP_PROGRAMACAO ADD COLUMN CLI_CODIGO INT');
$db->exec('ALTER TABLE PCP_PROGRAMACAO ADD COLUMN PROD_DATA TEXT');
$db->exec('ALTER TABLE PCP_PROGRAMACAO ADD COLUMN DATA_ENTREGA TEXT');
$db->exec('ALTER TABLE PCP_PROGRAMACAO ADD COLUMN TIPO_PRODUCAO TEXT');
$db->exec("INSERT INTO PCP_PROGRAMACAO (PRG_CODIGO, PROD_CODIGO, PRG_FINALIZADO) VALUES (2, 4000, 0), (3, 5000, 1)");
$novaOp = static fn (int $n, string $fim = 'N') => [
    'prod_codigo' => $n, 'venda_ref' => '3900', 'ven_cod_pedido' => '77', 'cli_codigo' => '9', 'cli_nome' => 'Cliente Novo',
    'prod_data' => '2026-11-01 00:00:00', 'data_entrega' => '2026-12-01 00:00:00', 'tipo_producao' => 'P', 'finalizado' => $fim,
];
$opsAgenda = static fn () => $db->query('SELECT AGD_CODIGO, PROD_CODIGO FROM PCP_AGENDA ORDER BY AGD_CODIGO')->fetchAll(PDO::FETCH_KEY_PAIR);
$antes = $opsAgenda();

confere('OP igual recusa', recusa(fn () => agAlterarOp($db, 1, $novaOp(3187), ['BAG'])), 'Este pedido já está nesta OP.');
confere('OP finalizada recusa', recusa(fn () => agAlterarOp($db, 1, $novaOp(3200, 'S'), ['BAG'])), 'Esta OP já está finalizada no ERP e não pode ser usada.');
confere('OP de outro pedido recusa', recusa(fn () => agAlterarOp($db, 1, $novaOp(4000), ['BAG'])), 'Esta OP já está na programação.');
confere('pedido finalizado recusa', recusa(fn () => agAlterarOp($db, 3, $novaOp(3200), ['BAG'])), 'Este pedido não está mais na programação.');
confere(
    'OP sem o tipo agendado recusa',
    recusa(fn () => agAlterarOp($db, 1, $novaOp(3200), ['SACARIA'])),
    'A OP 3200 não tem Big Bag, que este pedido tem agendado. Remova esses dias do calendário antes de alterar.'
);
confere('recusa não muda a agenda', $opsAgenda(), $antes);

agAlterarOp($db, 1, $novaOp(3200), ['BAG']);
$linha = $db->query('SELECT PROD_CODIGO, VENDA_REF, CLI_NOME, DATA_ENTREGA, DATA_ENTREGA_ESPERADA FROM PCP_PROGRAMACAO WHERE PRG_CODIGO = 1')->fetch(PDO::FETCH_NUM);
confere('pedido fica com os dados da OP nova e mantém a entrega esperada', $linha, [3200, '3900', 'Cliente Novo', '2026-12-01', '2026-12-20']);
confere('dia passado fica na OP antiga, hoje em diante vai para a nova', $opsAgenda(), [1 => 3187, 2 => 3200, 3 => 3200]);

echo $falhas ? "\n{$falhas} falha(s)\n" : "\nTudo certo\n";
exit($falhas ? 1 : 0);
