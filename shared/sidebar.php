<?php
// Suporta tanto URLs amigáveis quanto URLs físicas
$_uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

// ── Board ID do Trello (para notificações) ────────────────────────────────────
$_sb_trello_board = '';
try {
    $__pdo_ntf = dbPDO();
    $__st_ntf  = $__pdo_ntf->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'trello_default_board'");
    $_sb_trello_board = (string)($__st_ntf->fetchColumn() ?: '');
} catch (Throwable) {}

// ── Permissões do usuário logado ──────────────────────────────────────────────
$_sb_admin = false;
$_sb_perms = []; // páginas liberadas; vazio = sem acesso (exceto admin)

if (!empty($_SESSION['usu_codigo'])) {
    try {
        $__pdo = dbPDO();
        $__u = $__pdo->prepare('SELECT USU_ADMIN, GRU_CODIGO FROM USUARIOS WHERE USU_CODIGO = :c');
        $__u->execute([':c' => (int)$_SESSION['usu_codigo']]);
        $__row = $__u->fetch(PDO::FETCH_ASSOC);
        if ($__row) {
            $_sb_admin = !empty($__row['USU_ADMIN']);
            if (!$_sb_admin && $__row['GRU_CODIGO']) {
                $__p = $__pdo->prepare('SELECT PAC_PAGINA FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :g');
                $__p->execute([':g' => (int)$__row['GRU_CODIGO']]);
                $_sb_perms = array_column($__p->fetchAll(PDO::FETCH_ASSOC), 'PAC_PAGINA');
            }
        }
    } catch (Throwable) {}
}

// Páginas sempre visíveis na sidebar para qualquer usuário autenticado
$__sb_always_visible = ['/dashboard', '/dashboard/widgets', '/minha-senha'];

function sb_can(string $href): bool {
    global $_sb_admin, $_sb_perms, $__sb_always_visible;
    if ($_sb_admin) return true;
    if (in_array($href, $__sb_always_visible, true)) return true;
    return in_array($href, $_sb_perms, true);
}

// ── Menus desativados pelo administrador ──────────────────────────────────────
$_sb_desativados = [];
try {
    $__cfg = (isset($__pdo) ? $__pdo : dbPDO())->prepare(
        "SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'sidebar_menus_desativados'"
    );
    $__cfg->execute();
    $__val = $__cfg->fetchColumn();
    if ($__val) $_sb_desativados = json_decode($__val, true) ?? [];
} catch (Throwable) {}

function sb_ativo(string $key): bool {
    global $_sb_desativados;
    return !in_array($key, $_sb_desativados, true);
}

function nav_active(string $path): string
{
    global $_uri, $_script;
    $p = strtok($path, '?');
    if ($_uri === $p || $_script === $p) {
        return 'active';
    }
    if (str_ends_with($_script, ltrim($p, '/'))) {
        return 'active';
    }
    return '';
}

function group_open(array $paths): string
{
    global $_uri, $_script;
    foreach ($paths as $p) {
        $p = strtok($p, '?');
        if ($_uri === $p || $_script === $p) {
            return 'open';
        }
        if (str_ends_with($_script, ltrim($p, '/'))) {
            return 'open';
        }
    }
    return '';
}

// ── Monta $grupos e $standalone_paginas a partir do registro central ──────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/pages.php';

$grupos = [];
foreach ($DOMBAG_GRUPOS as $gkey => $gdef) {
    $grupos[$gkey] = [
        'label'      => $gdef['label'],
        'tooltip'    => $gdef['tooltip'],
        'icon'       => $gdef['icon'],
        'admin_only' => $gdef['admin_only'] ?? false,
        'itens'      => [],
    ];
}
foreach ($DOMBAG_PAGINAS as $_pag) {
    if (!isset($_pag['grupo']) || $_pag['grupo'] === null) continue;
    if (empty($_pag['menu'])) continue;
    $gkey = $_pag['grupo'];
    if (!isset($grupos[$gkey])) continue;
    $item = ['label' => $_pag['label'], 'href' => $_pag['href']];
    if (!empty($_pag['admin_only'])) $item['admin_only'] = true;
    $grupos[$gkey]['itens'][] = $item;
}

// Itens standalone (topo do sidebar, grupo=null, menu=true)
$standalone_paginas = array_values(
    array_filter($DOMBAG_PAGINAS, fn($p) => $p['grupo'] === null && !empty($p['menu']))
);
unset($_pag, $gkey, $item);
?>

<style>
.sidebar {
  width: var(--sidebar-w);
  min-width: var(--sidebar-w);
  background: var(--blue-mid);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  transition: width var(--transition), min-width var(--transition);
  overflow: hidden;
  position: relative;
  z-index: 30;
}

