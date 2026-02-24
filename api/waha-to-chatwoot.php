<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, $data=null, ?string $error=null, int $http=200): void {
  http_response_code($http);
  echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Carrega variáveis do .env (fallback) + getenv (prioridade).
 * Ajuste o caminho se seu .env estiver em outro lugar.
 */
function env_local(string $key, string $default = ''): string {
  static $loaded = false;
  static $map = [];

  if (!$loaded) {
    $loaded = true;
    $map = [];

    // tenta .env na raiz do projeto (../..), e também um .env ao lado do /api (../)
    $candidates = [
      dirname(__DIR__, 2) . '/.env',
      dirname(__DIR__) . '/.env',
    ];

    foreach ($candidates as $envPath) {
      if (!is_file($envPath)) continue;

      foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v);
        $v = trim($v, "\"'"); // remove aspas
        if ($k !== '') $map[$k] = $v;
      }
    }
  }

  $g = getenv($key);
  if ($g !== false && $g !== '') return (string)$g;

  if (isset($map[$key]) && $map[$key] !== '') return (string)$map[$key];

  return $default;
}

function get_header(string $name): ?string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return $_SERVER[$key] ?? null;
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

  if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

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

/**
 * Normaliza telefone para dígitos puros:
 * - aceita: "5565...@c.us", "5565...@s.whatsapp.net", "6685...@lid", "+55 (65) ..."
 * - se vier @lid, usa remoteJidAlt se fornecido (melhor).
 */
function normalize_phone(string $raw, ?string $remoteAlt = null): string {
  $raw = trim($raw);

  // se veio @lid e temos alternativa real
  if (str_contains($raw, '@lid') && $remoteAlt) {
    $raw = trim($remoteAlt);
  }

  // remove sufixos whatsapp
  $raw = str_replace(['@c.us', '@s.whatsapp.net', '@lid'], '', $raw);

  // mantém só dígitos
  $digits = preg_replace('/\D+/', '', $raw ?? '');
  return (string)$digits;
}

/**
 * Mask simples para log (não vaza token completo).
 */
function mask_token(string $s): string {
  $s = trim($s);
  if ($s === '') return '(empty)';
  return str_repeat('*', max(0, strlen($s) - 6)) . substr($s, -6);
}

// =====================
// CONFIG CHATWOOT
// =====================
$CHATWOOT_BASE = rtrim(env_local('CHATWOOT_BASE_URL', ''), '/');
$CHATWOOT_TOKEN = env_local('CHATWOOT_API_ACCESS_TOKEN', '');
$INBOX_IDENTIFIER = env_local('CHATWOOT_INBOX_IDENTIFIER', '');
$INBOX_ID_ENV = (int)(env_local('CHATWOOT_INBOX_ID', '0')); // opcional
$ACCOUNT_ID = (int)(env_local('CHATWOOT_ACCOUNT_ID', '1'));

if ($CHATWOOT_BASE === '') $CHATWOOT_BASE = 'https://chat.formenstore.com.br';
if ($INBOX_IDENTIFIER === '') $INBOX_IDENTIFIER = 'gHuxGfLktXnJvLMggKRQSzkE';

if ($CHATWOOT_TOKEN === '') {
  json_response(false, null, 'CHATWOOT_API_ACCESS_TOKEN não configurado (env/.env)', 500);
}

$headers = [
  'Content-Type' => 'application/json',
  'api_access_token' => $CHATWOOT_TOKEN,
];

// =====================
// AUTH DO BRIDGE (WAHA/ActivePieces -> CRM)
// =====================
// Use WAHA_BRIDGE_TOKEN como token esperado.
// (Opcional) Se vazio, não exige token.
$expected = env_local('WAHA_BRIDGE_TOKEN', '');

