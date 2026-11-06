<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/agendamento_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Dados do Agendamento na TV (/pcp/tv)
//  Reaproveita agMontar: mostra os pedidos agendados e, no dia de hoje, o que
//  está em produção mesmo sem agendamento. Sem totais nem fila. Quantidades
//  e textos saem prontos daqui, porque a TV não tem Intl confiável nem deve
//  repetir regra de negócio em JavaScript.
// ══════════════════════════════════════════════════════════════

const AGTV_UNIDADE = [PROD_TIPO_BAG => 'bags', PROD_TIPO_SACARIA => 'sacarias'];
const AGTV_MARCOS = ['inicio' => 'Início', 'finalizado' => 'Finalizado'];
const AGTV_MESES   = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho',
                      'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

class AgTvErro extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}

// ?mes=YYYY-MM válido, ou null (o painel acompanha o mês atual).
function agTvMesFixo(?string $valor): ?string
{
    $m = trim((string) $valor);
    return preg_match('/^\d{4}-\d{2}$/', $m) && agData($m . '-01') !== null ? $m : null;
}

function agTvNum(float $n): string
{
    return number_format($n, 0, ',', '.');
}

function agTvMesTitulo(string $mes): string
{
    [$ano, $m] = explode('-', $mes);
    return AGTV_MESES[(int) $m - 1] . ' de ' . $ano;
}

// Texto da quantidade: com previsto, mesmas regras de htmlCard em agendamento.php.
function agTvQtd(array $c, string $hoje): string
{
    $un = AGTV_UNIDADE[$c['tipo']];
    if ($c['plan'] > 0) {
        return ($c['real'] > 0 || $c['dia'] < $hoje)
            ? agTvNum($c['real']) . " {$un} de " . agTvNum($c['plan'])
            : agTvNum($c['plan']) . " {$un} prev.";
    }
    return match (true) {
        $c['real'] > 0   => agTvNum($c['real']) . " {$un} s/ agenda",
        $c['aberto'] > 0 => agTvNum($c['aberto']) . " {$un} em aberto",
        default          => AG_TIPO_NOME[$c['tipo']] . ' sem agenda',
    };
}

function agTvCard(array $c, string $hoje): array
{
    $agora = !empty($c['agora']);
    $pct   = $c['plan'] > 0
        ? min(100, $c['real'] / $c['plan'] * 100)
        : ($c['real'] > 0 && !$agora ? 100 : 0);

    $avisos = [];
    if ($c['aberto'] > 0 && ($c['plan'] > 0 || $c['real'] > 0)) {
        $avisos[] = agTvNum($c['aberto']) . ' em aberto no ERP';
    }
    if ($c['tarde']) {
        $avisos[] = 'Depois da entrega';
    }

    return [
        'tipo'    => $c['tipo'],
        'status'  => $c['status'],
        'agora'   => $agora,
        'pct'     => $pct,
        'qtd'     => agTvQtd($c, $hoje),
        'pedido'  => $c['pedido'] !== '' ? $c['pedido'] : 'OP ' . $c['op'],
        'cliente' => $c['cliente'],
        'avisos'  => $avisos,
    ];
}