.sidebar.collapsed {
  width: var(--sidebar-w-col);
  min-width: var(--sidebar-w-col);
}

.sidebar-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 16px 14px;
  border-bottom: 1px solid var(--border);
}
.sidebar.collapsed .sidebar-head {
  justify-content: center;
  padding-inline: 0;
}

.sidebar-toggle-btn {
  width: 100%;
  height: 42px;
  min-width: 42px;
  border: none;
  border-radius: 12px;
  background: rgba(255,255,255,.08);
  color: var(--text-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background .15s ease, transform .15s ease;
}
.sidebar-toggle-btn:hover {
  background: rgba(255,255,255,.08);
  transform: translateY(-1px);
}

.sidebar.collapsed .sidebar-toggle-btn {
  width: 70%;
}

.nav-section {
  flex: 1;
  overflow: auto;
  padding: 14px 10px 16px;
}
.nav-section::-webkit-scrollbar { width: 6px; }
.nav-section::-webkit-scrollbar-thumb {
  background: rgba(144,160,183,.22);
  border-radius: 999px;
}

.nav-sem-permissao {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 12px 10px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(255,255,255,.04);
  color: var(--text-muted);
  font-size: 11.5px;
  line-height: 1.5;
}
.sidebar.collapsed .nav-sem-permissao { display: none; }

.nav-label {
  padding: 0 12px;
  margin: 12px 0 8px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--text-muted);
  transition: opacity var(--transition), max-height var(--transition), margin var(--transition);
}
.sidebar.collapsed .nav-label {
  opacity: 0;
  max-height: 0;
  margin: 0;
  padding: 0;
  overflow: hidden;
}

.nav-item,
.nav-group-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  padding: 10px 12px;
  margin-bottom: 4px;
  border: none;
  border-radius: 12px;
  background: transparent;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  position: relative;
  transition: background .15s ease, color .15s ease;
}
.nav-item:hover,
.nav-group-btn:hover {
  background: rgba(255,255,255,.05);
  color: var(--text-primary);
}
.nav-item.active,
.nav-group.has-active > .nav-group-btn {
  background: rgba(79,123,255,.12);
  color: #cfe0ff;
}

