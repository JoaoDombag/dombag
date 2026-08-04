<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Cadastro de Etapas de Produção
//  Operações: criar, editar, excluir
// ══════════════════════════════════════════════════════════════════════════════

$pdo = dbPDO();
$msg = '';
$msg_tipo = '';

// ── Ações POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Criar etapa ───────────────────────────────────────────────────────────
    if ($acao === 'criar') {
        $desc = trim($_POST['descricao'] ?? '');
        if (!$desc) {
            $msg = 'Informe a descrição da etapa.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->prepare('INSERT INTO ETAPA_PRODUCAO (ETP_DESCRICAO) VALUES (:d)')
                    ->execute([':d' => $desc]);
                $msg = 'Etapa cadastrada com sucesso.';
                $msg_tipo = 'ok';
            } catch (PDOException $e) {
                $msg = $e->getCode() === '23000'
                    ? 'Já existe uma etapa com essa descrição.'
                    : 'Erro ao cadastrar etapa: ' . $e->getMessage();
                $msg_tipo = 'err';
            }
        }
    }

    // ── Editar etapa ──────────────────────────────────────────────────────────
    if ($acao === 'editar') {
        $cod  = (int) ($_POST['cod'] ?? 0);
        $desc = trim($_POST['descricao'] ?? '');
        if (!$cod || !$desc) {
            $msg = 'Preencha a descrição da etapa.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->prepare('UPDATE ETAPA_PRODUCAO SET ETP_DESCRICAO = :d WHERE ETP_CODIGO = :c')
                    ->execute([':d' => $desc, ':c' => $cod]);
                $msg = 'Etapa atualizada com sucesso.';
                $msg_tipo = 'ok';
            } catch (PDOException $e) {
                $msg = $e->getCode() === '23000'
                    ? 'Já existe uma etapa com essa descrição.'
                    : 'Erro ao atualizar etapa: ' . $e->getMessage();
                $msg_tipo = 'err';
            }
        }
    }

    // ── Excluir etapa ─────────────────────────────────────────────────────────
    if ($acao === 'excluir') {
        $cod = (int) ($_POST['cod'] ?? 0);
        if ($cod > 0) {
            $count = 0;
            try {
                $st = $pdo->prepare('SELECT COUNT(*) FROM PCP_PROGRAMACAO WHERE ETP_CODIGO = :c');
                $st->execute([':c' => $cod]);
                $count = (int) $st->fetchColumn();
            } catch (Throwable) {}

            if ($count > 0) {
                $msg = "Não é possível excluir: esta etapa está em uso por {$count} pedido(s) programado(s).";
                $msg_tipo = 'err';
            } else {
                $pdo->prepare('DELETE FROM ETAPA_PRODUCAO WHERE ETP_CODIGO = :c')->execute([':c' => $cod]);
                $msg = 'Etapa excluída com sucesso.';
                $msg_tipo = 'ok';
            }
        }
    }
}