// OPs de bag com apontamento aberto agora. Sacaria não tem apontamento.
function agTvOpsAtivas($pg): array
{
    return array_map('intval', array_column(agPgAll($pg, "
        SELECT DISTINCT A.PROD_CODIGO AS op
          FROM OP_ITENS_ATIVIDADES_APONTADAS A
          JOIN PRODUTO P ON P.PRO_CODIGO = A.PRO_CODIGO
         WHERE NOT A.OIAP_EXCLUIDO
           AND TRIM(A.OIAP_STATUS) <> 'C'
           AND A.OIAP_DATA_HORA_FIM IS NULL
           AND P.GRP_CODIGO = " . AG_GRP_BAG . "
    "), 'op'));
}

// Marca os cards de hoje das OPs ativas e cria card para a OP ativa que ainda
// não tem card hoje, como a iniciada ontem ou sem leitura. OP fora de $info é
// de outra empresa.
function agTvComAtivas(array $cards, array $ativas, array $info, string $hoje): array
{
    $semCard = array_fill_keys($ativas, true);
    foreach ($cards as &$c) {
        if ($c['dia'] === $hoje && $c['tipo'] === PROD_TIPO_BAG && isset($semCard[$c['op']])) {
            $c['agora'] = true;
            unset($semCard[$c['op']]);
        }
    }
    unset($c);

    foreach (array_keys($semCard) as $op) {
        if (!isset($info[$op])) {
            continue;
        }
        $cards[] = [
            'dia' => $hoje, 'op' => $op, 'tipo' => PROD_TIPO_BAG,
            'pedido' => $info[$op]['pedido'], 'cliente' => $info[$op]['cliente'],
            'plan' => 0.0, 'real' => 0.0, 'aberto' => 0.0,
            'status' => 'produzido', 'tarde' => false, 'agora' => true,
        ];
    }
    return $cards;
}

function agTvMarco(array $m): array
{
    $pedido = $m['pedido'] !== '' ? $m['pedido'] : 'OP ' . $m['op'];
    return [
        'marco'    => $m['marco'],
        'texto'    => trim(AGTV_MARCOS[$m['marco']] . " {$pedido} {$m['cliente']}"),
        'tipo'     => $m['tipo'] ?? null,
        'iniciada' => $m['iniciada'] ?? null,
        'detalhe'  => $m['detalhe'] ?? '',
    ];
}

// Pedidos da Reunião finalizados no período. Sem FATURADO = 'Y', DATA_FINALIZA é
// só a previsão de saída: há pedidos com data futura e OP ainda aberta.
function agTvBuscarFinalizados(PDO $db, $pg, string $ini, string $fim): array
{
    $ops = array_map('intval', $db->query('SELECT PROD_CODIGO FROM PCP_PROGRAMACAO')->fetchAll(PDO::FETCH_COLUMN));
    if (!$ops) {
        return [];
    }
    return agPgAll($pg, "
        SELECT PR.PROD_CODIGO AS op, TRIM(PR.VENDA_REF) AS pedido, V.DATA_FINALIZA::date AS dia
          FROM PRODUCAO PR
          JOIN VENDAS V ON V.EMP_CODIGO = PR.EMP_CODIGO AND V.VEN_COD_PEDIDO::TEXT = TRIM(PR.VENDA_REF)
         WHERE PR.EMP_CODIGO = 1
           AND PR.PROD_CODIGO = ANY(\$1::int[])
           AND TRIM(V.FATURADO) = 'Y'
           AND V.DATA_FINALIZA >= \$2::date AND V.DATA_FINALIZA < \$3::date + 1
         ORDER BY 3, 2, 1
    ", ['{' . implode(',', array_unique($ops)) . '}', $ini, $fim]);
}

// Um marcador por pedido, mesmo com mais de uma OP. $info vem de agOpsInfo.
function agTvMarcosFinalizados(array $linhas, array $info): array
{
    $pedidos = [];
    foreach ($linhas as $l) {
        $op = (int) $l['op'];
        $k  = "{$l['dia']}|{$l['pedido']}";
        $pedidos[$k] ??= ['dia' => $l['dia'], 'pedido' => $l['pedido'], 'op' => $op,
                          'cliente' => $info[$op]['cliente'] ?? '', 'tipos' => []];
        $pedidos[$k]['tipos'] += $info[$op]['tipos'] ?? [];
    }

    $marcos = [];
    foreach ($pedidos as $p) {
        $tipos = array_keys($p['tipos']);
        sort($tipos);
        $marcos[$p['dia']][] = [
            'marco'    => 'finalizado',
            'pedido'   => $p['pedido'],
            'cliente'  => $p['cliente'],
            'op'       => $p['op'],
            'tipo'     => count($tipos) === 1 ? $tipos[0] : null,
            'iniciada' => true,
            'detalhe'  => implode(' e ', array_map(static fn ($t) => AG_TIPO_NOME[$t], $tipos)),
        ];
    }
    return $marcos;
}

// Dias passados seguem só com o agendado: produção fora da agenda aparece no
// dia de hoje, para o calendário não lotar com o histórico do mês.
function agTvMostra(array $c, string $hoje): bool
{
    return $c['plan'] > 0
        || ($c['dia'] === $hoje && (!empty($c['agora']) || $c['real'] > 0 || $c['aberto'] > 0));
}

// Grade do mês em semanas de 7 dias, pronta para desenhar.
function agTvPainel(array $d): array
{
    $porDia = [];
    $temAgenda = false;
    foreach ($d['cards'] as $c) {
        if (agTvMostra($c, $d['hoje'])) {
            $porDia[$c['dia']][] = $c;
            $temAgenda = $temAgenda || $c['plan'] > 0;
        }
    }

    $semanas = [[]];
    $fim     = new DateTimeImmutable($d['fim']);
    for ($dt = new DateTimeImmutable($d['ini']); $dt <= $fim; $dt = $dt->modify('+1 day')) {
        $dia   = $dt->format('Y-m-d');
        $cards = $porDia[$dia] ?? [];
        usort($cards, static fn ($a, $b) =>
            [$a['tipo'], $b['real'] + $b['plan']] <=> [$b['tipo'], $a['real'] + $a['plan']]);

        $soma = [PROD_TIPO_BAG => 0.0, PROD_TIPO_SACARIA => 0.0];
        foreach ($cards as $c) {
            $soma[$c['tipo']] += $c['plan'];
        }

        if (count(end($semanas)) === 7) {
            $semanas[] = [];
        }
        $semanas[array_key_last($semanas)][] = [
            'num'       => (int) $dt->format('j'),
            'hoje'      => $dia === $d['hoje'],
            'fds'       => (int) $dt->format('N') >= 6,
            'outro_mes' => !str_starts_with($dia, $d['mes']),
            'soma_bag'  => $soma[PROD_TIPO_BAG] > 0 ? agTvNum($soma[PROD_TIPO_BAG]) : '',
            'soma_sac'  => $soma[PROD_TIPO_SACARIA] > 0 ? agTvNum($soma[PROD_TIPO_SACARIA]) : '',
            'marcos'    => array_map('agTvMarco', $d['marcos'][$dia] ?? []),
            'cards'     => array_map(static fn ($c) => agTvCard($c, $d['hoje']), $cards),
        ];
    }

    return [
        'mes'        => $d['mes'],
        'mes_titulo' => agTvMesTitulo($d['mes']),
        'hoje'       => $d['hoje'],
        'tem_agenda' => $temAgenda,
        'semanas'    => $semanas,
    ];
}

// Calendário fictício para apresentação (?demo=1). Passa pelas mesmas regras do
// painel, mas não consulta nem grava nada; a página o identifica na tela.
function agTvDemo(string $mes): array
{
    $clientes = ['Agro Exemplo Ltda', 'Fazenda Modelo', 'Cooperativa Demonstração', 'Sementes Amostra',
                 'Café Fictício', 'Insumos Teste', 'Fertilizantes Protótipo'];
    $hoje = date('Y-m-d');
    [$ini, $fim] = agGrade($mes);

    $cards = [];
    $n = 0;
    for ($dt = new DateTimeImmutable($ini), $i = 0; $dt <= new DateTimeImmutable($fim); $dt = $dt->modify('+1 day'), $i++) {
        if ((int) $dt->format('N') >= 6) {
            continue;
        }
        $dia = $dt->format('Y-m-d');
        for ($k = 0, $qtdDia = 2 + $i % 2; $k < $qtdDia; $k++, $n++) {
            $tipo = $k === 2 ? PROD_TIPO_SACARIA : PROD_TIPO_BAG;
            $plan = (float) ($tipo === PROD_TIPO_BAG ? 80 + ($n * 37) % 140 : 4000 + ($n * 700) % 6000);
            $real = match (true) {
                $dia > $hoje   => 0.0,
                $dia === $hoje => round($plan * .45),
                $n % 7 === 0   => round($plan * .7),
                default        => $plan,
            };
            $cards[] = [
                'dia' => $dia, 'op' => 9000 + $n, 'tipo' => $tipo, 'pedido' => (string) (7000 + $n),
                'cliente' => $clientes[$n % count($clientes)], 'plan' => $plan, 'real' => $real, 'aberto' => 0.0,
                'status' => $real >= $plan ? 'cumprido' : ($dia < $hoje ? 'abaixo' : 'agendado'),
                'tarde'  => $dia > $hoje && $n % 17 === 0,
                'agora'  => $dia === $hoje && $k === 0,
            ];
        }
    }
    $cards[] = [
        'dia' => $hoje, 'op' => 8999, 'tipo' => PROD_TIPO_BAG, 'pedido' => '7999', 'cliente' => $clientes[0],
        'plan' => 0.0, 'real' => 35.0, 'aberto' => 0.0, 'status' => 'produzido', 'tarde' => false, 'agora' => true,
    ];

    $marcos = [
        $hoje => [
            ['marco' => 'inicio', 'pedido' => '7999', 'cliente' => $clientes[0], 'op' => 8999,
             'tipo' => PROD_TIPO_BAG, 'iniciada' => true, 'detalhe' => agMarcoDetalhe(PROD_TIPO_BAG, true)],
            ['marco' => 'inicio', 'pedido' => '7998', 'cliente' => $clientes[1], 'op' => 8998,
             'tipo' => PROD_TIPO_SACARIA, 'iniciada' => false, 'detalhe' => agMarcoDetalhe(PROD_TIPO_SACARIA, false)],
        ],
        date('Y-m-d', strtotime($hoje . ' -1 weekday')) => [
            ['marco' => 'finalizado', 'pedido' => '7001', 'cliente' => $clientes[1], 'op' => 9001,
             'tipo' => PROD_TIPO_BAG, 'iniciada' => true, 'detalhe' => AG_TIPO_NOME[PROD_TIPO_BAG]],
        ],
    ];

    return ['demo' => true] + agTvPainel(['mes' => $mes, 'hoje' => $hoje, 'ini' => $ini, 'fim' => $fim, 'cards' => $cards, 'marcos' => $marcos]);
}

// Sem conexão com o ERP agMontar segue com a produção zerada; na TV isso
// precisa ser erro. Falha de consulta já vem como exceção de agPgAll.
function agTvBuscar(PDO $pdo, $pg, string $mes): array
{
    if (!$pg) {
        throw new AgTvErro('erp_indisponivel', 'Sem conexão com o ERP.');
    }
    $d      = agMontar($pdo, $pg, $mes);
    $ativas = agTvOpsAtivas($pg);
    $d['cards'] = agTvComAtivas($d['cards'], $ativas, agOpsInfo($pg, $ativas), $d['hoje']);

    $finalizados = agTvBuscarFinalizados($pdo, $pg, $d['ini'], $d['fim']);
    $info = agOpsInfo($pg, array_column($finalizados, 'op'));
    foreach (agTvMarcosFinalizados($finalizados, $info) as $dia => $marcos) {
        $d['marcos'][$dia] = array_merge($d['marcos'][$dia] ?? [], $marcos);
    }
    return agTvPainel($d);
}
