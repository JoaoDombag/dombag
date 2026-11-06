<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/login/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/pcp/agendamento_tv_dados.php';

// ══════════════════════════════════════════════════════════════
//  DOMBAG — Agendamento na TV (clone somente leitura de /pcp/agendamento)
//  Alvo: LG UJ6565 / webOS 3.5 (Chromium 38). Por isso esta página é
//  autossuficiente e usa só ES5 e CSS sem variáveis, grid, clamp ou gap.
//  Não reaproveita sidebar nem unified_admin.css, que dependem de recursos
//  modernos. Mantenha essas restrições ao editar.
//  Parâmetros: ?mes=YYYY-MM fixa o mês; ?diag=1 abre o diagnóstico;
//  ?demo=1 mostra dados fictícios, com aviso na tela, sem consultar nem gravar.
// ══════════════════════════════════════════════════════════════

header('Cache-Control: no-store');

// Mesma garantia da página de Agendamento: a sessão da TV pode ser anterior à
// publicação da tabela PCP_AGENDA.
if (empty($_SESSION['agenda_mig_v2_ok'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/migrations.php';
    ob_start();
    unset($_SESSION['mig_ok']);
    rodarMigrations();
    ob_end_clean();
    $_SESSION['agenda_mig_v2_ok'] = 1;
}

$mesFixo = agTvMesFixo($_GET['mes'] ?? null);

// ── AJAX: dados do painel ─────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'refresh') {
    // O cookie de sessão não é reenviado sozinho e venceria 30 dias após o
    // login; quem está no painel renova uma vez por dia.
    if (time() - (int) ($_SESSION['tv_cookie_em'] ?? 0) > 86400) {
        $c = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $c['lifetime'],
            'path'     => $c['path'],
            'domain'   => $c['domain'],
            'secure'   => $c['secure'],
            'httponly' => $c['httponly'],
            'samesite' => $c['samesite'],
        ]);
        $_SESSION['tv_cookie_em'] = time();
    }
    session_write_close();

    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    try {
        $mes    = $mesFixo ?? date('Y-m');
        $painel = !empty($_GET['demo']) ? agTvDemo($mes) : agTvBuscar(dbPDO(), dbPG(), $mes);
        echo json_encode(['ok' => true, 'atualizado_em' => date('H:i:s')] + $painel, $flags);
    } catch (AgTvErro $e) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'codigo' => $e->codigo, 'erro' => $e->getMessage()], $flags);
    } catch (Throwable $e) {
        error_log('[AGENDAMENTO TV] ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['ok' => false, 'codigo' => 'consulta', 'erro' => 'Não foi possível consultar o agendamento.'], $flags);
    }
    exit;
}

