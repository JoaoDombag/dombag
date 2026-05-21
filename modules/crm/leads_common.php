<?php

declare(strict_types=1);

// DOMBAG — leads_common.php (adaptado ao payload do Apps Script / planilha)
// Inclua APÓS config.php e auth.php.

if (!function_exists('dbPDO')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
}

const LEADS_STATUS_OPTIONS = [
    'NOVO' => 'Novo',
    'EM_ANALISE' => 'Em análise',
    'QUALIFICADO' => 'Qualificado',
    'DESQUALIFICADO' => 'Desqualificado',
    'CLIENTE_EXISTENTE' => 'Cliente existente',
    'DUPLICADO' => 'Duplicado',
    'VENDA' => 'Venda',
    'PERDIDO' => 'Perdido',
];

function leadsPdo(): PDO
{
    return dbPDO();
}

function leadsEnsureSchema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS LEADS_ADS (
            ID                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            LEAD_ID             VARCHAR(120)   NULL,
            DATA_CRIACAO        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            NOME                VARCHAR(160)   NULL,
            TELEFONE            VARCHAR(60)    NULL,
            EMAIL               VARCHAR(180)   NULL,
            EMPRESA             VARCHAR(180)   NULL,
            PRODUTO_INTERESSE   VARCHAR(180)   NULL,
            ORIGEM_BOTAO        VARCHAR(120)   NULL,
            ORIGEM_LP           VARCHAR(120)   NULL,
            GCLID               VARCHAR(255)   NULL,
            GBRAID              VARCHAR(255)   NULL,
            WBRAID              VARCHAR(255)   NULL,
            UTM_SOURCE          VARCHAR(120)   NULL,
            UTM_MEDIUM          VARCHAR(120)   NULL,
            UTM_CAMPAIGN        VARCHAR(180)   NULL,
            STATUS_ATUAL        VARCHAR(40)    NOT NULL DEFAULT 'NOVO',
            DATA_QUALIFICACAO   DATETIME       NULL,
            DATA_VENDA_FECHADA  DATETIME       NULL,
            DATA_PERDA          DATETIME       NULL,
            MOTIVO_PERDA        TEXT           NULL,
            VALOR_VENDA         DECIMAL(15,2)  NULL,
            VENDEDOR            VARCHAR(120)   NULL,
            REFERER_URL         VARCHAR(255)   NULL,
            LANDING_URL         VARCHAR(255)   NULL,
            IP_ORIGEM           VARCHAR(64)    NULL,
            USER_AGENT          VARCHAR(255)   NULL,
            PAYLOAD_JSON        LONGTEXT       NULL,
            DEDUPE_HASH         CHAR(64)       NULL,
            CRIADO_EM           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ATUALIZADO_EM       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_leads_status   (STATUS_ATUAL),
            INDEX idx_leads_data     (DATA_CRIACAO),
            INDEX idx_leads_email    (EMAIL),
            INDEX idx_leads_tel      (TELEFONE),
            INDEX idx_leads_lp       (ORIGEM_LP),
            INDEX idx_leads_vendedor (VENDEDOR),
            UNIQUE KEY uq_leads_dedupe (DEDUPE_HASH),
            UNIQUE KEY uq_leads_lead_id (LEAD_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);
}

function leadsH(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function leadsTrim(?string $v): string
{
    return trim((string) $v);
}

function leadsOnlyDigits(?string $v): string
{
    return preg_replace('/\D+/', '', (string) $v) ?? '';
}

function leadsNormalizeText(?string $v): string
{
    $v = leadsTrim($v);
    if ($v === '') {
        return '';
    }
    $c = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
    $v = strtolower($c !== false ? $c : $v);
    $v = preg_replace('/[^a-z0-9]+/i', ' ', $v);
    return trim((string) $v);
}

function leadsPhoneForDisplay(?string $v): string
{
    $d = leadsOnlyDigits($v);
    if (strlen($d) === 11) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7, 4));
    }
    if (strlen($d) === 10) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6, 4));
    }
    return leadsTrim($v);
}

function leadsStatusLabel(string $status): string
{
    return LEADS_STATUS_OPTIONS[$status] ?? ucfirst(strtolower(str_replace('_', ' ', $status)));
}

function leadsParseDate(?string $value): ?string
{
    $value = leadsTrim($value);
    if ($value === '') {
        return null;
    }

    // YYYY-MM-DD ou ISO
    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }

    // dd/mm/yyyy hh:mm:ss
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)) {
        $h = $m[4] ?? '00';
        $i = $m[5] ?? '00';
        $s = $m[6] ?? '00';
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int) $m[3], (int) $m[2], (int) $m[1], (int) $h, (int) $i, (int) $s);
    }

    return null;
}

function leadsNormalizeStatus(?string $status): string
{
    $s = strtoupper(leadsTrim($status));
    if ($s === '') {
        return 'NOVO';
    }
    $aliases = [
        'NOVO' => 'NOVO',
        'EM ANALISE' => 'EM_ANALISE',
        'EM_ANALISE' => 'EM_ANALISE',
        'QUALIFICADO' => 'QUALIFICADO',
        'DESQUALIFICADO' => 'DESQUALIFICADO',
        'CLIENTE EXISTENTE' => 'CLIENTE_EXISTENTE',
        'CLIENTE_EXISTENTE' => 'CLIENTE_EXISTENTE',
        'DUPLICADO' => 'DUPLICADO',
        'VENDA' => 'VENDA',
        'PERDIDO' => 'PERDIDO',
    ];
    return $aliases[$s] ?? 'NOVO';
}

