<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/pcp_engine.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/producao_tipo.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/relatorios/rel_defeitos_dados.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/rastreabilidade_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Rastreabilidade: busca por NSU, O.P, pedido ou lote
//  ?campo=…&q=termo    → procura só no campo escolhido (1 resultado abre direto)
//  ?tipo=nsu|op|lote&id=… → ficha do resultado
//  Consultas: rastreabilidade_dados.php
// ══════════════════════════════════════════════════════════════

$url    = '/pcp/rastreabilidade';
$q      = trim((string) ($_GET['q'] ?? ''));
$campo  = (string) ($_GET['campo'] ?? '');
$campo  = isset(RT_CAMPOS[$campo]) ? $campo : 'nsu';
$busca  = ['q' => $q, 'campo' => $campo];
$tipo   = (string) ($_GET['tipo'] ?? '');
$id     = trim((string) ($_GET['id'] ?? ''));
$limite = max(RT_BAGS_PAGINA, (int) ($_GET['limite'] ?? RT_BAGS_PAGINA));

$error      = '';
$resultados = null;
$ficha      = null;

$pg = ($q !== '' || $id !== '') ? pcpGetPG() : null;
if (($q !== '' || $id !== '') && !$pg) {
    $error = 'Falha ao conectar no ERP.';
} elseif ($pg) {
    try {
        if ($id === '') {
            $resultados = rtBuscar($pg, $q, $campo);
            $nNsu = count($resultados['nsu']);
            $nOp  = count($resultados['op']);
            $nLt  = count($resultados['lote']);
            if ($nNsu + $nOp + $nLt === 1) {
                [$tipo, $id] = $nNsu ? ['nsu', $resultados['nsu'][0]['nsu']]
                    : ($nOp ? ['op', $resultados['op'][0]['op']] : ['lote', $resultados['lote'][0]['lt_codigo']]);
            }
        }
        if ($id !== '') {
            $ficha = match ($tipo) {
                'nsu'   => rtFichaBag($pg, $id),
                'op'    => ctype_digit($id) && strlen($id) < 10 ? rtFichaOp($pg, (int) $id, $limite) : null,
                'lote'  => ctype_digit($id) && strlen($id) < 10 ? rtFichaLote($pg, (int) $id) : null,
                default => null,
            };
            if ($ficha === null) {
                $error = 'Registro não encontrado no ERP.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    pg_close($pg);
}

function rtLink(string $tipo, $id, array $busca): string
{
    return '/pcp/rastreabilidade?' . http_build_query(['tipo' => $tipo, 'id' => $id] + $busca);
}

function rtBadgeClasse(string $situacao): string
{
    return ['Descartada' => 'ruim', 'Pendente' => 'alerta', 'Corrigida' => 'ok', 'Sem defeito' => 'ok'][$situacao] ?? 'ok';
}

// Tabela de lotes consumidos: usada na ficha do bag e na da OP
function rtTabelaLotes(array $lotes, array $busca): void
{
    if (!$lotes) {
        echo '<div class="rt-vazio">Nenhum consumo de material registrado nesta O.P.</div>';
        return;
    }
    echo '<div class="table-wrap"><table><thead><tr><th>Material</th><th>Lote</th><th>Fornecedor</th><th>Nota de compra</th><th>Fabricação</th><th class="rt-num">Consumido</th></tr></thead><tbody>';
    foreach ($lotes as $l) {
        $lote = $l['lt_codigo'] === null
            ? '<span class="rt-muted">Sem lote</span>'
            : '<a class="rt-link" href="' . rtE(rtLink('lote', $l['lt_codigo'], $busca)) . '">' . rtE($l['lote'] ?: $l['lt_codigo']) . '</a>';
        echo '<tr><td>' . rtE($l['material'] ?? '—') . '</td><td class="rt-nowrap">' . $lote . '</td><td>' . rtE($l['fornecedor'] ?? '—')
            . '</td><td class="rt-nowrap">' . rtE($l['nota'] ?? '—') . '</td><td class="rt-nowrap">' . rtFmtData($l['fabricacao'], false)
            . '</td><td class="rt-num">' . rtQtd($l['qtd']) . ' ' . rtE($l['unidade'] ?? '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rastreabilidade | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .rt-busca { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; margin-bottom: 16px; }
  .rt-busca form { display: flex; gap: 12px; }
  .rt-busca select { width: 130px; flex: none; height: 40px; padding: 0 10px; font-size: 14px; font-family: 'Segoe UI', sans-serif; }
  .rt-busca input { flex: 1; min-width: 0; height: 40px; padding: 0 12px; font-size: 14px; font-family: 'Segoe UI', sans-serif; }
  .rt-dica { font-size: 11.5px; color: var(--text-muted); margin-top: 8px; }

  .rt-cab { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
  .rt-cab h2 { font-size: 17px; font-weight: 700; overflow-wrap: anywhere; }
  .rt-voltar { font-size: 12px; color: #7db3ff; text-decoration: none; margin-bottom: 10px; display: inline-block; }
  .rt-dados { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-bottom: 16px; }
  .rt-dado { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; min-width: 0; }
  .rt-dado.destaque { border-color: rgba(79,123,255,.35); background: rgba(36,83,212,.08); }
  .rt-dado span { display: block; font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
  .rt-dado b { display: block; font-size: 14px; margin-top: 3px; overflow-wrap: anywhere; }
  .rt-nota { font-size: 11.5px; color: var(--text-muted); margin: -6px 0 16px; }

  /* unified_admin prende o conteúdo à altura da tela: aqui a página rola inteira */
  .content { display: block; overflow-y: auto; }
  .panel-table.rt-bloco { display: block; height: auto; min-height: 0; max-height: none; flex: none; overflow: visible; margin-bottom: 16px; }
  .rt-bloco .table-wrap { height: auto; max-height: none; flex: none; overflow-x: auto; overflow-y: visible; }
  .rt-aviso { font-size: 12px; color: var(--text-muted); padding: 10px 16px; border-top: 1px solid var(--border); }
  .rt-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .rt-nowrap { white-space: nowrap; }
  .rt-muted { color: var(--text-muted); }
  .rt-link { color: #7db3ff; font-weight: 600; text-decoration: none; }
  .rt-link:hover { text-decoration: underline; }
  .rt-vazio { padding: 22px; text-align: center; color: var(--text-muted); font-size: 13px; }
  .rt-mais { display: block; padding: 10px; text-align: center; font-size: 12.5px; }
  .rt-badge { padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
  .rt-badge.ok     { background: rgba(0,201,167,.14); color: var(--teal); }
  .rt-badge.ruim   { background: rgba(239,68,68,.12); color: var(--red); }
  .rt-badge.alerta { background: rgba(240,166,56,.14); color: #f0a638; }
  .alert-error {
    padding: 12px 16px; border-radius: var(--radius); background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2); color: var(--red); font-size: 13px; margin-bottom: 16px;
  }
  @media (max-width: 600px) { .rt-busca form { flex-direction: column; } .rt-busca input, .rt-busca select { flex: none; width: 100%; } }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/shared/sidebar.php'; ?>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <div class="page-title">
          <h1>Rastreabilidade</h1>
          <p>Encontre um bag, uma O.P, um pedido ou um lote: quem produziu, quando e com qual material.</p>
        </div>
      </div>
    </header>

    <div class="content">

      <div class="rt-busca">
        <form method="get" action="<?= rtE($url) ?>">
          <select name="campo" id="rtCampo" aria-label="Buscar por">
            <?php foreach (RT_CAMPOS as $valor => $rotulo): ?>
              <option value="<?= rtE($valor) ?>" <?= $valor === $campo ? 'selected' : '' ?>><?= rtE($rotulo) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="search" name="q" id="rtTermo" value="<?= rtE($q) ?>" aria-label="Termo da busca" autofocus required>
          <button type="submit" class="btn-refresh">Buscar</button>
        </form>
        <div class="rt-dica">NSU e lote aceitam parte do número. O.P e pedido precisam do número completo.</div>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert-error"><?= rtE($error) ?></div>
      <?php endif; ?>

      <?php if ($ficha !== null && $q !== '' && $resultados === null): ?>
        <a class="rt-voltar" href="<?= rtE($url . '?' . http_build_query($busca)) ?>">← Voltar aos resultados de "<?= rtE($q) ?>"</a>
      <?php endif; ?>

      <?php if ($ficha !== null && $tipo === 'nsu'):
          $i = $ficha['item']; $u = $ficha['ultima']; ?>
        <div class="rt-cab">
          <h2>Bag <?= rtE($ficha['nsu']) ?></h2>
          <span class="rt-badge <?= rtBadgeClasse($ficha['situacao']) ?>"><?= rtE($ficha['situacao']) ?></span>
        </div>
        <div class="rt-dados">
          <div class="rt-dado destaque"><span>Quem produziu</span><b><?= rtE($u['funcionario'] ?? '—') ?></b></div>
          <div class="rt-dado destaque"><span>Horário</span><b><?= rtFmtData($u['data_hora']) ?></b></div>
          <div class="rt-dado"><span>Lote do produto final</span><b><?= rtE($ficha['lote_final'] ?? '—') ?></b></div>
          <div class="rt-dado"><span>O.P</span><b><a class="rt-link" href="<?= rtE(rtLink('op', $u['op'], $busca)) ?>"><?= rtE($u['op']) ?></a></b></div>
          <div class="rt-dado"><span>Cliente</span><b><?= rtE($i['cliente'] ?? '—') ?></b></div>
          <div class="rt-dado"><span>Pedido</span><b><?= rtE(($i['pedido'] ?? '') ?: '—') ?></b></div>
          <div class="rt-dado" style="grid-column:1/-1;"><span>Produto</span><b><?= rtE($i['produto'] ?? '—') ?></b></div>
        </div>
        <div class="rt-nota">O ERP registra o lote do produto final no fechamento da O.P, não por bag. Em entrega parcial, este pode não ser o lote físico do bag.</div>

        <div class="panel-table rt-bloco">
          <div class="panel-header"><span class="panel-title">Lotes da O.P <?= rtE($u['op']) ?></span><span class="source-badge src-erp">ERP</span></div>
          <?php rtTabelaLotes($ficha['lotes'], $busca); ?>
          <div class="rt-aviso">O ERP registra os lotes consumidos pela O.P, não por bag. Estes são todos os lotes que podem ter sido usados neste bag.</div>
        </div>

        <div class="panel-table rt-bloco">
          <div class="panel-header"><span class="panel-title">Leituras da etiqueta</span></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Horário</th><th>Atividade</th><th>Funcionário</th><th>O.P</th></tr></thead>
            <tbody>
              <?php foreach ($ficha['leituras'] as $l): ?>
                <tr>
                  <td class="rt-nowrap"><?= rtFmtData($l['data_hora']) ?></td>
                  <td><?= rtE($l['atividade'] ?? '—') ?></td>
                  <td><?= rtE($l['funcionario'] ?? '—') ?></td>
                  <td><?= rtE($l['op']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>

        <div class="panel-table rt-bloco">
          <div class="panel-header"><span class="panel-title">Defeitos</span></div>
          <?php if (!$ficha['defeitos']): ?>
            <div class="rt-vazio">Nenhum defeito apontado neste bag.</div>
          <?php else: ?>
            <div class="table-wrap"><table>
              <thead><tr><th>Defeito</th><th>Horário</th><th>Gerado por</th><th>Apontado por</th><th>Corrigido por</th><th>Corrigido em</th></tr></thead>
              <tbody>
                <?php foreach ($ficha['defeitos'] as $d): $ok = $d['correcao_fim'] !== null; ?>
                  <tr>
                    <td><?= rtE($d['tipo']) ?></td>
                    <td class="rt-nowrap"><?= rtFmtData($d['data_hora']) ?></td>
                    <td><?= rtE($d['gerado_por'] ?? '—') ?></td>
                    <td><?= rtE($d['apontado_por'] ?? '—') ?></td>
                    <td><?= rtE($ok ? ($d['corrigido_por'] ?? '—') : '—') ?></td>
                    <td class="rt-nowrap"><?= $ok ? rtFmtData($d['correcao_fim']) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>
        </div>

      <?php elseif ($ficha !== null && $tipo === 'op'):
          $i = $ficha['itens'][0];
          $bag = $ficha['producao']['tipos']['bag'] ?? null;
          $sac = $ficha['producao']['tipos']['sacaria'] ?? null;
          $sacaria = $bag === null;
          $qsMais = ['tipo' => 'op', 'id' => $id] + $busca + ['limite' => $limite + RT_BAGS_PAGINA]; ?>
        <div class="rt-cab"><h2>O.P <?= rtE($id) ?></h2></div>
        <div class="rt-dados">
          <div class="rt-dado"><span>Cliente</span><b><?= rtE($i['cliente'] ?? '—') ?></b></div>
          <div class="rt-dado"><span>Pedido</span><b><?= rtE(($i['pedido'] ?? '') ?: '—') ?></b></div>
          <div class="rt-dado"><span>Emissão</span><b><?= rtFmtData($i['data_emissao'], false) ?></b></div>
          <div class="rt-dado"><span>Entrega</span><b><?= rtFmtData($i['data_entrega'], false) ?></b></div>
          <div class="rt-dado"><span>Finalizada em</span><b><?= rtFmtData($i['data_finaliza'], false) ?></b></div>
          <?php foreach ($ficha['producao']['linhas'] as $it): ?>
            <div class="rt-dado" style="grid-column:1/-1;">
              <span>Produto · pedido <?= rtQtd($it['pedido']) ?> · produzido <?= rtQtd($it['produzido']) ?><?= $it['com_defeito'] > 0 ? ' · com defeito ' . rtQtd($it['com_defeito']) : '' ?> · lote final <?= rtE($ficha['lotes_finais'][$it['pro']] ?? '—') ?></span>
              <b><?= rtE($it['produto']) ?></b>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="rt-dados">
          <?php if ($bag !== null): ?>
            <div class="rt-dado"><span>Pedido (bags)</span><b><?= rtQtd($bag['pedido']) ?></b></div>
            <div class="rt-dado destaque"><span>Bags produzidos</span><b><?= rtQtd($bag['produzido']) ?></b></div>
            <div class="rt-dado"><span>Bags lidos</span><b><?= rtQtd($ficha['total_bags']) ?></b></div>
            <div class="rt-dado"><span>Fechado no ERP</span><b><?= rtQtd($bag['fechado']) ?></b></div>
            <?php if ($bag['aberto'] > 0): ?>
              <div class="rt-dado"><span>Em aberto no ERP</span><b><?= rtQtd($bag['aberto']) ?></b></div>
            <?php endif; ?>
            <div class="rt-dado destaque"><span>Bags com defeito</span><b><?= rtQtd($bag['com_defeito']) ?><?= $bag['produzido'] > 0 ? ' (' . rdPct($bag['com_defeito'], $bag['produzido']) . ')' : '' ?></b></div>
            <div class="rt-dado"><span>Corrigidos</span><b><?= rtQtd($bag['corrigidos']) ?></b></div>
            <div class="rt-dado"><span>Pendentes</span><b><?= rtQtd($bag['pendentes']) ?></b></div>
            <div class="rt-dado"><span>Descartados</span><b><?= rtQtd($bag['descartados']) ?></b></div>
          <?php endif; ?>
          <?php if ($sac !== null): ?>
            <div class="rt-dado"><span>Pedido (sacarias)</span><b><?= rtQtd($sac['pedido']) ?></b></div>
            <div class="rt-dado destaque"><span>Sacarias produzidas</span><b><?= rtQtd($sac['produzido']) ?></b></div>
            <?php if ($sac['aberto'] > 0): ?>
              <div class="rt-dado"><span>Sacarias em aberto no ERP</span><b><?= rtQtd($sac['aberto']) ?></b></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($bag !== null): ?>
          <div class="rt-nota">Produzidos são os bags apontados na análise. Fechado no ERP é o lançamento de encerramento: em entrega parcial as leituras continuam nesta O.P e os dois números ficam diferentes. Cada bag entra uma vez em defeito, mesmo com vários apontamentos.</div>
        <?php endif; ?>

        <?php if ($ficha['defeitos_tipo']): ?>
          <div class="panel-table rt-bloco">
            <div class="panel-header"><span class="panel-title">Defeitos por tipo</span></div>
            <div class="table-wrap"><table>
              <thead><tr><th>Defeito</th><th class="rt-num">Apontamentos</th></tr></thead>
              <tbody>
                <?php foreach ($ficha['defeitos_tipo'] as $d): ?>
                  <tr><td><?= rtE($d['tipo']) ?></td><td class="rt-num"><?= rtQtd($d['qtd']) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          </div>
        <?php endif; ?>

        <div class="panel-table rt-bloco">
          <div class="panel-header"><span class="panel-title">Lotes consumidos</span><span class="source-badge src-erp">ERP</span></div>
          <?php rtTabelaLotes($ficha['lotes'], $busca); ?>
        </div>

        <?php if ($ficha['produzido']): ?>
          <div class="panel-table rt-bloco">
            <div class="panel-header"><span class="panel-title">Lotes produzidos</span></div>
            <div class="table-wrap"><table>
              <thead><tr><th>Lote</th><th>Produto</th><th>Data</th><th class="rt-num">Quantidade</th></tr></thead>
              <tbody>
                <?php foreach ($ficha['produzido'] as $p): ?>
                  <tr>
                    <td class="rt-nowrap"><?= $p['lt_codigo'] !== null ? '<a class="rt-link" href="' . rtE(rtLink('lote', $p['lt_codigo'], $busca)) . '">' . rtE($p['lote'] ?: $p['lt_codigo']) . '</a>' : '—' ?></td>
                    <td><?= rtE($p['produto'] ?? '—') ?></td>
                    <td class="rt-nowrap"><?= rtFmtData($p['data'], false) ?></td>
                    <td class="rt-num"><?= rtQtd($p['qtd']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          </div>
        <?php endif; ?>

        <?php if ($sacaria): ?>
          <div class="panel-table rt-bloco"><div class="rt-vazio">Sacaria não tem NSU nem apontamento no ERP, então não há registro de quem produziu nem de defeitos.</div></div>
        <?php else: ?>
          <div class="panel-table rt-bloco">
            <div class="panel-header"><span class="panel-title">Quem produziu</span></div>
            <?php if (!$ficha['por_pessoa']): ?>
              <div class="rt-vazio">Nenhum bag lido nesta O.P.</div>
            <?php else: ?>
              <div class="table-wrap"><table>
                <thead><tr><th>Funcionário</th><th>Primeiro bag</th><th>Último bag</th><th class="rt-num">Bags</th></tr></thead>
                <tbody>
                  <?php foreach ($ficha['por_pessoa'] as $p): ?>
                    <tr>
                      <td><?= rtE($p['funcionario'] ?? '—') ?></td>
                      <td class="rt-nowrap"><?= rtFmtData($p['primeira']) ?></td>
                      <td class="rt-nowrap"><?= rtFmtData($p['ultima']) ?></td>
                      <td class="rt-num"><?= rtQtd($p['bags']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table></div>
            <?php endif; ?>
          </div>

          <?php if ($ficha['bags']): ?>
            <div class="panel-table rt-bloco">
              <div class="panel-header"><span class="panel-title">Bags (mais recentes primeiro)</span></div>
              <div class="table-wrap"><table>
                <thead><tr><th>NSU</th><th>Horário</th><th>Quem produziu</th><th>Lote do produto final</th><?= count($ficha['itens']) > 1 ? '<th>Produto</th>' : '' ?><th>Defeito</th></tr></thead>
                <tbody>
                  <?php foreach ($ficha['bags'] as $b): ?>
                    <tr>
                      <td class="rt-nowrap"><a class="rt-link" href="<?= rtE(rtLink('nsu', $b['nsu'], $busca)) ?>"><?= rtE($b['nsu']) ?></a></td>
                      <td class="rt-nowrap"><?= rtFmtData($b['data_hora']) ?></td>
                      <td><?= rtE($b['funcionario'] ?? '—') ?></td>
                      <td class="rt-nowrap"><?= rtE($ficha['lotes_finais'][$b['pro']] ?? '—') ?></td>
                      <?php if (count($ficha['itens']) > 1): ?><td><?= rtE($b['produto'] ?? '—') ?></td><?php endif; ?>
                      <td><?= (int) $b['defeitos'] > 0 ? '<span class="rt-badge alerta">' . (int) $b['defeitos'] . '</span>' : '<span class="rt-muted">—</span>' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table></div>
              <?php if (count($ficha['bags']) >= $limite): ?>
                <a class="rt-link rt-mais" href="<?= rtE($url . '?' . http_build_query($qsMais)) ?>">Mostrar mais <?= RT_BAGS_PAGINA ?> (<?= rtQtd(count($ficha['bags'])) ?> de <?= rtQtd($ficha['total_bags']) ?>)</a>
              <?php endif; ?>
              <div class="rt-aviso">O ERP registra o lote do produto final no fechamento da O.P, não por bag. Em entrega parcial, este pode não ser o lote físico do bag.</div>
            </div>
          <?php endif; ?>
        <?php endif; ?>

      <?php elseif ($ficha !== null && $tipo === 'lote'):
          $l = $ficha['lote'];
          $totalBags = array_sum(array_map(fn ($c) => (int) $c['bags'], $ficha['consumo'])); ?>
        <div class="rt-cab"><h2>Lote <?= rtE($l['lote'] ?: $l['lt_codigo']) ?></h2></div>
        <div class="rt-dados">
          <div class="rt-dado" style="grid-column:1/-1;"><span>Material</span><b><?= rtE($l['material'] ?? '—') ?></b></div>
          <div class="rt-dado"><span>Fornecedor</span><b><?= rtE($l['fornecedor'] ?? '—') ?></b></div>
          <div class="rt-dado"><span>Nota de compra</span><b><?= rtE($l['nota'] ?? '—') ?></b></div>
          <div class="rt-dado"><span>Recebimento</span><b><?= rtFmtData($l['recebimento'], false) ?></b></div>
          <div class="rt-dado"><span>Fabricação</span><b><?= rtFmtData($l['fabricacao'], false) ?></b></div>
          <div class="rt-dado destaque"><span>O.Ps que usaram</span><b><?= rtQtd(count(array_unique(array_column($ficha['consumo'], 'op')))) ?></b></div>
          <div class="rt-dado destaque"><span>Bags nessas O.Ps</span><b><?= rtQtd($totalBags) ?></b></div>
        </div>
        <div class="panel-table rt-bloco">
          <div class="panel-header"><span class="panel-title">O.Ps que consumiram este lote</span><span class="source-badge src-erp">ERP</span></div>
          <?php if (!$ficha['consumo']): ?>
            <div class="rt-vazio">Este lote ainda não foi consumido em nenhuma O.P.</div>
          <?php else: ?>
            <div class="table-wrap"><table>
              <thead><tr><th>O.P</th><th>Pedido</th><th>Cliente</th><th>Produto</th><th>Data</th><th class="rt-num">Consumido</th><th class="rt-num">Bags na O.P</th></tr></thead>
              <tbody>
                <?php foreach ($ficha['consumo'] as $c): ?>
                  <tr>
                    <td><a class="rt-link" href="<?= rtE(rtLink('op', $c['op'], $busca)) ?>"><?= rtE($c['op']) ?></a></td>
                    <td><?= rtE(($c['pedido'] ?? '') ?: '—') ?></td>
                    <td><?= rtE(($c['cliente'] ?? '') ?: '—') ?></td>
                    <td><?= rtE($c['produto'] ?? '—') ?></td>
                    <td class="rt-nowrap"><?= rtFmtData($c['data'], false) ?></td>
                    <td class="rt-num"><?= rtQtd($c['qtd']) ?> <?= rtE($l['unidade'] ?? '') ?></td>
                    <td class="rt-num"><?= rtQtd($c['bags']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
            <div class="rt-aviso">O ERP registra o lote por O.P: nem todos os bags da O.P usaram necessariamente este lote.</div>
          <?php endif; ?>
        </div>

      <?php elseif ($resultados !== null):
          $total = count($resultados['nsu']) + count($resultados['op']) + count($resultados['lote']); ?>
        <?php if ($total === 0): ?>
          <div class="panel-table"><div class="rt-vazio">Nada encontrado em <?= rtE(RT_CAMPOS[$campo]) ?> para "<?= rtE($q) ?>". Confira o número ou escolha outro campo.</div></div>
        <?php endif; ?>

        <?php if ($resultados['op']): ?>
          <div class="panel-table rt-bloco">
            <div class="panel-header"><span class="panel-title">O.Ps e pedidos</span></div>
            <div class="table-wrap"><table>
              <thead><tr><th>O.P</th><th>Pedido</th><th>Cliente</th><th>Emissão</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($resultados['op'], 0, RT_LIMITE_BUSCA) as $r): ?>
                  <tr>
                    <td><a class="rt-link" href="<?= rtE(rtLink('op', $r['op'], $busca)) ?>"><?= rtE($r['op']) ?></a></td>
                    <td><?= rtE(($r['pedido'] ?? '') ?: '—') ?></td>
                    <td><?= rtE(($r['cliente'] ?? '') ?: '—') ?></td>
                    <td class="rt-nowrap"><?= rtFmtData($r['data'], false) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          </div>
        <?php endif; ?>

        <?php if ($resultados['nsu']): ?>
          <div class="panel-table rt-bloco">
            <div class="panel-header"><span class="panel-title">Bags</span></div>
            <div class="table-wrap"><table>
              <thead><tr><th>NSU</th><th>O.P</th><th>Produto</th><th>Última leitura</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($resultados['nsu'], 0, RT_LIMITE_BUSCA) as $r): ?>
                  <tr>
                    <td class="rt-nowrap"><a class="rt-link" href="<?= rtE(rtLink('nsu', $r['nsu'], $busca)) ?>"><?= rtE($r['nsu']) ?></a></td>
                    <td><?= rtE($r['op']) ?></td>
                    <td><?= rtE($r['produto'] ?? '—') ?></td>
                    <td class="rt-nowrap"><?= rtFmtData($r['data_hora']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php if (count($resultados['nsu']) > RT_LIMITE_BUSCA): ?>
              <div class="rt-aviso">Mostrando os <?= RT_LIMITE_BUSCA ?> mais recentes. Digite mais números do NSU para refinar.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($resultados['lote']): ?>
          <div class="panel-table rt-bloco">
            <div class="panel-header"><span class="panel-title">Lotes</span></div>
            <div class="table-wrap"><table>
              <thead><tr><th>Lote</th><th>Material</th><th class="rt-num">O.Ps que usaram</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($resultados['lote'], 0, RT_LIMITE_BUSCA) as $r): ?>
                  <tr>
                    <td class="rt-nowrap"><a class="rt-link" href="<?= rtE(rtLink('lote', $r['lt_codigo'], $busca)) ?>"><?= rtE($r['lote'] ?: $r['lt_codigo']) ?></a></td>
                    <td><?= rtE($r['material'] ?? '—') ?></td>
                    <td class="rt-num"><?= rtQtd($r['ops']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php if (count($resultados['lote']) > RT_LIMITE_BUSCA): ?>
              <div class="rt-aviso">Mostrando os <?= RT_LIMITE_BUSCA ?> mais recentes. Digite mais números do lote para refinar.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>
// Exemplo no campo de busca conforme o que será pesquisado
(function () {
  const exemplos = { nsu: 'Ex.: BBG000075313', op: 'Número da O.P', pedido: 'Número do pedido', lote: 'Número do lote' };
  const campo = document.getElementById('rtCampo');
  const termo = document.getElementById('rtTermo');
  const atualizar = () => { termo.placeholder = exemplos[campo.value]; };
  campo.addEventListener('change', () => { atualizar(); termo.focus(); });
  atualizar();
})();
</script>
</body>
</html>
