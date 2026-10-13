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

// ── Assistente IA: só administradores ────────────────────────────────────────
$st = $pdo->prepare('SELECT USU_ADMIN FROM USUARIOS WHERE USU_CODIGO = ?');
$st->execute([usuCodigo()]);
$ehAdmin = !empty($st->fetchColumn());
if (empty($_SESSION['par_csrf'])) {
    $_SESSION['par_csrf'] = bin2hex(random_bytes(32));
}
$ia_erros = [];

// Teste de conexão com o provedor (AJAX, só admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aba'] ?? '') === 'ia_testar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$ehAdmin || !hash_equals($_SESSION['par_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Sem permissão.']);
        exit;
    }
    echo json_encode(iaTestarConexao(), JSON_UNESCAPED_UNICODE);
    exit;
}

// Consulta só os metadados do modelo configurado (não envia dados da empresa).
function iaTestarConexao(): array
{
    $gemini = (dombagConfig('CHAT_PROVEDOR') ?: (dombagConfig('GEMINI_API_KEY') ? 'gemini' : 'anthropic')) === 'gemini';
    $chave  = dombagConfig($gemini ? 'GEMINI_API_KEY' : 'ANTHROPIC_API_KEY');
    $modelo = $gemini ? dombagConfig('GEMINI_MODEL', 'gemini-3.5-flash-lite') : dombagConfig('CLAUDE_MODEL', 'claude-sonnet-5-5');
    if (!$chave) {
        return ['ok' => false, 'msg' => 'Nenhuma chave configurada para o provedor ' . ($gemini ? 'Gemini' : 'Claude') . '.'];
    }
    $ch = curl_init($gemini
        ? 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelo)
        : 'https://api.anthropic.com/v1/models/' . rawurlencode($modelo));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $gemini ? ['x-goog-api-key: ' . $chave] : ['x-api-key: ' . $chave, 'anthropic-version: 2023-06-01'],
    ]);
    $corpo  = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);
    if ($corpo === false) {
        return ['ok' => false, 'msg' => 'Sem conexão com o provedor: ' . $erro];
    }
    return match (true) {
        $codigo === 200            => ['ok' => true,  'msg' => 'Conectado. Modelo ' . $modelo . ' disponível para esta chave.'],
        in_array($codigo, [401, 403], true) || ($codigo === 400 && str_contains((string) $corpo, 'API_KEY')) => ['ok' => false, 'msg' => 'Chave recusada pelo provedor (inválida ou sem permissão).'],
        $codigo === 404            => ['ok' => false, 'msg' => 'A chave funciona, mas o modelo "' . $modelo . '" não existe ou não está liberado.'],
        $codigo === 429            => ['ok' => false, 'msg' => 'Chave válida, mas a cota está esgotada no momento.'],
        default                    => ['ok' => false, 'msg' => "O provedor respondeu HTTP {$codigo}."],
    };
}

