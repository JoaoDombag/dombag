<?php

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Relatórios (PDF/XLSX) gerados pelo chat com IA
//
//  Garantia central: os NÚMEROS do relatório nunca são digitados pela IA.
//  Cada consulta feita no chat é guardada como uma "fonte" (F1, F2...) da
//  conversa e do usuário; tabelas, gráficos e indicadores do relatório apenas
//  APONTAM para essas fontes e o servidor copia os valores delas. A IA escreve
//  só título, período e textos.
//
//  Arquivos: nome aleatório, pasta fora da raiz pública, link com token
//  aleatório que expira em 24h e só abre para o usuário que gerou.
// ══════════════════════════════════════════════════════════════════════════════

use Dompdf\Dompdf;
use Dompdf\Options;
use Shuchkin\SimpleXLSXGen;

const CHAT_REL_VALIDADE_HORAS = 24;
const CHAT_FONTE_VALIDADE_DIAS = 7;

// Paleta categórica validada (dataviz: luminosidade, croma, daltonismo) para
// fundo claro, em ordem fixa. Contraste < 3:1 em 3 cores → por isso a pizza
// sempre tem legenda com valor e %, e o relatório traz a tabela.
const CHAT_CORES_SERIE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7'];
const CHAT_COR_TEXTO   = '#16233d';
const CHAT_COR_MUTED   = '#5b6b85';
const CHAT_COR_GRADE   = '#e3e7ee';

// Colunas de cada fonte: chave => [rótulo, tipo]
// tipos: texto · codigo (nº sem separador) · inteiro · numero · moeda · data
const CHAT_FONTE_DEF = [
    'listar_pedidos' => ['linhas' => 'pedidos', 'colunas' => [
        'pedido' => ['Pedido', 'codigo'], 'emissao' => ['Emissão', 'data'], 'finalizacao' => ['Finalização', 'data'],
        'operacao' => ['Operação', 'texto'], 'situacao' => ['Situação', 'texto'], 'cliente' => ['Cliente', 'texto'],
        'cidade' => ['Cidade', 'texto'], 'representante' => ['Representante', 'texto'], 'valor' => ['Valor (R$)', 'moeda'],
    ]],
    'detalhe_pedido' => ['linhas' => 'itens', 'colunas' => [
        'codigo' => ['Código', 'codigo'], 'produto' => ['Produto', 'texto'], 'quantidade' => ['Quantidade', 'numero'],
        'unidade' => ['Un.', 'texto'], 'valor_unitario' => ['Valor unit. (R$)', 'moeda'], 'valor_total' => ['Valor total (R$)', 'moeda'],
        'entrega_prevista' => ['Entrega prevista', 'data'], 'quantidade_expedida' => ['Qtd. expedida', 'numero'],
    ]],
    'resumo_faturamento' => ['linhas' => 'grupos', 'colunas' => [
        'grupo' => ['Grupo', 'texto'], 'pedidos' => ['Pedidos', 'inteiro'], 'pecas' => ['Peças', 'numero'],
        'valor' => ['Valor (R$)', 'moeda'], 'ticket_medio' => ['Ticket médio (R$)', 'moeda'],
    ]],
    'status_producao_pedido' => ['linhas' => 'ops', 'colunas' => [
        'op' => ['OP', 'codigo'], 'situacao' => ['Situação', 'texto'], 'abertura' => ['Abertura', 'data'],
        'entrega_prevista' => ['Entrega prevista', 'data'], 'finalizada_em' => ['Finalizada em', 'data'],
        'quantidade' => ['Quantidade', 'numero'], 'quantidade_faturada' => ['Qtd. faturada', 'numero'],
    ]],
];

// Indicadores (números de destaque) que cada fonte oferece.
const CHAT_FONTE_INDICADORES = [
    'listar_pedidos'         => ['total_pedidos' => ['Pedidos', 'inteiro'], 'valor_total' => ['Valor total', 'moeda']],
    'detalhe_pedido'         => ['valor' => ['Valor do pedido', 'moeda']],
    'resumo_faturamento'     => ['total_pedidos' => ['Pedidos faturados', 'inteiro'], 'total_pecas' => ['Peças', 'numero'],
                                 'total_valor' => ['Valor faturado', 'moeda'], 'ticket_medio' => ['Ticket médio', 'moeda']],
    'status_producao_pedido' => ['total_ops' => ['OPs', 'inteiro'], 'quantidade' => ['Quantidade', 'numero'],
                                 'quantidade_faturada' => ['Qtd. faturada', 'numero']],
];

// ══════════════════════════════════════════════════════════════════════════════
//  Fontes
// ══════════════════════════════════════════════════════════════════════════════

