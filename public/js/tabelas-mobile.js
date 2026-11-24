/* ══════════════════════════════════════════════════════════════════════════
   DOMBAG — Tabelas largas como cards no celular
   Uso no HTML:  <table class="tabela-cards">  e  <th data-destaque>  nas
   3 ou 4 colunas principais. A primeira vira o título do card, ou a marcada
   com data-destaque="titulo".
   Este script copia o nome de cada coluna para o data-label das células e
   marca as de destaque; o visual fica em unified_admin.css (até 600px).
   No desktop nada muda: os atributos só são usados pelo CSS do celular.
   Tabelas desenhadas por JavaScript são acompanhadas por um observador
   ligado só à própria tabela. Sem tabela marcada, o script não faz nada.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  function nomeColuna(th) {
    var nome = th.getAttribute('data-label');
    if (nome === null) nome = th.textContent;
    return nome.replace(/[↑↓↕▲▼]/g, '').replace(/\s+/g, ' ').trim();
  }

  function rotular(tabela) {
    var cab = tabela.tHead && tabela.tHead.rows.length ? tabela.tHead.rows[tabela.tHead.rows.length - 1].cells : [];
    var nomes = [], destaque = [], titulo = -1, i, k, col = 0;
    for (i = 0; i < cab.length; i++) {
      for (k = 0; k < (cab[i].colSpan || 1); k++) {
        nomes[col] = nomeColuna(cab[i]);
        destaque[col] = cab[i].hasAttribute('data-destaque');
        if (cab[i].getAttribute('data-destaque') === 'titulo' && titulo < 0) titulo = col;
        col++;
      }
    }
    // Título: o destaque marcado como "titulo" ou, sem ele, o primeiro destaque
    if (titulo < 0) titulo = destaque.indexOf(true);

    var grupos = [].concat([].slice.call(tabela.tBodies), tabela.tFoot ? [tabela.tFoot] : []);
    grupos.forEach(function (grupo) {
      [].forEach.call(grupo.rows, function (tr) {
        var c = 0;
        [].forEach.call(tr.cells, function (td) {
          var larga = (td.colSpan || 1) > 1;
          var nome = larga ? '' : (nomes[c] || '');
          if (td.getAttribute('data-label') !== nome) td.setAttribute('data-label', nome);
          td.classList.toggle('tm-larga', larga);
          td.classList.toggle('tm-destaque', !larga && !!destaque[c]);
          td.classList.toggle('tm-titulo', !larga && c === titulo);
          c += td.colSpan || 1;
        });
      });
    });
  }

  function acompanhar(tabela) {
    rotular(tabela);
    if (!window.MutationObserver) return;
    var pendente = false;
    // Só linhas entrando/saindo (childList). As mudanças de atributo feitas
    // aqui não disparam o observador, então não há laço.
    new MutationObserver(function () {
      if (pendente) return;
      pendente = true;
      (window.requestAnimationFrame || setTimeout)(function () { pendente = false; rotular(tabela); });
    }).observe(tabela, { childList: true, subtree: true });
  }

  function iniciar() {
    var tabelas = document.querySelectorAll('table.tabela-cards');
    if (!tabelas.length) return;
    [].forEach.call(tabelas, acompanhar);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
