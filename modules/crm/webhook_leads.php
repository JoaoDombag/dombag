<?php

declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/crm/leads_common.php';

// DOMBAG — webhook_leads.php
// Adaptado para receber o mesmo payload que hoje vai para o Google Sheets.
// Método: POST
// Autenticação: header X-Webhook-Token OU campo webhook_token no body.

define('WEBHOOK_TOKEN', '1Il66UA1xXyoD1bWE1B-s7fekK2ZpEUpVjlxw8Hhm1E4');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Webhook-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use POST para enviar o lead.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = leadsPdo();
    leadsEnsureSchema($pdo);

    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $raw = file_get_contents('php://input') ?: '';
    $data = [];

    if (str_contains($ct, 'application/json')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    if (!$data) {
        $data = $_POST;
    }

    $tokenHeader = trim((string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
    $tokenBody = trim((string) ($data['webhook_token'] ?? ''));
    $tokenRecebido = $tokenHeader !== '' ? $tokenHeader : $tokenBody;

    if (!hash_equals(WEBHOOK_TOKEN, $tokenRecebido)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Token inválido ou ausente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    unset($data['webhook_token']);

    $payload = leadsBuildPayload($data, $_SERVER);
    $errors = leadsValidate($payload);

    if ($errors) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => implode(' ', $errors),
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = leadsInsert($pdo, $payload);

    echo json_encode([
        'ok' => true,
        'message' => $result['duplicado']
            ? 'Lead já existia e foi identificado como duplicado.'
            : 'Lead salvo com sucesso no site.',
        'lead_id_interno' => $result['id'],
        'lead_id' => $payload['lead_id'],
        'duplicado' => $result['duplicado'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Erro ao processar lead.',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