function leadsBuildPayload(array $src, array $server = []): array
{
    $dataCriacao = leadsParseDate((string) ($src['data_criacao'] ?? '')) ?? date('Y-m-d H:i:s');

    return [
        'lead_id' => leadsTrim($src['lead_id'] ?? ''),
        'data_criacao' => $dataCriacao,
        'nome' => leadsTrim($src['nome'] ?? ''),
        'telefone' => leadsOnlyDigits($src['telefone'] ?? ''),
        'email' => strtolower(leadsTrim($src['email'] ?? '')),
        'empresa' => leadsTrim($src['empresa'] ?? ''),
        'produto_interesse' => leadsTrim($src['produto_interesse'] ?? ($src['interesse'] ?? '')),
        'origem_botao' => leadsTrim($src['origem_botao'] ?? ''),
        'origem_lp' => leadsTrim($src['origem_LP'] ?? ($src['origem_lp'] ?? '')),
        'gclid' => leadsTrim($src['gclid'] ?? ''),
        'gbraid' => leadsTrim($src['gbraid'] ?? ''),
        'wbraid' => leadsTrim($src['wbraid'] ?? ''),
        'utm_source' => leadsTrim($src['utm_source'] ?? ''),
        'utm_medium' => leadsTrim($src['utm_medium'] ?? ''),
        'utm_campaign' => leadsTrim($src['utm_campaign'] ?? ''),
        'status_atual' => leadsNormalizeStatus($src['status_atual'] ?? 'NOVO'),
        'data_qualificacao' => leadsParseDate((string) ($src['data_qualificacao'] ?? '')),
        'data_venda_fechada' => leadsParseDate((string) ($src['data_venda_fechada'] ?? '')),
        'data_perda' => leadsParseDate((string) ($src['data_perda'] ?? '')),
        'motivo_perda' => leadsTrim($src['motivo_perda'] ?? ''),
        'valor_venda' => ($src['valor_venda'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $src['valor_venda']) : null,
        'vendedor' => leadsTrim($src['vendedor'] ?? ''),
        'referer_url' => leadsTrim($src['referer_url'] ?? ($server['HTTP_REFERER'] ?? '')),
        'landing_url' => leadsTrim($src['landing_url'] ?? ''),
        'ip_origem' => leadsTrim($server['REMOTE_ADDR'] ?? ''),
        'user_agent' => leadsTrim($server['HTTP_USER_AGENT'] ?? ''),
    ];
}

function leadsValidate(array $p): array
{
    $e = [];
    if ($p['nome'] === '') {
        $e[] = 'Informe o nome.';
    }
    if ($p['telefone'] === '' && $p['email'] === '') {
        $e[] = 'Informe ao menos telefone ou e-mail.';
    }
    if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        $e[] = 'E-mail inválido.';
    }
    return $e;
}

function leadsDedupeHash(array $p): string
{
    return hash('sha256', implode('|', [
        strtolower($p['email'] ?? ''),
        $p['telefone'] ?? '',
        leadsNormalizeText($p['nome'] ?? ''),
        leadsNormalizeText($p['empresa'] ?? ''),
        leadsNormalizeText($p['produto_interesse'] ?? ''),
    ]));
}

function leadsInsert(PDO $pdo, array $p): array
{
    $p['dedupe_hash'] = leadsDedupeHash($p);
    $p['payload_json'] = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $sql = 'INSERT INTO LEADS_ADS (
        LEAD_ID,DATA_CRIACAO,NOME,TELEFONE,EMAIL,EMPRESA,PRODUTO_INTERESSE,ORIGEM_BOTAO,ORIGEM_LP,
        GCLID,GBRAID,WBRAID,UTM_SOURCE,UTM_MEDIUM,UTM_CAMPAIGN,STATUS_ATUAL,DATA_QUALIFICACAO,
        DATA_VENDA_FECHADA,DATA_PERDA,MOTIVO_PERDA,VALOR_VENDA,VENDEDOR,REFERER_URL,LANDING_URL,
        IP_ORIGEM,USER_AGENT,PAYLOAD_JSON,DEDUPE_HASH
    ) VALUES (
        :lead_id,:data_criacao,:nome,:telefone,:email,:empresa,:produto_interesse,:origem_botao,:origem_lp,
        :gclid,:gbraid,:wbraid,:utm_source,:utm_medium,:utm_campaign,:status_atual,:data_qualificacao,
        :data_venda_fechada,:data_perda,:motivo_perda,:valor_venda,:vendedor,:referer_url,:landing_url,
        :ip_origem,:user_agent,:payload_json,:dedupe_hash
    )';

    try {
        $pdo->prepare($sql)->execute($p);
        return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'duplicado' => false];
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            $s = $pdo->prepare('SELECT ID FROM LEADS_ADS WHERE LEAD_ID = :lead_id OR DEDUPE_HASH = :dedupe_hash ORDER BY ID DESC LIMIT 1');
            $s->execute(['lead_id' => $p['lead_id'], 'dedupe_hash' => $p['dedupe_hash']]);
            return ['ok' => true, 'id' => (int) ($s->fetchColumn() ?: 0), 'duplicado' => true];
        }
        throw $e;
    }
}

function leadsCountByStatus(PDO $pdo): array
{
    $rows = $pdo->query('SELECT STATUS_ATUAL, COUNT(*) qtd FROM LEADS_ADS GROUP BY STATUS_ATUAL')->fetchAll();
    $out = array_fill_keys(array_keys(LEADS_STATUS_OPTIONS), 0);
    foreach ($rows as $r) {
        $out[$r['STATUS_ATUAL']] = (int) $r['qtd'];
    }
    return $out;
}
