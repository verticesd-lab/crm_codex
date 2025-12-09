<?php
// api/whatsapp-lead-create.php

header('Content-Type: application/json; charset=utf-8');

// =============================
// 1. Autenticação simples
// =============================
$headers = getallheaders();
$apiKey  = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? null;

$secretKey = 'SEU_TOKEN_SECRETO_AQUI'; // Trocar depois

if ($apiKey !== $secretKey) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized'
    ]);
    exit;
}

// =============================
// 2. Receber o JSON
// =============================
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON'
    ]);
    exit;
}

// Campos da mensagem
$phone     = $data['phone']      ?? null;
$name      = $data['name']       ?? null;
$message   = $data['message']    ?? null;
$intentRaw = $data['intent_raw'] ?? null;
$source    = $data['source']     ?? 'whatsapp';


// =============================
// 3. (Por enquanto) apenas responder
// =============================

echo json_encode([
    'ok'        => true,
    'received'  => [
        'phone'     => $phone,
        'name'      => $name,
        'message'   => $message,
        'intentRaw' => $intentRaw,
        'source'    => $source,
    ]
]);