session_write_close();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Agendamento na TV | DOMBAG</title>
<style>
  /* 1rem = 20px em 1920×1080 e proporcional abaixo; o JS refina em telas que
     não são 16:9. */
  html { font-size: 1.852vh; }
  html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: #05070a; }
  body { color: #eef2f7; font-family: "LG Smart UI", "Segoe UI", Roboto, Arial, sans-serif; }
  * { box-sizing: border-box; }

  #tela { display: -webkit-flex; display: flex; -webkit-flex-direction: column; flex-direction: column; height: 100%; padding: .7rem 1rem; }

  /* ── Topo ── */
  #topo { display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; -webkit-flex: 0 0 auto; flex: 0 0 auto; margin-bottom: .4rem; }
  #titulo { -webkit-flex: 1 1 auto; flex: 1 1 auto; min-width: 0; font-size: 1.5rem; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  #hora { font-size: 1.05rem; font-weight: 700; color: #8fa0b3; margin-left: 1.2rem; white-space: nowrap; }
  #selo { font-size: 1rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; padding: .25rem .9rem; border-radius: 2rem; margin-left: 1.2rem; white-space: nowrap; background: #1a2430; color: #8fa0b3; }
  .ok #selo { background: #123a36; color: #58d6c9; }
  .desatualizado #selo { background: #4a3208; color: #ffc766; }
  .erro #selo { background: #4d1a1a; color: #ff9a9a; }
  .demo #selo { background: #ffc766; color: #05070a; }
  #btnTela { display: none; margin-left: 1.2rem; font: inherit; font-size: 1rem; font-weight: 700; color: #eef2f7; background: #10161d; border: 2px solid #2b3540; border-radius: .5rem; padding: .25rem .8rem; }
  #btnTela:focus { outline: none; border-color: #58d6c9; }

  /* ── Legenda: discreta, no canto inferior esquerdo ── */
  #legenda { display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; -webkit-flex: 0 0 auto; flex: 0 0 auto; margin-top: .25rem; font-size: .65rem; font-weight: 600; color: #66727f; }
  .leg { display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; margin-right: .9rem; white-space: nowrap; }
  .leg i { display: block; width: 1rem; height: .5rem; margin-right: .3rem; border-radius: .12rem; border: 1px solid #66727f; opacity: .8; }
  .leg .bag { background: #22c55e; border-color: #22c55e; }
  .leg .sac { background: #a855f7; border-color: #a855f7; }
  .leg .agendado { border-style: dashed; }
  .leg .parcial { background: #66727f; background: linear-gradient(90deg, #66727f 55%, transparent 55%); }
  .leg .cumprido { background: #66727f; }
  .leg .abaixo { border-color: #ef4444; }
  .leg .agora { border-color: #58d6c9; }
  .leg .inicio { background: #a3e635; border-color: #a3e635; }
  #vazio { display: none; margin-left: auto; font-size: .8rem; color: #8fa0b3; }

  /* ── Aviso de dados desatualizados / erro ── */
  #aviso { display: none; -webkit-flex: 0 0 auto; flex: 0 0 auto; margin-bottom: .4rem; padding: .45rem 1rem; border-radius: .6rem; font-size: 1.1rem; font-weight: 700; }
  .desatualizado #aviso { display: block; background: #4a3208; color: #ffd98f; }
  .erro #aviso { display: block; background: #4d1a1a; color: #ffc4c4; }
  #aviso a { color: #fff; }

  /* ── Calendário: fim de semana mais estreito, como na página original ── */
  #calendario { display: -webkit-flex; display: flex; -webkit-flex-direction: column; flex-direction: column; -webkit-flex: 1 1 auto; flex: 1 1 auto; min-height: 0; background: #0d1117; border: 1px solid #222a33; border-radius: .6rem; overflow: hidden; }
  .desatualizado #grade { opacity: .6; }
  .semana { display: -webkit-flex; display: flex; }
  #cabecalho { -webkit-flex: 0 0 auto; flex: 0 0 auto; border-bottom: 1px solid #222a33; font-size: .95rem; font-weight: 700; color: #8fa0b3; }
  #cabecalho div { padding: .2rem .5rem; }
  .col { -webkit-flex: 1 1 0; flex: 1 1 0; min-width: 0; }
  .col.fds { -webkit-flex: .72 1 0; flex: .72 1 0; }
  #grade { -webkit-flex: 1 1 auto; flex: 1 1 auto; min-height: 0; overflow: hidden; }

  .dia { position: relative; padding: .15em .25em; border-right: 1px solid #222a33; border-bottom: 1px solid #222a33; overflow: hidden; }
  .dia:last-child { border-right: 0; }
  .dia.fds { background: #10151c; }
  .dia.outro { background: #141a22; }
  .dia.outro .num, .dia.outro .cartao { opacity: .55; }
  .dia.hoje { border: 3px solid #2d6aff; }
  .topoDia { display: -webkit-flex; display: flex; -webkit-justify-content: space-between; justify-content: space-between; line-height: 1.3; height: 1.3em; white-space: nowrap; overflow: hidden; }
  .num { font-weight: 700; color: #8fa0b3; }
  .hoje .num { color: #fff; font-weight: 900; }
  .somaBag { color: #4ade80; font-weight: 800; margin-left: .5em; }
  .somaSac { color: #c084fc; font-weight: 800; margin-left: .5em; }

  .marco { margin-top: .15em; padding: 0 .3em; border-radius: .2em; font-size: .8em; font-weight: 700; line-height: 1.25; color: #eef2f7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .marco.inicio { background: rgba(163,230,53,.18); border-left: .25em solid #a3e635; }
  .dia.outro .marco { opacity: .55; }

  .cartao { position: relative; overflow: hidden; margin-top: .2em; padding: .1em .35em .1em .5em; border-radius: .25em; border: 1px solid #36404c; background: #161d26; line-height: 1.2; }
  .cartao.agendado { border-style: dashed; }
  .cartao.abaixo { border-color: #ef4444; }
  .cartao.agora { border: 1px solid #58d6c9; }
  .agora .cliCard:before { content: '\25CF\00A0'; color: #58d6c9; }
  .cartao .faixa { position: absolute; left: 0; top: 0; bottom: 0; width: .2em; }
  .cartao.BAG .faixa { background: #22c55e; }
  .cartao.SACARIA .faixa { background: #a855f7; }
  .cartao .enche { position: absolute; left: 0; top: 0; bottom: 0; }
  .cartao.BAG .enche { background: rgba(34,197,94,.3); }
  .cartao.SACARIA .enche { background: rgba(168,85,247,.3); }
  .cartao div { position: relative; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cartao .faixa, .cartao .enche { position: absolute; }
  .cliCard { font-weight: 800; color: #fff; }
  .detCard { font-size: .8em; font-weight: 600; color: #b4c0cd; }
  .avisoCard { font-size: .8em; font-weight: 700; color: #ffb4b4; }
  .cartao.compacto { display: -webkit-flex; display: flex; -webkit-flex-wrap: wrap; flex-wrap: wrap; -webkit-align-items: center; align-items: center; margin-top: .12em; padding-top: 0; padding-bottom: 0; }
  .compacto .cliCard { -webkit-flex: 1 1 0; flex: 1 1 0; min-width: 0; }
  .compacto .detCard { -webkit-flex: 0 0 auto; flex: 0 0 auto; margin-left: .5em; }
  .compacto .avisoCard { -webkit-flex: 0 0 100%; flex: 0 0 100%; }

  #estado { margin: auto; padding: 1rem; text-align: center; font-size: 1.6rem; font-weight: 700; color: #8fa0b3; }

  /* ── Diagnóstico (?diag=1) ── */
  #diag { display: none; position: fixed; left: 0; right: 0; bottom: 0; max-height: 45%; overflow: hidden; margin: 0; padding: .5rem .8rem; background: #000; border-top: 2px solid #ffc766; color: #d6e0ea; font: .8rem/1.3 monospace; white-space: pre-wrap; word-wrap: break-word; }
</style>
</head>
<body class="carregando">
<script>
// Separado do script principal de propósito: se aquele não compilar neste
// navegador, este ainda roda e mostra o erro em vez de "Carregando" eterno.
window.tvDiag = [];
window.tvIniciou = false;
function tvRegistra(texto) {
  var d = new Date();
  window.tvDiag.push(d.toTimeString().substr(0, 8) + ' ' + texto);
  if (window.tvDiag.length > 40) { window.tvDiag.shift(); }
  if (window.tvMostraDiag) { window.tvMostraDiag(); }
}
function tvFalhaDeScript(texto) {
  var aviso = document.getElementById('aviso');
  document.body.className = 'erro';
  if (aviso) { aviso.textContent = texto; }
  var selo = document.getElementById('selo');
  if (selo) { selo.textContent = 'Erro'; }
}
window.onerror = function (msg, arquivo, linha) {
  tvRegistra('ERRO JS: ' + msg + ' (linha ' + linha + ')');
  if (!window.tvIniciou) { tvFalhaDeScript('Este navegador não conseguiu executar o painel: ' + msg); }
};
setTimeout(function () {
  if (!window.tvIniciou) { tvFalhaDeScript('O script do painel não iniciou neste navegador.'); }
}, 8000);
</script>

<div id="tela">
  <div id="topo">
    <div id="titulo">Agendamento de Produção</div>
    <div id="hora"></div>
    <div id="selo">Carregando</div>
    <button type="button" id="btnTela">Tela cheia</button>
  </div>
  <div id="aviso" role="status"></div>
  <noscript><div style="font-size:1.5rem;color:#ffc4c4;padding:1rem">Este painel precisa de JavaScript habilitado.</div></noscript>

  <div id="calendario">
    <div class="semana" id="cabecalho">
      <div class="col">Segunda</div><div class="col">Terça</div><div class="col">Quarta</div><div class="col">Quinta</div><div class="col">Sexta</div><div class="col fds">Sábado</div><div class="col fds">Domingo</div>
    </div>
    <div id="grade"><div id="estado">Carregando dados…</div></div>
  </div>

  <div id="legenda">
    <span class="leg"><i class="bag"></i>Big Bag</span>
    <span class="leg"><i class="sac"></i>Sacaria</span>
    <span class="leg"><i class="agendado"></i>Agendado</span>
    <span class="leg"><i class="parcial"></i>Em andamento</span>
    <span class="leg"><i class="cumprido"></i>Produzido</span>
    <span class="leg"><i class="abaixo"></i>Abaixo do previsto</span>
    <span class="leg"><i class="agora"></i>Produzindo agora</span>
    <span class="leg"><i class="inicio"></i>Início da produção</span>
    <span id="vazio">Nenhum pedido agendado neste mês.</span>
  </div>
</div>
<pre id="diag"></pre>

<script>
(function () {
  'use strict';
  window.tvIniciou = true;

  var INTERVALO = 30000;       // agenda muda devagar; são várias consultas ao ERP
  var ESPERA_MAX = 120000;     // teto entre tentativas quando há falhas
  var TIMEOUT = 25000;
  var RECARREGA_APOS = 12 * 3600 * 1000; // TV ligada dias a fio: renova a página

  // Alturas em "em" da fonte do calendário, usadas para distribuir a tela.
  var ALT_TOPO = 1.7;
  var ALT_CARD = 2.45;
  var ALT_COMPACTO = 1.5;
  var ALT_AVISO = 1.05;
  var ALT_MARCO = 1.15;
  var ALT_SEMANA_MIN = 3.2;
  var FONTE_MIN_REM = 0.7;     // abaixo disso os cards passam a ter uma linha só
  var FONTE_MAX_REM = 1.25;
  var FONTE_MAX_COMPACTO_REM = 1;

  var MES_FIXO = <?= json_encode($mesFixo) ?>;
  var DIAG = <?= !empty($_GET['diag']) ? 'true' : 'false' ?>;
  var DEMO = <?= !empty($_GET['demo']) ? 'true' : 'false' ?>;

  var ultimo = null;           // último retorno válido; nunca é apagado por falha
  var falhas = 0;
  var buscando = false;
  var timer = null;
  var inicio = new Date().getTime();

  function $(id) { return document.getElementById(id); }
  function texto(id, valor) { $(id).textContent = valor; }

  function dataBr(iso) {
    var p = String(iso).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : '';
  }

  function criar(tag, classe, conteudo) {
    var el = document.createElement(tag);
    if (classe) { el.className = classe; }
    if (conteudo !== undefined) { el.textContent = conteudo; }
    return el;
  }

  // ── Escala: 1rem proporcional à tela disponível ────────────────────────────
  function ajustaEscala() {
    var l = window.innerWidth;
    var a = window.innerHeight;
    if (l && a) {
      document.documentElement.style.fontSize = (Math.min(l / 1920, a / 1080) * 20) + 'px';
    }
  }

  // ── Distribuição da altura ─────────────────────────────────────────────────
  // Cada semana recebe altura proporcional ao dia mais cheio, então semanas
  // vazias encolhem e sobra espaço para as agendadas. Todo pedido aparece: se
  // a fonte ficar pequena demais, os cards viram uma linha só e, se preciso,
  // a fonte continua diminuindo até caber.
  function altCard(c, compacto) {
    return (compacto ? ALT_COMPACTO : ALT_CARD) + c.avisos.length * ALT_AVISO;
  }

  function altDia(d, compacto) {
    var alt = ALT_TOPO + d.marcos.length * ALT_MARCO;
    for (var i = 0; i < d.cards.length; i++) { alt += altCard(d.cards[i], compacto); }
    return alt;
  }

  function altSemana(s, compacto) {
    var alt = ALT_SEMANA_MIN;
    for (var i = 0; i < s.length; i++) { alt = Math.max(alt, altDia(s[i], compacto)); }
    return alt;
  }

  function somaAlturas(semanas, compacto) {
    var soma = 0;
    for (var i = 0; i < semanas.length; i++) { soma += altSemana(semanas[i], compacto); }
    return soma;
  }

  // ── Calendário ─────────────────────────────────────────────────────────────
  // O cliente é a informação principal; quantidade e pedido são detalhe.
  function criaCartao(c, compacto) {
    var el = criar('div', 'cartao ' + c.tipo + ' ' + c.status + (compacto ? ' compacto' : '') + (c.agora ? ' agora' : ''));
    var enche = criar('div', 'enche');
    enche.style.width = c.pct + '%';
    el.appendChild(enche);
    el.appendChild(criar('div', 'faixa'));
    el.appendChild(criar('div', 'cliCard', c.cliente || c.pedido));
    el.appendChild(criar('div', 'detCard', compacto ? c.qtd : c.qtd + ' · ' + c.pedido));
    for (var i = 0; i < c.avisos.length; i++) {
      el.appendChild(criar('div', 'avisoCard', c.avisos[i]));
    }
    return el;
  }

  function criaDia(d, compacto) {
    var classes = 'dia col' + (d.fds ? ' fds' : '') + (d.outro_mes ? ' outro' : '') + (d.hoje ? ' hoje' : '');
    var el = criar('div', classes);
    var topo = criar('div', 'topoDia');
    topo.appendChild(criar('span', 'num', String(d.num)));
    var somas = criar('span');
    if (d.soma_bag) { somas.appendChild(criar('span', 'somaBag', d.soma_bag)); }
    if (d.soma_sac) { somas.appendChild(criar('span', 'somaSac', d.soma_sac)); }
    topo.appendChild(somas);
    el.appendChild(topo);

    for (var m = 0; m < d.marcos.length; m++) {
      el.appendChild(criar('div', 'marco ' + d.marcos[m].marco, d.marcos[m].texto));
    }
    for (var i = 0; i < d.cards.length; i++) { el.appendChild(criaCartao(d.cards[i], compacto)); }
    return el;
  }

  function montaGrade(d, altura, compacto, soma, fonte) {
    var grade = $('grade');
    grade.textContent = '';
    for (var i = 0; i < d.semanas.length; i++) {
      var linha = criar('div', 'semana');
      linha.style.fontSize = fonte + 'px';
      linha.style.height = (altura * altSemana(d.semanas[i], compacto) / soma) + 'px';
      for (var j = 0; j < d.semanas[i].length; j++) {
        linha.appendChild(criaDia(d.semanas[i][j], compacto));
      }
      grade.appendChild(linha);
    }
  }

  function algumDiaCortado() {
    var dias = document.querySelectorAll('#grade .dia');
    for (var i = 0; i < dias.length; i++) {
      if (dias[i].scrollHeight > dias[i].clientHeight) { return true; }
    }
    return false;
  }

  // As alturas acima são estimativas e bordas em px pesam mais em telas
  // menores; se algo não couber de verdade, reduz a fonte até caber.
  function desenhaGrade(d) {
    $('grade').textContent = '';
    var altura = $('grade').clientHeight;
    var rem = parseFloat(document.documentElement.style.fontSize) || 20;
    var soma = somaAlturas(d.semanas, false);
    var compacto = altura / soma < FONTE_MIN_REM * rem;
    if (compacto) { soma = somaAlturas(d.semanas, true); }
    var fonte = Math.min(altura / soma, (compacto ? FONTE_MAX_COMPACTO_REM : FONTE_MAX_REM) * rem);
    for (var fator = 1; fator >= 0.4; fator -= 0.05) {
      montaGrade(d, altura, compacto, soma, fonte * fator);
      if (!algumDiaCortado()) { return; }
    }
  }

  function desenhaDados(d) {
    texto('titulo', 'Agendamento de Produção — ' + d.mes_titulo + (d.demo ? ' — DEMONSTRAÇÃO' : ''));
    $('vazio').style.display = d.tem_agenda ? 'none' : 'block';
    desenhaGrade(d);
  }

  function mostraMensagemGrade(msg) {
    var grade = $('grade');
    grade.textContent = '';
    var el = criar('div', '', msg);
    el.id = 'estado';
    grade.appendChild(el);
  }

  function estado(nome, selo) {
    document.body.className = nome;
    texto('selo', selo);
  }

  function mostraSucesso() {
    estado(ultimo.demo ? 'ok demo' : 'ok', ultimo.demo ? 'Dados fictícios' : 'Ao vivo');
    texto('hora', 'Atualizado às ' + ultimo.atualizado_em);
    texto('aviso', '');
  }

  // Sem nada válido ainda a grade não é desenhada: erro nunca vira calendário vazio.
  function mostraFalha(motivo) {
    var aviso = $('aviso');
    aviso.textContent = '';
    if (ultimo) {
      estado('desatualizado', 'Desatualizado');
      aviso.appendChild(document.createTextNode(
        'Dados desatualizados: mostrando ' + dataBr(ultimo.hoje) + ' às ' + ultimo.atualizado_em + '. ' + motivo.texto + ' '
      ));
      desenhaDados(ultimo); // o aviso tirou altura do calendário
    } else {
      estado('erro', 'Erro');
      aviso.appendChild(document.createTextNode('Sem dados para exibir. ' + motivo.texto + ' '));
      mostraMensagemGrade('Não foi possível carregar o agendamento.');
    }
    if (motivo.login) {
      var link = criar('a', '', 'Entrar novamente');
      link.href = '/login';
      aviso.appendChild(link);
    } else {
      aviso.appendChild(document.createTextNode('Tentando novamente…'));
    }
  }

  // ── Interpretação da resposta ──────────────────────────────────────────────
  function valido(d) {
    return !!d && d.ok === true && typeof d.mes_titulo === 'string'
      && Object.prototype.toString.call(d.semanas) === '[object Array]';
  }

  function motivoDaResposta(status, corpo, d) {
    if (status === 0) { return { texto: 'Sem resposta do servidor.', codigo: 'rede' }; }
    if (status === 401 || corpo.indexOf('id="frmLogin"') !== -1) {
      return { texto: 'Sessão encerrada.', codigo: 'sessao', login: true };
    }
    if (status === 403) { return { texto: 'Usuário sem permissão para este painel.', codigo: 'permissao' }; }
    if (d && d.erro) { return { texto: d.erro, codigo: d.codigo || 'servidor' }; }
    return { texto: 'Resposta inválida do servidor.', codigo: 'resposta_invalida' };
  }

  function concluir(status, corpo, ms) {
    var d = null;
    try { d = JSON.parse(corpo); } catch (e) { d = null; }

    if (status === 200 && valido(d)) {
      ultimo = d;
      falhas = 0;
      mostraSucesso();
      desenhaDados(d);
      tvRegistra('dados ok: HTTP 200, ' + ms + 'ms, ' + corpo.length + ' bytes');
      if (new Date().getTime() - inicio > RECARREGA_APOS) {
        window.location.reload();
        return;
      }
    } else {
      var motivo = motivoDaResposta(status, corpo, d);
      falhas++;
      mostraFalha(motivo);
      tvRegistra('falha: HTTP ' + status + ', ' + ms + 'ms, motivo=' + motivo.codigo);
    }
  }

  // ── Atualização: a próxima só é agendada quando a atual termina ────────────
  function agendar() {
    var espera = falhas ? Math.min(ESPERA_MAX, 15000 * Math.pow(2, falhas - 1)) : INTERVALO;
    clearTimeout(timer);
    timer = setTimeout(buscar, espera);
  }

  function buscar() {
    if (buscando) { return; }
    buscando = true;
    var t0 = new Date().getTime();
    var xhr = new XMLHttpRequest();
    var encerrado = false;

    function fim() {
      if (encerrado) { return; }
      encerrado = true;
      buscando = false;
      try {
        concluir(xhr.status, xhr.responseText || '', new Date().getTime() - t0);
      } catch (e) {
        falhas++;
        tvRegistra('ERRO ao desenhar: ' + e.message);
        mostraFalha({ texto: 'Falha ao desenhar os dados.', codigo: 'render' });
      }
      agendar();
    }

    var url = '/pcp/tv?action=refresh&_=' + t0;
    if (MES_FIXO) { url += '&mes=' + encodeURIComponent(MES_FIXO); }
    if (DEMO) { url += '&demo=1'; }
    xhr.open('GET', url, true);
    xhr.timeout = TIMEOUT;
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = fim;
    xhr.onerror = fim;
    xhr.onabort = fim;
    xhr.ontimeout = fim;
    xhr.send();
  }

  // ── Tela cheia: opcional, só aparece se a API existir ──────────────────────
  function iniciaTelaCheia() {
    var raiz = document.documentElement;
    var entrar = raiz.requestFullscreen || raiz.webkitRequestFullscreen;
    var sair = document.exitFullscreen || document.webkitExitFullscreen;
    if (!entrar || !sair) { return; }
    var btn = $('btnTela');
    btn.style.display = 'block';
    btn.onclick = function () {
      try {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
          sair.call(document);
        } else {
          entrar.call(raiz);
        }
      } catch (e) {
        tvRegistra('tela cheia indisponível: ' + e.message);
      }
    };
  }

  // ── Diagnóstico: nunca inclui corpo de resposta, nomes ou cookies ──────────
  function iniciaDiagnostico() {
    var el = $('diag');
    el.style.display = 'block';
    var div = document.createElement('div');
    div.style.display = 'flex';
    var recursos = 'flex=' + (div.style.display === 'flex')
      + ' fetch=' + (typeof window.fetch === 'function')
      + ' Promise=' + (typeof window.Promise === 'function')
      + ' Intl=' + (typeof window.Intl === 'object');
    window.tvMostraDiag = function () {
      el.textContent = 'navegador: ' + navigator.userAgent + '\n'
        + 'tela: ' + window.innerWidth + 'x' + window.innerHeight + ' dpr=' + (window.devicePixelRatio || 1) + '  ' + recursos + '\n'
        + window.tvDiag.slice(-12).join('\n');
    };
    window.tvMostraDiag();
  }

  window.addEventListener('resize', function () {
    ajustaEscala();
    if (ultimo) { desenhaDados(ultimo); }
  });

  ajustaEscala();
  iniciaTelaCheia();
  if (DIAG) { iniciaDiagnostico(); }
  buscar();
}());
</script>
</body>
</html>
