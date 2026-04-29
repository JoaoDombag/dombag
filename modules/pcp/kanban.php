<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

$pdo = dbPDO();

// ── Garante tabela de imagens ─────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ITENS_VENDAS_IMAGENS (
            img_codigo    INT AUTO_INCREMENT PRIMARY KEY,
            iv_codigo     INT          NOT NULL,
            img_arquivo   VARCHAR(255) NOT NULL,
            img_nome_orig VARCHAR(255) NOT NULL,
            img_criado_em DATETIME     DEFAULT NOW(),
            INDEX idx_iv (iv_codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {}

// ── AJAX: mover card (status) ─────────────────────────────────────────────────
$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($body['id'] ?? 0);
        $status = (string) ($body['status'] ?? '');
        $valid = ['Pendente de produção', 'Produção', 'Aguardando', 'Aguardando envio', 'Finalizado'];
        if (!$id || !in_array($status, $valid, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']);
            exit;
        }
        $pdo->prepare('UPDATE ITENS_VENDAS SET iv_status=:s, iv_atualizado_em=NOW() WHERE iv_codigo=:id')
            ->execute([':s' => $status, ':id' => $id]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: buscar imagens ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_images') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'images' => []]); exit; }
    try {
        $st = $pdo->prepare('SELECT img_codigo, img_arquivo, img_nome_orig FROM ITENS_VENDAS_IMAGENS WHERE iv_codigo=:id ORDER BY img_criado_em');
        $st->execute([':id' => $id]);
        echo json_encode(['ok' => true, 'images' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'images' => []]);
    }
    exit;
}

// ── AJAX: upload / delete imagem ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/pedidos/';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

    if ($_POST['action'] === 'upload_image') {
        $id = (int) ($_POST['iv_codigo'] ?? 0);
        if (!$id || empty($_FILES['imagem']['tmp_name'])) {
            echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']); exit;
        }
        $f   = $_FILES['imagem'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true) || $f['size'] > 10 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'msg' => 'Arquivo inválido (máx 10 MB, tipos: jpg/png/gif/webp).']); exit;
        }
        $nome = $id . '_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $uploadDir . $nome)) {
            echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar arquivo.']); exit;
        }
        try {
            $pdo->prepare('INSERT INTO ITENS_VENDAS_IMAGENS (iv_codigo, img_arquivo, img_nome_orig) VALUES (:iv,:arq,:orig)')
                ->execute([':iv' => $id, ':arq' => $nome, ':orig' => $f['name']]);
            echo json_encode(['ok' => true, 'img_codigo' => (int)$pdo->lastInsertId(), 'img_arquivo' => $nome, 'img_nome_orig' => $f['name']]);
        } catch (Throwable $e) {
            @unlink($uploadDir . $nome);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'delete_image') {
        $imgId = (int) ($_POST['img_codigo'] ?? 0);
        if (!$imgId) { echo json_encode(['ok' => false, 'msg' => 'ID inválido.']); exit; }
        try {
            $st = $pdo->prepare('SELECT img_arquivo FROM ITENS_VENDAS_IMAGENS WHERE img_codigo=:id');
            $st->execute([':id' => $imgId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                @unlink($uploadDir . $row['img_arquivo']);
                $pdo->prepare('DELETE FROM ITENS_VENDAS_IMAGENS WHERE img_codigo=:id')->execute([':id' => $imgId]);
            }
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$f_cat       = $_GET['cat'] ?? '';
$f_busca     = trim($_GET['q'] ?? '');
$show_amostras = isset($_GET['amostras']);

// ── Dados ─────────────────────────────────────────────────────────────────────
$where = 'WHERE 1=1';
$params = [];
if ($f_cat) {
    $where .= ' AND p.pro_categoria = :cat';
    $params[':cat'] = $f_cat;
}
if ($f_busca) {
    $where .= ' AND (v.ven_codigo_yzidro LIKE :q OR v.ven_cliente LIKE :q2 OR p.pro_descricao LIKE :q3)';
    $params[':q'] = "%$f_busca%";
    $params[':q2'] = "%$f_busca%";
    $params[':q3'] = "%$f_busca%";
}
if (!$show_amostras) {
    $where .= ' AND iv.iv_qtde >= 20';
}

$stmt = $pdo->prepare("
    SELECT
        iv.iv_codigo,
        iv.iv_qtde,
        iv.iv_status,
        iv.iv_prioridade,
        iv.iv_obs,
        v.ven_codigo,
        v.ven_codigo_yzidro,
        COALESCE(NULLIF(v.ven_fantasia,''), v.ven_cliente, '') AS cliente,
        v.ven_uf,
        v.ven_entrega,
        v.ven_representante,
        p.pro_descricao,
        p.pro_categoria,
        p.pro_tipo,
        iv.iv_atualizado_em,
        (SELECT COUNT(*) FROM ITENS_VENDAS_IMAGENS img WHERE img.iv_codigo = iv.iv_codigo) AS img_count
    FROM ITENS_VENDAS iv
    INNER JOIN VENDAS   v ON v.ven_codigo  = iv.ven_codigo
    INNER JOIN PRODUTOS p ON p.pro_codigo  = iv.pro_codigo
    $where
    ORDER BY
        CASE WHEN COALESCE(iv.iv_prioridade,0) > 0 THEN 0 ELSE 1 END,
        COALESCE(iv.iv_prioridade, 999999),
        v.ven_entrega,
        v.ven_codigo_yzidro
");
$stmt->execute($params);
$todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Progresso real ────────────────────────────────────────────────────────────
$prodProgress = [];
try {
    $pp = $pdo->query("
        SELECT pd_pedido, SUM(pd_quantidade) AS produzido
        FROM PRODUCAO_DIARIA
        WHERE pd_pedido IS NOT NULL AND TRIM(pd_pedido) <> ''
        GROUP BY pd_pedido
    ");
    foreach ($pp->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $prodProgress[trim($r['pd_pedido'])] = (float) $r['produzido'];
    }
} catch (Throwable $e) {
}

// ── Categorias disponíveis para filtro ───────────────────────────────────────
$cats = $pdo->query("SELECT DISTINCT pro_categoria FROM PRODUTOS WHERE pro_categoria IS NOT NULL AND pro_categoria <> '' ORDER BY pro_categoria")
            ->fetchAll(PDO::FETCH_COLUMN);

// ── Colunas do Kanban ─────────────────────────────────────────────────────────
$colunas = [
    'Pendente de produção' => ['cor' => '#f59e0b', 'icon' => 'clock',  'items' => []],
    'Produção'             => ['cor' => '#2d6aff', 'icon' => 'zap',    'items' => []],
    'Aguardando'           => ['cor' => '#a78bfa', 'icon' => 'package','items' => []],
    'Aguardando envio'     => ['cor' => '#f97316', 'icon' => 'truck',  'items' => []],
    'Finalizado'           => ['cor' => '#00c9a7', 'icon' => 'check',  'items' => []],
];

foreach ($todos as $item) {
    $s = $item['iv_status'] ?: 'Pendente de produção';
    // migração suave: status antigo vai para "Aguardando"
    if ($s === 'Aguardando envio') { $s = 'Aguardando'; }
    if (!isset($colunas[$s])) { $s = 'Pendente de produção'; }
    $colunas[$s]['items'][] = $item;
}

$hoje = new DateTime();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kanban de Pedidos | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ── Layout Kanban ─────────────────────────────────────────────────────── */
.content { padding: 20px; overflow: hidden; display: flex; flex-direction: column; gap: 16px; }

.filter-row {
  display: flex; gap: 10px; align-items: center; flex-wrap: wrap; flex-shrink: 0;
}
.filter-row input, .filter-row select {
  height: 34px; padding: 0 12px;
  background: var(--card-bg); border: 1px solid var(--border);
  border-radius: 8px; color: var(--text-primary);
  font-family: inherit; font-size: 12.5px; outline: none;
}
.filter-row input:focus, .filter-row select:focus { border-color: rgba(45,106,255,.5); }
.filter-row input { width: 220px; }
.filter-clear {
  height: 34px; padding: 0 12px; background: transparent;
  border: 1px solid var(--border); border-radius: 8px;
  color: var(--text-muted); font-family: inherit; font-size: 12.5px;
  cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;
}
.filter-clear:hover { background: var(--card-hover); color: var(--text-primary); }

/* Colunas */
.kanban-board {
  display: flex;
  gap: 14px;
  flex: 1;
  overflow-x: auto;
  overflow-y: hidden;
  padding-bottom: 4px;
  align-items: stretch;
}
.kanban-board::-webkit-scrollbar { height: 5px; }
.kanban-board::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

.kanban-col {
  display: flex; flex-direction: column; gap: 0;
  background: rgba(255,255,255,.025);
  border: 1px solid var(--border);
  border-radius: 14px;
  overflow: hidden;
  min-height: 0;
  flex: 1 1 220px;
  min-width: 220px;
  max-width: 400px;
  transition: flex .25s ease, min-width .25s ease, max-width .25s ease;
}
.kanban-col.wide {
  flex: 2 1 480px;
  min-width: 480px;
  max-width: 800px;
}

/* Coluna recolhida */
.kanban-col.collapsed {
  flex: 0 0 48px;
  min-width: 48px;
  max-width: 48px;
}
.kanban-col.collapsed .col-cards,
.kanban-col.collapsed .col-total { display: none; }
.kanban-col.collapsed .col-title {
  writing-mode: vertical-rl;
  transform: rotate(180deg);
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
}
.kanban-col.collapsed .col-header {
  flex-direction: column;
  gap: 10px;
  padding: 12px 0 14px;
  height: 100%;
  align-items: center;
  justify-content: flex-start;
  cursor: pointer;
  border-bottom: none;
}
.kanban-col.collapsed .col-badge { display: none; }
.kanban-col.collapsed .col-dot { margin-bottom: 2px; }

/* Botões do cabeçalho */
.col-toggle, .col-wide-btn {
  background: none; border: none; padding: 0; cursor: pointer;
  color: var(--text-muted); flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 6px;
  transition: background .15s, color .15s;
}
.col-toggle:hover, .col-wide-btn:hover { background: rgba(255,255,255,.10); color: var(--text-primary); }
.col-toggle svg { transition: transform .25s; }
.kanban-col.collapsed .col-toggle svg { transform: rotate(180deg); }
/* Quando larga, o ícone vira "encolher" */
.kanban-col.wide .col-wide-btn svg { transform: rotate(45deg); }
.kanban-col.collapsed .col-wide-btn { display: none; }

.col-header {
  padding: 13px 16px; flex-shrink: 0;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
  background: rgba(0,0,0,.12);
}
.col-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.col-title { font-size: 13px; font-weight: 700; flex: 1; }
.col-badge {
  font-size: 11px; font-weight: 700;
  background: rgba(255,255,255,.08); color: var(--text-muted);
  padding: 2px 8px; border-radius: 20px;
}
.col-total {
  font-size: 10px; color: var(--text-muted); padding: 4px 16px 8px;
  flex-shrink: 0; border-bottom: 1px solid var(--border);
}

.col-cards {
  flex: 1; overflow-y: auto; padding: 10px; display: flex; flex-direction: column; gap: 8px;
}
.col-cards::-webkit-scrollbar { width: 3px; }
.col-cards::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.col-empty {
  text-align: center; padding: 32px 16px;
  font-size: 12px; color: var(--text-muted);
}

/* Card */
.kanban-card {
  background: var(--blue-mid);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 14px;
  cursor: grab;
  transition: box-shadow .15s, border-color .15s, opacity .15s, transform .12s;
  user-select: none;
}
.kanban-card:hover {
  border-color: rgba(45,106,255,.35);
  box-shadow: 0 4px 16px rgba(0,0,0,.25);
}
.kanban-card.dragging { opacity: .45; cursor: grabbing; transform: rotate(1.5deg); }
.kanban-card.drag-over-card { border-color: rgba(45,106,255,.6); box-shadow: 0 0 0 2px rgba(45,106,255,.25); }

.col-cards.drag-over { background: rgba(45,106,255,.05); border-radius: 10px; }

.card-top {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;
  margin-bottom: 8px;
}
.card-num {
  font-size: 13px; font-weight: 800; color: #7db3ff; white-space: nowrap;
}
.card-prio {
  font-size: 10px; font-weight: 700;
  background: rgba(45,106,255,.15); color: #7db3ff;
  padding: 2px 7px; border-radius: 8px; white-space: nowrap;
}
.card-cliente {
  font-size: 12.5px; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  margin-bottom: 3px;
}
.card-produto {
  font-size: 11px; color: var(--text-muted);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  margin-bottom: 9px;
}
.card-meta {
  display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
  margin-bottom: 8px;
}
.card-chip {
  font-size: 10.5px; padding: 2px 8px; border-radius: 6px;
  background: rgba(255,255,255,.06); color: var(--text-muted);
  white-space: nowrap;
}
.card-chip.urgente  { background: rgba(239,68,68,.12); color: var(--red); font-weight: 700; }
.card-chip.proximo  { background: rgba(245,158,11,.12); color: var(--amber); font-weight: 700; }
.card-chip.ok       { background: rgba(0,201,167,.10); color: var(--teal); }
.card-chip.amostra  { background: rgba(139,92,246,.14); color: #c4b5fd; font-weight: 700; letter-spacing: .3px; }

/* Link sutil de amostras */
.filter-amostras {
  font-size: 10.5px; color: var(--text-muted); opacity: .45;
  text-decoration: none; padding: 0 8px; height: 34px;
  display: inline-flex; align-items: center; border-radius: 6px;
  border: 1px solid transparent; transition: opacity .15s, border-color .15s;
  white-space: nowrap;
}
.filter-amostras:hover { opacity: .85; border-color: var(--border); }
.filter-amostras.ativo { opacity: .7; color: #c4b5fd; border-color: rgba(139,92,246,.25); }
.card-qty { font-size: 10.5px; color: var(--text-muted); white-space: nowrap; }

/* Barra de progresso */
.card-prog { margin-top: 6px; }
.prog-bar-bg {
  width: 100%; height: 4px; border-radius: 4px;
  background: rgba(255,255,255,.07); overflow: hidden;
}
.prog-bar-fill { height: 4px; border-radius: 4px; background: var(--teal); transition: width .4s; }
.prog-bar-fill.parcial { background: var(--amber); }
.prog-label {
  font-size: 10px; color: var(--text-muted);
  display: flex; justify-content: space-between; margin-top: 3px;
}

/* Botão de imagem no card */
.card-actions {
  display: flex; justify-content: flex-end; margin-top: 8px; padding-top: 8px;
  border-top: 1px solid rgba(255,255,255,.06);
}
.card-img-btn {
  display: inline-flex; align-items: center; gap: 4px;
  background: none; border: none; cursor: pointer;
  color: var(--text-muted); font-size: 10.5px; padding: 2px 6px;
  border-radius: 6px; transition: background .15s, color .15s;
}
.card-img-btn:hover { background: rgba(255,255,255,.08); color: var(--text-primary); }
.card-img-count {
  font-size: 10px; font-weight: 700;
  background: rgba(45,106,255,.25); color: #7db3ff;
  padding: 0 5px; border-radius: 8px; min-width: 16px; text-align: center;
  display: none;
}
.card-img-count.has-images { display: inline-block; }

/* Modal de imagens */
/* ── Modal Detalhes ── */
.det-modal {
  position: fixed; inset: 0; z-index: 990;
  background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
}
.det-modal-box {
  background: var(--card-bg); border: 1px solid var(--border);
  border-radius: 16px; width: 560px; max-width: 96vw;
  max-height: 90vh; display: flex; flex-direction: column;
  box-shadow: 0 24px 64px rgba(0,0,0,.5);
  overflow: hidden;
}
.det-modal-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 18px 20px 14px; border-bottom: 1px solid var(--border);
  gap: 12px;
}
.det-modal-title { font-size: 15px; font-weight: 700; }
.det-modal-title span { color: #7db3ff; }
.det-modal-close {
  background: none; border: none; color: var(--text-muted);
  font-size: 16px; cursor: pointer; padding: 0 2px; line-height: 1;
}
.det-modal-close:hover { color: var(--text); }
.det-modal-body { padding: 18px 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
.det-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.det-full { grid-column: 1 / -1; }
.det-field {}
.det-label { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px; }
.det-value { font-size: 13px; font-weight: 500; }
.det-status-badge {
  display: inline-block; font-size: 11px; font-weight: 600;
  padding: 2px 10px; border-radius: 20px;
  background: rgba(45,106,255,.18); color: #7db3ff;
  margin-top: 4px;
}
.det-prog-area { display: flex; flex-direction: column; gap: 6px; }
.det-prog-row { display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--text-muted); }
.det-obs-area { display: flex; flex-direction: column; gap: 6px; }
.det-obs-text {
  font-size: 12.5px; color: var(--text);
  background: rgba(255,255,255,.04); border: 1px solid var(--border);
  border-radius: 8px; padding: 10px 12px; white-space: pre-wrap;
}
.det-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding-top: 12px; border-top: 1px solid var(--border);
  font-size: 11px; color: var(--text-muted);
}
.det-img-btn {
  display: flex; align-items: center; gap: 5px;
  background: rgba(45,106,255,.12); border: 1px solid rgba(45,106,255,.3);
  color: #7db3ff; font-size: 11.5px; font-weight: 600;
  border-radius: 8px; padding: 5px 12px; cursor: pointer;
}
.det-img-btn:hover { background: rgba(45,106,255,.22); }
.kanban-card { cursor: pointer; }

.img-modal {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
}
.img-modal-box {
  background: var(--card-bg); border: 1px solid var(--border);
  border-radius: 16px; width: 680px; max-width: 96vw;
  max-height: 90vh; display: flex; flex-direction: column;
  box-shadow: 0 24px 64px rgba(0,0,0,.5);
  overflow: hidden;
}
.img-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px; border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.img-modal-title { font-size: 14px; font-weight: 700; }
.img-modal-subtitle { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.img-modal-close {
  background: none; border: none; cursor: pointer;
  color: var(--text-muted); font-size: 16px; width: 28px; height: 28px;
  border-radius: 6px; display: flex; align-items: center; justify-content: center;
  transition: background .15s, color .15s;
}
.img-modal-close:hover { background: rgba(255,255,255,.08); color: var(--text-primary); }
.img-modal-body { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
.img-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px;
}
.img-thumb {
  position: relative; border-radius: 10px; overflow: hidden;
  background: rgba(255,255,255,.04); border: 1px solid var(--border);
  aspect-ratio: 1; cursor: pointer;
}
.img-thumb img {
  width: 100%; height: 100%; object-fit: cover;
  transition: transform .2s;
}
.img-thumb:hover img { transform: scale(1.04); }
.img-thumb-del {
  position: absolute; top: 5px; right: 5px;
  background: rgba(0,0,0,.65); border: none; border-radius: 6px;
  color: #fff; cursor: pointer; width: 22px; height: 22px;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; opacity: 0; transition: opacity .15s;
}
.img-thumb:hover .img-thumb-del { opacity: 1; }
.img-thumb-del:hover { background: var(--red); }
.img-thumb-name {
  position: absolute; bottom: 0; left: 0; right: 0;
  background: linear-gradient(transparent, rgba(0,0,0,.75));
  color: #fff; font-size: 9px; padding: 12px 6px 4px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.img-empty { text-align: center; color: var(--text-muted); font-size: 12.5px; padding: 24px 0; }
.img-upload-zone {
  border: 2px dashed var(--border); border-radius: 12px;
  padding: 24px; text-align: center; cursor: pointer;
  transition: border-color .15s, background .15s;
  color: var(--text-muted); font-size: 12.5px;
}
.img-upload-zone:hover, .img-upload-zone.dragover {
  border-color: rgba(45,106,255,.5); background: rgba(45,106,255,.05);
  color: var(--text-primary);
}
.img-upload-zone svg { display: block; margin: 0 auto 8px; opacity: .5; }
.img-upload-zone input { display: none; }

/* Lightbox */
.img-lightbox {
  position: fixed; inset: 0; z-index: 2000;
  background: rgba(0,0,0,.92); display: flex; align-items: center; justify-content: center;
}
.img-lightbox img { max-width: 92vw; max-height: 90vh; border-radius: 8px; }
.img-lightbox-close {
  position: absolute; top: 20px; right: 24px;
  background: rgba(255,255,255,.12); border: none; border-radius: 8px;
  color: #fff; font-size: 18px; width: 36px; height: 36px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: background .15s;
}
.img-lightbox-close:hover { background: rgba(255,255,255,.22); }

/* Toast */
.toast {
  position: fixed; bottom: 24px; right: 24px; z-index: 9999;
  background: #1e2f4a; border: 1px solid var(--border);
  border-radius: 10px; padding: 12px 18px;
  font-size: 13px; display: flex; align-items: center; gap: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,.3);
  animation: toastIn .2s ease;
}
.toast.err { border-color: rgba(239,68,68,.35); }
@keyframes toastIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
@media(max-width:768px){
  .filter-row{flex-wrap:wrap;}
  .filter-row input{width:100%;}
  .kanban-board{overflow-x:auto;padding-bottom:12px;}
  .kanban-col{min-width:260px;}
}
@media(max-width:480px){
  .filter-row input,.filter-row select{width:100%;}
  .kanban-col{min-width:220px;}
}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Kanban de Pedidos</h1>
          <p>Visão geral por status · arraste para mover entre etapas</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/pcp/planejamento" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
          Planejamento
        </a>
        <a href="/pcp/programacao" class="btn-teal">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Programação
        </a>
      </div>
    </header>

    <div class="content">

      <!-- Filtros -->
      <form method="GET" class="filter-row">
        <input type="text"
               name="q"
               placeholder="Buscar pedido, cliente ou produto…"
               value="<?= htmlspecialchars($f_busca) ?>">
        <select name="cat" onchange="this.form.submit()">
          <option value="">Todas as categorias</option>
          <?php foreach ($cats as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $f_cat === $c ? 'selected' : '' ?>>
            <?= htmlspecialchars($c) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if ($show_amostras): ?>
        <input type="hidden" name="amostras" value="1">
        <?php endif; ?>
        <button type="submit" class="btn-primary" style="height:34px;">Filtrar</button>
        <?php if ($f_busca || $f_cat): ?>
        <a href="/pcp/kanban<?= $show_amostras ? '?amostras=1' : '' ?>" class="filter-clear">✕ Limpar</a>
        <?php endif; ?>
        <?php
          $baseQs = array_filter(['q' => $f_busca, 'cat' => $f_cat]);
          $urlAmostras = '/pcp/kanban?' . http_build_query(array_merge($baseQs, ['amostras' => '1']));
          $urlSemAmostras = '/pcp/kanban' . ($baseQs ? '?' . http_build_query($baseQs) : '');
        ?>
        <?php if ($show_amostras): ?>
        <a href="<?= htmlspecialchars($urlSemAmostras) ?>" class="filter-amostras ativo" title="Ocultar amostras">amostras ✕</a>
        <?php else: ?>
        <a href="<?= htmlspecialchars($urlAmostras) ?>" class="filter-amostras" title="Exibir amostras (< 20 un)">+ amostras</a>
        <?php endif; ?>
        <span style="font-size:11.5px;color:var(--text-muted);margin-left:auto;">
          <?= count($todos) ?> item(ns)
        </span>
      </form>

      <!-- Board -->
      <div class="kanban-board" id="kanbanBoard">
        <?php foreach ($colunas as $statusNome => $col):
            $items = $col['items'];
            $totalQtd = array_sum(array_column($items, 'iv_qtde'));
            $cor = $col['cor'];
            ?>
        <div class="kanban-col"
             data-status="<?= htmlspecialchars($statusNome) ?>"
             id="col-<?= preg_replace('/[^a-z0-9]/i', '_', $statusNome) ?>">

          <div class="col-header">
            <span class="col-dot" style="background:<?= $cor ?>;box-shadow:0 0 6px <?= $cor ?>55;"></span>
            <span class="col-title"><?= htmlspecialchars($statusNome) ?></span>
            <span class="col-badge"><?= count($items) ?></span>
            <button class="col-wide-btn" title="Ampliar coluna" aria-label="Ampliar">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
            </button>
            <button class="col-toggle" title="Recolher/expandir coluna" aria-label="Recolher/expandir">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
          </div>
          <div class="col-total">
            <?= number_format($totalQtd, 0, ',', '.') ?> unidades
          </div>

          <div class="col-cards" id="cards-<?= preg_replace('/[^a-z0-9]/i', '_', $statusNome) ?>">

            <?php if (empty($items)): ?>
            <div class="col-empty">Nenhum pedido aqui</div>
            <?php else: ?>
            <?php foreach ($items as $item):
                $entDt = $item['ven_entrega'] ? new DateTime($item['ven_entrega']) : null;
                $diff = $entDt ? (int) $hoje->diff($entDt)->format('%r%a') : null;
                if ($diff === null) {
                    $chipCls = 'card-chip';
                } elseif ($diff < 0) {
                    $chipCls = 'card-chip urgente';
                } elseif ($diff < 7) {
                    $chipCls = 'card-chip proximo';
                } else {
                    $chipCls = 'card-chip ok';
                }
                $entFmt = $entDt ? $entDt->format('d/m/Y') : '—';

                $pedNum = trim((string) ($item['ven_codigo_yzidro'] ?? ''));
                $produzido = $prodProgress[$pedNum] ?? 0.0;
                $reqQtd = (float) $item['iv_qtde'];
                $pct = ($reqQtd > 0) ? min(100, round($produzido / $reqQtd * 100)) : 0;
                $barCls = $pct >= 100 ? '' : 'parcial';
                ?>
            <div class="kanban-card"
                 draggable="true"
                 data-id="<?= $item['iv_codigo'] ?>"
                 data-status="<?= htmlspecialchars($item['iv_status']) ?>"
                 data-pedido="<?= htmlspecialchars($pedNum ?: $item['ven_codigo']) ?>"
                 data-cliente="<?= htmlspecialchars($item['cliente']) ?>"
                 data-uf="<?= htmlspecialchars($item['ven_uf'] ?? '') ?>"
                 data-produto="<?= htmlspecialchars($item['pro_descricao']) ?>"
                 data-categoria="<?= htmlspecialchars($item['pro_categoria'] ?? '') ?>"
                 data-tipo="<?= htmlspecialchars($item['pro_tipo'] ?? '') ?>"
                 data-qtde="<?= number_format((float)$item['iv_qtde'], 0, ',', '.') ?>"
                 data-entrega="<?= $entFmt ?>"
                 data-entrega-cls="<?= $chipCls ?>"
                 data-prio="<?= (int)$item['iv_prioridade'] ?>"
                 data-obs="<?= htmlspecialchars($item['iv_obs'] ?? '') ?>"
                 data-rep="<?= htmlspecialchars($item['ven_representante'] ?? '') ?>"
                 data-produzido="<?= number_format($produzido, 0, ',', '.') ?>"
                 data-pct="<?= $pct ?>"
                 data-atualizado="<?= htmlspecialchars($item['iv_atualizado_em'] ?? '') ?>"
                 onclick="if(Date.now()-_lastDragEnd>250)openDetailModal(this)">

              <div class="card-top">
                <span class="card-num"><?= htmlspecialchars($pedNum ?: $item['ven_codigo']) ?></span>
                <?php if ((int) $item['iv_prioridade'] > 0): ?>
                <span class="card-prio">P<?= $item['iv_prioridade'] ?></span>
                <?php endif; ?>
              </div>

              <div class="card-cliente" title="<?= htmlspecialchars($item['cliente']) ?>">
                <?= htmlspecialchars(mb_substr($item['cliente'], 0, 32)) ?>
              </div>
              <div class="card-produto" title="<?= htmlspecialchars($item['pro_descricao']) ?>">
                <?= htmlspecialchars(mb_substr($item['pro_descricao'], 0, 42)) ?>
              </div>

              <div class="card-meta">
                <span class="<?= $chipCls ?>">
                  <?= $diff !== null
                          ? ($diff < 0 ? abs($diff) . 'd atrasado' : ($diff === 0 ? 'Hoje' : 'Entrega ' . $entFmt))
                          : '—' ?>
                </span>
                <?php if ($item['pro_categoria']): ?>
                <span class="card-chip"><?= htmlspecialchars($item['pro_categoria']) ?></span>
                <?php endif; ?>
                <?php if ($show_amostras && $reqQtd < 20): ?>
                <span class="card-chip amostra">Amostra</span>
                <?php endif; ?>
                <span class="card-qty"><?= number_format($reqQtd, 0, ',', '.') ?> un</span>
              </div>

              <?php if ($produzido > 0): ?>
              <div class="card-prog">
                <div class="prog-bar-bg">
                  <div class="prog-bar-fill <?= $barCls ?>" style="width:<?= $pct ?>%;"></div>
                </div>
                <div class="prog-label">
                  <span><?= number_format($produzido, 0, ',', '.') ?> produzido</span>
                  <span><?= $pct ?>%</span>
                </div>
              </div>
              <?php endif; ?>

              <div class="card-actions">
                <button class="card-img-btn" onclick="openImageModal(<?= $item['iv_codigo'] ?>, '<?= htmlspecialchars(addslashes($pedNum ?: $item['ven_codigo'])) ?>', event)">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                  <span class="card-img-count <?= ($item['img_count'] ?? 0) > 0 ? 'has-images' : '' ?>" id="img-count-<?= $item['iv_codigo'] ?>"><?= ($item['img_count'] ?? 0) > 0 ? $item['img_count'] : '' ?></span>
                </button>
              </div>

            </div>
            <?php endforeach; ?>
            <?php endif; ?>

          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div><!-- /content -->
  </div>
</div>

<!-- Modal de Detalhes do Pedido -->
<div class="det-modal" id="detModal" style="display:none;" onclick="if(event.target===this)closeDetailModal()">
  <div class="det-modal-box">
    <div class="det-modal-header">
      <div>
        <div class="det-modal-title">Pedido <span id="detPedNum"></span></div>
        <div class="det-modal-status"><span id="detStatusBadge"></span></div>
      </div>
      <button class="det-modal-close" onclick="closeDetailModal()">✕</button>
    </div>
    <div class="det-modal-body">
      <div class="det-grid">
        <div class="det-field">
          <div class="det-label">Cliente</div>
          <div class="det-value" id="detCliente"></div>
        </div>
        <div class="det-field">
          <div class="det-label">UF</div>
          <div class="det-value" id="detUF"></div>
        </div>
        <div class="det-field det-full">
          <div class="det-label">Produto</div>
          <div class="det-value" id="detProduto"></div>
        </div>
        <div class="det-field">
          <div class="det-label">Categoria</div>
          <div class="det-value" id="detCategoria"></div>
        </div>
        <div class="det-field">
          <div class="det-label">Tipo</div>
          <div class="det-value" id="detTipo"></div>
        </div>
        <div class="det-field">
          <div class="det-label">Quantidade</div>
          <div class="det-value" id="detQtde"></div>
        </div>
        <div class="det-field">
          <div class="det-label">Entrega</div>
          <div class="det-value" id="detEntrega"></div>
        </div>
        <div class="det-field">
          <div class="det-label">Representante</div>
          <div class="det-value" id="detRep"></div>
        </div>
        <div class="det-field">
          <div class="det-label">Prioridade</div>
          <div class="det-value" id="detPrio"></div>
        </div>
      </div>
      <div id="detProgArea" style="display:none;" class="det-prog-area">
        <div class="det-label">Progresso de Produção</div>
        <div class="det-prog-row">
          <div class="prog-bar-bg" style="flex:1;">
            <div class="prog-bar-fill" id="detProgBar" style="width:0%;"></div>
          </div>
          <span id="detProgLabel"></span>
        </div>
      </div>
      <div id="detObsArea" style="display:none;" class="det-obs-area">
        <div class="det-label">Observações</div>
        <div class="det-obs-text" id="detObs"></div>
      </div>
      <div class="det-footer">
        <span id="detAtualizado"></span>
        <button class="det-img-btn" id="detOpenImgBtn">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          Ver imagens
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de Imagens -->
<div class="img-modal" id="imgModal" style="display:none;" onclick="if(event.target===this)closeImageModal()">
  <div class="img-modal-box">
    <div class="img-modal-header">
      <div>
        <div class="img-modal-title">Imagens do Pedido <span id="imgModalPedNum" style="color:#7db3ff;"></span></div>
        <div class="img-modal-subtitle" id="imgModalSubtitle">Carregando…</div>
      </div>
      <button class="img-modal-close" onclick="closeImageModal()">✕</button>
    </div>
    <div class="img-modal-body">
      <div class="img-grid" id="imgGrid"></div>
      <label class="img-upload-zone" id="imgUploadZone">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Clique para selecionar ou arraste imagens aqui<br>
        <small style="font-size:10.5px;opacity:.6;">JPG, PNG, GIF, WEBP · Máx 10 MB cada</small>
        <input type="file" id="imgFileInput" accept="image/*" multiple>
      </label>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="img-lightbox" id="imgLightbox" style="display:none;" onclick="closeLightbox()">
  <button class="img-lightbox-close" onclick="closeLightbox()">✕</button>
  <img id="imgLightboxImg" src="" alt="">
</div>

<!-- Toast -->
<div class="toast" id="toast" style="display:none;"></div>

<script>
const STATUS_LIST = <?= json_encode(array_keys($colunas)) ?>;

// ── Toast ─────────────────────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, err = false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (err ? ' err' : '');
  t.style.display = 'flex';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.style.display = 'none', 3200);
}

// ── Drag & Drop ───────────────────────────────────────────────────────────────
let dragging   = null;   // card element being dragged
let fromStatus = null;
let _lastDragEnd = 0;

document.querySelectorAll('.kanban-card').forEach(addCardDrag);
document.querySelectorAll('.col-cards').forEach(addColDrop);

function addCardDrag(card) {
  card.addEventListener('dragstart', e => {
    dragging   = card;
    fromStatus = card.dataset.status;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
  });
  card.addEventListener('dragend', () => {
    _lastDragEnd = Date.now();
    card.classList.remove('dragging');
    document.querySelectorAll('.col-cards').forEach(c => c.classList.remove('drag-over'));
    document.querySelectorAll('.kanban-card').forEach(c => c.classList.remove('drag-over-card'));
    dragging = null;
  });
  // Reordenação dentro da mesma coluna
  card.addEventListener('dragover', e => {
    e.preventDefault();
    if (!dragging || dragging === card) return;
    const col = card.closest('.col-cards');
    if (!col || col !== dragging.closest('.col-cards')) return;
    card.classList.add('drag-over-card');
    const rect   = card.getBoundingClientRect();
    const before = e.clientY < rect.top + rect.height / 2;
    col.insertBefore(dragging, before ? card : card.nextSibling);
  });
  card.addEventListener('dragleave', () => card.classList.remove('drag-over-card'));
}

function addColDrop(col) {
  col.addEventListener('dragover', e => {
    e.preventDefault();
    col.classList.add('drag-over');
    e.dataTransfer.dropEffect = 'move';
  });
  col.addEventListener('dragleave', e => {
    if (!col.contains(e.relatedTarget)) col.classList.remove('drag-over');
  });
  col.addEventListener('drop', e => {
    e.preventDefault();
    col.classList.remove('drag-over');
    if (!dragging) return;

    const newStatus = col.closest('.kanban-col').dataset.status;
    const id        = parseInt(dragging.dataset.id);

    col.appendChild(dragging);
    dragging.dataset.status = newStatus;

    // Atualiza contadores visualmente
    updateCounters();

    // Persiste via AJAX
    if (newStatus !== fromStatus) {
      fetch(window.location.pathname, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ id, status: newStatus })
      })
      .then(r => r.json())
      .then(data => {
        if (data.ok) showToast('Status atualizado: ' + newStatus);
        else { showToast('Erro: ' + data.msg, true); dragging && restoreCard(dragging, fromStatus); }
      })
      .catch(() => showToast('Erro de conexão.', true));
    }
  });
}

// ── Contadores ────────────────────────────────────────────────────────────────
function updateCounters() {
  document.querySelectorAll('.kanban-col').forEach(col => {
    const cards = col.querySelectorAll('.kanban-card');
    col.querySelector('.col-badge').textContent = cards.length;
    let total = 0;
    cards.forEach(c => {
      const qtyEl = c.querySelector('.card-qty');
      if (qtyEl) {
        const n = parseFloat(qtyEl.textContent.replace(/\./g,'').replace(',','.')) || 0;
        total += n;
      }
    });
    const totalEl = col.querySelector('.col-total');
    if (totalEl) totalEl.textContent = total.toLocaleString('pt-BR',{maximumFractionDigits:0}) + ' unidades';
    // Remove ou adiciona empty state
    const empty = col.querySelector('.col-empty');
    const cardsDiv = col.querySelector('.col-cards');
    if (cards.length === 0 && !empty) {
      const div = document.createElement('div');
      div.className = 'col-empty';
      div.textContent = 'Nenhum pedido aqui';
      cardsDiv.appendChild(div);
    } else if (cards.length > 0 && empty) {
      empty.remove();
    }
  });
}

// ── Colapso e largura de colunas ─────────────────────────────────────────────
const COLLAPSE_KEY = 'kanban_collapsed';
const WIDE_KEY     = 'kanban_wide';
function loadSet(key) {
  try { return new Set(JSON.parse(localStorage.getItem(key) || '[]')); } catch { return new Set(); }
}
function saveSet(key, set) {
  localStorage.setItem(key, JSON.stringify([...set]));
}

const collapsedCols = loadSet(COLLAPSE_KEY);
const wideCols      = loadSet(WIDE_KEY);

document.querySelectorAll('.kanban-col').forEach(col => {
  const st = col.dataset.status;
  if (collapsedCols.has(st)) col.classList.add('collapsed');
  if (wideCols.has(st))      col.classList.add('wide');

  function toggleCol() {
    col.classList.toggle('collapsed');
    if (col.classList.contains('collapsed')) { collapsedCols.add(st); col.classList.remove('wide'); wideCols.delete(st); }
    else collapsedCols.delete(st);
    saveSet(COLLAPSE_KEY, collapsedCols);
    saveSet(WIDE_KEY, wideCols);
  }

  function toggleWide() {
    col.classList.toggle('wide');
    if (col.classList.contains('wide')) wideCols.add(st);
    else wideCols.delete(st);
    saveSet(WIDE_KEY, wideCols);
  }

  col.querySelector('.col-toggle').addEventListener('click', e => { e.stopPropagation(); toggleCol(); });
  col.querySelector('.col-wide-btn').addEventListener('click', e => { e.stopPropagation(); toggleWide(); });

  // Clicar no cabeçalho da coluna recolhida expande
  col.querySelector('.col-header').addEventListener('click', () => {
    if (col.classList.contains('collapsed')) toggleCol();
  });
});

// ── Busca em tempo real ───────────────────────────────────────────────────────
const searchInput = document.querySelector('input[name="q"]');
if (searchInput) {
  searchInput.addEventListener('input', () => {
    const q = searchInput.value.toLowerCase();
    document.querySelectorAll('.kanban-card').forEach(card => {
      const text = card.textContent.toLowerCase();
      card.style.display = text.includes(q) ? '' : 'none';
    });
  });
}

// ── Modal de Imagens ──────────────────────────────────────────────────────────
let currentIvCodigo = null;
const imgModal    = document.getElementById('imgModal');
const imgGrid     = document.getElementById('imgGrid');
const imgSubtitle = document.getElementById('imgModalSubtitle');
const imgPedNum   = document.getElementById('imgModalPedNum');

function openImageModal(ivCodigo, pedNum, evt) {
  evt && evt.stopPropagation();
  evt && evt.preventDefault();
  currentIvCodigo = ivCodigo;
  imgPedNum.textContent = pedNum;
  imgSubtitle.textContent = 'Carregando…';
  imgGrid.innerHTML = '';
  imgModal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  loadImages(ivCodigo);
}

function closeImageModal() {
  imgModal.style.display = 'none';
  document.body.style.overflow = '';
  currentIvCodigo = null;
}

function loadImages(ivCodigo) {
  fetch(`${window.location.pathname}?action=get_images&id=${ivCodigo}`)
    .then(r => r.json())
    .then(data => {
      imgGrid.innerHTML = '';
      if (!data.ok || !data.images.length) {
        imgSubtitle.textContent = 'Nenhuma imagem adicionada';
        return;
      }
      imgSubtitle.textContent = data.images.length + ' imagem(ns)';
      data.images.forEach(img => addThumb(img));
      updateCountBadge(ivCodigo, data.images.length);
    })
    .catch(() => { imgSubtitle.textContent = 'Erro ao carregar imagens.'; });
}

function addThumb(img) {
  const div = document.createElement('div');
  div.className = 'img-thumb';
  div.dataset.imgCodigo = img.img_codigo;
  const url = `/uploads/pedidos/${img.img_arquivo}`;
  div.innerHTML = `
    <img src="${url}" alt="${img.img_nome_orig}" loading="lazy" onclick="openLightbox('${url}')">
    <div class="img-thumb-name">${img.img_nome_orig}</div>
    <button class="img-thumb-del" onclick="deleteImage(${img.img_codigo}, this)" title="Excluir">✕</button>
  `;
  imgGrid.appendChild(div);
}

function deleteImage(imgCodigo, btn) {
  if (!confirm('Excluir esta imagem?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_image');
  fd.append('img_codigo', imgCodigo);
  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        const thumb = btn.closest('.img-thumb');
        thumb.remove();
        const remaining = imgGrid.querySelectorAll('.img-thumb').length;
        imgSubtitle.textContent = remaining ? remaining + ' imagem(ns)' : 'Nenhuma imagem adicionada';
        updateCountBadge(currentIvCodigo, remaining);
        showToast('Imagem excluída.');
      } else {
        showToast('Erro: ' + data.msg, true);
      }
    })
    .catch(() => showToast('Erro de conexão.', true));
}

function updateCountBadge(ivCodigo, count) {
  const el = document.getElementById('img-count-' + ivCodigo);
  if (!el) return;
  el.textContent = count > 0 ? count : '';
  el.classList.toggle('has-images', count > 0);
}

// Upload via input file
document.getElementById('imgFileInput').addEventListener('change', function() {
  [...this.files].forEach(file => uploadFile(file));
  this.value = '';
});

// Drag & drop na zona de upload
const uploadZone = document.getElementById('imgUploadZone');
uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone.addEventListener('drop', e => {
  e.preventDefault();
  uploadZone.classList.remove('dragover');
  if (!currentIvCodigo) return;
  [...e.dataTransfer.files].forEach(file => uploadFile(file));
});

function uploadFile(file) {
  if (!currentIvCodigo) return;
  const fd = new FormData();
  fd.append('action', 'upload_image');
  fd.append('iv_codigo', currentIvCodigo);
  fd.append('imagem', file);

  // Thumb provisório com loader
  const div = document.createElement('div');
  div.className = 'img-thumb';
  div.style.display = 'flex'; div.style.alignItems = 'center'; div.style.justifyContent = 'center';
  div.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.4;animation:spin 1s linear infinite"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
  imgGrid.appendChild(div);

  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      div.remove();
      if (data.ok) {
        addThumb(data);
        const count = imgGrid.querySelectorAll('.img-thumb').length;
        imgSubtitle.textContent = count + ' imagem(ns)';
        updateCountBadge(currentIvCodigo, count);
        showToast('Imagem adicionada!');
      } else {
        showToast('Erro: ' + data.msg, true);
      }
    })
    .catch(() => { div.remove(); showToast('Erro ao enviar imagem.', true); });
}