.nav-icon {
  width: 18px;
  min-width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.nav-text,
.nav-sub-text {
  overflow: hidden;
  white-space: nowrap;
  transition: opacity var(--transition), max-width var(--transition);
  max-width: 180px;
}

.nav-arrow {
  width: 14px;
  height: 14px;
  margin-left: auto;
  opacity: .65;
  transition: transform var(--transition), opacity var(--transition), max-width var(--transition);
}
.nav-group.open > .nav-group-btn .nav-arrow {
  transform: rotate(180deg);
  opacity: 1;
}

.nav-sub {
  overflow: hidden;
  max-height: 0;
  opacity: 0;
  transition: max-height .25s ease, opacity .18s ease;
}
.nav-group.open > .nav-sub {
  max-height: 420px;
  opacity: 1;
}

.nav-sub-item {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 4px 34px;
  padding: 8px 12px;
  border-radius: 10px;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 12.5px;
  font-weight: 500;
  transition: background .15s ease, color .15s ease;
}
.nav-sub-item:hover {
  background: rgba(255,255,255,.04);
  color: var(--text-primary);
}
.nav-sub-item.active {
  background: rgba(79,123,255,.10);
  color: #cfe0ff;
}

.nav-dot {
  width: 6px;
  height: 6px;
  min-width: 6px;
  border-radius: 999px;
  background: currentColor;
  opacity: .42;
}

/* ── Estado colapsado ── */
.sidebar.collapsed .nav-item,
.sidebar.collapsed .nav-group-btn {
  justify-content: center;
  gap: 0;
  padding-inline: 0;
  min-height: 46px;
}
.sidebar.collapsed .nav-sub      { display: none !important; }
.sidebar.collapsed .nav-text,
.sidebar.collapsed .nav-sub-text,
.sidebar.collapsed .nav-arrow {
  max-width: 0;
  opacity: 0;
  overflow: hidden;
  margin: 0;
  padding: 0;
  border: 0;
}
.sidebar.collapsed .nav-icon { margin: 0; }

.sidebar-footer {
  padding: 10px;
  border-top: 1px solid var(--border);
  flex-shrink: 0;
}
.sidebar-logout {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 44px;
  padding: 10px 12px;
  border-radius: 12px;
  background: transparent;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  transition: background .15s ease, color .15s ease;
}
.sidebar-logout:hover {
  background: rgba(239,68,68,.12);
  color: #fca5a5;
}
.sidebar.collapsed .sidebar-logout {
  justify-content: center;
  padding-inline: 0;
}
.sidebar.collapsed .sidebar-logout .nav-text {
  max-width: 0;
  opacity: 0;
  overflow: hidden;
}

/* Tooltips no estado colapsado */
.sidebar.collapsed .nav-item::after,
.sidebar.collapsed .nav-group-btn::after {
  content: attr(data-tooltip);
  position: absolute;
  left: calc(var(--sidebar-w-col) + 10px);
  top: 50%;
  transform: translateY(-50%);
  padding: 7px 10px;
  border-radius: 10px;
  background: #152642;
  border: 1px solid var(--border);
  color: var(--text-primary);
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  transition: opacity .15s ease;
  box-shadow: 0 12px 24px rgba(0,0,0,.18);
}
.sidebar.collapsed .nav-item:hover::after,
.sidebar.collapsed .nav-group-btn:hover::after { opacity: 1; }

/* ── Botão sino ── */
.ntf-btn {
  display:flex;align-items:center;gap:12px;width:100%;min-height:44px;
  padding:10px 12px;border-radius:12px;background:transparent;border:none;
  color:var(--text-muted);font-size:13px;font-weight:600;
  font-family:'Segoe UI',system-ui,sans-serif;cursor:pointer;
  transition:background .15s,color .15s;position:relative;text-align:left;
}
.ntf-btn:hover { background:var(--card-hover);color:var(--text-primary); }
.sidebar.collapsed .ntf-btn { justify-content:center;padding-inline:0; }
.sidebar.collapsed .ntf-btn .ntf-label { display:none; }
.ntf-icon-wrap { position:relative;flex-shrink:0;display:flex; }
.ntf-badge {
  display:none;position:absolute;top:-5px;right:-6px;
  background:var(--red);color:#fff;font-size:9px;font-weight:800;
  border-radius:10px;min-width:16px;height:16px;
  align-items:center;justify-content:center;padding:0 4px;line-height:1;
  border:2px solid var(--blue-mid,#0f2040);
}
.ntf-badge.visible { display:flex; }

/* ── Painel de notificações ── */
.ntf-panel {
  position:fixed;bottom:70px;
  left:calc(var(--sidebar-w) + 8px);
  width:320px;max-height:460px;
  background:#0f2040;border:1px solid rgba(255,255,255,.1);
  border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.55);
  z-index:500;display:none;flex-direction:column;overflow:hidden;
  transition:left .25s;
}
.ntf-panel.open { display:flex; }
.sidebar.collapsed ~ .ntf-panel { left:calc(var(--sidebar-w-col) + 8px); }

.ntf-panel-head {
  padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.07);
  display:flex;align-items:center;justify-content:space-between;
  font-size:13px;font-weight:700;flex-shrink:0;
}
.ntf-mark-btn {
  background:none;border:none;color:#7db3ff;font-size:11px;
  cursor:pointer;font-family:inherit;padding:0;
}
.ntf-mark-btn:hover { text-decoration:underline; }

.ntf-list { overflow-y:auto;flex:1; }
.ntf-list::-webkit-scrollbar { width:3px; }
.ntf-list::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1);border-radius:3px; }

