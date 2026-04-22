<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Planejamento de Produção
//  Pedidos importados do MySQL, separados em 6 categorias de produção.
//  Status por item: Pendente de produção / Produção / Aguardando expedição / Finalizado
//  Permite reordenar manualmente (prioridade) e alterar status via AJAX.
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();

// Status disponíveis
const STATUS_PROD = [
    'Pendente de produção',
    'Produção',
    'Aguardando expedição',
    'Finalizado',
];

// 6 categorias na ordem desejada
const CATEGORIAS = [
    'Bag sem impressão',
    'Bag com impressão',
    'Bag travado sem impressão',
    'Bag travado com impressão',
    'Sacaria sem impressão',
    'Sacaria com impressão',
];

// ── AJAX: atualizar status de item ────────────────────────────────────────────
$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $acao = $body['acao'] ?? '';

        if ($acao === 'status') {
            $id = (int) ($body['id'] ?? 0);
            $status = (string) ($body['status'] ?? '');
            if (!$id || !in_array($status, STATUS_PROD)) {
                echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']);
                exit;
            }
            $pdo->prepare(
                'UPDATE itens_vendas SET iv_status=:s, iv_atualizado_em=NOW() WHERE iv_codigo=:id'
            )->execute([':s' => $status, ':id' => $id]);
            echo json_encode(['ok' => true, 'status' => $status]);

        } elseif ($acao === 'prioridade') {
            // Recebe array de ids na nova ordem para uma categoria
            $ids = array_map('intval', $body['ids'] ?? []);
            $pdo->beginTransaction();
            foreach ($ids as $pos => $id) {
                $pdo->prepare('UPDATE itens_vendas SET iv_prioridade=:p WHERE iv_codigo=:id')
                    ->execute([':p' => $pos + 1, ':id' => $id]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true]);

        } elseif ($acao === 'obs') {
            $id = (int) ($body['id'] ?? 0);
            $obs = trim((string) ($body['obs'] ?? ''));
            if (!$id) {
                echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
                exit;
            }
            $pdo->prepare('UPDATE itens_vendas SET iv_obs=:o, iv_atualizado_em=NOW() WHERE iv_codigo=:id')
                ->execute([':o' => $obs, ':id' => $id]);
            echo json_encode(['ok' => true]);

        } elseif ($acao === 'entrega') {
            $ven_codigo = (int) ($body['ven_codigo'] ?? 0);
            $data = (string) ($body['data'] ?? '');
            if (!$ven_codigo || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                echo json_encode(['ok' => false, 'msg' => 'Dados inválidos.']);
                exit;
            }
            $pdo->prepare('UPDATE vendas SET ven_entrega=:d WHERE ven_codigo=:id')
                ->execute([':d' => $data, ':id' => $ven_codigo]);
            echo json_encode(['ok' => true]);

        } else {
            echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Filtro de status ──────────────────────────────────────────────────────────
// Padrão: 'ativos' = Pendente de produção + Produção
// Passe ?status=<valor> para filtrar por status específico
// Passe ?status=todos para ver todos exceto Finalizado
$f_status      = $_GET['status'] ?? 'ativos';
$f_cat         = $_GET['cat'] ?? '';
$show_amostras = isset($_GET['amostras']);

// ── Busca itens do MySQL com join de vendas + produtos ────────────────────────
$where = 'WHERE 1=1';
$params = [];
if ($f_status === 'ativos') {
    $where .= " AND iv.iv_status IN ('Pendente de produção','Produção')";
} elseif ($f_status === 'todos') {
    $where .= " AND iv.iv_status <> 'Finalizado'";
} elseif ($f_status && in_array($f_status, STATUS_PROD)) {
    $where .= ' AND iv.iv_status = :status';
    $params[':status'] = $f_status;
}
if ($f_cat && in_array($f_cat, CATEGORIAS)) {
    $where .= ' AND p.pro_categoria = :cat';
    $params[':cat'] = $f_cat;
}
if (!$show_amostras) {
    $where .= ' AND iv.iv_qtde >= 20';
}

$stmt = $pdo->prepare("
    SELECT
        iv.iv_codigo,
        iv.iv_qtde,
        iv.iv_vlr_unit,
        iv.iv_total,
        iv.iv_unidade,
        iv.iv_status,
        iv.iv_prioridade,
        iv.iv_obs,
        iv.iv_atualizado_em,
        v.ven_codigo,
        v.ven_codigo_yzidro,
        v.ven_cliente,
        v.ven_fantasia,
        v.ven_entrega,
        v.ven_total,
        v.ven_representante,
        v.ven_uf,
        p.pro_codigo,
        p.pro_codigo_yz,
        p.pro_descricao,
        p.pro_tipo,
        p.pro_travado,
        p.pro_impressao,
        p.pro_categoria
    FROM itens_vendas iv
    INNER JOIN vendas   v ON v.ven_codigo = iv.ven_codigo
    INNER JOIN produtos p ON p.pro_codigo = iv.pro_codigo
    $where
    ORDER BY
        p.pro_categoria,
        CASE WHEN iv.iv_prioridade = 0 THEN 1 ELSE 0 END,
        iv.iv_prioridade,
        v.ven_entrega,
        v.ven_codigo_yzidro
");
$stmt->execute($params);
$todos_itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs por status ───────────────────────────────────────────────────────────
$kpiStmt = $pdo->query(
    'SELECT iv_status, COUNT(*) qtd, SUM(iv_qtde) und FROM itens_vendas GROUP BY iv_status'
);
$kpis = [];
foreach ($kpiStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $kpis[$r['iv_status']] = ['qtd' => (int) $r['qtd'], 'und' => (float) $r['und']];
}

// ── Progresso real por pedido (soma de producao_diaria) ───────────────────────
$prodProgress = [];
try {
    $stmtPp = $pdo->query("
        SELECT pd_pedido, SUM(pd_quantidade) AS produzido
        FROM producao_diaria
        WHERE pd_pedido IS NOT NULL AND TRIM(pd_pedido) <> ''
        GROUP BY pd_pedido
    ");
    foreach ($stmtPp->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $prodProgress[trim($r['pd_pedido'])] = (float) $r['produzido'];
    }
} catch (Throwable $e) { /* tabela ainda não existe */
}

// ── Agrupa por categoria ──────────────────────────────────────────────────────
$board = [];
foreach (CATEGORIAS as $cat) {
    $board[$cat] = [];
}
foreach ($todos_itens as $item) {
    $cat = $item['pro_categoria'] ?: 'Não classificado';
    $board[$cat][] = $item;
}
// Remove categorias vazias para não poluir
$board = array_filter($board, fn ($items) => !empty($items));

$js_board = json_encode($board, JSON_UNESCAPED_UNICODE);
$js_status = json_encode(STATUS_PROD);
$js_cats = json_encode(CATEGORIAS);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Planejamento de Produção | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.content{overflow-y:auto;overflow-x:hidden;}
/* ─── Filtros ──────────────────────────────────────────────── */
.filter-bar{display:flex;gap:10px;align-items:flex-end;padding:14px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap;}

/* ─── Board de categorias ──────────────────────────────────── */
.cat-board{display:flex;flex-direction:column;gap:20px;}

/* Seção de categoria */
.cat-section{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.cat-header{padding:12px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;}
.cat-title{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;}
.cat-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.cat-meta{font-size:11.5px;color:var(--text-muted);}

/* Tabela dentro da categoria */
.cat-table{width:100%;border-collapse:collapse;}
.cat-table th{padding:8px 14px;font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);background:#112240;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:2;}
.cat-table td{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--border);vertical-align:middle;}
.cat-table tr:last-child td{border-bottom:none;}
.cat-table tbody tr{transition:background .1s;}
.cat-table tbody tr:hover td{background:rgba(255,255,255,.025);}

/* Drag handle */
.drag-handle{cursor:grab;color:var(--text-muted);padding:0 4px;display:inline-flex;align-items:center;}
.drag-handle:active{cursor:grabbing;}
.cat-table tbody tr.dragging{opacity:.4;}
.cat-table tbody tr.drag-over{border-top:2px solid var(--blue-accent);}

/* Status select inline */
.status-sel{height:30px;padding:0 8px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;color:var(--text-primary);font-family:inherit;font-size:12px;cursor:pointer;outline:none;min-width:180px;transition:border-color .15s;}
.status-sel:focus{border-color:rgba(45,106,255,.5);}
.status-sel.saving{opacity:.6;}

/* Status pills */
.sp{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;}
.sp::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;}
.sp-pend{background:rgba(245,158,11,.1);color:var(--amber);}
.sp-prod{background:rgba(45,106,255,.1);color:#7db3ff;}
.sp-exp {background:rgba(167,139,250,.1);color:var(--purple);}
.sp-fin {background:rgba(0,201,167,.1);color:var(--teal);}

/* Cores por categoria */
.c-bsi  {background:#2d6aff;}
.c-bci  {background:#a78bfa;}
.c-btsi {background:#38bdf8;}
.c-btci {background:#fbbf24;}
.c-ssi  {background:#00c9a7;}
.c-sci  {background:#ef4444;}
.c-nc   {background:#7a90b0;}

/* Obs popup */
.obs-btn{background:transparent;border:none;cursor:pointer;color:var(--text-muted);padding:2px 4px;border-radius:4px;display:inline-flex;align-items:center;}
.obs-btn:hover{background:rgba(255,255,255,.06);color:var(--text-primary);}
.obs-input{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;padding:4px 8px;color:var(--text-primary);font-family:inherit;font-size:12px;outline:none;min-height:32px;resize:none;}
.obs-input:focus{border-color:rgba(45,106,255,.5);}

/* Prioridade badge */
.prio-num{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:6px;background:rgba(45,106,255,.1);color:#7db3ff;font-size:11px;font-weight:800;}

/* Tipo Amostra */
.tipo-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:6px;font-size:10.5px;font-weight:700;white-space:nowrap;}
.tipo-amostra{background:rgba(139,92,246,.14);color:#c4b5fd;}
/* Link sutil de amostras */
.filter-amostras{font-size:10.5px;color:var(--text-muted);opacity:.45;text-decoration:none;padding:0 8px;height:36px;display:inline-flex;align-items:center;border-radius:6px;border:1px solid transparent;transition:opacity .15s,border-color .15s;white-space:nowrap;}
.filter-amostras:hover{opacity:.85;border-color:var(--border);}
.filter-amostras.ativo{opacity:.7;color:#c4b5fd;border-color:rgba(139,92,246,.25);}

/* Entrega urgente */
.td-urgente{color:var(--red);font-weight:700;}
.td-proximo{color:var(--amber);font-weight:600;}

/* Barra de progresso de produção */
.prog-bar-wrap{width:90px;background:rgba(255,255,255,.07);border-radius:4px;height:6px;overflow:hidden;}
.prog-bar-fill{height:6px;border-radius:4px;background:var(--teal);transition:width .3s;}
.prog-bar-fill.risco{background:var(--amber);}
.prog-bar-fill.ok{background:var(--teal);}
.prog-pct{font-size:10px;color:var(--text-muted);white-space:nowrap;}

/* Editor inline de entrega */
.td-entrega{white-space:nowrap;}
.td-entrega .obs-btn{opacity:0;transition:opacity .15s;}
.td-entrega:hover .obs-btn{opacity:1;}
.entrega-input{height:28px;padding:0 6px;background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:6px;color:var(--text-primary);font-family:inherit;font-size:12px;outline:none;width:130px;transition:border-color .15s;}
.entrega-input:focus{border-color:rgba(45,106,255,.5);}
@media(max-width:900px){.filter-bar{flex-direction:column;align-items:stretch;}.status-sel{min-width:0;width:100%;}.entrega-input{width:100%;}}
@media(max-width:640px){.prog-bar-wrap{width:60px;}}
.cat-section{min-width:0;}
.cat-section .cat-table-wrap{-webkit-overflow-scrolling:touch;}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Reunião de Planejamento</h1>
          <p>Pedidos em produção · marque no kanban antes da reunião</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/yzidro/pedidos" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Importar Pedidos
        </a>
        <a href="/pcp/fila" class="btn-teal">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3v18h18"/><path d="M7 13v5"/><path d="M12 9v9"/><path d="M17 5v13"/></svg>
          Fila de Máquinas
        </a>
      </div>
    </header>

    <div class="content">

      <!-- KPIs -->
      <div class="kpi-strip" style="grid-template-columns:repeat(4,minmax(0,1fr));">
        <?php
        $kpiDefs = [
            ['k' => 'Pendente de produção', 'l' => 'Pendente',           'c' => 'c-amber'],
            ['k' => 'Produção',             'l' => 'Em Produção',        'c' => 'c-blue'],
            ['k' => 'Aguardando expedição', 'l' => 'Ag. Expedição',      'c' => 'c-amber'],
            ['k' => 'Finalizado',           'l' => 'Finalizados',        'c' => 'c-teal'],
        ];
foreach ($kpiDefs as $kd):
    $v = $kpis[$kd['k']] ?? ['qtd' => 0, 'und' => 0]; ?>
        <div class="kpi-mini <?= $kd['c'] ?>" style="flex-direction:column;align-items:flex-start;gap:4px;">
          <div class="kpi-mini-val"><?= $v['qtd'] ?></div>
          <div class="kpi-mini-lbl"><?= $kd['l'] ?> · <?= number_format($v['und'], 0, ',', '.') ?> un</div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Filtros -->
      <div class="panel" style="margin-bottom:20px;">
        <form method="GET">
          <div class="filter-bar">
            <div class="field">
              <label>Filtrar por Status</label>
              <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="ativos" <?= $f_status === 'ativos' ? 'selected' : '' ?>>Pendente + Em Produção</option>
                <?php foreach (STATUS_PROD as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
                <option value="todos" <?= $f_status === 'todos' ? 'selected' : '' ?>>Todos (exceto Finalizados)</option>
              </select>
            </div>
            <div class="field">
              <label>Filtrar por Categoria</label>
              <select name="cat" class="filter-select" onchange="this.form.submit()">
                <option value="">Todas</option>
                <?php foreach (CATEGORIAS as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $f_cat === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if ($show_amostras): ?>
            <input type="hidden" name="amostras" value="1">
            <?php endif; ?>
            <button type="submit" class="btn-secondary" style="height:36px;">Filtrar</button>
            <a href="/pcp/planejamento" class="btn-secondary" style="height:36px;">✕</a>
            <?php
              $baseQs = array_filter(['status' => $f_status, 'cat' => $f_cat]);
              $urlAmostras    = '/pcp/planejamento?' . http_build_query(array_merge($baseQs, ['amostras' => '1']));
              $urlSemAmostras = '/pcp/planejamento' . ($baseQs ? '?' . http_build_query($baseQs) : '');
            ?>
            <?php if ($show_amostras): ?>
            <a href="<?= htmlspecialchars($urlSemAmostras) ?>" class="filter-amostras ativo" title="Ocultar amostras">amostras ✕</a>
            <?php else: ?>
            <a href="<?= htmlspecialchars($urlAmostras) ?>" class="filter-amostras" title="Exibir amostras (< 20 un)">+ amostras</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Board por categoria -->
      <div class="cat-board" id="catBoard">
        <?php if (empty($board)): ?>
        <div class="empty-state">
          <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
          <p><?php if ($f_status === 'ativos'): ?>Nenhum pedido com status <strong>Pendente de produção</strong> ou <strong>Em Produção</strong>.<?php else: ?>Nenhum item encontrado com os filtros selecionados.<?php endif; ?></p>
        </div>
        <?php else: ?>
        <?php
        $catColors = [
    'Bag sem impressão' => 'c-bsi',
    'Bag com impressão' => 'c-bci',
    'Bag travado sem impressão' => 'c-btsi',
    'Bag travado com impressão' => 'c-btci',
    'Sacaria sem impressão' => 'c-ssi',
    'Sacaria com impressão' => 'c-sci',
        ];
            foreach ($board as $cat => $itens):
                $dotClass = $catColors[$cat] ?? 'c-nc';
                $totalQtd = array_sum(array_column($itens, 'iv_qtde'));
                ?>
        <div class="cat-section">
          <div class="cat-header">
            <div class="cat-title">
              <span class="cat-dot <?= $dotClass ?>"></span>
              <?= htmlspecialchars($cat) ?>
              <span class="count-badge"><?= count($itens) ?> item(ns)</span>
            </div>
            <div class="cat-meta">
              Total: <?= number_format($totalQtd, 0, ',', '.') ?> un
            </div>
          </div>
          <div class="cat-table-wrap table-wrap">
            <table class="cat-table">
              <thead>
                <tr>
                  <th style="width:28px;"></th>
                  <th style="width:28px;">#</th>
                  <th>Pedido</th>
                  <th>Cliente</th>
                  <th>Produto</th>
                  <th class="td-right">Qtd</th>
                  <th>Tipo</th>
                  <th>Progresso</th>
                  <th>Entrega</th>
                  <th>Status</th>
                  <th>Obs. Produção</th>
                </tr>
              </thead>
              <tbody id="tbody-<?= preg_replace('/[^a-z0-9]/i', '_', $cat) ?>"
                     data-cat="<?= htmlspecialchars($cat) ?>">
              <?php foreach ($itens as $idx => $item):
                  $entDt = $item['ven_entrega'] ? new DateTime($item['ven_entrega']) : null;
                  $hoje = new DateTime();
                  $tdCls = '';
                  if ($entDt) {
                      $diff = (int) $hoje->diff($entDt)->format('%r%a');
                      if ($diff < 0) {
                          $tdCls = 'td-urgente';
                      } elseif ($diff < 7) {
                          $tdCls = 'td-proximo';
                      }
                  }
                  $entFmt = $entDt ? $entDt->format('d/m/Y') : '—';

                  $spClass = match($item['iv_status']) {
                      'Produção' => 'sp-prod',
                      'Aguardando expedição' => 'sp-exp',
                      'Finalizado' => 'sp-fin',
                      default => 'sp-pend',
                  };
                  ?>
              <tr draggable="true"
                  data-id="<?= $item['iv_codigo'] ?>"
                  data-cat="<?= htmlspecialchars($cat) ?>">
                <td>
                  <span class="drag-handle" title="Arrastar para reordenar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="9"  cy="5"  r=".7" fill="currentColor"/>
                      <circle cx="9"  cy="12" r=".7" fill="currentColor"/>
                      <circle cx="9"  cy="19" r=".7" fill="currentColor"/>
                      <circle cx="15" cy="5"  r=".7" fill="currentColor"/>
                      <circle cx="15" cy="12" r=".7" fill="currentColor"/>
                      <circle cx="15" cy="19" r=".7" fill="currentColor"/>
                    </svg>
                  </span>
                </td>
                <td><span class="prio-num"><?= $idx + 1 ?></span></td>
                <td>
                  <strong style="color:#7db3ff;"><?= htmlspecialchars($item['ven_codigo_yzidro']) ?></strong>
                </td>
                <td>
                  <div style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                       title="<?= htmlspecialchars($item['ven_fantasia'] ?: $item['ven_cliente']) ?>">
                    <?= htmlspecialchars(mb_substr($item['ven_fantasia'] ?: $item['ven_cliente'], 0, 30)) ?>
                  </div>
                  <div class="td-muted" style="font-size:11px;"><?= htmlspecialchars($item['ven_uf']) ?></div>
                </td>
                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= htmlspecialchars($item['pro_descricao']) ?>">
                  <?= htmlspecialchars(mb_substr($item['pro_descricao'], 0, 40)) ?>
                </td>
                <td class="td-right" style="font-weight:700;">
                  <?= number_format((float) $item['iv_qtde'], 0, ',', '.') ?>
                  <span class="td-muted" style="font-size:11px;"><?= $item['iv_unidade'] ?></span>
                </td>
                <td>
                  <?php if ($show_amostras && (float) $item['iv_qtde'] < 20): ?>
                  <span class="tipo-badge tipo-amostra">Amostra</span>
                  <?php endif; ?>
                </td>
                <?php
                      $pedNum = trim((string) ($item['ven_codigo_yzidro'] ?? ''));
                  $produzido = $prodProgress[$pedNum] ?? 0.0;
                  $reqQtd = (float) $item['iv_qtde'];
                  $pct = ($reqQtd > 0) ? min(100, round($produzido / $reqQtd * 100)) : 0;
                  $barCls = $pct >= 100 ? 'ok' : ($pct >= 50 ? 'ok' : 'risco');
                  ?>
                <td style="min-width:110px;">
                  <?php if ($produzido > 0): ?>
                  <div style="display:flex;flex-direction:column;gap:3px;">
                    <div class="prog-bar-wrap">
                      <div class="prog-bar-fill <?= $barCls ?>" style="width:<?= $pct ?>%;"></div>
                    </div>
                    <span class="prog-pct"><?= number_format($produzido, 0, ',', '.') ?> / <?= number_format($reqQtd, 0, ',', '.') ?> (<?= $pct ?>%)</span>
                  </div>
                  <?php else: ?>
                  <span class="prog-pct">—</span>
                  <?php endif; ?>
                </td>
                <td class="<?= $tdCls ?> td-nowrap td-entrega">
                  <span class="entrega-display"><?= $entFmt ?></span>
                  <button class="obs-btn" title="Editar data de entrega"
                          onclick="editarEntrega(this, <?= $item['ven_codigo'] ?>, '<?= $item['ven_entrega'] ?? '' ?>')">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                </td>
                <td>
                  <select class="status-sel"
                          data-id="<?= $item['iv_codigo'] ?>"
                          onchange="salvarStatus(this)">
                    <?php foreach (STATUS_PROD as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>"
                            <?= $item['iv_status'] === $s ? 'selected' : '' ?>>
                      <?= $s ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <div style="display:flex;align-items:center;gap:4px;">
                    <?php if ($item['iv_obs']): ?>
                    <span style="font-size:11.5px;color:var(--text-muted);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                          title="<?= htmlspecialchars($item['iv_obs']) ?>">
                      <?= htmlspecialchars(mb_substr($item['iv_obs'], 0, 25)) ?>
                    </span>
                    <?php endif; ?>
                    <button class="obs-btn" title="Editar observação"
                            onclick="editarObs(this, <?= $item['iv_codigo'] ?>, <?= htmlspecialchars(json_encode($item['iv_obs'] ?? ''), ENT_QUOTES) ?>)">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div><!-- /content -->
  </div>
</div>

<script>
// ── Salvar status via AJAX ────────────────────────────────────────────────────
async function salvarStatus(sel) {
  const id     = sel.dataset.id;
  const status = sel.value;
  sel.classList.add('saving');
  try {
    const res  = await fetch(window.location.href, {
      method:  'POST',
      headers: { 'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest' },
      body:    JSON.stringify({ acao:'status', id, status }),
    });
    const data = await res.json();
    if (!data.ok) { alert('Erro: '+data.msg); sel.value = sel.dataset.prev || sel.value; }
    else sel.dataset.prev = status;
  } catch(e) { alert('Erro na requisição.'); }
  sel.classList.remove('saving');
}

// ── Editar observação ─────────────────────────────────────────────────────────
function editarObs(btn, id, obsAtual) {
  const td   = btn.closest('td');
  if (td.querySelector('input,textarea')) return;
  const prev = td.innerHTML;
  td.innerHTML = `
    <textarea class="obs-input" id="obs-input-${id}">${obsAtual || ''}</textarea>
    <div style="display:flex;gap:4px;margin-top:4px;">
      <button onclick="salvarObs(${id})" class="btn-primary" style="height:26px;padding:0 10px;font-size:11px;">OK</button>
      <button onclick="this.closest('td').innerHTML='${prev.replace(/'/g,"&#39;").replace(/"/g,'&quot;')}'" class="btn-secondary" style="height:26px;padding:0 10px;font-size:11px;">✕</button>
    </div>
  `;
  document.getElementById('obs-input-'+id)?.focus();
}

async function salvarObs(id) {
  const obs = document.getElementById('obs-input-'+id)?.value ?? '';
  await fetch(window.location.href, {
    method:  'POST',
    headers: { 'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest' },
    body:    JSON.stringify({ acao:'obs', id, obs }),
  });
  location.reload();
}

// ── Editar data de entrega ────────────────────────────────────────────────────
function editarEntrega(btn, venCodigo, dataAtual) {
  const td   = btn.closest('td');
  if (td.querySelector('input,textarea')) return;
  const prev = td.innerHTML;
  td.innerHTML = `
    <input type="date" class="entrega-input" id="ent-input-${venCodigo}" value="${dataAtual}">
    <div style="display:flex;gap:4px;margin-top:4px;">
      <button onclick="salvarEntrega(${venCodigo})" class="btn-primary" style="height:26px;padding:0 10px;font-size:11px;">OK</button>
      <button onclick="this.closest('td').innerHTML='${prev.replace(/'/g,"&#39;").replace(/"/g,'&quot;').replace(/\n/g,' ')}'" class="btn-secondary" style="height:26px;padding:0 10px;font-size:11px;">✕</button>
    </div>
  `;
  document.getElementById('ent-input-'+venCodigo)?.focus();
}

async function salvarEntrega(venCodigo) {
  const input = document.getElementById('ent-input-'+venCodigo);
  const data  = input?.value ?? '';
  if (!data) { alert('Selecione uma data.'); return; }
  const res  = await fetch(window.location.href, {
    method:  'POST',
    headers: { 'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest' },
    body:    JSON.stringify({ acao:'entrega', ven_codigo:venCodigo, data }),
  });
  const json = await res.json();
  if (!json.ok) { alert('Erro: '+json.msg); return; }
  location.reload();
}

// ── Drag & Drop para reordenar ────────────────────────────────────────────────
let dragRow = null;
let dragCat = null;

document.querySelectorAll('.cat-table tbody').forEach(tbody => {
  tbody.addEventListener('dragstart', e => {
    dragRow = e.target.closest('tr');
    dragCat = dragRow?.dataset.cat;
    if (dragRow) { dragRow.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; }
  });

  tbody.addEventListener('dragend', e => {
    dragRow?.classList.remove('dragging');
    document.querySelectorAll('.drag-over').forEach(r => r.classList.remove('drag-over'));
    dragRow = null;
    dragCat = null;
  });

  tbody.addEventListener('dragover', e => {
    e.preventDefault();
    if (!dragRow) return;
    const target = e.target.closest('tr');
    if (!target || target === dragRow || target.dataset.cat !== dragCat) return;
    document.querySelectorAll('.drag-over').forEach(r => r.classList.remove('drag-over'));
    target.classList.add('drag-over');
    e.dataTransfer.dropEffect = 'move';
  });

  tbody.addEventListener('drop', e => {
    e.preventDefault();
    if (!dragRow) return;
    const target = e.target.closest('tr');
    if (!target || target === dragRow || target.dataset.cat !== dragCat) return;
    target.classList.remove('drag-over');

    // Insere antes ou depois dependendo da posição do mouse
    const rect  = target.getBoundingClientRect();
    const after = e.clientY > rect.top + rect.height/2;
    if (after) target.after(dragRow); else target.before(dragRow);

    // Renumera
    renumerarTbody(target.closest('tbody'));

    // Salva nova ordem
    const ids = [...target.closest('tbody').querySelectorAll('tr')].map(r => r.dataset.id);
    salvarPrioridade(ids);
  });
});

function renumerarTbody(tbody) {
  tbody.querySelectorAll('tr').forEach((tr, i) => {
    const badge = tr.querySelector('.prio-num');
    if (badge) badge.textContent = i+1;
  });
}

async function salvarPrioridade(ids) {
  await fetch(window.location.href, {
    method:  'POST',
    headers: { 'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest' },
    body:    JSON.stringify({ acao:'prioridade', ids }),
  });
}
</script>
</body>
</html>