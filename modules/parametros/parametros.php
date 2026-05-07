<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Parâmetros do Sistema
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── Schema ────────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS PARAMETROS (
        PAR_CHAVE   VARCHAR(100) NOT NULL PRIMARY KEY,
        PAR_VALOR   TEXT         NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Helper ────────────────────────────────────────────────────────────────────
function getParam(PDO $pdo, string $chave, string $default = ''): string
{
    $st = $pdo->prepare('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = :k');
    $st->execute([':k' => $chave]);
    $v = $st->fetchColumn();
    return $v !== false ? (string)$v : $default;
}

function setParam(PDO $pdo, string $chave, string $valor): void
{
    $pdo->prepare('
        INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE PAR_VALOR = :v2
    ')->execute([':k' => $chave, ':v' => $valor, ':v2' => $valor]);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aba = $_POST['aba'] ?? 'integracoes';

    if ($aba === 'integracoes') {
        $openai_key          = trim($_POST['openai_key']          ?? '');
        $trello_key          = trim($_POST['trello_key']          ?? '');
        $trello_token        = trim($_POST['trello_token']        ?? '');
        $trello_default_board = trim($_POST['trello_default_board'] ?? '');
        setParam($pdo, 'openai_api_key',      $openai_key);
        setParam($pdo, 'trello_api_key',      $trello_key);
        setParam($pdo, 'trello_token',        $trello_token);
        setParam($pdo, 'trello_default_board', $trello_default_board);
    } elseif ($aba === 'aparencia') {
        $tema = in_array($_POST['tema'] ?? '', ['claro', 'escuro']) ? $_POST['tema'] : 'claro';
        setParam($pdo, 'tema_sistema', $tema);
    } elseif ($aba === 'crm') {
        $gru_leads = (int)($_POST['gru_leads'] ?? 0);
        setParam($pdo, 'crm_gru_leads', (string)$gru_leads);
    }

    $msg      = 'Parâmetros salvos com sucesso.';
    $msg_tipo = 'ok';
}

// ── Leitura atual ─────────────────────────────────────────────────────────────
$openai_key           = getParam($pdo, 'openai_api_key');
$trello_key           = getParam($pdo, 'trello_api_key');
$trello_token         = getParam($pdo, 'trello_token');
$trello_default_board = getParam($pdo, 'trello_default_board');
$tema                 = getParam($pdo, 'tema_sistema', 'claro');
$gru_leads  = (int)getParam($pdo, 'crm_gru_leads', '0');

$grupos_usuario = $pdo->query('SELECT GRU_CODIGO, GRU_NOME FROM GRUPO_USUARIO ORDER BY GRU_NOME')
                      ->fetchAll(PDO::FETCH_ASSOC);

$aba_ativa  = $_POST['aba'] ?? ($_GET['aba'] ?? 'integracoes');
if (!in_array($aba_ativa, ['integracoes', 'aparencia', 'crm'])) $aba_ativa = 'integracoes';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Parâmetros | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
/* ── Abas ─────────────────────────────────────────────────────────────────── */
.tabs-bar {
  display: flex;
  gap: 2px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 20px;
}
.tab-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 10px 18px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted);
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  cursor: pointer;
  transition: color .15s, border-color .15s;
  margin-bottom: -1px;
  font-family: inherit;
}
.tab-btn:hover { color: var(--text-primary); }
.tab-btn.active {
  color: #7db3ff;
  border-bottom-color: #4f7bff;
}
.tab-pane { display: none; }
.tab-pane.active { display: block; }

/* ── API Key ──────────────────────────────────────────────────────────────── */
.api-key-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.api-key-wrap input {
  padding-right: 42px !important;
  font-family: 'Courier New', monospace;
  font-size: 12px;
  letter-spacing: .04em;
}
.btn-toggle-vis {
  position: absolute;
  right: 10px;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--text-muted);
  padding: 4px;
  display: flex;
  align-items: center;
  transition: color .15s;
}
.btn-toggle-vis:hover { color: var(--text-primary); }

