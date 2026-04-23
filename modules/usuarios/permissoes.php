<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Permissões por Grupo
//  Define quais páginas cada grupo de usuários pode acessar
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── Schema: cria tabela PERMISSAO_ACESSO se não existir ───────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS PERMISSAO_ACESSO (
            PAC_CODIGO     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            PAC_GRU_CODIGO INT UNSIGNED NOT NULL,
            PAC_PAGINA     VARCHAR(150) NOT NULL,
            UNIQUE KEY uk_gru_pag (PAC_GRU_CODIGO, PAC_PAGINA)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable) {}

// ── Páginas disponíveis no sistema (espelha o sidebar) ────────────────────────
$modulos = [
    'Geral' => [
        ['path' => '/modules/produtos/cadastro_produtos.php', 'label' => 'Produtos'],
    ],
    'Vendas' => [
        ['path' => '/vendas',           'label' => 'Pedidos de Venda'],
        ['path' => '/vendas/simulacao', 'label' => 'Simulação de Vendas'],
    ],
    'Yzidro' => [
        ['path' => '/yzidro/pedidos',  'label' => 'Pedidos de Venda (Yzidro)'],
        ['path' => '/yzidro/consulta', 'label' => 'Consulta Vendas'],
    ],
    'Produção' => [
        ['path' => '/pcp/reuniao',      'label' => 'Reunião de Planejamento'],
        ['path' => '/pcp/kanban',       'label' => 'Kanban de Pedidos'],
        ['path' => '/pcp/planejamento', 'label' => 'Planejamento'],
        ['path' => '/pcp/ordens',       'label' => 'Ordens de Produção'],
        ['path' => '/pcp/programacao',  'label' => 'Programação Diária'],
        ['path' => '/pcp/producao',     'label' => 'Produção'],
    ],
    'Máquinas' => [
        ['path' => '/maquinas',  'label' => 'Cadastro de Máquinas'],
        ['path' => '/pcp/fila',  'label' => 'Fila de Máquinas'],
    ],
    'Financeiro' => [
        ['path' => '/financeiro',               'label' => 'Dashboard Financeiro'],
        ['path' => '/financeiro/receber',       'label' => 'Contas a Receber'],
        ['path' => '/financeiro/pagar',         'label' => 'Contas a Pagar'],
        ['path' => '/financeiro/baixas-pagar',  'label' => 'Baixas a Pagar'],
    ],
    'CRM' => [
        ['path' => '/modules/crm/qualificacao_leads.php', 'label' => 'Leads do Webhook'],
        ['path' => '/modules/crm/leads_resultados.php',   'label' => 'Prospecção com IA'],
    ],
    'Trello' => [
        ['path' => '/trello', 'label' => 'Pedidos Trello'],
    ],
    'Relatórios' => [
        ['path' => '/relatorios/producao',         'label' => 'Relatório de Produção'],
        ['path' => '/relatorios/estoque-futuro',   'label' => 'Estoque Futuro'],
        ['path' => '/relatorios/posicao-estoque',  'label' => 'Posição de Estoque'],
        ['path' => '/relatorios/controle-estoque', 'label' => 'Controle de Estoque'],
    ],
    'Usuários' => [
        ['path' => '/usuarios',                         'label' => 'Cadastro de Usuários'],
        ['path' => '/modules/usuarios/cad_grupos.php',  'label' => 'Grupos de Usuários'],
        ['path' => '/modules/usuarios/permissoes.php',  'label' => 'Permissões por Grupo'],
    ],
    'Parâmetros' => [
        ['path' => '/modules/parametros/parametros.php', 'label' => 'Parâmetros do Sistema'],
    ],
];

// Lista plana de caminhos válidos (para validação no save)
$paths_validos = [];
foreach ($modulos as $itens) {
    foreach ($itens as $item) {
        $paths_validos[] = $item['path'];
    }
}

