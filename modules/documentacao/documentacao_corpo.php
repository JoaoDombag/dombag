<?php
// Corpo da Documentação. Recebe $telasPorArea de documentacao.php.
$h = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
?>
<style>
  /* A linha de costura e o tecido de ráfia vêm do produto: é o caminho do pedido. */
  .doc { --doc-fio: rgba(255,255,255,.03); --doc-linha: var(--amber); --doc-titulo: Bahnschrift, 'Segoe UI', system-ui, sans-serif; }
  :root[data-theme="claro"] .doc { --doc-fio: rgba(15,23,42,.04); --doc-linha: #b45309; }

  .content.doc { display: block; overflow: auto; padding: 0 !important; scroll-behavior: smooth; }
  .doc :focus-visible { outline: 2px solid var(--blue-light); outline-offset: 2px; border-radius: 3px; }

  .doc-capa {
    padding: 44px 40px 40px; border-bottom: 1px solid var(--border);
    background:
      repeating-linear-gradient(0deg, var(--doc-fio) 0 2px, transparent 2px 5px),
      repeating-linear-gradient(90deg, var(--doc-fio) 0 2px, transparent 2px 5px),
      var(--blue-mid);
  }
  .doc-capa-in { max-width: 1180px; margin: 0 auto; }
  .doc-capa h2 {
    font-family: var(--doc-titulo); font-stretch: 75%; font-weight: 700; font-size: clamp(38px, 5.4vw, 68px);
    line-height: 1.02; letter-spacing: -.5px; max-width: 16ch;
  }
  .doc-capa > div > p { margin-top: 12px; max-width: 58ch; font-size: 15px; line-height: 1.55; color: var(--text-muted); }

  .doc-caminho { position: relative; display: grid; grid-template-columns: repeat(6, 1fr); list-style: none; margin: 40px 0 0; padding: 0; }
  .doc-caminho::before {
    content: ''; position: absolute; left: 15px; right: 16.66%; top: 15px; height: 3px;
    background: repeating-linear-gradient(90deg, var(--doc-linha) 0 10px, transparent 10px 17px);
    animation: doc-costura 1.2s ease-out both;
  }
  @keyframes doc-costura { from { clip-path: inset(0 100% 0 0); } to { clip-path: inset(0); } }
  .doc-caminho a { position: relative; display: block; padding-right: 14px; color: inherit; text-decoration: none; }
  .doc-caminho i {
    display: flex; align-items: center; justify-content: center; width: 33px; height: 33px; border-radius: 50%;
    border: 3px solid var(--doc-linha); background: var(--blue-mid); font: 700 14px var(--doc-titulo);
  }
  .doc-caminho b { display: block; margin-top: 12px; font: 700 19px/1.15 var(--doc-titulo); font-stretch: 75%; }
  .doc-caminho span { display: block; margin-top: 3px; font-size: 12.5px; line-height: 1.35; color: var(--text-muted); }
  .doc-caminho a:hover b { color: var(--doc-linha); }

  .doc-corpo { display: grid; grid-template-columns: 190px minmax(0, 1fr); gap: 44px; max-width: 1260px; margin: 0 auto; padding: 28px 40px 64px; }
  .doc-indice { position: sticky; top: 24px; align-self: start; font-size: 13px; }
  .doc-indice a { display: block; padding: 5px 0; color: var(--text-muted); text-decoration: none; }
  .doc-indice a:hover { color: var(--text-primary); }
  .doc-indice hr { margin: 8px 0; border: 0; border-top: 1px solid var(--border); }

  .doc-busca label { display: block; margin-bottom: 7px; font-size: 13px; font-weight: 600; }
  .doc-busca input { width: 100%; max-width: 560px; height: 42px; padding: 0 14px; font-size: 14px; }
  .doc-busca p { min-height: 20px; margin-top: 7px; font-size: 13px; color: var(--text-muted); }
  .doc-busca p a { color: var(--blue-light); }

  .doc-secao { margin-top: 30px; scroll-margin-top: 16px; }
  .doc-secao > h3 { font: 700 27px/1.15 var(--doc-titulo); font-stretch: 75%; }
  .doc-secao > p { margin-top: 3px; font-size: 13.5px; color: var(--text-muted); }

  .doc-linha { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 4px 20px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; line-height: 1.45; }
  .doc-secao > h3 + p + .doc-linha, .doc-secao > h3 + .doc-linha { margin-top: 8px; border-top: 1px solid var(--border); }
  .doc-linha > a { font-weight: 600; color: var(--text-primary); text-decoration-color: var(--doc-linha); text-underline-offset: 3px; }
  .doc-linha > a:hover { color: var(--doc-linha); }
  .doc-linha > strong { font-weight: 600; }
  .doc-linha small { display: block; font-size: 12px; font-weight: 400; color: var(--text-muted); }
  .doc-linha > span { max-width: 68ch; color: var(--text-muted); }

  .doc-guia { border-bottom: 1px solid var(--border); }
  .doc-guia:first-of-type { margin-top: 8px; border-top: 1px solid var(--border); }
  .doc-guia summary { padding: 11px 0; font-size: 14px; font-weight: 600; cursor: pointer; }
  .doc-guia ol { max-width: 68ch; margin: 0 0 14px; padding-left: 24px; font-size: 13.5px; line-height: 1.55; color: var(--text-muted); }
  .doc-guia li { padding-left: 4px; margin-top: 3px; }
  .doc-guia li::marker { font-weight: 700; color: var(--doc-linha); }

  .doc [hidden] { display: none; }

  @media (max-width: 1000px) {
    .doc-corpo { grid-template-columns: minmax(0, 1fr); gap: 0; padding: 22px 20px 48px; }
    .doc-indice { display: none; }
    .doc-capa { padding: 30px 20px; }
  }
  @media (max-width: 760px) {
    .doc-capa-in { max-width: none; }
    .doc-caminho { grid-template-columns: minmax(0, 1fr); gap: 16px; margin-top: 28px; }
    .doc-caminho::before {
      right: auto; bottom: 30px; width: 3px; height: auto; animation-name: doc-costura-v;
      background: repeating-linear-gradient(180deg, var(--doc-linha) 0 10px, transparent 10px 17px);
    }
    .doc-caminho a { display: grid; grid-template-columns: 33px minmax(0, 1fr); column-gap: 14px; padding: 0; }
    .doc-caminho i { grid-row: span 2; }
    .doc-caminho b { margin-top: 0; }
    .doc-linha { grid-template-columns: minmax(0, 1fr); }
  }
  @keyframes doc-costura-v { from { clip-path: inset(0 0 100% 0); } to { clip-path: inset(0); } }
  @media (prefers-reduced-motion: reduce) {
    .doc-caminho::before { animation: none; }
    .content.doc { scroll-behavior: auto; }
  }
</style>

<div class="content doc">
  <section class="doc-capa">
    <div class="doc-capa-in">
      <h2>Do pedido à saída da fábrica</h2>
      <p>Siga o caminho do pedido ou procure uma tela. Cada etapa leva às telas que você usa nela.</p>
      <ol class="doc-caminho">
        <?php foreach (DOC_CAMINHO as $i => [$etapa, $resumo, $area]): ?>
          <li><a href="#area-<?= $h($area) ?>"><i><?= $i + 1 ?></i><b><?= $h($etapa) ?></b><span><?= $h($resumo) ?></span></a></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <div class="doc-corpo">
    <nav class="doc-indice" aria-label="Nesta página">
      <a href="#guias">Passo a passo</a>
      <hr>
      <?php foreach ($telasPorArea as $id => $area): ?>
        <a href="#area-<?= $h($id) ?>"><?= $h($area['nome']) ?></a>
      <?php endforeach; ?>
      <hr>
      <a href="#numeros">Dúvidas sobre os números</a>
      <a href="#termos">Termos</a>
    </nav>

    <main>
      <div class="doc-busca">
        <label for="docBusca">Procurar na documentação</label>
        <input type="search" id="docBusca" placeholder="Ex.: sacaria, NSU, agendar" autocomplete="off">
        <p id="docResultado" role="status"></p>
      </div>

      <section class="doc-secao" id="guias" data-doc-secao>
        <h3>Passo a passo</h3>
        <p>As tarefas mais comuns do dia a dia.</p>
        <?php foreach (DOC_GUIAS as [$titulo, $passos]): ?>
          <details class="doc-guia" data-doc>
            <summary><?= $h($titulo) ?></summary>
            <ol><?php foreach ($passos as $passo): ?><li><?= $h($passo) ?></li><?php endforeach; ?></ol>
          </details>
        <?php endforeach; ?>
      </section>

      <?php foreach ($telasPorArea as $id => $area): ?>
        <section class="doc-secao" id="area-<?= $h($id) ?>" data-doc-secao>
          <h3><?= $h($area['nome']) ?></h3>
          <p><?= $h($area['texto']) ?></p>
          <?php foreach ($area['telas'] as $tela): ?>
            <div class="doc-linha" data-doc>
              <?php if ($tela['acesso']): ?>
                <a href="<?= $h($tela['href']) ?>"><?= $h($tela['nome']) ?></a>
              <?php else: ?>
                <strong><?= $h($tela['nome']) ?><small>Sem acesso. Peça a um administrador.</small></strong>
              <?php endif; ?>
              <span><?= $h($tela['texto']) ?></span>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <section class="doc-secao" id="numeros" data-doc-secao>
        <h3>Dúvidas sobre os números</h3>
        <p>Por que duas telas podem mostrar valores diferentes.</p>
        <?php foreach (DOC_NUMEROS as [$pergunta, $resposta]): ?>
          <div class="doc-linha" data-doc><strong><?= $h($pergunta) ?></strong><span><?= $h($resposta) ?></span></div>
        <?php endforeach; ?>
      </section>

      <section class="doc-secao" id="termos" data-doc-secao>
        <h3>Termos</h3>
        <p>O vocabulário usado nas telas.</p>
        <?php foreach (DOC_TERMOS as [$termo, $sentido]): ?>
          <div class="doc-linha" data-doc><strong><?= $h($termo) ?></strong><span><?= $h($sentido) ?></span></div>
        <?php endforeach; ?>
      </section>
    </main>
  </div>
</div>

<script>
(function () {
  const campo = document.getElementById('docBusca');
  const resultado = document.getElementById('docResultado');
  const normaliza = t => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const itens = [...document.querySelectorAll('[data-doc]')].map(el => ({ el, texto: normaliza(el.textContent) }));
  const secoes = [...document.querySelectorAll('[data-doc-secao]')];

  function filtrar() {
    const termo = normaliza(campo.value.trim());
    let achados = 0;
    itens.forEach(({ el, texto }) => {
      const passa = termo === '' || texto.includes(termo);
      el.hidden = !passa;
      if (passa) achados++;
      if (el.tagName === 'DETAILS') el.open = termo !== '' && passa;
    });
    secoes.forEach(s => { s.hidden = !s.querySelector('[data-doc]:not([hidden])'); });

    resultado.textContent = '';
    if (termo === '') return;
    if (achados) {
      resultado.textContent = achados === 1 ? '1 resultado' : achados + ' resultados';
      return;
    }
    resultado.append(`Nada encontrado para "${campo.value.trim()}". Tente outra palavra ou pergunte ao `);
    const link = document.createElement('a');
    link.href = '/assistente';
    link.textContent = 'Assistente IA';
    resultado.append(link, '.');
  }

  campo.addEventListener('input', filtrar);
  campo.addEventListener('keydown', e => {
    if (e.key === 'Escape') { campo.value = ''; filtrar(); }
  });
}());
</script>
