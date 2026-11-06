<?php
declare(strict_types=1);

// Conteúdo da Documentação. Rodar com: php tests/documentacao/documentacao_test.php

require_once __DIR__ . '/../../config/pages.php';
require_once __DIR__ . '/../../modules/documentacao/documentacao_dados.php';

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

$tudo = static fn (): bool => true;
$nada = static fn (): bool => false;

$semDoc = array_values(array_filter(
    array_column(array_filter($DOMBAG_PAGINAS, static fn ($p) => !empty($p['menu'])), 'href'),
    static fn ($href) => $href !== '/documentacao' && !isset(DOC_TELAS[$href])
));
confere('toda tela do menu está descrita', $semDoc, []);
confere('toda tela usa uma área existente', array_diff(array_column(DOC_TELAS, 0), array_keys(DOC_AREAS)), []);
confere('o caminho usa áreas existentes', array_diff(array_column(DOC_CAMINHO, 2), array_keys(DOC_AREAS)), []);

$paginas = [
    ['href' => '/pcp/agendamento', 'label' => 'Agendamento', 'publico' => false],
    ['href' => '/pcp/tv', 'label' => 'TV', 'publico' => false],
    ['href' => '/minha-senha', 'label' => 'Senha', 'publico' => true],
    ['href' => '/admin/atualiza-bd', 'label' => 'Console', 'publico' => false],
    ['href' => '/nao-documentada', 'label' => 'Nova', 'publico' => false],
];

$comum = docTelasPorArea($paginas, $nada, false);
confere('administração fica oculta', isset($comum['admin']), false);
confere('sem permissão aparece sem link', $comum['planejar']['telas'][0]['acesso'], false);
confere('tela pública sempre tem link', $comum['conta']['telas'][0]['acesso'], true);
confere('área sem tela fica de fora', isset($comum['vendas']), false);
confere('rótulo da área preservado', $comum['planejar']['nome'], 'Reunião e agendamento');

$soAgenda = docTelasPorArea($paginas, static fn ($h) => $h === '/pcp/agendamento', false);
confere('TV herda o acesso do Agendamento', array_column($soAgenda['planejar']['telas'], 'acesso'), [true, true]);

confere('administrador vê a administração', isset(docTelasPorArea($paginas, $tudo, true)['admin']), true);

$paraIa = docTextoParaIa($paginas);
confere('texto da IA traz a tela', str_contains($paraIa, '- Agendamento: ' . DOC_TELAS['/pcp/agendamento'][1]), true);
confere('texto da IA traz o passo a passo', str_contains($paraIa, '1. Abra a Reunião de Planejamento'), true);
confere('texto da IA traz os termos', str_contains($paraIa, '- OP: '), true);
confere('texto da IA não traz administração', str_contains($paraIa, 'Console'), false);
confere('texto da IA respeita menu desativado', str_contains(docTextoParaIa($paginas, ['/pcp/agendamento']), '- Agendamento: '), false);
confere('tela desativada some', count(docTelasPorArea($paginas, $tudo, true, ['/pcp/tv'])['planejar']['telas']), 1);
confere('tela fora do menu não aparece', str_contains(json_encode(docTelasPorArea($paginas, $tudo, true)), 'nao-documentada'), false);

echo $falhas === 0 ? "\nTodos os testes passaram.\n" : "\n{$falhas} falha(s).\n";
exit($falhas === 0 ? 0 : 1);