if ($expected !== '') {
  $tokenHeader =
    (string)(get_header('X-Chatwoot-Webhook-Token') ?: '') ?:
    (string)(get_header('X-Webhook-Token') ?: '');

  $got =
    (string)($_GET['token'] ?? '') ?:
    (string)(get_header('Authorization') ?: '') ?:
    (string)$tokenHeader;

  $got = str_replace('Bearer ', '', trim((string)$got));

  if (!hash_equals(trim($expected), trim($got))) {
    // se você tiver função de log no helpers, ótimo; se não tiver, não quebra.
    if (function_exists('log_cw')) {
      log_cw("UNAUTHORIZED bridge_token expected=" . mask_token($expected) . " got=" . mask_token($got));
    }
    json_response(false, null, 'Unauthorized', 401);
  }
}

// =====================
// INPUT (aceita 2 formatos)
// =====================
// Formato A (antigo):
// { source, contact:{name,phone}, message:{id,content,direction,timestamp} }
//
// Formato B (novo do ActivePieces):
// { source, fromMe, phone_raw, chatId, pushName, messageId, timestamp, text }
// ou com remoteJidAlt etc.
$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = $_POST;

$source = (string)($body['source'] ?? 'waha');

// Detecta formato
$msg = $body['message'] ?? null;
$contact = $body['contact'] ?? null;

$direction = 'incoming';
$content = '';
$externalId = '';
$pushName = '';
$phoneDigits = '';
$rawPhone = '';
$remoteAlt = null; // remoteJidAlt
$chatId = '';
$fromMe = null;
$ts = null;

if (is_array($msg) && is_array($contact)) {
  // -------- Formato A --------
  $rawPhone = (string)($contact['phone'] ?? '');
  $pushName = trim((string)($contact['name'] ?? ''));
  $content = (string)($msg['content'] ?? '');
  $externalId = (string)($msg['id'] ?? '');
  $direction = (string)($msg['direction'] ?? 'incoming');
  $ts = $msg['timestamp'] ?? null;

  $phoneDigits = preg_replace('/\D+/', '', $rawPhone);
} else {
  // -------- Formato B --------
  $rawPhone = (string)($body['phone_raw'] ?? '');
  $remoteAlt = $body['remoteJidAlt'] ?? ($body['phone_alt'] ?? null);
  $chatId = (string)($body['chatId'] ?? '');
  $pushName = trim((string)($body['pushName'] ?? ''));
  $externalId = (string)($body['messageId'] ?? ($body['id'] ?? ''));
  $content = (string)($body['text'] ?? ($body['body'] ?? ''));
  $fromMe = $body['fromMe'] ?? null;
  $ts = $body['timestamp'] ?? null;

  // se vier algo tipo "5565...@s.whatsapp.net"
  $phoneDigits = normalize_phone($rawPhone, is_string($remoteAlt) ? $remoteAlt : null);

  // se phone_raw veio vazio mas chatId veio preenchido, tenta pelo chatId
  if ($phoneDigits === '' && $chatId !== '') {
    $phoneDigits = normalize_phone($chatId, is_string($remoteAlt) ? $remoteAlt : null);
  }

  // enforce incoming neste endpoint
  $direction = 'incoming';
}

// Validações
if ($phoneDigits === '') json_response(false, ['raw'=>$rawPhone, 'chatId'=>$chatId], 'contact.phone obrigatório (não consegui extrair número)', 400);
if ($content === '') json_response(false, null, 'message.content obrigatório', 400);
if ($direction !== 'incoming') json_response(false, null, 'direction deve ser incoming neste endpoint', 400);

// se pushName vazio, usa o telefone
if ($pushName === '') $pushName = $phoneDigits;

