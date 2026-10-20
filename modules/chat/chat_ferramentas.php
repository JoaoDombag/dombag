<?php

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Ferramentas do chat com IA
//
//  Só ferramentas FECHADAS: cada uma tem a consulta fixa no código, com
//  parâmetros validados aqui. Não existe ferramenta de SQL livre.
//
//  Escopo de dados (montado da SESSÃO em chatMontarEscopo, nunca da IA):
//    • admin                              → vê tudo
//    • representante vinculado (não admin) → só pedidos dos seus RE_CODIGO
//    • demais                             → tudo, mas só nas ferramentas cujas
//                                           páginas o grupo pode acessar
//    • ERP fora do ar ao montar o escopo  → não admin fica sem ferramentas
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/chat_relatorio.php';

const CHAT_MAX_DIAS   = 366;
const CHAT_MAX_LINHAS = 200;

// Páginas do sistema que liberam cada ferramenta (para quem não é admin nem
// representante vinculado). Ter acesso a QUALQUER uma delas basta.
const CHAT_PAGINAS_FERRAMENTA = [
    'listar_pedidos'         => ['/yzidro/consulta', '/yzidro/pedidos', '/vendas'],
    'detalhe_pedido'         => ['/yzidro/consulta', '/yzidro/pedidos', '/vendas'],
    'resumo_faturamento'     => ['/relatorios/historico-faturamento'],
    'status_producao_pedido' => ['/pcp/status-ops', '/pcp/painel-ops', '/yzidro/consulta', '/yzidro/pedidos'],
    'faturamento_notas'      => ['/relatorios/historico-faturamento'],
    'conciliar_faturamento'  => ['/relatorios/historico-faturamento'],
];

// Visões gerenciais da empresa inteira (notas fiscais/DRE): nunca liberadas a
// representante vinculado, mesmo que o grupo dele tenha a página.
const CHAT_FERRAMENTAS_SO_GESTAO = ['faturamento_notas', 'conciliar_faturamento'];

// ── Conexão Postgres exclusiva do chat: somente leitura + timeout ────────────
// Use CHAT_PG_USER/CHAT_PG_PASS para o usuário dombag_ia quando ele existir.
function chatPG()
{
    static $conn = null, $tentou = false;
    if ($tentou) {
        return $conn;
    }
    $tentou = true;
    if (!function_exists('pg_connect')) {
        return null;
    }
    $usuario = dombagConfig('CHAT_PG_USER') ?: PG_USER;
    $senha   = dombagConfig('CHAT_PG_USER') ? (string) dombagConfig('CHAT_PG_PASS') : PG_PASS;
    $dsn = sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s connect_timeout=10 options='-c default_transaction_read_only=on -c statement_timeout=15000'",
        PG_HOST, PG_PORT, PG_DBNAME, $usuario, addcslashes($senha, "'\\")
    );
    $conn = @pg_connect($dsn) ?: null;
    if (!$conn) {
        error_log('[CHAT] falha ao conectar no ERP');
    }
    return $conn;
}

function chatPGConsulta($pg, string $sql, array $params): array
{
    $r = @pg_query_params($pg, $sql, $params);
    if (!$r) {
        throw new RuntimeException('consulta ERP falhou: ' . pg_last_error($pg));
    }
    $linhas = pg_fetch_all($r) ?: [];
    pg_free_result($r);
    return $linhas;
}

// Array de inteiros no formato literal do Postgres ({1,2,3}) ou null (= todos).
function chatPGArray(?array $ints): ?string
{
    return $ints === null ? null : '{' . implode(',', array_map('intval', $ints)) . '}';
}

// ── Escopo do usuário ────────────────────────────────────────────────────────
// $representantes: [['re_codigo' => int, 're_nome' => string], ...] vinculados
// ao uid da sessão, ou null se não foi possível consultar o ERP.
function chatMontarEscopo(array $ctx, ?array $representantes): array
{
    $ctx['representantes'] = $representantes ?? [];
    $ctx['re']             = null;          // null = sem filtro de representante
    $ctx['liberadas']      = ['meu_perfil'];

    if ($ctx['admin']) {
        $ctx['liberadas'] = array_merge($ctx['liberadas'], array_keys(CHAT_PAGINAS_FERRAMENTA));
    } elseif ($representantes === null) {
        // falha fechada: sem consultas
    } elseif ($representantes) {
        $ctx['re']        = array_map(fn($r) => (int) $r['re_codigo'], $representantes);
        $ctx['liberadas'] = array_merge($ctx['liberadas'], array_diff(array_keys(CHAT_PAGINAS_FERRAMENTA), CHAT_FERRAMENTAS_SO_GESTAO));
    } else {
        foreach (CHAT_PAGINAS_FERRAMENTA as $ferramenta => $paginas) {
            foreach ($paginas as $p) {
                if (chatTemPermissao($ctx, $p)) {
                    $ctx['liberadas'][] = $ferramenta;
                    break;
                }
            }
        }
    }
    // Relatório só para quem tem ao menos uma consulta (ele só usa dados delas).
    if (count($ctx['liberadas']) > 1) {
        $ctx['liberadas'][] = 'gerar_relatorio';
    }
    return $ctx;
}

function chatRepresentantesDoUsuario(int $uid): ?array
{
    $pg = chatPG();
    if (!$pg) {
        return null;
    }
    try {
        return chatPGConsulta($pg, 'SELECT RE_CODIGO AS re_codigo, RE_NOME AS re_nome FROM REPRESENTANTES WHERE USU_CODIGO = $1 ORDER BY RE_CODIGO', [$uid]);
    } catch (Throwable $e) {
        error_log('[CHAT] ' . $e->getMessage());
        return null;
    }
}

