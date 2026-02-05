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
$INBOX_IDENTIFIER = (string)getenv('CHATWOOT_INBOX_IDENTIFIER');

if ($CHATWOOT_BASE === '') $CHATWOOT_BASE = 'https://chat.formenstore.com.br';
if ($INBOX_IDENTIFIER === '') $INBOX_IDENTIFIER = 'gHuxGfLktXnJvLMggKRQSzkE';

if ($CHATWOOT_TOKEN === '') {
  json_response(false, null, 'CHATWOOT_API_ACCESS_TOKEN não configurado no ambiente', 500);
}

// Token simples do bridge (recomendado)
$expected = (string)(getenv('WAHA_BRIDGE_TOKEN') ?: '');
if ($expected !== '') {
  $got = $_GET['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
  $got = str_replace('Bearer ', '', (string)$got);
  if (!hash_equals(trim($expected), trim((string)$got))) {
    json_response(false, null, 'Unauthorized', 401);
  }
}

// =====================
// INPUT
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
  json_response(false, null, 'direction deve ser incoming neste endpoint', 400);
}

// =====================
// CHATWOOT ACCOUNTS API
// =====================
$ACCOUNT_ID = (int)(getenv('CHATWOOT_ACCOUNT_ID') ?: 1);

$headers = [
  'Content-Type' => 'application/json',
  'api_access_token' => $CHATWOOT_TOKEN,
];

// ---------- helper: encontrar inbox_id pelo identifier ----------
function resolve_inbox_id(string $base, int $accountId, array $headers, string $identifier): int {
  $url = $base . "/api/v1/accounts/{$accountId}/inboxes";
  $r = http_json('GET', $url, $headers, null);

  if (!$r['ok']) return 0;

  $list = $r['json']['payload'] ?? $r['json'] ?? [];
  if (!is_array($list)) return 0;

  foreach ($list as $ib) {
    $id = (int)($ib['id'] ?? 0);
    $ident = $ib['channel']['identifier'] ?? ($ib['identifier'] ?? '');
    if ((string)$ident === (string)$identifier) {
      return $id;
    }
  }
  return 0;
}

// ---------- helper: buscar contato ----------
function find_contact_id(string $base, int $accountId, array $headers, string $phone, string $identifier): int {
  // 1) tenta search por telefone
  $url = $base . "/api/v1/accounts/{$accountId}/contacts/search?q=" . urlencode($phone);
  $r = http_json('GET', $url, $headers, null);
  if ($r['ok']) {
    $payload = $r['json']['payload'] ?? $r['json'] ?? [];
    // payload pode vir como array de contatos
    if (is_array($payload)) {
      foreach ($payload as $c) {
        $cid = (int)($c['id'] ?? 0);
        $cident = (string)($c['identifier'] ?? '');
        $cphone = preg_replace('/\D+/', '', (string)($c['phone_number'] ?? ''));
        if ($cid && ($cident === $identifier || $cphone === $phone)) {
          return $cid;
        }
      }
    }
  }

  // 2) fallback: listar contatos com page (pesado) -> não fazer por padrão
  return 0;
}

// ---------- helper: criar contato ----------
function create_contact(string $base, int $accountId, array $headers, string $phone, string $name, string $identifier, string $source): array {
  $url = $base . "/api/v1/accounts/{$accountId}/contacts";
  $payload = [
    'name' => ($name !== '' ? $name : $phone),
    'phone_number' => '+' . $phone,
    'identifier' => $identifier,
    'custom_attributes' => [
      'source' => $source,
      'raw_phone' => $phone,
    ],
  ];
  return http_json('POST', $url, $headers, $payload);
}

