<?php
// api/whatsapp-lead-create.php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    // =============================
    // 1. Receber o JSON
    // =============================
    $rawBody = file_get_contents('php://input');
    $data    = json_decode($rawBody, true);

    if (!is_array($data)) {
        http_response_code(200); // evita 500 no Activepieces
        echo json_encode([
            'ok'       => false,
            'error'    => 'Invalid JSON. Envie um corpo JSON válido.',
            'raw_body' => $rawBody,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Campos da mensagem
    $phone     = trim($data['phone']      ?? '');
    $name      = trim($data['name']       ?? '');
    $message   = trim($data['message']    ?? '');
    $intentRaw = trim($data['intent_raw'] ?? '');
    $source    = trim($data['source']     ?? 'whatsapp');

    // =============================
    // 2. (Por enquanto) apenas responder
    //    Depois a gente pluga no CRM de verdade
    // =============================

    echo json_encode([
        'ok'       => true,
        'received' => [
            'phone'      => $phone,
            'name'       => $name,
            'message'    => $message,
            'intentRaw'  => $intentRaw,
            'source'     => $source,
        ],
        'debug'    => [
            'raw_body' => $rawBody,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(200); // evita 500 estourar no Activepieces
    echo json_encode([
        'ok'   => false,
        'error'=> 'exception',
        'msg'  => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
