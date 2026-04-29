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
    'pro_codigo'        => 0,
    'pro_descricao'     => '',
    'pro_tipo'          => '',
    'pro_comprimento'   => '',
    'pro_impressao'     => 'NAO',
    'pro_valvulado'     => 'NAO',
    'pro_maq_impressao' => '',
    'pro_codigo_yz'     => '',
    'pro_fluxo'         => '',
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

    $produto = [
        'pro_codigo'        => $id,
        'pro_descricao'     => $descricao,
        'pro_tipo'          => $tipo,
        'pro_comprimento'   => $_POST['pro_comprimento'] ?? '',
        'pro_impressao'     => $impressao,
        'pro_valvulado'     => $valvulado,
        'pro_maq_impressao' => $maq,
        'pro_codigo_yz'     => $_POST['pro_codigo_yz'] ?? '',
        'pro_fluxo'         => $fluxo,
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
.content { flex: 1; overflow: hidden; display: flex; flex-direction: column; padding: 20px 24px; }

.cad-alert { border-radius: 10px; padding: 11px 16px; font-size: 12.5px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.cad-alert-err { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #ef4444; }
.cad-alert-ok  { background: rgba(34,197,94,.1);  border: 1px solid rgba(34,197,94,.25); color: var(--teal); }

.cad-card { flex: 1; min-height: 0; background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; box-shadow: 0 4px 28px rgba(0,0,0,.22); display: flex; flex-direction: column; }

.cad-head { padding: 18px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 14px; background: linear-gradient(120deg, rgba(30,79,201,.1) 0%, transparent 70%); border-top: 3px solid var(--blue-accent); flex-shrink: 0; }
.cad-icon { width: 40px; height: 40px; border-radius: 10px; background: rgba(30,79,201,.15); border: 1px solid rgba(30,79,201,.25); color: #7db3ff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cad-head-info { flex: 1; }
.cad-head-info h2 { font-size: 15px; font-weight: 700; }
.cad-head-info p  { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.badge-edit { font-size: 10.5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: rgba(245,158,11,.12); color: var(--amber); display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; }

.cad-body { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }
.section-label { font-size: 10px; font-weight: 800; letter-spacing: 1.2px; color: var(--text-muted); text-transform: uppercase; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
.cad-section { display: flex; flex-direction: column; gap: 14px; }

.f-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.f-row.t3 { grid-template-columns: repeat(3, 1fr); }
.f-hint { font-size: 11px; color: var(--text-muted); margin-top: 2px; line-height: 1.5; }

.field select { text-transform: uppercase; }
.field select option { text-transform: uppercase; background: var(--blue-mid); }

.shortcuts { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.sc { border: none; padding: 4px 11px; border-radius: 6px; font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 600; transition: opacity .15s; }
.sc:hover { opacity: .75; }
.sc-bag  { background: rgba(45,106,255,.12); color: #7db3ff; }
.sc-sac  { background: rgba(0,201,167,.1);  color: var(--teal); }
.sc-valv { background: rgba(245,158,11,.1); color: var(--amber); }

.cad-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 8px; background: rgba(0,0,0,.08); flex-shrink: 0; }
.cad-footer .btn-primary { flex: 1; justify-content: center; font-size: 13.5px; padding: 10px 20px; }

@media (max-width: 700px) {
  .content { padding: 12px; }
  .cad-head, .cad-body, .cad-footer { padding-left: 16px; padding-right: 16px; }
  .cad-body { padding-top: 16px; padding-bottom: 16px; }
  .cad-footer { flex-direction: column-reverse; }
  .cad-footer .btn-primary { flex: none; }
  .f-row, .f-row.t3 { grid-template-columns: 1fr; }
  .cad-icon { width: 34px; height: 34px; }
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

    <div class="cad-card">

      <div class="cad-head">
        <div class="cad-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
            <path d="m3.27 6.96 8.73 5.04 8.73-5.04"/><line x1="12" y1="22" x2="12" y2="12"/>
          </svg>
        </div>
        <div class="cad-head-info">
          <h2><?= $is_edit ? 'Editar Produto' : 'Novo Produto' ?></h2>
          <p><?= $is_edit ? $h($produto['pro_descricao']) : 'Defina descrição, tipo e fluxo de produção' ?></p>
        </div>
        <?php if ($is_edit): ?>
        <span class="badge-edit">
          <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Editando
        </span>
        <?php endif; ?>
      </div>

      <form method="POST" action="/produtos/cadastro<?= $is_edit ? '?id=' . (int)$produto['pro_codigo'] : '' ?>">
        <input type="hidden" name="pro_codigo" value="<?= (int)$produto['pro_codigo'] ?>">

        <div class="cad-body">

          <?php if ($msg_err): ?>
          <div class="cad-alert cad-alert-err" id="alertEl">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= $h($msg_err) ?>
          </div>
          <?php endif; ?>
          <?php if ($msg_ok): ?>
          <div class="cad-alert cad-alert-ok" id="alertEl">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $h($msg_ok) ?>
          </div>
          <?php endif; ?>

          <!-- Identificação -->
          <div class="cad-section">
            <div class="section-label">Identificação</div>

          <div class="field">
            <label>Descrição do Produto *</label>
            <input type="text" name="pro_descricao"
                   value="<?= $h($produto['pro_descricao']) ?>"
                   placeholder="Ex: BIG BAG CDB4 160G 90X90X100 SEM IMPRESSAO"
                   required autofocus>
          </div>

          <div class="f-row">
            <div class="field">
              <label>Tipo</label>
              <select name="pro_tipo">
                <option value="">— SELECIONE —</option>
                <option value="BAG"     <?= $produto['pro_tipo'] === 'BAG'     ? 'selected' : '' ?>>BAG (BIG BAG)</option>
                <option value="SACARIA" <?= $produto['pro_tipo'] === 'SACARIA' ? 'selected' : '' ?>>SACARIA</option>
              </select>
            </div>
            <div class="field">
              <label>Comprimento (cm)</label>
              <input type="number" name="pro_comprimento"
                     value="<?= $produto['pro_comprimento'] > 0 ? $h($produto['pro_comprimento']) : '' ?>"
                     placeholder="Ex: 100" step="0.5" min="0">
            </div>
          </div>
        </div>

        <!-- Especificações técnicas -->
        <div class="cad-section">
          <div class="section-label">Especificações Técnicas</div>

          <div class="f-row t3">
            <div class="field">
              <label>Impressão?</label>
              <select name="pro_impressao">
                <option value="NAO" <?= ($produto['pro_impressao'] ?? 'NAO') !== 'SIM' ? 'selected' : '' ?>>NÃO</option>
                <option value="SIM" <?= ($produto['pro_impressao'] ?? '') === 'SIM' ? 'selected' : '' ?>>SIM</option>
              </select>
            </div>
            <div class="field">
              <label>Valvulado?</label>
              <select name="pro_valvulado">
                <option value="NAO" <?= ($produto['pro_valvulado'] ?? 'NAO') !== 'SIM' ? 'selected' : '' ?>>NÃO</option>
                <option value="SIM" <?= ($produto['pro_valvulado'] ?? '') === 'SIM' ? 'selected' : '' ?>>SIM</option>
              </select>
            </div>
            <div class="field">
              <label>Código Yzidro (ERP)</label>
              <input type="number" name="pro_codigo_yz"
                     value="<?= $h($produto['pro_codigo_yz'] ?? '') ?>"
                     placeholder="Código numérico">
            </div>
          </div>

          <div class="field">
            <label>Máquina de Impressão</label>
            <select name="pro_maq_impressao">
              <option value="" <?= ($produto['pro_maq_impressao'] ?? '') === '' ? 'selected' : '' ?>>— NENHUMA —</option>
              <option value="Impressao Bag"        <?= ($produto['pro_maq_impressao'] ?? '') === 'Impressao Bag'        ? 'selected' : '' ?>>IMPRESSÃO BAG</option>
              <option value="Flexo"                <?= ($produto['pro_maq_impressao'] ?? '') === 'Flexo'                ? 'selected' : '' ?>>FLEXO</option>
              <option value="Carimbadeira Sacaria" <?= ($produto['pro_maq_impressao'] ?? '') === 'Carimbadeira Sacaria' ? 'selected' : '' ?>>CARIMBADEIRA SACARIA</option>
            </select>
          </div>
        </div>

        <!-- Fluxo de produção -->
        <div class="cad-section">
          <div class="section-label">Fluxo de Produção</div>

          <div class="field">
            <label>Sequência de Etapas</label>
            <input type="text" name="pro_fluxo" id="fluxoInput"
                   value="<?= $h($produto['pro_fluxo']) ?>"
                   placeholder="Ex: Corte Bag > Impressão Bag > Costura Bag > Análise Bag">
            <span class="f-hint">Etapas separadas por " > ". Use os atalhos abaixo para preencher rapidamente.</span>
            <div class="shortcuts">
              <button type="button" class="sc sc-bag"  onclick="setFluxo('Corte Bag > Costura Bag > Analise Bag')">BAG s/ impressão</button>
              <button type="button" class="sc sc-bag"  onclick="setFluxo('Corte Bag > Impressao Bag > Costura Bag > Analise Bag')">BAG c/ impressão</button>
              <button type="button" class="sc sc-sac"  onclick="setFluxo('Corte+Costura Sacaria')">Sacaria s/ impressão</button>
              <button type="button" class="sc sc-sac"  onclick="setFluxo('Flexo > Corte+Costura Sacaria')">Sacaria Flexo</button>
              <button type="button" class="sc sc-sac"  onclick="setFluxo('Carimbadeira Sacaria > Corte+Costura Sacaria')">Sacaria Carimbo</button>
              <button type="button" class="sc sc-valv" onclick="setFluxo('Corte+Costura Sacaria > Valvuladeira')">Sacaria Valvulada</button>
            </div>
          </div>
        </div>

        </div><!-- /cad-body -->

        <div class="cad-footer">
          <a href="/produtos" class="btn-secondary">Cancelar</a>
          <button type="submit" class="btn-primary">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Salvar Alterações' : 'Salvar Produto' ?>
          </button>
        </div>
      </form>
    </div><!-- /cad-card -->
  </div><!-- /content -->
</div>
</div>

<script>
function setFluxo(v) {
  document.getElementById('fluxoInput').value = v;
}

const alertEl = document.getElementById('alertEl');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.remove(), 400);
}, 5000);
</script>
</body>
</html>
