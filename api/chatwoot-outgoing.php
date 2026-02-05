<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Resposta padrão
 */
function json_response(bool $ok, $data=null, ?string $error=null, int $http=200): void {
  http_response_code($http);
  echo json_encode(
    ['ok'=>$ok,'data'=>$data,'error'=>$error],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
  );
  exit;
}

/**
 * =====================
 * SEGURANÇA – assinatura Chatwoot
 * =====================
 */
$secret = getenv('CHATWOOT_WEBHOOK_SECRET') ?: '';
$signature = $_SERVER['HTTP_X_CHATWOOT_SIGNATURE'] ?? '';

$raw = file_get_contents('php://input');

if ($secret !== '') {
  $expected = hash_hmac('sha256', $raw, $secret);
  if (!hash_equals($expected, (string)$signature)) {
    json_response(false, null, 'Invalid webhook signature', 401);
  }
}

/**
 * =====================
 * PAYLOAD
 * =====================
 */
$payload = json_decode($raw, true);
if (!is_array($payload)) {
  json_response(false, null, 'Invalid JSON payload', 400);
}

/**
 * Apenas mensagens enviadas pelo agente / IA
 */
$event = $payload['event'] ?? '';
if ($event !== 'message_created') {
  json_response(true, ['ignored_event'=>$event], null, 200);
}

$message = $payload['message'] ?? [];
if (($message['message_type'] ?? '') !== 'outgoing') {
  json_response(true, ['ignored'=>'not outgoing'], null, 200);
}

/**
 * =====================
 * DADOS IMPORTANTES
 * =====================
 */
$content = trim((string)($message['content'] ?? ''));
if ($content === '') {
  json_response(true, ['ignored'=>'empty message'], null, 200);
}

$contact = $payload['conversation']['contact'] ?? [];
$phoneRaw = $contact['phone_number'] ?? '';

$phone = preg_replace('/\D+/', '', (string)$phoneRaw);
if ($phone === '') {
  json_response(false, null, 'Contato sem telefone', 422);
}

/**
 * =====================
 * ENVIO PARA WAHA
 * =====================
 */
$WAHA_URL = rtrim((string)getenv('WAHA_BASE_URL'), '/');
$WAHA_TOKEN = (string)getenv('WAHA_API_TOKEN');

if ($WAHA_URL === '' || $WAHA_TOKEN === '') {
  json_response(false, null, 'WAHA não configurado no ambiente', 500);
}

$wahaPayload = [
  'chatId' => $phone . '@c.us',
  'text'   => $content
];

$ch = curl_init($WAHA_URL . '/sendText');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $WAHA_TOKEN,
  ],
  CURLOPT_POSTFIELDS => json_encode($wahaPayload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $code >= 300) {
  json_response(false, [
    'http' => $code,
    'resp' => $response,
    'curl_error' => $error
  ], 'Falha ao enviar mensagem para WAHA', 502);
}

/**
 * =====================
 * OK
 * =====================
 */
json_response(true, [
  'sent_to' => $phone,
  'text' => $content
], null, 200);
