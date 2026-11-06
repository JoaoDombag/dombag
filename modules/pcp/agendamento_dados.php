<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/producao_tipo.php';

// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Dados do Agendamento de Produção
//
//  Previsto = PCP_AGENDA (MySQL), um registro por pedido, tipo e dia.
//  Realizado = AGD_REALIZADO, marcado à mão; vale no dia no lugar do ERP.
//  Produzido = ERP, somente leitura:
//    Bag: apontamentos, mesma regra do BI, no dia da leitura.
//    Sacaria: OP_ITENS_PRODUZIDOS, no dia do lançamento. A fábrica costuma
//    lançar a OP de uma vez, então a data é a do lançamento, não a da
//    fabricação. Status 'E' ainda é a quantidade esperada: fica como
//    "em aberto" e não conta como produzido.
//
//  Dias passados são registro: só hoje e os dias à frente podem mudar. Há
//  duas exceções: o realizado marca-se em hoje e nos dias passados, e o pedido
//  que não iniciou no dia pode ser arrastado de lá para hoje ou um dia à frente.
//  O que faltou num dia passado volta sozinho para o saldo a agendar.
// ══════════════════════════════════════════════════════════════════════

const AG_GRP_BAG = 6; // GRUPOS.GRP_CODIGO no ERP
const AG_MARCOS  = ['inicio' => 'DATA_INICIO_PRODUCAO', 'saida' => 'DATA_SAIDA'];

function agPgAll($pg, string $sql, array $params = []): array
{
    $res = @pg_query_params($pg, $sql, $params);
    if (!$res) {
        throw new RuntimeException('Não foi possível consultar a produção no ERP: ' . pg_last_error($pg));
    }
    $rows = pg_fetch_all($res) ?: [];
    pg_free_result($res);
    return $rows;
}

function agTipoValido(string $tipo): bool
{
    return in_array($tipo, [PROD_TIPO_BAG, PROD_TIPO_SACARIA], true);
}

function agData(string $valor): ?string
{
    $dt = DateTime::createFromFormat('!Y-m-d', $valor);
    return $dt && $dt->format('Y-m-d') === $valor ? $valor : null;
}

function agMes(string $valor): string
{
    return agData($valor . '-01') !== null ? $valor : date('Y-m');
}

// Semanas completas (segunda a domingo) que cobrem o mês
function agGrade(string $mes): array
{
    $ini = new DateTimeImmutable($mes . '-01');
    $fim = $ini->modify('last day of this month');
    return [
        $ini->modify('-' . ((int) $ini->format('N') - 1) . ' days')->format('Y-m-d'),
        $fim->modify('+' . (7 - (int) $fim->format('N')) . ' days')->format('Y-m-d'),
    ];
}

// Produção por dia, OP e tipo. $cond recebe as colunas de data e de OP e devolve o filtro.
function agProducaoSql(callable $cond): string
{
    $grpBag = AG_GRP_BAG;
    $grpSac = PROD_GRP_SACARIA;
    return "
        SELECT A.OIAP_DATA_HORA_INICIO::date AS dia, A.PROD_CODIGO AS op, '" . PROD_TIPO_BAG . "' AS tipo,
               SUM(A.OIAP_QTD_PRODUZIDA) AS qtd, 0 AS aberto
          FROM OP_ITENS_ATIVIDADES_APONTADAS A
          JOIN PRODUTO P ON P.PRO_CODIGO = A.PRO_CODIGO
         WHERE NOT A.OIAP_EXCLUIDO
           AND TRIM(A.OIAP_STATUS) <> 'C'
           AND P.GRP_CODIGO = {$grpBag}
           AND " . $cond('A.OIAP_DATA_HORA_INICIO', 'A.PROD_CODIGO') . "
         GROUP BY 1, 2
        UNION ALL
        SELECT I.OIP_DATA_INICIAL::date, I.PROD_CODIGO, '" . PROD_TIPO_SACARIA . "',
               COALESCE(SUM(I.OIP_QTD) FILTER (WHERE TRIM(I.OIP_STATUS) = 'F'), 0),
               COALESCE(SUM(I.OIP_QTD) FILTER (WHERE TRIM(I.OIP_STATUS) = 'E'), 0)
          FROM OP_ITENS_PRODUZIDOS I
          JOIN PRODUTO P ON P.PRO_CODIGO = I.PRO_CODIGO
         WHERE P.GRP_CODIGO = {$grpSac}
           AND " . $cond('I.OIP_DATA_INICIAL', 'I.PROD_CODIGO') . "
         GROUP BY 1, 2
    ";
}

