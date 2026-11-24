<?php
// Manifesto do aplicativo instalável. O ícone vem de ?icone= (ver shared/app_icone.php).
// O "id" é fixo: trocar o ícone atualiza o mesmo app instalado, não cria outro.
require_once __DIR__ . '/shared/app_icone.php';

$nome = appIconeValido($_GET['icone'] ?? null);
$img  = '/public/img/app/' . $nome;

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');

echo json_encode([
    'id'               => '/',
    'name'             => 'Sistema Dombag',
    'short_name'       => 'Dombag',
    'description'      => 'Pedidos, PCP e produção da Dom Bag.',
    'lang'             => 'pt-BR',
    'start_url'        => '/dashboard',
    'scope'            => '/',
    'display'          => 'standalone',
    'background_color' => APP_ICONES[$nome][1],
    'theme_color'      => '#112240',
    'icons'            => [
        ['src' => "$img-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => "$img-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        // "any" tem cantos arredondados (Windows, macOS, aba); "maskable" é o quadrado
        // cheio, porque o Android aplica a própria máscara.
        ['src' => "$img-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