// ── POST ──────────────────────────────────────────────────────────────────────
//  Todas as abas exigem o token CSRF. Sucesso → redireciona (PRG), pra que
//  atualizar a página (F5) não reenvie o formulário. Erro → mostra na hora.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aba = (string) ($_POST['aba'] ?? 'integracoes');

    if (!hash_equals($_SESSION['par_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $ia_erros[] = 'Sua sessão expirou. Recarregue a página e tente de novo.';
    } elseif ($aba === 'ia' && !$ehAdmin) {
        http_response_code(403);
        exit('Sem permissão.');
    } else {
        try {
            if ($aba === 'ia') {
                // Valida tudo antes de gravar qualquer coisa.
                $novos = [];
                foreach (DOMBAG_CONFIG_IA as $k => $d) {
                    $v = (string) ($_POST['ia'][$k] ?? '');
                    if ($d['tipo'] === 'segredo') {
                        if (!empty($_POST['ia_limpar'][$k])) {
                            $novos[$k] = '';
                        }
                        if (trim($v) === '') {
                            continue;                            // em branco = mantém a chave atual
                        }
                    }
                    [$norm, $erro] = dombagConfigValidar($k, $v);
                    if ($erro) {
                        $ia_erros[] = $erro;
                    } else {
                        $novos[$k] = $norm;
                    }
                }
                if (!$ia_erros) {
                    foreach ($novos as $k => $v) {
                        dombagConfigSalvar($k, $v);
                    }
                    // Confere se as chaves gravadas voltam legíveis (criptografia ok)
                    $lidas = dombagConfigTela(true);
                    foreach ($novos as $k => $v) {
                        if (DOMBAG_CONFIG_IA[$k]['tipo'] === 'segredo' && $v !== '' && ($lidas[$k] ?? '') !== $v) {
                            $ia_erros[] = 'A chave "' . DOMBAG_CONFIG_IA[$k]['rotulo'] . '" foi gravada, mas não pôde ser lida de volta.';
                        }
                    }
                }
            } elseif ($aba === 'integracoes') {
                // Credenciais: em branco mantém a atual; "remover" apaga.
                foreach (['trello_key' => 'trello_api_key', 'trello_token' => 'trello_token'] as $campo => $chave) {
                    $v = trim((string) ($_POST[$campo] ?? ''));
                    if (!empty($_POST['limpar'][$campo])) {
                        setParam($pdo, $chave, '');
                    } elseif ($v !== '') {
                        setParam($pdo, $chave, $v);
                    }
                }
                setParam($pdo, 'trello_default_board', trim((string) ($_POST['trello_default_board'] ?? '')));
            } elseif ($aba === 'aparencia') {
                $tema = in_array($_POST['tema'] ?? '', ['claro', 'escuro'], true) ? $_POST['tema'] : 'escuro';
                setParam($pdo, 'tema_sistema', $tema);
            }
        } catch (Throwable $e) {
            error_log('[PARAMETROS] ' . $e->getMessage());
            $ia_erros[] = 'Erro ao salvar: ' . $e->getMessage();
        }
    }

    if (!$ia_erros) {
        $_SESSION['par_flash'] = 'Parâmetros salvos com sucesso.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?aba=' . rawurlencode($aba));
        exit;
    }
    $msg      = 'Nada foi salvo. ' . htmlspecialchars(implode(' ', $ia_erros));
    $msg_tipo = 'err';
} elseif (!empty($_SESSION['par_flash'])) {
    $msg      = htmlspecialchars($_SESSION['par_flash']);
    $msg_tipo = 'ok';
    unset($_SESSION['par_flash']);
}

// ── Leitura atual ─────────────────────────────────────────────────────────────
$trello_key           = getParam($pdo, 'trello_api_key');
$trello_token         = getParam($pdo, 'trello_token');
$trello_default_board = getParam($pdo, 'trello_default_board');
// Mesmo padrão do resto do sistema (dombagTema): sem parâmetro salvo = escuro
$tema                 = getParam($pdo, 'tema_sistema', dombagTema());

$aba_ativa  = $_POST['aba'] ?? ($_GET['aba'] ?? 'integracoes');
if (!in_array($aba_ativa, $ehAdmin ? ['integracoes', 'aparencia', 'ia'] : ['integracoes', 'aparencia'])) $aba_ativa = 'integracoes';
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
/* ── Aba Assistente IA ── */
.ia-intro { font-size: 12.5px; color: var(--text-muted); margin-bottom: 18px; max-width: 820px; line-height: 1.6; }
.ia-intro a { color: #7db3ff; }
.ia-grupo { padding: 16px 0; border-top: 1px solid var(--border); }
.ia-grupo:first-of-type { border-top: 0; padding-top: 0; }
.ia-grupo-t { font-size: 13px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; }
.ia-campos { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px 22px; max-width: 1000px; }
.ia-meta { display: flex; flex-direction: column; gap: 2px; font-size: 11px; color: var(--text-muted); margin-top: 5px; }
.ia-origem { font-weight: 600; }
.ia-origem.ok  { color: var(--teal); }
.ia-origem.arq { color: #7db3ff; }
.ia-origem.amb { color: var(--amber); }
.ia-limpar { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--text-muted); margin-top: 6px; font-weight: 400; }
.ia-aviso { margin-top: 8px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg);
            font-size: 12px; color: var(--text-muted); line-height: 1.6; max-width: 1000px; }
.ia-aviso b { color: var(--text-primary); }
.ia-ilegivel { margin-top: 6px; padding: 7px 10px; border-radius: 6px; font-size: 11.5px; line-height: 1.5;
               color: var(--amber); background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.3); }