function chatFonteSalvar(PDO $pdo, string $conversa, int $uid, string $ferramenta, array $dados): string
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM CHAT_FONTE WHERE CCV_ID = ? AND USU_CODIGO = ?');
    $st->execute([$conversa, $uid]);
    $id = 'F' . ((int) $st->fetchColumn() + 1);
    $pdo->prepare('INSERT INTO CHAT_FONTE (CCV_ID, USU_CODIGO, CHF_ID, CHF_FERRAMENTA, CHF_DADOS) VALUES (?, ?, ?, ?, ?)')
        ->execute([$conversa, $uid, $id, $ferramenta, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
    return $id;
}

// Só devolve fonte da MESMA conversa e do MESMO usuário.
function chatFonteCarregar(PDO $pdo, string $conversa, int $uid, string $id): ?array
{
    $st = $pdo->prepare('SELECT CHF_FERRAMENTA, CHF_DADOS FROM CHAT_FONTE
                          WHERE CCV_ID = ? AND USU_CODIGO = ? AND CHF_ID = ?
                            AND CHF_CRIADO >= NOW() - INTERVAL ' . CHAT_FONTE_VALIDADE_DIAS . ' DAY');
    $st->execute([$conversa, $uid, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['ferramenta' => $r['CHF_FERRAMENTA'], 'dados' => json_decode($r['CHF_DADOS'], true) ?: []] : null;
}

// Converte o resultado bruto de uma ferramenta em tabela + indicadores.
function chatFonteTabela(string $ferramenta, array $d): array
{
    $def     = CHAT_FONTE_DEF[$ferramenta];
    $colunas = $def['colunas'];
    $linhas  = $d[$def['linhas']] ?? [];
    $ind     = [];
    $origem  = '';

    switch ($ferramenta) {
        case 'listar_pedidos':
            $ind    = ['total_pedidos' => $d['total_pedidos'] ?? 0, 'valor_total' => $d['valor_total'] ?? 0];
            $origem = 'Pedidos emitidos de ' . chatPeriodoBr($d['periodo_emissao'] ?? '');
            if (!empty($d['aviso'])) {
                $origem .= ' — ' . $d['aviso'];
            }
            break;
        case 'detalhe_pedido':
            $ind    = ['valor' => $d['valor'] ?? 0];
            $origem = 'Itens do pedido ' . ($d['pedido'] ?? '') . (isset($d['cliente']) ? ' — ' . $d['cliente'] : '');
            break;
        case 'resumo_faturamento':
            $rot = ['total' => 'Total', 'mes' => 'Mês', 'cliente' => 'Cliente', 'representante' => 'Representante', 'tipo' => 'Tipo'];
            $colunas['grupo'][0] = $rot[$d['agrupado_por'] ?? 'total'] ?? 'Grupo';
            $tp = array_sum(array_column($linhas, 'pedidos'));
            $tv = array_sum(array_column($linhas, 'valor'));
            $ind = ['total_pedidos' => $tp, 'total_pecas' => array_sum(array_column($linhas, 'pecas')),
                    'total_valor' => round($tv, 2), 'ticket_medio' => $tp > 0 ? round($tv / $tp, 2) : 0];
            $origem = 'Faturamento de ' . chatPeriodoBr($d['periodo_faturamento'] ?? '') . '. ' . ($d['observacao'] ?? '');
            break;
        case 'status_producao_pedido':
            $ind = ['total_ops' => count($linhas), 'quantidade' => array_sum(array_column($linhas, 'quantidade')),
                    'quantidade_faturada' => array_sum(array_column($linhas, 'quantidade_faturada'))];
            $origem = 'Ordens de produção do pedido ' . ($d['pedido'] ?? '');
            break;
    }
    return ['colunas' => $colunas, 'linhas' => $linhas, 'indicadores' => $ind, 'origem' => $origem];
}

function chatPeriodoBr(string $p): string
{
    return preg_replace_callback('/(\d{4})-(\d{2})-(\d{2})/', fn($m) => "{$m[3]}/{$m[2]}/{$m[1]}", $p);
}

// ══════════════════════════════════════════════════════════════════════════════
//  Ferramenta gerar_relatorio — definição e validação
// ══════════════════════════════════════════════════════════════════════════════

function chatDefinicaoRelatorio(): array
{
    $colunas = [];
    foreach (CHAT_FONTE_DEF as $f => $d) {
        $colunas[] = "{$f}: " . implode(', ', array_keys($d['colunas']))
                   . ' | indicadores: ' . implode(', ', array_keys(CHAT_FONTE_INDICADORES[$f]));
    }
    $ref = ['type' => 'string', 'pattern' => '^F[0-9]{1,3}$', 'description' => 'Código da fonte (ex.: F1) devolvido por uma consulta desta conversa.'];
    return [
        'name'        => 'gerar_relatorio',
        'description' => "Gera um relatório em PDF ou Excel (xlsx) com os dados JÁ CONSULTADOS nesta conversa. "
            . "Cada consulta devolve um código de fonte (F1, F2...). Tabelas, gráficos e indicadores do relatório apontam para essas fontes "
            . "e os valores são copiados delas pelo servidor — você não envia números. Faça as consultas antes. "
            . "Nos textos (resumo, texto das seções) não cite números que não estejam nas fontes. "
            . "Colunas disponíveis por tipo de consulta — " . implode(' ; ', $colunas) . '. '
            . 'Gráficos: tipo barra (comparar categorias), linha (evolução no tempo) ou pizza (participação, até 6 fatias); '
            . '"rotulos" é a coluna de nomes e "valores" uma coluna numérica. No Excel os gráficos não são desenhados; seus dados viram uma aba. '
            . 'Limite: ' . chatConfig()['rel_dia'] . ' relatórios por dia por usuário.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'titulo'      => ['type' => 'string', 'maxLength' => 120],
                'periodo'     => ['type' => 'string', 'maxLength' => 80, 'description' => 'Período coberto, por extenso (ex.: "Setembro de 2026").'],
                'resumo'      => ['type' => 'string', 'maxLength' => 3000, 'description' => 'Texto de abertura.'],
                'formato'     => ['type' => 'string', 'enum' => ['pdf', 'xlsx']],
                'indicadores' => ['type' => 'array', 'maxItems' => 6, 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['fonte', 'campo'],
                    'properties' => ['fonte' => $ref, 'campo' => ['type' => 'string']],
                ]],
                'secoes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['subtitulo'],
                    'properties' => [
                        'subtitulo' => ['type' => 'string', 'maxLength' => 120],
                        'texto'     => ['type' => 'string', 'maxLength' => 3000],
                        'tabela'    => ['type' => 'object', 'additionalProperties' => false, 'required' => ['fonte'], 'properties' => [
                            'fonte'   => $ref,
                            'colunas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Colunas na ordem desejada (padrão: todas).'],
                        ]],
                        'grafico' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['tipo', 'fonte', 'rotulos', 'valores'], 'properties' => [
                            'tipo'    => ['type' => 'string', 'enum' => ['barra', 'linha', 'pizza']],
                            'fonte'   => $ref,
                            'rotulos' => ['type' => 'string'],
                            'valores' => ['type' => 'string'],
                        ]],
                    ],
                ]],
            ],
            'required'             => ['titulo', 'formato', 'secoes'],
            'additionalProperties' => false,
        ],
        'eagerInputStreaming' => true,
    ];
}