// ── Lista de grupos ───────────────────────────────────────────────────────────
try {
    $grupos_db = $pdo->query('
        SELECT g.GRU_CODIGO, g.GRU_NOME, g.GRU_DESCRICAO,
               COUNT(u.USU_CODIGO) AS QTD_USUARIOS
        FROM GRUPO_USUARIO g
        LEFT JOIN USUARIOS u ON u.GRU_CODIGO = g.GRU_CODIGO
        GROUP BY g.GRU_CODIGO, g.GRU_NOME, g.GRU_DESCRICAO
        ORDER BY g.GRU_NOME
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $grupos_db = [];
}

// ── Grupo selecionado ─────────────────────────────────────────────────────────
$gru_cod    = (int)($_GET['gru'] ?? $_POST['gru_codigo'] ?? 0);
$grupo_sel  = null;
$permissoes_atuais = [];

if ($gru_cod > 0) {
    foreach ($grupos_db as $g) {
        if ((int)$g['GRU_CODIGO'] === $gru_cod) {
            $grupo_sel = $g;
            break;
        }
    }
}

// ── Salvar permissões (POST) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $grupo_sel) {
    $pdo->prepare('DELETE FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :g')
        ->execute([':g' => $gru_cod]);

    $stmt = $pdo->prepare('INSERT INTO PERMISSAO_ACESSO (PAC_GRU_CODIGO, PAC_PAGINA) VALUES (:g, :p)');
    $count_saved = 0;
    foreach (($_POST['paginas'] ?? []) as $pagina) {
        if (in_array($pagina, $paths_validos, true)) {
            $stmt->execute([':g' => $gru_cod, ':p' => $pagina]);
            $count_saved++;
        }
    }
    $msg = "Permissões salvas. {$count_saved} página(s) liberada(s) para o grupo <strong>" .
           htmlspecialchars($grupo_sel['GRU_NOME']) . "</strong>.";
    $msg_tipo = 'ok';
}

// ── Carrega permissões atuais do grupo ────────────────────────────────────────
if ($grupo_sel) {
    try {
        $st = $pdo->prepare('SELECT PAC_PAGINA FROM PERMISSAO_ACESSO WHERE PAC_GRU_CODIGO = :g');
        $st->execute([':g' => $gru_cod]);
        $permissoes_atuais = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'PAC_PAGINA');
    } catch (Throwable) {}
}

$total_paginas = count($paths_validos);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Permissões por Grupo | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.content{overflow-y:auto;overflow-x:hidden;}
.perm-layout{display:grid;grid-template-columns:270px 1fr;gap:20px;align-items:start;}
@media(max-width:960px){.perm-layout{grid-template-columns:1fr;}}