.ia-teste { font-size: 12px; margin-right: auto; margin-left: 10px; }
.trello-status { font-size: 11px; margin-top: 6px; }
.trello-status.err { color: var(--red); }
.ia-teste.ok  { color: var(--teal); }
.ia-teste.err { color: var(--red); }
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
      <div class="topbar-actions">
        <!-- Salva a aba aberta (cada aba é um formulário próprio) -->
        <button type="button" class="btn-primary" id="btnSalvar" onclick="salvarAbaAtiva()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
            <polyline points="17 21 17 13 7 13 7 21"/>
            <polyline points="7 3 7 8 15 8"/>
          </svg>
          Salvar
        </button>
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
                  data-aba="integracoes" onclick="trocarAba('integracoes')" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
              <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
            </svg>
            Integrações
          </button>
          <button class="tab-btn <?= $aba_ativa === 'aparencia' ? 'active' : '' ?>"
                  data-aba="aparencia" onclick="trocarAba('aparencia')" type="button">
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
          <?php if ($ehAdmin): ?>
          <button class="tab-btn <?= $aba_ativa === 'ia' ? 'active' : '' ?>"
                  data-aba="ia" onclick="trocarAba('ia')" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8"/><path d="M8 13h5"/>
            </svg>
            Assistente IA
          </button>
          <?php endif; ?>
        </div>

        <!-- ── Aba: Integrações ──────────────────────────────────────────── -->
        <div class="tab-pane <?= $aba_ativa === 'integracoes' ? 'active' : '' ?>" id="pane-integracoes">
          <form method="POST" class="par-form">
            <input type="hidden" name="aba" value="integracoes">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['par_csrf']) ?>">
            <div class="form-body">
              <!-- Trello (usado pelo Dashboard e pela tela Pedidos/Trello) -->
              <div>
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
                           value=""
                           placeholder="<?= $trello_key !== '' ? 'Atual: ' . htmlspecialchars(dombagMascarar($trello_key)) . ' — em branco mantém' : 'Cole a API Key aqui' ?>"
                           autocomplete="new-password"
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
                  <?php if ($trello_key !== ''): ?>
                    <label class="ia-limpar"><input type="checkbox" name="limpar[trello_key]" value="1"> Remover a API Key salva</label>
                  <?php endif; ?>
                </div>
                <div class="field" style="max-width:480px;">
                  <label>Token</label>
                  <div class="api-key-wrap">
                    <input type="password"
                           name="trello_token"
                           id="trello_token"
                           value=""
                           placeholder="<?= $trello_token !== '' ? 'Atual: ' . htmlspecialchars(dombagMascarar($trello_token)) . ' — em branco mantém' : 'Cole o Token aqui' ?>"
                           autocomplete="new-password"
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
                  <?php if ($trello_token !== ''): ?>
                    <label class="ia-limpar"><input type="checkbox" name="limpar[trello_token]" value="1"> Remover o Token salvo</label>
                  <?php endif; ?>
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
                  <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">Board cujos dados aparecem no Dashboard. Usa as credenciais já salvas — se alterou a API Key ou o Token, salve antes de carregar.</p>
                  <div class="trello-status" id="trelloStatus"></div>
                </div>
              </div>
            </div>
          </form>
        </div>

        <!-- ── Aba: Aparência ────────────────────────────────────────────── -->
        <div class="tab-pane <?= $aba_ativa === 'aparencia' ? 'active' : '' ?>" id="pane-aparencia">
          <form method="POST" class="par-form">
            <input type="hidden" name="aba" value="aparencia">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['par_csrf']) ?>">
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
          </form>
        </div>

        <?php if ($ehAdmin):
            $origemTxt = [
                'tela'     => ['Salvo nesta tela', 'ok'],
                'arquivo'  => ['Vindo do arquivo dombag.env.php', 'arq'],
                'ambiente' => ['Variável de ambiente do servidor — tem prioridade, altere no servidor', 'amb'],
                'padrao'   => ['Padrão do sistema', 'pad'],
            ];
            $telaIA = dombagConfigTela();
            $iaIlegiveis = dombagConfigIlegiveis();
        ?>
        <!-- ── Aba: Assistente IA (só admin) ─────────────────────────────── -->
        <div class="tab-pane <?= $aba_ativa === 'ia' ? 'active' : '' ?>" id="pane-ia">
          <form method="POST" class="par-form" autocomplete="off">
            <input type="hidden" name="aba" value="ia">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['par_csrf']) ?>">
            <div class="form-body">
              <p class="ia-intro">
                Configurações do <a href="/assistente">Assistente IA</a>. Valem na hora para todos os usuários.
                Campos em branco usam o valor do arquivo <code>dombag.env.php</code> ou o padrão do sistema.
                As chaves são guardadas criptografadas e nunca são exibidas inteiras.
              </p>

              <?php foreach (DOMBAG_CONFIG_IA_GRUPOS as $g => $tituloGrupo): ?>
              <div class="ia-grupo">
                <div class="ia-grupo-t"><?= htmlspecialchars($tituloGrupo) ?></div>
                <div class="ia-campos">
                <?php foreach (DOMBAG_CONFIG_IA as $k => $d):
                    if ($d['grupo'] !== $g) continue;
                    $origem  = dombagConfigOrigem($k);
                    $efetivo = dombagConfig($k, $d['padrao'] ?? null);
                    $naTela  = $telaIA[$k] ?? '';
                    $id      = 'ia_' . strtolower($k);
                ?>
                  <div class="field">
                    <label for="<?= $id ?>"><?= htmlspecialchars($d['rotulo']) ?></label>
                    <?php if ($d['tipo'] === 'opcao'): ?>
                      <select name="ia[<?= $k ?>]" id="<?= $id ?>">
                        <option value=""><?= $k === 'CHAT_PROVEDOR' ? 'Automático (Gemini, se houver chave)' : 'Padrão' ?><?= $origem !== 'tela' && $efetivo ? ' — atual: ' . htmlspecialchars($d['opcoes'][$efetivo] ?? $efetivo) : '' ?></option>
                        <?php foreach ($d['opcoes'] as $ov => $ol): ?>
                          <option value="<?= htmlspecialchars($ov) ?>" <?= $naTela === $ov ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($d['tipo'] === 'segredo'): ?>
                      <div class="api-key-wrap">
                        <input type="password" name="ia[<?= $k ?>]" id="<?= $id ?>" value="" maxlength="300" autocomplete="new-password"
                               placeholder="<?= $efetivo ? 'Atual: ' . htmlspecialchars(dombagMascarar($efetivo)) . ' — em branco mantém' : 'Cole a chave aqui' ?>">
                      </div>
                      <?php if (in_array($k, $iaIlegiveis, true)): ?>
                        <div class="ia-ilegivel">Há uma chave salva, mas este servidor não consegue lê-la (foi salva com outra chave de criptografia, ex.: em outro computador). Cole a chave de novo e salve.</div>
                      <?php endif; ?>
                      <?php if ($naTela !== '' || in_array($k, $iaIlegiveis, true)): ?>
                        <label class="ia-limpar"><input type="checkbox" name="ia_limpar[<?= $k ?>]" value="1"> Remover a chave salva nesta tela</label>
                      <?php endif; ?>
                    <?php else: ?>
                      <input type="<?= $d['tipo'] === 'inteiro' ? 'number' : 'text' ?>" name="ia[<?= $k ?>]" id="<?= $id ?>"
                             value="<?= htmlspecialchars($naTela) ?>"
                             <?= $d['tipo'] === 'inteiro' ? 'min="' . $d['min'] . '" max="' . $d['max'] . '"' : 'maxlength="200"' ?>
                             <?= !empty($d['sugestoes']) ? 'list="' . $id . '_lista"' : '' ?>
                             placeholder="<?= $efetivo !== null && $efetivo !== '' ? 'Atual: ' . htmlspecialchars($efetivo) : '' ?>">
                      <?php if (!empty($d['sugestoes'])): ?>
                        <datalist id="<?= $id ?>_lista"><?php foreach ($d['sugestoes'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?></datalist>
                      <?php endif; ?>
                    <?php endif; ?>
                    <div class="ia-meta">
                      <span class="ia-origem <?= $origemTxt[$origem][1] ?>"><?= $origemTxt[$origem][0] ?></span>
                      <?php if (!empty($d['ajuda'])): ?><span><?= htmlspecialchars($d['ajuda']) ?></span><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>

              <div class="ia-aviso">
                <b>Ficam só no arquivo <code>dombag.env.php</code>:</b> as credenciais dos bancos (MySQL e ERP).
                O sistema precisa delas para se conectar ao banco antes de conseguir ler esta tela.<br>
                A chave que criptografa os segredos fica no arquivo <code>dombag.chave</code>, ao lado do <code>dombag.env.php</code>.
                Se ele for apagado, as chaves de API salvas aqui precisam ser digitadas de novo.<br>
                <b>Mais de um servidor no mesmo banco</b> (ex.: XAMPP local e produção): a chave salva num deles só é
                lida pelo outro se os dois usarem o mesmo <code>DOMBAG_CHAVE_APP</code> no <code>dombag.env.php</code>.
                Na dúvida, salve as chaves pela tela do servidor de produção.
              </div>
            </div>
            <div class="form-actions">
              <button type="button" class="btn-secondary" id="btnTestarIA" onclick="testarIA()">Testar conexão</button>
              <span id="testeIA" class="ia-teste"></span>
            </div>
          </form>
        </div>
        <?php endif; ?>

      </div><!-- /panel -->
    </div><!-- /content -->
  </div>
</div>

<script>
// Testa a configuração SALVA (consulta só os dados do modelo no provedor).
function testarIA() {
  const btn = document.getElementById('btnTestarIA'), out = document.getElementById('testeIA');
  const csrf = document.querySelector('#pane-ia input[name="csrf"]').value;
  btn.disabled = true; out.className = 'ia-teste'; out.textContent = 'Testando a configuração salva…';
  fetch(location.pathname, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ aba: 'ia_testar', csrf })
  }).then(r => r.json()).then(j => {
    out.className = 'ia-teste ' + (j.ok ? 'ok' : 'err');
    out.textContent = (j.ok ? '✓ ' : '✕ ') + j.msg;
  }).catch(() => { out.className = 'ia-teste err'; out.textContent = '✕ Falha ao testar.'; })
    .finally(() => { btn.disabled = false; });
}

