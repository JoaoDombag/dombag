<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// Apenas admins
$pdo = dbPDO();
$row = $pdo->prepare('SELECT USU_ADMIN FROM USUARIOS WHERE USU_CODIGO = :c');
$row->execute([':c' => (int)($_SESSION['usu_codigo'] ?? 0)]);
$usu = $row->fetch(PDO::FETCH_ASSOC);
if (empty($usu['USU_ADMIN'])) {
    http_response_code(403);
    exit('Acesso negado.');
}

// ── Definição de todos os itens configuráveis ─────────────────────────────────
// Estrutura espelha exatamente o $grupos do sidebar.php + standalone items
$todosMenus = [
    [
        'grupo_key' => 'standalone:dashboard',
        'grupo_label' => 'Dashboard',
        'itens' => [],
    ],
    [
        'grupo_key' => 'standalone:produtos',
        'grupo_label' => 'Produtos',
        'itens' => [],
    ],
    [
        'grupo_key' => 'grupo:vendas',
        'grupo_label' => 'Vendas',
        'itens' => [
            ['key' => '/vendas',            'label' => 'Pedidos de Venda'],
            ['key' => '/vendas/simulacao',  'label' => 'Simulação'],
        ],
    ],
    [
        'grupo_key' => 'grupo:yzidro',
        'grupo_label' => 'Yzidro',
        'itens' => [
            ['key' => '/yzidro/pedidos',    'label' => 'Pedidos de Venda'],
            ['key' => '/yzidro/consulta',   'label' => 'Consulta Vendas'],
        ],
    ],
    [
        'grupo_key' => 'grupo:producao',
        'grupo_label' => 'Produção',
        'itens' => [
            ['key' => '/pcp/reuniao',       'label' => 'Reunião de Planejamento'],
            ['key' => '/pcp/kanban',        'label' => 'Kanban de Pedidos'],
            ['key' => '/pcp/planejamento',  'label' => 'Planejamento'],
            ['key' => '/pcp/ordens',        'label' => 'Ordens de Produção'],
            ['key' => '/pcp/programacao',   'label' => 'Programação Diária'],
            ['key' => '/pcp/producao',      'label' => 'Produção'],
        ],
    ],
    [
        'grupo_key' => 'grupo:maquinas',
        'grupo_label' => 'Máquinas',
        'itens' => [
            ['key' => '/maquinas',          'label' => 'Cadastro de Máquinas'],
            ['key' => '/pcp/fila',          'label' => 'Fila de Máquinas'],
        ],
    ],
    [
        'grupo_key' => 'grupo:financeiro',
        'grupo_label' => 'Financeiro',
        'itens' => [
            ['key' => '/financeiro',              'label' => 'Dashboard'],
            ['key' => '/financeiro/receber',      'label' => 'Contas a Receber'],
            ['key' => '/financeiro/pagar',        'label' => 'Contas a Pagar'],
            ['key' => '/financeiro/baixas-pagar', 'label' => 'Baixas a Pagar'],
        ],
    ],
    [
        'grupo_key' => 'grupo:crm',
        'grupo_label' => 'CRM',
        'itens' => [
            ['key' => '/modules/crm/qualificacao_leads.php', 'label' => 'Leads do Webhook'],
            ['key' => '/modules/crm/leads_resultados.php',   'label' => 'Prospecção com IA'],
        ],
    ],
    [
        'grupo_key' => 'grupo:trello',
        'grupo_label' => 'Trello',
        'itens' => [
            ['key' => '/trello', 'label' => 'Pedidos'],
        ],
    ],
    [
        'grupo_key' => 'grupo:relatorios',
        'grupo_label' => 'Relatórios',
        'itens' => [
            ['key' => '/relatorios/producao',          'label' => 'Relatório de Produção'],
            ['key' => '/relatorios/estoque-futuro',    'label' => 'Estoque Futuro'],
            ['key' => '/relatorios/posicao-estoque',   'label' => 'Posição de Estoque'],
            ['key' => '/relatorios/controle-estoque',  'label' => 'Controle de Estoque'],
        ],
    ],
    [
        'grupo_key' => 'grupo:usuarios',
        'grupo_label' => 'Usuários',
        'itens' => [
            ['key' => '/usuarios',                         'label' => 'Cadastro de Usuários'],
            ['key' => '/modules/usuarios/cad_grupos.php',  'label' => 'Grupos de Usuários'],
        ],
    ],
    [
        'grupo_key' => 'grupo:parametros',
        'grupo_label' => 'Parâmetros',
        'itens' => [
            ['key' => '/modules/parametros/parametros.php', 'label' => 'Parâmetros do Sistema'],
            ['key' => '/dashboard/widgets',                 'label' => 'Widgets do Dashboard'],
        ],
    ],
];

