<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/dashboard/dashboard_widgets.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Widgets do Dashboard (preferências pessoais de cada usuário)
//  O catálogo, as permissões e o armazenamento ficam em dashboard_widgets.php.
// ══════════════════════════════════════════════════════════════════════════════

$uid = (int)($_SESSION['usu_codigo'] ?? 0);

function widgetPermitido(string $key): bool {
    global $uid;
    return dashWidgetPermitido($key, $uid);
}

// ── Definição de todos os widgets disponíveis ─────────────────────────────────
$todosWidgets = [];
foreach (DASH_WIDGETS as $k => $d) {
    $todosWidgets[] = ['key' => $k, 'label' => $d['label'], 'desc' => $d['desc'], 'icon' => $d['icon']];
}

$activos = dashWidgetsDoUsuario($uid);
$msg     = '';
$msgType = '';

// ── POST: salvar (PRG — F5 não reenvia) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allKeys  = array_column($todosWidgets, 'key');
    $checked  = array_keys(array_filter($_POST['widget'] ?? [], fn($v) => $v === '1'));
    // Respeita a ordem do drag-and-drop se enviada
    $orderRaw = json_decode($_POST['order'] ?? '[]', true);
    $order    = (is_array($orderRaw) && count($orderRaw)) ? $orderRaw : $allKeys;
    if (isset($_POST['restaurar'])) {
        $novosActivos = DASH_WIDGETS_PADRAO;
    } else {
        // Só keys válidas, na ordem do drag, apenas as marcadas
        $novosActivos = array_values(array_filter(
            $order,
            fn($k) => in_array($k, $checked, true) && in_array($k, $allKeys, true)
        ));
    }

    try {
        dashSalvarWidgets($uid, $novosActivos);
        $_SESSION['widgets_flash'] = 'Preferências salvas com sucesso.';
        header('Location: /dashboard/widgets');
        exit;
    } catch (Throwable $e) {
        $msg     = 'Erro ao salvar: ' . $e->getMessage();
        $msgType = 'err';
    }
} elseif (!empty($_SESSION['widgets_flash'])) {
    $msg     = $_SESSION['widgets_flash'];
    $msgType = 'ok';
    unset($_SESSION['widgets_flash']);
}

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
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
        <button type="submit" form="formWidgets" name="restaurar" value="1" class="btn-secondary" title="Volta para os widgets de produção e saída">Restaurar padrão</button>
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
