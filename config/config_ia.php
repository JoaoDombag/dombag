<?php

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — Configurações do Assistente IA editáveis em Parâmetros do Sistema
//
//  Ordem de leitura de cada valor (dombagConfig):
//    1. variável de ambiente real do servidor (não editável pela tela)
//    2. tela Parâmetros → aba "Assistente IA" (tabela PARAMETROS, chave "ia.<NOME>")
//    3. arquivo dombag.env.php
//    4. padrão do código
//
//  Segredos (chaves de API, senha) são gravados CRIPTOGRAFADOS (AES-256-GCM).
//  A chave de criptografia fica fora do banco: DOMBAG_CHAVE_APP no dombag.env.php
//  ou, se não houver, o arquivo dombag.chave criado automaticamente ao lado dele.
//
//  As credenciais dos BANCOS (MYSQL*, PG_*) não entram aqui: o sistema precisa
//  delas para conectar ao banco antes de conseguir ler esta configuração.
// ══════════════════════════════════════════════════════════════════════════════

// Campos da aba. tipo: texto | segredo | inteiro | opcao | modelo
const DOMBAG_CONFIG_IA = [
    'CHAT_PROVEDOR'           => ['grupo' => 'provedor', 'rotulo' => 'Provedor da IA', 'tipo' => 'opcao',
                                  'opcoes' => ['gemini' => 'Google Gemini (tem plano gratuito)', 'anthropic' => 'Anthropic Claude (pago)'], 'padrao' => ''],

    'GEMINI_API_KEY'          => ['grupo' => 'gemini', 'rotulo' => 'Chave de API do Gemini', 'tipo' => 'segredo',
                                  'ajuda' => 'aistudio.google.com → Get API key'],
    'GEMINI_MODEL'            => ['grupo' => 'gemini', 'rotulo' => 'Modelo principal', 'tipo' => 'modelo', 'padrao' => 'gemini-3.6-flash',
                                  'sugestoes' => ['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-3.8-flash']],
    'GEMINI_MODEL_RESERVA'    => ['grupo' => 'gemini', 'rotulo' => 'Modelo reserva', 'tipo' => 'modelo', 'padrao' => 'gemini-3.5-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite,gemini-3.8-flash',
                                  'ajuda' => 'Tentados em ordem se o principal estiver sobrecarregado, lento ou sem cota. Vírgula separa vários.', 'lista' => true,
                                  'sugestoes' => ['gemini-3.5-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite,gemini-3.8-flash']],
    'GEMINI_THINKING'         => ['grupo' => 'gemini', 'rotulo' => 'Raciocínio', 'tipo' => 'opcao', 'padrao' => 'low',
                                  'opcoes' => ['low' => 'Baixo (mais rápido)', 'medium' => 'Médio', 'high' => 'Alto (mais lento)']],
    'GEMINI_ESPERA_INICIO'    => ['grupo' => 'gemini', 'rotulo' => 'Espera máxima para começar a responder (segundos)', 'tipo' => 'inteiro',
                                  'min' => 5, 'max' => 120, 'padrao' => '20',
                                  'ajuda' => 'Passado esse tempo sem resposta, o sistema tenta o próximo modelo.'],
    'GEMINI_LIMITE_DIA'       => ['grupo' => 'gemini', 'rotulo' => 'Limite diário de requisições (plano gratuito)', 'tipo' => 'inteiro',
                                  'min' => 0, 'max' => 10000000, 'padrao' => '0',
                                  'ajuda' => 'Copie de aistudio.google.com/rate-limit. 0 = a barrinha mostra só o total usado.'],

    'ANTHROPIC_API_KEY'       => ['grupo' => 'claude', 'rotulo' => 'Chave de API do Claude', 'tipo' => 'segredo',
                                  'ajuda' => 'platform.claude.com → API Keys'],
    'CLAUDE_MODEL'            => ['grupo' => 'claude', 'rotulo' => 'Modelo', 'tipo' => 'modelo', 'padrao' => 'claude-sonnet-5-5',
                                  'sugestoes' => ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5']],
    'CLAUDE_EFFORT'           => ['grupo' => 'claude', 'rotulo' => 'Esforço de raciocínio', 'tipo' => 'opcao', 'padrao' => 'low',
                                  'opcoes' => ['low' => 'Baixo (mais barato)', 'medium' => 'Médio', 'high' => 'Alto (mais caro)']],

    'CHAT_MSG_POR_HORA'       => ['grupo' => 'limites', 'rotulo' => 'Mensagens por usuário por hora', 'tipo' => 'inteiro', 'min' => 1, 'max' => 1000, 'padrao' => '20'],
    'CHAT_MSG_POR_HORA_IP'    => ['grupo' => 'limites', 'rotulo' => 'Mensagens por IP por hora', 'tipo' => 'inteiro', 'min' => 1, 'max' => 10000, 'padrao' => '120',
                                  'ajuda' => 'A fábrica inteira costuma sair pelo mesmo IP.'],
    'CHAT_RELATORIOS_POR_DIA' => ['grupo' => 'limites', 'rotulo' => 'Relatórios por usuário por dia', 'tipo' => 'inteiro', 'min' => 0, 'max' => 1000, 'padrao' => '5'],
    'CHAT_MAX_CHARS'          => ['grupo' => 'limites', 'rotulo' => 'Tamanho máximo de uma mensagem (caracteres)', 'tipo' => 'inteiro', 'min' => 100, 'max' => 10000, 'padrao' => '2000'],
    'CHAT_MAX_TURNOS'         => ['grupo' => 'limites', 'rotulo' => 'Perguntas por conversa', 'tipo' => 'inteiro', 'min' => 1, 'max' => 200, 'padrao' => '20'],

    'CHAT_PG_USER'            => ['grupo' => 'erp', 'rotulo' => 'Usuário somente leitura do ERP', 'tipo' => 'texto',
                                  'ajuda' => 'Ex.: dombag_ia, criado pelo suporte da Yzidro. Vazio = usa o usuário padrão do ERP.'],
    'CHAT_PG_PASS'            => ['grupo' => 'erp', 'rotulo' => 'Senha desse usuário', 'tipo' => 'segredo'],
];

const DOMBAG_CONFIG_IA_GRUPOS = [
    'provedor' => 'Provedor',
    'gemini'   => 'Google Gemini',
    'claude'   => 'Anthropic Claude',
    'limites'  => 'Limites de uso',
    'erp'      => 'Acesso ao ERP pelo chat',
];

// ── Leitura em camadas ───────────────────────────────────────────────────────
function dombagConfig(string $chave, ?string $padrao = null): ?string
{
    $v = getenv($chave);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset(DOMBAG_CONFIG_IA[$chave])) {
        $tela = dombagConfigTela();
        if (isset($tela[$chave]) && $tela[$chave] !== '') {
            return $tela[$chave];
        }
    }
    return dombagEnv($chave, $padrao);
}

