<?php
// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Registro Central de Páginas
//
//  Fonte única de verdade para:
//    • Menu lateral (sidebar.php)
//    • Menus Ativos (admin/menus_ativos.php)
//    • Permissões por grupo (usuarios/permissoes.php)
//    • Caminhos sempre acessíveis (login/auth.php)
//
//  AO CRIAR UMA NOVA PÁGINA, adicione-a aqui.
//  Não é preciso editar sidebar.php, menus_ativos.php nem permissoes.php.
//
//  Flags por página:
//    menu    = true  → aparece no menu lateral
//    publico = true  → sempre acessível sem permissão (ex: dashboard, minha-senha)
//    publico = false → requer permissão explícita do grupo do usuário
// ══════════════════════════════════════════════════════════════════════════════

// ── Grupos do menu lateral ────────────────────────────────────────────────────
// icon = conteúdo interno do SVG (sem a tag <svg> externa)
$DOMBAG_GRUPOS = [
    'planejamento' => [
        'label'      => 'Planejamento',
        'tooltip'    => 'Planejamento',
        'admin_only' => false,
        'icon'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m9 16 2 2 4-4"/>',
    ],
    'producao' => [
        'label'      => 'Produção',
        'tooltip'    => 'Produção',
        'admin_only' => false,
        'icon'       => '<path d="M10 10V5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5"/><path d="M14 6a6 6 0 0 1 6 6v3"/><path d="M4 15v-3a6 6 0 0 1 6-6"/><rect x="2" y="15" width="20" height="4" rx="1"/>',
    ],
    'conferencia' => [
        'label'      => 'Conferência',
        'tooltip'    => 'Conferência',
        'admin_only' => false,
        'icon'       => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
    ],
    'comercial' => [
        'label'      => 'Vendas',
        'tooltip'    => 'Vendas',
        'admin_only' => false,
        'icon'       => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
    ],
    'estoque' => [
        'label'      => 'Saída e Estoque',
        'tooltip'    => 'Saída e Estoque',
        'admin_only' => false,
        'icon'       => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    ],
    'cadastros' => [
        'label'      => 'Cadastros',
        'tooltip'    => 'Cadastros',
        'admin_only' => false,
        'icon'       => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    ],
    'usuarios' => [
        'label'      => 'Usuários',
        'tooltip'    => 'Usuários',
        'admin_only' => false,
        'icon'       => '<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
    ],
    'parametros' => [
        'label'      => 'Parâmetros',
        'tooltip'    => 'Parâmetros',
        'admin_only' => false,
        'icon'       => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    ],
    'administrador' => [
        'label'      => 'Administrador',
        'tooltip'    => 'Administrador',
        'admin_only' => true,
        'icon'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    ],
];