.ntf-item {
  display:flex;gap:10px;padding:11px 14px;
  border-bottom:1px solid rgba(255,255,255,.04);transition:background .1s;
}
.ntf-item:hover { background:rgba(255,255,255,.03); }
.ntf-item.unread { background:rgba(45,106,255,.07); }
.ntf-item:last-child { border-bottom:none; }
.ntf-dot-col { padding-top:5px;flex-shrink:0; }
.ntf-dot { width:7px;height:7px;border-radius:50%;background:#2d6aff; }
.ntf-dot.read { background:transparent; }
.ntf-body { flex:1;min-width:0; }
.ntf-card { font-size:12.5px;font-weight:600;color:var(--text-primary,#e8edf5);
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.ntf-move { font-size:11.5px;color:var(--text-muted,#7a90b0);margin-top:2px; }
.ntf-move strong { color:var(--teal,#00c9a7); }
.ntf-time { font-size:10.5px;color:var(--text-muted,#7a90b0);margin-top:4px; }
.ntf-who  { font-size:10.5px;color:var(--text-muted,#7a90b0); }

.ntf-empty { padding:28px 16px;text-align:center;font-size:13px;color:var(--text-muted,#7a90b0); }

/* ── Toast ── */
.ntf-toast {
  position:fixed;bottom:24px;right:24px;z-index:900;
  background:#0f2040;border:1px solid rgba(45,106,255,.35);
  border-radius:10px;padding:12px 16px;max-width:300px;
  box-shadow:0 8px 28px rgba(0,0,0,.45);
  transform:translateY(16px);opacity:0;
  transition:transform .22s ease,opacity .22s ease;pointer-events:none;
}
.ntf-toast.in { transform:translateY(0);opacity:1; }
.ntf-toast-title { font-size:13px;font-weight:600;color:#e8edf5; }
.ntf-toast-sub   { font-size:11.5px;color:#7a90b0;margin-top:3px; }
</style>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-head">
    <button class="sidebar-toggle-btn" id="sidebarToggle" type="button" title="Recolher menu (Alt+B)">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="4" y1="7"  x2="20" y2="7"/>
        <line x1="4" y1="12" x2="20" y2="12"/>
        <line x1="4" y1="17" x2="20" y2="17"/>
      </svg>
    </button>
  </div>

  <nav class="nav-section">

    <?php foreach ($standalone_paginas as $_sp):
        $_sp_key = 'standalone:' . ltrim(strtok($_sp['href'], '/'), '/');
        if (!sb_can($_sp['href'])) continue;
        if (!sb_ativo($_sp_key)) continue;
    ?>
    <a class="nav-item <?= nav_active($_sp['href']) ?>"
       href="<?= htmlspecialchars($_sp['href']) ?>"
       data-tooltip="<?= htmlspecialchars($_sp['label']) ?>">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?= $_sp['icon'] ?? '' ?>
        </svg>
      </span>
      <span class="nav-text"><?= htmlspecialchars($_sp['label']) ?></span>
    </a>
    <?php endforeach; unset($_sp, $_sp_key); ?>
    
    <?php foreach ($grupos as $id => $grupo):
        if (!empty($grupo['admin_only']) && !$_sb_admin) continue;
        if (!sb_ativo('grupo:' . $id)) continue;
        $itens_vis = array_filter($grupo['itens'], fn($i) =>
            (empty($i['admin_only']) || $_sb_admin) && sb_can($i['href']) && sb_ativo($i['href'])
        );
        if (empty($itens_vis) && empty($grupo['admin_only'])) continue;
        if (empty($itens_vis)) continue;
        $hrefs = array_column($itens_vis, 'href');
        $openCls = group_open($hrefs);
        $activeCls = $openCls ? 'has-active' : '';
        ?>
    <div class="nav-group <?= $openCls ?> <?= $activeCls ?>" id="grp-<?= $id ?>">

      <button class="nav-group-btn"
              type="button"
              data-tooltip="<?= htmlspecialchars($grupo['tooltip']) ?>"
              onclick="sidebarToggleGroup('<?= $id ?>')">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <?= $grupo['icon'] ?>
          </svg>
        </span>
        <span class="nav-text"><?= htmlspecialchars($grupo['label']) ?></span>
        <svg class="nav-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </button>

      <div class="nav-sub">
        <?php foreach ($itens_vis as $item): ?>
        <a class="nav-sub-item <?= nav_active($item['href']) ?>"
           href="<?= htmlspecialchars($item['href']) ?>">
          <span class="nav-dot"></span>
          <span class="nav-sub-text"><?= htmlspecialchars($item['label']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>

    </div>
    <?php endforeach; ?>

    <?php
    // Avisa usuários sem permissões atribuídas
    $__sb_tem_itens = sb_can('/produtos');
    if (!$__sb_tem_itens) {
        foreach ($grupos as $__g) {
            $__vis = array_filter($__g['itens'], fn($i) => sb_can($i['href']));
            if (!empty($__vis)) { $__sb_tem_itens = true; break; }
        }
    }
    if (!$_sb_admin && !$__sb_tem_itens): ?>
    <div class="nav-sem-permissao">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <span class="nav-text">Sem permissões atribuídas. Contacte o administrador.</span>
    </div>
    <?php endif; ?>

  </nav>

  <div class="sidebar-footer">
    <a href="/logout" class="sidebar-logout" title="Sair">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </span>
      <span class="nav-text">Sair</span>
    </a>
  </div>
</aside>

<!-- ── Topbar mobile (visível apenas em telas pequenas) ─────────────────── -->
<nav class="mobile-topbar" id="mobileTopbar">
  <?php foreach ($standalone_paginas as $_spm):
    if (!sb_can($_spm['href'])) continue; ?>
  <a class="mob-item <?= nav_active($_spm['href']) ?>" href="<?= htmlspecialchars($_spm['href']) ?>" title="<?= htmlspecialchars($_spm['label']) ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <?= $_spm['icon'] ?? '' ?>
    </svg>
    <span><?= htmlspecialchars($_spm['label']) ?></span>
  </a>
  <?php endforeach; unset($_spm); ?>
  <?php foreach ($grupos as $id => $grupo):
    $itens_mob = array_filter($grupo['itens'], fn($i) => sb_can($i['href']));
    if (empty($itens_mob)) continue;
    $firstHref = array_values($itens_mob)[0]['href'];
    $hrefs = array_column($itens_mob, 'href');
    $isActive = group_open($hrefs) ? 'active' : '';
    ?>
  <a class="mob-item <?= $isActive ?>" href="<?= htmlspecialchars($firstHref) ?>" title="<?= htmlspecialchars($grupo['label']) ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <?= $grupo['icon'] ?>
    </svg>
    <span><?= htmlspecialchars($grupo['label']) ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<style>
/* ── Mobile Topbar ────────────────────────────────── */
.mobile-topbar {
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0;
  height: 52px;
  background: var(--blue-mid);
  border-bottom: 1px solid var(--border);
  z-index: 100;
  overflow-x: auto;
  overflow-y: hidden;
  white-space: nowrap;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  align-items: center;
  box-shadow: 0 2px 12px rgba(0,0,0,.25);
}
.mobile-topbar::-webkit-scrollbar { display: none; }

.mob-item {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  padding: 6px 14px;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 10px;
  font-weight: 600;
  height: 52px;
  white-space: nowrap;
  transition: color .15s, background .15s;
  flex-shrink: 0;
}
.mob-item svg { flex-shrink: 0; }
.mob-item:hover { color: var(--text-primary); background: rgba(255,255,255,.05); }
.mob-item.active { color: #cfe0ff; background: rgba(79,123,255,.12); }

@media (max-width: 768px) {
  .mobile-topbar { display: flex; }
}
</style>

<script>
(function () {
  'use strict';

  const SIDEBAR_KEY = 'dombag_sidebar_collapsed';
  const GROUPS_KEY  = 'dombag_groups_open';

  const sidebar = document.getElementById('sidebar');
  const btn     = document.getElementById('sidebarToggle');

  if (!sidebar) return;

  /* ── Restaura estado colapsado ── */
  if (localStorage.getItem(SIDEBAR_KEY) === 'true') {
    sidebar.classList.add('collapsed');
  }

  /* ── Persiste e lê estado dos grupos ── */
  function getStates() {
    try { return JSON.parse(localStorage.getItem(GROUPS_KEY) || '{}'); }
    catch (e) { return {}; }
  }
  function setStates(s) { localStorage.setItem(GROUPS_KEY, JSON.stringify(s)); }

  /* Sincroniza grupos com localStorage (sem fechar os que o PHP marcou como open) */
  const saved = getStates();
  document.querySelectorAll('.nav-group').forEach(function (g) {
    const id = g.id.replace('grp-', '');
    if (g.classList.contains('open')) {
      saved[id] = true;
    } else if (saved[id] === true) {
      g.classList.add('open');
    }
  });
  setStates(saved);

  /* ── Toggle de grupo (chamado pelo onclick inline) ── */
  window.sidebarToggleGroup = function (id) {
    /* Se a sidebar estiver colapsada, expande primeiro e depois abre o grupo */
    if (sidebar.classList.contains('collapsed')) {
      sidebar.classList.remove('collapsed');
      localStorage.setItem(SIDEBAR_KEY, 'false');
      setTimeout(function () { openGroup(id); }, 60);
      return;
    }
    const g = document.getElementById('grp-' + id);
    if (!g) return;
    const opening = !g.classList.contains('open');
    g.classList.toggle('open', opening);
    const s = getStates();
    s[id] = opening;
    setStates(s);
  };

  function openGroup(id) {
    const g = document.getElementById('grp-' + id);
    if (!g) return;
    g.classList.add('open');
    const s = getStates();
    s[id] = true;
    setStates(s);
  }

  /* ── Toggle da sidebar (botão) ── */
  if (btn) {
    btn.addEventListener('click', function () {
      const collapsed = sidebar.classList.toggle('collapsed');
      localStorage.setItem(SIDEBAR_KEY, collapsed ? 'true' : 'false');
    });
  }

  /* ── Atalho Alt+B ── */
  document.addEventListener('keydown', function (e) {
    if (e.altKey && e.key.toLowerCase() === 'b') {
      const collapsed = sidebar.classList.toggle('collapsed');
      localStorage.setItem(SIDEBAR_KEY, collapsed ? 'true' : 'false');
    }
  });

})();
</script>