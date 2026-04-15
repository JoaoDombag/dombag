<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once __DIR__ . '/fin_schema.php';

$pdo = dbPDO();
finEnsureSchema($pdo);

$hoje = date('Y-m-d');

// Atualiza vencidos
$pdo->exec("UPDATE fin_contas_pagar SET status='VENCIDO' WHERE status IN ('ABERTO','PARCIAL') AND data_vencimento < '$hoje'");

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = (string) $_POST['action'];

        if ($action === 'salvar') {
            $id         = (int) ($_POST['id'] ?? 0);
            $desc       = trim((string) ($_POST['descricao'] ?? ''));
            $fornecedor = trim((string) ($_POST['fornecedor'] ?? ''));
            $cat        = trim((string) ($_POST['categoria'] ?? 'Fornecedor'));
            $valor      = (float) str_replace(',', '.', (string) ($_POST['valor'] ?? '0'));
            $emissao    = (string) ($_POST['data_emissao'] ?? $hoje);
            $venc       = (string) ($_POST['data_vencimento'] ?? $hoje);
            $obs        = trim((string) ($_POST['observacoes'] ?? ''));

            if ($desc === '' || $valor <= 0 || $venc === '') {
                echo json_encode(['ok' => false, 'msg' => 'Preencha descrição, valor e vencimento.']);
                exit;
            }

            if ($id > 0) {
                $pdo->prepare('UPDATE fin_contas_pagar SET descricao=:d, fornecedor=:f, categoria=:cat, valor=:v, data_emissao=:e, data_vencimento=:venc, observacoes=:obs WHERE id=:id')
                    ->execute([':d'=>$desc,':f'=>$fornecedor,':cat'=>$cat,':v'=>$valor,':e'=>$emissao,':venc'=>$venc,':obs'=>$obs,':id'=>$id]);
            } else {
                $pdo->prepare('INSERT INTO fin_contas_pagar (descricao,fornecedor,categoria,valor,data_emissao,data_vencimento,observacoes) VALUES (:d,:f,:cat,:v,:e,:venc,:obs)')
                    ->execute([':d'=>$desc,':f'=>$fornecedor,':cat'=>$cat,':v'=>$valor,':e'=>$emissao,':venc'=>$venc,':obs'=>$obs]);
            }
            echo json_encode(['ok' => true]);

        } elseif ($action === 'pagar') {
            $id        = (int) ($_POST['id'] ?? 0);
            $valorPago = (float) str_replace(',', '.', (string) ($_POST['valor_pago'] ?? '0'));
            $dataPag   = trim((string) ($_POST['data_pagamento'] ?? $hoje));

            $row = $pdo->prepare('SELECT valor FROM fin_contas_pagar WHERE id=?');
            $row->execute([$id]);
            $r = $row->fetch();
            if (!$r) { echo json_encode(['ok'=>false,'msg'=>'Registro não encontrado.']); exit; }

            $status = finResolveStatus((float)$r['valor'], $valorPago, $dataPag);
            $pdo->prepare('UPDATE fin_contas_pagar SET valor_pago=:vp, data_pagamento=:dp, status=:s WHERE id=:id')
                ->execute([':vp'=>$valorPago,':dp'=>$dataPag ?: null,':s'=>$status,':id'=>$id]);
            echo json_encode(['ok' => true]);

        } elseif ($action === 'cancelar') {
            $pdo->prepare('UPDATE fin_contas_pagar SET status=:s WHERE id=:id')
                ->execute([':s'=>'CANCELADO',':id'=>(int)($_POST['id']??0)]);
            echo json_encode(['ok' => true]);

        } elseif ($action === 'excluir') {
            $pdo->prepare('DELETE FROM fin_contas_pagar WHERE id=?')->execute([(int)($_POST['id']??0)]);
            echo json_encode(['ok' => true]);

        } else {
            echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Export CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query('SELECT descricao,fornecedor,categoria,valor,valor_pago,data_emissao,data_vencimento,data_pagamento,status,observacoes,criado_em FROM fin_contas_pagar ORDER BY data_vencimento DESC')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contas_pagar_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    if (!empty($rows)) { fputcsv($out, array_keys($rows[0]), ';'); foreach ($rows as $r) fputcsv($out, $r, ';'); }
    fclose($out);
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$fStatus = (string) ($_GET['status'] ?? '');
$fBusca  = trim((string) ($_GET['q'] ?? ''));
$fMes    = trim((string) ($_GET['mes'] ?? ''));

$where  = ['1=1'];
$params = [];
if ($fStatus !== '') { $where[] = 'status = ?'; $params[] = $fStatus; }
if ($fBusca  !== '') {
    $where[] = '(descricao LIKE ? OR fornecedor LIKE ? OR categoria LIKE ?)';
    $like = '%'.$fBusca.'%';
    array_push($params, $like, $like, $like);
}
if ($fMes !== '') { $where[] = 'DATE_FORMAT(data_vencimento,"%Y-%m") = ?'; $params[] = $fMes; }

$sql = 'SELECT * FROM fin_contas_pagar WHERE ' . implode(' AND ', $where) . ' ORDER BY data_vencimento ASC, id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalAberto = $totalPago = $totalVencido = 0.0;
foreach ($rows as $r) {
    $saldo = (float)$r['valor'] - (float)$r['valor_pago'];
    if ($r['status'] === 'PAGO')    $totalPago    += (float)$r['valor'];
    if ($r['status'] === 'VENCIDO') $totalVencido += $saldo;
    if (in_array($r['status'], ['ABERTO','PARCIAL'], true)) $totalAberto += $saldo;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contas a Pagar | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<style>
.fs-aberto   { background:rgba(45,106,255,.1);  color:#7db3ff;   padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.fs-pago     { background:rgba(16,185,129,.12); color:#10b981;  padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.fs-parcial  { background:rgba(245,158,11,.1);  color:var(--amber); padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.fs-vencido  { background:rgba(239,68,68,.12);  color:var(--red);   padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.fs-cancelado{ background:rgba(255,255,255,.06);color:var(--text-muted); padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }

.sum-bar { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.sum-chip { display:inline-flex; flex-direction:column; gap:3px; padding:14px 18px; border-radius:12px; border:1px solid var(--border); background:rgba(255,255,255,.025); min-width:140px; }
.sum-chip-val { font-size:17px; font-weight:800; }
.sum-chip-lbl { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }

.filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:16px 18px; border-bottom:1px solid var(--border); }
.filter-bar .field { min-width:150px; }
.filter-bar .field label { font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px; }
.filter-bar input, .filter-bar select { height:36px; font-size:12px; }

.fin-tbl { width:100%; border-collapse:collapse; }
.fin-tbl th { padding:9px 14px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); border-bottom:1px solid var(--border); background:rgba(0,0,0,.1); white-space:nowrap; }
.fin-tbl td { padding:11px 14px; font-size:12.5px; border-bottom:1px solid var(--border); color:var(--text-primary); vertical-align:middle; }
.fin-tbl tbody tr:hover td { background:rgba(255,255,255,.025); }
.fin-tbl .vencido-row td { background:rgba(239,68,68,.03); }

.row-actions { display:flex; gap:6px; align-items:center; }
.btn-icon { width:30px; height:30px; border-radius:8px; border:1px solid var(--border); background:rgba(255,255,255,.04); color:var(--text-muted); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; }
.btn-icon:hover { background:rgba(255,255,255,.08); color:var(--text-primary); }
.btn-icon.danger:hover { border-color:rgba(239,68,68,.3); background:rgba(239,68,68,.08); color:var(--red); }
.btn-icon.success:hover { border-color:rgba(16,185,129,.3); background:rgba(16,185,129,.08); color:#10b981; }

.modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:200; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(3px); }
.modal-backdrop.hidden { display:none; }
.modal { background:var(--blue-mid); border:1px solid var(--border); border-radius:18px; padding:28px; width:100%; max-width:520px; max-height:90vh; overflow-y:auto; box-shadow:0 24px 48px rgba(0,0,0,.4); }
.modal-title { font-size:16px; font-weight:800; margin-bottom:20px; color:var(--text-primary); }
.modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.modal-grid .span-2 { grid-column:1/-1; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:16px; border-top:1px solid var(--border); }
.empty-state { padding:60px 20px; text-align:center; color:var(--text-muted); font-size:14px; }
</style>
</head>
<body>
<div class="app-wrapper">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>
<div class="main">

<header class="topbar">
  <div class="topbar-left">
    <div class="page-title">
      <h1>Contas a Pagar</h1>
      <p>Gerencie seus pagamentos e controle vencimentos.</p>
    </div>
  </div>
  <div class="topbar-actions">
    <a href="?export=csv" class="btn-secondary">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar CSV
    </a>
    <button class="btn-primary" onclick="abrirModal()">+ Nova Conta</button>
  </div>
</header>

<div class="content">

  <!-- Totais -->
  <div class="sum-bar">
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:var(--red);"><?= finMoney($totalAberto) ?></div>
      <div class="sum-chip-lbl">Em aberto</div>
    </div>
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:var(--red);opacity:.8;"><?= finMoney($totalVencido) ?></div>
      <div class="sum-chip-lbl">Vencido</div>
    </div>
    <div class="sum-chip">
      <div class="sum-chip-val" style="color:var(--text-muted);"><?= finMoney($totalPago) ?></div>
      <div class="sum-chip-lbl">Pago</div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="panel" style="margin-bottom:20px;">
    <form method="GET" action="">
      <div class="filter-bar">
        <div class="field">
          <label>Busca</label>
          <input type="text" name="q" value="<?= finH($fBusca) ?>" placeholder="Descrição, fornecedor, categoria…">
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="">Todos</option>
            <?php foreach (['ABERTO'=>'Aberto','PARCIAL'=>'Parcial','VENCIDO'=>'Vencido','PAGO'=>'Pago','CANCELADO'=>'Cancelado'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $fStatus===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Mês de vencimento</label>
          <input type="month" name="mes" value="<?= finH($fMes) ?>">
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
          <button type="submit" class="btn-primary" style="height:36px;">Filtrar</button>
          <a href="/financeiro/pagar" class="btn-secondary" style="height:36px;display:inline-flex;align-items:center;">Limpar</a>
        </div>
      </div>
    </form>
  </div>

  <!-- Tabela -->
  <div class="panel">
    <div class="panel-header">
      <span class="panel-title">Lançamentos</span>
      <span class="count-badge"><?= count($rows) ?></span>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty-state">Nenhum lançamento encontrado<?= $fBusca || $fStatus || $fMes ? ' com esses filtros.' : '.' ?><br>
        <button class="btn-primary" onclick="abrirModal()" style="margin-top:14px;display:inline-flex;">+ Nova Conta</button>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="fin-tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Descrição</th>
            <th>Fornecedor</th>
            <th>Categoria</th>
            <th>Vencimento</th>
            <th>Valor</th>
            <th>Pago</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $atrasado = $r['data_vencimento'] < $hoje && !in_array($r['status'], ['PAGO','CANCELADO'], true);
        ?>
          <tr id="row-<?= $r['id'] ?>" class="<?= $atrasado ? 'vencido-row' : '' ?>">
            <td style="font-size:11px;color:var(--text-muted);">#<?= $r['id'] ?></td>
            <td>
              <div style="font-weight:600;"><?= finH($r['descricao']) ?></div>
              <?php if ($r['observacoes']): ?><div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= finH(mb_strimwidth($r['observacoes'],0,60,'…')) ?></div><?php endif; ?>
            </td>
            <td style="font-size:12px;"><?= finH($r['fornecedor'] ?: '—') ?></td>
            <td style="font-size:12px;"><?= finH($r['categoria']) ?></td>
            <td style="white-space:nowrap;<?= $atrasado ? 'color:var(--red);font-weight:700;' : '' ?>">
              <?= date('d/m/Y', strtotime($r['data_vencimento'])) ?>
            </td>
            <td style="white-space:nowrap;font-weight:700;"><?= finMoney((float)$r['valor']) ?></td>
            <td style="white-space:nowrap;color:<?= (float)$r['valor_pago']>0 ? '#10b981' : 'var(--text-muted)' ?>;">
              <?= (float)$r['valor_pago'] > 0 ? finMoney((float)$r['valor_pago']) : '—' ?>
            </td>
            <td><span class="<?= finStatusClass($r['status']) ?>"><?= finStatusLabel($r['status']) ?></span></td>
            <td>
              <div class="row-actions">
                <?php if (!in_array($r['status'], ['PAGO','CANCELADO'], true)): ?>
                <button class="btn-icon success" title="Registrar pagamento" onclick="abrirPagamento(<?= $r['id'] ?>, <?= $r['valor'] ?>, <?= $r['valor_pago'] ?>)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
                <?php endif; ?>
                <button class="btn-icon" title="Editar" onclick="editarLancamento(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <?php if ($r['status'] !== 'CANCELADO'): ?>
                <button class="btn-icon danger" title="Cancelar" onclick="cancelar(<?= $r['id'] ?>)">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <?php endif; ?>
                <button class="btn-icon danger" title="Excluir" onclick="excluir(<?= $r['id'] ?>)">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
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

</div>
</div>
</div>

<!-- Modal: Nova / Editar -->
<div class="modal-backdrop hidden" id="modalBackdrop" onclick="fecharModal(event)">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modal-title" id="modalTitulo">Nova Conta a Pagar</div>
    <input type="hidden" id="mId" value="0">
    <div class="modal-grid">
      <div class="field span-2">
        <label>Descrição *</label>
        <input type="text" id="mDesc" placeholder="Ex.: Fatura energia elétrica — Abril">
      </div>
      <div class="field">
        <label>Fornecedor</label>
        <input type="text" id="mFornecedor" placeholder="Nome do fornecedor">
      </div>
      <div class="field">
        <label>Categoria</label>
        <select id="mCat">
          <?php foreach (FIN_CATEGORIAS_PAGAR as $c): ?>
            <option value="<?= finH($c) ?>"><?= finH($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Valor (R$) *</label>
        <input type="number" id="mValor" min="0.01" step="0.01" placeholder="0,00">
      </div>
      <div class="field">
        <label>Emissão</label>
        <input type="date" id="mEmissao" value="<?= $hoje ?>">
      </div>
      <div class="field span-2">
        <label>Vencimento *</label>
        <input type="date" id="mVenc">
      </div>
      <div class="field span-2">
        <label>Observações</label>
        <textarea id="mObs" rows="3" style="width:100%;padding:8px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-family:inherit;font-size:12px;resize:none;outline:none;"></textarea>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-secondary" type="button" onclick="document.getElementById('modalBackdrop').classList.add('hidden')">Cancelar</button>
      <button class="btn-primary" type="button" id="btnSalvarModal" onclick="salvarModal()">Salvar</button>
    </div>
  </div>
</div>

<!-- Modal: Registrar pagamento -->
<div class="modal-backdrop hidden" id="pagBackdrop" onclick="fecharPagModal(event)">
  <div class="modal" onclick="event.stopPropagation()" style="max-width:380px;">
    <div class="modal-title">Registrar Pagamento</div>
    <input type="hidden" id="pagId" value="0">
    <div style="display:flex;flex-direction:column;gap:14px;">
      <div class="field">
        <label>Valor pago (R$) *</label>
        <input type="number" id="pagValor" min="0.01" step="0.01" placeholder="0,00">
      </div>
      <div class="field">
        <label>Data do pagamento</label>
        <input type="date" id="pagData" value="<?= $hoje ?>">
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-secondary" type="button" onclick="document.getElementById('pagBackdrop').classList.add('hidden')">Cancelar</button>
      <button class="btn-primary" type="button" onclick="confirmarPagamento()">Confirmar</button>
    </div>
  </div>
</div>

<script>
function abrirModal(id=0) {
  document.getElementById('mId').value = id;
  document.getElementById('modalTitulo').textContent = id ? 'Editar Conta' : 'Nova Conta a Pagar';
  if (!id) {
    ['mDesc','mFornecedor','mValor','mVenc','mObs'].forEach(id => document.getElementById(id).value = '');
  }
  document.getElementById('modalBackdrop').classList.remove('hidden');
  document.getElementById('mDesc').focus();
}

function editarLancamento(r) {
  document.getElementById('mId').value        = r.id;
  document.getElementById('modalTitulo').textContent = 'Editar Conta';
  document.getElementById('mDesc').value      = r.descricao || '';
  document.getElementById('mFornecedor').value= r.fornecedor || '';
  document.getElementById('mCat').value       = r.categoria || 'Fornecedor';
  document.getElementById('mValor').value     = r.valor || '';
  document.getElementById('mEmissao').value   = r.data_emissao || '';
  document.getElementById('mVenc').value      = r.data_vencimento || '';
  document.getElementById('mObs').value       = r.observacoes || '';
  document.getElementById('modalBackdrop').classList.remove('hidden');
}

function fecharModal(e) {
  if (e.target === document.getElementById('modalBackdrop'))
    document.getElementById('modalBackdrop').classList.add('hidden');
}

async function salvarModal() {
  const btn = document.getElementById('btnSalvarModal');
  const desc = document.getElementById('mDesc').value.trim();
  const valor = document.getElementById('mValor').value;
  const venc  = document.getElementById('mVenc').value;
  if (!desc || !valor || !venc) { alert('Preencha descrição, valor e vencimento.'); return; }

  btn.disabled = true; btn.textContent = 'Salvando…';
  const fd = new FormData();
  fd.append('action',          'salvar');
  fd.append('id',              document.getElementById('mId').value);
  fd.append('descricao',       desc);
  fd.append('fornecedor',      document.getElementById('mFornecedor').value);
  fd.append('categoria',       document.getElementById('mCat').value);
  fd.append('valor',           valor);
  fd.append('data_emissao',    document.getElementById('mEmissao').value);
  fd.append('data_vencimento', venc);
  fd.append('observacoes',     document.getElementById('mObs').value);

  const d = await (await fetch(window.location.pathname,{method:'POST',body:fd})).json();
  btn.disabled = false; btn.textContent = 'Salvar';
  if (d.ok) { location.reload(); } else { alert(d.msg || 'Erro ao salvar.'); }
}

function abrirPagamento(id, valor, valorPago) {
  document.getElementById('pagId').value    = id;
  document.getElementById('pagValor').value = parseFloat(valor) - parseFloat(valorPago);
  document.getElementById('pagBackdrop').classList.remove('hidden');
  document.getElementById('pagValor').focus();
}

function fecharPagModal(e) {
  if (e.target === document.getElementById('pagBackdrop'))
    document.getElementById('pagBackdrop').classList.add('hidden');
}

async function confirmarPagamento() {
  const id    = document.getElementById('pagId').value;
  const valor = document.getElementById('pagValor').value;
  const data  = document.getElementById('pagData').value;
  if (!valor) { alert('Informe o valor pago.'); return; }

  const fd = new FormData();
  fd.append('action','pagar'); fd.append('id',id);
  fd.append('valor_pago',valor); fd.append('data_pagamento',data);
  const d = await (await fetch(window.location.pathname,{method:'POST',body:fd})).json();
  if (d.ok) { location.reload(); } else { alert(d.msg||'Erro.'); }
}

async function cancelar(id) {
  if (!confirm('Cancelar esta conta?')) return;
  const fd = new FormData(); fd.append('action','cancelar'); fd.append('id',id);
  const d = await (await fetch(window.location.pathname,{method:'POST',body:fd})).json();
  if (d.ok) location.reload(); else alert(d.msg||'Erro.');
}

async function excluir(id) {
  if (!confirm('Excluir este lançamento permanentemente?')) return;
  const fd = new FormData(); fd.append('action','excluir'); fd.append('id',id);
  const d = await (await fetch(window.location.pathname,{method:'POST',body:fd})).json();
  if (d.ok) { const el=document.getElementById('row-'+id); if(el){el.style.opacity=0;el.style.transition='.3s';setTimeout(()=>el.remove(),300);} }
  else alert(d.msg||'Erro.');
}
</script>
</body>
</html>
