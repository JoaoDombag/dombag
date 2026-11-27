<?php
declare(strict_types=1);

// Novidades ("O que mudou"). Rodar com: php tests/novidades/novidades_test.php

require_once __DIR__ . '/../../config/pages.php';
require_once __DIR__ . '/../../config/novidades.php';

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

// ── O conteúdo de config/novidades.php ───────────────────────────────────────
$hrefs = array_column($DOMBAG_PAGINAS, 'href');
$chavesRuins = $tiposRuins = $telasRuins = $vazias = [];
foreach (NOVIDADES as $versao => $v) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(\.\d+)?$/', (string) $versao)
        || !DateTime::createFromFormat('!Y-m-d', substr((string) $versao, 0, 10))) {
        $chavesRuins[] = $versao;
    }
    if (empty($v['itens'])) {
        $vazias[] = $versao;
    }
    foreach ($v['itens'] ?? [] as [$tipo, $texto, $href]) {
        if (!isset(NOVIDADES_TIPOS[$tipo])) $tiposRuins[] = "{$versao}: {$tipo}";
        if ($href !== null && !in_array($href, $hrefs, true)) $telasRuins[] = "{$versao}: {$href}";
    }
}
confere('versões no formato AAAA-MM-DD ou AAAA-MM-DD.N', $chavesRuins, []);
confere('toda versão tem itens', $vazias, []);
confere('todo item usa um tipo conhecido', $tiposRuins, []);
confere('toda tela citada existe em config/pages.php', $telasRuins, []);
confere('a versão mais recente está no topo do arquivo', array_key_first(NOVIDADES), novidadesUltima());

// ── Regras da janela ─────────────────────────────────────────────────────────
// As funções leem a constante, então as regras são conferidas sobre o
// histórico real, com versões vistas relativas a ele.
$tudo = static fn (): bool => true;
$nada = static fn (): bool => false;
$ultima = novidadesUltima();

confere('quem já viu a última não vê nada', novidadesPendentes($ultima, $tudo), []);
confere('quem nunca viu vê só a última', array_column(novidadesPendentes(null, $tudo), 'versao'), [$ultima]);
confere('vazio conta como nunca viu', array_column(novidadesPendentes('', $tudo), 'versao'), [$ultima]);
confere('quem viu antes de tudo vê todas (até o limite)',
    array_column(novidadesPendentes('2000-01-01', $tudo), 'versao'),
    array_slice(array_column(novidadesTodas($tudo), 'versao'), 0, NOVIDADES_MAX_JANELA));
confere('mesmo dia, segundo deploy vem depois', strcmp('2026-11-24.2', '2026-11-24') > 0, true);
confere('dia seguinte vem depois do .2', strcmp('2026-11-25', '2026-11-24.2') > 0, true);
confere('data por extenso', novidadesData('2026-11-24.2'), '24/11/2026');

// Sem acesso a nenhuma tela: só sobram os itens gerais (sem tela)
$gerais = 0;
foreach (NOVIDADES as $v) foreach ($v['itens'] as $i) if ($i[2] === null) $gerais++;
$semAcesso = array_sum(array_map(static fn ($v) => count($v['itens']), novidadesTodas($nada)));
confere('sem acesso às telas, vê só os itens gerais', $semAcesso, $gerais);
confere('item de tela leva o link', novidadesTodas($tudo)[0]['itens'][0]['href'] !== null, true);

echo $falhas ? "\n{$falhas} falha(s)\n" : "\nTudo certo\n";
exit($falhas ? 1 : 0);
