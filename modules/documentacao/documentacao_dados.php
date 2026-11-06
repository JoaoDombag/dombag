<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Conteúdo da Documentação (/documentacao)
//  Ao criar uma tela em config/pages.php, descreva-a em DOC_TELAS.
//  tests/documentacao/documentacao_test.php acusa tela de menu sem descrição.
//  Nome e endereço vêm sempre de config/pages.php. Tela removida de lá some
//  daqui sozinha, por isso DOC_TELAS pode ter mais entradas que o menu.
// ══════════════════════════════════════════════════════════════

const DOC_AREAS = [
    'inicio'    => ['Início', 'O painel de abertura e o Assistente IA.'],
    'vendas'    => ['Pedido', 'Onde o pedido entra e como ele chega do ERP.'],
    'planejar'  => ['Reunião e agendamento', 'O que será produzido e em que dia.'],
    'produzir'  => ['Produção', 'O que está acontecendo na fábrica agora.'],
    'conferir'  => ['Conferência', 'Defeitos, rastreio e fechamento da produção.'],
    'saida'     => ['Saída e estoque', 'O que saiu da fábrica e o que há em estoque.'],
    'cadastros' => ['Cadastros e CRM', 'Produtos, máquinas, Trello e WhatsApp dos vendedores.'],
    'conta'     => ['Conta e acesso', 'Sua senha, usuários e grupos.'],
    'admin'     => ['Administração', 'Telas exclusivas de quem administra o sistema.'],
];

// Etapas reais do pedido na fábrica, na ordem em que acontecem.
const DOC_CAMINHO = [
    ['Pedido', 'Entra pelo ERP', 'vendas'],
    ['Reunião', 'Vai para a programação', 'planejar'],
    ['Agendamento', 'Ganha dia na agenda', 'planejar'],
    ['Produção', 'Os bags são apontados', 'produzir'],
    ['Conferência', 'Defeitos e rastreio', 'conferir'],
    ['Saída', 'O pedido é finalizado', 'saida'],
];

