<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Catálogo de widgets do Dashboard + preferências por usuário
//  Usado por modules/dashboard/dashboard.php e modules/parametros/widgets.php
// ══════════════════════════════════════════════════════════════════════

// Preferências salvas em PARAMETROS['dashboard_widgets_v2_<usuário>'] (lista
// ordenada de chaves). O "v2" separa das preferências do dashboard antigo,
// cujos widgets liam tabelas locais que pararam de ser alimentadas.
const DASH_WIDGETS_PREFIXO = 'dashboard_widgets_v2_';

// key => [rótulo, descrição, ícone SVG, página exigida, grupo de menu]
const DASH_WIDGETS = [
    'kpi_producao' => [
        'label' => 'KPIs — Produção',
        'desc'  => 'Produzido hoje (vs. dia anterior), produção do mês x meta e quem está produzindo agora',
        'icon'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'pagina' => '/pcp/bi', 'menu' => 'producao',
    ],
    'kpi_saida' => [
        'label' => 'KPIs — Saída',
        'desc'  => 'Pedidos que saíram hoje e no mês (bags e sacaria) e valor faturado',
        'icon'  => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'pagina' => '/relatorios/historico-faturamento', 'menu' => 'relatorios',
    ],
    'grafico_14dias' => [
        'label' => 'Produção x Saída — 14 Dias',
        'desc'  => 'Gráfico diário de bags produzidos x bags que saíram',
        'icon'  => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>',
        'pagina' => '/pcp/bi', 'menu' => 'producao',
    ],
    'ops_produzindo' => [
        'label' => 'O.Ps em Produção Agora',
        'desc'  => 'O.Ps com apontamento aberto: cliente, pessoas e quanto já foi lido do pedido',
        'icon'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'pagina' => '/pcp/bi', 'menu' => 'producao',
    ],
    'saidas_hoje' => [
        'label' => 'Saídas de Hoje',
        'desc'  => 'Lista dos pedidos que saíram hoje, com cliente, quantidades e valor',
        'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
        'pagina' => '/relatorios/historico-faturamento', 'menu' => 'relatorios',
    ],
    'trello_geral' => [
        'label' => 'Trello — Visão Geral',
        'desc'  => 'Cards por lista com indicadores de vencidos e prazos de hoje',
        'icon'  => '<rect x="3" y="3" width="7" height="18" rx="2"/><rect x="14" y="3" width="7" height="11" rx="2"/>',
        'pagina' => '/trello', 'menu' => 'trello',
    ],
    'trello_atividade' => [
        'label' => 'Trello — Atividade',
        'desc'  => 'Movimentos recentes de cards entre listas do board',
        'icon'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'pagina' => '/trello', 'menu' => 'trello',
    ],
];

// Sem preferência salva: os de produção e saída (Trello só se a pessoa ligar)
const DASH_WIDGETS_PADRAO = ['kpi_producao', 'kpi_saida', 'grafico_14dias', 'ops_produzindo', 'saidas_hoje'];

// Admin, permissões do grupo e menus desativados — carregados uma vez
function dashAcesso(int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    $a = ['admin' => false, 'perms' => [], 'desativados' => []];
    try {
        $pdo = dbPDO();
        $st = $pdo->prepare('SELECT USU_ADMIN, GRU_CODIGO FROM USUARIOS WHERE USU_CODIGO = :c');
        $st->execute([':c' => $uid]);
        if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
            $a['admin'] = !empty($u['USU_ADMIN']);
            if (!$a['admin'] && !empty($u['GRU_CODIGO'])) {
                $sp = $pdo->prepare('SELECT PAC_PAGINA FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :g');
                $sp->execute([':g' => (int) $u['GRU_CODIGO']]);
                $a['perms'] = $sp->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        if ($v = $pdo->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'sidebar_menus_desativados'")->fetchColumn()) {
            $a['desativados'] = json_decode($v, true) ?? [];
        }
    } catch (Throwable) {}
    return $cache[$uid] = $a;
}

// O usuário pode ver o widget? (menu desativado vale até pra admin)
function dashWidgetPermitido(string $key, int $uid): bool
{
    if (!isset(DASH_WIDGETS[$key])) {
        return false;
    }
    $a = dashAcesso($uid);
    $w = DASH_WIDGETS[$key];
    if (in_array($w['menu'], $a['desativados'], true)) {
        return false;
    }
    return $a['admin'] || in_array($w['pagina'], $a['perms'], true);
}

// Lista ordenada dos widgets ativos do usuário (padrão se nunca personalizou)
function dashWidgetsDoUsuario(int $uid): array
{
    try {
        $st = dbPDO()->prepare('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = :k');
        $st->execute([':k' => DASH_WIDGETS_PREFIXO . $uid]);
        $v = $st->fetchColumn();
        if ($v !== false) {
            $lista = json_decode((string) $v, true);
            return is_array($lista) ? array_values(array_filter($lista, fn ($k) => isset(DASH_WIDGETS[$k]))) : [];
        }
    } catch (Throwable) {}
    return DASH_WIDGETS_PADRAO;
}

function dashSalvarWidgets(int $uid, array $lista): void
{
    $lista = array_values(array_unique(array_filter($lista, fn ($k) => isset(DASH_WIDGETS[$k]))));
    dbPDO()->prepare('
        INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE PAR_VALOR = VALUES(PAR_VALOR)
    ')->execute([':k' => DASH_WIDGETS_PREFIXO . $uid, ':v' => json_encode($lista)]);
}
