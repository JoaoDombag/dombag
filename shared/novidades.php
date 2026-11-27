<?php
// ══════════════════════════════════════════════════════════════
//  DOMBAG — Janela "O que mudou"
//  Incluída no fim de shared/sidebar.php, então aparece na primeira tela que
//  a pessoa abrir depois de uma atualização. Conteúdo: config/novidades.php.
//  Fechar (botão, Esc, clique fora ou um link da lista) grava a versão vista
//  em USUARIOS.USU_NOVIDADES_VISTA pela /api/novidades.
//  Usa $_uri, $_script e sb_can() de sidebar.php.
// ══════════════════════════════════════════════════════════════

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/novidades.php';

$__nv_ultima = novidadesUltima();
$__nv_uri    = $_uri ?? strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$__nv_script = $_script ?? str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

// Painéis de TV ficam sem ninguém para fechar a janela
$__nv_tela_tv = (bool) preg_match('#^/pcp/(bi|bi-individual|tv)$#', $__nv_uri)
             || (bool) preg_match('#/modules/pcp/(bi_|agendamento_tv)#', $__nv_script);

if (empty($_SESSION['usu_codigo']) || $__nv_ultima === '' || $__nv_tela_tv) {
    return;
}

// A versão vista fica na sessão para não consultar o banco a cada tela
if (!array_key_exists('novidades_vista', $_SESSION)) {
    try {
        $__nv_st = dbPDO()->prepare('SELECT USU_NOVIDADES_VISTA FROM USUARIOS WHERE USU_CODIGO = :c');
        $__nv_st->execute([':c' => (int) $_SESSION['usu_codigo']]);
        $__nv_val = $__nv_st->fetchColumn();
        $_SESSION['novidades_vista'] = is_string($__nv_val) ? $__nv_val : '';
    } catch (Throwable) {
        return; // coluna ainda não criada: tenta de novo na próxima tela
    }
}
$__nv_vista = (string) $_SESSION['novidades_vista'];

if (strcmp($__nv_vista, $__nv_ultima) >= 0) {
    return;
}

$__nv_pode  = function_exists('sb_can') ? 'sb_can' : static fn (): bool => false;
$__nv_lista = novidadesPendentes($__nv_vista, $__nv_pode);
if (!$__nv_lista) {
    // Nada que esta pessoa possa ver: não procura de novo nesta sessão
    $_SESSION['novidades_vista'] = $__nv_ultima;
    return;
}

if (empty($_SESSION['novidades_csrf'])) {
    $_SESSION['novidades_csrf'] = bin2hex(random_bytes(32));
}
$__nv_h = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$__nv_varias = count($__nv_lista) > 1;
?>
<style>
  .nv-fundo {
    position: fixed; inset: 0; z-index: 9500; display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(0,0,0,.6); backdrop-filter: blur(3px);
    animation: nv-entra .2s ease-out both;
  }
  .nv-janela {
    display: flex; flex-direction: column; width: 100%; max-width: 560px; max-height: calc(100vh - 32px);
    background: var(--modal-bg, #152845); color: var(--text-primary); border: 1px solid var(--border);
    border-top: 3px solid var(--blue-light); border-radius: 14px; box-shadow: 0 24px 64px rgba(0,0,0,.45);
    animation: nv-sobe .25s cubic-bezier(.4,0,.2,1) both;
  }
  .nv-topo { padding: 22px 24px 14px; border-bottom: 1px solid var(--border); }
  .nv-topo small { font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--blue-light); }
  .nv-topo h2 { margin-top: 4px; font-size: 20px; font-weight: 700; letter-spacing: -.2px; }
  .nv-topo p { margin-top: 4px; font-size: 13px; color: var(--text-muted); }
  .nv-lista { flex: 1 1 auto; overflow-y: auto; padding: 6px 24px 8px; -webkit-overflow-scrolling: touch; }
  .nv-versao + .nv-versao { margin-top: 6px; border-top: 1px dashed var(--border); }
  .nv-versao h3 { padding: 14px 0 4px; font-size: 13px; font-weight: 600; color: var(--text-muted); }
  .nv-versao h3 b { color: var(--text-primary); }
  .nv-item { display: grid; grid-template-columns: 82px minmax(0, 1fr); gap: 10px; padding: 9px 0; font-size: 13.5px; line-height: 1.5; }
  .nv-tipo {
    align-self: start; justify-self: start; padding: 2px 8px; border-radius: 20px;
    font-size: 11px; font-weight: 700; letter-spacing: .3px; white-space: nowrap;
  }
  .nv-tipo-novo     { background: rgba(0,201,167,.14);  color: var(--teal); }
  .nv-tipo-melhoria { background: rgba(45,106,255,.15); color: var(--blue-light); }
  .nv-tipo-correcao { background: rgba(245,158,11,.15); color: var(--amber); }
  .nv-item a { margin-left: 4px; color: var(--blue-light); font-weight: 600; white-space: nowrap; text-underline-offset: 3px; }
  .nv-acoes {
    display: flex; gap: 10px; justify-content: flex-end; align-items: center;
    padding: 14px 24px 18px; border-top: 1px solid var(--border);
  }
  .nv-acoes a { margin-right: auto; font-size: 13px; color: var(--text-muted); text-underline-offset: 3px; }
  .nv-acoes a:hover { color: var(--text-primary); }
  .nv-acoes button {
    min-height: 40px; padding: 0 22px; border: 0; border-radius: 9px; cursor: pointer;
    background: var(--blue-accent); color: #fff; font-size: 14px; font-weight: 600; font-family: inherit;
  }
  .nv-acoes button:hover, .nv-acoes button:focus-visible { background: var(--blue-light); }
  .nv-janela :focus-visible { outline: 2px solid var(--blue-light); outline-offset: 2px; }
  @keyframes nv-entra { from { opacity: 0; } }
  @keyframes nv-sobe  { from { opacity: 0; transform: translateY(14px) scale(.98); } }
  @media (max-width: 520px) {
    .nv-fundo { align-items: flex-end; padding: 0; }
    .nv-janela { max-width: none; max-height: 88vh; border-radius: 14px 14px 0 0; }
    .nv-topo, .nv-lista, .nv-acoes { padding-left: 18px; padding-right: 18px; }
    .nv-item { grid-template-columns: minmax(0, 1fr); gap: 4px; }
    .nv-acoes { flex-direction: column-reverse; align-items: stretch; }
    .nv-acoes a { margin: 0; text-align: center; padding: 6px 0; }
    .nv-acoes button { min-height: 46px; }
  }
  @media (prefers-reduced-motion: reduce) { .nv-fundo, .nv-janela { animation: none; } }