// Texto livre da IA: tira caracteres de controle e limita tamanho.
function chatTextoLimpo($v, int $max): ?string
{
    if (!is_string($v)) {
        return null;
    }
    $v = preg_replace('/[^\P{C}\n\t]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function chatSoChaves(array $a, array $permitidas): ?string
{
    foreach (array_keys($a) as $k) {
        if (!in_array($k, $permitidas, true)) {
            return "Campo não permitido: {$k}.";
        }
    }
    return null;
}

// Valida o pedido da IA e monta o modelo do relatório a partir das fontes.
// Retorna [modelo, erro|null].
function chatMontarRelatorio(PDO $pdo, string $conversa, int $uid, array $in): array
{
    if ($e = chatSoChaves($in, ['titulo', 'periodo', 'resumo', 'formato', 'indicadores', 'secoes'])) {
        return [null, $e];
    }
    $titulo = chatTextoLimpo($in['titulo'] ?? null, 120);
    if (!$titulo) {
        return [null, 'Informe o título do relatório.'];
    }
    $formato = $in['formato'] ?? '';
    if (!in_array($formato, ['pdf', 'xlsx'], true)) {
        return [null, 'formato deve ser "pdf" ou "xlsx".'];
    }
    $secoesIn = $in['secoes'] ?? null;
    if (!is_array($secoesIn) || !array_is_list($secoesIn) || count($secoesIn) < 1 || count($secoesIn) > 10) {
        return [null, 'secoes deve ser uma lista com 1 a 10 seções.'];
    }

    $cache = [];
    $fonte = function ($ref) use ($pdo, $conversa, $uid, &$cache): array {
        if (!is_string($ref) || !preg_match('/^F[0-9]{1,3}$/', $ref)) {
            return [null, 'Código de fonte inválido.'];
        }
        if (!array_key_exists($ref, $cache)) {
            $f = chatFonteCarregar($pdo, $conversa, $uid, $ref);
            $cache[$ref] = $f ? ['ferramenta' => $f['ferramenta']] + chatFonteTabela($f['ferramenta'], $f['dados']) : null;
        }
        return $cache[$ref]
            ? [$cache[$ref], null]
            : [null, "A fonte {$ref} não existe nesta conversa. Faça a consulta antes de gerar o relatório."];
    };

    $modelo = [
        'titulo'      => $titulo,
        'periodo'     => chatTextoLimpo($in['periodo'] ?? '', 80) ?? '',
        'resumo'      => chatTextoLimpo($in['resumo'] ?? '', 3000) ?? '',
        'formato'     => $formato,
        'indicadores' => [],
        'secoes'      => [],
        'fontes'      => [],
    ];

    foreach ((array) ($in['indicadores'] ?? []) as $i) {
        if (!is_array($i) || ($e = chatSoChaves($i, ['fonte', 'campo']))) {
            return [null, $e ?? 'Indicador inválido.'];
        }
        [$f, $erro] = $fonte($i['fonte'] ?? null);
        if ($erro) {
            return [null, $erro];
        }
        $def = CHAT_FONTE_INDICADORES[$f['ferramenta']][$i['campo'] ?? ''] ?? null;
        if (!$def) {
            return [null, "Indicador \"{$i['campo']}\" não existe na fonte {$i['fonte']}. Disponíveis: " . implode(', ', array_keys(CHAT_FONTE_INDICADORES[$f['ferramenta']])) . '.'];
        }
        $modelo['indicadores'][] = ['rotulo' => $def[0], 'tipo' => $def[1], 'valor' => $f['indicadores'][$i['campo']] ?? 0];
        $modelo['fontes'][$i['fonte']] = $f['origem'];
    }
    if (count($modelo['indicadores']) > 6) {
        return [null, 'No máximo 6 indicadores.'];
    }

    foreach ($secoesIn as $n => $s) {
        if (!is_array($s) || ($e = chatSoChaves($s, ['subtitulo', 'texto', 'tabela', 'grafico']))) {
            return [null, 'Seção ' . ($n + 1) . ': ' . ($e ?? 'formato inválido.')];
        }
        $sec = [
            'subtitulo' => chatTextoLimpo($s['subtitulo'] ?? '', 120) ?: 'Seção ' . ($n + 1),
            'texto'     => chatTextoLimpo($s['texto'] ?? '', 3000) ?? '',
            'tabela'    => null,
            'grafico'   => null,
        ];

        if (isset($s['tabela'])) {
            $t = $s['tabela'];
            if (!is_array($t) || ($e = chatSoChaves($t, ['fonte', 'colunas']))) {
                return [null, 'Seção ' . ($n + 1) . ': ' . ($e ?? 'tabela inválida.')];
            }
            [$f, $erro] = $fonte($t['fonte'] ?? null);
            if ($erro) {
                return [null, $erro];
            }
            $cols = $t['colunas'] ?? array_keys($f['colunas']);
            if (!is_array($cols) || !$cols) {
                return [null, 'Seção ' . ($n + 1) . ': colunas inválidas.'];
            }
            foreach ($cols as $c) {
                if (!is_string($c) || !isset($f['colunas'][$c])) {
                    return [null, 'Seção ' . ($n + 1) . ": coluna \"{$c}\" não existe. Disponíveis: " . implode(', ', array_keys($f['colunas'])) . '.'];
                }
            }
            $sec['tabela'] = [
                'colunas' => array_map(fn($c) => ['chave' => $c, 'rotulo' => $f['colunas'][$c][0], 'tipo' => $f['colunas'][$c][1]], array_values(array_unique($cols))),
                'linhas'  => array_map(fn($l) => array_map(fn($c) => $l[$c] ?? null, array_values(array_unique($cols))), $f['linhas']),
                'origem'  => $f['origem'] . " (fonte {$t['fonte']})",
            ];
            $modelo['fontes'][$t['fonte']] = $f['origem'];
        }

        if (isset($s['grafico'])) {
            $g = $s['grafico'];
            if (!is_array($g) || ($e = chatSoChaves($g, ['tipo', 'fonte', 'rotulos', 'valores']))) {
                return [null, 'Seção ' . ($n + 1) . ': ' . ($e ?? 'gráfico inválido.')];
            }
            if (!in_array($g['tipo'] ?? '', ['barra', 'linha', 'pizza'], true)) {
                return [null, 'Seção ' . ($n + 1) . ': tipo de gráfico deve ser barra, linha ou pizza.'];
            }
            [$f, $erro] = $fonte($g['fonte'] ?? null);
            if ($erro) {
                return [null, $erro];
            }
            $rot = $g['rotulos'] ?? '';
            $val = $g['valores'] ?? '';
            if (!isset($f['colunas'][$rot])) {
                return [null, 'Seção ' . ($n + 1) . ": coluna de rótulos \"{$rot}\" não existe."];
            }
            if (!in_array($f['colunas'][$val][1] ?? '', ['inteiro', 'numero', 'moeda'], true)) {
                return [null, 'Seção ' . ($n + 1) . ": a coluna de valores \"{$val}\" precisa ser numérica."];
            }
            $rotulos = array_map(fn($l) => chatFormatar($l[$rot] ?? '', $f['colunas'][$rot][1]), $f['linhas']);
            $valores = array_map(fn($l) => (float) ($l[$val] ?? 0), $f['linhas']);
            $limite  = ['barra' => 20, 'linha' => 60, 'pizza' => 6][$g['tipo']];
            if (count($valores) > $limite && $g['tipo'] !== 'pizza') {
                return [null, 'Seção ' . ($n + 1) . ": gráfico de {$g['tipo']} aceita no máximo {$limite} pontos; a fonte tem " . count($valores) . '. Use uma tabela ou agrupe mais.'];
            }
            $sec['grafico'] = [
                'tipo' => $g['tipo'], 'rotulos' => $rotulos, 'valores' => $valores,
                'tipo_valor' => $f['colunas'][$val][1], 'rotulo_valor' => $f['colunas'][$val][0],
                'origem' => $f['origem'] . " (fonte {$g['fonte']})",
            ];
            $modelo['fontes'][$g['fonte']] = $f['origem'];
        }
        $modelo['secoes'][] = $sec;
    }

    if (!$modelo['fontes']) {
        return [null, 'O relatório precisa usar pelo menos uma fonte de dados (tabela, gráfico ou indicador). Faça a consulta primeiro.'];
    }

    // Sem dados: nenhuma tabela/gráfico com linhas e nenhum indicador diferente de zero.
    $temDados = false;
    foreach ($modelo['secoes'] as $s) {
        $temDados = $temDados || !empty($s['tabela']['linhas']) || !empty($s['grafico']['valores']);
    }
    foreach ($modelo['indicadores'] as $i) {
        $temDados = $temDados || (float) $i['valor'] != 0.0;
    }
    $modelo['sem_dados'] = !$temDados;

    return [$modelo, null];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Formatação pt-BR
// ══════════════════════════════════════════════════════════════════════════════

function chatFormatar($v, string $tipo): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return match ($tipo) {
        'moeda'   => 'R$ ' . number_format((float) $v, 2, ',', '.'),
        'inteiro' => number_format((float) $v, 0, ',', '.'),
        'numero'  => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ','),
        'data'    => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $v, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : (string) $v,
        'codigo'  => (string) $v,
        default   => (string) $v,
    };
}

// Versão curta para rótulos de gráfico (R$ 1,2 mi · 350 mil).
function chatFormatarCurto(float $v, string $tipo): string
{
    $abs = abs($v);
    [$div, $suf] = $abs >= 1e6 ? [1e6, ' mi'] : ($abs >= 1e4 ? [1e3, ' mil'] : [1, '']);
    $dec = $div > 1 ? 1 : ($tipo === 'moeda' && $v != floor($v) ? 2 : 0);
    $txt = number_format($v / $div, $dec, ',', '.');
    $txt = $div > 1 ? preg_replace('/,0$/', '', $txt) : $txt;
    return ($tipo === 'moeda' ? 'R$ ' : '') . $txt . $suf;
}

// ══════════════════════════════════════════════════════════════════════════════
//  Gráficos em SVG (gerados no servidor, embutidos no PDF)
// ══════════════════════════════════════════════════════════════════════════════

function chatSvgEsc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function chatSvgTrunca(string $s, int $n): string
{
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

function chatGraficoSvg(array $g): string
{
    return match ($g['tipo']) {
        'barra' => chatSvgBarras($g['rotulos'], $g['valores'], $g['tipo_valor']),
        'linha' => chatSvgLinha($g['rotulos'], $g['valores'], $g['tipo_valor']),
        'pizza' => chatSvgPizza($g['rotulos'], $g['valores'], $g['tipo_valor']),
    };
}

function chatPassoRedondo(float $bruto): float
{
    $exp = 10 ** floor(log10(abs($bruto)));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $exp >= abs($bruto)) {
            return $m * $exp;
        }
    }
    return 10 * $exp;
}

