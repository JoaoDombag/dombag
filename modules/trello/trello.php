<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════
//  DOMBAG — Trello Board
// ══════════════════════════════════════════════════

// ── Credenciais ───────────────────────────────────
$trello_key = $trello_token = '';
try {
    $pdo = dbPDO();
    $st  = $pdo->prepare("SELECT PAR_CHAVE, PAR_VALOR FROM parametros WHERE PAR_CHAVE IN ('trello_api_key','trello_token')");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['PAR_CHAVE'] === 'trello_api_key') $trello_key   = trim($r['PAR_VALOR']);
        if ($r['PAR_CHAVE'] === 'trello_token')   $trello_token = trim($r['PAR_VALOR']);
    }
} catch (PDOException $e) {}

// ── Helper de chamada à API ───────────────────────
function trello_get(string $endpoint, string $key, string $token): array|false
{
    $sep = str_contains($endpoint, '?') ? '&' : '?';
    $url = 'https://api.trello.com/1' . $endpoint . $sep . 'key=' . urlencode($key) . '&token=' . urlencode($token);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code !== 200) return false;
    return json_decode($resp, true) ?? false;
}

// ── Busca dados do Trello ─────────────────────────
$trello_ok       = false;
$trello_error    = '';
$boards          = [];
$empty_board     = ['lists' => [], 'cards' => [], 'members' => [], 'members_map' => [], 'labels' => []];
$board_data1     = $empty_board;
$board_data2     = $empty_board;
$selected_board1 = $_GET['board1'] ?? '';
$selected_board2 = $_GET['board2'] ?? '';

function fetchBoardData(string $boardId, string $key, string $token): array
{
    $lists   = trello_get("/boards/{$boardId}/lists?fields=id,name,pos&filter=open", $key, $token);
    $cards   = trello_get(
        "/boards/{$boardId}/cards?fields=id,name,desc,idList,due,dueComplete,labels,url,idMembers,shortUrl,pos,cover",
        $key, $token
    );
    $members = trello_get("/boards/{$boardId}/members?fields=id,fullName,initials,avatarUrl", $key, $token);
    $labels  = trello_get("/boards/{$boardId}/labels?fields=all", $key, $token);
    $map     = [];
    foreach ($members ?: [] as $m) $map[$m['id']] = $m;
    return [
        'lists'       => $lists   ?: [],
        'cards'       => $cards   ?: [],
        'members'     => $members ?: [],
        'members_map' => $map,
        'labels'      => $labels  ?: [],
    ];
}

if ($trello_key && $trello_token) {
    $result = trello_get('/members/me/boards?fields=id,name,url,closed&filter=open', $trello_key, $trello_token);
    if ($result === false) {
        $trello_error = 'Não foi possível conectar à API do Trello. Verifique sua chave e token em Parâmetros → Integrações.';
    } else {
        $trello_ok = true;
        $boards    = $result;
        if (!$selected_board1 && !empty($boards)) $selected_board1 = $boards[0]['id'];
        if (!$selected_board2) {
            $selected_board2 = count($boards) > 1 ? $boards[1]['id'] : ($boards[0]['id'] ?? '');
        }
        if ($selected_board1) $board_data1 = fetchBoardData($selected_board1, $trello_key, $trello_token);
        if ($selected_board2) $board_data2 = fetchBoardData($selected_board2, $trello_key, $trello_token);
    }
}

// ── Agrupa cards por lista ────────────────────────
$cards_by_list1 = [];
foreach ($board_data1['cards'] as $card) $cards_by_list1[$card['idList']][] = $card;
$cards_by_list2 = [];
foreach ($board_data2['cards'] as $card) $cards_by_list2[$card['idList']][] = $card;

// ── Mapa de cores dos labels (paleta oficial do Trello) ──
// Clássicos
$label_hex = [
    'green'        => ['bg' => '#19573D', 'text' => '#fff'],
    'yellow'       => ['bg' => '#f2d600', 'text' => '#172b4d'],
    'orange'       => ['bg' => '#ff9f1a', 'text' => '#fff'],
    'red'          => ['bg' => '#eb5a46', 'text' => '#fff'],
    'purple'       => ['bg' => '#50253F', 'text' => '#fff'],
    'blue'         => ['bg' => '#0079bf', 'text' => '#fff'],
    'sky'          => ['bg' => '#00c2e0', 'text' => '#172b4d'],
    'lime'         => ['bg' => '#51e898', 'text' => '#172b4d'],
    'pink'         => ['bg' => '#ff78cb', 'text' => '#172b4d'],
    'black'        => ['bg' => '#344563', 'text' => '#fff'],
    // Variantes escuras (dark)
    'green_dark'   => ['bg' => '#19573D', 'text' => '#fff'],
    'yellow_dark'  => ['bg' => '#533f04', 'text' => '#fff'],
    'orange_dark'  => ['bg' => '#5c2a00', 'text' => '#fff'],
    'red_dark'     => ['bg' => '#42100c', 'text' => '#fff'],
    'purple_dark'  => ['bg' => '#50253F', 'text' => '#fff'],
    'blue_dark'    => ['bg' => '#09326c', 'text' => '#fff'],
    'sky_dark'     => ['bg' => '#164555', 'text' => '#fff'],
    'lime_dark'    => ['bg' => '#37471f', 'text' => '#fff'],
    'pink_dark'    => ['bg' => '#50253f', 'text' => '#fff'],
    'black_dark'   => ['bg' => '#091e42', 'text' => '#fff'],
    // Variantes claras (light)
    'green_light'  => ['bg' => '#19573D', 'text' => '#172b4d'],
    'yellow_light' => ['bg' => '#f8e6a0', 'text' => '#172b4d'],
    'orange_light' => ['bg' => '#fedec8', 'text' => '#172b4d'],
    'red_light'    => ['bg' => '#ffd5d2', 'text' => '#172b4d'],
    'purple_light' => ['bg' => '#50253F', 'text' => '#172b4d'],
    'blue_light'   => ['bg' => '#cce0ff', 'text' => '#172b4d'],
    'sky_light'    => ['bg' => '#c6edfb', 'text' => '#172b4d'],
    'lime_light'   => ['bg' => '#d3f1a7', 'text' => '#172b4d'],
    'pink_light'   => ['bg' => '#fdd0ec', 'text' => '#172b4d'],
    'black_light'  => ['bg' => '#b3bac5', 'text' => '#172b4d'],
];

// ── Cores por nome de label (sobrepõe o mapa de cores do Trello) ──
$label_name_hex = [
    'pronto para produzir'    => ['bg' => '#216E4E', 'text' => '#fff'],
    'aguardando clichê chegar'=> ['bg' => '#4BCE97', 'text' => '#fff'],
    'aguardando frete'        => ['bg' => '#533F04', 'text' => '#fff'],
    'falta op'                => ['bg' => '#DDB30E', 'text' => '#fff'],
    'aguardando pagamento'    => ['bg' => '#9E4C00', 'text' => '#fff'],
    'falta mp'                => ['bg' => '#9E4C00', 'text' => '#fff'],
    'não produzir'            => ['bg' => '#5D1F1A', 'text' => '#fff'],
    'cancelado'               => ['bg' => '#872821', 'text' => '#fff'],
    'prioridade'              => ['bg' => '#F87168', 'text' => '#fff'],
    'apontamento iniciado'    => ['bg' => '#48245D', 'text' => '#fff'],
    'apontamento finalizado'  => ['bg' => '#803FA5', 'text' => '#fff'],
    'pedido programado'       => ['bg' => '#1558BC', 'text' => '#fff'],
    'com impressão'           => ['bg' => '#6CC3E0', 'text' => '#fff'],
    'falta cliche'            => ['bg' => '#50253F', 'text' => '#fff'],
    'aguardando aprovação'    => ['bg' => '#4B4D51', 'text' => '#fff'],
];

