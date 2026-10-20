/* ══════════════════════════════════════════════════════════════════════════
   DOMBAG — Chat com IA (widget flutuante e página /assistente)
   JavaScript puro, isolado em Shadow DOM: o CSS do site não afeta o chat e
   o CSS do chat não vaza para o site. Só as variáveis de tema (--blue-*,
   --text-*) atravessam, para acompanhar o tema claro/escuro.

   Modos (atributos da <script>):
     data-modo="flutuante" (padrão) → botão no canto inferior direito
     data-modo="pagina" data-alvo="#seletor" → ocupa o elemento, com a lista
                                               de conversas à esquerda
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__dombagChat) return;
  window.__dombagChat = true;

  var script = document.currentScript;
  var attr = function (n, d) { return (script && script.getAttribute(n)) || d; };
  var CSRF = attr('data-csrf', '');
  var MAX_CHARS = parseInt(attr('data-max', '2000'), 10);
  var API = '/api/chat';
  // conversa aberta guardada por usuário (computadores compartilhados na fábrica)
  var KEY_CONV = 'dombag_chat_conversa_' + attr('data-usuario', '0');
  var KEY_OPEN = 'dombag_chat_aberto';

  var alvo = attr('data-modo', '') === 'pagina' ? document.querySelector(attr('data-alvo', '')) : null;
  var PAGINA = !!alvo;

  var STATUS = {
    meu_perfil: 'Verificando seu perfil…',
    listar_pedidos: 'Consultando pedidos…',
    detalhe_pedido: 'Consultando itens do pedido…',
    resumo_faturamento: 'Calculando faturamento…',
    status_producao_pedido: 'Consultando a produção…',
    gerar_relatorio: 'Gerando o relatório…',
    faturamento_notas: 'Consultando as notas fiscais…',
    conciliar_faturamento: 'Cruzando Histórico de Vendas com as NF-e…',
    trocar_modelo: 'O serviço de IA oscilou — refazendo com outro modelo…'
  };
  var SUGESTOES = [
    'Quanto faturamos este mês?',
    'Liste os pedidos emitidos nos últimos 7 dias',
    'Qual foi o faturamento por representante no mês passado?',
    'Gere um PDF com o faturamento mensal deste ano'
  ];

  function store(fn, k, v) {
    try { return fn === 'get' ? sessionStorage.getItem(k) : (v === null ? sessionStorage.removeItem(k) : sessionStorage.setItem(k, v)); }
    catch (e) { return null; }
  }

  var CSS = [
    ':host{all:initial}',
    '*{box-sizing:border-box;font-family:"Segoe UI",system-ui,-apple-system,sans-serif}',
    'button{font:inherit}',
    /* botão flutuante */
    '.fab{position:fixed;right:20px;bottom:20px;width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;',
    ' background:var(--blue-accent,#1e4fc9);color:#fff;box-shadow:0 6px 20px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;z-index:9000;transition:transform .15s}',
    '.fab:hover{transform:scale(1.06)}.fab:focus-visible{outline:3px solid var(--teal,#00c9a7);outline-offset:2px}',
    '.fab svg{width:26px;height:26px}',
    /* janela do chat */
    '.panel{position:fixed;right:20px;bottom:88px;width:380px;max-width:calc(100vw - 32px);height:min(600px,calc(100vh - 120px));',
    ' background:var(--modal-bg,#152845);color:var(--text-primary,#e8edf5);border:1px solid var(--border,rgba(255,255,255,.08));',
    ' border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.35);display:none;flex-direction:column;overflow:hidden;z-index:9001}',
    '.panel.open{display:flex}',
    '.head{display:flex;align-items:center;gap:8px;padding:12px 14px;background:var(--blue-mid,#112240);border-bottom:1px solid var(--border,rgba(255,255,255,.08));min-width:0}',
    '.head h2{flex:1;margin:0;font-size:15px;font-weight:600;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.head small{display:block;font-size:11px;font-weight:400;color:var(--text-muted,#7a90b0);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.icon{background:transparent;border:1px solid var(--border,rgba(255,255,255,.1));color:var(--text-muted,#7a90b0);border-radius:8px;height:32px;padding:0 10px;cursor:pointer;font-size:12px;flex-shrink:0}',
    '.icon:hover{color:var(--text-primary,#e8edf5);background:var(--card-hover,rgba(255,255,255,.06))}',
    '.msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;font-size:14px;line-height:1.5}',
    '.m{max-width:88%;padding:9px 12px;border-radius:12px;word-wrap:break-word;white-space:normal}',
    '.m.user{align-self:flex-end;background:var(--blue-accent,#1e4fc9);color:#fff;border-bottom-right-radius:4px;white-space:pre-wrap}',
    '.m.assistant{align-self:flex-start;background:var(--card-hover,rgba(255,255,255,.06));border-bottom-left-radius:4px}',
    '.m.erro{align-self:flex-start;background:rgba(239,68,68,.12);color:var(--red,#ef4444);border:1px solid rgba(239,68,68,.3)}',
    '.m ul{margin:4px 0;padding-left:18px}.m p{margin:0 0 6px}.m p:last-child{margin:0}',
    '.status{font-size:12px;color:var(--text-muted,#7a90b0);font-style:italic;margin-top:4px}',
    '.dots{display:inline-flex;gap:4px;padding:4px 0}.dots i{width:7px;height:7px;border-radius:50%;background:var(--text-muted,#7a90b0);animation:b 1.2s infinite}',
    '.dots i:nth-child(2){animation-delay:.2s}.dots i:nth-child(3){animation-delay:.4s}',
    '@keyframes b{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}',
    '.files{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}',
    '.files a{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:8px;background:var(--teal,#00c9a7);color:#06281f;text-decoration:none;font-size:13px;font-weight:600}',
    '.files a.xlsx{background:#1d9a5b;color:#fff}',
    '.welcome{margin:auto 0;text-align:center;color:var(--text-muted,#7a90b0);font-size:13px;padding:0 8px}',
    '.welcome h3{margin:0 0 4px;font-size:17px;color:var(--text-primary,#e8edf5);font-weight:600}',
    '.chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:14px}',
    '.chip{background:var(--card-bg,rgba(255,255,255,.04));border:1px solid var(--border,rgba(255,255,255,.1));color:var(--text-primary,#e8edf5);',
    ' border-radius:999px;padding:7px 12px;font-size:12.5px;cursor:pointer;text-align:left}',
    '.welcome-chips{justify-content:flex-start;margin:-2px 0 4px}',
    '.chip:hover{border-color:var(--blue-light,#2d6aff);background:var(--card-hover,rgba(255,255,255,.06))}',
    '.foot{border-top:1px solid var(--border,rgba(255,255,255,.08));padding:10px;display:flex;gap:8px;align-items:flex-end}',
    'textarea{flex:1;resize:none;max-height:140px;min-height:40px;border-radius:10px;padding:9px 11px;font-size:14px;',
    ' background:var(--card-bg,rgba(255,255,255,.04));color:var(--text-primary,#e8edf5);border:1px solid var(--border,rgba(255,255,255,.1))}',
    'textarea:focus{outline:2px solid var(--blue-light,#2d6aff);outline-offset:-1px}',
    '.send{height:40px;width:44px;border-radius:10px;border:0;background:var(--blue-accent,#1e4fc9);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}',
    '.send:disabled{opacity:.45;cursor:default}.send svg{width:18px;height:18px}',
    '.count{font-size:11px;color:var(--text-muted,#7a90b0);padding:0 12px 6px;text-align:right;min-height:6px}',
    '.count.over{color:var(--red,#ef4444)}',
    '.aviso{font-size:11px;color:var(--text-muted,#7a90b0);text-align:center;padding:0 12px 8px}',
    '@media (max-width:600px){.panel{right:0;bottom:0;width:100vw;max-width:100vw;height:100dvh;border-radius:0}',
    ' .fab{right:16px;bottom:16px}.panel.open~.fab{display:none}}',

    /* ── modo página ── */
    ':host(.pagina){display:block;height:100%;min-height:0}',
    '.app{display:grid;grid-template-columns:270px 1fr;height:100%;min-height:0;border:1px solid var(--border,rgba(255,255,255,.08));',
    ' border-radius:var(--radius,12px);overflow:hidden;background:var(--modal-bg,#152845);color:var(--text-primary,#e8edf5);position:relative}',
    '.lista{display:flex;flex-direction:column;min-height:0;background:var(--blue-mid,#112240);border-right:1px solid var(--border,rgba(255,255,255,.08))}',
    '.lista-topo{padding:12px}',
    '.nova{width:100%;height:38px;border-radius:9px;border:1px dashed var(--border,rgba(255,255,255,.18));background:transparent;color:var(--text-primary,#e8edf5);cursor:pointer;font-size:13px;font-weight:600}',
    '.nova:hover{border-color:var(--blue-light,#2d6aff);background:var(--card-hover,rgba(255,255,255,.06))}',
    '.lista h4{margin:6px 14px 6px;font-size:10.5px;letter-spacing:.6px;text-transform:uppercase;color:var(--text-muted,#7a90b0);font-weight:600}',
    '.convs{flex:1;overflow-y:auto;padding:0 8px 12px;list-style:none;margin:0}',
    '.conv{display:flex;align-items:center;gap:4px;border-radius:8px;margin-bottom:2px}',
    '.conv button.abrir{flex:1;min-width:0;text-align:left;background:transparent;border:0;color:var(--text-primary,#e8edf5);padding:8px 8px;cursor:pointer;border-radius:8px}',
    '.conv .t{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.conv .d{display:block;font-size:11px;color:var(--text-muted,#7a90b0);margin-top:1px}',
    '.conv:hover,.conv.ativa{background:var(--card-hover,rgba(255,255,255,.06))}',
    '.conv.ativa{box-shadow:inset 3px 0 0 var(--blue-light,#2d6aff)}',
    '.conv .apagar{opacity:0;background:transparent;border:0;color:var(--text-muted,#7a90b0);cursor:pointer;width:28px;height:28px;border-radius:6px;flex-shrink:0}',
    '.conv:hover .apagar,.conv .apagar:focus-visible{opacity:1}.conv .apagar:hover{color:var(--red,#ef4444);background:rgba(239,68,68,.12)}',
    '.vazia{padding:10px 14px;font-size:12px;color:var(--text-muted,#7a90b0)}',
    /* barrinha de uso: a parte cheia é o que ainda RESTA (como bateria) */
    '.uso{border-top:1px solid var(--border,rgba(255,255,255,.08));padding:10px 14px 12px;font-size:11.5px;color:var(--text-muted,#7a90b0)}',
    '.uso h4{margin:0 0 8px}',
    '.uso-item{margin-bottom:9px}.uso-item:last-child{margin-bottom:0}',
    '.uso-l{display:flex;justify-content:space-between;gap:8px;margin-bottom:4px}',
    '.uso-l b{color:var(--text-primary,#e8edf5);font-weight:600;white-space:nowrap}',
    '.barra{height:6px;border-radius:999px;background:var(--card-hover,rgba(255,255,255,.08));overflow:hidden}',
    '.barra i{display:block;height:100%;border-radius:999px;background:var(--blue-light,#2d6aff);transition:width .3s}',
    '.baixo .barra i{background:var(--amber,#f59e0b)}.zerado .barra i{background:var(--red,#ef4444)}',
    '.zerado .uso-l b,.uso-mini.zerado span{color:var(--red,#ef4444)}',
    '.uso-nota{font-size:10.5px;margin-top:3px;opacity:.9}',
    '.uso-mini{display:flex;align-items:center;gap:8px;padding:0 12px 8px;font-size:11px;color:var(--text-muted,#7a90b0)}',
    '.uso-mini[hidden]{display:none}',
    '.uso-mini .barra{flex:0 0 70px;height:5px}',
    '.app .uso-mini{display:none}',
    '.app .panel{position:static;display:flex;width:auto;max-width:none;height:100%;min-height:0;border:0;border-radius:0;box-shadow:none;background:transparent}',
    '.app .msgs{padding:22px max(16px,calc((100% - 820px)/2))}',
    '.app .foot{padding:12px max(12px,calc((100% - 820px)/2))}',
    '.app .m{max-width:min(88%,720px)}',
    '.app .welcome h3{font-size:20px}',
    '.btn-lista{display:none}',
    '@media (max-width:760px){.app{grid-template-columns:1fr;border-radius:0}',
    ' .lista{position:absolute;inset:0 auto 0 0;width:min(300px,85%);z-index:2;transform:translateX(-102%);transition:transform .2s;box-shadow:8px 0 24px rgba(0,0,0,.35)}',
    ' .app.lista-aberta .lista{transform:none}.btn-lista{display:inline-block}.conv .apagar{opacity:1}}'
  ].join('');

  /* ── Montagem ── */
  var host = PAGINA ? alvo : document.body.appendChild(document.createElement('div'));
  if (!PAGINA) host.id = 'dombag-chat';
  else host.classList.add('pagina');
  var root = host.attachShadow({ mode: 'open' });

  var ICONE_ENVIAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>';
  var PAINEL =
    '<section class="panel' + (PAGINA ? ' open' : '') + '" role="' + (PAGINA ? 'region' : 'dialog') + '" aria-label="Assistente DOMBAG">' +
    '  <header class="head">' +
    (PAGINA ? '<button class="icon btn-lista" data-a="lista" aria-label="Mostrar conversas">☰</button>' : '') +
    '    <h2><span class="titulo">Assistente DOMBAG</span><small>Vendas, pedidos, faturamento e produção</small></h2>' +
    (PAGINA ? '' : '<a class="icon" href="/assistente" title="Abrir em tela cheia" style="display:inline-flex;align-items:center;text-decoration:none">⤢</a>') +
    '    <button class="icon" data-a="nova" title="Começar uma nova conversa">Nova conversa</button>' +
    (PAGINA ? '' : '<button class="icon" data-a="fechar" aria-label="Fechar chat">✕</button>') +
    '  </header>' +
    '  <div class="msgs" aria-live="polite"></div>' +
    '  <div class="foot"><textarea rows="1" placeholder="Pergunte sobre pedidos, faturamento ou produção…" aria-label="Mensagem"></textarea>' +
    '    <button class="send" aria-label="Enviar">' + ICONE_ENVIAR + '</button></div>' +
    '  <div class="count"></div>' +
    '  <div class="uso-mini" hidden></div>' +
    (PAGINA ? '<div class="aviso">As respostas usam dados do ERP conforme suas permissões. Confira números importantes antes de decisões.</div>' : '') +
    '</section>';

  if (PAGINA) {
    root.innerHTML = '<style>' + CSS + '</style><div class="app">' +
      '<aside class="lista" aria-label="Conversas"><div class="lista-topo"><button class="nova" data-a="nova">+ Nova conversa</button></div>' +
      '<h4>Conversas recentes</h4><ul class="convs"></ul><div class="uso" hidden></div></aside>' + PAINEL + '</div>';
  } else {
    root.innerHTML = '<style>' + CSS + '</style>' + PAINEL +
      '<button class="fab" aria-label="Abrir assistente"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></button>';
  }

  var app = root.querySelector('.app');
  var panel = root.querySelector('.panel');
  var msgs = root.querySelector('.msgs');
  var input = root.querySelector('textarea');
  var btnSend = root.querySelector('.send');
  var count = root.querySelector('.count');
  var tituloEl = root.querySelector('.titulo');
  var convsEl = root.querySelector('.convs');
  var busy = false;
  var carregado = false;

  /* ── Quanto o usuário ainda tem disponível ── */
  var usoEl = root.querySelector('.uso');
  var usoMini = root.querySelector('.uso-mini');
  function nivel(resta, limite) { return resta <= 0 ? 'zerado' : (resta / limite <= 0.2 ? 'baixo' : ''); }
  function itemUso(rotulo, resta, limite, nota) {
    var pct = limite > 0 ? Math.max(0, Math.min(100, resta / limite * 100)) : 0;
    return '<div class="uso-item ' + nivel(resta, limite) + '"><div class="uso-l"><span>' + esc(rotulo) + '</span><b>' +
      esc(resta + ' de ' + limite) + '</b></div><div class="barra" role="progressbar" aria-label="' + esc(rotulo) +
      '" aria-valuemin="0" aria-valuemax="' + limite + '" aria-valuenow="' + resta + '"><i style="width:' + pct + '%"></i></div>' +
      (nota ? '<div class="uso-nota">' + esc(nota) + '</div>' : '') + '</div>';
  }
  function desenharUso(u) {
    var m = u.mensagens, r = u.relatorios;
    var restaM = Math.max(0, m.limite - m.usadas), restaR = Math.max(0, r.limite - r.usados);
    var notaM = restaM === 0 && m.libera_em_min ? 'Libera uma nova mensagem em ' + m.libera_em_min + ' min' : 'Renova continuamente (janela de 1 hora)';
    if (usoEl) {
      var html = '<h4>Disponível para você</h4>' +
        itemUso('Mensagens nesta hora', restaM, m.limite, notaM) +
        itemUso('Relatórios hoje', restaR, r.limite, restaR === 0 ? 'Renova à meia-noite' : '');
      var p = u.provedor;
      if (p) {
        var notaP = (p.esgotou ? 'Cota esgotou hoje às ' + p.esgotou + '. ' : '') + 'Zera às ' + p.renova_as + ' (horário de Brasília).';
        html += p.limite
          ? itemUso(p.nome + ' — empresa hoje', Math.max(0, p.limite - p.usadas), p.limite, notaP)
          : '<div class="uso-item"><div class="uso-l"><span>' + esc(p.nome) + ' — empresa hoje</span><b>' + p.usadas +
            ' usadas</b></div><div class="uso-nota">' + esc(notaP + ' Defina GEMINI_LIMITE_DIA para ver o saldo.') + '</div></div>';
      }
      usoEl.innerHTML = html;
      usoEl.hidden = false;
    }
    if (usoMini) {
      var pct = m.limite > 0 ? restaM / m.limite * 100 : 0;
      usoMini.className = 'uso-mini ' + nivel(restaM, m.limite);
      usoMini.innerHTML = '<div class="barra"><i style="width:' + pct + '%"></i></div><span>' +
        esc(restaM + ' de ' + m.limite + ' mensagens nesta hora · ' + restaR + ' de ' + r.limite + ' relatórios hoje') + '</span>';
      usoMini.hidden = false;
    }
  }
  function carregarUso() {
    fetch(API + '?acao=uso', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(desenharUso)
      .catch(function () {});
  }

  /* ── Renderização segura (texto escapado + markdown mínimo) ── */
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function md(texto) {
    var linhas = esc(texto).split('\n'), html = '', lista = false, par = [];
    function fechaPar() { if (par.length) { html += '<p>' + par.join('<br>') + '</p>'; par = []; } }
    linhas.forEach(function (l) {
      var item = /^\s*[-*•]\s+(.*)$/.exec(l);
      if (item) { fechaPar(); if (!lista) { html += '<ul>'; lista = true; } html += '<li>' + item[1] + '</li>'; return; }
      if (lista) { html += '</ul>'; lista = false; }
      if (l.trim() === '') fechaPar(); else par.push(l);
    });
    fechaPar();
    if (lista) html += '</ul>';
    return html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  }

  function bolha(papel, texto) {
    var w = root.querySelector('.welcome'); if (w) w.remove();
    var sug = root.querySelector('.welcome-chips'); if (sug) sug.remove();
    var d = document.createElement('div');
    d.className = 'm ' + papel;
    if (papel === 'user' || papel === 'erro') d.textContent = texto; else d.innerHTML = md(texto || '');
    msgs.appendChild(d);
    rolar();
    return d;
  }
  function anexarArquivos(el, arquivos) {
    if (!arquivos || !arquivos.length) return;
    var box = el.querySelector('.files') || el.appendChild(Object.assign(document.createElement('div'), { className: 'files' }));
    arquivos.forEach(function (a) {
      if (!a || typeof a.url !== 'string' || a.url.indexOf('/api/chat/relatorio/') !== 0) return;
      var link = document.createElement('a');
      link.href = a.url;
      link.className = a.formato === 'xlsx' ? 'xlsx' : 'pdf';
      link.textContent = '⬇ Baixar ' + (a.formato === 'xlsx' ? 'Excel' : 'PDF');
      link.title = a.nome || '';
      link.setAttribute('download', '');
      box.appendChild(link);
    });
    rolar();
  }
  function rolar() { msgs.scrollTop = msgs.scrollHeight; }

  // Tela inicial: apresentação personalizada do usuário (montada no servidor a
  // partir das permissões dele, sem custo de IA) + sugestões do que ele pode
  // consultar. A apresentação fica no topo da conversa; as sugestões somem
  // quando a primeira pergunta é enviada.
  var inicio = null;
  function boasVindas() {
    msgs.innerHTML = '';
    if (tituloEl) tituloEl.textContent = 'Assistente DOMBAG';
    var intro = document.createElement('div');
    intro.className = 'm assistant inicio';
    intro.innerHTML = '<span class="dots"><i></i><i></i><i></i></span>';
    msgs.appendChild(intro);
    var chips = document.createElement('div');
    chips.className = 'chips welcome-chips';
    msgs.appendChild(chips);

    function preencher(d) {
      intro.innerHTML = md(d.texto);
      chips.innerHTML = '';
      (d.sugestoes || []).forEach(function (s) {
        var b = document.createElement('button');
        b.className = 'chip';
        b.textContent = s;
        b.addEventListener('click', function () { input.value = s; atualizaContador(); enviar(); });
        chips.appendChild(b);
      });
    }
    if (inicio) { preencher(inicio); return; }
    fetch(API + '?acao=inicio', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (d) { inicio = d; preencher(d); })
      .catch(function () {
        preencher({ texto: 'Olá! Posso ajudar com vendas, pedidos, faturamento e produção, e gerar relatórios em PDF ou Excel. Como posso ajudar?', sugestoes: SUGESTOES });
      });
  }

  /* ── Histórico de uma conversa ── */
  function carregarConversa(id, titulo) {
    carregado = true;
    if (!id) { boasVindas(); marcarAtiva(null); return; }
    msgs.innerHTML = '<div class="welcome"><span class="dots"><i></i><i></i><i></i></span></div>';
    fetch(API + '?acao=historico&conversa=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { mensagens: [] }; })
      .then(function (j) {
        msgs.innerHTML = '';
        if (!j.mensagens || !j.mensagens.length) { store('set', KEY_CONV, null); boasVindas(); marcarAtiva(null); return; }
        store('set', KEY_CONV, id);
        if (tituloEl && titulo) tituloEl.textContent = titulo;
        j.mensagens.forEach(function (m) { anexarArquivos(bolha(m.papel === 'user' ? 'user' : 'assistant', m.texto), m.arquivos); });
        marcarAtiva(id);
      })
      .catch(boasVindas);
  }

  function nova() {
    if (busy) return;
    store('set', KEY_CONV, null);
    boasVindas();
    marcarAtiva(null);
    fecharLista();
    input.focus();
  }

  /* ── Lista de conversas (modo página) ── */
  var conversas = [];
  function dataCurta(s) {
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return '';
    var hoje = new Date();
    return d.toDateString() === hoje.toDateString()
      ? 'hoje, ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit' });
  }
  function carregarLista() {
    if (!PAGINA) return;
    fetch(API + '?acao=conversas', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { conversas: [] }; })
      .then(function (j) { conversas = j.conversas || []; desenharLista(); })
      .catch(function () {});
  }
  function desenharLista() {
    convsEl.innerHTML = '';
    if (!conversas.length) {
      convsEl.innerHTML = '<li class="vazia">Suas conversas aparecem aqui.</li>';
      return;
    }
    var atual = store('get', KEY_CONV);
    conversas.forEach(function (c) {
      var li = document.createElement('li');
      li.className = 'conv' + (c.id === atual ? ' ativa' : '');
      if (c.id === atual && tituloEl) tituloEl.textContent = c.titulo;
      li.dataset.id = c.id;
      var abrir = document.createElement('button');
      abrir.className = 'abrir';
      abrir.innerHTML = '<span class="t"></span><span class="d"></span>';
      abrir.querySelector('.t').textContent = c.titulo;
      abrir.querySelector('.d').textContent = dataCurta(c.atualizado) + ' · ' + c.perguntas + (c.perguntas === 1 ? ' pergunta' : ' perguntas');
      abrir.title = c.titulo;
      abrir.addEventListener('click', function () { if (!busy) { carregarConversa(c.id, c.titulo); fecharLista(); } });
      var apagar = document.createElement('button');
      apagar.className = 'apagar';
      apagar.setAttribute('aria-label', 'Apagar conversa');
      apagar.title = 'Apagar conversa';
      apagar.textContent = '🗑';
      apagar.addEventListener('click', function () { apagarConversa(c); });
      li.appendChild(abrir);
      li.appendChild(apagar);
      convsEl.appendChild(li);
    });
  }
  function marcarAtiva(id) {
    if (!convsEl) return;
    convsEl.querySelectorAll('.conv').forEach(function (li) { li.classList.toggle('ativa', li.dataset.id === id); });
  }
  function apagarConversa(c) {
    if (busy || !confirm('Apagar a conversa "' + c.titulo + '"? Isso não pode ser desfeito.')) return;
    fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ acao: 'apagar', conversa: c.id })
    }).then(function () {
      if (store('get', KEY_CONV) === c.id) { store('set', KEY_CONV, null); boasVindas(); }
      carregarLista();
    });
  }
  function abrirLista() { if (app) app.classList.add('lista-aberta'); }
  function fecharLista() { if (app) app.classList.remove('lista-aberta'); }

  /* ── Abrir / fechar (modo flutuante) ── */
  function abrir() {
    panel.classList.add('open');
    store('set', KEY_OPEN, '1');
    carregarUso();
    if (!carregado) carregarConversa(store('get', KEY_CONV));
    setTimeout(function () { input.focus(); }, 50);
  }
  function fechar() { panel.classList.remove('open'); store('set', KEY_OPEN, null); }

  /* ── Envio + leitura do stream SSE ── */
  function atualizaContador() {
    var n = input.value.length;
    count.textContent = n > MAX_CHARS * 0.8 ? n + ' / ' + MAX_CHARS : '';
    count.classList.toggle('over', n > MAX_CHARS);
    btnSend.disabled = busy || !input.value.trim() || n > MAX_CHARS;
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 140) + 'px';
  }

  function enviar() {
    var texto = input.value.trim();
    if (busy || !texto || texto.length > MAX_CHARS) return;
    busy = true;
    input.value = '';
    atualizaContador();
    bolha('user', texto);
    if (tituloEl && !store('get', KEY_CONV)) tituloEl.textContent = texto.slice(0, 80);

    var el = bolha('assistant', '');
    el.innerHTML = '<span class="dots"><i></i><i></i><i></i></span>';
    var status = null, acumulado = '', arquivos = [], terminou = false;

    function render() {
      el.innerHTML = acumulado ? md(acumulado) : '<span class="dots"><i></i><i></i><i></i></span>';
      if (status) el.appendChild(status);
      anexarArquivos(el, arquivos);
      rolar();
    }
    function erro(msg) {
      terminou = true;
      if (!acumulado) el.remove();
      bolha('erro', msg);
    }
    function evento(nome, dados) {
      if (nome === 'conversa') { store('set', KEY_CONV, dados.id); marcarAtiva(dados.id); }
      else if (nome === 'delta') { acumulado += dados.t; status = null; render(); }
      else if (nome === 'status') {
        status = Object.assign(document.createElement('div'), { className: 'status', textContent: STATUS[dados.ferramenta] || 'Consultando…' });
        render();
      } else if (nome === 'descartar') { acumulado = ''; render(); }
      else if (nome === 'arquivo') { arquivos.push(dados); render(); }
      else if (nome === 'erro') erro(dados.msg || 'Erro ao responder.');
      else if (nome === 'fim') {
        terminou = true; status = null; render();
        if (dados.cheia) bolha('erro', 'Esta conversa chegou ao limite de tamanho. Clique em "Nova conversa" para continuar.');
      }
    }

    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ acao: 'enviar', conversa: store('get', KEY_CONV), mensagem: texto })
    }).then(function (r) {
      var tipo = r.headers.get('Content-Type') || '';
      if (tipo.indexOf('text/event-stream') === -1) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (r.status === 401) { erro(j.erro || 'Sessão expirada.'); setTimeout(function () { location.href = '/login'; }, 1500); return; }
          erro(j.erro || 'Não foi possível enviar a mensagem.');
        });
      }
      var reader = r.body.getReader(), dec = new TextDecoder(), buf = '';
      function ler() {
        return reader.read().then(function (p) {
          if (p.done) return;
          buf += dec.decode(p.value, { stream: true });
          var partes = buf.split('\n\n');
          buf = partes.pop();
          partes.forEach(function (bloco) {
            var ev = 'message', data = '';
            bloco.split('\n').forEach(function (l) {
              if (l.indexOf('event: ') === 0) ev = l.slice(7);
              else if (l.indexOf('data: ') === 0) data += l.slice(6);
            });
            if (data) { try { evento(ev, JSON.parse(data)); } catch (e) { /* bloco inválido: ignora */ } }
          });
          return ler();
        });
      }
      return ler();
    }).catch(function () {
      erro('Falha de conexão. Verifique sua internet e tente novamente.');
    }).then(function () {
      if (!terminou && !acumulado) erro('A resposta foi interrompida. Tente novamente.');
      busy = false;
      atualizaContador();
      carregarLista();
      carregarUso();
    });
  }

  /* ── Eventos ── */
  root.querySelectorAll('[data-a="nova"]').forEach(function (b) { b.addEventListener('click', nova); });
  var bFechar = root.querySelector('[data-a="fechar"]');
  if (bFechar) bFechar.addEventListener('click', fechar);
  var bLista = root.querySelector('[data-a="lista"]');
  if (bLista) bLista.addEventListener('click', function () { app.classList.contains('lista-aberta') ? fecharLista() : abrirLista(); });
  btnSend.addEventListener('click', enviar);
  input.addEventListener('input', atualizaContador);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); }
    if (e.key === 'Escape') PAGINA ? fecharLista() : fechar();
  });
  atualizaContador();

  if (PAGINA) {
    // a página sempre abre na tela inicial; conversas anteriores ficam na lista
    store('set', KEY_CONV, null);
    carregarConversa(null);
    carregarLista();
    carregarUso();
    setInterval(carregarUso, 60000);   // a janela de 1 hora vai liberando mensagens
    setTimeout(function () { input.focus(); }, 50);
  } else {
    root.querySelector('.fab').addEventListener('click', function () { panel.classList.contains('open') ? fechar() : abrir(); });
    if (store('get', KEY_OPEN)) abrir();
  }
})();