// ── Lista de etapas com contagem de pedidos em cada uma ───────────────────────
try {
    $etapas = $pdo->query('
        SELECT e.ETP_CODIGO, e.ETP_DESCRICAO,
               COUNT(p.PRG_CODIGO) AS QTD_PEDIDOS
        FROM ETAPA_PRODUCAO e
        LEFT JOIN PCP_PROGRAMACAO p ON p.ETP_CODIGO = e.ETP_CODIGO AND p.PRG_FINALIZADO = 0
        GROUP BY e.ETP_CODIGO, e.ETP_DESCRICAO
        ORDER BY e.ETP_CODIGO
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $etapas = [];
}

$total = count($etapas);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Etapas de Produção | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.td-actions{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;border:1px solid transparent;text-decoration:none;background:none;}
.btn-sm-edit{background:rgba(45,106,255,.1);border-color:rgba(45,106,255,.2);color:#7db3ff;}
.btn-sm-edit:hover{background:rgba(45,106,255,.2);}
.btn-sm-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:var(--red);}
.btn-sm-danger:hover{background:rgba(239,68,68,.2);}

.count-pedidos{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 6px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(45,106,255,.1);color:#7db3ff;}

.table-panel{background:var(--panel-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.table-panel .panel-header{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);}
.table-panel .panel-header h2{font-size:14px;font-weight:600;flex:1;}
.table-wrap{overflow-x:auto;}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:#152845;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:26px 26px 20px;width:440px;max-width:calc(100vw - 32px);transform:translateY(10px) scale(.98);transition:transform .2s;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1);}
.modal-box h3{font-size:15px;font-weight:600;margin-bottom:8px;}
.modal-box p{font-size:12.5px;color:var(--text-muted);line-height:1.55;margin-bottom:4px;}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end;}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Etapas de Produção</h1>
          <p>Cadastro das etapas exibidas na Reunião de Planejamento</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/pcp/reuniao" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Voltar
        </a>
        <button type="button" class="btn-primary" onclick="abrirModalCriar()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nova Etapa
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
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>

      <div class="table-panel">
        <div class="panel-header">
          <h2>Etapas Cadastradas</h2>
          <span class="count-badge"><?= $total ?></span>
        </div>

        <?php if (empty($etapas)): ?>
        <div class="empty-state">
          <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          <p>Nenhuma etapa cadastrada.<br>Clique em <strong>Nova Etapa</strong> para começar.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Código</th>
                <th>Descrição</th>
                <th class="td-center">Pedidos nesta etapa</th>
                <th>Operações</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($etapas as $e): ?>
            <tr>
              <td class="td-muted">#<?= (int) $e['ETP_CODIGO'] ?></td>
              <td style="font-weight:600;"><?= htmlspecialchars($e['ETP_DESCRICAO']) ?></td>
              <td class="td-center">
                <span class="count-pedidos"><?= (int) $e['QTD_PEDIDOS'] ?></span>
              </td>
              <td>
                <div class="td-actions">
                  <button class="btn-sm btn-sm-edit"
                          onclick="abrirModalEditar(<?= (int) $e['ETP_CODIGO'] ?>, '<?= addslashes($e['ETP_DESCRICAO']) ?>')">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Editar
                  </button>
                  <button class="btn-sm btn-sm-danger"
                          onclick="confirmarExclusao(<?= (int) $e['ETP_CODIGO'] ?>, '<?= addslashes($e['ETP_DESCRICAO']) ?>', <?= (int) $e['QTD_PEDIDOS'] ?>)">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Excluir
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- /content -->
  </div>
</div>

<!-- ── Modal: Criar/Editar Etapa ──────────────────────────────────────────── -->
<div class="modal-overlay" id="modalForm">
  <div class="modal-box">
    <h3 id="modalFormTitulo">Nova Etapa</h3>
    <p>Descreva a etapa de produção como ela deve aparecer na Reunião de Planejamento.</p>
    <form method="POST" id="frmForm" onsubmit="return validarForm()">
      <input type="hidden" name="acao" id="frmAcao" value="criar">
      <input type="hidden" name="cod" id="frmCod" value="">
      <div class="field" style="margin-top:14px;">
        <label>Descrição *</label>
        <input type="text" name="descricao" id="frmDescricao"
               placeholder="Ex: Em produção"
               maxlength="100" required autocomplete="off">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal('modalForm')">Cancelar</button>
        <button type="submit" class="btn-primary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Salvar
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Confirmar Exclusão ──────────────────────────────────────────── -->
<div class="modal-overlay" id="modalExclusao">
  <div class="modal-box">
    <h3>Confirmar Exclusão</h3>
    <p id="modal_excl_txt"></p>
    <form method="POST" id="frmExclusao">
      <input type="hidden" name="acao" value="excluir">
      <input type="hidden" name="cod"  id="modal_excl_cod" value="">
      <div class="modal-actions">
        <button type="button" class="btn-secondary" onclick="fecharModal('modalExclusao')">Cancelar</button>
        <button type="submit" class="btn-danger" id="btnConfExcl">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Sim, excluir
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalCriar() {
  document.getElementById('modalFormTitulo').textContent = 'Nova Etapa';
  document.getElementById('frmAcao').value = 'criar';
  document.getElementById('frmCod').value = '';
  document.getElementById('frmDescricao').value = '';
  document.getElementById('modalForm').classList.add('open');
  document.getElementById('frmDescricao').focus();
}

function abrirModalEditar(cod, descricao) {
  document.getElementById('modalFormTitulo').textContent = 'Editar Etapa';
  document.getElementById('frmAcao').value = 'editar';
  document.getElementById('frmCod').value = cod;
  document.getElementById('frmDescricao').value = descricao;
  document.getElementById('modalForm').classList.add('open');
  document.getElementById('frmDescricao').focus();
}

function validarForm() {
  const desc = document.getElementById('frmDescricao').value.trim();
  if (!desc) { alert('Informe a descrição da etapa.'); return false; }
  return true;
}

function confirmarExclusao(cod, descricao, qtdPedidos) {
  const txt = document.getElementById('modal_excl_txt');
  const btn = document.getElementById('btnConfExcl');
  if (qtdPedidos > 0) {
    txt.innerHTML = 'A etapa <strong>' + descricao + '</strong> está em uso por <strong>' + qtdPedidos +
      ' pedido(s)</strong> programado(s) e não pode ser excluída.';
    btn.style.display = 'none';
  } else {
    txt.innerHTML = 'Tem certeza que deseja excluir a etapa <strong>' + descricao +
      '</strong>? Esta ação não pode ser desfeita.';
    btn.style.display = '';
  }
  document.getElementById('modal_excl_cod').value = cod;
  document.getElementById('modalExclusao').classList.add('open');
}

function fecharModal(id) {
  document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => { if (e.target === e.currentTarget) fecharModal(overlay.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(o => fecharModal(o.id));
});

const alertEl = document.getElementById('alertMsg');
if (alertEl) setTimeout(() => {
  alertEl.style.transition = 'opacity .4s';
  alertEl.style.opacity = '0';
  setTimeout(() => alertEl.style.display = 'none', 400);
}, 4000);
</script>
</body>
</html>