</style>

<div class="nv-fundo" id="nvFundo"
     data-csrf="<?= $__nv_h($_SESSION['novidades_csrf']) ?>"
     data-versao="<?= $__nv_h($__nv_lista[0]['versao']) ?>">
  <div class="nv-janela" role="dialog" aria-modal="true" aria-labelledby="nvTitulo">
    <div class="nv-topo">
      <small>O sistema foi atualizado</small>
      <h2 id="nvTitulo"><?= $__nv_varias ? 'O que mudou desde a sua última visita' : $__nv_h($__nv_lista[0]['titulo'] ?: 'O que mudou') ?></h2>
      <p><?= $__nv_varias ? count($__nv_lista) . ' atualizações' : 'Atualização de ' . $__nv_h($__nv_lista[0]['data']) ?></p>
    </div>

    <div class="nv-lista">
      <?php foreach ($__nv_lista as $__nv_v): ?>
        <section class="nv-versao">
          <?php if ($__nv_varias): ?>
            <h3><b><?= $__nv_h($__nv_v['data']) ?></b><?= $__nv_v['titulo'] !== '' ? ' · ' . $__nv_h($__nv_v['titulo']) : '' ?></h3>
          <?php endif; ?>
          <?php foreach ($__nv_v['itens'] as $__nv_i): ?>
            <div class="nv-item">
              <span class="nv-tipo nv-tipo-<?= $__nv_h($__nv_i['tipo']) ?>"><?= $__nv_h($__nv_i['rotulo']) ?></span>
              <span><?= $__nv_h($__nv_i['texto']) ?><?php if ($__nv_i['href'] !== null && $__nv_i['href'] !== $__nv_uri): ?><a href="<?= $__nv_h($__nv_i['href']) ?>" data-nv-fecha>Abrir</a><?php endif; ?></span>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="nv-acoes">
      <a href="/documentacao#novidades" data-nv-fecha>Ver todas as novidades</a>
      <button type="button" id="nvOk">Entendi</button>
    </div>
  </div>
</div>

<script>
(function () {
  var fundo = document.getElementById('nvFundo');
  if (!fundo) return;
  var anterior = document.activeElement;
  var gravado = false;

  function gravar() {
    if (gravado) return;
    gravado = true;
    var dados = new URLSearchParams({ csrf: fundo.dataset.csrf, versao: fundo.dataset.versao });
    // keepalive: continua enviando mesmo se a pessoa sair da tela por um link da lista
    fetch('/api/novidades', { method: 'POST', body: dados, credentials: 'same-origin', keepalive: true })
      .catch(function () {});
  }
  function fechar() {
    gravar();
    fundo.remove();
    document.removeEventListener('keydown', teclas, true);
    if (anterior && anterior.focus) anterior.focus();
  }
  function teclas(e) {
    if (e.key === 'Escape') { e.preventDefault(); fechar(); return; }
    if (e.key !== 'Tab') return;
    // Mantém o Tab dentro da janela
    var focaveis = fundo.querySelectorAll('a[href], button');
    var primeiro = focaveis[0], ultimo = focaveis[focaveis.length - 1];
    if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
    else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
  }

  document.getElementById('nvOk').addEventListener('click', fechar);
  fundo.addEventListener('click', function (e) { if (e.target === fundo) fechar(); });
  fundo.querySelectorAll('[data-nv-fecha]').forEach(function (a) { a.addEventListener('click', gravar); });
  document.addEventListener('keydown', teclas, true);
  document.getElementById('nvOk').focus();
}());
</script>