// =====================
// CHATWOOT HELPERS
// =====================
function resolve_inbox_id(string $base, int $accountId, array $headers, string $inboxIdentifier): int {
  $url = $base . "/api/v1/accounts/{$accountId}/inboxes";
  $r = http_json('GET', $url, $headers, null);
  if (!$r['ok']) return 0;

  $j = $r['json'] ?? [];
  $list = null;

  if (is_array($j)) {
    if (isset($j['payload']) && is_array($j['payload'])) $list = $j['payload'];
    elseif (isset($j['data']['payload']) && is_array($j['data']['payload'])) $list = $j['data']['payload'];
    elseif (isset($j['data']) && is_array($j['data'])) $list = $j['data'];
    elseif (array_is_list($j)) $list = $j;
  }

  if (!is_array($list)) return 0;

  foreach ($list as $ib) {
    if (!is_array($ib)) continue;

    $id = (int)($ib['id'] ?? 0);

    $cand = [
      (string)($ib['inbox_identifier'] ?? ''),
      (string)($ib['identifier'] ?? ''),
      (string)($ib['channel']['identifier'] ?? ''),
      (string)($ib['messaging_channel']['identifier'] ?? ''),
      (string)($ib['website_token'] ?? ''),
    ];

    foreach ($cand as $c) {
      if ($c !== '' && $c === (string)$inboxIdentifier) return $id;
    }
  }

  return 0;
}

function find_contact_id(string $base, int $accountId, array $headers, string $phone, string $identifier): int {
  $url = $base . "/api/v1/accounts/{$accountId}/contacts/search?q=" . urlencode($phone);
  $r = http_json('GET', $url, $headers, null);
  if (!$r['ok']) return 0;

  $payload = $r['json']['payload'] ?? $r['json'] ?? [];
  if (!is_array($payload)) return 0;

  foreach ($payload as $c) {
    if (!is_array($c)) continue;
    $cid = (int)($c['id'] ?? 0);
    if (!$cid) continue;

    $cident = (string)($c['identifier'] ?? '');
    $cphone = preg_replace('/\D+/', '', (string)($c['phone_number'] ?? ''));

    if ($cident === $identifier || $cphone === $phone) return $cid;
  }

  return 0;
}

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

function find_conversation_id(string $base, int $accountId, array $headers, int $inboxId, string $sourceId): int {
  $url = $base . "/api/v1/accounts/{$accountId}/conversations?inbox_id={$inboxId}&status=all&assignee_type=all&page=1";
  $r = http_json('GET', $url, $headers, null);
  if (!$r['ok']) return 0;

  $list = $r['json']['data']['payload'] ?? $r['json']['payload'] ?? $r['json'] ?? [];
  if (!is_array($list)) return 0;

  foreach ($list as $c) {
    if (!is_array($c)) continue;
    $cid = (int)($c['id'] ?? 0);
    $sid = (string)($c['source_id'] ?? '');
    if ($cid && $sid === $sourceId) return $cid;
  }
  return 0;
}

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
// 0) inbox_id
// =====================
$inboxId = $INBOX_ID_ENV;
if ($inboxId <= 0) {
  $inboxId = resolve_inbox_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $INBOX_IDENTIFIER);
}
if (!$inboxId) {
  json_response(false, [
    'step' => 'find_inbox_id',
    'inbox_identifier' => $INBOX_IDENTIFIER,
  ], 'Não consegui resolver inbox_id numérico pelo identifier', 502);
}

// =====================
// 1) Find-or-Create Contact
// =====================
$identifier = $phoneDigits;
$contactId = find_contact_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $phoneDigits, $identifier);

if (!$contactId) {
  $r1 = create_contact($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $phoneDigits, $pushName, $identifier, $source);

  if (!$r1['ok'] && (int)$r1['code'] === 422) {
    $contactId = find_contact_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $phoneDigits, $identifier);
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
// 2) Find-or-Create Conversation
// =====================
$sourceId = 'waha:' . $phoneDigits;
$conversationId = find_conversation_id($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $inboxId, $sourceId);

if (!$conversationId) {
  $r2 = create_conversation($CHATWOOT_BASE, $ACCOUNT_ID, $headers, $inboxId, $contactId, $sourceId);

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

// Chatwoot aceita external_source_ids (depende da versão/build)
if ($externalId !== '') {
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