// Valores salvos pela tela (já descriptografados). Lidos uma vez por requisição.
function dombagConfigTela(bool $recarregar = false): array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) {
        return $cache;
    }
    $cache = [];
    try {
        $st = dbPDO()->query("SELECT PAR_CHAVE, PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE LIKE 'ia.%'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nome = substr($r['PAR_CHAVE'], 3);
            if (!isset(DOMBAG_CONFIG_IA[$nome])) {
                continue;
            }
            $valor = (string) $r['PAR_VALOR'];
            if (DOMBAG_CONFIG_IA[$nome]['tipo'] === 'segredo' && $valor !== '') {
                $valor = dombagDecifrar($valor) ?? '';
            }
            $cache[$nome] = $valor;
        }
    } catch (Throwable $e) {
        error_log('[CONFIG IA] ' . $e->getMessage());   // banco fora: segue com arquivo/padrões
    }
    return $cache;
}

// De onde vem o valor atual (para a tela mostrar a origem).
function dombagConfigOrigem(string $chave): string
{
    $v = getenv($chave);
    if ($v !== false && $v !== '') {
        return 'ambiente';
    }
    $tela = dombagConfigTela();
    if (isset($tela[$chave]) && $tela[$chave] !== '') {
        return 'tela';
    }
    return dombagEnv($chave) !== null ? 'arquivo' : 'padrao';
}

function dombagConfigSalvar(string $chave, string $valor): void
{
    if (!isset(DOMBAG_CONFIG_IA[$chave])) {
        throw new InvalidArgumentException("configuração desconhecida: {$chave}");
    }
    if (DOMBAG_CONFIG_IA[$chave]['tipo'] === 'segredo' && $valor !== '') {
        $valor = dombagCifrar($valor);
    }
    dbPDO()->prepare('INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (?, ?) ON DUPLICATE KEY UPDATE PAR_VALOR = VALUES(PAR_VALOR)')
        ->execute(['ia.' . $chave, $valor]);
    dombagConfigTela(true);
}

