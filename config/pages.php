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
    'vendas' => [
        'label'      => 'Vendas',
        'tooltip'    => 'Vendas',
        'admin_only' => false,
        'icon'       => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
    ],
    'yzidro' => [
        'label'      => 'Yzidro',
        'tooltip'    => 'Yzidro',
        'admin_only' => false,
        'icon'       => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 15 21.84"/><path d="M21 5V8"/><path d="M21 12L18 17H22L19 22"/><path d="M3 12A9 3 0 0 0 14.59 14.87"/>',
    ],
    'producao' => [
        'label'      => 'Produção',
        'tooltip'    => 'Produção',
        'admin_only' => false,
        'icon'       => '<path d="M10 10V5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5"/><path d="M14 6a6 6 0 0 1 6 6v3"/><path d="M4 15v-3a6 6 0 0 1 6-6"/><rect x="2" y="15" width="20" height="4" rx="1"/>',
    ],
    'maquinas' => [
        'label'      => 'Máquinas',
        'tooltip'    => 'Máquinas',
        'admin_only' => false,
        'icon'       => '<path d="M11 10.27 7 3.34"/><path d="m11 13.73-4 6.93"/><path d="M12 22v-2"/><path d="M12 2v2"/><path d="M14 12h8"/><path d="m17 20.66-1-1.73"/><path d="m17 3.34-1 1.73"/><path d="M2 12h2"/><path d="m20.66 17-1.73-1"/><path d="m20.66 7-1.73 1"/><path d="m3.34 17 1.73-1"/><path d="m3.34 7 1.73 1"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="12" r="8"/>',
    ],
    'financeiro' => [
        'label'      => 'Financeiro',
        'tooltip'    => 'Financeiro',
        'admin_only' => false,
        'icon'       => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    ],
    'crm' => [
        'label'      => 'CRM',
        'tooltip'    => 'CRM',
        'admin_only' => false,
        'icon'       => '<path d="M13.354 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14v6a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341l1.218-1.348"/><path d="M16 6h6"/><path d="M19 3v6"/>',
    ],
    'trello' => [
        'label'      => 'Trello',
        'tooltip'    => 'Trello',
        'admin_only' => false,
        'icon'       => '<rect x="3" y="3" width="7" height="18" rx="2"/><rect x="14" y="3" width="7" height="11" rx="2"/>',
    ],
    'relatorios' => [
        'label'      => 'Relatórios',
        'tooltip'    => 'Relatórios',
        'admin_only' => false,
        'icon'       => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
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
        'href'    => '/produtos',
        'label'   => 'Consulta de Produtos',
        'grupo'   => null,
        'menu'    => true,
        'publico' => false,
        'icon'    => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    ],
    [
        'href'    => '/produtos/cadastro',
        'label'   => 'Cadastro de Produtos',
        'grupo'   => null,
        'menu'    => true,
        'publico' => false,
        'icon'    => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    ],

    // ── Vendas ────────────────────────────────────────────────────────────────
    ['href' => '/vendas',           'label' => 'Pedidos de Venda',  'grupo' => 'vendas', 'menu' => true,  'publico' => false],
    ['href' => '/vendas/simulacao', 'label' => 'Simulação',         'grupo' => 'vendas', 'menu' => true,  'publico' => false],

    // ── Yzidro ────────────────────────────────────────────────────────────────
    ['href' => '/yzidro/pedidos',     'label' => 'Pedidos de Venda',        'grupo' => 'yzidro', 'menu' => true,  'publico' => false],
    ['href' => '/yzidro/consulta',    'label' => 'Consulta Vendas',         'grupo' => 'yzidro', 'menu' => true,  'publico' => false],
    ['href' => '/yzidro/finalizados', 'label' => 'Finalizados (Sac × Bag)', 'grupo' => 'yzidro', 'menu' => true,  'publico' => false],

    // ── Produção ──────────────────────────────────────────────────────────────
    ['href' => '/pcp/reuniao',     'label' => 'Reunião de Planejamento', 'grupo' => 'producao', 'menu' => true,  'publico' => false],
    ['href' => '/pcp/kanban',      'label' => 'Kanban de Pedidos',       'grupo' => 'producao', 'menu' => true,  'publico' => false],
    ['href' => '/pcp/planejamento','label' => 'Planejamento',            'grupo' => 'producao', 'menu' => true,  'publico' => false],
    ['href' => '/pcp/ordens',      'label' => 'Ordens de Produção',      'grupo' => 'producao', 'menu' => true,  'publico' => false],
    ['href' => '/pcp/programacao', 'label' => 'Programação Diária',      'grupo' => 'producao', 'menu' => true,  'publico' => false],
    ['href' => '/pcp/producao',    'label' => 'Produção',                'grupo' => 'producao', 'menu' => true,  'publico' => false],
    ['href' => '/pcp/pdf',         'label' => 'PDF de Produção',         'grupo' => 'producao', 'menu' => false, 'publico' => false],

    // ── Máquinas ──────────────────────────────────────────────────────────────
    ['href' => '/maquinas',           'label' => 'Consulta de Máquinas', 'grupo' => 'maquinas', 'menu' => true,  'publico' => false],
    ['href' => '/maquinas/cadastro',  'label' => 'Cadastro de Máquinas', 'grupo' => 'maquinas', 'menu' => true,  'publico' => false],
    ['href' => '/pcp/fila',           'label' => 'Fila de Máquinas',     'grupo' => 'maquinas', 'menu' => true,  'publico' => false],

    // ── Financeiro ────────────────────────────────────────────────────────────
    ['href' => '/financeiro',              'label' => 'Dashboard Financeiro', 'grupo' => 'financeiro', 'menu' => true, 'publico' => false],
    ['href' => '/financeiro/receber',      'label' => 'Contas a Receber',     'grupo' => 'financeiro', 'menu' => true, 'publico' => false],
    ['href' => '/financeiro/pagar',        'label' => 'Contas a Pagar',       'grupo' => 'financeiro', 'menu' => true, 'publico' => false],
    ['href' => '/financeiro/baixas-pagar', 'label' => 'Baixas a Pagar',       'grupo' => 'financeiro', 'menu' => true, 'publico' => false],
    ['href' => '/financeiro/fluxo',        'label' => 'Fluxo de Caixa',       'grupo' => 'financeiro', 'menu' => true, 'publico' => false],

    // ── CRM ───────────────────────────────────────────────────────────────────
    ['href' => '/crm/leads',      'label' => 'Leads do Webhook',  'grupo' => 'crm', 'menu' => true, 'publico' => false],
    ['href' => '/crm/resultados', 'label' => 'Prospecção com IA', 'grupo' => 'crm', 'menu' => true, 'publico' => false],

    // ── Trello ────────────────────────────────────────────────────────────────
    ['href' => '/trello', 'label' => 'Pedidos', 'grupo' => 'trello', 'menu' => true, 'publico' => false],

    // ── Relatórios ────────────────────────────────────────────────────────────
    ['href' => '/relatorios/producao',         'label' => 'Relatório de Produção',  'grupo' => 'relatorios', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/estoque-futuro',   'label' => 'Estoque Futuro',         'grupo' => 'relatorios', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/posicao-estoque',  'label' => 'Posição de Estoque',     'grupo' => 'relatorios', 'menu' => true, 'publico' => false],
    ['href' => '/relatorios/controle-estoque', 'label' => 'Controle de Estoque',    'grupo' => 'relatorios', 'menu' => true, 'publico' => false],

    // ── Usuários ──────────────────────────────────────────────────────────────
    ['href' => '/minha-senha',              'label' => 'Minha Senha',          'grupo' => 'usuarios', 'menu' => true,  'publico' => true,  'admin_only' => false],
    ['href' => '/usuarios',                 'label' => 'Consulta de Usuários', 'grupo' => 'usuarios', 'menu' => true,  'publico' => false, 'admin_only' => true],
    ['href' => '/usuarios/cadastro',        'label' => 'Cadastro de Usuários', 'grupo' => 'usuarios', 'menu' => true,  'publico' => false, 'admin_only' => true],
    ['href' => '/usuarios/grupos',          'label' => 'Consulta de Grupos',   'grupo' => 'usuarios', 'menu' => true,  'publico' => false, 'admin_only' => true],
    ['href' => '/usuarios/grupos/cadastro', 'label' => 'Cadastro de Grupos',   'grupo' => 'usuarios', 'menu' => true,  'publico' => false, 'admin_only' => true],

    // ── Parâmetros ────────────────────────────────────────────────────────────
    ['href' => '/modules/parametros/parametros.php', 'label' => 'Parâmetros do Sistema', 'grupo' => 'parametros', 'menu' => true, 'publico' => false],
    ['href' => '/dashboard/widgets',                 'label' => 'Widgets do Dashboard',  'grupo' => 'parametros', 'menu' => true, 'publico' => true],

    // ── Administrador (admin_only) ────────────────────────────────────────────
    ['href' => '/admin/migrations',                  'label' => 'Migrations BD',         'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/admin/atualiza-bd',                 'label' => 'Console SQL',            'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/admin/menus-ativos',                'label' => 'Menus Ativos',           'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],
    ['href' => '/modules/usuarios/permissoes.php',   'label' => 'Permissões por Grupo',   'grupo' => 'administrador', 'menu' => true, 'publico' => false, 'admin_only' => true],

];