// JSON para JS
$toList = fn($d) => array_values(array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name']], $d['lists']));
$js_board_id1      = json_encode($selected_board1);
$js_board_id2      = json_encode($selected_board2);
$js_board_lists1   = json_encode($toList($board_data1));
$js_board_lists2   = json_encode($toList($board_data2));
$js_members_map1   = json_encode($board_data1['members_map']);
$js_members_map2   = json_encode($board_data2['members_map']);
$js_board_labels1  = json_encode($board_data1['labels']);
$js_board_labels2  = json_encode($board_data2['labels']);
$js_label_hex      = json_encode($label_hex);
$js_label_name_hex = json_encode($label_name_hex);

// ── Renderiza um board kanban ─────────────────────
function renderKanbanBoard(array $bd, array $cbl, array $lhex, array $lname): string
{
    if (empty($bd['lists'])) {
        return '<div style="text-align:center;padding:60px;color:var(--text-muted);">Nenhuma lista encontrada neste board.</div>';
    }
    ob_start();
    ?>
    <div class="kanban-scroll">
      <div class="kanban-board">
        <?php foreach ($bd['lists'] as $list):
          $list_cards = $cbl[$list['id']] ?? [];
        ?>
        <div class="kanban-col">
          <div class="kanban-col-header">
            <span class="kanban-col-name"><?= htmlspecialchars($list['name']) ?></span>
            <span class="kanban-col-count"><?= count($list_cards) ?></span>
          </div>
          <div class="kanban-cards" data-list-id="<?= htmlspecialchars($list['id']) ?>">
            <?php if (empty($list_cards)): ?>
            <div class="empty-col" style="pointer-events:none;">Nenhum card</div>
            <?php endif; ?>
            <?php foreach ($list_cards as $card): ?>
            <?php
              $cover_html = '';
              $cover = $card['cover'] ?? [];
              if (!empty($cover['color'])) {
                  $cover_colors = ['blue'=>'#0079bf','orange'=>'#d29034','green'=>'#519839','red'=>'#b04632','purple'=>'#89609e','pink'=>'#cd5a91','lime'=>'#4bbf6b','sky'=>'#00aecc','grey'=>'#838c91'];
                  $cc = $cover_colors[$cover['color']] ?? '#334155';
                  $cover_html = '<div class="card-cover solid" style="background:' . $cc . ';"></div>';
              } elseif (!empty($cover['scaled'])) {
                  $scaled = end($cover['scaled']);
                  if (!empty($scaled['url'])) {
                      $cover_html = '<div class="card-cover"><img src="' . htmlspecialchars($scaled['url']) . '" loading="lazy" alt=""></div>';
                  }
              }
              $labels_html = '';
              if (!empty($card['labels'])) {
                  $labels_html = '<div class="card-labels">';
                  foreach ($card['labels'] as $lbl) {
                      $lc = $lname[mb_strtolower($lbl['name'] ?? '')] ?? $lhex[$lbl['color'] ?? ''] ?? ['bg' => '#334155', 'text' => '#fff'];
                      $has_name = !empty($lbl['name']);
                      $labels_html .= '<span class="card-label' . ($has_name ? ' wide' : '') . '" style="background:' . $lc['bg'] . ';" title="' . htmlspecialchars($lbl['name'] ?? '') . '"></span>';
                  }
                  $labels_html .= '</div>';
              }
              $due_html = '';
              if (!empty($card['due'])) {
                  $due_ts = strtotime($card['due']);
                  $diff   = $due_ts - time();
                  $fmt    = date('d/m', $due_ts);
                  if ($card['dueComplete']) $cls = 'done';
                  elseif ($diff < 0)        $cls = 'late';
                  elseif ($diff < 172800)   $cls = 'soon';
                  else                      $cls = 'ok';
                  $due_html = '<span class="card-due ' . $cls . '">' . $fmt . '</span>';
              }
              $desc_icon = !empty($card['desc']) ? '<svg class="card-desc-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>' : '';
              $members_html = '';
              if (!empty($card['idMembers'])) {
                  $members_html = '<div class="card-members">';
                  foreach (array_slice($card['idMembers'], 0, 3) as $mid) {
                      $m = $bd['members_map'][$mid] ?? null;
                      if (!$m) continue;
                      $members_html .= '<span class="card-avatar" title="' . htmlspecialchars($m['fullName'] ?? '') . '">' . htmlspecialchars($m['initials'] ?? '?') . '</span>';
                  }
                  $members_html .= '</div>';
              }
            ?>
            <div class="kanban-card"
                 data-id="<?= htmlspecialchars($card['id']) ?>"
                 data-pos="<?= (float)($card['pos'] ?? 0) ?>"
                 onclick="if(Date.now()-_dragEndTime>250)openCard('<?= htmlspecialchars($card['id']) ?>')">
              <?= $cover_html ?>
              <div class="card-body">
                <?= $labels_html ?>
                <div class="card-title"><?= htmlspecialchars($card['name']) ?></div>
                <?php if ($due_html || $desc_icon || $members_html): ?>
                <div class="card-footer">
                  <?= $desc_icon ?>
                  <?= $due_html ?>
                  <?= $members_html ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trello | DOMBAG</title>
  <link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
  <style>
/* ══ Abas de board ════════════════════════════════ */
.board-tabs-bar{display:flex;align-items:center;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:0;}
.board-tab-btn{padding:8px 18px;border:none;background:transparent;color:var(--text-muted);font-family:'Segoe UI',sans-serif;font-size:13px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s,border-color .15s;}
.board-tab-btn:hover{color:var(--text-primary);}
.board-tab-btn.active{color:#7db3ff;border-bottom-color:#2d6aff;font-weight:600;}
.board-tab-content{display:none;}
.board-tab-content.active{display:block;}

/* ══ Toolbar ══════════════════════════════════════ */
.trello-toolbar {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;
}
.trello-board-select {
  background: var(--blue-mid); border: 1px solid var(--border);
  color: var(--text-primary); border-radius: 8px; padding: 8px 14px;
  font-size: 13px; cursor: pointer; outline: none; min-width: 220px;
}
.trello-board-select:focus { border-color: #2d6aff; }
.trello-open-btn {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(45,106,255,.12); color: #7db3ff;
  border: 1px solid rgba(45,106,255,.25); border-radius: 8px;
  padding: 7px 14px; font-size: 12px; font-weight: 600;
  text-decoration: none; transition: background .15s;
}
.trello-open-btn:hover { background: rgba(45,106,255,.2); }
.trello-stats { margin-left: auto; display: flex; gap: 16px; font-size: 12px; color: var(--text-muted); }
.trello-stats span strong { color: var(--text-primary); }
.config-toggle-btn {
  font-size: 11.5px; color: var(--text-muted); background: none;
  border: 1px solid var(--border); border-radius: 6px; padding: 5px 10px;
  cursor: pointer; transition: color .15s, border-color .15s;
  display: inline-flex; align-items: center; gap: 4px; text-decoration: none;
}
.config-toggle-btn:hover { color: var(--text-primary); border-color: rgba(45,106,255,.4); }

/* ══ Kanban ═══════════════════════════════════════ */
.kanban-scroll { overflow-x: auto; padding-bottom: 16px; }
.kanban-board  { display: flex; gap: 14px; align-items: flex-start; min-width: min-content; }
.kanban-col {
  width: 280px; min-width: 280px;
  background: rgba(255,255,255,.04); border: 1px solid var(--border);
  border-radius: 12px; display: flex; flex-direction: column;
  max-height: calc(100vh - 220px);
}
.kanban-col-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 14px 10px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.kanban-col-name  { font-size: 12.5px; font-weight: 700; color: var(--text-primary); }
.kanban-col-count {
  font-size: 11px; font-weight: 700; color: var(--text-muted);
  background: rgba(255,255,255,.07); border-radius: 10px; padding: 2px 8px;
}
.kanban-cards {
  overflow-y: auto; padding: 8px; display: flex; flex-direction: column; gap: 7px; flex: 1;
}
.kanban-cards::-webkit-scrollbar { width: 4px; }
.kanban-cards::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 2px; }

/* ══ Card ═════════════════════════════════════════ */
.kanban-card {
  background: var(--blue-mid); border: 1px solid var(--border);
  border-radius: 9px; cursor: grab;
  transition: border-color .15s, box-shadow .15s;
  display: block; text-decoration: none; user-select: none;
  touch-action: none;
}
.kanban-card:active { cursor: grabbing; }
.kanban-card:hover { border-color: rgba(45,106,255,.5); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.25); }
.card-cover { border-radius: 8px 8px 0 0; overflow: hidden; max-height: 120px; }
.card-cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.card-cover.solid { height: 32px; }
.card-body { padding: 10px 12px 11px; }
.card-labels { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 7px; }
.card-label  { height: 8px; border-radius: 4px; min-width: 40px; cursor: pointer; }
.card-label.wide { min-width: 60px; }
.card-title  { font-size: 12.5px; font-weight: 500; color: var(--text-primary); line-height: 1.45; }
.card-footer { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.card-due    { font-size: 10.5px; font-weight: 600; padding: 2px 7px; border-radius: 5px; white-space: nowrap; }
.card-due.ok   { background: rgba(0,201,167,.1);  color: #00c9a7; }
.card-due.late { background: rgba(239,68,68,.12);  color: #ef4444; }
.card-due.soon { background: rgba(245,158,11,.12); color: #f59e0b; }
.card-due.done { background: rgba(45,106,255,.1);  color: #7db3ff; text-decoration: line-through; }
.card-desc-icon { color: var(--text-muted); flex-shrink:0; }
.card-members  { display: flex; gap: 3px; margin-left: auto; }
.card-avatar {
  width: 22px; height: 22px; border-radius: 50%;
  background: linear-gradient(135deg,#1e4fc9,#2d6aff);
  color: #fff; font-size: 9px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid rgba(255,255,255,.15); flex-shrink: 0; cursor: default;
}

/* Drag & Drop */
.card-ghost    { opacity: 0.3 !important; border: 2px dashed rgba(45,106,255,.5) !important; background: rgba(45,106,255,.06) !important; }
.card-chosen   { box-shadow: 0 4px 14px rgba(0,0,0,.4) !important; }
.card-dragging { box-shadow: 0 10px 28px rgba(0,0,0,.55) !important; transform: rotate(1.5deg) !important; z-index: 99999 !important; opacity: 0.95 !important; pointer-events: none !important; }
.kanban-cards.sortable-over { background: rgba(45,106,255,.06); border-radius: 8px; }

/* ══ Modal ════════════════════════════════════════ */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.72);
  z-index: 1000; display: flex; align-items: flex-start; justify-content: center;
  padding: 40px 16px 32px; overflow-y: auto;
  opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal-card {
  background: #1a2744; border: 1px solid var(--border);
  border-radius: 14px; width: 100%; max-width: 760px;
  position: relative; flex-shrink: 0;
}
.modal-close {
  position: absolute; top: 12px; right: 12px;
  background: rgba(255,255,255,.06); border: none; border-radius: 8px;
  color: var(--text-muted); cursor: pointer; padding: 6px; display: flex;
  transition: background .15s, color .15s; z-index: 2;
}
.modal-close:hover { background: rgba(255,255,255,.12); color: var(--text-primary); }
.modal-body { min-height: 120px; }
.modal-loading {
  display: flex; align-items: center; justify-content: center;
  padding: 60px; color: var(--text-muted); font-size: 13px;
}
.modal-inner { display: flex; gap: 0; }
.modal-main { flex: 1; padding: 22px 20px 24px 24px; min-width: 0; }
.modal-sidebar {
  width: 180px; min-width: 180px; padding: 22px 16px 24px 0;
  border-left: 1px solid var(--border); flex-shrink: 0;
  /* no left padding on sidebar — keep it tight */
  padding-left: 16px;
}
@media(max-width:600px){
  .modal-inner { flex-direction: column; }
  .modal-sidebar { width: 100%; border-left: none; border-top: 1px solid var(--border); padding: 16px 20px; }
}

/* Modal: cover image */
.modal-cover { border-radius: 13px 13px 0 0; overflow: hidden; max-height: 180px; }
.modal-cover img { width: 100%; object-fit: cover; display: block; }
.modal-cover.solid { height: 48px; }

/* Modal: title */
.modal-title-wrap { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 18px; }
.modal-title {
  font-size: 17px; font-weight: 700; color: var(--text-primary); line-height: 1.35;
  outline: none; border-radius: 6px; padding: 4px 6px; margin: -4px -6px;
  transition: background .15s;
}
.modal-title:focus { background: rgba(255,255,255,.06); }
.modal-list-name { font-size: 11.5px; color: var(--text-muted); padding-left: 6px; margin-top: 3px; }

/* Modal: sections */
.modal-section { margin-bottom: 18px; }
.modal-section-title {
  font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
  color: var(--text-muted); margin-bottom: 8px;
}
.modal-section-title-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.modal-section-title-row .modal-section-title { margin-bottom: 0; }

/* Modal: labels */
.modal-labels-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.modal-label-pill {
  display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px;
  border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer;
  transition: opacity .15s; border: none; color: #fff;
}
.modal-label-pill:hover { opacity: .8; }
.modal-label-add {
  background: rgba(255,255,255,.08); border: 1px dashed var(--border); color: var(--text-muted);
  border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer;
  transition: background .15s, color .15s;
}
.modal-label-add:hover { background: rgba(255,255,255,.12); color: var(--text-primary); }

/* Modal: members */
.modal-members-row { display: flex; gap: 5px; flex-wrap: wrap; }
.card-avatar.lg { width: 30px; height: 30px; font-size: 11px; }

/* Modal: due date */
.modal-due-row { display: flex; align-items: center; gap: 8px; }
.modal-due-row input[type=checkbox] { width: 15px; height: 15px; accent-color: #2d6aff; cursor: pointer; }

/* Modal: description */
.modal-desc-wrap {
  cursor: pointer; padding: 10px 12px; border-radius: 8px;
  background: rgba(255,255,255,.03); border: 1px solid transparent;
  min-height: 48px; transition: border-color .15s, background .15s;
}
.modal-desc-wrap:hover { border-color: var(--border); background: rgba(255,255,255,.05); }
.modal-desc-text { font-size: 13px; color: var(--text-primary); line-height: 1.6; white-space: pre-wrap; }
.modal-desc-placeholder { font-size: 13px; color: var(--text-muted); }
.modal-desc-editor {
  width: 100%; background: rgba(255,255,255,.04); border: 1px solid #2d6aff;
  color: var(--text-primary); border-radius: 8px; padding: 10px 12px;
  font-size: 13px; font-family: inherit; line-height: 1.6; resize: vertical;
  min-height: 100px; outline: none; box-sizing: border-box;
}
.desc-actions { display: flex; gap: 8px; margin-top: 8px; }
.btn-sm {
  background: linear-gradient(135deg,#1e4fc9,#2d6aff); color: #fff;
  border: none; border-radius: 7px; padding: 7px 14px; font-size: 12.5px;
  font-weight: 600; cursor: pointer; transition: opacity .15s;
}
.btn-sm:hover { opacity: .88; }
.btn-sm.ghost {
  background: rgba(255,255,255,.06); color: var(--text-muted);
  border: 1px solid var(--border);
}
.btn-sm.ghost:hover { color: var(--text-primary); }

/* Modal: attachments */
.modal-attachments { display: flex; flex-direction: column; gap: 8px; }
.attach-item {
  display: flex; gap: 10px; align-items: center;
  background: rgba(255,255,255,.03); border: 1px solid var(--border);
  border-radius: 8px; padding: 8px 10px; text-decoration: none;
  transition: border-color .15s;
}
.attach-item:hover { border-color: rgba(45,106,255,.4); }
.attach-thumb { width: 52px; height: 40px; border-radius: 5px; object-fit: cover; background: rgba(255,255,255,.06); flex-shrink: 0; }
.attach-icon { width: 52px; height: 40px; border-radius: 5px; background: rgba(255,255,255,.06); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.attach-name { font-size: 12.5px; font-weight: 500; color: var(--text-primary); }
.attach-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Modal: checklist */
.modal-checklist { margin-bottom: 16px; }
.checklist-name { font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
.checklist-progress { height: 4px; background: rgba(255,255,255,.08); border-radius: 2px; margin-bottom: 10px; overflow: hidden; }
.checklist-progress-fill { height: 100%; background: #00c9a7; border-radius: 2px; transition: width .3s; }
.checklist-item {
  display: flex; align-items: flex-start; gap: 9px; padding: 5px 0;
  cursor: pointer; border-radius: 6px; padding: 5px 6px;
  transition: background .12s;
}
.checklist-item:hover { background: rgba(255,255,255,.04); }
.checklist-item input[type=checkbox] { width: 15px; height: 15px; margin-top: 1px; accent-color: #2d6aff; cursor: pointer; flex-shrink: 0; }
.checklist-item-name { font-size: 13px; color: var(--text-primary); line-height: 1.4; }
.checklist-item-name.done { text-decoration: line-through; color: var(--text-muted); }

/* Modal: sidebar */
.modal-sidebar-group { margin-bottom: 20px; }
.modal-sidebar-title {
  font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
  color: var(--text-muted); margin-bottom: 6px;
}
.modal-action-btn {
  display: flex; align-items: center; gap: 8px; width: 100%;
  background: rgba(255,255,255,.06); border: none; color: var(--text-secondary);
  border-radius: 7px; padding: 8px 10px; font-size: 12.5px; font-weight: 500;
  cursor: pointer; transition: background .15s, color .15s; text-decoration: none;
  font-family: inherit; text-align: left; margin-bottom: 5px;
}
.modal-action-btn:hover { background: rgba(255,255,255,.11); color: var(--text-primary); }
.modal-move-panel { margin-top: 8px; }
.modal-move-select {
  width: 100%; background: rgba(255,255,255,.04); border: 1px solid var(--border);
  color: var(--text-primary); border-radius: 7px; padding: 7px 9px;
  font-size: 12.5px; outline: none; cursor: pointer; margin-bottom: 6px;
}
.modal-move-select:focus { border-color: #2d6aff; }

/* ══ Label Picker ═════════════════════════════════ */
.label-picker {
  position: fixed; z-index: 1100; background: #1a2744;
  border: 1px solid var(--border); border-radius: 10px;
  padding: 0; width: 240px; box-shadow: 0 8px 32px rgba(0,0,0,.5);
}
.label-picker-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px 8px; border-bottom: 1px solid var(--border);
  font-size: 12px; font-weight: 700; color: var(--text-muted);
  letter-spacing: .5px; text-transform: uppercase;
}
.label-picker-close {
  background: none; border: none; color: var(--text-muted);
  cursor: pointer; font-size: 16px; line-height: 1; padding: 0 2px;
}
.label-picker-close:hover { color: var(--text-primary); }
.label-picker-list { padding: 8px; display: flex; flex-direction: column; gap: 4px; }
.label-picker-item {
  display: flex; align-items: center; gap: 10px; padding: 7px 9px;
  border-radius: 7px; cursor: pointer; transition: background .12s;
}
.label-picker-item:hover { background: rgba(255,255,255,.06); }
.label-picker-swatch { width: 36px; height: 22px; border-radius: 4px; flex-shrink: 0; }
.label-picker-name { font-size: 12.5px; color: var(--text-primary); flex: 1; }
.label-picker-check { color: #2d6aff; font-size: 14px; font-weight: 700; }

/* ══ Alert ════════════════════════════════════════ */
.alert-warn {
  background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.25);
  color: #fcd34d; border-radius: 8px; padding: 11px 14px;
  font-size: 12.5px; margin-bottom: 16px;
}

.empty-col { padding: 24px 14px; text-align: center; color: var(--text-muted); font-size: 12px; }

/* ══ Comentário / Arquivar ════════════════════════ */
.modal-comment-panel { margin-top: 8px; }
.modal-comment-textarea {
  width: 100%; background: rgba(255,255,255,.04); border: 1px solid var(--border);
  color: var(--text-primary); border-radius: 7px; padding: 7px 9px;
  font-size: 12.5px; font-family: inherit; line-height: 1.5;
  resize: vertical; min-height: 70px; outline: none; box-sizing: border-box;
  transition: border-color .15s;
}
.modal-comment-textarea:focus { border-color: #2d6aff; }
.modal-action-btn.danger { color: #ef4444; }
.modal-action-btn.danger:hover { background: rgba(239,68,68,.1); color: #ef4444; }

/* Toast */
.toast {
  position: fixed; bottom: 24px; right: 24px; z-index: 2000;
  background: #1e4fc9; color: #fff; border-radius: 10px;
  padding: 11px 18px; font-size: 13px; font-weight: 500;
  box-shadow: 0 4px 20px rgba(0,0,0,.4);
  transform: translateY(80px); opacity: 0;
  transition: transform .25s, opacity .25s;
}
.toast.show { transform: translateY(0); opacity: 1; }

/* ══ Lightbox ═════════════════════════════════════ */
#lightbox {
  display: none; position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,.88); align-items: center; justify-content: center;
  flex-direction: column; gap: 12px;
}
#lightbox.open { display: flex; }
#lightbox-img {
  max-width: 92vw; max-height: 82vh; border-radius: 10px;
  object-fit: contain; box-shadow: 0 8px 40px rgba(0,0,0,.6);
}
#lightbox-name {
  color: rgba(255,255,255,.75); font-size: 13px; max-width: 92vw;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center;
}
#lightbox-close {
  position: fixed; top: 18px; right: 22px; width: 36px; height: 36px;
  background: rgba(255,255,255,.12); border: none; border-radius: 50%;
  color: #fff; font-size: 20px; cursor: pointer; display: flex;
  align-items: center; justify-content: center; line-height: 1;
  transition: background .15s;
}
#lightbox-close:hover { background: rgba(255,255,255,.25); }
#lightbox-open-btn {
  color: rgba(255,255,255,.55); font-size: 12px; text-decoration: underline;
  cursor: pointer; background: none; border: none; padding: 0;
}
#lightbox-open-btn:hover { color: #fff; }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include($_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'); ?>

  <div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Pedidos</h1>
          <p id="topbar-date"></p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="config-toggle-btn" onclick="location.reload()" title="Atualizar">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Atualizar
        </button>
        <a href="https://trello.com" target="_blank" class="trello-open-btn">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Abrir no Trello
        </a>
        <a href="/modules/parametros/parametros.php?aba=integracoes" class="config-toggle-btn" style="text-decoration:none;">⚙ Configurar API</a>
      </div>
    </header>

    <div class="content">

      <?php if (!$trello_key || !$trello_token): ?>
      <!-- ══ Sem credenciais ══ -->
      <div style="max-width:480px;margin:0 auto;text-align:center;padding:60px 20px;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" style="color:var(--text-muted);margin-bottom:16px;"><rect x="3" y="3" width="7" height="18" rx="2"/><rect x="14" y="3" width="7" height="11" rx="2"/></svg>
        <p style="font-size:14px;font-weight:600;color:var(--text-primary);margin-bottom:8px;">Integração não configurada</p>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Configure a API Key e o Token do Trello em Parâmetros do Sistema.</p>
        <a href="/modules/parametros/parametros.php?aba=integracoes"
           style="display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#1e4fc9,#2d6aff);color:#fff;border-radius:8px;padding:10px 22px;font-size:13px;font-weight:600;text-decoration:none;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
          Ir para Parâmetros → Integrações
        </a>
      </div>

      <?php elseif ($trello_error): ?>
      <div class="alert-warn"><?= htmlspecialchars($trello_error) ?></div>

      <?php else: ?>

      <!-- ══ Abas de board ══ -->
      <?php
        function boardTabName(array $boards, string $id): string {
            foreach ($boards as $b) { if ($b['id'] === $id) return $b['name']; }
            return 'Board';
        }
        function boardStats(array $bd): string {
            $cards   = count($bd['cards']);
            $listas  = count($bd['lists']);
            $venc    = 0;
            foreach ($bd['cards'] as $c) {
                if (!empty($c['due']) && !$c['dueComplete'] && strtotime($c['due']) < time()) $venc++;
            }
            $s = "{$listas} listas · {$cards} cards";
            if ($venc > 0) $s .= " · <span style='color:#ef4444;'>{$venc} vencidos</span>";
            return $s;
        }
      ?>
      <div class="board-tabs-bar" id="boardTabsBar">
        <div class="trello-stats" id="boardStats" style="margin-right:12px;"><?= boardStats($board_data1) ?></div>
        <button class="board-tab-btn active" data-tab="1" onclick="switchBoardTab(1)">
          <?= htmlspecialchars(boardTabName($boards, $selected_board1)) ?>
        </button>
        <button class="board-tab-btn" data-tab="2" onclick="switchBoardTab(2)">
          <?= htmlspecialchars(boardTabName($boards, $selected_board2)) ?>
        </button>
      </div>

      <!-- ══ Aba 1 ══ -->
      <div class="board-tab-content active" data-tab="1">
        <?= renderKanbanBoard($board_data1, $cards_by_list1, $label_hex, $label_name_hex) ?>
      </div>

      <!-- ══ Aba 2 ══ -->
      <div class="board-tab-content" data-tab="2">
        <?= renderKanbanBoard($board_data2, $cards_by_list2, $label_hex, $label_name_hex) ?>
      </div>

      <?php endif; ?>
    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- ══ Card Modal ══════════════════════════════════ -->
<div class="modal-overlay" id="cardModal" onclick="if(event.target===this) closeModal()">
  <div class="modal-card">
    <button class="modal-close" onclick="closeModal()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
    <div class="modal-body" id="modalBody">
      <div class="modal-loading">Carregando…</div>
    </div>
  </div>
</div>

<!-- ══ Label Picker ════════════════════════════════ -->
<div class="label-picker" id="labelPicker" style="display:none;">
  <div class="label-picker-header">
    <span>Etiquetas</span>
    <button class="label-picker-close" onclick="closeLabelPicker()">×</button>
  </div>
  <div class="label-picker-list" id="labelPickerList"></div>
</div>

<!-- ══ Toast ═══════════════════════════════════════ -->
<div class="toast" id="toast"></div>

<!-- ══ Lightbox ════════════════════════════════════ -->
<div id="lightbox">
  <button id="lightbox-close" onclick="closeLightbox()" title="Fechar">&#x2715;</button>
  <img id="lightbox-img" src="" alt="">
  <div id="lightbox-name"></div>
  <button id="lightbox-open-btn">Abrir em nova aba</button>
</div>

<!-- ══ SortableJS ══════════════════════════════════ -->
<script src="/public/js/Sortable.min.js"></script>

<script>
// ── Constantes PHP → JS ───────────────────────────
const API              = '/modules/trello/api.php';
const BOARD_ID_1       = <?= $js_board_id1 ?>;
const BOARD_ID_2       = <?= $js_board_id2 ?>;
const BOARD_LISTS_1    = <?= $js_board_lists1 ?>;
const BOARD_LISTS_2    = <?= $js_board_lists2 ?>;
const BOARD_MEMBERS_1  = <?= $js_members_map1 ?>;
const BOARD_MEMBERS_2  = <?= $js_members_map2 ?>;
const BOARD_LABELS_1   = <?= $js_board_labels1 ?>;
const BOARD_LABELS_2   = <?= $js_board_labels2 ?>;
const LABEL_HEX        = <?= $js_label_hex ?>;
const LABEL_NAME_HEX   = <?= $js_label_name_hex ?>;
const BOARD_STATS_1    = <?= json_encode(boardStats($board_data1)) ?>;
const BOARD_STATS_2    = <?= json_encode(boardStats($board_data2)) ?>;

// Estado do board ativo (sincronizado com a aba)
let BOARD_ID      = BOARD_ID_1;
let BOARD_LISTS   = BOARD_LISTS_1.slice();
let BOARD_MEMBERS = Object.assign({}, BOARD_MEMBERS_1);
let BOARD_LABELS  = BOARD_LABELS_1.slice();

// ── Troca de aba ─────────────────────────────────
function switchBoardTab(n) {
  document.querySelectorAll('.board-tab-btn').forEach(b => b.classList.toggle('active', +b.dataset.tab === n));
  document.querySelectorAll('.board-tab-content').forEach(c => c.classList.toggle('active', +c.dataset.tab === n));
  const statsEl = document.getElementById('boardStats');
  if (n === 1) {
    BOARD_ID = BOARD_ID_1; BOARD_LISTS = BOARD_LISTS_1.slice();
    BOARD_MEMBERS = Object.assign({}, BOARD_MEMBERS_1); BOARD_LABELS = BOARD_LABELS_1.slice();
    if (statsEl) statsEl.innerHTML = BOARD_STATS_1;
  } else {
    BOARD_ID = BOARD_ID_2; BOARD_LISTS = BOARD_LISTS_2.slice();
    BOARD_MEMBERS = Object.assign({}, BOARD_MEMBERS_2); BOARD_LABELS = BOARD_LABELS_2.slice();
    if (statsEl) statsEl.innerHTML = BOARD_STATS_2;
  }
  try { localStorage.setItem('trello_active_tab', n); } catch(e) {}
}
// Restaura aba salva
(function(){ try { const t=+localStorage.getItem('trello_active_tab'); if(t===2) switchBoardTab(2); } catch(e){} })();

// ── Estado ────────────────────────────────────────
let currentCard      = null;   // dados completos do card aberto
let currentCardLabels = [];    // IDs das labels ativas no card
let _dragEndTime     = 0;      // timestamp do último onEnd de drag

// ── Helpers ───────────────────────────────────────
function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function labelColor(color, name) {
  const n = name ? name.toLowerCase() : '';
  return (n && LABEL_NAME_HEX[n]) || LABEL_HEX[color] || { bg: '#334155', text: '#fff' };
}
function lBg(color, name)   { return labelColor(color, name).bg;   }
function lText(color, name) { return labelColor(color, name).text;  }
async function apiGet(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const r  = await fetch(`${API}?${qs}`);
  if (!r.ok) throw new Error('API error ' + r.status);
  return r.json();
}
async function apiPost(action, data = {}) {
  const r = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  });
  if (!r.ok) throw new Error('API error ' + r.status);
  return r.json();
}
function showToast(msg, ms = 2500) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), ms);
}

// ── Data no topbar ─────────────────────────────────
(function(){
  const dias  = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
  const meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  const d = new Date();
  const el = document.getElementById('topbar-date');
  if (el) el.textContent = dias[d.getDay()] + ', ' + d.getDate() + ' de ' + meses[d.getMonth()] + ' de ' + d.getFullYear();
})();

// ══ DRAG & DROP ════════════════════════════════════
function initDrag() {
  if (typeof Sortable === 'undefined') {
    showToast('ERRO: SortableJS não carregou');
    return;
  }

  document.querySelectorAll('.kanban-cards').forEach(el => {
    new Sortable(el, {
      group:             'kanban',
      animation:         150,
      ghostClass:        'card-ghost',
      chosenClass:       'card-chosen',
      dragClass:         'card-dragging',
      forceFallback:     true,
      fallbackOnBody:    true,
      fallbackTolerance: 3,

      onStart() {
        document.querySelectorAll('.kanban-cards').forEach(c => c.classList.add('sortable-over'));
      },

      onEnd(evt) {
        _dragEndTime = Date.now();
        document.querySelectorAll('.kanban-cards').forEach(c => c.classList.remove('sortable-over'));

        const cardEl = evt.item;
        const cardId = cardEl.dataset.id;
        const newList = evt.to;
        const oldList = evt.from;
        const listId  = newList.dataset.listId;

        if (!cardId || !listId) { showToast('Erro: card ou lista não identificados'); return; }

        updateColCount(oldList);
        updateColCount(newList);
        newList.querySelectorAll('.empty-col').forEach(e => e.remove());

        const siblings = [...newList.querySelectorAll('.kanban-card')];
        const idx      = siblings.indexOf(cardEl);
        let pos;
        if (siblings.length <= 1) pos = 'bottom';
        else if (idx === 0)       pos = 'top';
        else {
          const prev = parseFloat(siblings[idx - 1]?.dataset.pos || 0);
          const next = idx < siblings.length - 1
                       ? parseFloat(siblings[idx + 1].dataset.pos || prev + 65536)
                       : prev + 65536;
          pos = (prev + next) / 2;
          cardEl.dataset.pos = pos;
        }

        apiPost('move_card', { id: cardId, idList: listId, pos })
          .then(d => { if (!d.ok) showToast('Erro ao mover card no Trello'); })
          .catch(() => showToast('Erro de rede ao mover card'));
      }
    });
  });
}
function updateColCount(listEl) {
  const col = listEl.closest('.kanban-col');
  if (!col) return;
  col.querySelector('.kanban-col-count').textContent =
    listEl.querySelectorAll('.kanban-card').length;
}

// ══ MODAL ══════════════════════════════════════════
async function openCard(cardId) {
  const modal = document.getElementById('cardModal');
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
  document.getElementById('modalBody').innerHTML = '<div class="modal-loading">Carregando…</div>';
  try {
    const data = await apiGet('card_detail', { id: cardId });
    currentCard       = data;
    currentCardLabels = (data.labels || []).map(l => l.id);
    renderModal(data);
  } catch(e) {
    document.getElementById('modalBody').innerHTML =
      '<div class="modal-loading" style="color:#ef4444;">Erro ao carregar card.</div>';
  }
}
function closeModal() {
  document.getElementById('cardModal').classList.remove('open');
  document.body.style.overflow = '';
  currentCard = null;
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function getListName(idList) {
  const l = BOARD_LISTS.find(x => x.id === idList);
  return l ? l.name : '';
}

function renderModal(card) {
  // Cover
  let coverHtml = '';
  const cover = card.cover || {};
  if (cover.color) {
    const cc = { blue:'#0079bf', orange:'#d29034', green:'#519839', red:'#b04632', purple:'#89609e', pink:'#cd5a91', lime:'#4bbf6b', sky:'#00aecc', grey:'#838c91' };
    coverHtml = `<div class="modal-cover solid" style="background:${cc[cover.color]||'#334155'}"></div>`;
  } else if (cover.scaled && cover.scaled.length) {
    const best = cover.scaled[cover.scaled.length - 1];
    if (best?.url) coverHtml = `<div class="modal-cover"><img src="${escHtml(best.url)}" alt=""></div>`;
  }

  // Labels
  const labelsHtml = (card.labels || []).map(l => {
    return `<span class="modal-label-pill" style="background:${lBg(l.color,l.name)};color:${lText(l.color,l.name)};" onclick="openLabelPicker(event)">${escHtml(l.name || l.color || '')}</span>`;
  }).join('');

  // Members
  const membersHtml = (card.idMembers || []).map(mid => {
    const m = BOARD_MEMBERS[mid] || {};
    return `<span class="card-avatar lg" title="${escHtml(m.fullName||'')}">${escHtml(m.initials||'?')}</span>`;
  }).join('');

  // Due date
  let dueHtml = '';
  if (card.due) {
    const d   = new Date(card.due);
    const fmt = d.toLocaleDateString('pt-BR', { day:'2-digit', month:'2-digit', year:'numeric' });
    const diff = d - new Date();
    const cls  = card.dueComplete ? 'done' : diff < 0 ? 'late' : diff < 172800000 ? 'soon' : 'ok';
    dueHtml = `
      <div class="modal-section">
        <div class="modal-section-title">DATA DE VENCIMENTO</div>
        <div class="modal-due-row">
          <input type="checkbox" id="dueCheck" ${card.dueComplete ? 'checked' : ''} onchange="toggleDue(this)">
          <label for="dueCheck" class="card-due ${cls}">${escHtml(fmt)}</label>
        </div>
      </div>`;
  }

  // Description
  const descHtml = card.desc
    ? `<div class="modal-desc-text">${escHtml(card.desc)}</div>`
    : `<div class="modal-desc-placeholder">Adicionar uma descrição mais detalhada…</div>`;

  // Attachments
  let attachHtml = '';
  if (card.attachments && card.attachments.length > 0) {
    const items = card.attachments.map(a => {
      const isImg = a.mimeType && a.mimeType.startsWith('image/');
      const preview = (a.previews || []).find(p => p.width >= 120) || a.previews?.[0];
      const imgEl = isImg && preview?.url
        ? `<img class="attach-thumb" src="${escHtml(preview.url)}" alt="" loading="lazy">`
        : `<div class="attach-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--text-muted)"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>`;
      const size = a.bytes ? (a.bytes > 1048576 ? (a.bytes/1048576).toFixed(1)+' MB' : (a.bytes/1024).toFixed(0)+' KB') : '';
      if (isImg) {
        return `<a class="attach-item" href="javascript:void(0)" onclick="openLightbox('${escHtml(a.url).replace(/'/g,"\\'")}','${escHtml(a.name).replace(/'/g,"\\'")}');event.preventDefault();">${imgEl}<div><div class="attach-name">${escHtml(a.name)}</div><div class="attach-meta">${escHtml(size)}</div></div></a>`;
      }
      return `<a class="attach-item" href="${escHtml(a.url)}" target="_blank" rel="noopener">${imgEl}<div><div class="attach-name">${escHtml(a.name)}</div><div class="attach-meta">${escHtml(size)}</div></div></a>`;
    }).join('');
    attachHtml = `
      <div class="modal-section">
        <div class="modal-section-title-row">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--text-muted)"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          <div class="modal-section-title" style="margin-bottom:0">ANEXOS</div>
        </div>
        <div class="modal-attachments">${items}</div>
      </div>`;
  }

  // Checklists
  let checkHtml = '';
  for (const cl of card.checklists || []) {
    const items   = cl.checkItems || [];
    const done    = items.filter(i => i.state === 'complete').length;
    const total   = items.length;
    const pct     = total > 0 ? Math.round(done / total * 100) : 0;
    const barColor = pct === 100 ? '#00c9a7' : '#2d6aff';
    const itemsHtml = items.map(item => {
      const isDone = item.state === 'complete';
      return `<div class="checklist-item" onclick="toggleCheck('${escHtml(card.id)}','${escHtml(item.id)}','${isDone ? 'incomplete' : 'complete'}',this)">
        <input type="checkbox" ${isDone ? 'checked' : ''} onclick="event.stopPropagation();toggleCheck('${escHtml(card.id)}','${escHtml(item.id)}','${isDone ? 'incomplete' : 'complete'}',this.closest('.checklist-item'))">
        <span class="checklist-item-name${isDone ? ' done' : ''}">${escHtml(item.name)}</span>
      </div>`;
    }).join('');
    checkHtml += `
      <div class="modal-section modal-checklist">
        <div class="modal-section-title-row">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--text-muted)"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          <div class="modal-section-title" style="margin-bottom:0">${escHtml(cl.name)}</div>
          <span style="font-size:11px;color:var(--text-muted);margin-left:auto;">${done}/${total}</span>
        </div>
        <div class="checklist-progress"><div class="checklist-progress-fill" style="width:${pct}%;background:${barColor};"></div></div>
        ${itemsHtml}
      </div>`;
  }

  // Move options
  const moveOptions = BOARD_LISTS
    .filter(l => l.id !== card.idList)
    .map(l => `<option value="${escHtml(l.id)}">${escHtml(l.name)}</option>`)
    .join('');

  document.getElementById('modalBody').innerHTML = `
    ${coverHtml}
    <div class="modal-inner">
      <div class="modal-main">

        <div class="modal-title-wrap">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;color:var(--text-muted);margin-top:3px"><rect x="3" y="3" width="7" height="18" rx="2"/><rect x="14" y="3" width="7" height="11" rx="2"/></svg>
          <div style="flex:1">
            <div class="modal-title" id="modalTitle" contenteditable="true" spellcheck="false"
                 onblur="saveTitle(this)" onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">${escHtml(card.name)}</div>
            <div class="modal-list-name">na lista <strong>${escHtml(getListName(card.idList))}</strong></div>
          </div>
        </div>

        <div class="modal-section">
          <div class="modal-section-title">ETIQUETAS</div>
          <div class="modal-labels-row" id="modalLabelsRow">
            ${labelsHtml}
            <button class="modal-label-add" onclick="openLabelPicker(event)">+ Etiqueta</button>
          </div>
        </div>

        ${membersHtml ? `<div class="modal-section"><div class="modal-section-title">MEMBROS</div><div class="modal-members-row">${membersHtml}</div></div>` : ''}
        ${dueHtml}

        <div class="modal-section">
          <div class="modal-section-title-row">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:var(--text-muted)"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            <div class="modal-section-title" style="margin-bottom:0">DESCRIÇÃO</div>
          </div>
          <div class="modal-desc-wrap" id="descWrap" onclick="editDesc()" title="Clique para editar">${descHtml}</div>
          <textarea class="modal-desc-editor" id="descEditor" style="display:none;">${escHtml(card.desc || '')}</textarea>
          <div class="desc-actions" id="descActions" style="display:none;">
            <button class="btn-sm" onclick="saveDesc()">Salvar</button>
            <button class="btn-sm ghost" onclick="cancelDesc()">Cancelar</button>
          </div>
        </div>

        ${attachHtml}
        ${checkHtml}
      </div>

      <div class="modal-sidebar">
        <div class="modal-sidebar-group">
          <div class="modal-sidebar-title">AÇÕES</div>
          <button class="modal-action-btn" onclick="openLabelPicker(event)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            Etiquetas
          </button>
          <button class="modal-action-btn" id="btnMove" onclick="toggleMovePanel()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>
            Mover
          </button>
          <button class="modal-action-btn" onclick="toggleCommentPanel()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Comentar
          </button>
          <a class="modal-action-btn" href="${escHtml(card.shortUrl || '#')}" target="_blank" rel="noopener">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            Ver no Trello
          </a>
          <button class="modal-action-btn danger" onclick="archiveCard()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
            Arquivar
          </button>
        </div>

        <div class="modal-comment-panel" id="commentPanelEl" style="display:none;">
          <div class="modal-sidebar-title" style="margin-bottom:8px;">COMENTÁRIO</div>
          <textarea class="modal-comment-textarea" id="commentText" placeholder="Escrever comentário…"></textarea>
          <div style="display:flex;gap:6px;margin-top:6px;">
            <button class="btn-sm" style="flex:1;" onclick="submitComment()">Enviar</button>
            <button class="btn-sm ghost" onclick="toggleCommentPanel()">Cancelar</button>
          </div>
        </div>

        <div class="modal-move-panel" id="movePanelEl" style="display:none;">
          <div class="modal-sidebar-title" style="margin-bottom:8px;">MOVER PARA</div>
          <select class="modal-move-select" id="moveListSelect">
            <option value="">— Selecione uma lista —</option>
            ${moveOptions}
          </select>
          <button class="btn-sm" style="width:100%;" onclick="doMoveCard()">Mover</button>
        </div>
      </div>
    </div>`;
}

// ══ TÍTULO ═════════════════════════════════════════
function saveTitle(el) {
  if (!currentCard) return;
  const newName = el.textContent.trim();
  if (!newName || newName === currentCard.name) return;
  apiPost('update_card', { id: currentCard.id, name: newName })
    .then(r => {
      if (!r || !r.ok) { showToast('Erro ao salvar'); return; }
      currentCard.name = newName;
      // Atualiza título no card do kanban
      const cardEl = document.querySelector(`.kanban-card[data-id="${currentCard.id}"] .card-title`);
      if (cardEl) cardEl.textContent = newName;
      showToast('Título atualizado');
    })
    .catch(() => showToast('Erro ao salvar'));
}

// ══ DESCRIÇÃO ══════════════════════════════════════
function editDesc() {
  const wrap    = document.getElementById('descWrap');
  const editor  = document.getElementById('descEditor');
  const actions = document.getElementById('descActions');
  if (!wrap || !editor) return;
  wrap.style.display    = 'none';
  editor.style.display  = 'block';
  actions.style.display = 'flex';
  editor.value = currentCard?.desc || '';
  editor.focus();
  editor.setSelectionRange(editor.value.length, editor.value.length);
}
function cancelDesc() {
  document.getElementById('descWrap').style.display    = 'block';
  document.getElementById('descEditor').style.display  = 'none';
  document.getElementById('descActions').style.display = 'none';
}
function saveDesc() {
  if (!currentCard) return;
  const newDesc = document.getElementById('descEditor').value;
  apiPost('update_card', { id: currentCard.id, desc: newDesc })
    .then(r => {
      if (!r || !r.ok) { showToast('Erro ao salvar'); return; }
      currentCard.desc = newDesc;
      const wrap = document.getElementById('descWrap');
      wrap.innerHTML = newDesc
        ? `<div class="modal-desc-text">${escHtml(newDesc)}</div>`
        : `<div class="modal-desc-placeholder">Adicionar uma descrição mais detalhada…</div>`;
      cancelDesc();
      // Atualiza ícone de desc no kanban
      const cardEl = document.querySelector(`.kanban-card[data-id="${currentCard.id}"] .card-footer`);
      showToast('Descrição salva');
    })
    .catch(() => showToast('Erro ao salvar'));
}

// ══ DATA DE VENCIMENTO ═════════════════════════════
function toggleDue(checkbox) {
  if (!currentCard) return;
  const done = checkbox.checked;
  apiPost('update_card', { id: currentCard.id, dueComplete: done })
    .then(r => {
      if (!r || !r.ok) { showToast('Erro ao atualizar'); return; }
      currentCard.dueComplete = done;
      const label = document.querySelector('#dueCheck + label');
      if (label) {
        label.className = 'card-due ' + (done ? 'done' : (new Date(currentCard.due) < new Date() ? 'late' : 'ok'));
      }
      showToast(done ? 'Marcado como concluído' : 'Marcado como pendente');
    })
    .catch(() => showToast('Erro ao atualizar'));
}

// ══ MOVER CARD ═════════════════════════════════════
function toggleMovePanel() {
  const p = document.getElementById('movePanelEl');
  const isOpen = p.style.display !== 'none';
  p.style.display = isOpen ? 'none' : 'block';
  if (!isOpen) document.getElementById('commentPanelEl').style.display = 'none';
}
function doMoveCard() {
  if (!currentCard) return;
  const listId = document.getElementById('moveListSelect').value;
  if (!listId) return;
  apiPost('move_card', { id: currentCard.id, idList: listId, pos: 'bottom' })
    .then(d => {
      if (d.ok) {
        showToast('Card movido!');
        // Move o elemento no DOM
        const cardEl = document.querySelector(`.kanban-card[data-id="${currentCard.id}"]`);
        const target = document.querySelector(`.kanban-cards[data-list-id="${listId}"]`);
        if (cardEl && target) {
          target.querySelectorAll('.empty-col').forEach(e => e.remove());
          target.appendChild(cardEl);
          updateColCount(cardEl.closest('.kanban-cards'));
          updateColCount(target);
        }
        closeModal();
      } else {
        showToast('Erro ao mover');
      }
    })
    .catch(() => showToast('Erro ao mover'));
}

// ══ ETIQUETAS ══════════════════════════════════════
function openLabelPicker(evt) {
  if (evt) evt.stopPropagation();
  if (!currentCard || !BOARD_LABELS) return;

  const picker = document.getElementById('labelPicker');
  const list   = document.getElementById('labelPickerList');

  list.innerHTML = BOARD_LABELS.map(lbl => {
    const active = currentCardLabels.includes(lbl.id);
    return `<div class="label-picker-item" onclick="toggleLabel('${escHtml(lbl.id)}')">
      <span class="label-picker-swatch" style="background:${lBg(lbl.color,lbl.name)};"></span>
      <span class="label-picker-name">${escHtml(lbl.name || lbl.color || '—')}</span>
      <span class="label-picker-check" id="lcheck_${escHtml(lbl.id)}" style="${active ? '' : 'display:none'}">✓</span>
    </div>`;
  }).join('');

  // Posiciona o picker
  picker.style.display = 'block';
  const modal = document.querySelector('.modal-card');
  const mRect = modal.getBoundingClientRect();
  picker.style.top  = (mRect.top + 60) + 'px';
  picker.style.left = (mRect.right - 260) + 'px';
}
function closeLabelPicker() {
  document.getElementById('labelPicker').style.display = 'none';
}
function toggleLabel(labelId) {
  if (!currentCard) return;
  const idx = currentCardLabels.indexOf(labelId);
  if (idx === -1) currentCardLabels.push(labelId);
  else currentCardLabels.splice(idx, 1);

  // Atualiza checkmark no picker
  const chk = document.getElementById(`lcheck_${labelId}`);
  if (chk) chk.style.display = idx === -1 ? '' : 'none';

  // Salva
  apiPost('update_card', { id: currentCard.id, idLabels: currentCardLabels })
    .then(updated => {
      if (!updated || !updated.ok) {
        // Reverte update otimista
        if (idx === -1) {
          const i2 = currentCardLabels.indexOf(labelId);
          if (i2 !== -1) currentCardLabels.splice(i2, 1);
        } else {
          currentCardLabels.splice(idx, 0, labelId);
        }
        const chkEl = document.getElementById(`lcheck_${labelId}`);
        if (chkEl) chkEl.style.display = idx === -1 ? 'none' : '';
        showToast('Erro ao salvar etiqueta');
        return;
      }
      if (updated.labels) {
        currentCard.labels = updated.labels;
        // Re-renderiza labels no modal
        const row = document.getElementById('modalLabelsRow');
        if (row) {
          const labelsHtml = updated.labels.map(l =>
            `<span class="modal-label-pill" style="background:${lBg(l.color,l.name)};color:${lText(l.color,l.name)};" onclick="openLabelPicker(event)">${escHtml(l.name || l.color || '')}</span>`
          ).join('');
          row.innerHTML = labelsHtml + '<button class="modal-label-add" onclick="openLabelPicker(event)">+ Etiqueta</button>';
        }
        // Re-renderiza labels no card do kanban
        const cardEl = document.querySelector(`.kanban-card[data-id="${currentCard.id}"] .card-labels`);
        if (cardEl) {
          cardEl.innerHTML = updated.labels.map(l =>
            `<span class="card-label wide" style="background:${lBg(l.color,l.name)};" title="${escHtml(l.name||'')}"></span>`
          ).join('');
        }
      }
    })
    .catch(() => showToast('Erro de conexão'));
}

// Fecha picker ao clicar fora
document.addEventListener('click', e => {
  const picker = document.getElementById('labelPicker');
  if (picker.style.display !== 'none' && !picker.contains(e.target)) {
    closeLabelPicker();
  }
});

// ══ CHECKLIST ══════════════════════════════════════
function toggleCheck(cardId, itemId, newState, rowEl) {
  apiPost('check_item', { cardId, itemId, state: newState })
    .then(d => {
      if (!d.ok) return;
      const isDone   = newState === 'complete';
      const nameEl   = rowEl.querySelector('.checklist-item-name');
      const checkEl  = rowEl.querySelector('input[type=checkbox]');
      if (nameEl)  nameEl.classList.toggle('done', isDone);
      if (checkEl) checkEl.checked = isDone;
      // Atualiza data-onclick para o próximo toggle
      rowEl.setAttribute('onclick', `toggleCheck('${escHtml(cardId)}','${escHtml(itemId)}','${isDone ? 'incomplete' : 'complete'}',this)`);
      checkEl?.setAttribute('onclick', `event.stopPropagation();toggleCheck('${escHtml(cardId)}','${escHtml(itemId)}','${isDone ? 'incomplete' : 'complete'}',this.closest('.checklist-item'))`);
      // Atualiza barra de progresso
      updateCheckProgress(rowEl.closest('.modal-checklist'));
    })
    .catch(() => showToast('Erro ao atualizar'));
}
function updateCheckProgress(clEl) {
  if (!clEl) return;
  const items = clEl.querySelectorAll('.checklist-item');
  const done  = clEl.querySelectorAll('.checklist-item-name.done').length;
  const pct   = items.length > 0 ? Math.round(done / items.length * 100) : 0;
  const bar   = clEl.querySelector('.checklist-progress-fill');
  if (bar) { bar.style.width = pct + '%'; bar.style.background = pct === 100 ? '#00c9a7' : '#2d6aff'; }
  const counter = clEl.querySelector('.modal-section-title-row span');
  if (counter) counter.textContent = `${done}/${items.length}`;
}

// ══ COMENTÁRIO ═════════════════════════════════════
function toggleCommentPanel() {
  const p = document.getElementById('commentPanelEl');
  const isOpen = p.style.display !== 'none';
  p.style.display = isOpen ? 'none' : 'block';
  if (!isOpen) {
    document.getElementById('commentText').value = '';
    document.getElementById('commentText').focus();
    // Fecha painel de mover se estiver aberto
    document.getElementById('movePanelEl').style.display = 'none';
  }
}
function submitComment() {
  if (!currentCard) return;
  const text = document.getElementById('commentText').value.trim();
  if (!text) return;
  apiPost('add_comment', { id: currentCard.id, text })
    .then(d => {
      if (d.ok) {
        showToast('Comentário adicionado!');
        document.getElementById('commentPanelEl').style.display = 'none';
        document.getElementById('commentText').value = '';
      } else {
        showToast('Erro ao adicionar comentário');
      }
    })
    .catch(() => showToast('Erro ao adicionar comentário'));
}

// ══ ARQUIVAR CARD ═══════════════════════════════════
function archiveCard() {
  if (!currentCard) return;
  if (!confirm('Arquivar este card?')) return;
  apiPost('archive_card', { id: currentCard.id })
    .then(d => {
      if (d.ok) {
        showToast('Card arquivado!');
        // Remove o card do DOM
        const cardEl = document.querySelector(`.kanban-card[data-id="${currentCard.id}"]`);
        if (cardEl) {
          const listEl = cardEl.closest('.kanban-cards');
          cardEl.remove();
          if (listEl) updateColCount(listEl);
        }
        closeModal();
      } else {
        showToast('Erro ao arquivar');
      }
    })
    .catch(() => showToast('Erro ao arquivar'));
}

// ══ LIGHTBOX ═══════════════════════════════════════
function openLightbox(url, name) {
  const lb = document.getElementById('lightbox');
  document.getElementById('lightbox-img').src = url;
  document.getElementById('lightbox-name').textContent = name || '';
  document.getElementById('lightbox-open-btn').onclick = () => window.open(url, '_blank');
  lb.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.getElementById('lightbox-img').src = '';
  document.body.style.overflow = '';
}
document.getElementById('lightbox').addEventListener('click', function(e) {
  if (e.target === this) closeLightbox();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLightbox();
});

<?php if ($trello_ok && (!empty($board_data1['lists']) || !empty($board_data2['lists']))): ?>
initDrag();
<?php endif; ?>
</script>
</body>
</html>