// Coleta todas as keys possíveis
function allKeys(array $todosMenus): array {
    $keys = [];
    foreach ($todosMenus as $g) {
        $keys[] = $g['grupo_key'];
        foreach ($g['itens'] as $item) {
            $keys[] = $item['key'];
        }
    }
    return $keys;
}

// ── Carrega desativados atuais ────────────────────────────────────────────────
$desativados = [];
try {
    $st = $pdo->prepare("SELECT PAR_VALOR FROM parametros WHERE PAR_CHAVE = 'sidebar_menus_desativados'");
    $st->execute();
    $val = $st->fetchColumn();
    if ($val) $desativados = json_decode($val, true) ?? [];
} catch (Throwable) {}

$msg = '';
$msgType = '';

// ── POST: salvar ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar'])) {
    $allK = allKeys($todosMenus);
    $ativos = array_keys(array_filter($_POST['menu'] ?? [], fn($v) => $v === '1'));
    $novosDesativados = array_values(array_diff($allK, $ativos));

    try {
        $upsert = $pdo->prepare("
            INSERT INTO parametros (PAR_CHAVE, PAR_VALOR) VALUES ('sidebar_menus_desativados', :v)
            ON DUPLICATE KEY UPDATE PAR_VALOR = VALUES(PAR_VALOR)
        ");
        $upsert->execute([':v' => json_encode($novosDesativados, JSON_UNESCAPED_UNICODE)]);
        $desativados = $novosDesativados;
        $msg = 'Configuração salva com sucesso.';
        $msgType = 'ok';
    } catch (Throwable $e) {
        $msg = 'Erro ao salvar: ' . $e->getMessage();
        $msgType = 'err';
    }
}

function isAtivo(string $key, array $desativados): bool {
    return !in_array($key, $desativados, true);
}
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Menus Ativos | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--blue-deep);color:var(--text-primary);overflow:hidden;height:100vh;}
.app-wrapper{display:flex;height:100vh;overflow:hidden;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
.topbar{padding:14px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--blue-mid);flex-shrink:0;gap:12px;}
.page-title h1{font-size:17px;font-weight:600;letter-spacing:-.2px;}
.page-title p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.topbar-actions{display:flex;align-items:center;gap:10px;}
.btn-primary{background:var(--blue-accent);color:#fff;border:none;padding:8px 20px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Segoe UI',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .15s;}
.btn-primary:hover{background:var(--blue-light);}
.btn-secondary{background:transparent;color:var(--text-muted);border:1px solid var(--border);padding:8px 14px;border-radius:7px;font-size:13px;font-family:'Segoe UI',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.btn-secondary:hover{background:var(--card-hover);color:var(--text-primary);}
.content{flex:1;overflow-y:auto;padding:24px;}
.content::-webkit-scrollbar{width:4px;}
.content::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}

.alert{border-radius:8px;padding:10px 16px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#22c55e;}
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}

.menus-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;}

