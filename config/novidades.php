<?php
// ══════════════════════════════════════════════════════════════
//  DOMBAG — Novidades do sistema ("O que mudou")
//  A cada atualização que o usuário percebe, acrescente uma versão NO TOPO.
//  Na primeira tela que a pessoa abrir depois do deploy, aparece uma janela
//  com tudo o que mudou desde a última versão que ela viu (shared/novidades.php).
//  O histórico completo fica na Documentação, seção Novidades.
//
//  Chave da versão: data do deploy 'AAAA-MM-DD'. Dois deploys no mesmo dia:
//  'AAAA-MM-DD.2', '.3'... A ordem é a ordem de texto da chave.
//  Cada item: [tipo, texto, tela (href de config/pages.php) ou null].
//  Tipos: 'novo', 'melhoria', 'correcao'. Item com tela só aparece para
//  quem tem acesso a ela; item sem tela aparece para todos.
// ══════════════════════════════════════════════════════════════

const NOVIDADES = [
    '2026-11-27' => [
        'titulo' => 'Aplicativo instalável e sistema no celular',
        'itens'  => [
            ['novo', 'Em Parâmetros você escolhe o ícone do aplicativo e instala o sistema no computador ou no celular.', '/modules/parametros/parametros.php'],
            ['melhoria', 'Durante uma atualização do sistema aparece um aviso de que ele volta em instantes, no lugar da tela de erro. A página tenta de novo sozinha.', null],
            ['melhoria', 'Sistema mais fácil de usar no celular: tabelas largas viram cartões, filtros e indicadores em duas colunas, letras e botões maiores, e o botão do Assistente sai da frente enquanto você rola a tela.', null],
            ['novo', 'No rodapé do login e do menu aparece a data da última atualização do sistema.', null],
            ['novo', 'Esta janela: depois de cada atualização, ela mostra o que mudou na primeira vez que você entra. O histórico fica na Documentação.', '/documentacao'],
        ],
    ],
];

const NOVIDADES_TIPOS = [
    'novo'     => 'Novo',
    'melhoria' => 'Melhoria',
    'correcao' => 'Correção',
];

// Quantas versões a janela mostra de uma vez (quem ficou muito tempo sem entrar
// vê as mais recentes; o resto está na Documentação).
const NOVIDADES_MAX_JANELA = 5;

if (!function_exists('novidadesUltima')) {
    function novidadesUltima(): string
    {
        $versoes = array_keys(NOVIDADES);
        rsort($versoes, SORT_STRING);
        return (string) ($versoes[0] ?? '');
    }

    // '2026-11-24.2' → '24/11/2026'
    function novidadesData(string $versao): string
    {
        $d = DateTime::createFromFormat('!Y-m-d', substr($versao, 0, 10));
        return $d ? $d->format('d/m/Y') : $versao;
    }

    // Todas as versões, mais nova primeiro, só com os itens que a pessoa pode ver.
    // $pode(href) diz se ela tem acesso à tela. Versão sem item visível sai da lista.
    function novidadesTodas(callable $pode): array
    {
        $versoes = NOVIDADES;
        krsort($versoes, SORT_STRING);
        $saida = [];
        foreach ($versoes as $versao => $v) {
            $itens = [];
            foreach ($v['itens'] as [$tipo, $texto, $href]) {
                if ($href !== null && !$pode($href)) {
                    continue;
                }
                $itens[] = [
                    'tipo'  => $tipo,
                    'rotulo'=> NOVIDADES_TIPOS[$tipo] ?? $tipo,
                    'texto' => $texto,
                    'href'  => $href,
                ];
            }
            if ($itens) {
                $saida[] = [
                    'versao' => (string) $versao,
                    'data'   => novidadesData((string) $versao),
                    'titulo' => $v['titulo'] ?? '',
                    'itens'  => $itens,
                ];
            }
        }
        return $saida;
    }

    // O que a janela mostra para quem viu por último a versão $vista.
    // Nunca viu nenhuma (usuário novo ou primeira vez da janela): só a mais recente.
    function novidadesPendentes(?string $vista, callable $pode): array
    {
        $todas = novidadesTodas($pode);
        if ($vista === null || $vista === '') {
            return array_slice($todas, 0, 1);
        }
        $novas = array_filter($todas, static fn ($v) => strcmp($v['versao'], $vista) > 0);
        return array_slice(array_values($novas), 0, NOVIDADES_MAX_JANELA);
    }
}
