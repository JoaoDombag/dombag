<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

$pdo = dbPDO();
$uid = (int)($_SESSION['usu_codigo'] ?? 0);

// ── Permissões e menus activos ────────────────────────────────────────────────
$_w_admin       = false;
$_w_perms       = [];
$_w_desativados = [];
try {
    $_st_wu = $pdo->prepare('SELECT USU_ADMIN, GRU_CODIGO FROM USUARIOS WHERE USU_CODIGO = :c');
    $_st_wu->execute([':c' => $uid]);
    $_row_wu = $_st_wu->fetch(PDO::FETCH_ASSOC);
    if ($_row_wu) {
        $_w_admin = !empty($_row_wu['USU_ADMIN']);
        if (!$_w_admin && !empty($_row_wu['GRU_CODIGO'])) {
            $_st_wp = $pdo->prepare('SELECT PAC_PAGINA FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :g');
            $_st_wp->execute([':g' => (int)$_row_wu['GRU_CODIGO']]);
            $_w_perms = array_column($_st_wp->fetchAll(PDO::FETCH_ASSOC), 'PAC_PAGINA');
        }
    }
    if ($v = $pdo->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'sidebar_menus_desativados'")->fetchColumn()) {
        $_w_desativados = json_decode($v, true) ?? [];
    }
} catch (Throwable) {}

$_widget_perm_map = [
    'kpi_erp'          => ['/financeiro',  'financeiro'],
    'kpi_local'        => ['/pcp/kanban',  'producao'],
    'kpi_faturamento'  => ['/financeiro',  'financeiro'],
    'ultimas_vendas'   => ['/financeiro',  'financeiro'],
    'maquinas_hoje'    => ['/pcp/producao','producao'],
    'vendas_grupo'     => ['/financeiro',  'financeiro'],
    'prod_7dias'       => ['/pcp/producao','producao'],
    'trello_geral'     => ['/trello',      'trello'],
    'trello_atividade' => ['/trello',      'trello'],
    'financeiro'       => ['/financeiro',  'financeiro'],
];

function widgetPermitido(string $key): bool {
    global $_w_admin, $_w_perms, $_w_desativados, $_widget_perm_map;
    if (!isset($_widget_perm_map[$key])) return true;
    [$page, $menu] = $_widget_perm_map[$key];
    // Menu desativado aplica-se a todos, incluindo admins
    if (in_array($menu, $_w_desativados, true)) return false;
    // Permissão de página: só verifica para não-admins
    if (!$_w_admin && !in_array($page, $_w_perms, true)) return false;
    return true;
}

// ── Definição de todos os widgets disponíveis ─────────────────────────────────
$todosWidgets = [
    [
        'key'   => 'kpi_erp',
        'label' => 'KPIs — ERP',
        'desc'  => 'Pedidos do mês, pedidos hoje e pedidos a importar do ERP',
        'icon'  => '<path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.5 7h13M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>',
    ],
    [
        'key'   => 'kpi_local',
        'label' => 'KPIs — Produção',
        'desc'  => 'Itens finalizados e unidades pendentes de produção',
        'icon'  => '<path d="M2 20h.01M7 20v-4"/><path d="M12 20V10"/><path d="M17 20V4"/>',
    ],
    [
        'key'   => 'ultimas_vendas',
        'label' => 'Últimas Vendas',
        'desc'  => 'Tabela com as 5 últimas vendas do ERP',
        'icon'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
    ],
    [
        'key'   => 'maquinas_hoje',
        'label' => 'Produção por Máquina',
        'desc'  => 'Top máquinas com maior produção no dia de hoje',
        'icon'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    ],
    [
        'key'   => 'vendas_grupo',
        'label' => 'Vendas por Grupo',
        'desc'  => 'Pedidos do mês agrupados por tipo de cliente',
        'icon'  => '<circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><path d="M2 12h20"/>',
    ],
    [
        'key'   => 'prod_7dias',
        'label' => 'Produção — 7 Dias',
        'desc'  => 'Gráfico de barras da produção nos últimos 7 dias',
        'icon'  => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>',
    ],
    [
        'key'   => 'trello_geral',
        'label' => 'Trello — Visão Geral',
        'desc'  => 'Cards por lista com indicadores de vencidos e prazos de hoje',
        'icon'  => '<rect x="3" y="3" width="7" height="18" rx="2"/><rect x="14" y="3" width="7" height="11" rx="2"/>',
    ],
    [
        'key'   => 'trello_atividade',
        'label' => 'Trello — Atividade',
        'desc'  => 'Movimentos recentes de cards entre listas do board',
        'icon'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    ],
    [
        'key'   => 'kpi_faturamento',
        'label' => 'KPIs — Faturamento',
        'desc'  => 'Faturamento bruto do mês e ticket médio por pedido',
        'icon'  => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    ],
    [
        'key'   => 'financeiro',
        'label' => 'Financeiro',
        'desc'  => 'Resumo de contas a pagar e a receber com alertas de vencidos',
        'icon'  => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    ],
];