// href => [área, o que a tela faz, tela cuja permissão vale aqui (opcional)]
const DOC_TELAS = [
    '/dashboard'         => ['inicio', 'Resumo do dia: bags produzidos, pedidos finalizados e OPs em andamento.'],
    '/assistente'        => ['inicio', 'Tire dúvidas sobre como usar o sistema. Administradores e representantes também consultam dados e pedem relatórios em PDF ou Excel.'],
    '/dashboard/widgets' => ['inicio', 'Escolha o que aparece no seu Dashboard. Vale só para você.'],

    '/vendas'            => ['vendas', 'Pedidos já importados do ERP, com a data de Saída/Faturamento da venda atacado. Clique no + de um pedido para ver a data e os itens.'],
    '/yzidro/pedidos'    => ['vendas', 'No menu Yzidro: compara os pedidos aprovados no ERP com os do sistema e importa os selecionados. Mostra a Saída/Faturamento na lista e no + de cada pedido.'],
    '/yzidro/consulta'   => ['vendas', 'Pedidos aprovados no ERP que ainda não foram finalizados.'],
    '/vendas/simulacao'  => ['vendas', 'Mostra a ocupação de cada máquina e a capacidade livre para novos pedidos.'],

    '/pcp/reuniao'       => ['planejar', 'Programação dos pedidos. Na linha de cada um você altera entrega esperada, início da produção e etapa, e vê o andamento com o link para o calendário.'],
    '/pcp/agendamento'   => ['planejar', 'Calendário do mês com os pedidos planejados. Arraste de "A agendar" para definir o dia, divida a quantidade em vários dias e marque o realizado. Pedido sem dia na agenda fica só em "A agendar". Big Bag aparece em verde e Sacaria em roxo.'],
    '/pcp/tv'            => ['planejar', 'O calendário do Agendamento para a TV da fábrica, somente leitura. No dia de hoje mostra também o que está em produção sem agendamento.', '/pcp/agendamento'],
    '/pcp/kanban'        => ['planejar', 'Quadro com os pedidos por etapa. Arraste um card para mudar a etapa. Cada card mostra o andamento e o link para o calendário.'],
    '/pcp/planejamento'  => ['planejar', 'Pedidos em produção, marcados no kanban antes da reunião.'],
    '/pcp/etapas'        => ['planejar', 'Cadastro das etapas usadas na Reunião de Planejamento.', '/pcp/reuniao'],
    '/pcp/status-ops'    => ['planejar', 'Situação das OPs programadas, com o andamento e o link para o calendário. Pedido todo produzido não conta como atrasado.'],

    '/pcp/tempo-real'    => ['produzir', 'Acompanhe os apontamentos abertos, com atualização automática.'],
    '/pcp/painel-ops'    => ['produzir', 'Status e produção de cada ordem de produção.'],
    '/pcp/ordens'        => ['produzir', 'Lista de ordens de produção. Clique em uma ordem para ver os detalhes.'],
    '/pcp/monitor'       => ['produzir', 'Acompanhamento da produção de cada máquina em tempo real.'],
    '/pcp/fila'          => ['produzir', 'Fila de trabalho das máquinas.'],
    '/pcp/painel-funcionarios' => ['produzir', 'Bags produzidos por pessoa no período, com relatório em PDF.'],
    '/pcp/bi'            => ['produzir', 'Painel para TV com bags do mês, meta, OPs ativas e produção por pessoa.'],
    '/pcp/bi-individual' => ['produzir', 'Total de bags do dia contra a meta, e a produção de cada pessoa.'],
    '/yzidro/producao'   => ['produzir', 'Ordens finalizadas, com os itens separados por setor.'],

    '/relatorios/producao'      => ['conferir', 'Produção por máquina e por dia, com bag e sacaria somados. Gera PDF.'],
    '/relatorios/defeitos'      => ['conferir', 'Bags produzidos, com defeito, corrigidos e descartados no período. Gera PDF.'],
    '/pcp/rastreabilidade'      => ['conferir', 'Encontre um bag, uma OP, um pedido ou um lote: quem produziu, quando e com qual material.'],
    '/relatorios/analise-bag'   => ['conferir', 'Análise dos apontamentos do ERP.'],

    '/relatorios/historico-faturamento' => ['saida', 'Pedidos que saíram no período, pela data de finalização. Valores sem IPI. Gera PDF.'],
    '/relatorios/posicao-estoque'  => ['saida', 'Saldo atual por local, vindo do ERP. Gera PDF.'],
    '/relatorios/estoque-futuro'   => ['saida', 'Estoque atual mais pedidos pendentes, menos o reservado. Gera PDF.'],
    '/relatorios/controle-estoque' => ['saida', 'Planilha de controle integrada pelo Google Sheets.'],

    '/produtos'          => ['cadastros', 'Lista dos produtos cadastrados.'],
    '/maquinas'          => ['cadastros', 'Consulta das máquinas cadastradas no ERP.'],
    '/trello'            => ['cadastros', 'Quadro de pedidos do Trello dentro do sistema.'],
    '/modules/crm/whatsapp_sincronizar.php' => ['cadastros', 'Conecta o WhatsApp Web de cada vendedor. Faça isso antes de sincronizar os contatos.'],
    '/modules/crm/whatsapp_contatos.php'    => ['cadastros', 'Agenda dos vendedores em um só lugar, com origem e último contato.'],

    '/minha-senha'       => ['conta', 'Altere a sua senha de acesso.'],
    '/usuarios'          => ['conta', 'Gerencie quem acessa o sistema.'],
    '/usuarios/grupos'   => ['conta', 'Organize os usuários em grupos para controlar as permissões.'],
    '/modules/parametros/parametros.php' => ['conta', 'Integrações e preferências gerais do sistema.'],

    '/admin/migrations'  => ['admin', 'Histórico das atualizações do banco de dados.'],
    '/admin/atualiza-bd' => ['admin', 'Executa SQL direto no banco. Use com cuidado: a alteração é imediata.'],
    '/admin/menus-ativos' => ['admin', 'Define quais telas aparecem no menu para todos os usuários.'],
    '/modules/usuarios/permissoes.php' => ['admin', 'Define quais telas cada grupo pode acessar.'],
];

