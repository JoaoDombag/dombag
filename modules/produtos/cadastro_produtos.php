<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Cadastro de Produtos (MySQL em tempo real)
//  Arquivo: cadastro_produtos.php
// ══════════════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

$msg_ok = '';
$msg_err = '';

// ══════════════════════════════════════════════════════════════════════
//  AJAX — Salvar / Editar / Excluir produto
// ══════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $_POST['action'] ?? '';

    try {
        $db = pcpGetPDO();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // ── SALVAR (insert ou update) ─────────────────────────────────
        if ($action === 'salvar') {
            $id = (int) ($body['pro_codigo'] ?? 0);
            $descricao = trim($body['pro_descricao'] ?? '');
            $fluxo = trim($body['pro_fluxo'] ?? '');
            $tipo = trim($body['pro_tipo'] ?? '');
            $impressao = trim($body['pro_impressao'] ?? 'NAO');
            $valvulado = trim($body['pro_valvulado'] ?? 'NAO');
            $comprimento = (float) ($body['pro_comprimento'] ?? 0);
            $maq = trim($body['pro_maq_impressao'] ?? '');
            $cod_yz = $body['pro_codigo_yz'] !== '' ? (int) $body['pro_codigo_yz'] : null;

            if (!$descricao) {
                throw new Exception('Descrição do produto é obrigatória.');
            }

            if ($id > 0) {
                $st = $db->prepare('UPDATE produtos SET
                    pro_descricao=?, pro_fluxo=?, pro_tipo=?, pro_impressao=?,
                    pro_valvulado=?, pro_comprimento=?, pro_maq_impressao=?, pro_codigo_yz=?
                    WHERE pro_codigo=?');
                $st->execute([$descricao, $fluxo, $tipo, $impressao, $valvulado, $comprimento ?: null, $maq, $cod_yz, $id]);
                echo json_encode(['success' => true, 'msg' => 'Produto atualizado com sucesso.', 'id' => $id]);
            } else {
                $st = $db->prepare('INSERT INTO produtos
                    (pro_descricao, pro_fluxo, pro_tipo, pro_impressao, pro_valvulado, pro_comprimento, pro_maq_impressao, pro_codigo_yz)
                    VALUES (?,?,?,?,?,?,?,?)');
                $st->execute([$descricao, $fluxo, $tipo, $impressao, $valvulado, $comprimento ?: null, $maq, $cod_yz]);
                echo json_encode(['success' => true, 'msg' => 'Produto cadastrado com sucesso.', 'id' => (int) $db->lastInsertId()]);
            }
            exit;
        }

        // ── EXCLUIR ───────────────────────────────────────────────────
        if ($action === 'excluir') {
            $id = (int) ($body['pro_codigo'] ?? 0);
            if (!$id) {
                throw new Exception('ID inválido.');
            }
            // Verifica se há itens de venda vinculados
            $check = $db->prepare('SELECT COUNT(*) FROM itens_vendas WHERE pro_codigo=?');
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception('Produto possui itens de venda vinculados e não pode ser excluído.');
            }
            $db->prepare('DELETE FROM produtos WHERE pro_codigo=?')->execute([$id]);
            echo json_encode(['success' => true, 'msg' => 'Produto excluído.']);
            exit;
        }

        throw new Exception('Ação desconhecida.');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  Busca produtos do MySQL
// ══════════════════════════════════════════════════════════════════════
$produtos = [];
$db_error = '';
$produtos_js = '[]';

// Verifica/adiciona colunas extras que podem não existir ainda
try {
    $db = pcpGetPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Adiciona colunas extras caso não existam (migração silenciosa)
    $cols_extra = ['pro_tipo VARCHAR(20)', 'pro_impressao VARCHAR(3)', 'pro_valvulado VARCHAR(3)', 'pro_comprimento DECIMAL(8,2)', 'pro_maq_impressao VARCHAR(100)'];
    foreach ($cols_extra as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try {
            $db->exec("ALTER TABLE produtos ADD COLUMN $col_def");
        } catch (Exception $e) { /* já existe */
        }
    }

    $st = $db->query("
        SELECT
            pro_codigo,
            pro_codigo_yz,
            pro_descricao,
            COALESCE(pro_fluxo, '')          AS pro_fluxo,
            COALESCE(pro_tipo, '')           AS pro_tipo,
            COALESCE(pro_impressao, 'NAO')   AS pro_impressao,
            COALESCE(pro_valvulado, 'NAO')   AS pro_valvulado,
            COALESCE(pro_comprimento, 0)     AS pro_comprimento,
            COALESCE(pro_maq_impressao, '')  AS pro_maq_impressao,
            (SELECT COUNT(*) FROM itens_vendas iv WHERE iv.pro_codigo = p.pro_codigo) AS total_itens
        FROM produtos p
        ORDER BY pro_descricao
    ");
    $produtos = $st->fetchAll(PDO::FETCH_ASSOC);
    $produtos_js = json_encode($produtos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$total_prod = count($produtos);
$total_bags = count(array_filter($produtos, fn ($p) => stripos($p['pro_tipo'], 'BAG') !== false));
$total_sacs = count(array_filter($produtos, fn ($p) => stripos($p['pro_tipo'], 'SACARIA') !== false));
$total_imp = count(array_filter($produtos, fn ($p) => ($p['pro_impressao'] ?? '') === 'SIM'));
$total_vinc = count(array_filter($produtos, fn ($p) => (int) ($p['total_itens'] ?? 0) > 0));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Produtos | DOMBAG</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}

/* Topbar */
.topbar{visibility: visible; padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
.toggle-btn{width:36px;height:36px;min-width:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;}
.toggle-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;white-space:nowrap;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.btn-primary{background:var(--blue-accent);color:white;border:none;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.btn-primary:hover{background:var(--blue-light);}
.icon-btn{width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;}
.icon-btn:hover{background:var(--card-hover);color:var(--text-primary);}
.content{flex:1;overflow:hidden;padding:24px;display:flex;flex-direction:column;}

/* Stats */
.stat-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.stat-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;padding:13px 16px;display:flex;align-items:center;gap:12px;transition:.2s;}
.stat-card:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.1);}
.stat-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-card.c-blue .stat-icon{background:rgba(45,106,255,.15);color:#7db3ff;}
.stat-card.c-teal .stat-icon{background:rgba(0,201,167,.12);color:var(--teal);}
.stat-card.c-amb  .stat-icon{background:rgba(245,158,11,.12);color:var(--amber);}
.stat-card.c-red  .stat-icon{background:rgba(239,68,68,.12);color:var(--red);}
.stat-card.c-grn  .stat-icon{background:rgba(34,197,94,.12);color:var(--green);}
.stat-val{font-size:22px;font-weight:700;letter-spacing:-.6px;line-height:1;}
.stat-lbl{font-size:11px;color:var(--text-muted);margin-top:2px;}

/* Toolbar */
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap;}
.toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:8px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 12px;height:36px;transition:.15s;}
.search-box:focus-within{border-color:rgba(45,106,255,.5);}
.search-box svg{color:var(--text-muted);flex-shrink:0;}
.search-box input{background:transparent;border:none;outline:none;font-family:'Segoe UI',sans-serif;font-size:13px;color:var(--text-primary);width:260px;}
.search-box input::placeholder{color:var(--text-muted);}
.filter-select{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:0 10px;height:36px;font-family:'Segoe UI',sans-serif;font-size:12.5px;color:var(--text-primary);cursor:pointer;outline:none;}
.filter-select option{background:#112240;}
.results-count{font-size:11.5px;color:var(--text-muted);}

/* Tabela */
.orders-panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;flex:1;min-height:0;display:flex;flex-direction:column;}
.orders-panel .panel-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.panel-title{font-size:13px;font-weight:600;}
.prod-table{width:100%;border-collapse:collapse;}
.prod-table th{padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:#112240;white-space:nowrap;cursor:pointer;user-select:none;position:sticky;top:0;z-index:2;}
.prod-table th:hover{color:var(--text-primary);}
.prod-table td{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--border);color:var(--text-primary);white-space:nowrap;}
.prod-table tr:last-child td{border-bottom:none;}
.prod-table tbody tr:hover td{background:rgba(255,255,255,.03);}
.sort-icon{margin-left:3px;opacity:.4;font-size:9px;}
.sort-icon.active{opacity:1;color:var(--blue-light);}
.bool-yes{background:rgba(0,201,167,.1);color:var(--teal);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.bool-no{background:rgba(255,255,255,.06);color:var(--text-muted);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.tipo-bag{background:rgba(45,106,255,.12);color:#7db3ff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.tipo-sac{background:rgba(0,201,167,.1);color:var(--teal);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.tipo-imp{background:rgba(245,158,11,.1);color:var(--amber);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.fluxo-text{font-size:11.5px;color:var(--text-muted);max-width:240px;overflow:hidden;text-overflow:ellipsis;display:block;}
.action-btns{display:flex;gap:5px;}
.btn-edit{background:rgba(45,106,255,.15);color:#7db3ff;border:none;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;}
.btn-edit:hover{background:rgba(45,106,255,.3);}
.btn-del{background:rgba(239,68,68,.12);color:var(--red);border:none;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;}
.btn-del:hover{background:rgba(239,68,68,.25);}
.vinc-badge{background:rgba(0,201,167,.08);color:var(--teal);font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;}


/* Modal Formulário */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:1000;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.show{opacity:1;pointer-events:all;}
.modal{background:var(--blue-mid);border:1px solid var(--border);border-radius:16px;padding:28px;width:620px;max-width:94vw;max-height:90vh;overflow-y:auto;transform:scale(.96);transition:transform .2s;}
.modal-overlay.show .modal{transform:scale(1);}
.modal h2{font-size:16px;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
.form-grid.full{grid-template-columns:1fr;}
.f-group{display:flex;flex-direction:column;gap:5px;}
.f-group label{font-size:11px;font-weight:700;color:var(--text-muted);letter-spacing:.5px;text-transform:uppercase;}
.f-input{background:#0a1628;border:1px solid var(--border);color:var(--text-primary);padding:9px 12px;border-radius:8px;font-size:13px;font-family:'Segoe UI',sans-serif;outline:none;transition:.15s;width:100%;}
.f-input:focus{border-color:rgba(45,106,255,.5);}
.f-input::placeholder{color:var(--text-muted);}
.f-select{background:#0a1628;border:1px solid var(--border);color:var(--text-primary);padding:9px 12px;border-radius:8px;font-size:13px;font-family:'Segoe UI',sans-serif;outline:none;cursor:pointer;width:100%;}
.f-select option{background:#112240;}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding-top:8px;border-top:1px solid var(--border);margin-top:8px;}
.btn-cancel{background:transparent;color:var(--text-muted);border:1px solid var(--border);padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;}
.btn-cancel:hover{background:var(--card-hover);}
.btn-save{background:var(--blue-accent);color:white;border:none;padding:8px 22px;border-radius:7px;font-size:13px;font-weight:700;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;}
.btn-save:hover{background:var(--blue-light);}
.btn-save:disabled{opacity:.5;cursor:not-allowed;}
.modal-msg{padding:8px 12px;border-radius:7px;font-size:12.5px;margin-bottom:14px;display:none;}
.modal-msg.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);display:block;}
.modal-msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);display:block;}

/* Sidebar */
.sidebar{width:var(--sidebar-w);min-width:var(--sidebar-w);background:var(--blue-mid);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden;transition:width var(--transition),min-width var(--transition);position:relative;z-index:100;}
.sidebar.collapsed{width:64px;min-width:64px;}
.logo{padding:22px 14px 18px;border-bottom:1px solid var(--border);flex-shrink:0;}
.sidebar.collapsed .logo{padding:0;height:0;overflow:hidden;border-bottom:none;}
.logo-mark{display:flex;align-items:center;gap:10px;overflow:hidden;white-space:nowrap;}
.logo-icon{width:36px;height:36px;min-width:36px;background:linear-gradient(135deg,var(--blue-light),var(--teal));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:white;}
.logo-texts{overflow:hidden;transition:opacity var(--transition),max-width var(--transition);max-width:160px;opacity:1;}
.sidebar.collapsed .logo-texts,.sidebar.collapsed .logo-icon{max-width:0;opacity:0;}
.logo-text{font-size:16px;font-weight:700;letter-spacing:1.5px;white-space:nowrap;}
.logo-sub{font-size:10px;color:var(--text-muted);letter-spacing:.4px;margin-top:1px;white-space:nowrap;}
.nav-section{padding:16px 10px 8px;flex:1;overflow-y:auto;overflow-x:hidden;}
.nav-label{font-size:9px;font-weight:700;letter-spacing:1.6px;color:var(--text-muted);padding:0 10px;margin-bottom:4px;text-transform:uppercase;white-space:nowrap;overflow:hidden;transition:opacity var(--transition),max-height var(--transition);max-height:30px;opacity:1;}
.sidebar.collapsed .nav-label{opacity:0;max-height:0;margin:0;padding:0;}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px;border-radius:8px;color:var(--text-muted);text-decoration:none;font-size:13.5px;font-weight:500;transition:.15s;margin-bottom:2px;overflow:hidden;white-space:nowrap;position:relative;}
.nav-item:hover{background:var(--card-hover);color:var(--text-primary);}
.nav-item.active{background:rgba(45,106,255,.18);color:#7db3ff;border-left:2px solid var(--blue-light);}
.nav-icon{width:20px;min-width:20px;text-align:center;font-size:15px;line-height:1;}
.nav-text{overflow:hidden;transition:opacity var(--transition),max-width var(--transition);max-width:160px;opacity:1;}
.sidebar.collapsed .nav-text{max-width:0;opacity:0;}
.sidebar.collapsed .nav-item{justify-content:center;}
.sidebar.collapsed .nav-item::after{content:attr(data-tooltip);position:absolute;left:72px;background:#1e3a6e;color:var(--text-primary);padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:200;border:1px solid var(--border);box-shadow:0 4px 12px rgba(0,0,0,.3);}
.sidebar.collapsed .nav-item:hover::after{opacity:1;}
.sidebar-footer{padding:12px 10px;border-top:1px solid var(--border);flex-shrink:0;}
.user-block{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;cursor:pointer;overflow:hidden;white-space:nowrap;transition:.15s;}
.user-block:hover{background:var(--card-hover);}
.avatar{width:32px;height:32px;min-width:32px;border-radius:50%;background:linear-gradient(135deg,var(--blue-light),var(--teal));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;}
.user-info{overflow:hidden;transition:opacity var(--transition),max-width var(--transition);max-width:160px;opacity:1;}
.sidebar.collapsed .user-info{max-width:0;opacity:0;}
.user-info p{font-size:12.5px;font-weight:600;white-space:nowrap;}
.user-info span{font-size:11px;color:var(--text-muted);white-space:nowrap;}
.db-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:12.5px;color:#ef4444;display:flex;align-items:center;gap:8px;}
@media(max-width:1100px){.stat-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:640px){.search-box input{width:140px;}.form-grid{grid-template-columns:1fr;}}
@media(max-width:480px){.stat-strip{grid-template-columns:1fr 1fr;}.search-box input{width:auto;flex:1;}.orders-panel{overflow-x:auto;}.prod-table{min-width:500px;}}
</style>

<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
</head>
<body>
<div class="app-wrapper">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <header class="topbar">
    <div class="topbar-left">
<div class="page-title">
        <h1>Cadastro de Produtos</h1>
        <p><?= $total_prod ?> produto(s) cadastrado(s) • atualizado em tempo real</p>
      </div>
    </div>
    <div class="topbar-actions">
      <button class="icon-btn" id="btnExport" title="Exportar CSV">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      </button>
      <button class="btn-primary" onclick="abrirModal()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo Produto
      </button>
    </div>
  </header>

  <div class="content">

    <?php if ($db_error): ?>
    <div class="db-error">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Erro ao conectar ao banco: <?= htmlspecialchars($db_error) ?>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="stat-strip">
      <div class="stat-card c-blue">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
        <div><div class="stat-val" id="s-total"><?= $total_prod ?></div><div class="stat-lbl">Total de Produtos</div></div>
      </div>
      <div class="stat-card c-teal">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div>
        <div><div class="stat-val" id="s-bags"><?= $total_bags ?></div><div class="stat-lbl">Big Bags</div></div>
      </div>
      <div class="stat-card c-amb">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
        <div><div class="stat-val" id="s-sacs"><?= $total_sacs ?></div><div class="stat-lbl">Sacarias</div></div>
      </div>
      <div class="stat-card c-red">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg></div>
        <div><div class="stat-val" id="s-imp"><?= $total_imp ?></div><div class="stat-lbl">Com Impressão</div></div>
      </div>
      <div class="stat-card c-grn">
        <div class="stat-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div><div class="stat-val" id="s-vinc"><?= $total_vinc ?></div><div class="stat-lbl">Vinculados a Pedidos</div></div>
      </div>
    </div>

    <!-- Toolbar de filtros -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-box">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="searchInput" placeholder="Buscar produto, máquina ou fluxo…">
        </div>
        <select class="filter-select" id="filterTipo">
          <option value="">Todos os tipos</option>
          <option value="BAG">BAG</option>
          <option value="SACARIA">SACARIA</option>
        </select>
        <select class="filter-select" id="filterImp">
          <option value="">Impressão (todos)</option>
          <option value="SIM">Com impressão</option>
          <option value="NAO">Sem impressão</option>
        </select>
        <select class="filter-select" id="filterValv">
          <option value="">Valvulado (todos)</option>
          <option value="SIM">Valvulado</option>
          <option value="NAO">Não valvulado</option>
        </select>
      </div>
      <span class="results-count" id="resultsCount"></span>
    </div>

    <!-- Tabela de produtos -->
    <div class="orders-panel">
      <div class="panel-header">
        <span class="panel-title">Produtos cadastrados</span>
        <span class="results-count" id="resultsPanel"></span>
      </div>
      <div class="table-wrap">
        <table class="prod-table">
          <thead><tr>
            <th data-col="pro_descricao">Produto <span class="sort-icon active" id="s-pro_descricao">↑</span></th>
            <th data-col="pro_tipo">Tipo <span class="sort-icon" id="s-pro_tipo">↕</span></th>
            <th data-col="pro_comprimento">Comp. (cm) <span class="sort-icon" id="s-pro_comprimento">↕</span></th>
            <th>Impressão</th>
            <th>Valvulado</th>
            <th>Máq. Impressão</th>
            <th>Fluxo de Produção</th>
            <th>Pedidos</th>
            <th>Cód. YZ</th>
            <th style="text-align:center;">Ações</th>
          </tr></thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>
      <div id="emptyState" style="display:none;text-align:center;padding:50px 20px;color:var(--text-muted);">
        <p style="font-size:13px;">Nenhum produto encontrado.</p>
        <button onclick="abrirModal()" style="margin-top:12px;background:var(--blue-accent);color:white;border:none;padding:8px 18px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;">+ Cadastrar primeiro produto</button>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- ══ MODAL FORMULÁRIO ══ -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <h2 id="modalTitle">Novo Produto</h2>
    <div class="modal-msg" id="modalMsg"></div>

    <input type="hidden" id="f-pro_codigo" value="0">

    <div class="form-grid full">
      <div class="f-group">
        <label>Descrição do Produto *</label>
        <input type="text" class="f-input" id="f-pro_descricao" placeholder="Ex: BIG BAG CDB4 160G 90X90X100 SEM IMPRESSAO">
      </div>
    </div>

    <div class="form-grid">
      <div class="f-group">
        <label>Tipo</label>
        <select class="f-select" id="f-pro_tipo">
          <option value="">— Selecione —</option>
          <option value="BAG">BAG (Big Bag)</option>
          <option value="SACARIA">SACARIA</option>
        </select>
      </div>
      <div class="f-group">
        <label>Comprimento (cm)</label>
        <input type="number" class="f-input" id="f-pro_comprimento" placeholder="Ex: 100" step="0.5" min="0">
      </div>
      <div class="f-group">
        <label>Impressão?</label>
        <select class="f-select" id="f-pro_impressao">
          <option value="NAO">Não</option>
          <option value="SIM">Sim</option>
        </select>
      </div>
      <div class="f-group">
        <label>Valvulado?</label>
        <select class="f-select" id="f-pro_valvulado">
          <option value="NAO">Não</option>
          <option value="SIM">Sim</option>
        </select>
      </div>
      <div class="f-group">
        <label>Máquina de Impressão</label>
        <select class="f-select" id="f-pro_maq_impressao">
          <option value="">— Nenhuma —</option>
          <option value="Impressao Bag">Impressão Bag</option>
          <option value="Flexo">Flexo</option>
          <option value="Carimbadeira Sacaria">Carimbadeira Sacaria</option>
        </select>
      </div>
      <div class="f-group">
        <label>Código Yzidro (ERP)</label>
        <input type="number" class="f-input" id="f-pro_codigo_yz" placeholder="Código numérico do ERP">
      </div>
    </div>

    <div class="form-grid full">
      <div class="f-group">
        <label>Fluxo de Produção</label>
        <input type="text" class="f-input" id="f-pro_fluxo" placeholder="Ex: Corte Bag > Impressao Bag > Costura Bag > Analise Bag">
        <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;" id="fluxo-atalhos">
          <button type="button" onclick="setFluxo('Corte Bag > Costura Bag > Analise Bag')" style="background:rgba(45,106,255,.12);color:#7db3ff;border:none;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;">BAG s/ impressão</button>
          <button type="button" onclick="setFluxo('Corte Bag > Impressao Bag > Costura Bag > Analise Bag')" style="background:rgba(45,106,255,.12);color:#7db3ff;border:none;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;">BAG c/ impressão</button>
          <button type="button" onclick="setFluxo('Corte+Costura Sacaria')" style="background:rgba(0,201,167,.1);color:var(--teal);border:none;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;">Sacaria s/ impressão</button>
          <button type="button" onclick="setFluxo('Flexo > Corte+Costura Sacaria')" style="background:rgba(0,201,167,.1);color:var(--teal);border:none;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;">Sacaria Flexo</button>
          <button type="button" onclick="setFluxo('Carimbadeira Sacaria > Corte+Costura Sacaria')" style="background:rgba(0,201,167,.1);color:var(--teal);border:none;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;">Sacaria Carimbo</button>
          <button type="button" onclick="setFluxo('Corte+Costura Sacaria > Valvuladeira')" style="background:rgba(245,158,11,.1);color:var(--amber);border:none;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;">Sacaria Valvulada</button>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn-cancel" onclick="fecharModal()">Cancelar</button>
      <button class="btn-save" id="btnSalvar" onclick="salvarProduto()">💾 Salvar Produto</button>
    </div>
  </div>
</div>

<script>
let PRODUTOS = <?= $produtos_js ?>;
let sortCol = 'pro_descricao', sortAsc = true, filtered = [...PRODUTOS];

function boolPill(v){ return v==='SIM' ? '<span class="bool-yes">Sim</span>' : '<span class="bool-no">Não</span>'; }
function tipoPill(v){
  if(!v) return '<span style="color:var(--text-muted);font-size:11px;">—</span>';
  return v==='BAG' ? '<span class="tipo-bag">BAG</span>' : '<span class="tipo-sac">SACARIA</span>';
}

function applyFilters(){
  const q  = document.getElementById('searchInput').value.toLowerCase();
  const ti = document.getElementById('filterTipo').value;
  const im = document.getElementById('filterImp').value;
  const va = document.getElementById('filterValv').value;
  filtered = PRODUTOS.filter(p => {
    if(q && !(p.pro_descricao||'').toLowerCase().includes(q) && !(p.pro_maq_impressao||'').toLowerCase().includes(q) && !(p.pro_fluxo||'').toLowerCase().includes(q)) return false;
    if(ti && p.pro_tipo !== ti) return false;
    if(im && p.pro_impressao !== im) return false;
    if(va && p.pro_valvulado !== va) return false;
    return true;
  });
  filtered.sort((a,b) => {
    let va2 = a[sortCol] ?? '', vb2 = b[sortCol] ?? '';
    if(sortCol === 'pro_comprimento'){ va2 = parseFloat(va2)||0; vb2 = parseFloat(vb2)||0; }
    if(va2 < vb2) return sortAsc ? -1 : 1;
    if(va2 > vb2) return sortAsc ?  1 : -1;
    return 0;
  });
  render();
}

function render(){
  const tot = filtered.length;
  document.getElementById('resultsCount').textContent = tot+' produto(s)';
  document.getElementById('resultsPanel').textContent = tot+' produto(s)';
  const tbody = document.getElementById('tbody'), empty = document.getElementById('emptyState');
  if(!tot){ tbody.innerHTML=''; empty.style.display='block'; return; }
  empty.style.display = 'none';

  tbody.innerHTML = filtered.map(p => `
    <tr>
      <td title="${p.pro_descricao||''}" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;">${(p.pro_descricao||'').length>48?(p.pro_descricao).slice(0,48)+'…':p.pro_descricao||'—'}</td>
      <td>${tipoPill(p.pro_tipo)}</td>
      <td style="text-align:right;">${p.pro_comprimento > 0 ? parseFloat(p.pro_comprimento).toLocaleString('pt-BR') : '—'}</td>
      <td>${boolPill(p.pro_impressao)}</td>
      <td>${boolPill(p.pro_valvulado)}</td>
      <td>${p.pro_maq_impressao || '—'}</td>
      <td><span class="fluxo-text" title="${p.pro_fluxo||''}">${p.pro_fluxo || '—'}</span></td>
      <td>${parseInt(p.total_itens||0) > 0 ? `<span class="vinc-badge">${p.total_itens} item(ns)</span>` : '<span style="color:var(--text-muted);font-size:11px;">—</span>'}</td>
      <td style="color:var(--text-muted);font-size:11.5px;">${p.pro_codigo_yz || '—'}</td>
      <td>
        <div class="action-btns" style="justify-content:center;">
          <button class="btn-edit" onclick='editarProduto(${JSON.stringify(p)})'>✏️ Editar</button>
          ${parseInt(p.total_itens||0) === 0 ? `<button class="btn-del" onclick="excluirProduto(${p.pro_codigo},'${(p.pro_descricao||'').replace(/'/g,"\\'")}')">🗑</button>` : ''}
        </div>
      </td>
    </tr>`).join('');

}

// ── Sort ────────────────────────────────────────────────────────────
document.querySelectorAll('.prod-table th[data-col]').forEach(th => {
  th.addEventListener('click', () => {
    const col = th.dataset.col;
    sortAsc = sortCol===col ? !sortAsc : true; sortCol = col;
    document.querySelectorAll('.sort-icon').forEach(i=>{ i.classList.remove('active'); i.textContent='↕'; });
    const ic = document.getElementById('s-'+col); if(ic){ ic.classList.add('active'); ic.textContent = sortAsc?'↑':'↓'; }
    applyFilters();
  });
});
['searchInput'].forEach(id => document.getElementById(id).addEventListener('input', applyFilters));
['filterTipo','filterImp','filterValv'].forEach(id => document.getElementById(id).addEventListener('change', applyFilters));

// ── Modal ───────────────────────────────────────────────────────────
function abrirModal(){ limparModal(); document.getElementById('modalOverlay').classList.add('show'); document.getElementById('f-pro_descricao').focus(); }
function fecharModal(){ document.getElementById('modalOverlay').classList.remove('show'); }
function limparModal(){
  document.getElementById('modalTitle').textContent = '📦 Novo Produto';
  document.getElementById('f-pro_codigo').value = '0';
  ['pro_descricao','pro_fluxo','pro_comprimento','pro_codigo_yz'].forEach(f=>document.getElementById('f-'+f).value='');
  document.getElementById('f-pro_tipo').value='';
  document.getElementById('f-pro_impressao').value='NAO';
  document.getElementById('f-pro_valvulado').value='NAO';
  document.getElementById('f-pro_maq_impressao').value='';
  setModalMsg('','');
}

function editarProduto(p){
  document.getElementById('modalTitle').textContent = '✏️ Editar Produto';
  document.getElementById('f-pro_codigo').value      = p.pro_codigo;
  document.getElementById('f-pro_descricao').value   = p.pro_descricao || '';
  document.getElementById('f-pro_fluxo').value       = p.pro_fluxo || '';
  document.getElementById('f-pro_tipo').value        = p.pro_tipo || '';
  document.getElementById('f-pro_impressao').value   = p.pro_impressao || 'NAO';
  document.getElementById('f-pro_valvulado').value   = p.pro_valvulado || 'NAO';
  document.getElementById('f-pro_comprimento').value = p.pro_comprimento > 0 ? p.pro_comprimento : '';
  document.getElementById('f-pro_maq_impressao').value = p.pro_maq_impressao || '';
  document.getElementById('f-pro_codigo_yz').value   = p.pro_codigo_yz || '';
  setModalMsg('','');
  document.getElementById('modalOverlay').classList.add('show');
}

function setFluxo(v){ document.getElementById('f-pro_fluxo').value = v; }

function setModalMsg(txt, tipo){
  const el = document.getElementById('modalMsg');
  el.textContent = txt; el.className = 'modal-msg';
  if(tipo) el.classList.add(tipo);
}

async function salvarProduto(){
  const btn = document.getElementById('btnSalvar');
  const desc = document.getElementById('f-pro_descricao').value.trim();
  if(!desc){ setModalMsg('Informe a descrição do produto.','err'); return; }

  btn.disabled = true; btn.textContent = 'Salvando…';
  const payload = {
    action:           'salvar',
    pro_codigo:       document.getElementById('f-pro_codigo').value,
    pro_descricao:    desc,
    pro_fluxo:        document.getElementById('f-pro_fluxo').value,
    pro_tipo:         document.getElementById('f-pro_tipo').value,
    pro_impressao:    document.getElementById('f-pro_impressao').value,
    pro_valvulado:    document.getElementById('f-pro_valvulado').value,
    pro_comprimento:  document.getElementById('f-pro_comprimento').value,
    pro_maq_impressao:document.getElementById('f-pro_maq_impressao').value,
    pro_codigo_yz:    document.getElementById('f-pro_codigo_yz').value,
  };

  try {
    const resp = await fetch(window.location.href, {
      method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify(payload)
    });
    const data = await resp.json();
    if(data.success){
      setModalMsg(data.msg,'ok');
      setTimeout(()=>{ fecharModal(); window.location.reload(); }, 900);
    } else {
      setModalMsg(data.msg,'err');
    }
  } catch(e){ setModalMsg('Erro de comunicação: '+e.message,'err'); }
  finally { btn.disabled=false; btn.innerHTML='💾 Salvar Produto'; }
}

async function excluirProduto(id, nome){
  if(!confirm(`Excluir o produto:\n"${nome}"?\n\nEsta ação não pode ser desfeita.`)) return;
  try {
    const resp = await fetch(window.location.href, {
      method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({action:'excluir', pro_codigo: id})
    });
    const data = await resp.json();
    if(data.success){ window.location.reload(); }
    else { alert('Erro: '+data.msg); }
  } catch(e){ alert('Erro de comunicação: '+e.message); }
}

// Fechar modal clicando fora
document.getElementById('modalOverlay').addEventListener('click', function(e){ if(e.target===this) fecharModal(); });

// Export CSV
document.getElementById('btnExport').addEventListener('click', () => {
  const cols = ['pro_codigo','pro_codigo_yz','pro_descricao','pro_tipo','pro_comprimento','pro_impressao','pro_valvulado','pro_maq_impressao','pro_fluxo','total_itens'];
  const hdr  = ['ID','Cód. YZ','Produto','Tipo','Comp.(cm)','Impressão','Valvulado','Máq. Impressão','Fluxo','Qtd Pedidos'];
  const rows = [hdr, ...filtered.map(p => cols.map(c => (p[c]||'').toString().replace(/;/g,' ')))];
  const a = document.createElement('a'); a.href='data:text/csv;charset=utf-8,\uFEFF'+encodeURIComponent(rows.map(r=>r.join(';')).join('\n')); a.download='cadastro_produtos.csv'; a.click();
});


applyFilters();
</script>
</body>
</html>