// ---------- helper: buscar conversa existente (por source_id) ----------
function find_conversation_id(string $base, int $accountId, array $headers, int $inboxId, string $sourceId): int {
  // lista conversas do inbox e tenta achar pelo source_id
  // (limita 20 pra não pesar)
  $url = $base . "/api/v1/accounts/{$accountId}/conversations?inbox_id={$inboxId}&status=all&assignee_type=all&page=1";
  $r = http_json('GET', $url, $headers, null);
  if (!$r['ok']) return 0;

  $list = $r['json']['data']['payload'] ?? $r['json']['payload'] ?? $r['json'] ?? [];
  if (!is_array($list)) return 0;

  foreach ($list as $c) {
    $cid = (int)($c['id'] ?? 0);
    $sid = (string)($c['source_id'] ?? '');
    if ($cid && $sid === $sourceId) return $cid;
  }
  return 0;
}

// ---------- helper: criar conversa ----------
function create_conversation(string $base, int $accountId, array $headers, int $inboxId, int $contactId, string $sourceId): array {
  $url = $base . "/api/v1/accounts/{$accountId}/conversations";
  $payload = [
    'source_id' => $sourceId,
    'inbox_id' => $inboxId,
    'contact_id' => $contactId,
  ];
  return http_json('POST', $url, $headers, $payload);
}

// =====================
// 0) Resolve inbox_id
// =====================
$inboxId = resolve_inbox_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $INBOX_IDENTIFIER);
if (!$inboxId) {
  json_response(false, [
    'step' => 'find_inbox_id',
    'inbox_identifier' => $INBOX_IDENTIFIER,
  ], 'Não consegui resolver inbox_id numérico pelo identifier', 502);
}

// =====================
// 1) Find-or-Create Contact
// =====================
$identifier = $phone;
$contactId = find_contact_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $phone, $identifier);

if (!$contactId) {
  $r1 = create_contact($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $phone, $name, $identifier, $source);

  // Se deu 422 porque já existe, tenta buscar de novo
  if (!$r1['ok'] && (int)$r1['code'] === 422) {
    $contactId = find_contact_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $phone, $identifier);
  } elseif ($r1['ok']) {
    $j = $r1['json'] ?? [];
    $contactId = (int)($j['payload']['contact']['id'] ?? ($j['id'] ?? 0));
  }

  if (!$contactId) {
    json_response(false, [
      'step' => 'create_contact',
      'http' => $r1['code'] ?? null,
      'resp' => $r1['json'] ?? $r1['raw'] ?? null,
    ], 'Falha ao criar/obter contato no Chatwoot', 502);
  }
}

// =====================
// 2) Find-or-Create Conversation (por source_id)
// =====================
$sourceId = 'waha:' . $phone;

// tenta achar já existente
$conversationId = find_conversation_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $inboxId, $sourceId);

if (!$conversationId) {
  $r2 = create_conversation($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $inboxId, $contactId, $sourceId);

  // Se falhou porque "já existe" (varia), tenta achar de novo
  if (!$r2['ok']) {
    $conversationId = find_conversation_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $inboxId, $sourceId);
  } else {
    $j = $r2['json'] ?? [];
    $conversationId = (int)($j['id'] ?? ($j['payload']['id'] ?? 0));
  }

  if (!$conversationId) {
    json_response(false, [
      'step' => 'create_conversation',
      'http' => $r2['code'] ?? null,
      'resp' => $r2['json'] ?? $r2['raw'] ?? null,
    ], 'Falha ao criar/obter conversa no Chatwoot', 502);
  }
}

// =====================
// 3) Create Message (incoming)
// =====================
$createMsgUrl = $CHATWOOT_BASE . "/api/v1/accounts/{$ACCOUNT_ID}/conversations/{$conversationId}/messages";
$messagePayload = [
  'content' => $content,
  'message_type' => 'incoming',
];

// Evita mandar null
if ($externalId !== '') {
  // Alguns builds aceitam, outros ignoram; mantém sem quebrar
  $messagePayload['external_source_ids'] = $externalId;
}

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
  'conversation_id' => $conversationId,
], null, 200);
