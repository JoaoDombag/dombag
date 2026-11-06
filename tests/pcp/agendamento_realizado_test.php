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

confere('pedido com dia na agenda é planejado', array_values(array_unique(array_column($cards, 'planejado'))), [true]);

// ── O realizado não some com o agendamento ───────────────────────────────
confere('remover recusa dia com realizado', recusa(fn () => agRemover($db, 2)), 'Este dia tem realizado marcado. Apague o realizado antes de remover.');
confere('mover recusa dia já realizado', recusa(fn () => agMover($db, null, agSlotAberto($db, 2), $amanha)), 'Este agendamento já foi produzido hoje.');

// ── Dia passado: só sai o pedido que não iniciou ─────────────────────────
confere('nada produzido não iniciou', agNaoIniciou(null, 0.0, 0.0), true);
confere('realizado zero não iniciou', agNaoIniciou(0.0, 0.0, 0.0), true);
confere('realizado marcado iniciou', agNaoIniciou(5.0, 0.0, 0.0), false);
confere('produção no ERP iniciou', agNaoIniciou(null, 5.0, 0.0), false);
confere('lançamento em aberto iniciou', agNaoIniciou(null, 0.0, 5.0), false);
confere('zero marcado com produção no ERP iniciou', agNaoIniciou(0.0, 5.0, 0.0), false);
confere('editar segue recusando dia passado', recusa(fn () => agSlotAberto($db, 1)), 'Dias passados não podem ser alterados.');
$movePassado = static fn (string $dia) => agMover($db, null, agSlotAberto($db, 1, true), $dia);
confere('dia com realizado não sai', recusa(fn () => $movePassado($amanha)), 'Este dia já tem produção e não pode ser movido.');
agMarcarRealizado($db, 1, '0');
confere('sem ERP não sai', recusa(fn () => $movePassado($amanha)), 'Sem conexão com o ERP não é possível confirmar que o pedido não iniciou neste dia.');
confere('destino passado recusa', recusa(fn () => $movePassado($anteon)), 'Dias passados não podem ser alterados.');
agMarcarRealizado($db, 1, '250');
$semErp = agMontar($db, null, date('Y-m'))['cards'];
confere('sem ERP o card passado não arrasta', array_values(array_filter($semErp, static fn ($c) => $c['dia'] < $hoje && $c['movel'])), []);

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

echo $falhas ? "\n{$falhas} falha(s)\n" : "\nTudo certo\n";
exit($falhas ? 1 : 0);