// ── Definições ───────────────────────────────────────────────────────────────
function chatDefinicoes(): array
{
    $periodo = [
        'data_ini' => ['type' => 'string', 'format' => 'date', 'description' => 'Data inicial (AAAA-MM-DD).'],
        'data_fim' => ['type' => 'string', 'format' => 'date', 'description' => 'Data final, inclusiva (AAAA-MM-DD). Período máximo de 366 dias.'],
    ];
    return [
        'meu_perfil' => [
            'description' => 'Mostra quem é o usuário logado, se ele é representante (e quais), e quais consultas ele pode fazer. Use quando a pessoa perguntar o que pode consultar ou quem ela é no sistema.',
            'properties'  => [],
            'required'    => [],
        ],
        'listar_pedidos' => [
            'description' => 'Lista pedidos de venda por data de EMISSÃO, com cliente, representante, situação e valor, e devolve também a quantidade e o valor total de todos os pedidos encontrados. O filtro de representante é aplicado automaticamente conforme o usuário logado. Por padrão não inclui orçamentos.',
            'properties'  => $periodo + [
                'cliente'            => ['type' => 'string', 'maxLength' => 60, 'description' => 'Parte do nome ou nome fantasia do cliente (opcional).'],
                'incluir_orcamentos' => ['type' => 'boolean', 'description' => 'Incluir orçamentos (padrão: false).'],
                'limite'             => ['type' => 'integer', 'minimum' => 1, 'maximum' => CHAT_MAX_LINHAS, 'description' => 'Máximo de pedidos listados (padrão 50, máx. 200). Os totais consideram todos.'],
            ],
            'required' => ['data_ini', 'data_fim'],
        ],
        'detalhe_pedido' => [
            'description' => 'Mostra o cabeçalho e os itens (produtos, quantidades, valores, previsão de entrega) de um pedido de venda pelo número.',
            'properties'  => ['pedido' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Número do pedido.']],
            'required'    => ['pedido'],
        ],
        'resumo_faturamento' => [
            'description' => 'Resume o faturamento (pedidos que SAÍRAM/foram faturados, pela data de finalização) no período: nº de pedidos, peças, valor e ticket médio, agrupado por mês, cliente, representante, tipo de produto (Bag/Sacaria) ou total. Devoluções entram com sinal negativo. Mesmos critérios do relatório Histórico de Faturamento.',
            'properties'  => $periodo + [
                'agrupar_por' => ['type' => 'string', 'enum' => ['total', 'mes', 'cliente', 'representante', 'tipo'], 'description' => 'Agrupamento (padrão: total).'],
            ],
            'required' => ['data_ini', 'data_fim'],
        ],
        'status_producao_pedido' => [
            'description' => 'Mostra as ordens de produção (OPs) de um pedido: situação, datas de abertura, entrega prevista e finalização, quantidade produzida e faturada.',
            'properties'  => ['pedido' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Número do pedido.']],
            'required'    => ['pedido'],
        ],
        'faturamento_notas' => [
            'description' => 'Faturamento pela visão das NOTAS FISCAIS / DRE: NF-e de saída AUTORIZADAS pela SEFAZ, pela data de EMISSÃO, '
                . 'com valor dos produtos, IPI e total da nota (com IPI). É a base do DRE. Diferente do resumo_faturamento '
                . '(Histórico de Vendas: pedidos faturados, sem IPI, pela data de finalização). Por padrão considera só operações de venda; '
                . 'use agrupar_por "operacao" para ver também remessas, amostras e devoluções.',
            'properties'  => $periodo + [
                'agrupar_por' => ['type' => 'string', 'enum' => ['total', 'mes', 'operacao', 'cliente', 'empresa'], 'description' => 'Agrupamento (padrão: total). "empresa" separa por CNPJ do grupo.'],
                'somente_venda' => ['type' => 'boolean', 'description' => 'Só operações de venda (padrão: true). false inclui remessas, amostras, devoluções etc.'],
                'todas_empresas' => ['type' => 'boolean', 'description' => 'Incluir todos os CNPJs do grupo Dom Bag (padrão: false = só o CNPJ principal, empresa 1).'],
            ],
            'required' => ['data_ini', 'data_fim'],
        ],
        'conciliar_faturamento' => [
            'description' => 'Cruza o Histórico de Vendas (pedidos faturados) com as NF-e de venda autorizadas (base do DRE) no período e aponta as '
                . 'DIVERGÊNCIAS: pedidos faturados sem NF-e correspondente e NF-e sem pedido correspondente. A correspondência é feita por '
                . 'cliente + valor sem IPI, com até 10 dias entre as datas (não existe vínculo direto no ERP). Traz também os totais dos dois '
                . 'lados (sem IPI, IPI e com IPI) e a diferença. Use quando pedirem para comparar/conferir/cruzar o faturamento com o DRE.',
            'properties'  => $periodo,
            'required'    => ['data_ini', 'data_fim'],
        ],
    ];
}

// Definições no formato do SDK, só as liberadas para o usuário.
function chatFerramentasDisponiveis(array $ctx): array
{
    $saida = [];
    foreach (chatDefinicoes() as $nome => $d) {
        if (!in_array($nome, $ctx['liberadas'] ?? [], true)) {
            continue;
        }
        $saida[] = [
            'name'                => $nome,
            'description'         => $d['description'],
            'inputSchema'         => [
                'type'                 => 'object',
                'properties'           => $d['properties'] ?: new stdClass(),
                'required'             => $d['required'],
                'additionalProperties' => false,
            ],
            'eagerInputStreaming' => true,
        ];
    }
    if (in_array('gerar_relatorio', $ctx['liberadas'] ?? [], true)) {
        $saida[] = chatDefinicaoRelatorio();
    }
    return $saida;
}

// ── Validação dos argumentos enviados pela IA ────────────────────────────────
// Rejeita chaves desconhecidas (ex.: "re_codigo", "usuario"), tipos errados,
// datas inválidas e períodos longos. Retorna [argumentos, erro|null].
function chatValidarArgumentos(string $nome, array $input): array
{
    $def = chatDefinicoes()[$nome] ?? null;
    if (!$def) {
        return [[], 'Ferramenta desconhecida.'];
    }
    foreach (array_keys($input) as $k) {
        if (!isset($def['properties'][$k])) {
            return [[], "Parâmetro não permitido: {$k}."];
        }
    }
    foreach ($def['required'] as $k) {
        if (!array_key_exists($k, $input) || $input[$k] === null || $input[$k] === '') {
            return [[], "Parâmetro obrigatório ausente: {$k}."];
        }
    }
    $args = [];
    foreach ($input as $k => $v) {
        $p = $def['properties'][$k];
        switch ($p['type']) {
            case 'integer':
                if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
                    return [[], "{$k} deve ser um número inteiro."];
                }
                $v = (int) $v;
                if ($v < ($p['minimum'] ?? PHP_INT_MIN) || $v > ($p['maximum'] ?? PHP_INT_MAX)) {
                    return [[], "{$k} fora do intervalo permitido."];
                }
                break;
            case 'boolean':
                if (!is_bool($v)) {
                    return [[], "{$k} deve ser true ou false."];
                }
                break;
            case 'string':
                if (!is_string($v)) {
                    return [[], "{$k} deve ser texto."];
                }
                $v = trim($v);
                if (mb_strlen($v) > ($p['maxLength'] ?? 200)) {
                    return [[], "{$k} muito longo."];
                }
                if (($p['format'] ?? '') === 'date') {
                    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                    if (!$d || $d->format('Y-m-d') !== $v) {
                        return [[], "{$k} deve estar no formato AAAA-MM-DD."];
                    }
                }
                if (isset($p['enum']) && !in_array($v, $p['enum'], true)) {
                    return [[], "{$k} inválido. Use: " . implode(', ', $p['enum']) . '.'];
                }
                break;
        }
        $args[$k] = $v;
    }
    if (isset($args['data_ini'], $args['data_fim'])) {
        $ini = new DateTimeImmutable($args['data_ini']);
        $fim = new DateTimeImmutable($args['data_fim']);
        if ($fim < $ini) {
            return [[], 'data_fim é anterior a data_ini.'];
        }
        if ($ini->diff($fim)->days > CHAT_MAX_DIAS) {
            return [[], 'Período máximo é de 366 dias. Divida a consulta.'];
        }
    }
    return [$args, null];
}

// ── Execução ─────────────────────────────────────────────────────────────────
// Retorna ['ok' => bool, 'conteudo' => string (JSON p/ a IA), 'linhas' => int, 'dados' => ?array]
function chatExecutarFerramenta(string $nome, array $input, array $ctx, string $conversaId, PDO $pdo, $pg = null): array
{
    if (!in_array($nome, $ctx['liberadas'] ?? [], true)) {
        return chatErroFerramenta('Esta consulta não está disponível para o seu usuário.');
    }
    if ($nome === 'gerar_relatorio') {
        return chatFerGerarRelatorio($pdo, $ctx, $conversaId, $input);
    }
    [$args, $erro] = chatValidarArgumentos($nome, $input);
    if ($erro) {
        return chatErroFerramenta($erro);
    }

    if ($nome === 'meu_perfil') {
        return chatResultado(chatFerMeuPerfil($ctx));
    }

    $pg ??= chatPG();
    if (!$pg) {
        return chatErroFerramenta('O ERP está indisponível no momento. Avise o usuário e sugira tentar mais tarde.');
    }
    $dados = match ($nome) {
        'listar_pedidos'         => chatFerListarPedidos($pg, $ctx, $args),
        'detalhe_pedido'         => chatFerDetalhePedido($pg, $ctx, $args),
        'resumo_faturamento'     => chatFerResumoFaturamento($pg, $ctx, $args),
        'status_producao_pedido' => chatFerStatusProducao($pg, $ctx, $args),
        'faturamento_notas'      => chatFerFaturamentoNotas($pg, $args),
        'conciliar_faturamento'  => chatFerConciliarFaturamento($pg, $args),
    };
    // Guarda o resultado como fonte (F1, F2...) da conversa: é a ÚNICA origem
    // de números que um relatório pode usar.
    $dados = ['fonte' => chatFonteSalvar($pdo, $conversaId, $ctx['uid'], $nome, $dados)] + $dados;
    return chatResultado($dados);
}

function chatErroFerramenta(string $msg): array
{
    return ['ok' => false, 'conteudo' => $msg, 'linhas' => 0, 'dados' => null];
}

function chatResultado(array $dados): array
{
    $linhas = count($dados['pedidos'] ?? $dados['itens'] ?? $dados['grupos'] ?? $dados['ops'] ?? $dados['divergencias'] ?? []);
    return [
        'ok'       => true,
        'conteudo' => json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        'linhas'   => $linhas,
        'dados'    => $dados,
    ];
}

// Termo para ILIKE com curingas do usuário escapados.
function chatLike(string $termo): string
{
    return '%' . addcslashes($termo, '%_\\') . '%';
}

const CHAT_OPERACAO = ['V' => 'Venda', 'O' => 'Orçamento', 'D' => 'Devolução', 'B' => 'Bonificação'];

function chatNum($v): float
{
    return round((float) $v, 2);
}

// meu_perfil — não consulta o ERP além do vínculo já carregado no contexto.
// Tela inicial do assistente: apresentação personalizada (quem é o usuário e o
// que ele pode consultar), montada das permissões reais — sem chamar a IA.
function chatTextoInicio(array $ctx): array
{
    $nome = trim(explode(' ', trim((string) $ctx['nome']))[0] ?? '') ?: 'tudo bem';
    $l = $ctx['liberadas'] ?? [];

    if ($ctx['admin']) {
        $quem = 'Você é **administrador** do sistema: tem acesso a todos os pedidos e aos dados de faturamento e produção da Dom Bag.';
    } elseif (!empty($ctx['re'])) {
        $quem = 'Você está vinculado ao representante **' . implode(', ', array_column($ctx['representantes'], 're_nome'))
              . '**: as consultas mostram somente os seus pedidos.';
    } else {
        $quem = 'Você tem acesso às consultas liberadas para o seu grupo de usuários.';
    }

    $itens = [
        'listar_pedidos'         => '**Lista de pedidos** de venda por período',
        'detalhe_pedido'         => '**Detalhes e itens** de um pedido específico',
        'resumo_faturamento'     => '**Resumo de faturamento** (por período, cliente, representante ou tipo de produto)',
        'status_producao_pedido' => '**Status de produção** (ordens de produção) de um pedido',
        'faturamento_notas'      => '**Notas fiscais / DRE**: NF-e emitidas com IPI, por período, operação, cliente ou CNPJ',
        'conciliar_faturamento'  => '**Cruzamento Histórico × DRE**: aponta pedidos sem NF-e, NF-e sem pedido e diferenças',
    ];
    $lista = [];
    foreach ($itens as $f => $txt) {
        if (in_array($f, $l, true)) {
            $lista[] = '- ' . $txt;
        }
    }

    if (!$lista && !$ctx['admin'] && empty($ctx['re'])) {
        $quem = '';                       // evita "tem acesso..." seguido de "não tem acesso"
    }
    $texto = trim("Olá, **{$nome}**! {$quem}") . "\n\n";
    if ($lista) {
        $texto .= "Comigo, você pode consultar:\n" . implode("\n", $lista) . "\n\n";
        if (in_array('gerar_relatorio', $l, true)) {
            $texto .= "Também posso gerar **relatórios em PDF ou Excel** com esses dados.\n\n";
        }
        $texto .= 'Como posso ajudar hoje?';
    } else {
        $texto .= 'No momento o seu grupo não tem acesso às consultas de vendas ou produção. '
                . 'Peça a um administrador para liberar as páginas de vendas, faturamento ou produção no seu grupo. '
                . 'Enquanto isso, posso tirar dúvidas sobre como usar o DOMBAG.';
    }

    // Sugestões só do que o usuário pode consultar.
    $sugestoes = [];
    if (in_array('resumo_faturamento', $l, true)) {
        $sugestoes[] = 'Quanto faturamos este mês?';
        $sugestoes[] = 'Qual foi o faturamento por representante no mês passado?';
    }
    if (in_array('listar_pedidos', $l, true)) {
        $sugestoes[] = 'Liste os pedidos emitidos nos últimos 7 dias';
    }
    if (in_array('conciliar_faturamento', $l, true)) {
        $sugestoes[] = 'Cruze o Histórico de Vendas com o DRE do mês passado';
    }
    if (in_array('resumo_faturamento', $l, true) && in_array('gerar_relatorio', $l, true)) {
        $sugestoes[] = 'Gere um PDF com o faturamento mensal deste ano';
    } elseif (in_array('listar_pedidos', $l, true) && in_array('gerar_relatorio', $l, true)) {
        $sugestoes[] = 'Gere um Excel com os pedidos deste mês';
    }

    return ['texto' => $texto, 'sugestoes' => $sugestoes];
}

function chatFerMeuPerfil(array $ctx): array
{
    $nomesFerramentas = [
        'listar_pedidos' => 'lista de pedidos', 'detalhe_pedido' => 'itens de um pedido',
        'resumo_faturamento' => 'resumo de faturamento', 'status_producao_pedido' => 'produção de um pedido',
        'faturamento_notas' => 'notas fiscais / DRE', 'conciliar_faturamento' => 'cruzamento Histórico × DRE',
    ];
    $consultas = [];
    foreach ($ctx['liberadas'] as $f) {
        if (isset($nomesFerramentas[$f])) {
            $consultas[] = $nomesFerramentas[$f];
        }
    }
    return [
        'nome'           => $ctx['nome'],
        'administrador'  => $ctx['admin'],
        'representantes' => array_column($ctx['representantes'] ?? [], 're_nome'),
        'escopo'         => $ctx['re'] === null ? 'todos os pedidos' : 'somente os pedidos dos representantes acima',
        'consultas'      => $consultas,
    ];
}

function chatFerListarPedidos($pg, array $ctx, array $a): array
{
    $limite = $a['limite'] ?? 50;
    $sql = "
        SELECT V.VEN_COD_PEDIDO                                   AS pedido
              ,TO_CHAR(V.VEN_DATA, 'YYYY-MM-DD')                  AS emissao
              ,TO_CHAR(V.DATA_FINALIZA, 'YYYY-MM-DD')             AS finalizacao
              ,COALESCE(V.VEN_STATUS, 'V')                        AS operacao
              ,COALESCE(EP.EST_DESCRICAO, '')                     AS situacao
              ,COALESCE(V.VEN_TOTAL, 0)                           AS valor
              ,COALESCE(NULLIF(C.CLI_NOME_FANTASIA, ''), C.CLI_NOME, '') AS cliente
              ,COALESCE(C.CLI_CIDADE, '')                         AS cidade
              ,COALESCE(R.RE_NOME, '')                            AS representante
              ,COUNT(*) OVER ()                                   AS total_pedidos
              ,SUM(COALESCE(V.VEN_TOTAL, 0)) OVER ()              AS total_valor
          FROM VENDAS V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO  = V.CLI_CODIGO
          LEFT JOIN REPRESENTANTES R  ON R.RE_CODIGO   = V.RE_CODIGO
          LEFT JOIN ESTADO_PEDIDO  EP ON EP.EST_CODIGO = V.EST_CODIGO
         WHERE V.EMP_CODIGO = 1
           AND V.VEN_DATA >= $1::date AND V.VEN_DATA < $2::date + 1
           AND ($3::int[] IS NULL OR V.RE_CODIGO = ANY($3::int[]))
           AND ($4::text IS NULL OR C.CLI_NOME ILIKE $4 OR C.CLI_NOME_FANTASIA ILIKE $4)
           AND ($5::boolean OR COALESCE(V.VEN_STATUS, 'V') <> 'O')
         ORDER BY V.VEN_DATA DESC, V.VEN_COD_PEDIDO DESC
         LIMIT $6
    ";
    $linhas = chatPGConsulta($pg, $sql, [
        $a['data_ini'], $a['data_fim'], chatPGArray($ctx['re']),
        ($a['cliente'] ?? '') !== '' ? chatLike($a['cliente']) : null,
        !empty($a['incluir_orcamentos']) ? 't' : 'f', $limite,
    ]);

    $pedidos = array_map(fn($r) => [
        'pedido'        => (int) $r['pedido'],
        'emissao'       => $r['emissao'],
        'finalizacao'   => $r['finalizacao'],
        'operacao'      => CHAT_OPERACAO[$r['operacao']] ?? $r['operacao'],
        'situacao'      => $r['situacao'],
        'cliente'       => $r['cliente'],
        'cidade'        => $r['cidade'],
        'representante' => $r['representante'],
        'valor'         => chatNum($r['valor']),
    ], $linhas);
    $total = (int) ($linhas[0]['total_pedidos'] ?? 0);

    return [
        'periodo_emissao' => "{$a['data_ini']} a {$a['data_fim']}",
        'total_pedidos'   => $total,
        'valor_total'     => chatNum($linhas[0]['total_valor'] ?? 0),
        'listados'        => count($pedidos),
        'aviso'           => $total > count($pedidos) ? "Há {$total} pedidos; só os {$limite} mais recentes foram listados. Os totais consideram todos." : null,
        'pedidos'         => $pedidos,
    ];
}

function chatFerDetalhePedido($pg, array $ctx, array $a): array
{
    $cab = chatPGConsulta($pg, "
        SELECT V.VEN_COD_PEDIDO AS pedido, TO_CHAR(V.VEN_DATA, 'YYYY-MM-DD') AS emissao,
               TO_CHAR(V.DATA_FINALIZA, 'YYYY-MM-DD') AS finalizacao, COALESCE(V.VEN_STATUS, 'V') AS operacao,
               COALESCE(EP.EST_DESCRICAO, '') AS situacao, COALESCE(V.VEN_TOTAL, 0) AS valor,
               COALESCE(NULLIF(C.CLI_NOME_FANTASIA, ''), C.CLI_NOME, '') AS cliente, COALESCE(C.CLI_CIDADE, '') AS cidade,
               COALESCE(R.RE_NOME, '') AS representante
          FROM VENDAS V
          LEFT JOIN CLIENTES       C  ON C.CLI_CODIGO  = V.CLI_CODIGO
          LEFT JOIN REPRESENTANTES R  ON R.RE_CODIGO   = V.RE_CODIGO
          LEFT JOIN ESTADO_PEDIDO  EP ON EP.EST_CODIGO = V.EST_CODIGO
         WHERE V.EMP_CODIGO = 1 AND V.VEN_COD_PEDIDO = $1
           AND ($2::int[] IS NULL OR V.RE_CODIGO = ANY($2::int[]))
    ", [$a['pedido'], chatPGArray($ctx['re'])]);

    // Pedido fora do escopo responde igual a inexistente (não revela que existe).
    if (!$cab) {
        return ['pedido' => $a['pedido'], 'encontrado' => false, 'mensagem' => 'Pedido não encontrado entre os pedidos que você pode consultar.'];
    }

    $itens = chatPGConsulta($pg, "
        SELECT VV.COD_PRODUTO AS codigo, CAST(VV.PRODUTO AS VARCHAR(120)) AS produto,
               COALESCE(VV.QTD, 0) AS quantidade, COALESCE(VV.UNIDADE, '') AS unidade,
               COALESCE(VV.VALOR_UNIT, 0) AS valor_unitario, COALESCE(VV.TOTAL_PRODUTO, 0) AS valor_total,
               TO_CHAR(VV.DATA_ENTREGA_ITEM, 'YYYY-MM-DD') AS entrega_prevista,
               COALESCE(VV.QTD_EXPEDIDO, 0) AS quantidade_expedida
          FROM VW_VENDAS VV
         WHERE VV.EMP_CODIGO = 1 AND VV.PEDIDO = $1
         ORDER BY VV.ITE_CODIGO
         LIMIT 200
    ", [$a['pedido']]);

    $c = $cab[0];
    return [
        'pedido'        => (int) $c['pedido'],
        'encontrado'    => true,
        'emissao'       => $c['emissao'],
        'finalizacao'   => $c['finalizacao'],
        'operacao'      => CHAT_OPERACAO[$c['operacao']] ?? $c['operacao'],
        'situacao'      => $c['situacao'],
        'cliente'       => $c['cliente'],
        'cidade'        => $c['cidade'],
        'representante' => $c['representante'],
        'valor'         => chatNum($c['valor']),
        'itens'         => array_map(fn($i) => [
            'codigo'              => (int) $i['codigo'],
            'produto'             => $i['produto'],
            'quantidade'          => (float) $i['quantidade'],
            'unidade'             => $i['unidade'],
            'valor_unitario'      => chatNum($i['valor_unitario']),
            'valor_total'         => chatNum($i['valor_total']),
            'entrega_prevista'    => $i['entrega_prevista'],
            'quantidade_expedida' => (float) $i['quantidade_expedida'],
        ], $itens),
    ];
}

// Critérios idênticos ao relatório Histórico de Faturamento.
const CHAT_WHERE_FATURADO = "
    V.EMP_CODIGO = 1
    AND V.EST_CODIGO IN (14)
    AND V.VEN_MODELO_VENDA NOT IN (2, 3, 9, 10, 11, 12)
    AND V.DATA_FINALIZA >= $1::date AND V.DATA_FINALIZA < $2::date + 1
    AND ($3::int[] IS NULL OR V.RE_CODIGO = ANY($3::int[]))";

function chatFerResumoFaturamento($pg, array $ctx, array $a): array
{
    $agrupar = $a['agrupar_por'] ?? 'total';
    $params  = [$a['data_ini'], $a['data_fim'], chatPGArray($ctx['re'])];
    $sinal   = "CASE WHEN V.VEN_STATUS = 'D' THEN -1 ELSE 1 END";

    if ($agrupar === 'tipo') {
        // Classificação Bag/Sacaria pelo nome do produto (mesma regra do relatório).
        $linhas = chatPGConsulta($pg, "
            SELECT CASE WHEN UPPER(VV.PRODUTO) LIKE '%SACARIA%' OR UPPER(VV.PRODUTO) LIKE '%SACO%' THEN 'Sacaria'
                        WHEN UPPER(VV.PRODUTO) LIKE '%BIG BAG%' OR UPPER(VV.PRODUTO) LIKE '%BIGBAG%'
                          OR UPPER(VV.PRODUTO) ~ '(^|[^A-Z])BAG([^A-Z]|$)' THEN 'Bag'
                        ELSE 'Outros' END                               AS grupo
                  ,COUNT(DISTINCT V.VEN_COD_PEDIDO)                     AS pedidos
                  ,SUM({$sinal} * COALESCE(VV.QTD, 0))                  AS pecas
                  ,SUM({$sinal} * COALESCE(VV.TOTAL_PRODUTO, 0))        AS valor
              FROM VENDAS V
              JOIN VW_VENDAS VV ON VV.PEDIDO = V.VEN_COD_PEDIDO AND VV.EMP_CODIGO = 1 AND VV.OPERACAO_PEDIDO = 'V'
             WHERE " . CHAT_WHERE_FATURADO . "
             GROUP BY 1 ORDER BY 4 DESC
        ", $params);
        $obs = 'Por tipo, o valor é a soma dos itens (sem frete e impostos destacados) e as peças são as quantidades dos itens.';
    } else {
        $expr = [
            'total'         => "'Total'",
            'mes'           => "TO_CHAR(V.DATA_FINALIZA, 'YYYY-MM')",
            'cliente'       => "COALESCE(NULLIF(C.CLI_NOME_FANTASIA, ''), C.CLI_NOME, '(sem cliente)')",
            'representante' => "COALESCE(R.RE_NOME, '(sem representante)')",
        ][$agrupar];
        $ordem = $agrupar === 'mes' ? '1' : '4 DESC';
        // Peças = soma das quantidades dos itens (VEN_QUANTIDADE é volume de carga).
        $linhas = chatPGConsulta($pg, "
            SELECT {$expr}                                   AS grupo
                  ,COUNT(*)                                  AS pedidos
                  ,SUM({$sinal} * COALESCE(IT.QTD, 0))       AS pecas
                  ,SUM({$sinal} * COALESCE(V.VEN_TOTAL, 0))  AS valor
              FROM VENDAS V
              LEFT JOIN CLIENTES       C ON C.CLI_CODIGO = V.CLI_CODIGO
              LEFT JOIN REPRESENTANTES R ON R.RE_CODIGO  = V.RE_CODIGO
              LEFT JOIN LATERAL (
                    SELECT SUM(VV.QTD) AS QTD FROM VW_VENDAS VV
                     WHERE VV.PEDIDO = V.VEN_COD_PEDIDO AND VV.EMP_CODIGO = 1 AND VV.OPERACAO_PEDIDO = 'V'
                   ) IT ON TRUE
             WHERE " . CHAT_WHERE_FATURADO . "
             GROUP BY 1 ORDER BY {$ordem}
             LIMIT 100
        ", $params);
        $obs = 'Valor total da nota (VEN_TOTAL). Devoluções entram com sinal negativo.';
    }

    $grupos = array_map(fn($r) => [
        'grupo'        => (string) $r['grupo'],
        'pedidos'      => (int) $r['pedidos'],
        'pecas'        => chatNum($r['pecas']),
        'valor'        => chatNum($r['valor']),
        'ticket_medio' => (int) $r['pedidos'] > 0 ? chatNum((float) $r['valor'] / (int) $r['pedidos']) : 0.0,
    ], $linhas);

    return [
        'periodo_faturamento' => "{$a['data_ini']} a {$a['data_fim']}",
        'agrupado_por'        => $agrupar,
        'observacao'          => $obs,
        'sem_dados'           => !$grupos,
        'grupos'              => $grupos,
    ];
}

function chatFerStatusProducao($pg, array $ctx, array $a): array
{
    $existe = chatPGConsulta($pg, '
        SELECT 1 FROM VENDAS V
         WHERE V.EMP_CODIGO = 1 AND V.VEN_COD_PEDIDO = $1
           AND ($2::int[] IS NULL OR V.RE_CODIGO = ANY($2::int[]))
    ', [$a['pedido'], chatPGArray($ctx['re'])]);
    if (!$existe) {
        return ['pedido' => $a['pedido'], 'encontrado' => false, 'mensagem' => 'Pedido não encontrado entre os pedidos que você pode consultar.'];
    }

    // VENDA_REF é texto livre: filtra só valores numéricos antes de comparar.
    $ops = chatPGConsulta($pg, "
        SELECT PR.PROD_CODIGO                                  AS op
              ,TO_CHAR(PR.PROD_DATA, 'YYYY-MM-DD')             AS abertura
              ,TO_CHAR(PR.DATA_ENTREGA, 'YYYY-MM-DD')          AS entrega_prevista
              ,TO_CHAR(PR.DATA_FINALIZA, 'YYYY-MM-DD')         AS finalizada_em
              ,COALESCE(PR.FINALIZADO, 'N')                    AS finalizado
              ,COALESCE(EP.EST_DESCRICAO, '')                  AS situacao
              ,COALESCE(SUM(IP.ITE_QTD), 0)                    AS quantidade
              ,COALESCE(SUM(IP.ITE_QTD_FAT), 0)                AS quantidade_faturada
          FROM PRODUCAO PR
          LEFT JOIN ITENS_PRODUCAO IP ON IP.PROD_CODIGO = PR.PROD_CODIGO
          LEFT JOIN ESTADO_PEDIDO  EP ON EP.EST_CODIGO  = PR.EST_CODIGO
         WHERE TRIM(PR.VENDA_REF) ~ '^[0-9]+$' AND TRIM(PR.VENDA_REF)::BIGINT = $1
         GROUP BY 1, 2, 3, 4, 5, 6
         ORDER BY PR.PROD_CODIGO
         LIMIT 100
    ", [$a['pedido']]);

    return [
        'pedido'     => $a['pedido'],
        'encontrado' => true,
        'sem_ops'    => !$ops,
        'ops'        => array_map(fn($o) => [
            'op'                  => (int) $o['op'],
            'situacao'            => $o['situacao'] ?: ($o['finalizado'] === 'Y' ? 'Finalizada' : 'Em aberto'),
            'finalizada'          => $o['finalizado'] === 'Y',
            'abertura'            => $o['abertura'],
            'entrega_prevista'    => $o['entrega_prevista'],
            'finalizada_em'       => $o['finalizada_em'],
            'quantidade'          => (float) $o['quantidade'],
            'quantidade_faturada' => (float) $o['quantidade_faturada'],
        ], $ops),
    ];
}

// ── Visão das NOTAS FISCAIS (base do DRE) ────────────────────────────────────
// NF-e de saída autorizadas pela SEFAZ (NF_ESTADONFE = 100), empresa 1, pela
// data de emissão. NF_TOTAL = produtos (sem IPI); TOTAL_NOTA = com IPI.
const CHAT_WHERE_NFE = "
    N.EMP_CODIGO = 1
    AND N.ENTRADA_SAIDA = 'S'
    AND N.NF_ESTADONFE = 100";

function chatFerFaturamentoNotas($pg, array $a): array
{
    $agrupar = $a['agrupar_por'] ?? 'total';
    $soVenda = $a['somente_venda'] ?? true;
    $todas   = !empty($a['todas_empresas']) || $agrupar === 'empresa';
    $expr = [
        'total'    => "'Total'",
        'mes'      => "TO_CHAR(N.NF_DATA_EMISSAO, 'YYYY-MM')",
        'operacao' => "COALESCE(TP.TO_DESCRICAO, '(sem operação)')",
        'cliente'  => "COALESCE(NULLIF(C.CLI_NOME_FANTASIA, ''), C.CLI_NOME, '(sem cliente)')",
        'empresa'  => "COALESCE(E.EMP_NOMEFANTASIA, 'Empresa ' || N.EMP_CODIGO)",
    ][$agrupar];
    $ordem = $agrupar === 'mes' ? '1' : '5 DESC';

    $linhas = chatPGConsulta($pg, "
        SELECT {$expr}                          AS grupo
              ,COUNT(*)                         AS notas
              ,SUM(COALESCE(N.NF_TOTAL, 0))     AS valor_produtos
              ,SUM(COALESCE(N.TOTAL_IPI, 0))    AS ipi
              ,SUM(COALESCE(N.TOTAL_NOTA, 0))   AS total_nota
          FROM NOTA N
          LEFT JOIN TIPO_OPERACAO TP ON TP.TO_CODIGO  = N.TO_CODIGO
          LEFT JOIN CLIENTES      C  ON C.CLI_CODIGO  = N.CLI_CODIGO
          LEFT JOIN EMPRESA       E  ON E.EMP_CODIGO  = N.EMP_CODIGO
         WHERE N.ENTRADA_SAIDA = 'S' AND N.NF_ESTADONFE = 100
           AND ($4::boolean OR N.EMP_CODIGO = 1)
           AND N.NF_DATA_EMISSAO >= $1::date AND N.NF_DATA_EMISSAO < $2::date + 1
           AND (NOT $3::boolean OR TP.TO_DESCRICAO ILIKE 'VENDA%')
         GROUP BY 1 ORDER BY {$ordem}
         LIMIT 100
    ", [$a['data_ini'], $a['data_fim'], $soVenda ? 't' : 'f', $todas ? 't' : 'f']);

    $grupos = array_map(fn($r) => [
        'grupo'          => (string) $r['grupo'],
        'notas'          => (int) $r['notas'],
        'valor_produtos' => chatNum($r['valor_produtos']),
        'ipi'            => chatNum($r['ipi']),
        'total_nota'     => chatNum($r['total_nota']),
    ], $linhas);

    return [
        'periodo_emissao' => "{$a['data_ini']} a {$a['data_fim']}",
        'agrupado_por'    => $agrupar,
        'criterio'        => 'NF-e de saída autorizadas pela SEFAZ, ' . ($todas ? 'todos os CNPJs do grupo' : 'CNPJ principal (empresa 1)') . ', pela data de emissão'
                           . ($soVenda ? ', só operações de venda' : ', todas as operações') . '. '
                           . 'valor_produtos = sem IPI; total_nota = com IPI (base do DRE).',
        'sem_dados'       => !$grupos,
        'grupos'          => $grupos,
    ];
}

// ── Cruzamento Histórico de Vendas × NF-e (as divergências) ─────────────────
// Não há vínculo direto entre o pedido (documento série 5) e a NF-e autorizada
// (série 2) no ERP: casamos por cliente + valor sem IPI, com até 10 dias entre
// a finalização do pedido e a emissão da nota, um para um (o mais próximo).
const CHAT_CONCILIA_DIAS = 10;

function chatFerConciliarFaturamento($pg, array $a): array
{
    $ini = $a['data_ini'];
    $fim = $a['data_fim'];
    $d   = CHAT_CONCILIA_DIAS;
    $ate = $d + 1;

    // Janela maior que o período para achar o par de quem está perto da borda.
    $nfes = chatPGConsulta($pg, "
        SELECT N.NF_CODIGO                                   AS id
              ,COALESCE(N.NUMERO_NOTA_EMITIDA::TEXT, '')     AS numero
              ,TO_CHAR(N.NF_DATA_EMISSAO, 'YYYY-MM-DD')      AS data
              ,N.CLI_CODIGO                                  AS cli
              ,COALESCE(NULLIF(C.CLI_NOME_FANTASIA, ''), C.CLI_NOME, '') AS cliente
              ,COALESCE(N.NF_TOTAL, 0)                       AS sem_ipi
              ,COALESCE(N.TOTAL_IPI, 0)                      AS ipi
              ,COALESCE(N.TOTAL_NOTA, 0)                     AS com_ipi
              ,COALESCE(TP.TO_DESCRICAO, '')                 AS operacao
          FROM NOTA N
          LEFT JOIN TIPO_OPERACAO TP ON TP.TO_CODIGO = N.TO_CODIGO
          LEFT JOIN CLIENTES      C  ON C.CLI_CODIGO = N.CLI_CODIGO
         WHERE " . CHAT_WHERE_NFE . "
           AND TP.TO_DESCRICAO ILIKE 'VENDA%'
           AND N.NF_DATA_EMISSAO >= $1::date - {$d} AND N.NF_DATA_EMISSAO < $2::date + {$ate}
         ORDER BY N.NF_DATA_EMISSAO, N.NF_CODIGO
    ", [$ini, $fim]);

    $pedidos = chatPGConsulta($pg, "
        SELECT V.VEN_COD_PEDIDO                              AS pedido
              ,TO_CHAR(V.DATA_FINALIZA, 'YYYY-MM-DD')        AS data
              ,V.CLI_CODIGO                                  AS cli
              ,COALESCE(NULLIF(C.CLI_NOME_FANTASIA, ''), C.CLI_NOME, '') AS cliente
              ,COALESCE(V.VEN_TOTAL, 0)                      AS valor
              ,COALESCE(V.VEN_STATUS, 'V')                   AS status
          FROM VENDAS V
          LEFT JOIN CLIENTES C ON C.CLI_CODIGO = V.CLI_CODIGO
         WHERE V.EMP_CODIGO = 1
           AND V.EST_CODIGO IN (14)
           AND V.VEN_MODELO_VENDA NOT IN (2, 3, 9, 10, 11, 12)
           AND V.DATA_FINALIZA >= $1::date - {$d} AND V.DATA_FINALIZA < $2::date + {$ate}
         ORDER BY V.DATA_FINALIZA, V.VEN_COD_PEDIDO
    ", [$ini, $fim]);

    // NF-e de venda dos OUTROS CNPJs do grupo: não entram no casamento, mas são
    // mostradas ao lado do pedido sem NF-e (o mesmo cliente pode ter sido
    // faturado por outro CNPJ).
    $outras = chatPGConsulta($pg, "
        SELECT N.CLI_CODIGO                                  AS cli
              ,COALESCE(N.NUMERO_NOTA_EMITIDA::TEXT, '')     AS numero
              ,TO_CHAR(N.NF_DATA_EMISSAO, 'YYYY-MM-DD')      AS data
              ,COALESCE(N.TOTAL_NOTA, 0)                     AS com_ipi
              ,COALESCE(E.EMP_NOMEFANTASIA, 'Empresa ' || N.EMP_CODIGO) AS empresa
          FROM NOTA N
          LEFT JOIN TIPO_OPERACAO TP ON TP.TO_CODIGO = N.TO_CODIGO
          LEFT JOIN EMPRESA       E  ON E.EMP_CODIGO = N.EMP_CODIGO
         WHERE N.EMP_CODIGO <> 1 AND N.ENTRADA_SAIDA = 'S' AND N.NF_ESTADONFE = 100
           AND TP.TO_DESCRICAO ILIKE 'VENDA%'
           AND N.NF_DATA_EMISSAO >= $1::date - {$d} AND N.NF_DATA_EMISSAO < $2::date + {$ate}
    ", [$ini, $fim]);
    $outrasPorCli = [];
    foreach ($outras as $o) {
        $outrasPorCli[$o['cli']][] = $o;
    }

    $noPeriodo = fn(string $data) => $data >= $ini && $data <= $fim;
    $dias      = fn(string $x, string $y) => abs((strtotime($x) - strtotime($y)) / 86400);
    $outrasDe  = function (array $p) use ($outrasPorCli, $dias, $d): array {
        return array_values(array_filter($outrasPorCli[$p['cli']] ?? [], fn($o) => $dias($o['data'], $p['data']) <= $d));
    };

    // Casamento um para um: para cada NF-e, o pedido livre do mesmo cliente e
    // mesmo valor com a data mais próxima. Devoluções (status D) não entram.
    $usado = [];                                   // índice do pedido => id da NF-e
    $par   = [];                                   // id da NF-e => índice do pedido
    foreach ($nfes as $n) {
        $melhor = null;
        foreach ($pedidos as $i => $p) {
            if (isset($usado[$i]) || $p['status'] === 'D' || $p['cli'] !== $n['cli']
                || abs((float) $p['valor'] - (float) $n['sem_ipi']) >= 0.01) {
                continue;
            }
            $dd = $dias($p['data'], $n['data']);
            if ($dd <= $d && ($melhor === null || $dd < $melhor[1])) {
                $melhor = [$i, $dd];
            }
        }
        if ($melhor) {
            $usado[$melhor[0]] = $n['id'];
            $par[$n['id']]     = $melhor[0];
        }
    }

    $div = [];
    $tot = ['hist' => 0.0, 'hist_qtd' => 0, 'nfe_sem' => 0.0, 'nfe_ipi' => 0.0, 'nfe_com' => 0.0, 'nfe_qtd' => 0, 'casados' => 0, 'outros_ped' => 0];
    foreach ($pedidos as $i => $p) {
        if (!$noPeriodo($p['data'])) {
            continue;
        }
        $tot['hist'] += ($p['status'] === 'D' ? -1 : 1) * (float) $p['valor'];
        $tot['hist_qtd']++;
        if ($p['status'] === 'D') {
            $div[] = ['tipo' => 'Devolução no Histórico', 'documento' => 'Pedido ' . $p['pedido'], 'data' => $p['data'],
                      'cliente' => $p['cliente'], 'valor_sem_ipi' => -chatNum($p['valor']), 'ipi' => null,
                      'observacao' => 'Devolução abatida no Histórico; não aparece nas NF-e de venda.'];
        } elseif (!isset($usado[$i])) {
            $obs = "Nenhuma NF-e do CNPJ principal com o mesmo cliente e valor em até {$d} dias.";
            if ($os = $outrasDe($p)) {
                $tot['outros_ped']++;
                $obs .= ' Há NF-e de outro CNPJ do grupo para o mesmo cliente: '
                      . implode('; ', array_map(fn($o) => "{$o['empresa']}, nº {$o['numero']} em " . chatPeriodoBr($o['data'])
                          . ' (R$ ' . number_format((float) $o['com_ipi'], 2, ',', '.') . ')', array_slice($os, 0, 3))) . '.';
            }
            $div[] = ['tipo' => 'Pedido sem NF-e', 'documento' => 'Pedido ' . $p['pedido'], 'data' => $p['data'],
                      'cliente' => $p['cliente'], 'valor_sem_ipi' => chatNum($p['valor']), 'ipi' => null,
                      'observacao' => $obs];
        }
    }
    $nfePorId = array_column($nfes, null, 'id');
    foreach ($nfes as $n) {
        if (!$noPeriodo($n['data'])) {
            continue;
        }
        $tot['nfe_sem'] += (float) $n['sem_ipi'];
        $tot['nfe_ipi'] += (float) $n['ipi'];
        $tot['nfe_com'] += (float) $n['com_ipi'];
        $tot['nfe_qtd']++;
        if (!isset($par[$n['id']])) {
            $div[] = ['tipo' => 'NF-e sem pedido', 'documento' => 'NF-e ' . $n['numero'], 'data' => $n['data'],
                      'cliente' => $n['cliente'], 'valor_sem_ipi' => chatNum($n['sem_ipi']), 'ipi' => chatNum($n['ipi']),
                      'observacao' => 'Operação: ' . $n['operacao'] . ". Nenhum pedido faturado do mesmo cliente e valor em até {$d} dias."];
            continue;
        }
        $p = $pedidos[$par[$n['id']]];
        if ($noPeriodo($p['data'])) {
            $tot['casados']++;
        } else {
            $div[] = ['tipo' => 'Datas em períodos diferentes', 'documento' => 'NF-e ' . $n['numero'] . ' / Pedido ' . $p['pedido'],
                      'data' => $n['data'], 'cliente' => $n['cliente'], 'valor_sem_ipi' => chatNum($n['sem_ipi']), 'ipi' => chatNum($n['ipi']),
                      'observacao' => 'NF-e emitida em ' . chatPeriodoBr($n['data']) . ', pedido finalizado em ' . chatPeriodoBr($p['data']) . '.'];
        }
    }
    // Pedido do período casado com NF-e de fora do período.
    foreach ($usado as $i => $idNfe) {
        $p = $pedidos[$i];
        $n = $nfePorId[$idNfe];
        if ($noPeriodo($p['data']) && !$noPeriodo($n['data'])) {
            $div[] = ['tipo' => 'Datas em períodos diferentes', 'documento' => 'Pedido ' . $p['pedido'] . ' / NF-e ' . $n['numero'],
                      'data' => $p['data'], 'cliente' => $p['cliente'], 'valor_sem_ipi' => chatNum($p['valor']), 'ipi' => chatNum($n['ipi']),
                      'observacao' => 'Pedido finalizado em ' . chatPeriodoBr($p['data']) . ', NF-e emitida em ' . chatPeriodoBr($n['data']) . '.'];
        }
    }
    usort($div, fn($x, $y) => [$x['tipo'], $x['data']] <=> [$y['tipo'], $y['data']]);
    $total = count($div);
    // Contagem e soma por tipo prontas (a IA não deve contar a lista sozinha).
    $porTipo = [];
    foreach ($div as $x) {
        $porTipo[$x['tipo']] ??= ['quantidade' => 0, 'valor_sem_ipi' => 0.0];
        $porTipo[$x['tipo']]['quantidade']++;
        $porTipo[$x['tipo']]['valor_sem_ipi'] = chatNum($porTipo[$x['tipo']]['valor_sem_ipi'] + (float) $x['valor_sem_ipi']);
    }

    return [
        'periodo'   => "{$ini} a {$fim}",
        'criterio'  => 'Histórico = pedidos faturados (data de finalização, sem IPI, devoluções abatidas). '
                     . 'NF-e = notas de venda autorizadas pela SEFAZ (data de emissão). Correspondência por cliente + valor sem IPI, '
                     . "até {$d} dias, um para um — não há vínculo direto entre pedido e NF-e no ERP.",
        'resumo'    => [
            'historico_pedidos'       => $tot['hist_qtd'],
            'historico_valor'         => chatNum($tot['hist']),
            'nfe_quantidade'          => $tot['nfe_qtd'],
            'nfe_valor_sem_ipi'       => chatNum($tot['nfe_sem']),
            'nfe_ipi'                 => chatNum($tot['nfe_ipi']),
            'nfe_valor_com_ipi'       => chatNum($tot['nfe_com']),
            'conciliados'             => $tot['casados'],
            'diferenca_sem_ipi'       => chatNum($tot['nfe_sem'] - $tot['hist']),
            'outros_cnpjs_notas'      => count(array_filter($outras, fn($o) => $noPeriodo($o['data']))),
            'outros_cnpjs_valor_com_ipi' => chatNum(array_sum(array_map(fn($o) => $noPeriodo($o['data']) ? (float) $o['com_ipi'] : 0, $outras))),
            'pedidos_sem_nfe_com_nota_de_outro_cnpj' => $tot['outros_ped'],
            'quantidade_divergencias' => $total,
            'divergencias_por_tipo'   => $porTipo,
        ],
        'como_ler'     => 'Use os números de "resumo" e "divergencias_por_tipo" (já contados); não conte a lista. '
                        . 'outros_cnpjs_valor_com_ipi é o total de NF-e de venda dos outros CNPJs no período inteiro, não só dos pedidos listados.',
        'aviso'        => $total > 150 ? "Há {$total} divergências; só as 150 primeiras foram listadas." : null,
        'divergencias' => array_slice($div, 0, 150),
    ];
}
