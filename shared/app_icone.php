<?php
// ══════════════════════════════════════════════════════════════
//  DOMBAG — Aplicativo instalável (PWA) e ícone escolhido pelo usuário
//  Cada página chama appHeadTags() dentro do <head>. A escolha fica em
//  USUARIOS.USU_ICONE_APP e num cookie, para a tela de login também usar.
//  Ícones em public/img/app/{nome}-{512|192|180|48}.png.
// ══════════════════════════════════════════════════════════════

const APP_ICONES = [
    'classico' => ['Clássico', '#ffffff'],
    'azul'     => ['Azul', '#0033ff'],
    'neon'     => ['Neon', '#020b2a'],
    'oceano'   => ['Oceano', '#0b4f86'],
];
const APP_ICONE_PADRAO = 'classico';
const APP_ICONE_COOKIE = 'dombag_icone';

if (!function_exists('appIconeValido')) {
    function appIconeValido(?string $nome): string
    {
        return isset(APP_ICONES[$nome ?? '']) ? $nome : APP_ICONE_PADRAO;
    }

    // Logado: o banco manda (guardado na sessão). Sem login: o cookie.
    function appIconeAtual(): string
    {
        if (isset($_SESSION['app_icone'])) return appIconeValido($_SESSION['app_icone']);

        $cookie = $_COOKIE[APP_ICONE_COOKIE] ?? null;
        if (!empty($_SESSION['usu_codigo']) && function_exists('dbPDO')) {
            try {
                $st = dbPDO()->prepare('SELECT USU_ICONE_APP FROM USUARIOS WHERE USU_CODIGO = :c');
                $st->execute([':c' => (int) $_SESSION['usu_codigo']]);
                $banco = $st->fetchColumn();
                $_SESSION['app_icone'] = appIconeValido($banco ?: $cookie);
                return $_SESSION['app_icone'];
            } catch (Throwable) {}
        }
        return appIconeValido($cookie);
    }

    function appIconeCookie(string $nome): void
    {
        if (headers_sent()) return;
        setcookie(APP_ICONE_COOKIE, $nome, [
            'expires'  => time() + 365 * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    function appHeadTags(): void
    {
        $nome = appIconeAtual();
        // Barra de título acompanha o tema do sistema (topbar em unified_admin.css)
        $cor  = (function_exists('dombagTema') && dombagTema() === 'claro') ? '#ffffff' : '#112240';
        $img  = '/public/img/app/' . $nome;
        echo '<link rel="icon" href="' . $img . '-48.png" type="image/png">' . "\n"
           . '<link rel="apple-touch-icon" href="' . $img . '-180.png">' . "\n"
           . '<link rel="manifest" href="/manifest.php?icone=' . $nome . '">' . "\n"
           . '<meta name="theme-color" content="' . $cor . '">' . "\n"
           . '<script>if("serviceWorker"in navigator){addEventListener("load",function(){navigator.serviceWorker.register("/sw.js").catch(function(){})})}</script>' . "\n";
    }
}