$paramKey = 'dashboard_widgets_' . $uid;

// ── Carrega preferências atuais do usuário ───────────────────────────────────
function loadWidgets(PDO $pdo, string $key): array
{
    $st = $pdo->prepare('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = :k');
    $st->execute([':k' => $key]);
    $val = $st->fetchColumn();
    if ($val !== false) return json_decode($val, true) ?? [];
    // Sem preferência salva → dashboard vazio por padrão
    return [];
}

$activos = loadWidgets($pdo, $paramKey);
$msg     = '';
$msgType = '';

// ── POST: salvar ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allKeys  = array_column($todosWidgets, 'key');
    $checked  = array_keys(array_filter($_POST['widget'] ?? [], fn($v) => $v === '1'));
    // Respeita a ordem do drag-and-drop se enviada
    $orderRaw = json_decode($_POST['order'] ?? '[]', true);
    $order    = (is_array($orderRaw) && count($orderRaw)) ? $orderRaw : $allKeys;
    // Filtra: só keys válidas, na ordem do drag, apenas as marcadas
    $novosActivos = array_values(array_filter(
        $order,
        fn($k) => in_array($k, $checked, true) && in_array($k, $allKeys, true)
    ));

    try {
        $pdo->prepare("
            INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE PAR_VALOR = VALUES(PAR_VALOR)
        ")->execute([':k' => $paramKey, ':v' => json_encode($novosActivos, JSON_UNESCAPED_UNICODE)]);
        $activos = $novosActivos;
        $msg     = 'Preferências guardadas com sucesso.';
        $msgType = 'ok';
    } catch (Throwable $e) {
        $msg     = 'Erro ao salvar: ' . $e->getMessage();
        $msgType = 'err';
    }
}

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Widgets do Dashboard | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.tip { font-size:12px; color:var(--text-muted); margin-bottom:20px; line-height:1.6; }

.widgets-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}

.widget-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 16px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  cursor: pointer;
  transition: border-color .15s, background .15s;
  user-select: none;
}
.widget-card:hover { background: var(--card-hover); }
.widget-card.active { border-color: rgba(45,106,255,.45); }
.widget-card.inactive { opacity: .55; }

.widget-icon {
  width: 38px; height: 38px;
  border-radius: 9px;
  background: rgba(45,106,255,.1);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  color: #7db3ff;
  transition: background .15s;
}
.widget-card.active .widget-icon { background: rgba(45,106,255,.18); }