// ── Páginas ───────────────────────────────────────────────────────────────────
// Campos:
//   href       string   — URL da página (usado como chave de permissão)
//   label      string   — Nome exibido no menu e nas telas de admin
//   grupo      string|null — chave de $DOMBAG_GRUPOS; null = item standalone no topo do sidebar
//   menu       bool     — aparece no menu lateral
//   publico    bool     — true = sempre acessível; false = requer permissão do grupo
//   admin_only bool     — só visível/acessível a admins (default false)
//   icon       string   — SVG interno (só para itens standalone, grupo=null)
//
// ─────────────────────────────────────────────────────────────────────────────
// ADICIONE NOVAS PÁGINAS AQUI ↓
// ─────────────────────────────────────────────────────────────────────────────
$DOMBAG_PAGINAS = [

    // ── Standalone (topo do sidebar) ──────────────────────────────────────────
    [
        'href'    => '/dashboard',
        'label'   => 'Dashboard',
        'grupo'   => null,
        'menu'    => true,
        'publico' => true,
        'icon'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
    ],
    [
        'href'    => '/assistente',
        'label'   => 'Assistente IA',
        'grupo'   => null,
        'menu'    => true,
        'publico' => true,   // todos os logados; os DADOS continuam limitados por permissão/representante
        'icon'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8"/><path d="M8 13h5"/>',
    ],
    [
        'href'    => '/documentacao',
        'label'   => 'Documentação',
        'grupo'   => null,
        'menu'    => true,
        'publico' => true,
        'icon'    => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    ],
    // ── Planejamento ────────────────────────────────────────────────────────────
    ['href' => '/pcp/reuniao', 'label' => 'Reunião de Planejamento', 'grupo' => 'planejamento', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/agendamento', 'label' => 'Agendamento de Produção', 'grupo' => 'planejamento', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/tv', 'label' => 'Agendamento na TV', 'grupo' => 'planejamento', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/planejamento', 'label' => 'Planejamento', 'grupo' => 'planejamento', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/etapas', 'label' => 'Etapas de Produção', 'grupo' => 'planejamento', 'menu' => false, 'publico' => false],

    // ── Produção ────────────────────────────────────────────────────────────────
    ['href' => '/pcp/tempo-real', 'label' => 'Produção em Tempo Real', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/painel-ops', 'label' => 'Painel de OPs', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/ordens', 'label' => 'Ordens de Produção', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/monitor', 'label' => 'Monitor de Máquinas', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/fila', 'label' => 'Fila de Máquinas', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/yzidro/producao', 'label' => 'Produção (por setor)', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/painel-funcionarios', 'label' => 'Produção por Funcionário', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/painel-funcionarios-pdf', 'label' => 'Relatório de Funcionários (PDF)', 'grupo' => 'producao', 'menu' => false, 'publico' => false],
    ['href' => '/pcp/bi', 'label' => 'BI da Produção', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/bi-individual', 'label' => 'BI Individual', 'grupo' => 'producao', 'menu' => true, 'publico' => false],
    ['href' => '/pcp/pdf', 'label' => 'PDF de Produção', 'grupo' => 'producao', 'menu' => false, 'publico' => false],

    // ── Conferência ─────────────────────────────────────────────────────────────
    ['href' => '/pcp/rastreabilidade', 'label' => 'Rastreabilidade', 'grupo' => 'conferencia', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/defeitos', 'label' => 'Relatório de Defeitos', 'grupo' => 'conferencia', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/producao', 'label' => 'Relatório de Produção', 'grupo' => 'conferencia', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/analise-bag', 'label' => 'Análise de Apontamento', 'grupo' => 'conferencia', 'menu' => true, 'publico' => false],

    // ── Vendas ──────────────────────────────────────────────────────────────────
    ['href' => '/yzidro/pedidos', 'label' => 'Importar Pedidos do ERP', 'grupo' => 'comercial', 'menu' => true, 'publico' => false],
    ['href' => '/yzidro/consulta', 'label' => 'Pedidos em Aberto no ERP', 'grupo' => 'comercial', 'menu' => true, 'publico' => false],
    ['href' => '/vendas', 'label' => 'Pedidos Importados', 'grupo' => 'comercial', 'menu' => true, 'publico' => false],
    ['href' => '/vendas/simulacao', 'label' => 'Simulação', 'grupo' => 'comercial', 'menu' => true, 'publico' => false],
    ['href' => '/trello', 'label' => 'Pedidos no Trello', 'grupo' => 'comercial', 'menu' => true, 'publico' => false],

    // ── Saída e Estoque ─────────────────────────────────────────────────────────
    ['href' => '/relatorios/historico-faturamento', 'label' => 'Histórico de Pedidos Finalizados', 'grupo' => 'estoque', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/posicao-estoque', 'label' => 'Posição de Estoque', 'grupo' => 'estoque', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/estoque-futuro', 'label' => 'Estoque Futuro', 'grupo' => 'estoque', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/controle-estoque', 'label' => 'Controle de Estoque', 'grupo' => 'estoque', 'menu' => true, 'publico' => false],

    // ── Cadastros ───────────────────────────────────────────────────────────────
    ['href' => '/produtos', 'label' => 'Produtos', 'grupo' => 'cadastros', 'menu' => true, 'publico' => false],
    ['href' => '/produtos/cadastro', 'label' => 'Cadastro de Produtos', 'grupo' => 'cadastros', 'menu' => false, 'publico' => false],
    ['href' => '/maquinas', 'label' => 'Máquinas', 'grupo' => 'cadastros', 'menu' => true, 'publico' => false],

    // ── Usuários ────────────────────────────────────────────────────────────────
    ['href' => '/minha-senha', 'label' => 'Minha Senha', 'grupo' => 'usuarios', 'menu' => true, 'publico' => true, 'admin_only' => false],
    ['href' => '/usuarios', 'label' => 'Usuários', 'grupo' => 'usuarios', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/usuarios/cadastro', 'label' => 'Cadastro de Usuários', 'grupo' => 'usuarios', 'menu' => false, 'publico' => false, 'admin_only' => true],
    ['href' => '/usuarios/grupos', 'label' => 'Grupos de Usuários', 'grupo' => 'usuarios', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/usuarios/grupos/cadastro', 'label' => 'Cadastro de Grupos', 'grupo' => 'usuarios', 'menu' => false, 'publico' => false, 'admin_only' => true],

    // ── Parâmetros ──────────────────────────────────────────────────────────────
    ['href' => '/modules/parametros/parametros.php', 'label' => 'Parâmetros do Sistema', 'grupo' => 'parametros', 'menu' => true, 'publico' => true],
    ['href' => '/dashboard/widgets', 'label' => 'Widgets do Dashboard', 'grupo' => 'parametros', 'menu' => true, 'publico' => true],

    // ── Administrador (admin_only) ──────────────────────────────────────────────
    ['href' => '/modules/usuarios/permissoes.php', 'label' => 'Permissões por Grupo', 'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/admin/menus-ativos', 'label' => 'Menus Ativos', 'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/admin/migrations', 'label' => 'Migrations BD', 'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/admin/atualiza-bd', 'label' => 'Console SQL', 'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],
];
