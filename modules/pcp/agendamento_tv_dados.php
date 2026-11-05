<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/agendamento_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Dados do Agendamento na TV (/pcp/tv)
//  Reaproveita agMontar e aplica o critério do modo Tela cheia da página
//  de Agendamento: só os pedidos agendados, sem totais nem fila. Quantidades
//  e textos saem prontos daqui, porque a TV não tem Intl confiável nem deve
//  repetir regra de negócio em JavaScript.
// ══════════════════════════════════════════════════════════════

const AGTV_UNIDADE = [PROD_TIPO_BAG => 'bags', PROD_TIPO_SACARIA => 'sac.'];
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

// Mesmas regras de htmlCard em agendamento.php, para um card com previsto.
function agTvCard(array $c, string $hoje): array
{
    $un      = AGTV_UNIDADE[$c['tipo']];
    $passado = $c['dia'] < $hoje;
    $qtd = ($c['real'] > 0 || $passado)
        ? agTvNum($c['real']) . " {$un} de " . agTvNum($c['plan'])
        : agTvNum($c['plan']) . " {$un} prev.";

    $avisos = [];
    if ($c['aberto'] > 0) {
        $avisos[] = agTvNum($c['aberto']) . ' em aberto no ERP';
    }
    if ($c['tarde']) {
        $avisos[] = 'Depois da entrega';
    }

    return [
        'tipo'    => $c['tipo'],
        'status'  => $c['status'],
        'pct'     => min(100, $c['real'] / $c['plan'] * 100),
        'qtd'     => $qtd,
        'pedido'  => $c['pedido'] !== '' ? $c['pedido'] : 'OP ' . $c['op'],
        'cliente' => $c['cliente'],
        'avisos'  => $avisos,
    ];
}

// Grade do mês em semanas de 7 dias, pronta para desenhar.
function agTvPainel(array $d): array
{
    $porDia = [];
    foreach ($d['cards'] as $c) {
        if ($c['plan'] > 0) {
            $porDia[$c['dia']][] = $c;
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
            'cards'     => array_map(static fn ($c) => agTvCard($c, $d['hoje']), $cards),
        ];
    }

    return [
        'mes'        => $d['mes'],
        'mes_titulo' => agTvMesTitulo($d['mes']),
        'hoje'       => $d['hoje'],
        'tem_agenda' => (bool) $porDia,
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
            ];
        }
    }

    return ['demo' => true] + agTvPainel(['mes' => $mes, 'hoje' => $hoje, 'ini' => $ini, 'fim' => $fim, 'cards' => $cards]);
}

// Sem conexão com o ERP agMontar segue com a produção zerada; na TV isso
// precisa ser erro. Falha de consulta já vem como exceção de agPgAll.
function agTvBuscar(PDO $pdo, $pg, string $mes): array
{
    if (!$pg) {
        throw new AgTvErro('erp_indisponivel', 'Sem conexão com o ERP.');
    }
    return agTvPainel(agMontar($pdo, $pg, $mes));
}