const DOC_GUIAS = [
    ['Programar e agendar um pedido', [
        'Abra a Reunião de Planejamento e use "Adicionar Pedido à Programação".',
        'Busque a OP, informe a entrega esperada e, se já souber, o início da produção. Depois adicione.',
        'No Agendamento de Produção, arraste o pedido de "A agendar" para o dia.',
        'No celular ou pelo teclado, abra o pedido, informe dia e quantidade e clique em Agendar.',
    ]],
    ['Dividir o pedido em dias', [
        'No Agendamento de Produção, arraste o pedido de "A agendar" para o primeiro dia.',
        'Clique no card e reduza "Previsto no dia", por exemplo de 1.000 para 300.',
        'O restante volta para "A agendar". Arraste de novo para os próximos dias.',
    ]],
    ['Marcar o que foi realmente produzido', [
        'No Agendamento de Produção, clique no card de hoje ou de um dia passado.',
        'Preencha "Realizado no dia" e clique em Salvar realizado. Zero significa que nada foi produzido.',
        'O realizado substitui o número do ERP no card. O que faltou volta para "A agendar".',
        'Deixe o campo vazio e salve para voltar a mostrar o número do ERP.',
    ]],
    ['Mudar de dia um pedido que não iniciou', [
        'No Agendamento de Produção, arraste o card para hoje ou um dia à frente, mesmo que o dia previsto já tenha passado.',
        'Um dia passado só pode ser movido se não teve produção, nem realizado marcado, nem lançamento no ERP. O card sai com a quantidade inteira.',
        'Se o dia de destino já tem esse pedido, as quantidades são somadas.',
        'Se o saldo que faltou já foi agendado por "A agendar", não arraste o card antigo: a previsão passaria do total do pedido.',
    ]],
    ['Marcar início da produção e entrega esperada', [
        'Na Reunião de Planejamento, altere as datas na linha do pedido. Cada data salva ao ser alterada.',
        'No Agendamento, clique no pedido, altere as datas e clique em Salvar datas.',
        'As duas datas aparecem no calendário. O início aparece também na TV, com a cor da legenda.',
        'O início pode ficar vazio. A entrega esperada é obrigatória.',
    ]],
    ['Encontrar um bag, uma OP, um pedido ou um lote', [
        'Abra a Rastreabilidade.',
        'Digite o NSU, a OP, o pedido ou o lote.',
        'Veja quem produziu, quando e com qual material.',
    ]],
    ['Colocar o Agendamento na TV da fábrica', [
        'No navegador da TV, abra o endereço do sistema seguido de /pcp/tv.',
        'Entre com um usuário que tenha acesso ao Agendamento.',
        'A tela atualiza sozinha. O selo no alto mostra se os dados estão ao vivo ou desatualizados.',
    ]],
    ['Gerar um relatório com o Assistente IA (administradores e representantes)', [
        'Abra o Assistente IA ou o chat no canto inferior direito.',
        'Escreva o que quer saber, com o período.',
        'Peça o resultado em PDF ou Excel e baixe pelo link da resposta.',
    ]],
    ['Liberar uma tela para alguém', [
        'Em Grupos de Usuários, confira em qual grupo a pessoa está.',
        'Em Permissões por Grupo, marque a tela para esse grupo.',
        'Somente administradores podem alterar permissões.',
    ]],
];

const DOC_NUMEROS = [
    ['Por que a sacaria não aparece em tempo real?',
     'Só os bags têm apontamento. A sacaria entra quando a OP é lançada no ERP, geralmente de uma vez, então a data é a do lançamento e não a da fabricação.'],
    ['"Pedidos finalizados" é o mesmo que faturamento?',
     'Não. Pedidos finalizados usam o valor do pedido, sem IPI, na data em que ele saiu. Faturamento é o total das NF-e autorizadas. Os dois normalmente não batem.'],
    ['Por que o Relatório de Produção é diferente do BI?',
     'O Relatório de Produção soma bag e sacaria por máquina. O BI e o Painel de Funcionários contam só bags apontados.'],
    ['Por que um pedido não aparece no calendário?',
     'O calendário mostra só pedidos com pelo menos um dia na agenda. Pedido da Reunião sem dia marcado fica em "A agendar". Na tela cheia aparecem apenas os cards dos dias agendados.'],
    ['Por que o "Produzido no mês" do Agendamento é menor que o de outros relatórios?',
     'Ele soma só os pedidos planejados na agenda, com a produção do ERP. Os relatórios de produção somam tudo o que foi produzido. O realizado marcado à mão não entra nesse total.'],
    ['Por que uma OP agendada aparece sem produção?',
     'Em entrega parcial, a OP é encerrada e o restante vira uma nova OP. As leituras de bag costumam continuar na OP original, então a OP nova pode ficar sem produção no ERP. Nesse caso, marque o realizado no dia.'],
    ['Qual é a diferença entre Entrega e Entrega Esperada na Reunião?',
     'Entrega vem da OP no ERP e não pode ser alterada aqui. Entrega Esperada é definida na Reunião e é a data usada no calendário.'],
    ['O que é a Saída/Faturamento dos pedidos?',
     'É a data informada na venda atacado do ERP. Ela pode estar no futuro enquanto o pedido aguarda produção, então não significa que o pedido já saiu.'],
    ['O que muda no ERP quando uso o sistema?',
     'Nada. As consultas ao ERP Yzidro são somente leitura. Reunião, agenda, etapas e datas ficam no sistema DOMBAG.'],
    ['O que o Assistente IA responde?',
     'Administradores consultam pedidos, faturamento e produção. Representantes consultam só os próprios pedidos. Os demais usuários tiram dúvidas sobre o uso do sistema, com base nesta documentação.'],
    ['O lote mostrado na Rastreabilidade é sempre o físico?',
     'Nem sempre. O lote é gerado por OP no fechamento, não por bag. Em entrega parcial, o lote mostrado para um bag pode não ser o dele.'],
];

