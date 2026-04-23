<?php
// ══════════════════════════════════════════════════
//  DOMBAG — Trello API Proxy (AJAX)
//  Todos os requests ao Trello passam por aqui
// ══════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── Credenciais ───────────────────────────────────
$key = $token = '';
try {
    $pdo = dbPDO();
    $st  = $pdo->prepare("SELECT PAR_CHAVE, PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE IN ('trello_api_key','trello_token')");
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['PAR_CHAVE'] === 'trello_api_key') $key   = trim($r['PAR_VALOR']);
        if ($r['PAR_CHAVE'] === 'trello_token')   $token = trim($r['PAR_VALOR']);
    }
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

if (!$key || !$token) {
    http_response_code(401);
    echo json_encode(['error' => 'not_configured']);
    exit;
}

// ── Helper de chamada à API ───────────────────────
// $body_fields: array para enviar como form-body (PUT/POST)
function t_req(string $method, string $ep, string $key, string $token, array $body_fields = []): array
{
    $sep = str_contains($ep, '?') ? '&' : '?';
    $url = 'https://api.trello.com/1' . $ep . $sep . 'key=' . urlencode($key) . '&token=' . urlencode($token);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];

    if (!empty($body_fields)) {
        $opts[CURLOPT_POSTFIELDS]  = http_build_query($body_fields);
        $opts[CURLOPT_HTTPHEADER]  = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }

    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['code' => 0, 'data' => null, 'error' => $err];
    }

    $data = json_decode($body, true);
    return ['code' => $code, 'data' => $data];
}