/* ── Toggle de tema ───────────────────────────────────────────────────────── */
.theme-toggle {
  display: flex;
  gap: 10px;
  margin-top: 4px;
  max-width: 420px;
}
.theme-opt {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: 10px;
  border: 1.5px solid var(--border);
  cursor: pointer;
  transition: border-color .15s, background .15s;
  background: transparent;
}
.theme-opt:has(input:checked) {
  border-color: #4f7bff;
  background: rgba(79,123,255,.08);
}
.theme-opt input[type=radio] {
  accent-color: #4f7bff;
  width: 16px;
  height: 16px;
  flex-shrink: 0;
}
.theme-opt-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.theme-opt-icon.light { background: rgba(255,255,255,.12); }
.theme-opt-icon.dark  { background: rgba(0,0,0,.25); }
.theme-opt-label { font-size: 13px; font-weight: 600; }
.theme-opt-sub   { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

@media (max-width: 768px) {
  .theme-toggle { flex-direction: column; max-width: 100%; }
  .api-key-wrap input { font-size: 11px; }
}
@media (max-width: 480px) {
  .tab-btn { font-size: 11px; padding: 7px 10px; gap: 5px; }
  .tab-btn svg { width: 14px; height: 14px; }
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
          <h1>Parâmetros do Sistema</h1>
          <p>Configure integrações e preferências globais do sistema</p>
        </div>
      </div>
    </header>

    <div class="content">

      <?php if ($msg): ?>
      <div class="alert <?= $msg_tipo === 'ok' ? 'alert-ok' : 'alert-err' ?>" id="alertMsg">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <?= $msg_tipo === 'ok'
            ? '<polyline points="20 6 9 17 4 12"/>'
            : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' ?>
        </svg>
        <?= $msg ?>
      </div>
      <?php endif; ?>

      <div class="panel">

        <!-- ── Abas ─────────────────────────────────────────────────────── -->
        <div class="tabs-bar">
          <button class="tab-btn <?= $aba_ativa === 'integracoes' ? 'active' : '' ?>"
                  onclick="trocarAba('integracoes')" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
              <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
            </svg>
            Integrações
          </button>
          <button class="tab-btn <?= $aba_ativa === 'aparencia' ? 'active' : '' ?>"
                  onclick="trocarAba('aparencia')" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="5"/>
              <line x1="12" y1="1"  x2="12" y2="3"/>
              <line x1="12" y1="21" x2="12" y2="23"/>
              <line x1="4.22" y1="4.22"  x2="5.64" y2="5.64"/>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
              <line x1="1" y1="12" x2="3" y2="12"/>
              <line x1="21" y1="12" x2="23" y2="12"/>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            Aparência
          </button>
          <button class="tab-btn <?= $aba_ativa === 'crm' ? 'active' : '' ?>"
                  onclick="trocarAba('crm')" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.5 7h13M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
            </svg>
            CRM
          </button>
        </div>

        <!-- ── Aba: Integrações ──────────────────────────────────────────── -->
        <div class="tab-pane <?= $aba_ativa === 'integracoes' ? 'active' : '' ?>" id="pane-integracoes">
          <form method="POST">
            <input type="hidden" name="aba" value="integracoes">
            <div class="form-body">
              <!-- OpenAI -->
              <div class="field" style="max-width:480px;">
                <label>Chave de API OpenAI</label>
                <div class="api-key-wrap">
                  <input type="password"
                         name="openai_key"
                         id="openai_key"
                         value="<?= htmlspecialchars($openai_key) ?>"
                         placeholder="sk-..."
                         autocomplete="off"
                         maxlength="200">
                  <button type="button" class="btn-toggle-vis" onclick="toggleVis('openai_key','iconEye','iconEyeOff')" title="Mostrar/ocultar chave">
                    <svg id="iconEye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                      <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="iconEyeOff" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:none;">
                      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                      <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                      <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Trello -->
              <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="color:#7db3ff;">
                    <rect x="3" y="3" width="7" height="18" rx="2"/>
                    <rect x="14" y="3" width="7" height="11" rx="2"/>
                  </svg>
                  <span style="font-size:13px;font-weight:700;color:var(--text-primary);">Trello</span>
                  <span style="font-size:11px;color:var(--text-muted);">— <a href="https://trello.com/app-key" target="_blank" style="color:#7db3ff;">Obter credenciais</a></span>
                </div>
                <div class="field" style="max-width:480px;margin-bottom:14px;">
                  <label>API Key</label>
                  <div class="api-key-wrap">
                    <input type="password"
                           name="trello_key"
                           id="trello_key"
                           value="<?= htmlspecialchars($trello_key) ?>"
                           placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                           autocomplete="off"
                           maxlength="200">
                    <button type="button" class="btn-toggle-vis" onclick="toggleVis('trello_key','iconTrelloEye','iconTrelloEyeOff')" title="Mostrar/ocultar">
                      <svg id="iconTrelloEye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                      </svg>
                      <svg id="iconTrelloEyeOff" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:none;">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                      </svg>
                    </button>
                  </div>
                </div>
                <div class="field" style="max-width:480px;">
                  <label>Token</label>
                  <div class="api-key-wrap">
                    <input type="password"
                           name="trello_token"
                           id="trello_token"
                           value="<?= htmlspecialchars($trello_token) ?>"
                           placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                           autocomplete="off"
                           maxlength="200">
                    <button type="button" class="btn-toggle-vis" onclick="toggleVis('trello_token','iconTrelloTokenEye','iconTrelloTokenEyeOff')" title="Mostrar/ocultar">
                      <svg id="iconTrelloTokenEye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                      </svg>
                      <svg id="iconTrelloTokenEyeOff" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:none;">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                      </svg>
                    </button>
                  </div>
                </div>

                <!-- Board do Dashboard -->
                <div class="field" style="max-width:480px;margin-top:14px;">
                  <label>Board do Dashboard</label>
                  <div style="display:flex;gap:8px;align-items:center;">
                    <select name="trello_default_board" id="trello_default_board" style="flex:1;">
                      <option value="">— Nenhum —</option>
                      <?php if ($trello_default_board): ?>
                      <option value="<?= htmlspecialchars($trello_default_board) ?>" selected>
                        Board atual (<?= htmlspecialchars(substr($trello_default_board, 0, 12)) ?>…)
                      </option>
                      <?php endif; ?>
                    </select>
                    <button type="button" class="btn-secondary" id="btnCarregarBoards" onclick="carregarBoards()" style="white-space:nowrap;flex-shrink:0;">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                      Carregar boards
                    </button>
                  </div>
                  <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Board cujos dados aparecem no Dashboard. Guarde as credenciais antes de carregar.</p>
                </div>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                  <polyline points="17 21 17 13 7 13 7 21"/>
                  <polyline points="7 3 7 8 15 8"/>
                </svg>
                Salvar
              </button>
            </div>
          </form>
        </div>

        <!-- ── Aba: Aparência ────────────────────────────────────────────── -->
        <div class="tab-pane <?= $aba_ativa === 'aparencia' ? 'active' : '' ?>" id="pane-aparencia">
          <form method="POST">
            <input type="hidden" name="aba" value="aparencia">
            <div class="form-body">
              <div class="field">
                <label>Tema do Sistema</label>
                <div class="theme-toggle">
                  <label class="theme-opt">
                    <input type="radio" name="tema" value="claro" <?= $tema === 'claro' ? 'checked' : '' ?>>
                    <span class="theme-opt-icon light">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1"  x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22"  x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                      </svg>
                    </span>
                    <div>
                      <div class="theme-opt-label">Claro</div>
                      <div class="theme-opt-sub">Interface com fundo claro</div>
                    </div>
                  </label>
                  <label class="theme-opt">
                    <input type="radio" name="tema" value="escuro" <?= $tema === 'escuro' ? 'checked' : '' ?>>
                    <span class="theme-opt-icon dark">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                      </svg>
                    </span>
                    <div>
                      <div class="theme-opt-label">Escuro</div>
                      <div class="theme-opt-sub">Interface com fundo escuro</div>
                    </div>
                  </label>
                </div>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                  <polyline points="17 21 17 13 7 13 7 21"/>
                  <polyline points="7 3 7 8 15 8"/>
                </svg>
                Salvar
              </button>
            </div>
          </form>
        </div>

        <!-- ── Aba: CRM ───────────────────────────────────────────────── -->
        <div class="tab-pane <?= $aba_ativa === 'crm' ? 'active' : '' ?>" id="pane-crm">
          <form method="POST">
            <input type="hidden" name="aba" value="crm">
            <div class="form-body">
              <div class="field" style="max-width:360px;">
                <label>Grupo de usuário para destinar leads</label>
                <select name="gru_leads">
                  <option value="0">— Nenhum —</option>
                  <?php foreach ($grupos_usuario as $g): ?>
                  <option value="<?= $g['GRU_CODIGO'] ?>"
                          <?= $gru_leads === (int)$g['GRU_CODIGO'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($g['GRU_NOME']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                  <polyline points="17 21 17 13 7 13 7 21"/>
                  <polyline points="7 3 7 8 15 8"/>
                </svg>
                Salvar
              </button>
            </div>
          </form>
        </div>

      </div><!-- /panel -->
    </div><!-- /content -->
  </div>
</div>

<script>
function trocarAba(id) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelector('.tab-btn[onclick*="' + id + '"]').classList.add('active');
  document.getElementById('pane-' + id).classList.add('active');
}

function toggleVis(inputId, eyeId, eyeOffId) {
  const inp    = document.getElementById(inputId);
  const eye    = document.getElementById(eyeId);
  const eyeOff = document.getElementById(eyeOffId);
  if (inp.type === 'password') {
    inp.type = 'text';
    eye.style.display    = 'none';
    eyeOff.style.display = '';
  } else {
    inp.type = 'password';
    eye.style.display    = '';
    eyeOff.style.display = 'none';
  }
}

function carregarBoards() {
  const btn = document.getElementById('btnCarregarBoards');
  const sel = document.getElementById('trello_default_board');
  const currentVal = sel.value;

  btn.disabled = true;
  btn.textContent = 'Carregando…';

  fetch('/modules/trello/api.php?action=my_boards')
    .then(r => r.json())
    .then(boards => {
      if (!Array.isArray(boards) || boards.error) {
        alert('Não foi possível carregar os boards. Verifique a API Key e o Token.');
        return;
      }
      sel.innerHTML = '<option value="">— Nenhum —</option>';
      boards.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = b.name;
        if (b.id === currentVal) opt.selected = true;
        sel.appendChild(opt);
      });
      if (!currentVal && boards.length) sel.value = boards[0].id;
    })
    .catch(() => alert('Erro ao comunicar com a API do Trello.'))
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Carregar boards';
    });
}

// Carrega boards automaticamente se já houver credenciais salvas
<?php if ($trello_key && $trello_token): ?>
document.addEventListener('DOMContentLoaded', carregarBoards);
<?php endif; ?>

const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.style.display = 'none', 400);
}, 4000);
</script>
</body>
</html>
