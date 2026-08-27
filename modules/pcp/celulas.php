<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Cadastro de Células de Produção
//  Cada célula agrupa funcionários (ERP) para exibir a produção somada no BI.
// ══════════════════════════════════════════════════════════════════════════════

const CEL_META_CHAVE = 'meta_diaria_celula';

$pdo = dbPDO();
$pg  = dbPG();
$msg = '';
$msg_tipo = '';

function celFuncionariosDaCelula(PDO $pdo, int $cel): array
{
    $st = $pdo->prepare('SELECT FU_CODIGO FROM CELULA_FUNCIONARIO WHERE CEL_CODIGO = :c');
    $st->execute([':c' => $cel]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function celFetchMeta(PDO $pdo): float
{
    try {
        $v = $pdo->query('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = ' . $pdo->quote(CEL_META_CHAVE))->fetchColumn();
        return $v !== false ? (float) $v : 0.0;
    } catch (Throwable) {
        return 0.0;
    }
}

function celSaveMeta(PDO $pdo, float $valor): void
{
    $stmt = $pdo->prepare('
        INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE PAR_VALOR = VALUES(PAR_VALOR)
    ');
    $stmt->execute([':k' => CEL_META_CHAVE, ':v' => (string) $valor]);
}

// ── Ações POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Criar / Editar célula (nome + funcionários) ─────────────────────────────
    if ($acao === 'criar' || $acao === 'editar') {
        $cod  = (int) ($_POST['cod'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $funcionarios = array_map('intval', $_POST['funcionarios'] ?? []);

        if (!$nome) {
            $msg = 'Informe o nome da célula.';
            $msg_tipo = 'err';
        } else {
            try {
                $pdo->beginTransaction();

                if ($acao === 'criar') {
                    $pdo->prepare('INSERT INTO CELULA_PRODUCAO (CEL_NOME) VALUES (:n)')->execute([':n' => $nome]);
                    $cod = (int) $pdo->lastInsertId();
                } else {
                    $pdo->prepare('UPDATE CELULA_PRODUCAO SET CEL_NOME = :n WHERE CEL_CODIGO = :c')
                        ->execute([':n' => $nome, ':c' => $cod]);
                }

                $pdo->prepare('DELETE FROM CELULA_FUNCIONARIO WHERE CEL_CODIGO = :c')->execute([':c' => $cod]);
                if ($funcionarios) {
                    $ins = $pdo->prepare('INSERT INTO CELULA_FUNCIONARIO (CEL_CODIGO, FU_CODIGO) VALUES (:c, :f)');
                    foreach (array_unique($funcionarios) as $fu) {
                        $ins->execute([':c' => $cod, ':f' => $fu]);
                    }
                }

                $pdo->commit();
                $msg = $acao === 'criar' ? 'Célula cadastrada com sucesso.' : 'Célula atualizada com sucesso.';
                $msg_tipo = 'ok';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $msg = $e->getCode() === '23000'
                    ? 'Já existe uma célula com esse nome.'
                    : 'Erro ao salvar célula: ' . $e->getMessage();
                $msg_tipo = 'err';
            }
        }
    }

    // ── Excluir célula ───────────────────────────────────────────────────────────
    if ($acao === 'excluir') {
        $cod = (int) ($_POST['cod'] ?? 0);
        if ($cod > 0) {
            $pdo->prepare('DELETE FROM CELULA_PRODUCAO WHERE CEL_CODIGO = :c')->execute([':c' => $cod]);
            $msg = 'Célula excluída com sucesso.';
            $msg_tipo = 'ok';
        }
    }

    // ── Salvar meta diária por célula (usada no BI Células) ─────────────────────
    if ($acao === 'meta_diaria') {
        $valor = (float) str_replace(',', '.', (string) ($_POST['meta'] ?? '0'));
        if ($valor < 0) {
            $valor = 0.0;
        }
        celSaveMeta($pdo, $valor);
        $msg = 'Meta diária atualizada com sucesso.';
        $msg_tipo = 'ok';
    }
}

$metaDiaria = celFetchMeta($pdo);

// ── Lista de funcionários do ERP (para o multi-select) ────────────────────────
$funcionariosErp = [];
if ($pg) {
    $res = @pg_query($pg, 'SELECT FU_CODIGO, FU_NOME FROM FUNCIONARIO ORDER BY FU_NOME');
    if ($res) {
        $funcionariosErp = pg_fetch_all($res) ?: [];
        pg_free_result($res);
    }
}
$nomesPorCodigo = [];
foreach ($funcionariosErp as $f) {
    $nomesPorCodigo[(int) $f['fu_codigo']] = $f['fu_nome'];
}

// ── Lista de células com seus funcionários ─────────────────────────────────────
try {
    $celulas = $pdo->query('SELECT CEL_CODIGO, CEL_NOME FROM CELULA_PRODUCAO ORDER BY CEL_NOME')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $celulas = [];
}
foreach ($celulas as &$c) {
    $c['funcionarios'] = celFuncionariosDaCelula($pdo, (int) $c['CEL_CODIGO']);
}
unset($c);

$total = count($celulas);
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastro de Células | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
.td-actions{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;border:1px solid transparent;text-decoration:none;background:none;}
.btn-sm-edit{background:rgba(45,106,255,.1);border-color:rgba(45,106,255,.2);color:#7db3ff;}
.btn-sm-edit:hover{background:rgba(45,106,255,.2);}
.btn-sm-danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:var(--red);}
.btn-sm-danger:hover{background:rgba(239,68,68,.2);}

.btn-danger{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.35);border-radius:7px;color:var(--red);font-size:13px;font-weight:600;cursor:pointer;transition:background .15s,border-color .15s;}
.btn-danger:hover{background:rgba(239,68,68,.25);border-color:rgba(239,68,68,.55);}

.count-badge2{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 6px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(45,106,255,.1);color:#7db3ff;}

.table-panel{background:var(--panel-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.table-panel .panel-header{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);}
.table-panel .panel-header h2{font-size:14px;font-weight:600;flex:1;}
.table-wrap{overflow-x:auto;}

.func-chip-list{display:flex;flex-wrap:wrap;gap:4px;max-width:420px;}
.func-chip{font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(0,201,167,.1);color:var(--teal);white-space:nowrap;}
.func-chip-empty{font-size:11.5px;color:var(--text-muted);}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:var(--modal-bg);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:26px 26px 20px;width:560px;max-width:calc(100vw - 32px);max-height:calc(100vh - 48px);overflow-y:auto;transform:translateY(10px) scale(.98);transition:transform .2s;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1);}
.modal-box h3{font-size:15px;font-weight:600;margin-bottom:8px;}
.modal-box p{font-size:12.5px;color:var(--text-muted);line-height:1.55;margin-bottom:4px;}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end;}

.func-picker-search{width:100%;margin-bottom:10px;}
.func-picker-count{font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:6px;}
.func-picker-count strong{color:var(--teal);}
.func-picker{
  max-height:min(560px, 62vh);overflow-y:auto;border:1px solid var(--border);border-radius:10px;
  padding:6px;display:flex;flex-direction:column;gap:2px;background:rgba(255,255,255,.02);
}
.func-picker::-webkit-scrollbar{width:7px;}
.func-picker::-webkit-scrollbar-track{background:transparent;}
.func-picker::-webkit-scrollbar-thumb{background:rgba(255,255,255,.14);border-radius:4px;}
.func-picker::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.22);}
.func-picker-item{
  display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;
  font-size:12.5px;font-weight:500;cursor:pointer;transition:background .12s;
}
.func-picker-item:hover{background:var(--card-hover, rgba(255,255,255,.06));}
.func-picker-item input{
  flex-shrink:0;width:16px;height:16px;margin:0;cursor:pointer;accent-color:var(--blue-accent,#2d6aff);
}
.func-picker-item span{flex:1;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.func-picker-item:has(input:checked){background:rgba(45,106,255,.1);}
.func-picker-item:has(input:checked) span{color:var(--text-primary);font-weight:600;}
.func-picker-empty{font-size:12px;color:var(--text-muted);text-align:center;padding:16px 0;}
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Cadastro de Células</h1>
          <p>Agrupe funcionários em células para acompanhar a produção somada no BI</p>
        </div>
      </div>
      <div class="topbar-actions">
        <a href="/pcp/bi-celulas" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
          BI Células
        </a>
        <a href="/pcp/bi" class="btn-secondary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Voltar ao BI
        </a>
        <button type="button" class="btn-primary" onclick="abrirModalCriar()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nova Célula
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

      <?php if (!$pg): ?>
      <div class="alert-error">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Não foi possível conectar ao banco de dados do ERP (PostgreSQL) para listar os funcionários.
      </div>
      <?php endif; ?>

      <div class="table-panel" style="margin-bottom:20px;">
        <div class="panel-header">
          <h2>Meta Diária por Célula</h2>
        </div>
        <div style="padding:16px 18px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
          <p style="font-size:12.5px; color:var(--text-muted); flex:1; min-width:220px;">
            Meta usada para comparar a produção de cada célula no <strong>BI Células</strong>.
          </p>
          <form method="POST" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="acao" value="meta_diaria">
            <input type="number" name="meta" min="0" step="1" value="<?= (int) $metaDiaria ?>"
                   style="height:38px; width:130px; padding:0 10px; border-radius:8px; border:1px solid var(--border); background:var(--input-bg,var(--panel-bg)); color:var(--text-primary); font-size:13px;">
            <button type="submit" class="btn-primary">Salvar Meta</button>
          </form>
        </div>
      </div>

      <div class="table-panel">
        <div class="panel-header">
          <h2>Células Cadastradas</h2>
          <span class="count-badge2"><?= $total ?></span>
        </div>

        <?php if (empty($celulas)): ?>
        <div class="empty-state">
          <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          <p>Nenhuma célula cadastrada.<br>Clique em <strong>Nova Célula</strong> para começar.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Código</th>
                <th>Nome</th>
                <th>Funcionários</th>
                <th>Operações</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($celulas as $c): ?>
            <tr>
              <td class="td-muted">#<?= (int) $c['CEL_CODIGO'] ?></td>
              <td style="font-weight:600;"><?= htmlspecialchars($c['CEL_NOME']) ?></td>
              <td>
                <?php if (empty($c['funcionarios'])): ?>
                  <span class="func-chip-empty">Nenhum funcionário</span>
                <?php else: ?>
                  <div class="func-chip-list">
                    <?php foreach ($c['funcionarios'] as $fu): ?>
                      <span class="func-chip"><?= htmlspecialchars($nomesPorCodigo[$fu] ?? ('#' . $fu)) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <button class="btn-sm btn-sm-edit"
                          onclick='abrirModalEditar(<?= (int) $c['CEL_CODIGO'] ?>, <?= json_encode($c['CEL_NOME']) ?>, <?= json_encode($c['funcionarios']) ?>)'>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Editar
                  </button>
                  <button class="btn-sm btn-sm-danger"
                          onclick='confirmarExclusao(<?= (int) $c['CEL_CODIGO'] ?>, <?= json_encode($c['CEL_NOME']) ?>)'>
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

<!-- ── Modal: Criar/Editar Célula ─────────────────────────────────────────── -->
<div class="modal-overlay" id="modalForm">
  <div class="modal-box">
    <h3 id="modalFormTitulo">Nova Célula</h3>
    <p>Dê um nome à célula e marque os funcionários que fazem parte dela.</p>
    <form method="POST" id="frmForm" onsubmit="return validarForm()">
      <input type="hidden" name="acao" id="frmAcao" value="criar">
      <input type="hidden" name="cod" id="frmCod" value="">
      <div class="field" style="margin-top:14px;">
        <label>Nome da Célula *</label>
        <input type="text" name="nome" id="frmNome"
               placeholder="Ex: Célula A"
               maxlength="100" required autocomplete="off">
      </div>
      <div class="field" style="margin-top:14px;">
        <label>Funcionários</label>
        <input type="text" class="func-picker-search" id="funcSearch" placeholder="Buscar funcionário..." autocomplete="off">
        <div class="func-picker-count"><strong id="funcSelectedCount">0</strong> funcionário(s) selecionado(s)</div>
        <div class="func-picker" id="funcPicker">
          <?php if (empty($funcionariosErp)): ?>
            <div class="func-picker-empty">Nenhum funcionário encontrado no ERP.</div>
          <?php else: ?>
            <?php foreach ($funcionariosErp as $f): ?>
              <label class="func-picker-item" data-nome="<?= htmlspecialchars(mb_strtolower($f['fu_nome'])) ?>">
                <input type="checkbox" name="funcionarios[]" value="<?= (int) $f['fu_codigo'] ?>" class="func-checkbox">
                <span><?= htmlspecialchars($f['fu_nome']) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
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
        <button type="submit" class="btn-danger">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
          Sim, excluir
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function limparSelecaoFuncionarios() {
  document.querySelectorAll('.func-checkbox').forEach(cb => cb.checked = false);
  atualizarContadorFuncionarios();
}

function atualizarContadorFuncionarios() {
  const total = document.querySelectorAll('.func-checkbox:checked').length;
  document.getElementById('funcSelectedCount').textContent = total;
}
document.getElementById('funcPicker').addEventListener('change', e => {
  if (e.target.classList.contains('func-checkbox')) atualizarContadorFuncionarios();
});

function abrirModalCriar() {
  document.getElementById('modalFormTitulo').textContent = 'Nova Célula';
  document.getElementById('frmAcao').value = 'criar';
  document.getElementById('frmCod').value = '';
  document.getElementById('frmNome').value = '';
  document.getElementById('funcSearch').value = '';
  limparSelecaoFuncionarios();
  filtrarFuncionarios();
  document.getElementById('modalForm').classList.add('open');
  document.getElementById('frmNome').focus();
}

function abrirModalEditar(cod, nome, funcionarios) {
  document.getElementById('modalFormTitulo').textContent = 'Editar Célula';
  document.getElementById('frmAcao').value = 'editar';
  document.getElementById('frmCod').value = cod;
  document.getElementById('frmNome').value = nome;
  document.getElementById('funcSearch').value = '';
  limparSelecaoFuncionarios();
  const set = new Set((funcionarios || []).map(Number));
  document.querySelectorAll('.func-checkbox').forEach(cb => {
    cb.checked = set.has(Number(cb.value));
  });
  atualizarContadorFuncionarios();
  filtrarFuncionarios();
  document.getElementById('modalForm').classList.add('open');
  document.getElementById('frmNome').focus();
}

function validarForm() {
  const nome = document.getElementById('frmNome').value.trim();
  if (!nome) { alert('Informe o nome da célula.'); return false; }
  return true;
}

function filtrarFuncionarios() {
  const termo = document.getElementById('funcSearch').value.trim().toLowerCase();
  document.querySelectorAll('#funcPicker .func-picker-item').forEach(item => {
    const nome = item.dataset.nome || '';
    item.style.display = !termo || nome.includes(termo) ? '' : 'none';
  });
}
document.getElementById('funcSearch').addEventListener('input', filtrarFuncionarios);

function confirmarExclusao(cod, nome) {
  document.getElementById('modal_excl_txt').innerHTML =
    'Tem certeza que deseja excluir a célula <strong>' + nome + '</strong>? Esta ação não pode ser desfeita.';
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