// Barras horizontais (nomes longos de clientes cabem à esquerda), uma cor só.
function chatSvgBarras(array $rot, array $val, string $tipo): string
{
    $n = count($val);
    if (!$n) {
        return '';
    }
    $W = 680; $esq = 210; $dir = 90; $linhaH = 24; $barH = 14; $topo = 8;
    $H = $topo * 2 + $n * $linhaH;
    $min = min(0, min($val)); $max = max(0, max($val));
    $esc = ($max - $min) ?: 1;
    $x0  = $esq + (0 - $min) / $esc * ($W - $esq - $dir);
    $sx  = fn($v) => $esq + ($v - $min) / $esc * ($W - $esq - $dir);
    $cor = CHAT_CORES_SERIE[0];

    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$W}' height='{$H}' viewBox='0 0 {$W} {$H}' font-family='DejaVu Sans'>";
    $s .= "<line x1='{$x0}' y1='{$topo}' x2='{$x0}' y2='" . ($H - $topo) . "' stroke='#9aa5b5' stroke-width='1'/>";
    foreach ($val as $i => $v) {
        $y  = $topo + $i * $linhaH + ($linhaH - $barH) / 2;
        $x1 = $sx($v);
        $xa = min($x0, $x1); $w = max(abs($x1 - $x0), 1);
        $r  = min(4, $w / 2);
        // ponta arredondada só no lado do dado; base reta no eixo
        if ($v >= 0) {
            $d = sprintf('M%.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Z',
                $xa, $y, $xa + $w - $r, $xa + $w, $y, $xa + $w, $y + $r, $y + $barH - $r, $xa + $w, $y + $barH, $xa + $w - $r, $y + $barH, $xa);
        } else {
            $d = sprintf('M%.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Z',
                $xa + $w, $y, $xa + $r, $xa, $y, $xa, $y + $r, $y + $barH - $r, $xa, $y + $barH, $xa + $r, $y + $barH, $xa + $w);
        }
        $s .= "<path d='{$d}' fill='{$cor}'/>";
        $ty = $y + $barH - 3;
        $s .= "<text x='" . ($esq - 8) . "' y='{$ty}' font-size='10' fill='" . CHAT_COR_TEXTO . "' text-anchor='end'>" . chatSvgEsc(chatSvgTrunca((string) $rot[$i], 32)) . '</text>';
        $tx = $v >= 0 ? $xa + $w + 5 : $xa - 5;
        $s .= "<text x='{$tx}' y='{$ty}' font-size='9.5' fill='" . CHAT_COR_MUTED . "' text-anchor='" . ($v >= 0 ? 'start' : 'end') . "'>" . chatSvgEsc(chatFormatarCurto($v, $tipo)) . '</text>';
    }
    return $s . '</svg>';
}