// ── Roteamento ────────────────────────────────────
$action = $_REQUEST['action'] ?? '';
$in     = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ── Detalhes completos de um card ─────────────────
    case 'card_detail':
        $id = $in['id'] ?? $_GET['id'] ?? '';
        if (!$id) { echo json_encode(['error' => 'no_id']); exit; }
        $r = t_req('GET',
            "/cards/{$id}"
            . '?fields=all'
            . '&attachments=true&attachment_fields=all'
            . '&checklists=all&checklist_fields=all'
            . '&members=true&member_fields=fullName,initials,avatarUrl',
            $key, $token
        );
        if ($r['code'] !== 200) {
            http_response_code(502);
            echo json_encode(['error' => 'trello_error', 'code' => $r['code']]);
            exit;
        }
        echo json_encode($r['data']);
        break;

    // ── Move card para outra lista / posição ──────────
    case 'move_card':
        $id     = $in['id']     ?? '';
        $listId = $in['idList'] ?? '';
        $pos    = $in['pos']    ?? 'bottom';
        if (!$id || !$listId) { echo json_encode(['error' => 'missing']); exit; }
        $fields = ['idList' => $listId, 'pos' => $pos];
        $r = t_req('PUT', "/cards/{$id}", $key, $token, $fields);
        echo json_encode(['ok' => $r['code'] === 200]);
        break;

    // ── Atualiza campos do card ────────────────────────
    case 'update_card':
        $id = $in['id'] ?? '';
        if (!$id) { echo json_encode(['error' => 'no_id']); exit; }
        $fields = [];
        if (array_key_exists('name', $in))        $fields['name']        = $in['name'];
        if (array_key_exists('desc', $in))        $fields['desc']        = $in['desc'];
        if (array_key_exists('due', $in))         $fields['due']         = $in['due'] ?: 'null';
        if (array_key_exists('dueComplete', $in)) $fields['dueComplete'] = $in['dueComplete'] ? 'true' : 'false';
        if (array_key_exists('idLabels', $in))    $fields['idLabels']    = implode(',', (array)$in['idLabels']);
        if (array_key_exists('idList', $in))      $fields['idList']      = $in['idList'];
        if (empty($fields)) { echo json_encode(['error' => 'no_fields']); exit; }
        $r = t_req('PUT', "/cards/{$id}", $key, $token, $fields);
        if ($r['code'] !== 200) {
            echo json_encode(['ok' => false, 'error' => 'trello_error', 'code' => $r['code']]);
            exit;
        }
        $d = is_array($r['data']) ? $r['data'] : [];
        echo json_encode([
            'ok'     => true,
            'labels' => $d['labels'] ?? [],
            'name'   => $d['name']   ?? '',
        ]);
        break;

    // ── Arquivar card ─────────────────────────────────
    case 'archive_card':
        $id = $in['id'] ?? '';
        if (!$id) { echo json_encode(['error' => 'no_id']); exit; }
        $r = t_req('PUT', "/cards/{$id}", $key, $token, ['closed' => 'true']);
        echo json_encode(['ok' => $r['code'] === 200]);
        break;

    // ── Adicionar comentário ──────────────────────────
    case 'add_comment':
        $id   = $in['id']   ?? '';
        $text = trim($in['text'] ?? '');
        if (!$id || $text === '') { echo json_encode(['error' => 'missing']); exit; }
        $r = t_req('POST', "/cards/{$id}/actions/comments", $key, $token, ['text' => $text]);
        echo json_encode(['ok' => $r['code'] === 200, 'data' => $r['data']]);
        break;

    // ── Labels do board ───────────────────────────────
    case 'board_labels':
        $bid = $in['boardId'] ?? $_GET['boardId'] ?? '';
        if (!$bid) { echo json_encode(['error' => 'no_board']); exit; }
        $r = t_req('GET', "/boards/{$bid}/labels?fields=all", $key, $token);
        echo json_encode($r['data']);
        break;

    // ── Movimentos de cards no board ──────────────────
    case 'board_actions':
        $bid   = $in['boardId'] ?? $_GET['boardId'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 30), 50);
        if (!$bid) { echo json_encode(['error' => 'no_board']); exit; }
        $r = t_req('GET',
            "/boards/{$bid}/actions"
            . '?filter=updateCard:idList'
            . "&limit={$limit}"
            . '&fields=data,date,type'
            . '&memberCreator=true'
            . '&memberCreator_fields=fullName,initials',
            $key, $token
        );
        if ($r['code'] !== 200) {
            http_response_code(502);
            echo json_encode(['error' => 'trello_error']);
            exit;
        }
        $actions = [];
        foreach (($r['data'] ?? []) as $a) {
            $actions[] = [
                'id'          => $a['id'],
                'date'        => $a['date'],
                'card_name'   => $a['data']['card']['name']        ?? '?',
                'list_before' => $a['data']['listBefore']['name']  ?? '?',
                'list_after'  => $a['data']['listAfter']['name']   ?? '?',
                'membro'      => $a['memberCreator']['fullName']   ?? '',
            ];
        }
        echo json_encode($actions);
        break;

    // ── Lista de boards do membro ─────────────────────
    case 'my_boards':
        $r = t_req('GET', '/members/me/boards?fields=id,name&filter=open', $key, $token);
        echo json_encode($r['code'] === 200 ? $r['data'] : ['error' => 'trello_error']);
        break;

    // ── Estatísticas do board para o dashboard ────────
    case 'board_stats':
        $bid = $in['boardId'] ?? $_GET['boardId'] ?? '';
        if (!$bid) { echo json_encode(['error' => 'no_board']); exit; }

        $lists_r = t_req('GET', "/boards/{$bid}/lists?fields=id,name&filter=open", $key, $token);
        $cards_r = t_req('GET', "/boards/{$bid}/cards?fields=id,idList,due,dueComplete&filter=open", $key, $token);

        if ($lists_r['code'] !== 200 || $cards_r['code'] !== 200) {
            http_response_code(502);
            echo json_encode(['error' => 'trello_error']);
            exit;
        }

        $lists = $lists_r['data'] ?? [];
        $cards = $cards_r['data'] ?? [];
        $now   = time();

        // Listas a ocultar no dashboard (comparação case-insensitive)
        $hidden = ['finalizados', 'finalizados bag', 'cancelados'];

        $per_list = [];
        foreach ($lists as $l) {
            if (in_array(mb_strtolower(trim($l['name'])), $hidden, true)) continue;
            $per_list[$l['id']] = ['name' => $l['name'], 'count' => 0];
        }
        $hidden_ids = array_diff(array_column($lists, 'id'), array_keys($per_list));
        foreach ($cards as $c) {
            if (isset($per_list[$c['idList']])) $per_list[$c['idList']]['count']++;
        }
        $cards = array_filter($cards, fn($c) => !in_array($c['idList'], $hidden_ids, true));

        $overdue   = 0;
        $due_today = 0;
        $today_str = date('Y-m-d');
        foreach ($cards as $c) {
            if (empty($c['due']) || $c['dueComplete']) continue;
            $due_ts  = strtotime($c['due']);
            $due_day = date('Y-m-d', $due_ts);
            if ($due_ts < $now)              $overdue++;
            elseif ($due_day === $today_str) $due_today++;
        }

        echo json_encode([
            'total'     => count($cards),
            'lists'     => array_values($per_list),
            'overdue'   => $overdue,
            'due_today' => $due_today,
        ]);
        break;

    // ── Marcar/desmarcar item de checklist ────────────
    case 'check_item':
        $cardId = $in['cardId'] ?? '';
        $itemId = $in['itemId'] ?? '';
        $state  = $in['state']  ?? 'incomplete';
        if (!$cardId || !$itemId) { echo json_encode(['error' => 'missing']); exit; }
        $r = t_req('PUT', "/cards/{$cardId}/checkItem/{$itemId}", $key, $token, ['state' => $state]);
        echo json_encode(['ok' => $r['code'] === 200]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown_action']);
}
