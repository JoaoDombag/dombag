<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/relatorios/rel_defeitos_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Relatório de Defeitos (ERP Yzidro)
//  Regras de contagem e consultas: rel_defeitos_dados.php
// ══════════════════════════════════════════════════════════════

$filters = rdFiltros();

$pg      = pcpGetPG();
$error   = '';
$porOp   = [];
$porTipo = [];

// ── AJAX: detalhe de uma OP (painel lateral) ─────────────────────────────────
if (($_GET['action'] ?? '') === 'detalhe_op') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pg) {
        echo json_encode(['error' => 'Falha ao conectar no ERP.']);
        exit;
    }
    try {
        $pecas = rdFetchDetalheOp($pg, $filters, (int) ($_GET['op'] ?? 0), (int) ($_GET['pro'] ?? 0));
        echo json_encode(['pecas' => $pecas], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if (!$pg) {
    $error = 'Falha ao conectar no ERP.';
} else {
    try {
        $porOp   = rdFetchPorOp($pg, $filters);
        $porTipo = rdFetchPorTipo($pg, $filters);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    pg_close($pg);
}

$totalProduzidos  = array_sum(array_map(fn($r) => (float) $r['produzidos'], $porOp));
$totalComDefeito  = array_sum(array_map(fn($r) => (float) $r['com_defeito'], $porOp));
$totalCorrigidos  = array_sum(array_map(fn($r) => (float) $r['corrigidos'], $porOp));
$totalDescartados = array_sum(array_map(fn($r) => (float) $r['descartados'], $porOp));
$totalPendentes   = array_sum(array_map(fn($r) => (float) $r['pendentes'], $porOp));
$totalTipos       = array_sum(array_map(fn($r) => (float) $r['qtd'], $porTipo));

$qsPdf = http_build_query($filters);
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório de Defeitos | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .filter-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 16px 18px; margin-bottom: 16px;
  }
  .filter-grid { display: flex; flex-wrap: wrap; gap: 16px; align-items: end; }
  .filter-field { display: flex; flex-direction: column; gap: 6px; width: 170px; }
  .filter-field label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
  }
  .filter-field input {
    height: 36px; padding: 0 10px; font-size: 12.5px; width: 100%; min-width: 0;
    font-family: 'Segoe UI', sans-serif;
  }
  .filter-actions { display: flex; gap: 12px; }

  .totalizador { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
  .tot-card {
    flex: 1; min-width: 150px;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 12px 16px;
  }
  .tot-card.tot-main { border-color: rgba(79,123,255,.35); background: rgba(36,83,212,.08); }
  .tot-lbl { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
  .tot-val { font-size: 20px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
  .tot-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }

  .rd-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
  .rd-right  { text-align: right; white-space: nowrap; }
  .rd-num    { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .rd-zero   { color: var(--text-muted); }
  .rd-op-col { font-weight: 700; color: #7db3ff; white-space: nowrap; }
  .rd-cliente-col { min-width: 160px; font-weight: 500; }
  .rd-produto-col { min-width: 200px; }
  .rd-total td { font-weight: 700; border-top: 2px solid var(--border); }
  .rd-linha-op { cursor: pointer; }
  .rd-linha-op:hover td { background: var(--card-hover); }
  .rd-linha-op:focus-visible { outline: 2px solid #7db3ff; outline-offset: -2px; }

  /* ── Painel lateral: detalhe da OP ───────────────────────────────────────── */
  .rd-drawer-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 300; }
  .rd-drawer-overlay.open { opacity: 1; pointer-events: all; }
  .rd-drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 560px; max-width: calc(100vw - 24px); background: var(--modal-bg, #152845); border-left: 1px solid var(--border); box-shadow: -12px 0 40px rgba(0,0,0,.45); transform: translateX(100%); transition: transform .25s ease; z-index: 301; display: flex; flex-direction: column; }
  .rd-drawer.open { transform: translateX(0); }
  .rd-drawer-head { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-shrink: 0; }
  .rd-drawer-title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
  .rd-drawer-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
  .rd-drawer-acoes { display: flex; gap: 8px; flex-shrink: 0; }
  .rd-drawer-close { width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; }
  .rd-drawer-close:hover { background: var(--card-hover); color: var(--text-primary); }
  .rd-drawer-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
  .rd-drawer-loading { text-align: center; padding: 40px 10px; color: var(--text-muted); font-size: 12.5px; }
  .rd-resumo { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; margin-bottom: 16px; }
  .rd-resumo div { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; min-width: 0; }
  .rd-resumo span { display: block; font-size: 9.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .03em; overflow-wrap: anywhere; }
  .rd-resumo b { display: block; font-size: 16px; margin-top: 2px; font-variant-numeric: tabular-nums; }
  .rd-peca { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; }
  .rd-peca-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12.5px; font-weight: 700; }
  .rd-badge { padding: 1px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
  .rd-badge.corrigida  { background: rgba(0,201,167,.14); color: var(--teal); }
  .rd-badge.descartada { background: rgba(239,68,68,.12); color: var(--red); }
  .rd-badge.pendente   { background: rgba(240,166,56,.14); color: #f0a638; }
  .rd-def { margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); font-size: 12px; }
  .rd-def-tipo { font-weight: 600; }
  .rd-def-info { font-size: 11px; color: var(--text-muted); margin-top: 2px; line-height: 1.5; }

  .rd-empty-state { padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px; }
  .alert-error {
    padding: 12px 16px; border-radius: var(--radius); background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2); color: var(--red); font-size: 13px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
  }

  @media (max-width: 1100px) {
    .rd-grid { grid-template-columns: minmax(0, 1fr); }
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
          <h1>Relatório de Defeitos</h1>
          <p>Bags produzidos, com defeito, corrigidos e descartados no período. Somente leitura.</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button class="btn-secondary" id="btnPdf">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Gerar PDF
        </button>
      </div>
    </header>

    <div class="content">

      <?php if ($error !== ''): ?>
        <div class="alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= rdEscape($error) ?>
        </div>
      <?php endif; ?>

      <!-- Filtros -->
      <div class="filter-card">
        <form method="get" action="<?= rdEscape(rdBaseUrl()) ?>">
          <div class="filter-grid">
            <div class="filter-field">
              <label for="data_ini">Data inicial</label>
              <input type="date" id="data_ini" name="data_ini" value="<?= rdEscape($filters['data_ini']) ?>">
            </div>
            <div class="filter-field">
              <label for="data_fim">Data final</label>
              <input type="date" id="data_fim" name="data_fim" value="<?= rdEscape($filters['data_fim']) ?>">
            </div>
            <div class="filter-actions">
              <button type="submit" class="btn-refresh">Filtrar</button>
              <a href="<?= rdEscape(rdBaseUrl()) ?>" class="icon-btn" title="Voltar ao mês atual" style="text-decoration:none;width:auto;padding:0 12px;font-size:13px;font-weight:600;">Limpar</a>
            </div>
          </div>
        </form>
      </div>

      <!-- Totalizador -->
      <div class="totalizador">
        <div class="tot-card tot-main">
          <div class="tot-lbl">Bags Produzidos</div>
          <div class="tot-val"><?= rdQty($totalProduzidos) ?> <span style="font-size:12px;font-weight:500;color:var(--text-muted);">bags</span></div>
        </div>
        <div class="tot-card">
          <div class="tot-lbl">Com defeito</div>
          <div class="tot-val"><?= rdQty($totalComDefeito) ?></div>
          <div class="tot-sub"><?= rdPct($totalComDefeito, $totalProduzidos) ?> dos produzidos</div>
        </div>
        <div class="tot-card">
          <div class="tot-lbl">Corrigidos</div>
          <div class="tot-val"><?= rdQty($totalCorrigidos) ?></div>
          <div class="tot-sub"><?= rdPct($totalCorrigidos, $totalComDefeito) ?> dos defeitos</div>
        </div>
        <div class="tot-card">
          <div class="tot-lbl">Descartados</div>
          <div class="tot-val"><?= rdQty($totalDescartados) ?></div>
          <div class="tot-sub"><?= rdPct($totalDescartados, $totalComDefeito) ?> dos defeitos</div>
        </div>
        <div class="tot-card">
          <div class="tot-lbl">Pendentes</div>
          <div class="tot-val"><?= rdQty($totalPendentes) ?></div>
          <div class="tot-sub"><?= rdPct($totalPendentes, $totalComDefeito) ?> dos defeitos</div>
        </div>
      </div>

      <div class="rd-grid">
        <!-- Por OP -->
        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title">Totais por O.P</span>
            <span class="source-badge src-erp">ERP</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>O.P</th>
                  <th>Cliente</th>
                  <th>Produto</th>
                  <th class="rd-right">Bags Produzidos</th>
                  <th class="rd-right">Com defeito</th>
                  <th class="rd-right">Corrigidos</th>
                  <th class="rd-right">Descartados</th>
                  <th class="rd-right">Pendentes</th>
                  <th class="rd-right">% defeito</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$porOp): ?>
                  <tr><td colspan="9"><div class="rd-empty-state">Nenhuma produção ou defeito no período informado.</div></td></tr>
                <?php else: foreach ($porOp as $row): ?>
                  <tr class="rd-linha-op" tabindex="0" title="Ver detalhes" data-op="<?= rdEscape($row['op']) ?>" data-pro="<?= rdEscape($row['pro']) ?>">
                    <td class="rd-op-col"><?= rdEscape($row['op']) ?></td>
                    <td class="rd-cliente-col"><?= rdEscape($row['cliente'] !== '' ? $row['cliente'] : '—') ?></td>
                    <td class="rd-produto-col"><?= rdEscape($row['produto'] ?? '—') ?></td>
                    <?php foreach (['produzidos', 'com_defeito', 'corrigidos', 'descartados', 'pendentes'] as $col): ?>
                      <td class="rd-num<?= (float) $row[$col] == 0.0 ? ' rd-zero' : '' ?>"><?= rdQty($row[$col]) ?></td>
                    <?php endforeach; ?>
                    <td class="rd-num"><?= rdPct((float) $row['com_defeito'], (float) $row['produzidos']) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
              <?php if ($porOp): ?>
              <tfoot>
                <tr class="rd-total">
                  <td colspan="3" style="text-align:right;">Total</td>
                  <td class="rd-num"><?= rdQty($totalProduzidos) ?></td>
                  <td class="rd-num"><?= rdQty($totalComDefeito) ?></td>
                  <td class="rd-num"><?= rdQty($totalCorrigidos) ?></td>
                  <td class="rd-num"><?= rdQty($totalDescartados) ?></td>
                  <td class="rd-num"><?= rdQty($totalPendentes) ?></td>
                  <td class="rd-num"><?= rdPct($totalComDefeito, $totalProduzidos) ?></td>
                </tr>
              </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>

        <!-- Por tipo -->
        <div class="panel-table">
          <div class="panel-header">
            <span class="panel-title">Apontamentos por tipo</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Tipo</th>
                  <th class="rd-right">Qtd</th>
                  <th class="rd-right">%</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$porTipo): ?>
                  <tr><td colspan="3"><div class="rd-empty-state">Nenhum defeito no período.</div></td></tr>
                <?php else: foreach ($porTipo as $row): ?>
                  <tr>
                    <td><?= rdEscape($row['tipo']) ?></td>
                    <td class="rd-num"><?= rdQty($row['qtd']) ?></td>
                    <td class="rd-num"><?= rdPct((float) $row['qtd'], $totalTipos) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
              <?php if ($porTipo): ?>
              <tfoot>
                <tr class="rd-total">
                  <td style="text-align:right;">Total</td>
                  <td class="rd-num"><?= rdQty($totalTipos) ?></td>
                  <td></td>
                </tr>
              </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /app-wrapper -->

<!-- Painel lateral de detalhes da OP -->
<div class="rd-drawer-overlay" id="rdDrawerOverlay"></div>
<div class="rd-drawer" id="rdDrawer">
  <div class="rd-drawer-head">
    <div style="min-width:0;">
      <div class="rd-drawer-title" id="rdDrawerTitle">—</div>
      <div class="rd-drawer-sub" id="rdDrawerSub">—</div>
    </div>
    <div class="rd-drawer-acoes">
      <button type="button" class="btn-secondary" id="rdDrawerPdf">Gerar PDF</button>
      <button type="button" class="rd-drawer-close" id="rdDrawerClose" title="Fechar">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  </div>
  <div class="rd-drawer-body" id="rdDrawerBody"></div>
</div>

<script>
  const RD_URL = <?= json_encode(rdBaseUrl(), JSON_UNESCAPED_SLASHES) ?>;
  const RD_QS = <?= json_encode($qsPdf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.getElementById('btnPdf').addEventListener('click', () => {
    window.open(RD_URL + '/pdf?' + RD_QS, '_blank');
  });

  function rdEsc(v) {
    const el = document.createElement('div');
    el.textContent = v;
    return el.innerHTML;
  }

  // ── Painel lateral: peças com defeito da OP clicada ────────────────────────
  const rdDrawerBody = document.getElementById('rdDrawerBody');
  let rdOpAberta = null;

  function rdHtmlPeca(p) {
    const defeitos = p.defeitos.map(d => `
      <div class="rd-def">
        <div class="rd-def-tipo">${rdEsc(d.tipo)}</div>
        <div class="rd-def-info">
          ${rdEsc(d.data)} · Gerado por ${rdEsc(d.gerado_por)} · Apontado por ${rdEsc(d.apontado_por)}
          ${d.corrigido_em ? `<br>Corrigido por ${rdEsc(d.corrigido_por)} em ${rdEsc(d.corrigido_em)}` : ''}
        </div>
      </div>`).join('');
    return `
      <div class="rd-peca">
        <div class="rd-peca-head">
          <span>${rdEsc(p.nsu)}</span>
          <span class="rd-badge ${p.situacao.toLowerCase()}">${rdEsc(p.situacao)}</span>
        </div>
        ${defeitos}
      </div>`;
  }

  function rdAbrirDetalhe(linha) {
    const c = linha.cells;
    const cabecalho = linha.closest('table').tHead.rows[0].cells;
    const resumo = [3, 4, 5, 6, 7].map(i =>
      `<div><span>${rdEsc(cabecalho[i].textContent)}</span><b>${rdEsc(c[i].textContent)}</b></div>`
    ).join('');
    const { op, pro } = linha.dataset;
    const aberta = { op, pro };
    rdOpAberta = aberta;
    document.getElementById('rdDrawerTitle').textContent = 'O.P ' + op;
    document.getElementById('rdDrawerSub').textContent = c[1].textContent + ' · ' + c[2].textContent;
    rdDrawerBody.innerHTML = `<div class="rd-resumo">${resumo}</div><div id="rdPecas" class="rd-drawer-loading">Carregando...</div>`;
    document.getElementById('rdDrawer').classList.add('open');
    document.getElementById('rdDrawerOverlay').classList.add('open');

    const params = new URLSearchParams(RD_QS);
    params.set('action', 'detalhe_op');
    params.set('op', op);
    params.set('pro', pro);
    const pecasEl = document.getElementById('rdPecas');
    fetch('?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json())
      .then(data => {
        if (rdOpAberta !== aberta) return; // já clicou em outra OP
        if (data.error) throw new Error(data.error);
        if (!data.pecas.length) {
          pecasEl.textContent = 'Nenhum defeito nesta O.P no período.';
          return;
        }
        pecasEl.className = '';
        pecasEl.innerHTML = data.pecas.map(rdHtmlPeca).join('');
      })
      .catch(() => {
        if (rdOpAberta === aberta) pecasEl.textContent = 'Erro ao carregar os dados.';
      });
  }

  function rdFecharDetalhe() {
    rdOpAberta = null;
    document.getElementById('rdDrawer').classList.remove('open');
    document.getElementById('rdDrawerOverlay').classList.remove('open');
  }

  document.querySelectorAll('.rd-linha-op').forEach(linha => {
    linha.addEventListener('click', () => rdAbrirDetalhe(linha));
    linha.addEventListener('keydown', e => { if (e.key === 'Enter') rdAbrirDetalhe(linha); });
  });
  document.getElementById('rdDrawerPdf').addEventListener('click', () => {
    window.open(`${RD_URL}/pdf?${RD_QS}&op=${rdOpAberta.op}&pro=${rdOpAberta.pro}`, '_blank');
  });
  document.getElementById('rdDrawerClose').addEventListener('click', rdFecharDetalhe);
  document.getElementById('rdDrawerOverlay').addEventListener('click', rdFecharDetalhe);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') rdFecharDetalhe(); });
</script>
</body>
</html>
