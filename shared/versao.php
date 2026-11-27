<?php
// ══════════════════════════════════════════════════════════════
//  DOMBAG — Versão publicada ("Atualizado em ...")
//  O deploy (.cpanel.yml) grava config/versao.txt com "<commit> <timestamp>".
//  Login e menu mostram a data e o commit, para conferir se a atualização
//  entrou. Sem o arquivo (localhost) não mostra nada.
// ══════════════════════════════════════════════════════════════

if (!function_exists('dombagVersao')) {
    // ['commit' => '1c88da2', 'quando' => '27/11/2026 09:00'] ou null
    function dombagVersao(): ?array
    {
        static $versao = false;
        if ($versao !== false) return $versao;

        $versao = null;
        $linha  = @file_get_contents(dirname(__DIR__) . '/config/versao.txt');
        if (is_string($linha) && preg_match('/^([0-9a-f]{7,40})\s+(\d{9,11})\s*$/', $linha, $m)) {
            $quando = (new DateTimeImmutable('@' . $m[2]))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
            $versao = ['commit' => substr($m[1], 0, 7), 'quando' => $quando->format('d/m/Y H:i')];
        }
        return $versao;
    }

    // Texto pronto, já escapado: "Atualizado em 27/11/2026 09:00 · 1c88da2"
    function dombagVersaoTexto(): string
    {
        $v = dombagVersao();
        return $v ? 'Atualizado em ' . $v['quando'] . ' &middot; ' . htmlspecialchars($v['commit']) : '';
    }
}
