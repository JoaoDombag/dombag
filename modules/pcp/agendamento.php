<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/agendamento_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Agendamento de Produção
//  Calendário do mês com os pedidos da Reunião de Planejamento.
//  Relatório: produzido por dia, vindo do ERP. Operacional: arrastar
//  um pedido para um dia grava o previsto em PCP_AGENDA, e o card
//  recebe o realizado do dia. Regras e
//  consultas: agendamento_dados.php
// ══════════════════════════════════════════════════════════════

// Migrations normalmente rodam no login; garante a tabela para quem já estava
// logado quando esta página foi publicada.
if (empty($_SESSION['agenda_mig_v3_ok'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/migrations.php';
    ob_start();
    unset($_SESSION['mig_ok']);
    rodarMigrations();
    ob_end_clean();
    $_SESSION['agenda_mig_v3_ok'] = 1;
}

$acao = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $pdo = dbPDO();
    $pg  = dbPG();
    try {
        if ($acao !== 'dados') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('Requisição inválida.');
            }
            $agd = (int) ($_POST['agd'] ?? 0);
            $qtd = (float) ($_POST['qtd'] ?? 0);
            $dia = agData((string) ($_POST['dia'] ?? ''));
            if (!in_array($acao, ['remover', 'datas', 'realizado'], true) && $dia === null) {
                throw new RuntimeException('Data inválida.');
            }
            match ($acao) {
                'agendar' => agAgendar($pdo, (int) ($_POST['prg'] ?? 0), (string) ($_POST['tipo'] ?? ''), $dia, $qtd),
                'mover'   => agMover($pdo, $pg, agSlotAberto($pdo, $agd), $dia),
                'editar'  => agEditar($pdo, $pg, $agd, $qtd, $dia),
                'remover' => agRemover($pdo, $agd),
                'realizado' => agMarcarRealizado($pdo, $agd, (string) ($_POST['realizado'] ?? '')),
                'datas'   => agMarcarDatas($pdo, (int) ($_POST['prg'] ?? 0), (string) ($_POST['inicio'] ?? ''), (string) ($_POST['saida'] ?? ''), (string) ($_POST['entrega'] ?? '')),
                default   => throw new RuntimeException('Ação desconhecida.'),
            };
        }
        echo json_encode(agMontar($pdo, $pg, agMes((string) ($_REQUEST['mes'] ?? ''))), $flags);
    } catch (Throwable $e) {
        error_log('[AGENDAMENTO] ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()], $flags);
    }
    exit;
}