// ── Criptografia dos segredos (AES-256-GCM) ──────────────────────────────────
function dombagChaveApp(): string
{
    static $chave = null;
    if ($chave !== null) {
        return $chave;
    }
    $env = dombagEnv('DOMBAG_CHAVE_APP');
    if ($env) {
        $bin = base64_decode($env, true);
        if ($bin !== false && strlen($bin) === 32) {
            return $chave = $bin;
        }
    }
    // Arquivo ao lado do dombag.env.php (fora da raiz pública e do git).
    $arq = getenv('DOMBAG_CHAVE_FILE') ?: dirname(__DIR__, 2) . '/dombag.chave';
    if (is_file($arq)) {
        $bin = base64_decode(trim((string) file_get_contents($arq)), true);
        if ($bin !== false && strlen($bin) === 32) {
            return $chave = $bin;
        }
    }
    $nova = random_bytes(32);
    if (@file_put_contents($arq, base64_encode($nova), LOCK_EX) === false) {
        throw new RuntimeException('não foi possível criar o arquivo da chave de criptografia (' . $arq . '). '
            . 'Dê permissão de escrita nessa pasta ou defina DOMBAG_CHAVE_APP no dombag.env.php.');
    }
    @chmod($arq, 0600);
    return $chave = $nova;
}

function dombagCifrar(string $texto): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $c   = openssl_encrypt($texto, 'aes-256-gcm', dombagChaveApp(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:v1:' . base64_encode($iv . $tag . $c);
}

function dombagDecifrar(string $valor): ?string
{
    if (!str_starts_with($valor, 'enc:v1:')) {
        return null;
    }
    $bin = base64_decode(substr($valor, 7), true);
    if ($bin === false || strlen($bin) < 29) {
        return null;
    }
    try {
        $t = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', dombagChaveApp(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    } catch (Throwable) {
        return null;
    }
    return $t === false ? null : $t;
}

// Validação de um valor vindo da tela. Retorna [valorNormalizado, erro|null].
function dombagConfigValidar(string $chave, string $valor): array
{
    $d = DOMBAG_CONFIG_IA[$chave];
    $valor = trim($valor);
    if ($valor === '') {
        return ['', null];                          // vazio = volta ao arquivo/padrão
    }
    switch ($d['tipo']) {
        case 'inteiro':
            if (!preg_match('/^\d+$/', $valor) || (int) $valor < $d['min'] || (int) $valor > $d['max']) {
                return [null, "{$d['rotulo']}: use um número entre {$d['min']} e {$d['max']}."];
            }
            return [(string) (int) $valor, null];
        case 'opcao':
            return isset($d['opcoes'][$valor]) ? [$valor, null] : [null, "{$d['rotulo']}: opção inválida."];
        case 'modelo':
            $partes = !empty($d['lista']) ? array_map('trim', explode(',', $valor)) : [$valor];
            foreach ($partes as $p) {
                if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,60}$/', $p)) {
                    return [null, "{$d['rotulo']}: nome de modelo inválido ({$p})."];
                }
            }
            return [implode(',', $partes), null];
        case 'segredo':
            if (strlen($valor) > 300 || preg_match('/\s/', $valor)) {
                return [null, "{$d['rotulo']}: valor inválido."];
            }
            return [$valor, null];
        default:
            if (!preg_match('/^[A-Za-z0-9_.\-]{1,63}$/', $valor)) {
                return [null, "{$d['rotulo']}: use apenas letras, números, ponto, hífen e sublinhado."];
            }
            return [$valor, null];
    }
}

// "••••abcd" — nunca devolve o segredo inteiro para o navegador.
// Segredos salvos pela tela que ESTE servidor não consegue descriptografar —
// foram gravados com outra chave de criptografia (ex.: salvos no XAMPP local,
// que usa o mesmo banco da produção mas outro dombag.chave). dombagConfigTela
// trata esses valores como vazios; a tela usa esta lista para avisar.
function dombagConfigIlegiveis(): array
{
    $ilegiveis = [];
    try {
        $st = dbPDO()->query("SELECT PAR_CHAVE, PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE LIKE 'ia.%'");
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $valor) {
            $nome = substr($k, 3);
            if (($valor ?? '') !== '' && (DOMBAG_CONFIG_IA[$nome]['tipo'] ?? '') === 'segredo' && dombagDecifrar($valor) === null) {
                $ilegiveis[] = $nome;
            }
        }
    } catch (Throwable $e) {
        error_log('[CONFIG IA] ' . $e->getMessage());
    }
    return $ilegiveis;
}

function dombagMascarar(?string $segredo): string
{
    if ($segredo === null || $segredo === '') {
        return '';
    }
    return '••••••••' . substr($segredo, -4);
}
