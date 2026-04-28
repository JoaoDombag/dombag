<?php
// ══════════════════════════════════════════════════════════════════════
//  DOMBAG — Produtos: Cadastro / Edição
//  Rota: /produtos/cadastro
//  Arquivo: modules/produtos/cad_produtos.php
// ══════════════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';

$msg_ok  = '';
$msg_err = '';

// ══════════════════════════════════════════════════════════════════════
//  AJAX handler (para reuso futuro)
// ══════════════════════════════════════════════════════════════════════
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $_POST['action'] ?? '';

    try {
        $db = pcpGetPDO();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($action === 'salvar') {
            $id        = (int) ($body['pro_codigo'] ?? 0);
            $descricao = trim($body['pro_descricao'] ?? '');
            $fluxo     = trim($body['pro_fluxo'] ?? '');
            $tipo      = trim($body['pro_tipo'] ?? '');
            $impressao = trim($body['pro_impressao'] ?? 'NAO');
            $valvulado = trim($body['pro_valvulado'] ?? 'NAO');
            $comprimento = (float) ($body['pro_comprimento'] ?? 0);
            $maq       = trim($body['pro_maq_impressao'] ?? '');
            $cod_yz    = ($body['pro_codigo_yz'] ?? '') !== '' ? (int) $body['pro_codigo_yz'] : null;

            if (!$descricao) throw new Exception('Descrição do produto é obrigatória.');

            if ($id > 0) {
                $st = $db->prepare('UPDATE PRODUTOS SET
                    pro_descricao=?, pro_fluxo=?, pro_tipo=?, pro_impressao=?,
                    pro_valvulado=?, pro_comprimento=?, pro_maq_impressao=?, pro_codigo_yz=?
                    WHERE pro_codigo=?');
                $st->execute([$descricao, $fluxo, $tipo, $impressao, $valvulado, $comprimento ?: null, $maq, $cod_yz, $id]);
                echo json_encode(['success' => true, 'msg' => 'Produto atualizado com sucesso.', 'id' => $id]);
            } else {
                $st = $db->prepare('INSERT INTO PRODUTOS
                    (pro_descricao, pro_fluxo, pro_tipo, pro_impressao, pro_valvulado, pro_comprimento, pro_maq_impressao, pro_codigo_yz)
                    VALUES (?,?,?,?,?,?,?,?)');
                $st->execute([$descricao, $fluxo, $tipo, $impressao, $valvulado, $comprimento ?: null, $maq, $cod_yz]);
                echo json_encode(['success' => true, 'msg' => 'Produto cadastrado com sucesso.', 'id' => (int) $db->lastInsertId()]);
            }
            exit;
        }

        throw new Exception('Ação desconhecida.');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  Carrega produto para edição (GET ?id=X)
// ══════════════════════════════════════════════════════════════════════
$edit_id = (int) ($_GET['id'] ?? 0);
$produto = [
    'pro_codigo'      => 0,
    'pro_descricao'   => '',
    'pro_tipo'        => '',
    'pro_comprimento' => '',
    'pro_impressao'   => 'NAO',
    'pro_valvulado'   => 'NAO',
    'pro_maq_impressao' => '',
    'pro_codigo_yz'   => '',
    'pro_fluxo'       => '',
];
$is_edit = false;

if ($edit_id > 0) {
    try {
        $db = pcpGetPDO();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $st = $db->prepare("
            SELECT pro_codigo, pro_descricao,
                   COALESCE(pro_tipo,'')           AS pro_tipo,
                   COALESCE(pro_comprimento,0)     AS pro_comprimento,
                   COALESCE(pro_impressao,'NAO')   AS pro_impressao,
                   COALESCE(pro_valvulado,'NAO')   AS pro_valvulado,
                   COALESCE(pro_maq_impressao,'')  AS pro_maq_impressao,
                   pro_codigo_yz,
                   COALESCE(pro_fluxo,'')          AS pro_fluxo
            FROM PRODUTOS WHERE pro_codigo = ?
        ");
        $st->execute([$edit_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $produto = $row;
            $is_edit = true;
        } else {
            $msg_err = 'Produto não encontrado (ID ' . $edit_id . ').';
        }
    } catch (Exception $e) {
        $msg_err = 'Erro ao carregar produto: ' . $e->getMessage();
    }
}

// ══════════════════════════════════════════════════════════════════════
//  POST padrão (não AJAX): salvar e redirecionar
// ══════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $id        = (int) ($_POST['pro_codigo'] ?? 0);
    $descricao = trim($_POST['pro_descricao'] ?? '');
    $fluxo     = trim($_POST['pro_fluxo'] ?? '');
    $tipo      = trim($_POST['pro_tipo'] ?? '');
    $impressao = trim($_POST['pro_impressao'] ?? 'NAO');
    $valvulado = trim($_POST['pro_valvulado'] ?? 'NAO');
    $comprimento = strlen(trim($_POST['pro_comprimento'] ?? '')) > 0 ? (float) $_POST['pro_comprimento'] : null;
    $maq       = trim($_POST['pro_maq_impressao'] ?? '');
    $cod_yz    = trim($_POST['pro_codigo_yz'] ?? '') !== '' ? (int) $_POST['pro_codigo_yz'] : null;

    // Preenche produto com valores postados (para reexibir em caso de erro)
    $produto = [
        'pro_codigo'       => $id,
        'pro_descricao'    => $descricao,
        'pro_tipo'         => $tipo,
        'pro_comprimento'  => $_POST['pro_comprimento'] ?? '',
        'pro_impressao'    => $impressao,
        'pro_valvulado'    => $valvulado,
        'pro_maq_impressao'=> $maq,
        'pro_codigo_yz'    => $_POST['pro_codigo_yz'] ?? '',
        'pro_fluxo'        => $fluxo,
    ];
    $is_edit = $id > 0;

    if (!$descricao) {
        $msg_err = 'Descrição do produto é obrigatória.';
    } else {
        try {
            $db = pcpGetPDO();
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($id > 0) {
                $st = $db->prepare('UPDATE PRODUTOS SET
                    pro_descricao=?, pro_fluxo=?, pro_tipo=?, pro_impressao=?,
                    pro_valvulado=?, pro_comprimento=?, pro_maq_impressao=?, pro_codigo_yz=?
                    WHERE pro_codigo=?');
                $st->execute([$descricao, $fluxo, $tipo, $impressao, $valvulado, $comprimento, $maq, $cod_yz, $id]);
            } else {
                $st = $db->prepare('INSERT INTO PRODUTOS
                    (pro_descricao, pro_fluxo, pro_tipo, pro_impressao, pro_valvulado, pro_comprimento, pro_maq_impressao, pro_codigo_yz)
                    VALUES (?,?,?,?,?,?,?,?)');
                $st->execute([$descricao, $fluxo, $tipo, $impressao, $valvulado, $comprimento, $maq, $cod_yz]);
            }

            header('Location: /produtos');
            exit;

        } catch (Exception $e) {
            $msg_err = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

$page_title = $is_edit ? 'Editando Produto' : 'Novo Produto';
$h = fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $h($page_title) ?> | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow-y:auto;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow-y:auto;min-width:0;}

/* Topbar */
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;position:sticky;top:0;z-index:10;}
.topbar-left{display:flex;align-items:center;gap:14px;min-width:0;}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;white-space:nowrap;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.topbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.btn-secondary{background:transparent;color:var(--text-muted);border:1px solid var(--border);padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:6px;white-space:nowrap;text-decoration:none;}
.btn-secondary:hover{background:var(--card-hover);color:var(--text-primary);}

/* Conteúdo */
.content{padding:32px 24px;display:flex;justify-content:center;}

/* Card do formulário */
.form-card{background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:28px 32px;width:100%;max-width:640px;}
.form-card-title{font-size:15px;font-weight:700;margin-bottom:24px;display:flex;align-items:center;gap:8px;padding-bottom:16px;border-bottom:1px solid var(--border);}

/* Alertas */
.alert{padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#ef4444;}
.alert-ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);}

/* Grupos */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
.form-grid.full{grid-template-columns:1fr;}
.f-group{display:flex;flex-direction:column;gap:5px;}
.f-group label{font-size:10.5px;font-weight:700;color:var(--text-muted);letter-spacing:.6px;text-transform:uppercase;}
.f-input{background:#0a1628;border:1px solid var(--border);color:var(--text-primary);padding:9px 12px;border-radius:8px;font-size:13px;font-family:'Segoe UI',sans-serif;outline:none;transition:.15s;width:100%;}
.f-input:focus{border-color:rgba(45,106,255,.5);}
.f-input::placeholder{color:var(--text-muted);}
.f-select{background:#0a1628;border:1px solid var(--border);color:var(--text-primary);padding:9px 12px;border-radius:8px;font-size:13px;font-family:'Segoe UI',sans-serif;outline:none;cursor:pointer;width:100%;text-transform:uppercase;}
.f-select option{background:#112240;text-transform:uppercase;}

/* Atalhos de fluxo */
.fluxo-shortcuts{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;}
.fluxo-btn{border:none;padding:4px 11px;border-radius:6px;font-size:11px;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;font-weight:600;}
.fluxo-btn:hover{opacity:.8;}
.fluxo-bag{background:rgba(45,106,255,.12);color:#7db3ff;}
.fluxo-sac{background:rgba(0,201,167,.1);color:var(--teal);}
.fluxo-valv{background:rgba(245,158,11,.1);color:var(--amber);}

/* Footer do form */
.form-footer{display:flex;gap:10px;justify-content:flex-end;padding-top:20px;border-top:1px solid var(--border);margin-top:8px;}
.btn-save{background:var(--blue-accent);color:white;border:none;padding:10px 26px;border-radius:7px;font-size:13px;font-weight:700;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;}
.btn-save:hover{background:var(--blue-light);}
.btn-cancel{background:transparent;color:var(--text-muted);border:1px solid var(--border);padding:10px 18px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;transition:.15s;text-decoration:none;display:inline-flex;align-items:center;}
.btn-cancel:hover{background:var(--card-hover);color:var(--text-primary);}

@media(max-width:640px){.form-grid{grid-template-columns:1fr;}.form-card{padding:20px 16px;}}
</style>
</head>
<body>
<div class="app-wrapper">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

<div class="main">
  <header class="topbar">
    <div class="topbar-left">
      <div class="page-title">
        <h1><?= $h($page_title) ?></h1>
        <p><?= $is_edit ? 'Editando produto ID ' . $h($produto['pro_codigo']) : 'Preencha os dados e salve' ?></p>
      </div>
    </div>
    <div class="topbar-actions">
      <a href="/produtos" class="btn-secondary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Ver Lista
      </a>
    </div>
  </header>

  <div class="content">
    <div class="form-card">
      <div class="form-card-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        <?= $h($page_title) ?>
      </div>

      <?php if ($msg_err): ?>
      <div class="alert alert-err">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= $h($msg_err) ?>
      </div>
      <?php endif; ?>

      <?php if ($msg_ok): ?>
      <div class="alert alert-ok">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= $h($msg_ok) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="/produtos/cadastro<?= $is_edit ? '?id=' . (int)$produto['pro_codigo'] : '' ?>">
        <input type="hidden" name="pro_codigo" value="<?= (int)$produto['pro_codigo'] ?>">

        <!-- Descrição -->
        <div class="form-grid full" style="margin-bottom:16px;">
          <div class="f-group">
            <label>Descricao do Produto *</label>
            <input type="text" class="f-input" name="pro_descricao"
                   value="<?= $h($produto['pro_descricao']) ?>"
                   placeholder="Ex: BIG BAG CDB4 160G 90X90X100 SEM IMPRESSAO"
                   required autofocus>
          </div>
        </div>

        <!-- Tipo + Comprimento -->
        <div class="form-grid">
          <div class="f-group">
            <label>Tipo</label>
            <select class="f-select" name="pro_tipo">
              <option value="">— Selecione —</option>
              <option value="BAG"     <?= $produto['pro_tipo'] === 'BAG'     ? 'selected' : '' ?>>BAG (Big Bag)</option>
              <option value="SACARIA" <?= $produto['pro_tipo'] === 'SACARIA' ? 'selected' : '' ?>>SACARIA</option>
            </select>
          </div>
          <div class="f-group">
            <label>Comprimento (cm)</label>
            <input type="number" class="f-input" name="pro_comprimento"
                   value="<?= $produto['pro_comprimento'] > 0 ? $h($produto['pro_comprimento']) : '' ?>"
                   placeholder="Ex: 100" step="0.5" min="0">
          </div>
        </div>

        <!-- Impressão + Valvulado -->
        <div class="form-grid">
          <div class="f-group">
            <label>Impressao?</label>
            <select class="f-select" name="pro_impressao">
              <option value="NAO" <?= ($produto['pro_impressao'] ?? 'NAO') !== 'SIM' ? 'selected' : '' ?>>NAO</option>
              <option value="SIM" <?= ($produto['pro_impressao'] ?? '') === 'SIM' ? 'selected' : '' ?>>SIM</option>
            </select>
          </div>
          <div class="f-group">
            <label>Valvulado?</label>
            <select class="f-select" name="pro_valvulado">
              <option value="NAO" <?= ($produto['pro_valvulado'] ?? 'NAO') !== 'SIM' ? 'selected' : '' ?>>NAO</option>
              <option value="SIM" <?= ($produto['pro_valvulado'] ?? '') === 'SIM' ? 'selected' : '' ?>>SIM</option>
            </select>
          </div>
        </div>

        <!-- Máquina de Impressão + Código Yzidro -->
        <div class="form-grid">
          <div class="f-group">
            <label>Maquina de Impressao</label>
            <select class="f-select" name="pro_maq_impressao">
              <option value="" <?= ($produto['pro_maq_impressao'] ?? '') === '' ? 'selected' : '' ?>>— Nenhuma —</option>
              <option value="Impressao Bag"        <?= ($produto['pro_maq_impressao'] ?? '') === 'Impressao Bag'        ? 'selected' : '' ?>>IMPRESSAO BAG</option>
              <option value="Flexo"                <?= ($produto['pro_maq_impressao'] ?? '') === 'Flexo'                ? 'selected' : '' ?>>FLEXO</option>
              <option value="Carimbadeira Sacaria" <?= ($produto['pro_maq_impressao'] ?? '') === 'Carimbadeira Sacaria' ? 'selected' : '' ?>>CARIMBADEIRA SACARIA</option>
            </select>
          </div>
          <div class="f-group">
            <label>Codigo Yzidro (ERP)</label>
            <input type="number" class="f-input" name="pro_codigo_yz"
                   value="<?= $h($produto['pro_codigo_yz'] ?? '') ?>"
                   placeholder="Codigo numerico do ERP">
          </div>
        </div>

        <!-- Fluxo de Produção -->
        <div class="form-grid full">
          <div class="f-group">
            <label>Fluxo de Producao</label>
            <input type="text" class="f-input" name="pro_fluxo" id="fluxoInput"
                   value="<?= $h($produto['pro_fluxo']) ?>"
                   placeholder="Ex: Corte Bag > Impressao Bag > Costura Bag > Analise Bag">
            <div class="fluxo-shortcuts">
              <button type="button" class="fluxo-btn fluxo-bag"  onclick="setFluxo('Corte Bag > Costura Bag > Analise Bag')">BAG s/ impressao</button>
              <button type="button" class="fluxo-btn fluxo-bag"  onclick="setFluxo('Corte Bag > Impressao Bag > Costura Bag > Analise Bag')">BAG c/ impressao</button>
              <button type="button" class="fluxo-btn fluxo-sac"  onclick="setFluxo('Corte+Costura Sacaria')">Sacaria s/ impressao</button>
              <button type="button" class="fluxo-btn fluxo-sac"  onclick="setFluxo('Flexo > Corte+Costura Sacaria')">Sacaria Flexo</button>
              <button type="button" class="fluxo-btn fluxo-sac"  onclick="setFluxo('Carimbadeira Sacaria > Corte+Costura Sacaria')">Sacaria Carimbo</button>
              <button type="button" class="fluxo-btn fluxo-valv" onclick="setFluxo('Corte+Costura Sacaria > Valvuladeira')">Sacaria Valvulada</button>
            </div>
          </div>
        </div>

        <div class="form-footer">
          <a href="/produtos" class="btn-cancel">Cancelar</a>
          <button type="submit" class="btn-save">Salvar Produto</button>
        </div>
      </form>
    </div>
  </div>
</div><!-- /main -->
</div><!-- /app-wrapper -->

<script>
function setFluxo(v) {
  document.getElementById('fluxoInput').value = v;
}
</script>
</body>
</html>