$mesInicial = agMes((string) ($_GET['mes'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="<?= htmlspecialchars(dombagTema()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agendamento de Produção | DOMBAG</title>
<link rel="stylesheet" href="/public/css/unified_admin.css">
<link rel="icon" href="/public/css/icone.ico" type="image/png">
<style>
  .tipo-BAG     { --tipo: 45,106,255;  --tipo-txt: #7db3ff; }
  .tipo-SACARIA { --tipo: 245,158,11;  --tipo-txt: var(--amber); }
  :root[data-theme="claro"] .tipo-BAG     { --tipo-txt: #1e4fc9; }
  :root[data-theme="claro"] .tipo-SACARIA { --tipo-txt: #a16207; }

  .content.ag-content { display: block; overflow: auto; padding: 18px 24px 28px; }

  /* ── Barra do mês ─────────────────────────────────────── */
  .ag-barra { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 18px; margin-bottom: 12px; }
  .ag-mes { display: flex; align-items: center; gap: 6px; }
  .ag-mes h2 { font-size: 19px; font-weight: 600; letter-spacing: -.3px; min-width: 176px; text-align: center; }
  .ag-mes button {
    height: 32px; min-width: 32px; padding: 0 10px; border-radius: 8px; cursor: pointer;
    border: 1px solid var(--border); background: var(--card-bg); color: var(--text-primary);
    font: 600 12.5px 'Segoe UI', sans-serif;
  }
  .ag-mes button:hover { background: var(--card-hover); }
  .ag-filtros { display: flex; align-items: center; gap: 12px; margin-left: auto; flex-wrap: wrap; }
  .ag-filtros input[type="search"] { height: 32px; width: 220px; padding: 0 10px; font-size: 12.5px; }
  .ag-check { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted); cursor: pointer; }

  /* ── Legenda: Big Bag e Sacaria também filtram ────────── */
  .ag-legenda { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-bottom: 12px; font-size: 12px; color: var(--text-muted); }
  .ag-tipo-btn.tipo-BAG     { --tipo: 34,197,94; }
  .ag-tipo-btn.tipo-SACARIA { --tipo: 168,85,247; }
  .ag-tipo-btn {
    display: inline-flex; align-items: center; gap: 7px; height: 30px; padding: 0 12px; border-radius: 8px; cursor: pointer;
    border: 1px solid rgba(var(--tipo), .45); background: rgba(var(--tipo), .16);
    color: var(--text-primary); font: 600 12.5px 'Segoe UI', sans-serif;
  }
  .ag-tipo-btn::before { content: ''; width: 11px; height: 11px; border-radius: 3px; background: rgb(var(--tipo)); }
  .ag-tipo-btn[aria-pressed="false"] { background: none; border-color: var(--border); color: var(--text-muted); text-decoration: line-through; }
  .ag-tipo-btn[aria-pressed="false"]::before { opacity: .3; }
  .ag-amostra { display: inline-flex; align-items: center; gap: 6px; }
  .ag-amostra i { width: 26px; height: 13px; border-radius: 3px; display: block; background: var(--card-bg); }
  .ag-amostra .s-agendado { border: 1px dashed var(--text-muted); }
  .ag-amostra .s-parcial  { border: 1px solid var(--text-muted); background: linear-gradient(90deg, var(--text-muted) 55%, transparent 55%); }
  .ag-amostra .s-produzido { background: var(--text-muted); }
  .ag-amostra .s-abaixo { border: 1px solid var(--red); }
  .ag-amostra .s-inicio { background: #a3e635; }
  .ag-amostra .s-saida  { background: #f472b6; }

  /* ── Totais do mês, um por tipo: as unidades não se somam ─ */
  .ag-totais { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
  .ag-total {
    display: grid; grid-template-columns: auto repeat(3, 1fr); align-items: center; gap: 4px 18px;
    padding: 10px 14px; border-radius: 10px; background: var(--card-bg); border: 1px solid var(--border);
    border-left: 3px solid rgb(var(--tipo));
  }
  .ag-total strong { font-size: 13px; font-weight: 600; }
  .ag-total dl { display: contents; }
  .ag-total dd { font-size: 17px; font-weight: 700; letter-spacing: -.4px; line-height: 1.15; }
  .ag-total dt { font-size: 11px; color: var(--text-muted); }

  /* ── Área principal ───────────────────────────────────── */
  .ag-area { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 14px; align-items: start; }
  .ag-fila {
    position: sticky; top: 0; max-height: calc(100vh - 110px); display: flex; flex-direction: column;
    background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius);
  }
  .ag-fila.solta { border-color: rgba(239,68,68,.6); }
  .ag-fila header { padding: 11px 13px 9px; border-bottom: 1px solid var(--border); }
  .ag-fila h3 { font-size: 13.5px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
  .ag-fila header p { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.4; }
  .ag-fila-lista { padding: 9px; overflow-y: auto; display: flex; flex-direction: column; gap: 7px; min-height: 90px; }
  .ag-fila-vazia { padding: 18px 10px; text-align: center; font-size: 12px; color: var(--text-muted); line-height: 1.5; }
  .ag-fila-vazia a { color: #7db3ff; }
  .ag-contador { font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 20px; background: var(--card-hover); color: var(--text-muted); }

  .ag-pend {
    padding: 8px 10px; border-radius: 8px; cursor: grab; text-align: left; font-size: 12px;
    background: rgba(var(--tipo), .08); border: 1px dashed rgba(var(--tipo), .55); border-left: 3px solid rgb(var(--tipo));
  }
  .ag-pend b { display: block; font-size: 14px; font-weight: 700; letter-spacing: -.2px; }
  .ag-pend span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ag-pend .ag-sub { color: var(--text-muted); font-size: 11px; margin-top: 2px; }
  .ag-pend .ag-alerta { color: var(--red); font-size: 11px; font-weight: 600; margin-top: 2px; }

  /* ── Calendário ───────────────────────────────────────── */
  .ag-cal { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--card-bg); }
  .ag-semana, .ag-grade { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) repeat(2, minmax(0, .72fr)); }
  .ag-semana div { padding: 7px 9px; font-size: 11.5px; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid var(--border); }
  .ag-dia {
    min-height: 118px; padding: 5px 5px 7px; display: flex; flex-direction: column; gap: 4px; min-width: 0;
    border-right: 1px solid var(--border); border-bottom: 1px solid var(--border);
  }
  .ag-dia:nth-child(7n) { border-right: 0; }
  .ag-dia.outro-mes { background: var(--card-hover); }
  .ag-dia.outro-mes > * { opacity: .55; }
  .ag-dia.fds { background: rgba(127,127,127,.05); }
  .ag-dia.hoje { box-shadow: inset 0 0 0 2px var(--blue-light); }
  .ag-dia.alvo { background: rgba(45,106,255,.16); }
  .ag-arrastando .ag-dia.passado { opacity: .35; }
  .ag-arrastando .ag-card { pointer-events: none; }
  .ag-dia-topo { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; padding: 0 3px; min-height: 18px; }
  .ag-num { font-size: 12.5px; font-weight: 600; color: var(--text-muted); }
  .ag-dia.hoje .ag-num { color: var(--text-primary); font-weight: 700; }
  .ag-dia-soma { display: flex; gap: 8px; font-size: 11px; font-weight: 700; white-space: nowrap; }
  .ag-dia-soma span { color: var(--tipo-txt); }
  .ag-rotulo-dia { display: none; }

  .ag-entrega {
    font-size: 10.5px; padding: 1px 5px; border-radius: 4px; color: var(--text-muted);
    background: var(--card-hover); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }

  .ag-marco {
    font-size: 10.5px; font-weight: 600; padding: 1px 5px; border-radius: 4px; color: var(--text-primary);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .ag-marco.inicio { background: rgba(163,230,53,.16); border-left: 3px solid #a3e635; }
  .ag-marco.saida  { background: rgba(244,114,182,.16); border-left: 3px solid #f472b6; }

  /* O card é um medidor: a cor enche conforme o produzido alcança o previsto */
  .ag-card {
    position: relative; overflow: hidden; display: block; width: 100%; padding: 4px 6px 4px 8px;
    border-radius: 6px; background: var(--card-bg); color: var(--text-primary); text-align: left;
    border: 1px solid rgba(var(--tipo), .6); cursor: pointer; font-family: 'Segoe UI', sans-serif;
  }
  .ag-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: rgb(var(--tipo)); }
  .ag-card .ag-enche { position: absolute; left: 0; top: 0; bottom: 0; background: rgba(var(--tipo), .3); }
  .ag-card > span:not(.ag-enche) { position: relative; display: block; }
  .ag-card.agendado { border-style: dashed; }
  .ag-card.pode-mover { cursor: grab; }
  .ag-card.abaixo { border-color: var(--red); }
  .ag-card.fora { opacity: .78; border-color: transparent; }
  .ag-card.arrastado, .ag-pend.arrastado { opacity: .35; }
  .ag-qtd { font-size: 13px; font-weight: 700; letter-spacing: -.2px; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ag-qtd small { font-size: 10.5px; font-weight: 500; color: var(--text-muted); letter-spacing: 0; }
  .ag-nome { font-size: 11px; color: var(--text-primary); line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ag-nome em { font-style: normal; font-weight: 700; }
  .ag-aviso { font-size: 10.5px; font-weight: 600; color: var(--red); line-height: 1.25; }
  .ag-aberto { font-size: 10.5px; color: var(--text-muted); line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  .ag-card:focus-visible, .ag-pend:focus-visible, .ag-tipo-btn:focus-visible, .ag-mes button:focus-visible {
    outline: 2px solid var(--blue-light); outline-offset: 1px;
  }

  /* ── Resumo do mês ────────────────────────────────────── */
  .ag-resumo { margin-top: 16px; background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .ag-resumo h3 { font-size: 13.5px; font-weight: 600; padding: 12px 14px; border-bottom: 1px solid var(--border); }
  .ag-resumo td.num, .ag-resumo th.num { text-align: right; white-space: nowrap; }
  .ag-resumo .tipo-txt { color: var(--tipo-txt); font-weight: 600; white-space: nowrap; }
  .ag-resumo small { display: block; color: var(--text-muted); font-size: 10.5px; }

  /* ── Modal ────────────────────────────────────────────── */
  .ag-det { margin: 12px 0 2px; display: grid; grid-template-columns: auto 1fr; gap: 5px 14px; font-size: 12.5px; }
  .ag-det dt { color: var(--text-muted); }
  .ag-form { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
  #agModal [hidden] { display: none; }
  .ag-form label { display: flex; flex-direction: column; gap: 5px; font-size: 12px; color: var(--text-muted); }
  .ag-form input { height: 36px; padding: 0 10px; font-size: 13px; }
  .ag-marcos-acao { grid-column: 1 / -1; justify-self: start; }
  .ag-nota { margin-top: 10px; }
  .ag-remover { margin-right: auto; color: var(--red) !important; }

  .ag-toast {
    position: fixed; left: 50%; bottom: 22px; transform: translate(-50%, 16px); z-index: 600; max-width: calc(100vw - 32px);
    padding: 9px 16px; border-radius: 9px; background: var(--modal-bg, #152845); border: 1px solid var(--border); box-shadow: var(--shadow);
    font-size: 12.5px; opacity: 0; pointer-events: none; transition: opacity .18s, transform .18s;
  }
  .ag-toast.visivel { opacity: 1; transform: translate(-50%, 0); }
  .ag-toast.erro { border-color: rgba(239,68,68,.6); color: var(--red); }

  .ag-so-impressao { display: none; }

  /* Tela cheia: a página inteira vai para tela cheia, para o modal e os avisos continuarem visíveis */
  .ag-btn-cheia {
    height: 32px; padding: 0 12px; border-radius: 8px; cursor: pointer; white-space: nowrap;
    border: 1px solid var(--border); background: var(--card-bg); color: var(--text-primary);
    font: 600 12.5px 'Segoe UI', sans-serif;
  }
  .ag-btn-cheia:hover { background: var(--card-hover); }
  .ag-btn-cheia:focus-visible { outline: 2px solid var(--blue-light); outline-offset: 1px; }
  body.ag-cheia .sidebar, body.ag-cheia .topbar, body.ag-cheia .mobile-topbar { display: none !important; }
  body.ag-cheia .main { padding-top: 0; }
  /* Na tela cheia o calendário é um quadro só com o que está agendado */
  body.ag-cheia .ag-fila, body.ag-cheia .ag-totais, body.ag-cheia .ag-resumo, body.ag-cheia .ag-check { display: none; }
  body.ag-cheia .ag-area { grid-template-columns: minmax(0, 1fr); }
  .ag-sem-agenda { margin-bottom: 12px; font-size: 13px; color: var(--text-muted); }

  @media (max-width: 1250px) {
    .ag-total { grid-template-columns: repeat(3, 1fr); }
    .ag-total strong { grid-column: 1 / -1; }
  }
  @media (max-width: 1050px) {
    .ag-area { grid-template-columns: minmax(0, 1fr); }
    .ag-fila { position: static; max-height: 300px; }
  }
  /* No celular o mês vira lista de dias; agendar é pelo toque, no formulário */
  @media (max-width: 760px) {
    .content.ag-content { padding: 12px 16px 24px; }
    .ag-totais { grid-template-columns: 1fr; }
    .ag-filtros { margin-left: 0; width: 100%; }
    .ag-filtros input[type="search"] { flex: 1; width: auto; }
    .ag-mes { width: 100%; justify-content: space-between; }
    .ag-mes h2 { min-width: 0; flex: 1; }
    .ag-semana { display: none; }
    .ag-grade { grid-template-columns: minmax(0, 1fr); }
    .ag-dia { min-height: 0; border-right: 0; padding: 8px; }
    .ag-dia.vazio, .ag-dia.outro-mes { display: none; }
    .ag-rotulo-dia { display: inline; }
    .ag-fila header p { display: none; }
    .topbar .page-title p, .topbar .btn-secondary { display: none; }
    .topbar .btn-refresh { white-space: nowrap; }
  }

  @media print {
    @page { size: A4 landscape; margin: 8mm; }
    :root, :root[data-theme="claro"] {
      --text-primary: #111; --text-muted: #555; --border: #bbb; --card-bg: #fff; --card-hover: #f1f1f1;
    }
    html, body { background: #fff; color: #111; overflow: visible; height: auto; }
    .tipo-BAG { --tipo-txt: #1e4fc9; } .tipo-SACARIA { --tipo-txt: #a16207; }
    .app-wrapper, .main { display: block; height: auto; overflow: visible; }
    .sidebar, .topbar, .ag-fila, .ag-filtros, .ag-mes button, .ag-toast, .alert-error, .mobile-topbar { display: none !important; }
    .content.ag-content { overflow: visible; padding: 0; }
    .ag-so-impressao { display: inline; font-size: 15px; font-weight: 600; margin-right: 8px; }
    .ag-area { display: block; }
    .ag-mes h2 { text-align: left; min-width: 0; font-size: 15px; }
    .ag-card, .ag-tipo-btn, .ag-total { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .ag-card, .ag-dia { break-inside: avoid; }
    .ag-dia { min-height: 70px; }
    .ag-dia.hoje { box-shadow: none; }
    .ag-card.fora { opacity: 1; border-color: #ccc; }
    .ag-resumo { break-before: page; }
    .ag-resumo table { display: table; }
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
          <h1>Agendamento de Produção</h1>
          <p>Arraste um pedido para o dia em que ele deve ser produzido</p>
        </div>
      </div>
      <div class="topbar-actions">
        <button type="button" class="btn-secondary" onclick="window.print()">Imprimir</button>
        <a href="/pcp/reuniao" class="btn-refresh">Reunião de Planejamento</a>
      </div>
    </header>

    <div class="content ag-content">
      <div class="alert-error" id="agErro" hidden></div>

      <div class="ag-barra">
        <div class="ag-mes">
          <span class="ag-so-impressao">Agendamento de Produção</span>
          <button type="button" id="agAnterior" aria-label="Mês anterior">‹</button>
          <h2 id="agMesTitulo">&nbsp;</h2>
          <button type="button" id="agProximo" aria-label="Próximo mês">›</button>
          <button type="button" id="agHoje">Hoje</button>
        </div>
        <div class="ag-filtros">
          <input type="search" id="agBusca" placeholder="Buscar pedido, OP ou cliente" aria-label="Buscar pedido, OP ou cliente">
          <label class="ag-check"><input type="checkbox" id="agSoProg"> Só pedidos da Reunião</label>
          <button type="button" class="ag-btn-cheia" id="agTelaCheia">Tela cheia</button>
        </div>
      </div>

      <div class="ag-legenda">
        <button type="button" class="ag-tipo-btn tipo-BAG" data-tipo="BAG" aria-pressed="true">Big Bag</button>
        <button type="button" class="ag-tipo-btn tipo-SACARIA" data-tipo="SACARIA" aria-pressed="true">Sacaria</button>
        <span class="ag-amostra"><i class="s-agendado"></i> Agendado</span>
        <span class="ag-amostra"><i class="s-parcial"></i> Em produção</span>
        <span class="ag-amostra"><i class="s-produzido"></i> Produzido</span>
        <span class="ag-amostra"><i class="s-abaixo"></i> Abaixo do previsto</span>
        <span class="ag-amostra"><i class="s-inicio"></i> Início da produção</span>
        <span class="ag-amostra"><i class="s-saida"></i> Saída</span>
      </div>

      <p class="ag-sem-agenda" id="agSemAgenda" hidden>Nenhum pedido agendado neste mês.</p>

      <div class="ag-totais" id="agTotais"></div>

      <div class="ag-area">
        <aside class="ag-fila" id="agFila" aria-label="Pedidos a agendar">
          <header>
            <h3>A agendar <span class="ag-contador" id="agFilaQtd">0</span></h3>
            <p>Solte aqui um card do calendário para desfazer o agendamento.</p>
          </header>
          <div class="ag-fila-lista" id="agFilaLista"></div>
        </aside>

        <div class="ag-cal">
          <div class="ag-semana">
            <div>Seg</div><div>Ter</div><div>Qua</div><div>Qui</div><div>Sex</div><div>Sáb</div><div>Dom</div>
          </div>
          <div class="ag-grade" id="agGrade"></div>
        </div>
      </div>

      <section class="ag-resumo">
        <h3>Pedidos do mês</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Pedido</th><th>OP</th><th>Cliente</th><th>Tipo</th><th>Entrega esperada</th>
                <th class="num">Agendado no mês</th><th class="num">Realizado marcado</th><th class="num">Produzido no ERP</th><th class="num">Quantidade da OP</th>
              </tr>
            </thead>
            <tbody id="agResumo"></tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</div>

<div class="modal-overlay" id="agModal">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="agModalTitulo">
    <h3 id="agModalTitulo"></h3>
    <dl class="ag-det" id="agModalDet"></dl>
    <form id="agModalForm">
      <div class="ag-form" id="agModalCampos">
        <label>Dia <input type="date" id="agCampoDia" required></label>
        <label>Previsto no dia <input type="number" id="agCampoQtd" min="1" step="1" required></label>
      </div>
      <div class="ag-form" id="agModalReal">
        <label>Realizado no dia <input type="number" id="agCampoReal" min="0" step="1" inputmode="numeric"></label>
        <button type="button" class="btn-secondary ag-marcos-acao" id="agBtnReal">Salvar realizado</button>
      </div>
      <div class="ag-form" id="agModalMarcos">
        <label>Início da produção <input type="date" id="agCampoInicio"></label>
        <label>Saída <input type="date" id="agCampoSaida"></label>
        <label>Entrega esperada <input type="date" id="agCampoEntrega"></label>
        <button type="button" class="btn-secondary ag-marcos-acao" id="agBtnDatas">Salvar datas</button>
      </div>
      <p class="ag-nota" id="agModalNota"></p>
      <div class="modal-actions">
        <button type="button" class="btn-secondary ag-remover" id="agBtnRemover">Remover</button>
        <button type="button" class="btn-secondary" id="agBtnFechar">Fechar</button>
        <button type="submit" class="btn-refresh" id="agBtnSalvar">Salvar</button>
      </div>
    </form>
  </div>
</div>

<div class="ag-toast" id="agToast" role="status"></div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const nf = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dataBr = d => d ? d.split('-').reverse().join('/') : '—';
  const tipoNome = { BAG: 'Big Bag', SACARIA: 'Sacaria' };
  const unidade = { BAG: 'bags', SACARIA: 'sac.' };

  let dados = null;
  let mes = <?= json_encode($mesInicial) ?>;
  const filtro = { BAG: true, SACARIA: true, busca: '', soProg: false };
  let arrastando = null;
  let toastTimer = 0;
  let selecionado = null;

  function toast(texto, erro) {
    const t = $('agToast');
    t.textContent = texto;
    t.classList.toggle('erro', !!erro);
    t.classList.add('visivel');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('visivel'), erro ? 6000 : 3500);
  }

  // ── Servidor: toda resposta traz a tela inteira, sempre conferida com o ERP ─
  async function carregar() {
    try {
      const r = await fetch('?action=dados&mes=' + encodeURIComponent(mes));
      aplicar(await r.json());
    } catch {
      mostrarErro('Não foi possível carregar o calendário. Verifique a conexão e recarregue a página.');
    }
  }

  async function enviar(acao, campos, sucesso) {
    try {
      const r = await fetch(location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ action: acao, mes, ...campos }).toString(),
      });
      const d = await r.json();
      if (d.error) {
        toast(d.error, true);
        await carregar();
        return false;
      }
      aplicar(d);
      toast(sucesso);
      return true;
    } catch {
      toast('A alteração não foi salva. Verifique a conexão e tente de novo.', true);
      await carregar();
      return false;
    }
  }

  function mostrarErro(texto) {
    $('agErro').textContent = texto;
    $('agErro').hidden = !texto;
  }

  function aplicar(d) {
    if (d.error) {
      mostrarErro(d.error);
      return;
    }
    dados = d;
    mostrarErro(d.erp ? '' : 'ERP indisponível: mostrando só o que foi agendado aqui, sem a produção real nem os pedidos a agendar.');
    render();
  }

  // ── Filtros ─────────────────────────────────────────────────────────────
  function passa(item) {
    if (!filtro[item.tipo]) return false;
    if (filtro.soProg && item.programado === false) return false;
    if (!filtro.busca) return true;
    return `${item.pedido} ${item.op} ${item.cliente}`.toLowerCase().includes(filtro.busca);
  }

  function render() {
    const [a, m] = dados.mes.split('-').map(Number);
    const titulo = new Date(a, m - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    $('agMesTitulo').textContent = titulo.charAt(0).toUpperCase() + titulo.slice(1);
    renderTotais();
    renderFila();
    renderGrade();
    renderResumo();
  }

  function renderTotais() {
    $('agTotais').innerHTML = ['BAG', 'SACARIA'].map(t => {
      const v = dados.totais[t];
      return `<div class="ag-total tipo-${t}">
        <strong>${tipoNome[t]}</strong>
        <dl>
          <div><dd>${nf.format(v.produzido)}</dd><dt>Produzido no mês</dt></div>
          <div><dd>${nf.format(v.agendado)}</dd><dt>Agendado no mês</dt></div>
          <div><dd>${nf.format(v.a_agendar)}</dd><dt>A agendar</dt></div>
        </dl>
      </div>`;
    }).join('');
  }

  function renderFila() {
    const lista = dados.pendentes.filter(passa);
    $('agFilaQtd').textContent = lista.length;
    $('agFilaLista').innerHTML = lista.map(p => {
      const i = dados.pendentes.indexOf(p);
      const alerta = p.atrasado ? 'Saldo de dia não cumprido'
                   : p.erp_finalizada ? 'OP finalizada no ERP' : '';
      return `<div class="ag-pend tipo-${p.tipo}" draggable="true" tabindex="0" role="button" data-pend="${i}">
        <b>${nf.format(p.saldo)} ${unidade[p.tipo]}</b>
        <span>${esc(p.pedido || 'OP ' + p.op)} ${esc(p.cliente)}</span>
        <span class="ag-sub">Entrega ${dataBr(p.entrega)}</span>
        ${alerta ? `<span class="ag-alerta">${alerta}</span>` : ''}
      </div>`;
    }).join('') || `<p class="ag-fila-vazia">Nenhum pedido aguardando dia.<br>Adicione pedidos na <a href="/pcp/reuniao">Reunião de Planejamento</a>.</p>`;
  }

  function htmlCard(c, i) {
    const passado = c.dia < dados.hoje;
    const pct = c.plan > 0 ? Math.min(100, c.real / c.plan * 100) : (c.real > 0 ? 100 : 0);
    const classes = ['ag-card', 'tipo-' + c.tipo, c.status];
    if (c.editavel) classes.push('pode-mover');
    if (!c.programado) classes.push('fora');

    // A quantidade produzida vem primeiro; o previsto aparece ao lado quando existe
    let qtd;
    if (c.real > 0 || (passado && c.plan > 0)) {
      qtd = `${nf.format(c.real)} ${unidade[c.tipo]}` + (c.plan > 0 ? ` <small>de ${nf.format(c.plan)}</small>` : '');
    } else if (c.plan > 0) {
      qtd = `${nf.format(c.plan)} ${unidade[c.tipo]} <small>prev.</small>`;
    } else {
      qtd = `${nf.format(c.aberto)} ${unidade[c.tipo]} <small>em aberto</small>`;
    }
    return `<div class="${classes.join(' ')}" ${c.editavel ? 'draggable="true"' : ''} tabindex="0" role="button" data-card="${i}"
        title="${esc((c.pedido ? 'Pedido ' + c.pedido + ', ' : '') + 'OP ' + c.op + ': ' + c.cliente)}">
      <span class="ag-enche" style="width:${pct}%"></span>
      <span class="ag-qtd">${qtd}</span>
      <span class="ag-nome"><em>${esc(c.pedido || 'OP ' + c.op)}</em> ${esc(c.cliente)}</span>
      ${c.aberto > 0 && (c.real > 0 || c.plan > 0) ? `<span class="ag-aberto">${nf.format(c.aberto)} em aberto no ERP</span>` : ''}
      ${c.feito !== null && c.erp > 0 && c.erp !== c.feito ? `<span class="ag-aberto">${nf.format(c.erp)} no ERP</span>` : ''}
      ${c.tarde ? '<span class="ag-aviso">Depois da entrega</span>' : ''}
    </div>`;
  }

  const marcoNome = { inicio: 'Início', saida: 'Saída' };

  function renderGrade() {
    const porDia = {};
    const soAgendado = document.body.classList.contains('ag-cheia');
    dados.cards.forEach((c, i) => {
      if (passa(c) && (!soAgendado || c.plan > 0)) (porDia[c.dia] ??= []).push([c, i]);
    });
    $('agSemAgenda').hidden = !soAgendado || Object.keys(porDia).length > 0;

    const dias = [];
    const fim = new Date(dados.fim + 'T12:00:00');
    for (let d = new Date(dados.ini + 'T12:00:00'); d <= fim; d.setDate(d.getDate() + 1)) {
      const p = n => String(n).padStart(2, '0');
      dias.push(`${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`);
    }

    $('agGrade').innerHTML = dias.map(dia => {
      const dt = new Date(dia + 'T12:00:00');
      const itens = (porDia[dia] || []).sort(([a], [b]) => a.tipo.localeCompare(b.tipo) || (b.real + b.plan) - (a.real + a.plan));
      const entregas = (soAgendado ? [] : dados.entregas[dia] || []).filter(e => filtro.busca === '' || `${e.pedido} ${e.op} ${e.cliente}`.toLowerCase().includes(filtro.busca));
      const marcos = (dados.marcos[dia] || []).filter(m => filtro.busca === '' || `${m.pedido} ${m.op} ${m.cliente}`.toLowerCase().includes(filtro.busca));
      const classes = ['ag-dia'];
      if (!dia.startsWith(dados.mes)) classes.push('outro-mes');
      if (dia < dados.hoje) classes.push('passado');
      if (dia === dados.hoje) classes.push('hoje');
      if (dt.getDay() === 0 || dt.getDay() === 6) classes.push('fds');
      if (!itens.length && !entregas.length && !marcos.length) classes.push('vazio');

      const soma = { BAG: 0, SACARIA: 0 };
      const somaAgendada = soAgendado || dia > dados.hoje;
      itens.forEach(([c]) => { soma[c.tipo] += somaAgendada ? c.plan : c.real; });
      const somaHtml = Object.keys(soma).filter(t => soma[t] > 0)
        .map(t => `<span class="tipo-${t}" title="${tipoNome[t]}: ${somaAgendada ? 'agendado' : 'produzido'} no dia">${nf.format(soma[t])}</span>`).join('');

      const rotulo = dt.toLocaleDateString('pt-BR', { weekday: 'long' });
      return `<div class="${classes.join(' ')}" data-dia="${dia}">
        <div class="ag-dia-topo">
          <span class="ag-num">${dt.getDate()}<span class="ag-rotulo-dia"> ${esc(rotulo)}</span></span>
          <span class="ag-dia-soma">${somaHtml}</span>
        </div>
        ${marcos.map(m => `<div class="ag-marco ${m.marco}" title="${marcoNome[m.marco]}: ${esc(m.cliente)}">${marcoNome[m.marco]} ${esc(m.pedido || 'OP ' + m.op)} ${esc(m.cliente)}</div>`).join('')}
        ${entregas.map(e => `<div class="ag-entrega" title="Entrega esperada: ${esc(e.cliente)}">Entrega ${esc(e.pedido || 'OP ' + e.op)} ${esc(e.cliente)}</div>`).join('')}
        ${itens.map(([c, i]) => htmlCard(c, i)).join('')}
      </div>`;
    }).join('');
  }

  function renderResumo() {
    const linhas = dados.resumo.filter(passa);
    $('agResumo').innerHTML = linhas.map(r => `<tr class="tipo-${r.tipo}">
      <td>${esc(r.pedido || '—')}</td>
      <td class="td-muted">${r.op}</td>
      <td>${esc(r.cliente)}${r.programado ? '' : '<small>Fora da Reunião de Planejamento</small>'}</td>
      <td><span class="tipo-txt">${tipoNome[r.tipo]}</span></td>
      <td class="td-nowrap">${dataBr(r.entrega)}</td>
      <td class="num">${r.agendado > 0 ? nf.format(r.agendado) : '—'}</td>
      <td class="num">${r.marcado > 0 ? nf.format(r.marcado) : '—'}</td>
      <td class="num">${nf.format(r.produzido)}${r.aberto > 0 ? `<small>${nf.format(r.aberto)} em aberto</small>` : ''}</td>
      <td class="num">${r.total > 0 ? nf.format(r.total) : '—'}</td>
    </tr>`).join('') || '<tr><td colspan="9" class="td-muted" style="text-align:center;padding:24px;">Nenhum pedido neste mês com os filtros atuais.</td></tr>';
  }

  // ── Arrastar e soltar ───────────────────────────────────────────────────
  const area = document.querySelector('.ag-area');

  area.addEventListener('dragstart', e => {
    const el = e.target.closest('[data-card],[data-pend]');
    if (!el) return;
    arrastando = el.dataset.card !== undefined
      ? { tipo: 'card', item: dados.cards[el.dataset.card], el }
      : { tipo: 'pend', item: dados.pendentes[el.dataset.pend], el };
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', 'agendamento');
    // Espera o navegador capturar a imagem do arraste antes de esmaecer
    requestAnimationFrame(() => {
      el.classList.add('arrastado');
      document.body.classList.add('ag-arrastando');
    });
  });

  function limparArraste() {
    if (arrastando) arrastando.el.classList.remove('arrastado');
    arrastando = null;
    document.body.classList.remove('ag-arrastando');
    document.querySelectorAll('.alvo').forEach(c => c.classList.remove('alvo'));
    $('agFila').classList.remove('solta');
  }
  area.addEventListener('dragend', limparArraste);

  const diaAberto = dia => dia >= dados.hoje;

  area.addEventListener('dragover', e => {
    if (!arrastando) return;
    if (e.target.closest('#agFila')) {
      if (arrastando.tipo === 'card') {
        e.preventDefault();
        $('agFila').classList.add('solta');
      }
      return;
    }
    $('agFila').classList.remove('solta');
    const cel = e.target.closest('.ag-dia');
    document.querySelectorAll('.alvo').forEach(c => { if (c !== cel) c.classList.remove('alvo'); });
    if (cel && diaAberto(cel.dataset.dia)) {
      e.preventDefault();
      cel.classList.add('alvo');
    }
  });

  area.addEventListener('drop', e => {
    e.preventDefault();
    if (!arrastando) return;
    const { tipo, item, el } = arrastando;
    const cel = e.target.closest('.ag-dia');
    const naFila = e.target.closest('#agFila');
    limparArraste();

    if (naFila && tipo === 'card') {
      enviar('remover', { agd: item.agd }, 'Agendamento removido. O saldo voltou para A agendar.');
    } else if (cel && diaAberto(cel.dataset.dia)) {
      const dia = cel.dataset.dia;
      if (tipo === 'card') {
        if (dia === item.dia) return;
        cel.appendChild(el);
        enviar('mover', { agd: item.agd, dia }, `Pedido movido para ${dataBr(dia)}.`);
      } else {
        enviar('agendar', { prg: item.prg, tipo: item.tipo, dia, qtd: item.saldo },
          `${nf.format(item.saldo)} ${unidade[item.tipo]} agendados em ${dataBr(dia)}. Clique no card para dividir em mais dias.`);
      }
    }
  });

  // ── Modal: detalhes, e agendar sem arrastar (celular e teclado) ─────────
  function abrirModal(sel) {
    selecionado = sel;
    const { item, tipo } = sel;
    const novo = tipo === 'pend';
    const editavel = novo || item.editavel;

    $('agModalTitulo').textContent = (item.pedido ? 'Pedido ' + item.pedido : 'OP ' + item.op) + ' — ' + tipoNome[item.tipo];
    const linhas = [
      ['Cliente', item.cliente], ['OP', item.op], ['Produto', item.produto || '—'],
      ['Quantidade da OP', item.total > 0 ? `${nf.format(item.total)} ${unidade[item.tipo]}` : '—'],
    ];
    if (novo) {
      linhas.push(['Já produzido', nf.format(item.produzido)], ['A agendar', nf.format(item.saldo)]);
    } else {
      linhas.push(['Dia', dataBr(item.dia)]);
      if (item.plan > 0) linhas.push(['Agendado', nf.format(item.plan)]);
      if (item.dia <= dados.hoje) linhas.push(['Produzido no ERP', nf.format(item.erp)]);
      if (item.feito_por) linhas.push(['Realizado marcado por', item.feito_por]);
      if (item.aberto > 0) linhas.push(['Em aberto no ERP', nf.format(item.aberto)]);
      if (item.usuario) linhas.push(['Agendado por', item.usuario]);
    }
    $('agModalDet').innerHTML = linhas.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('');

    let nota = '';
    if (!novo && !item.programado) nota = 'Este pedido não está na Reunião de Planejamento. Para agendá-lo, adicione-o lá primeiro.';
    else if (!novo && item.status === 'abaixo') nota = 'Dia encerrado. O que faltou voltou para A agendar.';
    else if (!novo && !editavel && item.plan > 0) nota = 'Dia encerrado: dia e previsto não mudam mais.';
    else if (!novo && editavel) nota = 'Para dividir em vários dias, reduza o previsto aqui: o restante volta para A agendar.';
    else if (novo) nota = 'Agende uma parte e o restante continua em A agendar.';
    $('agModalNota').textContent = nota;

    $('agModalCampos').hidden = !editavel;
    $('agBtnSalvar').hidden = !editavel;
    $('agBtnSalvar').textContent = novo ? 'Agendar' : 'Salvar';
    $('agBtnRemover').hidden = novo || !item.editavel;
    $('agCampoDia').min = dados.hoje;
    $('agCampoDia').value = novo ? '' : item.dia;
    $('agCampoQtd').value = Math.round(novo ? item.saldo : item.plan);
    // Só o dia agendado que já chegou recebe realizado
    const marcaReal = !novo && item.agd > 0 && item.dia <= dados.hoje;
    $('agModalReal').hidden = !marcaReal;
    $('agCampoReal').value = marcaReal && item.feito !== null ? Math.round(item.feito) : '';
    $('agModalMarcos').hidden = !item.prg;
    $('agCampoInicio').value = item.inicio || '';
    $('agCampoSaida').value = item.saida || '';
    $('agCampoEntrega').value = item.entrega || '';
    $('agCampoDia').required = editavel;
    $('agCampoQtd').required = editavel;

    $('agModal').classList.add('open');
    (marcaReal ? $('agCampoReal') : editavel ? $('agCampoDia') : $('agBtnFechar')).focus();
  }
  function fecharModal() {
    $('agModal').classList.remove('open');
    selecionado = null;
  }

  function abrirDe(el) {
    if (el.dataset.card !== undefined) abrirModal({ tipo: 'card', item: dados.cards[el.dataset.card] });
    else abrirModal({ tipo: 'pend', item: dados.pendentes[el.dataset.pend] });
  }
  area.addEventListener('click', e => {
    const el = e.target.closest('[data-card],[data-pend]');
    if (el) abrirDe(el);
  });
  area.addEventListener('keydown', e => {
    const el = e.target.closest('[data-card],[data-pend]');
    if (el && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      abrirDe(el);
    }
  });

  $('agModalForm').addEventListener('submit', async e => {
    e.preventDefault();
    const { tipo, item } = selecionado;
    const dia = $('agCampoDia').value;
    const qtd = $('agCampoQtd').value;
    const ok = tipo === 'pend'
      ? await enviar('agendar', { prg: item.prg, tipo: item.tipo, dia, qtd }, `${nf.format(qtd)} ${unidade[item.tipo]} agendados em ${dataBr(dia)}.`)
      : await enviar('editar', { agd: item.agd, dia, qtd }, 'Agendamento salvo.');
    if (ok) fecharModal();
  });
  async function salvarRealizado() {
    const realizado = $('agCampoReal').value;
    const ok = await enviar('realizado', { agd: selecionado.item.agd, realizado },
      realizado === '' ? 'Realizado apagado. O dia voltou a mostrar o ERP.' : 'Realizado salvo.');
    if (ok) fecharModal();
  }
  $('agBtnReal').addEventListener('click', salvarRealizado);
  $('agCampoReal').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      salvarRealizado();
    }
  });
  $('agBtnDatas').addEventListener('click', async () => {
    const ok = await enviar('datas', {
      prg: selecionado.item.prg, inicio: $('agCampoInicio').value, saida: $('agCampoSaida').value, entrega: $('agCampoEntrega').value,
    }, 'Datas do pedido salvas.');
    if (ok) fecharModal();
  });
  $('agBtnRemover').addEventListener('click', async () => {
    const ok = await enviar('remover', { agd: selecionado.item.agd }, 'Agendamento removido. O saldo voltou para A agendar.');
    if (ok) fecharModal();
  });
  $('agBtnFechar').addEventListener('click', fecharModal);
  $('agModal').addEventListener('click', e => { if (e.target.id === 'agModal') fecharModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharModal(); });

  // ── Navegação e filtros ─────────────────────────────────────────────────
  function irPara(novoMes) {
    mes = novoMes;
    history.replaceState(null, '', '?mes=' + mes);
    carregar();
  }
  function somarMes(delta) {
    const [a, m] = mes.split('-').map(Number);
    const d = new Date(a, m - 1 + delta, 1);
    irPara(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  }
  $('agAnterior').addEventListener('click', () => somarMes(-1));
  $('agProximo').addEventListener('click', () => somarMes(1));
  $('agHoje').addEventListener('click', () => irPara(dados.hoje.slice(0, 7)));

  document.querySelectorAll('.ag-tipo-btn').forEach(b => b.addEventListener('click', () => {
    filtro[b.dataset.tipo] = !filtro[b.dataset.tipo];
    b.setAttribute('aria-pressed', filtro[b.dataset.tipo]);
    render();
  }));
  $('agBusca').addEventListener('input', e => { filtro.busca = e.target.value.trim().toLowerCase(); render(); });
  // Link vindo da Reunião, do Kanban ou do Status das OPs abre já filtrado no pedido
  const buscaLink = new URLSearchParams(location.search).get('busca');
  if (buscaLink) {
    $('agBusca').value = buscaLink;
    filtro.busca = buscaLink.toLowerCase();
  }
  $('agSoProg').addEventListener('change', e => { filtro.soProg = e.target.checked; render(); });

  // ── Tela cheia ──────────────────────────────────────────────────────────
  // Sem suporte do navegador (iPhone), ainda esconde os menus para dar mais espaço
  function marcarTelaCheia(ativa) {
    document.body.classList.toggle('ag-cheia', ativa);
    $('agTelaCheia').textContent = ativa ? 'Sair da tela cheia' : 'Tela cheia';
    if (dados) renderGrade();
  }
  $('agTelaCheia').addEventListener('click', () => {
    const ativar = !document.body.classList.contains('ag-cheia');
    marcarTelaCheia(ativar);
    if (ativar) document.documentElement.requestFullscreen?.().catch(() => {});
    else if (document.fullscreenElement) document.exitFullscreen();
  });
  // Esc sai da tela cheia pelo navegador, sem passar pelo botão
  document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement) marcarTelaCheia(false);
  });

  carregar();
})();
</script>
</body>
</html>
