<?php
// Barrinha de uso: mensagens/hora, relatórios/dia e cota do provedor (admin).

$pdo = dbPDO();
limparDadosTeste($pdo);
echo "\nUso disponível\n";

teste('uso: conta mensagens da última hora e relatórios do dia, por usuário', function () use ($pdo) {
    for ($i = 0; $i < 3; $i++) {
        chatRegistrarUso($pdo, UID_A, '203.0.113.9', 'msg');
    }
    chatRegistrarUso($pdo, UID_A, '203.0.113.9', 'relatorio');
    chatRegistrarUso($pdo, UID_B, '203.0.113.9', 'msg');                 // outro usuário não conta
    // mensagem de mais de 1 hora atrás não conta
    $pdo->exec('INSERT INTO CHAT_USO (USU_CODIGO, CHU_IP, CHU_TIPO, CHU_CRIADO) VALUES (' . UID_A . ", '203.0.113.9', 'msg', NOW() - INTERVAL 2 HOUR)");

    $u = chatUso($pdo, ctxTeste());
    igual(3, $u['mensagens']['usadas']);
    igual(chatConfig()['msg_hora'], $u['mensagens']['limite']);
    igual(null, $u['mensagens']['libera_em_min'], 'só informa quando esgota');
    igual(1, $u['relatorios']['usados']);
    igual(null, $u['provedor'], 'usuário comum não vê a cota da empresa');
});

teste('uso: no limite, informa em quantos minutos libera', function () use ($pdo) {
    $lim = chatConfig()['msg_hora'];
    $pdo->exec('DELETE FROM CHAT_USO WHERE USU_CODIGO = ' . UID_B);
    // a mais antiga foi há 50 min → libera em ~10 min
    $pdo->exec('INSERT INTO CHAT_USO (USU_CODIGO, CHU_IP, CHU_TIPO, CHU_CRIADO) VALUES (' . UID_B . ", '203.0.113.9', 'msg', NOW() - INTERVAL 50 MINUTE)");
    for ($i = 1; $i < $lim; $i++) {
        chatRegistrarUso($pdo, UID_B, '203.0.113.9', 'msg');
    }
    $u = chatUso($pdo, ctxTeste(UID_B));
    igual($lim, $u['mensagens']['usadas']);
    confere($u['mensagens']['libera_em_min'] >= 9 && $u['mensagens']['libera_em_min'] <= 11, 'libera em ' . $u['mensagens']['libera_em_min']);
});

teste('uso: admin vê requisições ao Gemini da empresa desde a meia-noite do Pacífico', function () use ($pdo) {
    if (chatConfig()['provedor'] !== 'gemini') {
        echo "      (provedor atual não é Gemini — conferindo só que vem vazio)\n";
        igual(null, chatUso($pdo, ctxTeste(UID_A, ['admin' => true]))['provedor']);
        return;
    }
    $antes = chatUso($pdo, ctxTeste(UID_A, ['admin' => true]))['provedor']['usadas'];
    chatLog($pdo, ['uid' => UID_B, 'tipo' => 'api', 'modelo' => 'gemini-3.5-flash-lite', 'status' => 'stop']);
    chatLog($pdo, ['uid' => UID_B, 'tipo' => 'api', 'modelo' => 'gemini-3.5-flash-lite', 'status' => 'limite_provedor']);
    chatLog($pdo, ['uid' => UID_B, 'tipo' => 'ferramenta', 'ferramenta' => 'listar_pedidos']);     // não conta
    chatLog($pdo, ['uid' => UID_B, 'tipo' => 'api', 'modelo' => 'claude-sonnet-5-5', 'status' => 'end_turn']); // não conta
    $p = chatUso($pdo, ctxTeste(UID_A, ['admin' => true]))['provedor'];
    igual($antes + 2, $p['usadas']);
    confere($p['esgotou'] !== null, 'deveria informar que a cota esgotou');
    confere((bool) preg_match('/^\d{2}h\d{2}$/', $p['renova_as']), 'horário de renovação: ' . $p['renova_as']);
});

limparDadosTeste($pdo);