.widget-info { flex: 1; min-width: 0; }
.widget-label { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.widget-desc  { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.4; }

/* Toggle igual ao menus_ativos */
.widget-toggle { position: relative; display: inline-flex; align-items: center; flex-shrink: 0; }
.widget-toggle input[type=checkbox] { position: absolute; opacity: 0; width: 0; height: 0; }
.toggle-track {
  width: 36px; height: 20px; border-radius: 999px;
  background: rgba(255,255,255,.08); border: 1px solid var(--border);
  transition: background .2s, border-color .2s;
  display: flex; align-items: center; padding: 2px;
}
.toggle-thumb {
  width: 14px; height: 14px; border-radius: 999px;
  background: var(--text-muted); transition: transform .2s, background .2s;
}
.widget-toggle input:checked + .toggle-track { background: rgba(45,106,255,.25); border-color: rgba(45,106,255,.5); }
.widget-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(16px); background: #4f7bff; }

/* Drag handle */
.drag-handle { display:flex; align-items:center; color:var(--text-muted); cursor:grab; padding:0 4px 0 0; flex-shrink:0; opacity:.45; transition:opacity .15s; }
.widget-card:hover .drag-handle { opacity:.8; }
.widget-card.bloqueado { opacity:.35; cursor:not-allowed; }
.widget-card.bloqueado:hover { background: var(--card-bg); }
.widget-card.bloqueado .drag-handle { display:none; }
.widget-card.dragging { opacity:.4; border-style:dashed; }
.widget-card.drag-over { border-color:rgba(45,106,255,.6); background:var(--card-hover); }

@media(max-width:600px) { .widgets-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Widgets do Dashboard</h1>
          <p>Escolha o que aparece no seu painel de controle</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button type="button" class="btn-secondary" onclick="toggleTodos(true)">Ativar todos</button>
        <button type="button" class="btn-secondary" onclick="toggleTodos(false)">Desativar todos</button>
        <button type="submit" form="formWidgets" class="btn-primary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
            <polyline points="17 21 17 13 7 13"/><polyline points="7 3 7 8 15 8"/>
          </svg>
          Salvar
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($msg): ?>
      <div class="alert <?= $msgType === 'ok' ? 'alert-ok' : 'alert-err' ?>" id="alertMsg">
        <?= $msgType === 'ok'
          ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>'
          : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        ?>
        <?= esc($msg) ?>
      </div>
      <?php endif; ?>

      <p class="tip">
        As preferências são pessoais — cada usuário configura o seu próprio dashboard.<br>
        Clique num card para ativar ou desativar o widget correspondente.
      </p>

      <form method="POST" id="formWidgets">
        <input type="hidden" name="order" id="orderInput" value="<?= esc(json_encode(array_column($todosWidgets, 'key'))) ?>">
        <div class="widgets-grid" id="widgetsGrid">
          <?php
          // Exibe na ordem salva (ativos primeiro), depois os inativos
          $allKeys = array_column($todosWidgets, 'key');
          $todosByKey = array_column($todosWidgets, null, 'key');
          $ordered = array_merge(
              array_filter(array_map(fn($k) => $todosByKey[$k] ?? null, $activos)),
              array_filter($todosWidgets, fn($w) => !in_array($w['key'], $activos, true))
          );
          foreach ($ordered as $w):
            $on      = in_array($w['key'], $activos, true);
            $bloq    = !widgetPermitido($w['key']);
          ?>
          <div class="widget-card <?= $on && !$bloq ? 'active' : 'inactive' ?> <?= $bloq ? 'bloqueado' : '' ?>"
               data-key="<?= esc($w['key']) ?>"
               draggable="<?= $bloq ? 'false' : 'true' ?>"
               <?= $bloq ? '' : 'onclick="toggleWidget(this)"' ?>>
            <span class="drag-handle" onclick="event.stopPropagation()" title="Arrastar para reordenar">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>
            </span>
            <div class="widget-icon">
              <?php if ($bloq): ?>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
              <?php else: ?>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <?= $w['icon'] ?>
              </svg>
              <?php endif; ?>
            </div>
            <div class="widget-info">
              <div class="widget-label"><?= esc($w['label']) ?></div>
              <div class="widget-desc"><?= $bloq ? 'Sem permissão de acesso.' : esc($w['desc']) ?></div>
            </div>
            <?php if ($bloq): ?>
            <span style="font-size:10px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;">Bloqueado</span>
            <?php else: ?>
            <label class="widget-toggle" onclick="event.stopPropagation()">
              <input type="checkbox"
                     name="widget[<?= esc($w['key']) ?>]"
                     value="1"
                     <?= $on ? 'checked' : '' ?>
                     onchange="syncCard(this)">
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </form>

    </div>
  </div>
</div>

<script>
function toggleWidget(card) {
  const chk = card.querySelector('input[type=checkbox]');
  chk.checked = !chk.checked;
  syncCard(chk);
}
function syncCard(chk) {
  const card = chk.closest('.widget-card');
  card.classList.toggle('active',   chk.checked);
  card.classList.toggle('inactive', !chk.checked);
}
function toggleTodos(on) {
  document.querySelectorAll('.widget-card').forEach(card => {
    const chk = card.querySelector('input[type=checkbox]');
    chk.checked = on;
    syncCard(chk);
  });
}

// ── Drag-and-drop para reordenar ──────────────────
(function() {
  const grid = document.getElementById('widgetsGrid');
  let dragged = null;

  grid.addEventListener('dragstart', e => {
    dragged = e.target.closest('.widget-card');
    if (!dragged) return;
    dragged.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  grid.addEventListener('dragend', e => {
    const card = e.target.closest('.widget-card');
    if (card) card.classList.remove('dragging');
    grid.querySelectorAll('.drag-over').forEach(c => c.classList.remove('drag-over'));
    updateOrder();
  });
  grid.addEventListener('dragover', e => {
    e.preventDefault();
    const target = e.target.closest('.widget-card');
    if (!target || target === dragged) return;
    grid.querySelectorAll('.drag-over').forEach(c => c.classList.remove('drag-over'));
    target.classList.add('drag-over');
    const box = target.getBoundingClientRect();
    const after = e.clientY > box.top + box.height / 2;
    grid.insertBefore(dragged, after ? target.nextSibling : target);
  });
  grid.addEventListener('dragleave', e => {
    const target = e.target.closest('.widget-card');
    if (target) target.classList.remove('drag-over');
  });
  grid.addEventListener('drop', e => { e.preventDefault(); });

  function updateOrder() {
    const keys = [...grid.querySelectorAll('.widget-card')].map(c => c.dataset.key);
    document.getElementById('orderInput').value = JSON.stringify(keys);
  }
})();

const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.remove(), 400);
}, 4000);
</script>
</body>
</html>
