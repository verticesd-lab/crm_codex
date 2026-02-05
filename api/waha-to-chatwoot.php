<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, $data=null, ?string $error=null, int $http=200): void {
  http_response_code($http);
  echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function http_json(string $method, string $url, array $headers, ?array $body=null, int $timeout=20): array {
  $ch = curl_init($url);
  $payload = $body ? json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

  $hdrs = [];
  foreach ($headers as $k => $v) $hdrs[] = $k . ': ' . $v;

  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $hdrs,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);

  if ($payload !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  }

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    return ['ok'=>false,'code'=>$code,'error'=>'cURL: '.$err,'raw'=>null,'json'=>null];
  }

  $json = json_decode((string)$resp, true);
  return ['ok'=>($code >= 200 && $code < 300), 'code'=>$code, 'error'=>null, 'raw'=>$resp, 'json'=>$json];
}

// =====================
// CONFIG
// =====================
$CHATWOOT_BASE = rtrim((string)getenv('CHATWOOT_BASE_URL'), '/');
$CHATWOOT_TOKEN = (string)getenv('CHATWOOT_API_ACCESS_TOKEN');
$INBOX_IDENTIFIER = (string)getenv('CHATWOOT_INBOX_IDENTIFIER'); // <- seu identificador

if ($CHATWOOT_BASE === '') $CHATWOOT_BASE = 'https://chat.formenstore.com.br';

// Você já informou este:
if ($INBOX_IDENTIFIER === '') $INBOX_IDENTIFIER = 'gHuxGfLktXnJvLMggKRQSzkE';

if ($CHATWOOT_TOKEN === '') {
  json_response(false, null, 'CHATWOOT_API_ACCESS_TOKEN não configurado no ambiente', 500);
}

// Token simples do bridge (recomendado)
$expected = getenv('WAHA_BRIDGE_TOKEN') ?: '';
if ($expected !== '') {
  $got = $_GET['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
  $got = str_replace('Bearer ', '', (string)$got);
  if (!hash_equals($expected, trim((string)$got))) {
    json_response(false, null, 'Unauthorized', 401);
  }
}

// =====================
// INPUT (aceita seu formato atual)
// =====================
$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = $_POST;

$source = (string)($body['source'] ?? 'waha');
$msg = $body['message'] ?? null;
$contact = $body['contact'] ?? null;

if (!is_array($msg)) json_response(false, null, 'message obrigatório', 400);
if (!is_array($contact)) json_response(false, null, 'contact obrigatório', 400);

$phone = preg_replace('/\D+/', '', (string)($contact['phone'] ?? ''));
$name  = trim((string)($contact['name'] ?? ''));
$content = (string)($msg['content'] ?? '');
$externalId = (string)($msg['id'] ?? '');
$direction = (string)($msg['direction'] ?? 'incoming');

if ($phone === '') json_response(false, null, 'contact.phone obrigatório', 400);
if ($content === '') json_response(false, null, 'message.content obrigatório', 400);
if ($direction !== 'incoming') {
  // este bridge é para ENTRADA do cliente
  json_response(false, null, 'direction deve ser incoming neste endpoint', 400);
}

// =====================
// CHATWOOT ACCOUNTS API (mais estável)
// =====================
$ACCOUNT_ID = (int)(getenv('CHATWOOT_ACCOUNT_ID') ?: 1);

$headers = [
  'Content-Type' => 'application/json',
  'api_access_token' => $CHATWOOT_TOKEN,
];

// 1) Create/Update Contact
$createContactUrl = $CHATWOOT_BASE . "/api/v1/accounts/{$ACCOUNT_ID}/contacts";

$contactPayload = [
  'name' => ($name !== '' ? $name : $phone),
  'phone_number' => '+' . $phone,
  'identifier' => $phone,
  'custom_attributes' => [
    'source' => $source,
    'raw_phone' => $phone,
  ],
];

$r1 = http_json('POST', $createContactUrl, $headers, $contactPayload);
if (!$r1['ok']) {
  json_response(false, [
    'step' => 'create_contact',
    'http' => $r1['code'],
    'resp' => $r1['json'] ?? $r1['raw'],
  ], 'Falha ao criar contato no Chatwoot', 502);
}

// Contact ID
$contactJson = $r1['json'] ?? [];
$contactId = $contactJson['payload']['contact']['id'] ?? ($contactJson['id'] ?? null);
if (!$contactId) {
  json_response(false, ['step'=>'create_contact','resp'=>$contactJson], 'Chatwoot não retornou contact_id', 502);
}

// 2) Create Conversation (precisa do inbox_id numérico)
// Vamos buscar o inbox_id pelo identifier UMA vez e cachear opcionalmente
$inboxId = null;
$inboxesUrl = $CHATWOOT_BASE . "/api/v1/accounts/{$ACCOUNT_ID}/inboxes";
$rIn = http_json('GET', $inboxesUrl, $headers, null);

if ($rIn['ok']) {
  $list = $rIn['json']['payload'] ?? $rIn['json'] ?? [];
  foreach ($list as $ib) {
    // algumas versões usam "identifier" dentro de "channel" ou direto
    $identifier = $ib['channel']['identifier'] ?? ($ib['identifier'] ?? '');
    if ((string)$identifier === (string)$INBOX_IDENTIFIER) {
      $inboxId = (int)($ib['id'] ?? 0);
      break;
    }
  }
}

if (!$inboxId) {
  json_response(false, [
    'step' => 'find_inbox_id',
    'resp' => $rIn['json'] ?? $rIn['raw'],
  ], 'Não consegui resolver inbox_id numérico pelo identifier', 502);
}

// 3) Create Conversation
$createConvUrl = $CHATWOOT_BASE . "/api/v1/accounts/{$ACCOUNT_ID}/conversations";
$convPayload = [
  'source_id' => 'waha:' . $phone,   // idempotência por canal
  'inbox_id' => $inboxId,
  'contact_id' => $contactId,
];

$r2 = http_json('POST', $createConvUrl, $headers, $convPayload);
if (!$r2['ok']) {
  json_response(false, [
    'step' => 'create_conversation',
    'http' => $r2['code'],
    'resp' => $r2['json'] ?? $r2['raw'],
  ], 'Falha ao criar conversa no Chatwoot', 502);
}

$convJson = $r2['json'] ?? [];
$conversationId = $convJson['id'] ?? ($convJson['payload']['id'] ?? null);
if (!$conversationId) {
  json_response(false, ['step'=>'create_conversation','resp'=>$convJson], 'Chatwoot não retornou conversation_id', 502);
}

// 4) Create Message (incoming)
$createMsgUrl = $CHATWOOT_BASE . "/api/v1/accounts/{$ACCOUNT_ID}/conversations/{$conversationId}/messages";
$messagePayload = [
  'content' => $content,
  'message_type' => 'incoming',
  'external_source_ids' => ($externalId !== '' ? $externalId : null),
];

$r3 = http_json('POST', $createMsgUrl, $headers, $messagePayload);
if (!$r3['ok']) {
  json_response(false, [
    'step' => 'create_message',
    'http' => $r3['code'],
    'resp' => $r3['json'] ?? $r3['raw'],
  ], 'Falha ao criar mensagem no Chatwoot', 502);
}

json_response(true, [
  'account_id' => $ACCOUNT_ID,
  'inbox_identifier' => $INBOX_IDENTIFIER,
  'inbox_id' => $inboxId,
  'contact_id' => $contactId,
  'conversation_id' => (int)$conversationId,
], null, 200);