/* Lista de grupos */
.group-list{display:flex;flex-direction:column;gap:5px;}
.group-card{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:10px;border:1px solid var(--border);background:var(--card-bg);cursor:pointer;text-decoration:none;color:var(--text-primary);transition:all .15s;}
.group-card:hover{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.1);}
.group-card.selected{background:rgba(45,106,255,.1);border-color:rgba(45,106,255,.3);}
.group-card-name{font-size:13px;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.group-card-meta{font-size:10.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.group-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 5px;border-radius:999px;font-size:10px;font-weight:700;background:rgba(45,106,255,.15);color:#7db3ff;flex-shrink:0;}

/* Painel de permissões */
.perm-panel{display:flex;flex-direction:column;gap:12px;}
.perm-module{border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.perm-module-head{display:flex;align-items:center;justify-content:space-between;padding:9px 15px;background:rgba(255,255,255,.03);border-bottom:1px solid var(--border);}
.perm-module-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);}
.perm-mod-check{display:flex;align-items:center;gap:6px;cursor:pointer;font-size:11px;color:var(--text-muted);}
.perm-mod-check input{accent-color:var(--teal);cursor:pointer;}
.perm-items{display:flex;flex-direction:column;}
.perm-item{display:flex;align-items:center;padding:9px 15px;border-bottom:1px solid rgba(255,255,255,.03);transition:background .1s;}
.perm-item:last-child{border-bottom:none;}
.perm-item:hover{background:rgba(255,255,255,.02);}
.perm-item label{display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;font-size:13px;}
.perm-item input[type=checkbox]{width:15px;height:15px;accent-color:var(--teal);cursor:pointer;flex-shrink:0;}
.perm-item-text{display:flex;flex-direction:column;gap:2px;}
.perm-item .page-path{font-size:10px;color:var(--text-muted);font-family:monospace;line-height:1;}

/* Barra de ações fixa */
.save-bar{position:sticky;bottom:16px;background:#152845;border:1px solid rgba(45,106,255,.25);border-radius:12px;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 8px 32px rgba(0,0,0,.45);margin-top:12px;}
.save-bar-info{font-size:12.5px;color:var(--text-muted);}
.save-bar-info strong{color:var(--text-primary);}

/* Empty states */
.empty-perm{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:60px 24px;text-align:center;color:var(--text-muted);}
.empty-perm p{font-size:13.5px;line-height:1.6;}
.empty-perm a{color:#7db3ff;}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Permissões por Grupo</h1>
          <p>Defina quais páginas cada grupo de usuários pode acessar</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/modules/usuarios/cad_grupos.php" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
          Gerenciar Grupos
        </a>
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

      <!-- KPIs -->
      <div class="kpi-strip">
        <div class="kpi-mini c-blue">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= count($grupos_db) ?></div>
            <div class="kpi-mini-lbl">Grupos cadastrados</div>
          </div>
        </div>
        <div class="kpi-mini c-teal">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= $total_paginas ?></div>
            <div class="kpi-mini-lbl">Páginas no sistema</div>
          </div>
        </div>
        <?php if ($grupo_sel): ?>
        <div class="kpi-mini c-amber">
          <div class="kpi-mini-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div>
            <div class="kpi-mini-val"><?= count($permissoes_atuais) ?></div>
            <div class="kpi-mini-lbl">Páginas liberadas</div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (empty($grupos_db)): ?>
      <!-- Sem grupos cadastrados -->
      <div class="panel">
        <div class="empty-perm">
          <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
          <p>Nenhum grupo cadastrado ainda.<br>
            <a href="/modules/usuarios/cad_grupos.php">Crie grupos de usuários</a>
            antes de configurar permissões.</p>
        </div>
      </div>

      <?php else: ?>
      <div class="perm-layout">

        <!-- Lista de grupos -->
        <div class="panel" style="padding:14px;">
          <h2 style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px;">Selecione o Grupo</h2>
          <div class="group-list">
            <?php foreach ($grupos_db as $g):
              $isSel = ((int)$g['GRU_CODIGO'] === $gru_cod);
            ?>
            <a class="group-card <?= $isSel ? 'selected' : '' ?>"
               href="/modules/usuarios/permissoes.php?gru=<?= $g['GRU_CODIGO'] ?>">
              <div style="flex:1;min-width:0;">
                <div class="group-card-name"><?= htmlspecialchars($g['GRU_NOME']) ?></div>
                <?php if ($g['GRU_DESCRICAO']): ?>
                <div class="group-card-meta"><?= htmlspecialchars($g['GRU_DESCRICAO']) ?></div>
                <?php endif; ?>
              </div>
              <span class="group-badge" title="<?= (int)$g['QTD_USUARIOS'] ?> usuário(s)"><?= (int)$g['QTD_USUARIOS'] ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Painel de permissões -->
        <div>
          <?php if (!$grupo_sel): ?>
          <div class="panel">
            <div class="empty-perm">
              <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><line x1="12" y1="15" x2="12" y2="17"/></svg>
              <p>Selecione um grupo à esquerda<br>para configurar suas permissões de acesso.</p>
            </div>
          </div>

          <?php else: ?>
          <form method="POST" id="frmPermissoes">
            <input type="hidden" name="gru_codigo" value="<?= $grupo_sel['GRU_CODIGO'] ?>">

            <!-- Cabeçalho do painel -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
              <div>
                <h2 style="font-size:15px;font-weight:700;"><?= htmlspecialchars($grupo_sel['GRU_NOME']) ?></h2>
                <p style="font-size:12px;color:var(--text-muted);margin-top:3px;">
                  Marque as páginas que este grupo poderá acessar
                </p>
              </div>
              <div style="display:flex;gap:8px;flex-shrink:0;">
                <button type="button" class="btn-secondary" onclick="toggleTodos(true)">Marcar todos</button>
                <button type="button" class="btn-secondary" onclick="toggleTodos(false)">Desmarcar todos</button>
              </div>
            </div>

            <!-- Módulos e páginas -->
            <div class="perm-panel">
              <?php foreach ($modulos as $modNome => $itens):
                $qtd_mod = count($itens);
                $qtd_checked = count(array_filter($itens, fn($i) => in_array($i['path'], $permissoes_atuais, true)));
              ?>
              <div class="perm-module">
                <div class="perm-module-head">
                  <span class="perm-module-title"><?= htmlspecialchars($modNome) ?></span>
                  <label class="perm-mod-check" title="Marcar/desmarcar módulo inteiro">
                    <input type="checkbox"
                           class="mod-master"
                           data-mod="<?= htmlspecialchars($modNome) ?>"
                           <?= ($qtd_checked === $qtd_mod) ? 'checked' : '' ?>
                           onchange="toggleModulo(this)">
                    <?= $qtd_checked ?>/<?= $qtd_mod ?>
                  </label>
                </div>
                <div class="perm-items">
                  <?php foreach ($itens as $item):
                    $checked = in_array($item['path'], $permissoes_atuais, true);
                  ?>
                  <div class="perm-item">
                    <label>
                      <input type="checkbox"
                             name="paginas[]"
                             value="<?= htmlspecialchars($item['path']) ?>"
                             data-mod="<?= htmlspecialchars($modNome) ?>"
                             class="perm-cb"
                             <?= $checked ? 'checked' : '' ?>
                             onchange="onCbChange()">
                      <div class="perm-item-text">
                        <span><?= htmlspecialchars($item['label']) ?></span>
                        <span class="page-path"><?= htmlspecialchars($item['path']) ?></span>
                      </div>
                    </label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Barra de salvar -->
            <div class="save-bar">
              <div class="save-bar-info">
                Grupo: <strong><?= htmlspecialchars($grupo_sel['GRU_NOME']) ?></strong>
                &nbsp;·&nbsp;
                <span id="countChecked"><?= count($permissoes_atuais) ?></span> de <?= $total_paginas ?> página(s) selecionada(s)
              </div>
              <button type="submit" class="btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Salvar Permissões
              </button>
            </div>
          </form>
          <?php endif; ?>
        </div>

      </div><!-- /perm-layout -->
      <?php endif; ?>

    </div><!-- /content -->
  </div>
</div>

<script>
function toggleTodos(marcar) {
  document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = marcar);
  document.querySelectorAll('.mod-master').forEach(cb => cb.checked = marcar);
  atualizarContador();
}

function toggleModulo(masterCb) {
  const mod = masterCb.dataset.mod;
  document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]').forEach(cb => {
    cb.checked = masterCb.checked;
  });
  atualizarContador();
}

function onCbChange() {
  // Atualiza o master checkbox de cada módulo
  document.querySelectorAll('.mod-master').forEach(master => {
    const mod = master.dataset.mod;
    const cbs = document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]');
    const checked = document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]:checked');
    master.checked = (checked.length === cbs.length);
    master.indeterminate = (checked.length > 0 && checked.length < cbs.length);
    // Atualiza contagem no cabeçalho do módulo
    const label = master.parentElement;
    if (label) {
      const text = label.childNodes[label.childNodes.length - 1];
      if (text && text.nodeType === 3) {
        text.textContent = ' ' + checked.length + '/' + cbs.length;
      }
    }
  });
  atualizarContador();
}

function atualizarContador() {
  const count = document.querySelectorAll('.perm-cb:checked').length;
  const el = document.getElementById('countChecked');
  if (el) el.textContent = count;
}

// Inicializa estado indeterminate dos masters
document.querySelectorAll('.mod-master').forEach(master => {
  const mod = master.dataset.mod;
  const cbs = document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]');
  const checked = document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]:checked');
  master.indeterminate = (checked.length > 0 && checked.length < cbs.length);
});

const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.style.display = 'none', 400);
}, 5000);
</script>
</body>
</html>