// Linha: evolução no tempo, 2px, marcadores de 8px, rótulo só no último e no maior ponto.
function chatSvgLinha(array $rot, array $val, string $tipo): string
{
    $n = count($val);
    if (!$n) {
        return '';
    }
    $W = 680; $H = 260; $esq = 70; $dir = 30; $topo = 22; $base = 40;
    // Escala com marcas "redondas" (1, 2, 2,5, 5 × 10ⁿ) começando em zero.
    $passoY = chatPassoRedondo((max(max($val), 0) - min(min($val), 0)) / 4 ?: 1);
    $min = floor(min(0, min($val)) / $passoY) * $passoY;
    $max = ceil(max(max($val), 0) / $passoY) * $passoY;
    if ($max <= $min) {
        $max = $min + $passoY;
    }
    $sx = fn($i) => $n === 1 ? ($esq + ($W - $esq - $dir) / 2) : $esq + $i / ($n - 1) * ($W - $esq - $dir);
    $sy = fn($v) => $H - $base - ($v - $min) / ($max - $min) * ($H - $base - $topo);
    $cor = CHAT_CORES_SERIE[0];

    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$W}' height='{$H}' viewBox='0 0 {$W} {$H}' font-family='DejaVu Sans'>";
    for ($v = $min; $v <= $max + $passoY / 2; $v += $passoY) {
        $y = $sy($v);
        $s .= "<line x1='{$esq}' y1='{$y}' x2='" . ($W - $dir) . "' y2='{$y}' stroke='" . (abs($v) < 1e-9 ? '#9aa5b5' : CHAT_COR_GRADE) . "' stroke-width='1'/>";
        $s .= "<text x='" . ($esq - 6) . "' y='" . ($y + 3) . "' font-size='8.5' fill='" . CHAT_COR_MUTED . "' text-anchor='end'>" . chatSvgEsc(chatFormatarCurto($v, $tipo)) . '</text>';
    }
    $passo = max(1, (int) ceil($n / 12));      // no máximo ~12 rótulos no eixo X
    foreach ($rot as $i => $r) {
        if ($i % $passo === 0 || $i === $n - 1) {
            $s .= "<text x='" . $sx($i) . "' y='" . ($H - $base + 16) . "' font-size='8.5' fill='" . CHAT_COR_MUTED . "' text-anchor='middle'>" . chatSvgEsc(chatSvgTrunca((string) $r, 12)) . '</text>';
        }
    }
    $pts = [];
    foreach ($val as $i => $v) {
        $pts[] = sprintf('%.1f,%.1f', $sx($i), $sy($v));
    }
    if ($n > 1) {
        $s .= "<polyline points='" . implode(' ', $pts) . "' fill='none' stroke='{$cor}' stroke-width='2' stroke-linejoin='round'/>";
    }
    $iMax = array_keys($val, max($val))[0];
    foreach ($val as $i => $v) {
        $s .= "<circle cx='" . $sx($i) . "' cy='" . $sy($v) . "' r='4' fill='{$cor}' stroke='#ffffff' stroke-width='2'/>";
        if ($i === $n - 1 || $i === $iMax) {
            $s .= "<text x='" . $sx($i) . "' y='" . ($sy($v) - 9) . "' font-size='9' fill='" . CHAT_COR_TEXTO . "' text-anchor='middle'>" . chatSvgEsc(chatFormatarCurto($v, $tipo)) . '</text>';
        }
    }
    return $s . '</svg>';
}

// Pizza: até 6 fatias (o excedente vira "Outros"), separadas por 2px de fundo,
// com legenda sempre visível (rótulo + valor + %) — identidade nunca só pela cor.
function chatSvgPizza(array $rot, array $val, string $tipo): string
{
    $itens = [];
    foreach ($val as $i => $v) {
        if ($v > 0) {
            $itens[] = [(string) $rot[$i], (float) $v];
        }
    }
    if (!$itens) {
        return '';
    }
    usort($itens, fn($a, $b) => $b[1] <=> $a[1]);
    if (count($itens) > 6) {
        $resto  = array_sum(array_column(array_slice($itens, 5), 1));
        $itens  = array_slice($itens, 0, 5);
        $itens[] = ['Outros', $resto];
    }
    $total = array_sum(array_column($itens, 1));
    $W = 680; $H = 240; $cx = 130; $cy = 120; $r = 100;

    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$W}' height='{$H}' viewBox='0 0 {$W} {$H}' font-family='DejaVu Sans'>";
    $ang = -M_PI / 2;
    foreach ($itens as $i => [$nome, $v]) {
        $cor  = CHAT_CORES_SERIE[$i];
        $frac = $v / $total;
        if ($frac >= 0.9999) {
            $s .= "<circle cx='{$cx}' cy='{$cy}' r='{$r}' fill='{$cor}'/>";
        } else {
            $a2 = $ang + $frac * 2 * M_PI;
            $s .= sprintf("<path d='M%.2f %.2f L%.2f %.2f A%d %d 0 %d 1 %.2f %.2f Z' fill='%s' stroke='#ffffff' stroke-width='2'/>",
                $cx, $cy, $cx + $r * cos($ang), $cy + $r * sin($ang), $r, $r, $frac > 0.5 ? 1 : 0,
                $cx + $r * cos($a2), $cy + $r * sin($a2), $cor);
            $ang = $a2;
        }
        $ly = 30 + $i * 32;
        $s .= "<rect x='270' y='" . ($ly - 10) . "' width='12' height='12' rx='2' fill='{$cor}'/>";
        $s .= "<text x='290' y='{$ly}' font-size='10.5' fill='" . CHAT_COR_TEXTO . "'>" . chatSvgEsc(chatSvgTrunca($nome, 40)) . '</text>';
        $s .= "<text x='290' y='" . ($ly + 13) . "' font-size='9' fill='" . CHAT_COR_MUTED . "'>"
            . chatSvgEsc(chatFormatar($v, $tipo) . ' · ' . number_format($frac * 100, 1, ',', '.') . '%') . '</text>';
    }
    return $s . '</svg>';
}