// Pelo data-aba (antes procurava 'ia' dentro do onclick e acendia a aba
// "Aparência", que também contém "ia"). Guarda a aba na URL pra um F5 voltar nela.
function trocarAba(id) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.aba === id));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + id));
  try { history.replaceState(null, '', location.pathname + '?aba=' + encodeURIComponent(id)); } catch (e) {}
}

// Botão Salvar da topbar: envia o formulário da aba aberta. requestSubmit
// (e não submit) pra respeitar a validação dos campos (min/max etc.).
function salvarAbaAtiva() {
  const form = document.querySelector('.tab-pane.active form.par-form');
  if (!form) return;
  if (form.requestSubmit) form.requestSubmit(); else form.submit();
}
// Sem botão submit dentro dos formulários, o Enter num campo deixaria de salvar
document.querySelectorAll('form.par-form').forEach(f => f.addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'checkbox' && e.target.type !== 'radio') {
    e.preventDefault();
    salvarAbaAtiva();
  }
}));
document.querySelectorAll('form.par-form').forEach(f => f.addEventListener('submit', () => {
  const b = document.getElementById('btnSalvar');
  b.disabled = true;
  b.lastChild.textContent = ' Salvando…';
}));

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

// Usa as credenciais SALVAS (o proxy lê do banco). silencioso = carga
// automática ao abrir a página: erro vira texto discreto, sem alert().
function carregarBoards(silencioso) {
  const btn = document.getElementById('btnCarregarBoards');
  const sel = document.getElementById('trello_default_board');
  const status = document.getElementById('trelloStatus');
  const currentVal = sel.value;

  btn.disabled = true;
  btn.textContent = 'Carregando…';
  status.textContent = '';
  status.className = 'trello-status';

  const falhou = (txt) => {
    status.textContent = txt;
    status.className = 'trello-status err';
    if (silencioso !== true) alert(txt);
  };

  fetch('/modules/trello/api.php?action=my_boards', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(boards => {
      if (!Array.isArray(boards)) {
        falhou(boards && boards.error === 'not_configured'
          ? 'Salve a API Key e o Token antes de carregar os boards.'
          : 'Não foi possível carregar os boards. Verifique a API Key e o Token salvos.');
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
      // Board salvo que não existe mais (arquivado/sem acesso): mantém visível e avisa
      if (currentVal && !boards.some(b => b.id === currentVal)) {
        const opt = document.createElement('option');
        opt.value = currentVal;
        opt.textContent = 'Board salvo (não encontrado no Trello)';
        opt.selected = true;
        sel.appendChild(opt);
        status.textContent = 'O board salvo não está mais acessível com estas credenciais.';
        status.className = 'trello-status err';
      }
    })
    .catch(() => falhou('Erro ao comunicar com a API do Trello.'))
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Carregar boards';
    });
}

// Carrega boards automaticamente se já houver credenciais salvas
<?php if ($trello_key && $trello_token): ?>
document.addEventListener('DOMContentLoaded', () => carregarBoards(true));
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