.menu-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border-color .15s;}
.menu-card.desativado{opacity:.6;}
.menu-card-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;}
.menu-card-header:hover{background:var(--card-hover);}
.menu-card-label{font-size:13px;font-weight:600;flex:1;}
.menu-card-toggle{position:relative;display:inline-flex;align-items:center;flex-shrink:0;}
.menu-card-toggle input[type=checkbox]{position:absolute;opacity:0;width:0;height:0;}
.toggle-track{width:36px;height:20px;border-radius:999px;background:rgba(255,255,255,.1);border:1px solid var(--border);transition:background .2s,border-color .2s;display:flex;align-items:center;padding:2px;}
.toggle-thumb{width:14px;height:14px;border-radius:999px;background:var(--text-muted);transition:transform .2s,background .2s;}
.menu-card-toggle input:checked + .toggle-track{background:rgba(34,197,94,.25);border-color:rgba(34,197,94,.4);}
.menu-card-toggle input:checked + .toggle-track .toggle-thumb{transform:translateX(16px);background:#22c55e;}

.menu-items{padding:8px 0;}
.menu-item-row{display:flex;align-items:center;gap:10px;padding:8px 16px;cursor:pointer;transition:background .12s;}
.menu-item-row:hover{background:var(--card-hover);}
.menu-item-row label{font-size:12.5px;color:var(--text-muted);cursor:pointer;flex:1;}
.menu-item-row input[type=checkbox]{width:14px;height:14px;accent-color:#7db3ff;cursor:pointer;flex-shrink:0;}
.item-dot{width:5px;height:5px;border-radius:999px;background:var(--border);flex-shrink:0;}

.tip{font-size:11.5px;color:var(--text-muted);margin-bottom:18px;}

@media(max-width:700px){.menus-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="page-title">
        <h1>Menus Ativos</h1>
        <p>Defina quais páginas aparecem na barra lateral para todos os usuários</p>
      </div>
      <div class="topbar-actions">
        <button type="button" class="btn-secondary" onclick="toggleTodos(true)">Ativar todos</button>
        <button type="button" class="btn-secondary" onclick="toggleTodos(false)">Desativar todos</button>
        <button type="submit" form="formMenus" class="btn-primary" name="salvar">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13"/><polyline points="7 3 7 8 15 8"/></svg>
          Salvar
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>">
        <?= $msgType === 'ok'
          ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>'
          : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        ?>
        <?= esc($msg) ?>
      </div>
      <?php endif; ?>

      <p class="tip">Os itens desativados ficam ocultos na sidebar para todos os usuários. Administradores sempre veem o grupo <strong>Administrador</strong>.</p>

      <form method="post" id="formMenus">

        <div class="menus-grid" id="menusGrid">

          <?php foreach ($todosMenus as $g):
            $gAtivo = isAtivo($g['grupo_key'], $desativados);
            $temItens = !empty($g['itens']);
            $cardClass = $gAtivo ? '' : ' desativado';
          ?>
          <div class="menu-card<?= $cardClass ?>" id="card-<?= esc(str_replace([':', '/', '.'], '-', $g['grupo_key'])) ?>">

            <!-- Cabeçalho / toggle do grupo -->
            <div class="menu-card-header" onclick="toggleCard(this)">
              <span class="menu-card-label"><?= esc($g['grupo_label']) ?></span>
              <label class="menu-card-toggle" onclick="event.stopPropagation()">
                <input type="checkbox"
                       name="menu[<?= esc($g['grupo_key']) ?>]"
                       value="1"
                       <?= $gAtivo ? 'checked' : '' ?>
                       onchange="onGrupoChange(this)">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
              </label>
            </div>

            <?php if ($temItens): ?>
            <!-- Sub-itens -->
            <div class="menu-items">
              <?php foreach ($g['itens'] as $item):
                $iAtivo = isAtivo($item['key'], $desativados);
                $cbId = 'cb-' . md5($item['key']);
              ?>
              <div class="menu-item-row" onclick="document.getElementById('<?= $cbId ?>').click()">
                <span class="item-dot"></span>
                <input type="checkbox"
                       id="<?= $cbId ?>"
                       name="menu[<?= esc($item['key']) ?>]"
                       value="1"
                       <?= $iAtivo ? 'checked' : '' ?>
                       onclick="event.stopPropagation()">
                <label for="<?= $cbId ?>"><?= esc($item['label']) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

          </div>
          <?php endforeach; ?>

        </div><!-- /menus-grid -->

      </form>

    </div><!-- /content -->
  </div>
</div>

<script>
// Toggle visual do card quando o grupo é ligado/desligado
function onGrupoChange(chk) {
  const card = chk.closest('.menu-card');
  card.classList.toggle('desativado', !chk.checked);
}

// Clique no header abre/fecha o toggle do grupo
function toggleCard(header) {
  const chk = header.querySelector('input[type=checkbox]');
  if (chk) {
    chk.checked = !chk.checked;
    chk.dispatchEvent(new Event('change'));
  }
}

// Ativar / desativar todos
function toggleTodos(ativar) {
  document.querySelectorAll('#formMenus input[type=checkbox]').forEach(chk => {
    chk.checked = ativar;
    const card = chk.closest('.menu-card');
    if (card) card.classList.toggle('desativado', !ativar);
  });
}
</script>
</body>
</html>