// ══════════════════════════════════════════════════════════════════════════════
//  PDF (dompdf)
// ══════════════════════════════════════════════════════════════════════════════

function chatHtmlTexto(string $t): string
{
    $paras = preg_split('/\n\s*\n/', trim($t)) ?: [];
    return implode('', array_map(fn($p) => '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>', array_filter($paras, 'strlen')));
}

function chatRenderPdf(array $m, string $autor, DateTimeInterface $quando): string
{
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $maxCols = 0;
    foreach ($m['secoes'] as $s) {
        $maxCols = max($maxCols, count($s['tabela']['colunas'] ?? []));
    }
    $paisagem = $maxCols > 6;

    $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><style>
        @page { margin: 92px 36px 58px 36px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: ' . CHAT_COR_TEXTO . '; line-height: 1.4; }
        #cab { position: fixed; top: -74px; left: 0; right: 0; height: 54px; background: #0f172a; border-radius: 6px; }
        #cab table { width: 100%; height: 54px; border-collapse: collapse; }
        #cab td { padding: 0 14px; vertical-align: middle; color: #fff; }
        .logo { font-size: 17pt; font-weight: bold; letter-spacing: -0.5px; line-height: 1; }
        .logo span { color: #3b82f6; }
        .logo-sub { font-size: 5.5pt; color: #94a3b8; letter-spacing: 1.2px; line-height: 1; margin-top: 3px; }
        .cab-dir { text-align: right; font-size: 8pt; color: #cbd5e1; }
        #rod { position: fixed; bottom: -40px; left: 0; right: 0; height: 22px; border-top: 1px solid ' . CHAT_COR_GRADE . '; font-size: 7pt; color: ' . CHAT_COR_MUTED . '; padding-top: 5px; }
        h1 { font-size: 16pt; margin: 0 0 2px; color: #0f172a; }
        .periodo { color: ' . CHAT_COR_MUTED . '; font-size: 9.5pt; margin-bottom: 12px; }
        .resumo { background: #f3f6fb; border-left: 3px solid #1e4fc9; padding: 8px 12px; margin-bottom: 14px; }
        .resumo p, .sec p { margin: 0 0 6px; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 14px; }
        .kpi { border: 1px solid ' . CHAT_COR_GRADE . '; border-radius: 6px; padding: 8px 10px; }
        .kpi .r { font-size: 7.5pt; color: ' . CHAT_COR_MUTED . '; text-transform: uppercase; letter-spacing: .5px; }
        .kpi .v { font-size: 13pt; font-weight: bold; color: #0f172a; margin-top: 2px; }
        h2 { font-size: 11.5pt; color: #0f172a; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid ' . CHAT_COR_GRADE . '; }
        table.dados { width: 100%; border-collapse: collapse; font-size: ' . ($paisagem ? '7.5pt' : '8pt') . '; }
        table.dados th { background: #1e4fc9; color: #fff; text-align: left; padding: 4px 5px; font-weight: bold; }
        table.dados td { padding: 3px 5px; border-bottom: 1px solid ' . CHAT_COR_GRADE . '; }
        table.dados tr.par td { background: #f6f8fc; }
        table.dados .n { text-align: right; white-space: nowrap; }
        .origem { font-size: 7pt; color: ' . CHAT_COR_MUTED . '; margin-top: 3px; }
        .vazio { padding: 10px; background: #fff7e6; border: 1px solid #f5d48a; border-radius: 4px; color: #7a5300; }
        .grafico { margin: 4px 0; text-align: center; }
        .bloco { page-break-inside: avoid; }
    </style></head><body>';

    $html .= '<div id="cab"><table><tr><td><div class="logo">DOM<span>BAG</span></div><div class="logo-sub">DOM BAG LTDA · SISTEMA DE GESTÃO</div></td>'
           . '<td class="cab-dir">' . $e($m['titulo']) . '</td></tr></table></div>';
    $html .= '<div id="rod">Gerado em ' . $quando->format('d/m/Y \à\s H:i') . ' por ' . $e($autor)
           . ' · Dados do ERP consultados pelo Assistente DOMBAG · Uso interno</div>';

    $html .= '<h1>' . $e($m['titulo']) . '</h1>';
    if ($m['periodo'] !== '') {
        $html .= '<div class="periodo">' . $e($m['periodo']) . '</div>';
    }
    if ($m['sem_dados']) {
        $html .= '<div class="vazio"><b>Não há dados</b> para os filtros consultados. As consultas usadas neste relatório não retornaram registros.</div>';
    }
    if ($m['resumo'] !== '') {
        $html .= '<div class="resumo">' . chatHtmlTexto($m['resumo']) . '</div>';
    }
    if ($m['indicadores']) {
        $html .= '<table class="kpis"><tr>';
        foreach ($m['indicadores'] as $i) {
            $html .= '<td class="kpi"><div class="r">' . $e($i['rotulo']) . '</div><div class="v">' . $e(chatFormatar($i['valor'], $i['tipo'])) . '</div></td>';
        }
        $html .= '</tr></table>';
    }

    foreach ($m['secoes'] as $s) {
        // Título + texto + gráfico ficam juntos na mesma página.
        $html .= '<div class="sec"><div class="bloco"><h2>' . $e($s['subtitulo']) . '</h2>';
        if ($s['texto'] !== '') {
            $html .= chatHtmlTexto($s['texto']);
        }
        if ($g = $s['grafico']) {
            $svg = chatGraficoSvg($g);
            if ($svg !== '') {
                // largura fixa (680px do SVG = 18cm) — não estica no modo paisagem
                $html .= '<div class="grafico"><img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="width:18cm"></div>';
                if (!$s['tabela']) {
                    $html .= '<div class="origem">' . $e($g['rotulo_valor'] . ' — ' . $g['origem']) . '</div>';
                }
            } else {
                $html .= '<div class="vazio">Sem dados para o gráfico.</div>';
            }
        }
        $html .= '</div>';
        if ($t = $s['tabela']) {
            if (!$t['linhas']) {
                $html .= '<div class="vazio">Sem dados para este filtro.</div>';
            } else {
                // tabela curta não é partida entre páginas (evita cabeçalho órfão)
                $curta = count($t['linhas']) <= 25;
                $html .= ($curta ? '<div class="bloco">' : '') . '<table class="dados"><thead><tr>';
                foreach ($t['colunas'] as $c) {
                    $num = in_array($c['tipo'], ['inteiro', 'numero', 'moeda'], true);
                    $html .= '<th' . ($num ? ' class="n"' : '') . '>' . $e($c['rotulo']) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($t['linhas'] as $k => $l) {
                    $html .= '<tr' . ($k % 2 ? ' class="par"' : '') . '>';
                    foreach ($t['colunas'] as $j => $c) {
                        $num = in_array($c['tipo'], ['inteiro', 'numero', 'moeda'], true);
                        $html .= '<td' . ($num ? ' class="n"' : '') . '>' . $e(chatFormatar($l[$j], $c['tipo'])) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>' . ($curta ? '</div>' : '');
            }
            $html .= '<div class="origem">' . $e($t['origem']) . '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</body></html>';

    $opt = new Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('isPhpEnabled', false);
    $opt->set('isJavascriptEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('chroot', [dirname(__DIR__, 2) . '/vendor/dompdf/dompdf']);
    $pdf = new Dompdf($opt);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', $paisagem ? 'landscape' : 'portrait');
    $pdf->render();

    // Número de página no rodapé (canto direito), em todas as páginas.
    $canvas = $pdf->getCanvas();
    $fonte  = $pdf->getFontMetrics()->getFont('DejaVu Sans');
    $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 29, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fonte, 7, [0.357, 0.42, 0.522]);

    return $pdf->output();
}

// ══════════════════════════════════════════════════════════════════════════════
//  XLSX (SimpleXLSXGen)
// ══════════════════════════════════════════════════════════════════════════════

// Texto vindo de dados/IA vai como string "crua": sem interpretação de tags
// nem de fórmulas (evita injeção de fórmula tipo =HYPERLINK(...)).
function chatXlsTexto(string $s): string
{
    return "\0" . $s;
}

function chatXlsValor($v, string $tipo)
{
    if ($v === null || $v === '') {
        return null;
    }
    return match ($tipo) {
        'moeda'   => '<style nf="#,##0.00">' . number_format((float) $v, 2, '.', '') . '</style>',
        'numero'  => '<style nf="#,##0.##">' . (float) $v . '</style>',
        'inteiro' => (int) $v,
        'codigo'  => is_numeric($v) ? (int) $v : chatXlsTexto((string) $v),
        'data'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : chatXlsTexto((string) $v),
        default   => chatXlsTexto((string) $v),
    };
}

function chatXlsCabecalho(string $s): string
{
    return '<style bgcolor="#1E4FC9" color="#FFFFFF"><b>' . htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</b></style>';
}

function chatRenderXlsx(array $m, string $autor, DateTimeInterface $quando): string
{
    $resumo = [
        ['<style font-size="16"><b>' . htmlspecialchars($m['titulo'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</b></style>'],
    ];
    if ($m['periodo'] !== '') {
        $resumo[] = [chatXlsTexto('Período'), chatXlsTexto($m['periodo'])];
    }
    $resumo[] = [chatXlsTexto('Gerado em'), chatXlsTexto($quando->format('d/m/Y H:i') . ' por ' . $autor)];
    $resumo[] = [];
    if ($m['sem_dados']) {
        $resumo[] = ['<b>Não há dados para os filtros consultados.</b>'];
        $resumo[] = [];
    }
    if ($m['resumo'] !== '') {
        $resumo[] = ['<b>Resumo</b>'];
        foreach (preg_split('/\n+/', $m['resumo']) as $p) {
            $resumo[] = [chatXlsTexto($p)];
        }
        $resumo[] = [];
    }
    if ($m['indicadores']) {
        $resumo[] = [chatXlsCabecalho('Indicador'), chatXlsCabecalho('Valor')];
        foreach ($m['indicadores'] as $i) {
            $resumo[] = [chatXlsTexto($i['rotulo']), chatXlsValor($i['valor'], $i['tipo'])];
        }
        $resumo[] = [];
    }

    $abas  = [];
    $nomes = ['Resumo' => true];
    $nomeAba = function (string $base) use (&$nomes): string {
        $base = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $base)) ?: 'Dados';
        $base = mb_substr($base, 0, 28);
        $nome = $base;
        for ($k = 2; isset($nomes[mb_strtolower($nome)]) || isset($nomes[$nome]); $k++) {
            $nome = mb_substr($base, 0, 26) . " {$k}";
        }
        $nomes[$nome] = $nomes[mb_strtolower($nome)] = true;
        return $nome;
    };

    foreach ($m['secoes'] as $s) {
        if ($s['texto'] !== '' || (!$s['tabela'] && !$s['grafico'])) {
            $resumo[] = ['<b>' . htmlspecialchars($s['subtitulo'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</b>'];
            foreach (preg_split('/\n+/', $s['texto']) as $p) {
                if ($p !== '') {
                    $resumo[] = [chatXlsTexto($p)];
                }
            }
            $resumo[] = [];
        }
        // uma aba por tabela
        if ($t = $s['tabela']) {
            $linhas = [array_map(fn($c) => chatXlsCabecalho($c['rotulo']), $t['colunas'])];
            foreach ($t['linhas'] as $l) {
                $linhas[] = array_map(fn($j) => chatXlsValor($l[$j], $t['colunas'][$j]['tipo']), array_keys($t['colunas']));
            }
            if (!$t['linhas']) {
                $linhas[] = [chatXlsTexto('Sem dados para este filtro.')];
            }
            $linhas[] = [];
            $linhas[] = [chatXlsTexto('Origem: ' . $t['origem'])];
            $abas[] = [$nomeAba($s['subtitulo']), $linhas, count($t['colunas'])];
        }
        // gráfico: no Excel vira a aba com os dados dele
        if ($g = $s['grafico']) {
            if (!$t) {
                $linhas = [[chatXlsCabecalho('Rótulo'), chatXlsCabecalho($g['rotulo_valor'])]];
                foreach ($g['rotulos'] as $j => $r) {
                    $linhas[] = [chatXlsTexto((string) $r), chatXlsValor($g['valores'][$j], $g['tipo_valor'])];
                }
                $linhas[] = [];
                $linhas[] = [chatXlsTexto('Origem: ' . $g['origem'] . ' — dados do gráfico (' . $g['tipo'] . ')')];
                $abas[] = [$nomeAba($s['subtitulo']), $linhas, 2];
            }
        }
    }

    $x = SimpleXLSXGen::fromArray($resumo, 'Resumo');
    $x->setColWidth(1, 28);
    $x->setColWidth(2, 40);
    foreach ($abas as [$nome, $linhas, $ncol]) {
        $x->addSheet($linhas, $nome);
        $x->freezePanes('A2');
    }
    $x->setTitle($m['titulo']);
    $x->setAuthor('DOMBAG');
    $x->setCompany('Dom Bag Ltda');
    return (string) $x;
}

// ══════════════════════════════════════════════════════════════════════════════
//  Armazenamento e links temporários
// ══════════════════════════════════════════════════════════════════════════════

function chatDirRelatorios(): string
{
    $dir = dombagConfig('CHAT_REPORTS_DIR') ?: dirname(__DIR__, 3) . '/dombag_storage/relatorios';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    // Defesa extra caso a pasta acabe sob uma raiz web.
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return rtrim($dir, '/\\');
}

function chatSlug(string $s): string
{
    // mapa fixo: iconv//TRANSLIT varia por sistema (no Windows "ó" vira "'o")
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ù' => 'u', 'ç' => 'c', 'ñ' => 'n',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ç' => 'C', 'º' => 'o', 'ª' => 'a',
    ]);
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return substr($s ?: 'relatorio', 0, 60);
}

function chatSalvarRelatorio(PDO $pdo, int $uid, string $conversa, string $bytes, string $formato, string $titulo): array
{
    $token   = bin2hex(random_bytes(16));
    $arquivo = bin2hex(random_bytes(16)) . '.' . $formato;
    $dir     = chatDirRelatorios();
    if (file_put_contents($dir . '/' . $arquivo, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('não foi possível gravar o relatório');
    }
    @chmod($dir . '/' . $arquivo, 0600);

    $nome = chatSlug($titulo) . '-' . date('Ymd-Hi') . '.' . $formato;
    $pdo->prepare('INSERT INTO CHAT_RELATORIO (CHR_TOKEN, USU_CODIGO, CCV_ID, CHR_ARQUIVO, CHR_NOME, CHR_FORMATO, CHR_EXPIRA)
                   VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL ' . CHAT_REL_VALIDADE_HORAS . ' HOUR)')
        ->execute([$token, $uid, $conversa, $arquivo, $nome, $formato]);

    return ['url' => '/api/chat/relatorio/' . $token, 'nome' => $nome, 'formato' => $formato];
}

// Devolve [caminho, nome, formato] só se o token for do usuário e não expirou.
function chatRelatorioParaDownload(PDO $pdo, string $token, int $uid): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || $uid <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT CHR_ARQUIVO, CHR_NOME, CHR_FORMATO FROM CHAT_RELATORIO
                          WHERE CHR_TOKEN = ? AND USU_CODIGO = ? AND CHR_EXPIRA > NOW()');
    $st->execute([$token, $uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !preg_match('/^[a-f0-9]{32}\.(pdf|xlsx)$/', $r['CHR_ARQUIVO'])) {
        return null;
    }
    $caminho = chatDirRelatorios() . '/' . $r['CHR_ARQUIVO'];
    return is_file($caminho) ? [$caminho, $r['CHR_NOME'], $r['CHR_FORMATO']] : null;
}

// Apaga relatórios expirados (arquivo + registro) e fontes antigas.
function chatLimparExpirados(PDO $pdo): void
{
    try {
        $dir = chatDirRelatorios();
        $exp = $pdo->query('SELECT CHR_TOKEN, CHR_ARQUIVO FROM CHAT_RELATORIO WHERE CHR_EXPIRA <= NOW() LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($exp as $r) {
            if (preg_match('/^[a-f0-9]{32}\.(pdf|xlsx)$/', $r['CHR_ARQUIVO'])) {
                @unlink($dir . '/' . $r['CHR_ARQUIVO']);
            }
            $pdo->prepare('DELETE FROM CHAT_RELATORIO WHERE CHR_TOKEN = ?')->execute([$r['CHR_TOKEN']]);
        }
        $pdo->exec('DELETE FROM CHAT_FONTE WHERE CHF_CRIADO < NOW() - INTERVAL ' . CHAT_FONTE_VALIDADE_DIAS . ' DAY');
    } catch (Throwable $e) {
        error_log('[CHAT] limpeza: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  Execução da ferramenta
// ══════════════════════════════════════════════════════════════════════════════

function chatFerGerarRelatorio(PDO $pdo, array $ctx, string $conversa, array $input): array
{
    if ($erro = chatVerificarLimite($pdo, $ctx['uid'], $ctx['ip'] ?? '', 'relatorio')) {
        return chatErroFerramenta($erro . ' Avise o usuário.');
    }
    [$modelo, $erro] = chatMontarRelatorio($pdo, $conversa, $ctx['uid'], $input);
    if ($erro) {
        return chatErroFerramenta($erro);
    }

    $agora = new DateTimeImmutable();
    $bytes = $modelo['formato'] === 'pdf'
        ? chatRenderPdf($modelo, $ctx['nome'], $agora)
        : chatRenderXlsx($modelo, $ctx['nome'], $agora);
    $arq = chatSalvarRelatorio($pdo, $ctx['uid'], $conversa, $bytes, $modelo['formato'], $modelo['titulo']);
    chatRegistrarUso($pdo, $ctx['uid'], $ctx['ip'] ?? '', 'relatorio');
    chatLimparExpirados($pdo);

    return [
        'ok'       => true,
        'conteudo' => json_encode([
            'gerado'   => true,
            'arquivo'  => $arq['nome'],
            'sem_dados'=> $modelo['sem_dados'],
            'instrucao'=> 'O botão de download já apareceu para o usuário e vale por 24 horas. Não escreva link nem endereço; apenas confirme em uma frase.',
        ], JSON_UNESCAPED_UNICODE),
        'linhas'   => 0,
        'dados'    => null,
        'evento'   => ['arquivo', $arq],
    ];
}
