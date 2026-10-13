<?php
// ══════════════════════════════════════════════════════════════════════════════
//  MODELO — copie para UM NÍVEL ACIMA da raiz do site, com o nome dombag.env.php
//    produção (HostGator): /home/<usuario>/dombag.env.php   (fora de sistemadombag.com)
//    local (XAMPP):        C:\xampp\htdocs\dombag.env.php
//  O arquivo real NUNCA vai para o git. Variáveis de ambiente reais têm prioridade.
// ══════════════════════════════════════════════════════════════════════════════
return [
    // ── Chat com IA ──────────────────────────────────────────────────────────
    'CHAT_PROVEDOR'           => 'gemini',             // gemini | anthropic
    'GEMINI_API_KEY'          => '',                   // Google AI Studio → Get API key
    'GEMINI_MODEL'            => 'gemini-3.6-flash',
    'GEMINI_MODEL_RESERVA'    => 'gemini-3.5-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite,gemini-3.8-flash', // em ordem, se o principal falhar
    'GEMINI_ESPERA_INICIO'    => 20,                   // segundos sem resposta antes de tentar o próximo modelo
    'GEMINI_THINKING'         => 'low',                // low | medium | high (vazio = padrão do modelo)
    'GEMINI_LIMITE_DIA'       => 0,                    // requisições/dia do plano gratuito (aistudio.google.com/rate-limit); 0 = só mostra o total usado
    'ANTHROPIC_API_KEY'       => '',                   // sk-ant-... (Claude Console → API Keys)
    'CLAUDE_MODEL'            => 'claude-sonnet-5-5',
    'CLAUDE_EFFORT'           => 'low',                // low | medium | high
    'CHAT_MSG_POR_HORA'       => 20,                   // por usuário
    'CHAT_MSG_POR_HORA_IP'    => 120,                  // por IP (a fábrica toda sai pelo mesmo IP)
    'CHAT_RELATORIOS_POR_DIA' => 5,                    // por usuário
    'CHAT_MAX_CHARS'          => 2000,                 // tamanho máximo de uma mensagem
    'CHAT_MAX_TURNOS'         => 20,                   // perguntas por conversa
    'CHAT_REPORTS_DIR'        => '',                   // vazio = <acima da raiz>/dombag_storage/relatorios
    'CHAT_PG_USER'            => '',                   // usuário somente leitura do chat (dombag_ia); vazio = usa PG_USER
    'CHAT_PG_PASS'            => '',

    // ── Bancos (opcional: substitui config/db.production.php) ───────────────
    // 'MYSQLHOST' => '', 'MYSQLPORT' => '3306', 'MYSQLDATABASE' => '', 'MYSQLUSER' => '', 'MYSQLPASSWORD' => '',
    // 'PG_HOST' => '', 'PG_PORT' => '', 'PG_DBNAME' => '', 'PG_USER' => '', 'PG_PASS' => '',
];