// Lightbox
function openLightbox(url) {
  document.getElementById('imgLightboxImg').src = url;
  document.getElementById('imgLightbox').style.display = 'flex';
}
function closeLightbox() {
  document.getElementById('imgLightbox').style.display = 'none';
}

// Fechar modal com Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    if (document.getElementById('imgLightbox').style.display !== 'none') { closeLightbox(); return; }
    if (imgModal.style.display !== 'none') { closeImageModal(); return; }
    if (document.getElementById('detModal').style.display !== 'none') { closeDetailModal(); return; }
  }
});

// ── Modal de Detalhes ─────────────────────────────────────────────────────────
let _detIvCodigo = null;
let _detPedNum   = null;

function openDetailModal(card) {
  const d = card.dataset;
  _detIvCodigo = d.id;
  _detPedNum   = d.pedido;

  document.getElementById('detPedNum').textContent    = d.pedido;
  document.getElementById('detCliente').textContent   = d.cliente || '—';
  document.getElementById('detUF').textContent        = d.uf || '—';
  document.getElementById('detProduto').textContent   = d.produto || '—';
  document.getElementById('detCategoria').textContent = d.categoria || '—';
  document.getElementById('detTipo').textContent      = d.tipo || '—';
  document.getElementById('detQtde').textContent      = d.qtde + ' un';
  document.getElementById('detRep').textContent       = d.rep || '—';

  const prio = parseInt(d.prio);
  document.getElementById('detPrio').textContent = prio > 0 ? 'P' + prio : '—';

  const entEl = document.getElementById('detEntrega');
  entEl.textContent = d.entrega;
  entEl.className   = 'det-value ' + (d.entregaCls || d['entrega-cls'] || '');

  const statusBadge = document.getElementById('detStatusBadge');
  statusBadge.textContent = d.status || '—';
  statusBadge.className   = 'det-status-badge';

  const pct = parseInt(d.pct);
  const progArea = document.getElementById('detProgArea');
  if (parseInt(d.produzido.replace(/\./g,'')) > 0) {
    progArea.style.display = '';
    document.getElementById('detProgBar').style.width = pct + '%';
    document.getElementById('detProgBar').className   = 'prog-bar-fill' + (pct >= 100 ? '' : ' parcial');
    document.getElementById('detProgLabel').textContent = d.produzido + ' produzido · ' + pct + '%';
  } else {
    progArea.style.display = 'none';
  }

  const obsArea = document.getElementById('detObsArea');
  const obs = d.obs || '';
  if (obs) {
    obsArea.style.display = '';
    document.getElementById('detObs').textContent = obs;
  } else {
    obsArea.style.display = 'none';
  }

  const atEl = document.getElementById('detAtualizado');
  atEl.textContent = d.atualizado ? 'Atualizado: ' + d.atualizado : '';

  document.getElementById('detOpenImgBtn').onclick = () => {
    closeDetailModal();
    openImageModal(parseInt(_detIvCodigo), _detPedNum, null);
  };

  document.getElementById('detModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeDetailModal() {
  document.getElementById('detModal').style.display = 'none';
  document.body.style.overflow = '';
  _detIvCodigo = null;
}

// Animação do spinner provisório
const spinStyle = document.createElement('style');
spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(spinStyle);
</script>
</body>
</html>
