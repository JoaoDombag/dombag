<?php
// Configurações do Assistente IA na tela de Parâmetros: prioridade, criptografia
// e validação. Faz backup e restaura qualquer valor real salvo na tela.

$pdo = dbPDO();
echo "\nConfiguração pela tela de Parâmetros\n";

$backup = $pdo->query("SELECT PAR_CHAVE, PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE IN ('ia.CHAT_MAX_TURNOS','ia.GEMINI_API_KEY','ia.GEMINI_MODEL')")->fetchAll(PDO::FETCH_KEY_PAIR);
$restaurar = function () use ($pdo, $backup) {
    $pdo->exec("DELETE FROM PARAMETROS WHERE PAR_CHAVE IN ('ia.CHAT_MAX_TURNOS','ia.GEMINI_API_KEY','ia.GEMINI_MODEL')");
    foreach ($backup as $k => $v) {
        $pdo->prepare('INSERT INTO PARAMETROS (PAR_CHAVE, PAR_VALOR) VALUES (?, ?)')->execute([$k, $v]);
    }
    dombagConfigTela(true);
};

teste('config: valor da tela tem prioridade sobre o arquivo; vazio volta ao arquivo/padrão', function () use ($restaurar) {
    $arquivo = dombagEnv('GEMINI_MODEL');
    dombagConfigSalvar('GEMINI_MODEL', 'gemini-teste-tela');
    igual('gemini-teste-tela', dombagConfig('GEMINI_MODEL'));
    igual('tela', dombagConfigOrigem('GEMINI_MODEL'));
    dombagConfigSalvar('GEMINI_MODEL', '');
    igual($arquivo, dombagConfig('GEMINI_MODEL'), 'em branco volta ao arquivo');
    dombagConfigSalvar('CHAT_MAX_TURNOS', '7');
    igual('7', dombagConfig('CHAT_MAX_TURNOS'));
    $restaurar();
});

teste('config: chaves de API ficam criptografadas no banco e nunca voltam inteiras', function () use ($pdo, $restaurar) {
    $segredo = 'AIzaTESTE-' . bin2hex(random_bytes(8));
    dombagConfigSalvar('GEMINI_API_KEY', $segredo);
    $bruto = $pdo->query("SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = 'ia.GEMINI_API_KEY'")->fetchColumn();
    confere(str_starts_with($bruto, 'enc:v1:'), 'deveria estar criptografado');
    confere(!str_contains($bruto, $segredo) && !str_contains(base64_decode(substr($bruto, 7)), $segredo), 'segredo em texto puro no banco');
    igual($segredo, dombagConfig('GEMINI_API_KEY'), 'leitura descriptografa');
    igual('••••••••' . substr($segredo, -4), dombagMascarar($segredo));
    // valor adulterado no banco não é aceito
    $pdo->prepare("UPDATE PARAMETROS SET PAR_VALOR = ? WHERE PAR_CHAVE = 'ia.GEMINI_API_KEY'")->execute([substr($bruto, 0, -4) . 'AAAA']);
    dombagConfigTela(true);
    igual(null, dombagDecifrar(substr($bruto, 0, -4) . 'AAAA'));
    $restaurar();
});

teste('config: validação dos campos da tela', function () {
    $invalidos = [
        ['CHAT_PROVEDOR', 'openai'], ['GEMINI_MODEL', 'gemini 3; DROP'], ['GEMINI_MODEL_RESERVA', 'ok-1,../x'],
        ['CHAT_MSG_POR_HORA', '0'], ['CHAT_MSG_POR_HORA', 'abc'], ['CHAT_MAX_CHARS', '50'], ['GEMINI_LIMITE_DIA', '-1'],
        ['GEMINI_API_KEY', 'tem espaço'], ['CHAT_PG_USER', "x'; drop"], ['CLAUDE_EFFORT', 'max'],
    ];
    foreach ($invalidos as [$k, $v]) {
        [, $erro] = dombagConfigValidar($k, $v);
        confere($erro !== null, "aceitou {$k}={$v}");
    }
    igual(['gemini-3.8-flash,gemini-3.1-flash-lite', null], dombagConfigValidar('GEMINI_MODEL_RESERVA', ' gemini-3.8-flash , gemini-3.1-flash-lite '));
    igual(['25', null], dombagConfigValidar('CHAT_MSG_POR_HORA', '25'));
    igual(['', null], dombagConfigValidar('CHAT_MSG_POR_HORA', ''), 'vazio é permitido (volta ao padrão)');
    try {
        dombagConfigSalvar('MYSQLPASSWORD', 'x');
        confere(false, 'credencial de banco não pode ser salva pela tela');
    } catch (InvalidArgumentException) {
        confere(true);
    }
});