const DOC_TERMOS = [
    ['OP', 'Ordem de produção. É a unidade de trabalho da fábrica e costuma estar ligada a um pedido.'],
    ['Apontamento', 'Registro no ERP de quem trabalhou em uma OP, com início, fim e quantidade.'],
    ['Apontamento aberto', 'Trabalho iniciado e ainda sem fim registrado. É o que a TV marca como "Produzindo agora".'],
    ['NSU', 'Número que identifica um bag individual e permite rastreá-lo.'],
    ['Lote', 'Identificação do material consumido ou do produto gerado em uma OP.'],
    ['Agendado', 'Quantidade prevista para um dia no Agendamento de Produção.'],
    ['A agendar', 'Saldo do pedido sem dia marcado. Inclui o que faltou produzir em dias passados.'],
    ['Realizado', 'Quantidade marcada à mão como produzida no dia. No card, vale no lugar do número do ERP.'],
    ['Andamento', 'Quanto do pedido já foi produzido e quanto falta agendar, por exemplo "550 de 1.000 bags, 350 a agendar".'],
    ['Saída/Faturamento', 'Data da venda atacado no ERP, mostrada nos Pedidos de Venda.'],
    ['Em aberto no ERP', 'Quantidade de sacaria ainda esperada na OP, que não conta como produzida.'],
    ['Etapa', 'Fase do pedido na Reunião de Planejamento, como no Kanban.'],
];

function docMenusDesativados(PDO $pdo): array
{
    try {
        $st = $pdo->prepare("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'sidebar_menus_desativados'");
        $st->execute();
        $valor = $st->fetchColumn();
        return $valor ? (json_decode((string) $valor, true) ?? []) : [];
    } catch (Throwable) {
        return [];
    }
}

// A Documentação em texto corrido, base das respostas do Assistente para quem não
// consulta dados. Sem as telas de administração, como a página mostra a esse usuário.
function docTextoParaIa(array $paginas, array $desativadas = []): string
{
    $partes = ['Caminho do pedido: ' . implode(' > ', array_map(
        static fn ($e) => "{$e[0]} ({$e[1]})",
        DOC_CAMINHO
    ))];

    foreach (docTelasPorArea($paginas, static fn (): bool => true, false, $desativadas) as $area) {
        $linhas = array_map(static fn ($t) => "- {$t['nome']}: {$t['texto']}", $area['telas']);
        $partes[] = "Telas de {$area['nome']}. {$area['texto']}\n" . implode("\n", $linhas);
    }
    foreach (DOC_GUIAS as [$titulo, $passos]) {
        $numerados = array_map(static fn ($n, $p) => ($n + 1) . ". {$p}", array_keys($passos), $passos);
        $partes[] = "Passo a passo: {$titulo}\n" . implode("\n", $numerados);
    }
    $partes[] = "Perguntas frequentes\n" . implode("\n", array_map(static fn ($p) => "- {$p[0]} {$p[1]}", DOC_NUMEROS));
    $partes[] = "Termos\n" . implode("\n", array_map(static fn ($t) => "- {$t[0]}: {$t[1]}", DOC_TERMOS));
    return implode("\n\n", $partes);
}

// Agrupa por área as telas descritas que existem no menu e o usuário pode ver.
// $pode(href) diz se a pessoa tem acesso; sem acesso a tela aparece sem link.
function docTelasPorArea(array $paginas, callable $pode, bool $admin, array $desativadas = []): array
{
    $porHref = array_column($paginas, null, 'href');
    $areas   = [];
    foreach (DOC_TELAS as $href => $doc) {
        $pagina = $porHref[$href] ?? null;
        $area   = $doc[0];
        $soAdmin = $area === 'admin' || !empty($pagina['admin_only']);
        if ($pagina === null || in_array($href, $desativadas, true) || ($soAdmin && !$admin)) {
            continue;
        }
        $areas[$area][] = [
            'href'   => $href,
            'nome'   => $pagina['label'],
            'texto'  => $doc[1],
            'acesso' => !empty($pagina['publico']) || $pode($doc[2] ?? $href),
        ];
    }

    $saida = [];
    foreach (DOC_AREAS as $id => [$nome, $texto]) {
        if (isset($areas[$id])) {
            $saida[$id] = ['nome' => $nome, 'texto' => $texto, 'telas' => $areas[$id]];
        }
    }
    return $saida;
}