function agProducaoPeriodo($pg, string $ini, string $fim): array
{
    return agPgAll($pg, agProducaoSql(
        fn (string $data) => "{$data} >= \$1::date AND {$data} < \$2::date + 1"
    ), [$ini, $fim]);
}

function agProducaoOps($pg, array $ops): array
{
    if (!$ops) {
        return [];
    }
    return agPgAll($pg, agProducaoSql(
        fn (string $data, string $op) => "{$op} = ANY(\$1::int[])"
    ), ['{' . implode(',', array_map('intval', $ops)) . '}']);
}

// Pedido, cliente e quantidade por tipo de cada OP
function agOpsInfo($pg, array $ops): array
{
    if (!$ops) {
        return [];
    }
    $rows = agPgAll($pg, "
        SELECT PR.PROD_CODIGO AS op, TRIM(PR.VENDA_REF) AS pedido, TRIM(PR.FINALIZADO) AS finalizado,
               COALESCE(NULLIF(TRIM(C.CLI_NOME_FANTASIA), ''), C.CLI_NOME, '') AS cliente,
               CASE P.GRP_CODIGO WHEN " . AG_GRP_BAG . " THEN '" . PROD_TIPO_BAG . "'
                                 WHEN " . PROD_GRP_SACARIA . " THEN '" . PROD_TIPO_SACARIA . "' END AS tipo,
               SUM(IP.ITE_QTD) AS qtd, MIN(P.PRO_DESCRICAO) AS produto, COUNT(*) AS itens
          FROM PRODUCAO PR
          LEFT JOIN CLIENTES C        ON C.CLI_CODIGO = PR.CLI_CODIGO
          LEFT JOIN ITENS_PRODUCAO IP ON IP.PROD_CODIGO = PR.PROD_CODIGO
          LEFT JOIN PRODUTO P         ON P.PRO_CODIGO = IP.PRO_CODIGO
                                     AND P.GRP_CODIGO IN (" . AG_GRP_BAG . ', ' . PROD_GRP_SACARIA . ")
         WHERE PR.EMP_CODIGO = 1
           AND PR.PROD_CODIGO = ANY(\$1::int[])
         GROUP BY 1, 2, 3, 4, 5
    ", ['{' . implode(',', array_map('intval', $ops)) . '}']);

    $info = [];
    foreach ($rows as $r) {
        $op = (int) $r['op'];
        $info[$op] ??= [
            'pedido'     => $r['pedido'] ?? '',
            'cliente'    => $r['cliente'],
            'finalizado' => $r['finalizado'] === 'Y',
            'tipos'      => [],
        ];
        if ($r['tipo'] !== null) {
            $info[$op]['tipos'][$r['tipo']] = [
                'qtd'     => (float) $r['qtd'],
                'produto' => (int) $r['itens'] > 1 ? $r['produto'] . ' +' . ((int) $r['itens'] - 1) : $r['produto'],
            ];
        }
    }
    return $info;
}

// Andamento de cada pedido, na mesma conta do "A agendar", para as telas que
// mostram o pedido fora do calendário. $pedidos = [PRG_CODIGO => PROD_CODIGO].
// Sem ERP ou sem a tabela volta vazio: o andamento é informação extra nessas telas.
function agProgresso(PDO $db, $pg, array $pedidos): array
{
    if (!$pg || !$pedidos) {
        return [];
    }
    try {
        $hoje = date('Y-m-d');
        $lista = implode(',', array_map('intval', array_keys($pedidos)));
        $slots = [];
        $proximo = [];
        foreach ($db->query("SELECT PRG_CODIGO, AGD_TIPO, AGD_DATA, AGD_QTD, AGD_REALIZADO FROM PCP_AGENDA WHERE PRG_CODIGO IN ({$lista})") as $s) {
            $prg = (int) $s['PRG_CODIGO'];
            $slots[$prg][$s['AGD_TIPO']][] = $s;
            if ($s['AGD_DATA'] >= $hoje && ($s['AGD_DATA'] < ($proximo[$prg] ?? '9999'))) {
                $proximo[$prg] = $s['AGD_DATA'];
            }
        }
        $ops  = array_unique(array_map('intval', $pedidos));
        $info = agOpsInfo($pg, $ops);
        $real = [];
        foreach (agProducaoOps($pg, $ops) as $r) {
            $real[(int) $r['op']][$r['tipo']][$r['dia']] = (float) $r['qtd'];
        }
    } catch (Throwable $e) {
        error_log('[AGENDAMENTO] ' . $e->getMessage());
        return [];
    }

    $saida = [];
    foreach ($pedidos as $prg => $op) {
        $tipos = [];
        foreach ($info[(int) $op]['tipos'] ?? [] as $tipo => $t) {
            $tipos[$tipo] = agApurarSaldo($slots[$prg][$tipo] ?? [], $real[(int) $op][$tipo] ?? [], $t['qtd'], $hoje)
                + ['total' => $t['qtd']];
        }
        if ($tipos) {
            $saida[$prg] = [
                'tipos'     => $tipos,
                'proximo'   => $proximo[$prg] ?? null,
                'concluido' => !array_filter($tipos, static fn ($t) => $t['produzido'] < $t['total']),
            ];
        }
    }
    return $saida;
}

// Uma linha por tipo: "550 de 1.000 bags, 350 a agendar"
function agProgressoLinhas(?array $p): array
{
    $un = [PROD_TIPO_BAG => 'bags', PROD_TIPO_SACARIA => 'sac.'];
    $fmt = static fn (float $n): string => number_format($n, 0, ',', '.');
    $linhas = [];
    foreach ($p['tipos'] ?? [] as $tipo => $t) {
        $linhas[] = $fmt($t['produzido']) . ' de ' . $fmt($t['total']) . ' ' . $un[$tipo]
            . ($t['saldo'] > 0 ? ', ' . $fmt($t['saldo']) . ' a agendar' : '');
    }
    return $linhas;
}

// Abre o calendário no mês do próximo dia agendado, já filtrado no pedido
function agLinkCalendario(int $op, ?array $p): string
{
    return '/pcp/agendamento?' . http_build_query([
        'mes'   => substr($p['proximo'] ?? date('Y-m-d'), 0, 7),
        'busca' => $op,
    ]);
}

// Monta tudo o que a tela mostra para o mês
// O ERP pode lançar de uma vez o que foi marcado dia a dia, em outra data. Somar
// os dois contaria a mesma produção duas vezes, então vale o maior dos totais.
function agApurarSaldo(array $slots, array $reais, float $qtdOp, string $hoje): array
{
    $marcado  = 0.0;
    $previsto = 0.0;
    $atrasado = false;
    foreach ($slots as $s) {
        $qtd   = (float) $s['AGD_QTD'];
        $feito = $s['AGD_REALIZADO'] === null ? null : (float) $s['AGD_REALIZADO'];
        $real  = $feito ?? ($reais[$s['AGD_DATA']] ?? 0.0);
        $marcado += $feito ?? 0.0;
        if ($s['AGD_DATA'] > $hoje) {
            $previsto += $qtd;
        } elseif ($s['AGD_DATA'] === $hoje) {
            $previsto += max(0.0, $qtd - $real);
        } elseif ($real < $qtd) {
            $atrasado = true;
        }
    }
    $produzido = max((float) array_sum($reais), $marcado);
    return [
        'produzido' => $produzido,
        'previsto'  => $previsto,
        'saldo'     => max(0.0, $qtdOp - $produzido - $previsto),
        'atrasado'  => $atrasado,
    ];
}

function agMontar(PDO $db, $pg, string $mes): array
{
    $hoje = date('Y-m-d');
    [$ini, $fim] = agGrade($mes);

    $programados = $db->query('
        SELECT PRG_CODIGO, PROD_CODIGO, VENDA_REF, CLI_NOME, DATA_ENTREGA_ESPERADA, PRG_FINALIZADO,
               DATA_INICIO_PRODUCAO, DATA_SAIDA
          FROM PCP_PROGRAMACAO
    ')->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('
        SELECT a.AGD_CODIGO, a.PRG_CODIGO, a.PROD_CODIGO, a.AGD_TIPO, a.AGD_DATA, a.AGD_QTD, a.AGD_USUARIO,
               a.AGD_REALIZADO, a.AGD_REALIZADO_POR
          FROM PCP_AGENDA a
          JOIN PCP_PROGRAMACAO p ON p.PRG_CODIGO = a.PRG_CODIGO
         WHERE p.PRG_FINALIZADO = 0 OR a.AGD_DATA BETWEEN ? AND ?
    ');
    $stmt->execute([$ini, $fim]);
    $prgFinalizado = array_column($programados, 'PRG_FINALIZADO', 'PRG_CODIGO');
    // Pedido encerrado na Reunião não tem mais o que produzir: some dos dias à frente
    $slots = array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn ($s) =>
        !$prgFinalizado[$s['PRG_CODIGO']] || $s['AGD_DATA'] < $hoje);

    // Pedido planejado é o que tem dia na agenda. A TV segue mostrando também o que está em produção hoje.
    $opsPlanejadas = array_fill_keys(array_map('intval', array_column($slots, 'PROD_CODIGO')), true);

    $prgPorCodigo = array_column($programados, null, 'PRG_CODIGO');
    $prgPorOp = [];
    foreach ($programados as $p) {
        $op = (int) $p['PROD_CODIGO'];
        if (!isset($prgPorOp[$op]) || !$p['PRG_FINALIZADO']) {
            $prgPorOp[$op] = $p;
        }
    }
    $ativos = array_filter($programados, static fn ($p) => !$p['PRG_FINALIZADO']);

    // ── ERP ──────────────────────────────────────────────────────────────
    $producaoGrade = [];
    $producaoOps   = [];
    $info          = [];
    if ($pg) {
        $producaoGrade = agProducaoPeriodo($pg, $ini, $fim);
        $codigos = array_unique(array_merge(
            array_map('intval', array_column($ativos, 'PROD_CODIGO')),
            array_map('intval', array_column($slots, 'PROD_CODIGO'))
        ));
        $producaoOps = agProducaoOps($pg, $codigos);
        $info = agOpsInfo($pg, array_unique(array_merge($codigos, array_map('intval', array_column($producaoGrade, 'op')))));
    }

    $realDia = [];   // [op][tipo][dia] => qtd confirmada
    foreach ($producaoOps as $r) {
        $realDia[(int) $r['op']][$r['tipo']][$r['dia']] = (float) $r['qtd'];
    }

    // ── Cards do calendário: previsto + produzido por OP, tipo e dia ─────
    $cards = [];
    $novoCard = static function (int $op, string $tipo, string $dia) use (&$cards, &$info, &$prgPorOp): void {
        $k = "{$dia}|{$op}|{$tipo}";
        if (isset($cards[$k])) {
            return;
        }
        $prg = $prgPorOp[$op] ?? null;
        $cards[$k] = [
            'dia' => $dia, 'op' => $op, 'tipo' => $tipo,
            'pedido'  => ($info[$op]['pedido'] ?? '') ?: (string) ($prg['VENDA_REF'] ?? ''),
            'cliente' => ($info[$op]['cliente'] ?? '') ?: (string) ($prg['CLI_NOME'] ?? ''),
            'produto' => $info[$op]['tipos'][$tipo]['produto'] ?? '',
            'total'   => $info[$op]['tipos'][$tipo]['qtd'] ?? 0.0,
            'prg'     => $prg ? (int) $prg['PRG_CODIGO'] : 0,
            'programado' => $prg !== null,
            'entrega' => $prg['DATA_ENTREGA_ESPERADA'] ?? null,
            'inicio'  => $prg['DATA_INICIO_PRODUCAO'] ?? null,
            'saida'   => $prg['DATA_SAIDA'] ?? null,
            'agd' => 0, 'usuario' => '', 'plan' => 0.0, 'real' => 0.0, 'aberto' => 0.0,
            'feito' => null, 'feito_por' => '',
        ];
    };

    foreach ($slots as $s) {
        if ($s['AGD_DATA'] < $ini || $s['AGD_DATA'] > $fim) {
            continue;
        }
        $novoCard((int) $s['PROD_CODIGO'], $s['AGD_TIPO'], $s['AGD_DATA']);
        $k = "{$s['AGD_DATA']}|{$s['PROD_CODIGO']}|{$s['AGD_TIPO']}";
        $cards[$k]['agd']     = (int) $s['AGD_CODIGO'];
        $cards[$k]['prg']     = (int) $s['PRG_CODIGO'];
        $cards[$k]['plan']    = (float) $s['AGD_QTD'];
        $cards[$k]['usuario'] = (string) $s['AGD_USUARIO'];
        $cards[$k]['feito']   = $s['AGD_REALIZADO'] === null ? null : (float) $s['AGD_REALIZADO'];
        $cards[$k]['feito_por'] = (string) $s['AGD_REALIZADO_POR'];
        $cards[$k]['entrega'] = $prgPorCodigo[$s['PRG_CODIGO']]['DATA_ENTREGA_ESPERADA'] ?? null;
        $cards[$k]['inicio']  = $prgPorCodigo[$s['PRG_CODIGO']]['DATA_INICIO_PRODUCAO'] ?? null;
        $cards[$k]['saida']   = $prgPorCodigo[$s['PRG_CODIGO']]['DATA_SAIDA'] ?? null;
    }
    foreach ($producaoGrade as $r) {
        $op = (int) $r['op'];
        if (!isset($info[$op]) || ((float) $r['qtd'] <= 0 && (float) $r['aberto'] <= 0)) {
            continue; // outra empresa, ou linha zerada
        }
        $novoCard($op, $r['tipo'], $r['dia']);
        $k = "{$r['dia']}|{$op}|{$r['tipo']}";
        $cards[$k]['real']   = (float) $r['qtd'];
        $cards[$k]['aberto'] = (float) $r['aberto'];
    }

    foreach ($cards as &$c) {
        $c['erp']  = $c['real'];
        $c['planejado'] = isset($opsPlanejadas[$c['op']]);
        $c['real'] = $c['feito'] ?? $c['real'];
        $temPlano = $c['plan'] > 0;
        $c['status'] = match (true) {
            !$temPlano                => 'produzido',
            $c['real'] >= $c['plan']  => 'cumprido',
            $c['dia'] < $hoje         => 'abaixo',
            default                   => 'agendado',
        };
        $c['editavel'] = $temPlano && $c['dia'] >= $hoje;
        $c['movel']    = $c['editavel'] || ($pg && $temPlano && empty($prgFinalizado[$c['prg']])
            && agNaoIniciou($c['feito'], $c['erp'], $c['aberto']));
        $c['tarde']    = $temPlano && $c['status'] !== 'cumprido' && $c['entrega'] && $c['dia'] > $c['entrega'];
    }
    unset($c);

    // ── Saldo a agendar de cada pedido ativo, por tipo ───────────────────
    $slotsPorChave = [];
    foreach ($slots as $s) {
        $slotsPorChave[$s['PRG_CODIGO'] . '|' . $s['AGD_TIPO']][] = $s;
    }

    $pendentes = [];
    foreach ($ativos as $p) {
        $op = (int) $p['PROD_CODIGO'];
        foreach ($info[$op]['tipos'] ?? [] as $tipo => $t) {
            $apurado = agApurarSaldo(
                $slotsPorChave[$p['PRG_CODIGO'] . '|' . $tipo] ?? [],
                $realDia[$op][$tipo] ?? [],
                $t['qtd'],
                $hoje
            );
            if ($apurado['saldo'] <= 0) {
                continue;
            }
            $pendentes[] = [
                'prg' => (int) $p['PRG_CODIGO'], 'op' => $op, 'tipo' => $tipo,
                'pedido'     => $info[$op]['pedido'] ?: (string) $p['VENDA_REF'],
                'cliente'    => $info[$op]['cliente'] ?: (string) $p['CLI_NOME'],
                'produto'    => $t['produto'],
                'total'      => $t['qtd'],
                'produzido'  => $apurado['produzido'],
                'saldo'      => $apurado['saldo'],
                'entrega'    => $p['DATA_ENTREGA_ESPERADA'],
                'inicio'     => $p['DATA_INICIO_PRODUCAO'],
                'saida'      => $p['DATA_SAIDA'],
                'atrasado'   => $apurado['atrasado'],
                'erp_finalizada' => $info[$op]['finalizado'],
            ];
        }
    }
    usort($pendentes, static fn ($a, $b) =>
        [$a['entrega'] === null, $a['entrega']] <=> [$b['entrega'] === null, $b['entrega']]);

    // ── Totais do mês e resumo por pedido ────────────────────────────────
    $totais = [
        PROD_TIPO_BAG     => ['produzido' => 0.0, 'agendado' => 0.0, 'a_agendar' => 0.0],
        PROD_TIPO_SACARIA => ['produzido' => 0.0, 'agendado' => 0.0, 'a_agendar' => 0.0],
    ];
    foreach ($pendentes as $p) {
        $totais[$p['tipo']]['a_agendar'] += $p['saldo'];
    }

    $resumo = [];
    foreach ($cards as $c) {
        if (!str_starts_with($c['dia'], $mes) || !$c['planejado']) {
            continue;
        }
        // Totais ficam no ERP: o marcado à mão pode repetir um lançamento de outro dia
        $totais[$c['tipo']]['produzido'] += $c['erp'];
        $totais[$c['tipo']]['agendado']  += $c['plan'];

        $k = "{$c['op']}|{$c['tipo']}";
        $resumo[$k] ??= [
            'op' => $c['op'], 'tipo' => $c['tipo'], 'pedido' => $c['pedido'], 'cliente' => $c['cliente'],
            'programado' => $c['programado'], 'entrega' => $c['entrega'],
            'total' => $c['total'], 'agendado' => 0.0, 'marcado' => 0.0, 'produzido' => 0.0, 'aberto' => 0.0,
        ];
        $resumo[$k]['agendado']  += $c['plan'];
        $resumo[$k]['marcado']   += $c['feito'] ?? 0.0;
        $resumo[$k]['produzido'] += $c['erp'];
        $resumo[$k]['aberto']    += $c['aberto'];
    }
    $resumo = array_values($resumo);
    usort($resumo, static fn ($a, $b) =>
        [$b['programado'], $a['cliente'], $a['op']] <=> [$a['programado'], $b['cliente'], $b['op']]);

    // Prazo de entrega de cada pedido ativo, para aparecer no dia
    $entregas = [];
    foreach ($ativos as $p) {
        $dia = $p['DATA_ENTREGA_ESPERADA'];
        if ($dia && $dia >= $ini && $dia <= $fim) {
            $op = (int) $p['PROD_CODIGO'];
            $entregas[$dia][] = [
                'pedido'  => ($info[$op]['pedido'] ?? '') ?: (string) $p['VENDA_REF'],
                'cliente' => ($info[$op]['cliente'] ?? '') ?: (string) $p['CLI_NOME'],
                'op'      => $op,
                'planejado' => isset($opsPlanejadas[$op]),
            ];
        }
    }

    // Início da produção e saída marcados no pedido. Como nos agendamentos, o
    // pedido encerrado na Reunião some dos dias à frente.
    $marcos = [];
    foreach ($programados as $p) {
        foreach (AG_MARCOS as $marco => $coluna) {
            $dia = $p[$coluna];
            if (!$dia || $dia < $ini || $dia > $fim || ($p['PRG_FINALIZADO'] && $dia > $hoje)) {
                continue;
            }
            $op = (int) $p['PROD_CODIGO'];
            $marcos[$dia][] = [
                'marco'   => $marco,
                'planejado' => isset($opsPlanejadas[$op]),
                'pedido'  => ($info[$op]['pedido'] ?? '') ?: (string) $p['VENDA_REF'],
                'cliente' => ($info[$op]['cliente'] ?? '') ?: (string) $p['CLI_NOME'],
                'op'      => $op,
            ];
        }
    }

    return [
        'mes'       => $mes,
        'hoje'      => $hoje,
        'ini'       => $ini,
        'fim'       => $fim,
        'erp'       => (bool) $pg,
        'cards'     => array_values($cards),
        'pendentes' => $pendentes,
        'entregas'  => $entregas,
        'marcos'    => $marcos,
        'resumo'    => $resumo,
        'totais'    => $totais,
    ];
}

// ── Escrita ──────────────────────────────────────────────────────────────

// Campo vazio apaga a data. Valem dias passados: o início real pode ser anotado depois.
// A entrega esperada é obrigatória no pedido; null a deixa como está.
function agMarcarDatas(PDO $db, int $prg, string $inicio, string $saida, ?string $entrega = null): void
{
    if ($entrega !== null && agData($entrega) === null) {
        throw new RuntimeException('Informe a data de entrega esperada.');
    }
    $datas = [];
    foreach (['Início da produção' => $inicio, 'Saída' => $saida] as $nome => $valor) {
        $dia = agData($valor);
        if ($valor !== '' && $dia === null) {
            throw new RuntimeException("{$nome}: data inválida.");
        }
        $datas[] = $dia;
    }
    if ($datas[0] !== null && $datas[1] !== null && $datas[1] < $datas[0]) {
        throw new RuntimeException('A saída não pode ser antes do início da produção.');
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM PCP_PROGRAMACAO WHERE PRG_CODIGO = ?');
    $stmt->execute([$prg]);
    if (!(int) $stmt->fetchColumn()) {
        throw new RuntimeException('Pedido não encontrado na Reunião de Planejamento.');
    }

    $db->prepare('UPDATE PCP_PROGRAMACAO SET DATA_INICIO_PRODUCAO = ?, DATA_SAIDA = ? WHERE PRG_CODIGO = ?')
       ->execute([$datas[0], $datas[1], $prg]);
    if ($entrega !== null) {
        $db->prepare('UPDATE PCP_PROGRAMACAO SET DATA_ENTREGA_ESPERADA = ? WHERE PRG_CODIGO = ?')
           ->execute([$entrega, $prg]);
    }
}

// Campo vazio apaga a marcação, e o dia volta a mostrar o ERP. Zero é "nada produzido".
function agMarcarRealizado(PDO $db, int $agd, string $valor): void
{
    $valor = trim($valor);
    if ($valor !== '' && (!is_numeric($valor) || (float) $valor < 0)) {
        throw new RuntimeException('Informe a quantidade realizada, ou deixe vazio para apagar.');
    }
    $stmt = $db->prepare('SELECT AGD_DATA FROM PCP_AGENDA WHERE AGD_CODIGO = ?');
    $stmt->execute([$agd]);
    $dia = $stmt->fetchColumn();
    if ($dia === false) {
        throw new RuntimeException('Este agendamento não existe mais. A tela foi atualizada.');
    }
    if ($dia > date('Y-m-d')) {
        throw new RuntimeException('O realizado só pode ser marcado em hoje ou em dias passados.');
    }
    $db->prepare('UPDATE PCP_AGENDA SET AGD_REALIZADO = ?, AGD_REALIZADO_POR = ? WHERE AGD_CODIGO = ?')
       ->execute([$valor === '' ? null : (float) $valor, $valor === '' ? null : usuNome(), $agd]);
}

function agSincronizarPrevia(PDO $db, int $prg): void
{
    // Kanban e Status das OPs leem a previsão desta coluna
    $db->prepare('
        UPDATE PCP_PROGRAMACAO
           SET DATA_PREVIA_PRODUCAO = (SELECT MAX(AGD_DATA) FROM PCP_AGENDA WHERE PRG_CODIGO = ?)
         WHERE PRG_CODIGO = ?
    ')->execute([$prg, $prg]);
}

function agSomarNoDia(PDO $db, int $prg, int $op, string $tipo, string $dia, float $qtd): void
{
    $db->prepare('
        INSERT INTO PCP_AGENDA (PRG_CODIGO, PROD_CODIGO, AGD_TIPO, AGD_DATA, AGD_QTD, AGD_USUARIO)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE AGD_QTD = AGD_QTD + VALUES(AGD_QTD), AGD_USUARIO = VALUES(AGD_USUARIO)
    ')->execute([$prg, $op, $tipo, $dia, $qtd, usuNome()]);
}

function agExigirDiaAberto(string $dia): void
{
    if ($dia < date('Y-m-d')) {
        throw new RuntimeException('Dias passados não podem ser alterados.');
    }
}

// Sem produção marcada, confirmada ou em aberto. Realizado zero é "nada produzido".
function agNaoIniciou(?float $feito, float $erp, float $aberto): bool
{
    return ($feito ?? 0.0) <= 0 && $erp <= 0 && $aberto <= 0;
}

function agExigirNaoIniciado($pg, array $slot): void
{
    $erro = 'Este dia já tem produção e não pode ser movido.';
    if ((float) $slot['AGD_REALIZADO'] > 0) {
        throw new RuntimeException($erro);
    }
    if (!$pg) {
        throw new RuntimeException('Sem conexão com o ERP não é possível confirmar que o pedido não iniciou neste dia.');
    }
    foreach (agProducaoOps($pg, [(int) $slot['PROD_CODIGO']]) as $r) {
        if ($r['dia'] === $slot['AGD_DATA'] && $r['tipo'] === $slot['AGD_TIPO']
            && !agNaoIniciou(null, (float) $r['qtd'], (float) $r['aberto'])) {
            throw new RuntimeException($erro);
        }
    }
}

// Agendamento ainda editável, ou erro. $passado aceita dia passado, para mover.
function agSlotAberto(PDO $db, int $agd, bool $passado = false): array
{
    $stmt = $db->prepare('
        SELECT a.*, p.PRG_FINALIZADO
          FROM PCP_AGENDA a
          JOIN PCP_PROGRAMACAO p ON p.PRG_CODIGO = a.PRG_CODIGO
         WHERE a.AGD_CODIGO = ?
    ');
    $stmt->execute([$agd]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot || $slot['PRG_FINALIZADO']) {
        throw new RuntimeException('Este agendamento não existe mais. A tela foi atualizada.');
    }
    if (!$passado) {
        agExigirDiaAberto($slot['AGD_DATA']);
    }
    return $slot;
}

function agAgendar(PDO $db, int $prg, string $tipo, string $dia, float $qtd): void
{
    agExigirDiaAberto($dia);
    if (!agTipoValido($tipo) || $qtd <= 0) {
        throw new RuntimeException('Informe uma quantidade maior que zero.');
    }
    $stmt = $db->prepare('SELECT PROD_CODIGO FROM PCP_PROGRAMACAO WHERE PRG_CODIGO = ? AND PRG_FINALIZADO = 0');
    $stmt->execute([$prg]);
    $op = $stmt->fetchColumn();
    if ($op === false) {
        throw new RuntimeException('Este pedido não está mais na programação.');
    }
    agSomarNoDia($db, $prg, (int) $op, $tipo, $dia, $qtd);
    agSincronizarPrevia($db, $prg);
}

// Hoje com produção já lançada: o que foi feito fica no dia, só o restante muda de data.
// De dia passado, só o pedido que não iniciou sai, e sai inteiro.
function agMover(PDO $db, $pg, array $slot, string $destino): void
{
    agExigirDiaAberto($destino);
    if ($slot['AGD_DATA'] < date('Y-m-d')) {
        agExigirNaoIniciado($pg, $slot);
    }
    if ($destino === $slot['AGD_DATA']) {
        return;
    }
    $agd  = (int) $slot['AGD_CODIGO'];
    $qtd  = (float) $slot['AGD_QTD'];
    $fica = 0.0;
    if ($slot['AGD_DATA'] === date('Y-m-d') && $slot['AGD_REALIZADO'] !== null) {
        $fica = min($qtd, (float) $slot['AGD_REALIZADO']);
    } elseif ($slot['AGD_DATA'] === date('Y-m-d') && $pg) {
        foreach (agProducaoOps($pg, [(int) $slot['PROD_CODIGO']]) as $r) {
            if ($r['dia'] === $slot['AGD_DATA'] && $r['tipo'] === $slot['AGD_TIPO']) {
                $fica = min($qtd, (float) $r['qtd']);
            }
        }
    }
    if ($fica >= $qtd) {
        throw new RuntimeException('Este agendamento já foi produzido hoje.');
    }

    $db->beginTransaction();
    try {
        agSomarNoDia($db, (int) $slot['PRG_CODIGO'], (int) $slot['PROD_CODIGO'], $slot['AGD_TIPO'], $destino, $qtd - $fica);
        if ($fica > 0) {
            $db->prepare('UPDATE PCP_AGENDA SET AGD_QTD = ? WHERE AGD_CODIGO = ?')->execute([$fica, $agd]);
        } else {
            $db->prepare('DELETE FROM PCP_AGENDA WHERE AGD_CODIGO = ?')->execute([$agd]);
        }
        agSincronizarPrevia($db, (int) $slot['PRG_CODIGO']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function agEditar(PDO $db, $pg, int $agd, float $qtd, string $dia): void
{
    if ($qtd <= 0) {
        throw new RuntimeException('Informe uma quantidade maior que zero.');
    }
    $slot = agSlotAberto($db, $agd);
    agExigirDiaAberto($dia);
    $db->prepare('UPDATE PCP_AGENDA SET AGD_QTD = ?, AGD_USUARIO = ? WHERE AGD_CODIGO = ?')
       ->execute([$qtd, usuNome(), $agd]);
    $slot['AGD_QTD'] = $qtd;
    agMover($db, $pg, $slot, $dia);
}

function agRemover(PDO $db, int $agd): void
{
    $slot = agSlotAberto($db, $agd);
    if ((float) $slot['AGD_REALIZADO'] > 0) {
        throw new RuntimeException('Este dia tem realizado marcado. Apague o realizado antes de remover.');
    }
    $db->prepare('DELETE FROM PCP_AGENDA WHERE AGD_CODIGO = ?')->execute([$agd]);
    agSincronizarPrevia($db, (int) $slot['PRG_CODIGO']);
}
