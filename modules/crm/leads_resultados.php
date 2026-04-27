<?php
declare(strict_types=1);
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/leads_ia_lib.php';

// ── Execução dos presets (?exec=1) ────────────────────────────────────────────
if (isset($_GET['exec'])) {
    set_time_limit(600);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo        = dbPDO();
        $clientesYz = iaCarregarClientesYzidro();
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Erro de conexão: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $presets  = LEADS_PRESETS;
    $executar = array_keys($presets);

    if (!empty($_GET['presets'])) {
        $solicitados = array_map('trim', explode(',', (string) $_GET['presets']));
        $executar    = array_values(array_intersect($executar, $solicitados));
    }

    $resultados     = [];
    $totalNovos     = 0;
    $totalFiltrados = 0;
    $erros          = 0;

    foreach ($executar as $key) {
        $res = iaRunPreset($key, $pdo, $clientesYz);
        $resultados[] = [
            'preset'    => $key,
            'titulo'    => $res['titulo'] ?? $key,
            'ok'        => $res['ok'],
            'novos'     => $res['novos'] ?? 0,
            'filtrados' => $res['filtrados'] ?? 0,
            'message'   => $res['message'] ?? '',
        ];
        if ($res['ok']) {
            $totalNovos     += $res['novos'] ?? 0;
            $totalFiltrados += $res['filtrados'] ?? 0;
        } else {
            $erros++;
        }
    }

    echo json_encode([
        'ok'              => true,
        'executado_em'    => date('Y-m-d H:i:s'),
        'presets_rodados' => count($executar),
        'total_novos'     => $totalNovos,
        'total_filtrados' => $totalFiltrados,
        'erros'           => $erros,
        'detalhes'        => $resultados,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Ação AJAX ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = dbPDO();
        iaProspEnsureSchema($pdo);

        $action = $_POST['action'];

        // Ações unitárias (id simples)
        if ($action === 'delete' && !empty($_POST['id'])) {
            $pdo->prepare('DELETE FROM LEADS_PROSPECTADOS WHERE id = ?')->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true]);

        } elseif ($action === 'status' && !empty($_POST['id']) && !empty($_POST['status'])) {
            $allowed = ['NOVO','EM_CONTATO','QUALIFICADO','DESCARTADO'];
            $status  = in_array($_POST['status'], $allowed, true) ? $_POST['status'] : 'NOVO';
            $pdo->prepare('UPDATE LEADS_PROSPECTADOS SET status_prosp = ? WHERE id = ?')
                ->execute([$status, (int)$_POST['id']]);
            echo json_encode(['ok' => true]);

        // Ações em lote (ids[])
        } elseif ($action === 'lote_usuario' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
            if (!iaIsAdmin($pdo)) { echo json_encode(['ok'=>false,'message'=>'Sem permissão.']); exit; }
            $ids   = array_map('intval', $_POST['ids']);
            $usuId = (isset($_POST['usuario_id']) && $_POST['usuario_id'] !== '') ? (int)$_POST['usuario_id'] : null;
            $ph    = implode(',', array_fill(0, count($ids), '?'));
            $params = $usuId !== null ? array_merge([$usuId], $ids) : $ids;
            $pdo->prepare("UPDATE LEADS_PROSPECTADOS SET usuario_id = " . ($usuId !== null ? '?' : 'NULL') . " WHERE id IN ($ph)")
                ->execute($params);
            echo json_encode(['ok' => true, 'atualizados' => count($ids)]);

        } elseif ($action === 'lote_status' && !empty($_POST['ids']) && is_array($_POST['ids']) && !empty($_POST['status'])) {
            $allowed = ['NOVO','EM_CONTATO','QUALIFICADO','DESCARTADO'];
            $status  = in_array($_POST['status'], $allowed, true) ? $_POST['status'] : 'NOVO';
            $ids     = array_map('intval', $_POST['ids']);
            $ph      = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE LEADS_PROSPECTADOS SET status_prosp = ? WHERE id IN ($ph)")
                ->execute(array_merge([$status], $ids));
            echo json_encode(['ok' => true, 'atualizados' => count($ids)]);

        } elseif ($action === 'lote_delete' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
            $ids = array_map('intval', $_POST['ids']);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM LEADS_PROSPECTADOS WHERE id IN ($ph)")->execute($ids);
            echo json_encode(['ok' => true, 'removidos' => count($ids)]);

        } else {
            echo json_encode(['ok' => false]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Export CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $pdo = dbPDO();
        iaProspEnsureSchema($pdo);
        $rows = $pdo->query('SELECT nome_empresa,cnpj,site,telefone,email,cidade,uf,segmento,segmento_busca,score,status_prosp,motivo_relevancia,gravado_em FROM LEADS_PROSPECTADOS ORDER BY gravado_em DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) { $rows = []; }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_prospectados_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $r) fputcsv($out, $r, ';');
    }
    fclose($out);
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$ufList  = UF_LIST;

$filtUf       = trim((string) ($_GET['uf'] ?? ''));
$filtSegmento = trim((string) ($_GET['segmento'] ?? ''));
$filtStatus   = trim((string) ($_GET['status'] ?? ''));
$filtScore    = max(0, (int) ($_GET['score_min'] ?? 0));
$filtBusca    = trim((string) ($_GET['q'] ?? ''));
$filtUsuario  = ($_GET['usuario'] ?? '') === '' ? '' : (string)(int)$_GET['usuario'];

// ── Query ─────────────────────────────────────────────────────────────────────
$rows      = [];
$total     = 0;
$totalPags = 1;
$erroDb    = '';
$stats     = [];
$isAdmin   = false;
$usuarios  = [];

try {
    $pdo = dbPDO();
    iaProspEnsureSchema($pdo);

    $isAdmin  = iaIsAdmin($pdo);
    $usuarios = iaGetUsuarios($pdo);

    $where  = ['1=1'];
    $params = [];

    if (!$isAdmin) {
        $where[]  = '(usuario_id IS NULL OR usuario_id = ?)';
        $params[] = usuCodigo();
    } elseif ($filtUsuario !== '') {
        if ($filtUsuario === '0') {
            $where[] = 'usuario_id IS NULL';
        } else {
            $where[] = 'usuario_id = ?'; $params[] = (int)$filtUsuario;
        }
    }

    if ($filtUf !== '' && isset($ufList[$filtUf])) {
        $where[] = 'uf = ?'; $params[] = $filtUf;
    }
    if ($filtSegmento !== '') {
        $where[] = 'segmento_busca = ?'; $params[] = $filtSegmento;
    }
    if ($filtStatus !== '') {
        $where[] = 'status_prosp = ?'; $params[] = $filtStatus;
    }
    if ($filtScore > 0) {
        $where[] = 'score >= ?'; $params[] = $filtScore;
    }
    if ($filtBusca !== '') {
        $where[] = '(nome_empresa LIKE ? OR cidade LIKE ? OR email LIKE ? OR telefone LIKE ?)';
        $like = '%' . $filtBusca . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);

    $stmtCt = $pdo->prepare("SELECT COUNT(*) FROM LEADS_PROSPECTADOS WHERE $whereSql");
    $stmtCt->execute($params);
    $total = (int) $stmtCt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM LEADS_PROSPECTADOS WHERE $whereSql ORDER BY gravado_em DESC, id DESC");
    $stmt->execute($params);
    $rows = array_map(
        static fn($r) => array_change_key_case($r, CASE_LOWER),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    $statsRows = $pdo->query('SELECT segmento_busca, COUNT(*) qtd FROM LEADS_PROSPECTADOS GROUP BY segmento_busca ORDER BY qtd DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statsRows as $s) {
        $s = array_change_key_case($s, CASE_LOWER);
        $stats[$s['segmento_busca']] = (int)$s['qtd'];
    }

} catch (Throwable $e) {
    $erroDb = $e->getMessage();
}

$segmentosDisponiveis = array_keys($stats);

// Mapa rápido codigo→nome
$usuMap = [];
foreach ($usuarios as $u) $usuMap[$u['codigo']] = $u['nome'];

function rUrl(array $extra = [], array $remove = []): string
{
    $params = $_GET;
    foreach ($remove as $k) unset($params[$k]);
    foreach ($extra as $k => $v) $params[$k] = $v;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prospecção com IA — Resultados | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:20px}
/* Painel de pesquisa */
.search-panel{margin-bottom:20px}
.search-toggle{display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none}
.search-toggle-chevron{transition:transform .2s;color:var(--text-muted)}
.search-panel.open .search-toggle-chevron{transform:rotate(180deg)}
.search-body{display:none;padding:20px}
.search-panel.open .search-body{display:block}
.preset-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:18px}
.preset-card{background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:6px}
.preset-title{font-size:12px;font-weight:800;color:var(--text-primary)}
.preset-desc{font-size:10.5px;line-height:1.4;color:var(--text-secondary);flex:1}
.preset-meta{display:flex;flex-wrap:wrap;gap:4px}
.preset-pill{font-size:9.5px;padding:2px 7px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text-muted)}
.run-row{display:flex;align-items:center;gap:12px}
.log-box{background:rgba(0,0,0,.3);border:1px solid var(--border);border-radius:8px;padding:14px;font-family:ui-monospace,monospace;font-size:11.5px;line-height:1.7;color:var(--text-muted);max-height:260px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;margin-top:14px;display:none}
@media(max-width:1100px){.preset-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.preset-grid{grid-template-columns:repeat(2,1fr)}}
.filter-bar .field{min-width:140px;max-width:220px}
.filter-bar select,.filter-bar input{height:36px;font-size:12px}
.filter-bar .field label{font-size:11px;margin-bottom:4px;display:block;color:var(--text-muted)}
.stats-row{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.stat-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;background:rgba(255,255,255,.03);border:1px solid var(--border);font-size:12px;color:var(--text-secondary)}
.stat-chip strong{color:var(--text-primary)}
.leads-table-wrap{}
.leads-table{width:100%;border-collapse:collapse}
.leads-table th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);background:#112240;white-space:nowrap;position:sticky;top:0;z-index:2;}
.leads-table td{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);vertical-align:middle}
.leads-table tbody tr:hover td{background:rgba(255,255,255,.025)}
.leads-table tbody tr.selected td{background:rgba(45,106,255,.07)}
.cb-th{width:36px;padding-left:14px!important}
.cb-td{padding-left:14px!important}
.score-badge{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;font-size:13px;font-weight:800}
.score-high{background:rgba(0,201,167,.12);color:var(--teal)}.score-mid{background:rgba(245,158,11,.12);color:var(--amber)}.score-low{background:rgba(239,68,68,.1);color:var(--red)}
.ext-link{color:#7db3ff;text-decoration:none;font-size:12px;word-break:break-all}.ext-link:hover{text-decoration:underline}
.status-sel{font-size:11px;padding:4px 8px;border-radius:6px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--text-secondary);cursor:pointer}
.btn-del{width:28px;height:28px;border-radius:7px;border:1px solid rgba(239,68,68,.25);background:rgba(239,68,68,.06);color:var(--red);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:13px}
.btn-del:hover{background:rgba(239,68,68,.15)}
.resp-tag{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;background:rgba(45,106,255,.1);border:1px solid rgba(45,106,255,.25);color:#7db3ff;white-space:nowrap}
/* Barra de lote */
.bulk-bar{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);z-index:200;display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:14px;background:var(--surface,#1a1d27);border:1px solid rgba(45,106,255,.4);box-shadow:0 8px 32px rgba(0,0,0,.5);transition:transform .25s,opacity .25s;opacity:0;pointer-events:none}
.bulk-bar.visible{transform:translateX(-50%) translateY(0);opacity:1;pointer-events:auto}
.bulk-count{font-size:12px;font-weight:700;color:#7db3ff;white-space:nowrap}
.bulk-sep{width:1px;height:22px;background:var(--border)}
.bulk-sel{font-size:12px;padding:6px 10px;border-radius:8px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text-primary);cursor:pointer;height:34px}
.bulk-btn{display:inline-flex;align-items:center;gap:6px;padding:0 14px;height:34px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none}
.bulk-btn-assign{background:rgba(45,106,255,.2);border:1px solid rgba(45,106,255,.4);color:#7db3ff}
.bulk-btn-assign:hover{background:rgba(45,106,255,.35)}
.bulk-btn-status{background:rgba(0,201,167,.1);border:1px solid rgba(0,201,167,.3);color:var(--teal)}
.bulk-btn-status:hover{background:rgba(0,201,167,.2)}
.bulk-btn-del{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--red)}
.bulk-btn-del:hover{background:rgba(239,68,68,.18)}
.bulk-btn-clear{background:transparent;border:1px solid var(--border);color:var(--text-muted)}
.bulk-btn-clear:hover{background:rgba(255,255,255,.05)}
.empty-state{padding:60px 20px;text-align:center;color:var(--text-muted);font-size:14px}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">
<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Prospecção com IA</h1>
      <p>Leads encontrados pela IA e salvos automaticamente no banco.</p>
    </div>
  </div>
  <div class="topbar-actions">
    <a href="?export=csv&<?= http_build_query(array_diff_key($_GET, ['page'=>'','export'=>''])) ?>" class="btn-secondary">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar CSV
    </a>
  </div>
</header>

<div class="content">

  <?php if ($erroDb): ?>
    <div class="alert alert-err"><?= iaH($erroDb) ?></div>
  <?php endif; ?>

  <!-- Painel de pesquisa -->
  <div class="panel search-panel <?= $total === 0 ? 'open' : '' ?>" id="searchPanel">
    <div class="panel-header search-toggle" onclick="document.getElementById('searchPanel').classList.toggle('open')">
      <span class="panel-title">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:6px"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Pesquisar leads com IA
      </span>
      <svg class="search-toggle-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="search-body">
      <div class="preset-grid">
        <?php foreach (LEADS_PRESETS as $key => $cfg): ?>
          <div class="preset-card">
            <div class="preset-title"><?= iaH($cfg['titulo']) ?></div>
            <div class="preset-desc"><?= iaH($cfg['descricao']) ?></div>
            <div class="preset-meta">
              <span class="preset-pill"><?= implode(', ', $cfg['ufs']) ?></span>
              <span class="preset-pill">até <?= $cfg['quantidade'] ?> leads</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="run-row">
        <button id="btnRun" class="btn-primary" onclick="executarPesquisa()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          <span id="btnRunTxt">Pesquisar nos <?= count(LEADS_PRESETS) ?> segmentos</span>
        </button>
        <span style="font-size:11.5px;color:var(--text-muted);">A IA pesquisa cada segmento, filtra os já salvos e grava os novos leads.</span>
      </div>
      <div class="log-box" id="logBox"></div>
    </div>
  </div>

  <!-- Stats por segmento -->
  <?php if (!empty($stats)): ?>
  <div class="stats-row">
    <?php foreach ($stats as $seg => $qtd): ?>
      <a href="<?= iaH(rUrl(['segmento' => $seg])) ?>" class="stat-chip" style="<?= $filtSegmento === $seg ? 'border-color:rgba(45,106,255,.4);background:rgba(45,106,255,.08);color:#7db3ff;' : '' ?>">
        <?= iaH($seg ?: 'sem segmento') ?> <strong><?= $qtd ?></strong>
      </a>
    <?php endforeach; ?>
    <?php if ($filtSegmento !== ''): ?>
      <a href="<?= iaH(rUrl([], ['segmento'])) ?>" class="stat-chip">✕ limpar filtro</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Filtros -->
  <form method="GET" action="" class="filter-bar">
    <div class="field">
      <label>Busca</label>
      <input type="text" name="q" value="<?= iaH($filtBusca) ?>" placeholder="Empresa, cidade, e-mail…">
    </div>
    <div class="field">
      <label>Estado (UF)</label>
      <select name="uf">
        <option value="">Todos</option>
        <?php foreach ($ufList as $uf => $nome): ?>
          <option value="<?= $uf ?>" <?= $filtUf === $uf ? 'selected' : '' ?>><?= $uf ?> — <?= iaH($nome) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Segmento</label>
      <select name="segmento">
        <option value="">Todos</option>
        <?php foreach ($segmentosDisponiveis as $seg): ?>
          <option value="<?= iaH($seg) ?>" <?= $filtSegmento === $seg ? 'selected' : '' ?>><?= iaH($seg ?: '(sem segmento)') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Status</label>
      <select name="status">
        <option value="">Todos</option>
        <?php foreach (['NOVO'=>'Novo','EM_CONTATO'=>'Em contato','QUALIFICADO'=>'Qualificado','DESCARTADO'=>'Descartado'] as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $filtStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="max-width:110px;">
      <label>Score mínimo</label>
      <input type="number" name="score_min" min="0" max="100" value="<?= $filtScore ?: '' ?>" placeholder="0">
    </div>
    <?php if ($isAdmin && !empty($usuarios)): ?>
    <div class="field">
      <label>Responsável</label>
      <select name="usuario">
        <option value="">Todos</option>
        <option value="0" <?= $filtUsuario === '0' ? 'selected' : '' ?>>Sem responsável</option>
        <?php foreach ($usuarios as $u): ?>
          <option value="<?= $u['codigo'] ?>" <?= $filtUsuario === (string)$u['codigo'] ? 'selected' : '' ?>><?= iaH($u['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn-primary" style="height:36px;padding:0 16px;font-size:12px;">Filtrar</button>
    <a href="leads_resultados.php" class="btn-secondary" style="height:36px;padding:0 14px;font-size:12px;display:inline-flex;align-items:center;">Limpar</a>
  </form>

  <div class="panel-table">
    <div class="panel-header">
      <span class="panel-title">Resultados</span>
      <span class="count-badge"><?= $total ?></span>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <?php if ($erroDb): ?>
          Erro ao carregar dados do banco.
        <?php else: ?>
          Nenhum lead encontrado<?= ($filtBusca || $filtUf || $filtSegmento || $filtStatus || $filtScore) ? ' com os filtros aplicados.' : '. Use o painel acima para pesquisar.' ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="leads-table-wrap table-wrap">
      <table class="leads-table" id="leadsTable">
        <thead>
          <tr>
            <th class="cb-th"><input type="checkbox" id="cbAll" title="Selecionar todos"></th>
            <th>Score</th>
            <th>Empresa</th>
            <th>CNPJ</th>
            <th>Local</th>
            <th>Contato</th>
            <th>Site</th>
            <th>Segmento</th>
            <th>Status</th>
            <th>Responsável</th>
            <th>Gravado em</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
          $score    = (int) $row['score'];
          $rowUsuId = $row['usuario_id'] ? (int)$row['usuario_id'] : 0;
          $rowUsuNome = $usuMap[$rowUsuId] ?? '';
        ?>
          <?php
            $rNome   = (string) ($row['nome_empresa'] ?? '');
            $rFonte  = (string) ($row['fonte'] ?? '');
            $rMotivo = (string) ($row['motivo_relevancia'] ?? '');
            $rCnpj   = (string) ($row['cnpj'] ?? '');
            $rCidade = (string) ($row['cidade'] ?? '');
            $rUf     = (string) ($row['uf'] ?? '');
            $rTel    = (string) ($row['telefone'] ?? '');
            $rEmail  = (string) ($row['email'] ?? '');
            $rSite   = (string) ($row['site'] ?? '');
            $rSeg    = (string) ($row['segmento_busca'] ?? '');
          ?>
          <tr id="row-<?= $row['id'] ?>">
            <td class="cb-td"><input type="checkbox" class="row-cb" value="<?= $row['id'] ?>"></td>
            <td><div class="score-badge <?= iaScoreClass($score) ?>"><?= $score ?></div></td>
            <td>
              <div style="font-weight:700;font-size:13px;"><?= iaH($rNome) ?></div>
              <?php if ($rFonte !== ''): ?><div style="font-size:10.5px;color:var(--text-muted);margin-top:2px;"><?= iaH($rFonte) ?></div><?php endif; ?>
              <?php if ($rMotivo !== ''): ?><div style="font-size:11px;color:var(--text-secondary);margin-top:3px;font-style:italic;"><?= iaH($rMotivo) ?></div><?php endif; ?>
            </td>
            <td style="font-size:11px;color:var(--text-muted);"><?= iaH($rCnpj) ?></td>
            <td style="white-space:nowrap;"><?= iaH(trim($rCidade . ' / ' . $rUf, ' /')) ?></td>
            <td>
              <?php if ($rTel !== ''): ?><div><?= iaH($rTel) ?></div><?php endif; ?>
              <?php if ($rEmail !== ''): ?><div style="font-size:11px;color:var(--text-muted)"><?= iaH($rEmail) ?></div><?php endif; ?>
              <?php if ($rTel === '' && $rEmail === ''): ?>—<?php endif; ?>
            </td>
            <td>
              <?php if ($rSite !== ''): ?>
                <a href="<?= iaH($rSite) ?>" target="_blank" rel="noopener" class="ext-link"><?= iaH((string) preg_replace('/^https?:\/\/(www\.)?/', '', $rSite)) ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-size:11px;"><?= iaH($rSeg) ?></td>
            <td>
              <select class="status-sel" onchange="mudarStatus(<?= $row['id'] ?>, this.value)">
                <?php foreach (['NOVO'=>'Novo','EM_CONTATO'=>'Em contato','QUALIFICADO'=>'Qualificado','DESCARTADO'=>'Descartado'] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= ($row['status_prosp'] ?? 'NOVO') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <?php if ($rowUsuNome): ?>
                <span class="resp-tag"><?= iaH($rowUsuNome) ?></span>
              <?php else: ?>
                <span style="font-size:11px;color:var(--text-muted);">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:11px;white-space:nowrap;color:var(--text-muted);"><?= ($row['gravado_em'] ? date('d/m/y H:i', strtotime((string)$row['gravado_em'])) : '—') ?></td>
            <td>
              <button class="btn-del" title="Remover lead" onclick="deletarLead(<?= $row['id'] ?>)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>


    <?php endif; ?>
  </div>

</div>
</div>
</div>

<!-- Barra de ações em lote -->
<div class="bulk-bar" id="bulkBar">
  <span class="bulk-count" id="bulkCount">0 selecionados</span>
  <div class="bulk-sep"></div>

  <?php if ($isAdmin && !empty($usuarios)): ?>
  <select class="bulk-sel" id="bulkUsuario">
    <option value="">— Atribuir responsável —</option>
    <option value="0">Remover responsável</option>
    <?php foreach ($usuarios as $u): ?>
      <option value="<?= $u['codigo'] ?>"><?= iaH($u['nome']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="bulk-btn bulk-btn-assign" onclick="loteUsuario()">Atribuir</button>
  <div class="bulk-sep"></div>
  <?php endif; ?>

  <select class="bulk-sel" id="bulkStatus">
    <option value="">— Mudar status —</option>
    <option value="NOVO">Novo</option>
    <option value="EM_CONTATO">Em contato</option>
    <option value="QUALIFICADO">Qualificado</option>
    <option value="DESCARTADO">Descartado</option>
  </select>
  <button class="bulk-btn bulk-btn-status" onclick="loteStatus()">Aplicar</button>
  <div class="bulk-sep"></div>

  <button class="bulk-btn bulk-btn-del" onclick="loteDelete()">Remover</button>
  <button class="bulk-btn bulk-btn-clear" onclick="limparSelecao()">✕</button>
</div>

<script>
// ── Pesquisa de leads ─────────────────────────────────────────────────────────
async function executarPesquisa() {
  const btn = document.getElementById('btnRun');
  const txt = document.getElementById('btnRunTxt');
  const log = document.getElementById('logBox');

  btn.disabled = true;
  txt.textContent = 'Pesquisando…';
  btn.style.opacity = '.7';
  log.style.display = 'block';
  log.textContent = '⏳ Conectando à IA e pesquisando cada segmento…\n';

  try {
    const res  = await fetch(window.location.pathname + '?exec=1');
    const raw  = await res.text();

    log.textContent += `HTTP ${res.status} ${res.statusText}\n`;

    let data;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      log.textContent += `❌ Resposta não é JSON (HTTP ${res.status}):\n`;
      log.textContent += raw.slice(0, 800);
      txt.textContent = 'Pesquisar nos <?= count(LEADS_PRESETS) ?> segmentos';
      btn.disabled = false;
      btn.style.opacity = '1';
      return;
    }

    log.textContent = '';
    log.textContent += `✅ Concluído em ${data.executado_em}\n`;
    log.textContent += `🆕 Novos leads: ${data.total_novos}\n`;
    log.textContent += `♻️  Já existiam: ${data.total_filtrados}\n`;
    if (data.erros > 0) log.textContent += `❌ Erros: ${data.erros}\n`;
    log.textContent += '\n── Por segmento ──\n';
    for (const d of (data.detalhes || [])) {
      log.textContent += `${d.ok ? '✅' : '❌'} ${d.titulo}: ${d.message}\n`;
    }
    if (data.total_novos > 0) setTimeout(() => location.reload(), 1500);
  } catch (e) {
    log.textContent += `\n❌ Erro de rede: ${e.message}`;
  }

  txt.textContent = 'Pesquisar nos <?= count(LEADS_PRESETS) ?> segmentos';
  btn.disabled = false;
  btn.style.opacity = '1';
}

// ── Seleção ───────────────────────────────────────────────────────────────────
const cbAll   = document.getElementById('cbAll');
const bulkBar = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function getChecked() {
  return [...document.querySelectorAll('.row-cb:checked')].map(cb => cb.value);
}

function updateBulkBar() {
  const ids = getChecked();
  bulkCount.textContent = ids.length + ' selecionado' + (ids.length !== 1 ? 's' : '');
  bulkBar.classList.toggle('visible', ids.length > 0);
  // destaca linhas
  document.querySelectorAll('.row-cb').forEach(cb => {
    cb.closest('tr').classList.toggle('selected', cb.checked);
  });
}

function limparSelecao() {
  document.querySelectorAll('.row-cb').forEach(cb => cb.checked = false);
  if (cbAll) cbAll.checked = false;
  updateBulkBar();
}

if (cbAll) {
  cbAll.addEventListener('change', () => {
    document.querySelectorAll('.row-cb').forEach(cb => cb.checked = cbAll.checked);
    updateBulkBar();
  });
}
document.querySelectorAll('.row-cb').forEach(cb => cb.addEventListener('change', updateBulkBar));

// ── Ações individuais ─────────────────────────────────────────────────────────
async function mudarStatus(id, status) {
  const fd = new FormData();
  fd.append('action', 'status');
  fd.append('id', id);
  fd.append('status', status);
  await fetch('leads_resultados.php', { method: 'POST', body: fd });
}

async function deletarLead(id) {
  if (!confirm('Remover este lead do banco?')) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);
  const res  = await fetch('leads_resultados.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    const row = document.getElementById('row-' + id);
    if (row) { row.style.opacity = '0'; row.style.transition = '.3s'; setTimeout(() => row.remove(), 300); }
  }
}

// ── Ações em lote ─────────────────────────────────────────────────────────────
function buildLoteFd(action, extra = {}) {
  const fd  = new FormData();
  const ids = getChecked();
  fd.append('action', action);
  ids.forEach(id => fd.append('ids[]', id));
  for (const [k, v] of Object.entries(extra)) fd.append(k, v);
  return { fd, ids };
}

async function loteUsuario() {
  const sel = document.getElementById('bulkUsuario');
  if (!sel || sel.value === '') { alert('Escolha um responsável.'); return; }
  const usuId = sel.value === '0' ? '' : sel.value;
  const { fd, ids } = buildLoteFd('lote_usuario', { usuario_id: usuId });
  const res  = await fetch('leads_resultados.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) location.reload();
  else alert(data.message || 'Erro ao atribuir.');
}

async function loteStatus() {
  const sel = document.getElementById('bulkStatus');
  if (!sel || sel.value === '') { alert('Escolha um status.'); return; }
  const { fd, ids } = buildLoteFd('lote_status', { status: sel.value });
  const res  = await fetch('leads_resultados.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) location.reload();
  else alert(data.message || 'Erro ao mudar status.');
}

async function loteDelete() {
  const ids = getChecked();
  if (!confirm(`Remover ${ids.length} lead(s) do banco?`)) return;
  const { fd } = buildLoteFd('lote_delete');
  const res  = await fetch('leads_resultados.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    ids.forEach(id => {
      const row = document.getElementById('row-' + id);
      if (row) { row.style.opacity='0'; row.style.transition='.2s'; setTimeout(() => row.remove(), 200); }
    });
    limparSelecao();
  } else alert(data.message || 'Erro ao remover.');
}
</script>
</body>
</html>
