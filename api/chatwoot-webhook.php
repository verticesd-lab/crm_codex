<?php
declare(strict_types=1);

/**
 * Chatwoot -> CRM Webhook (PDO)
 * Rota: /api/chatwoot-webhook.php
 */

header('Content-Type: application/json; charset=utf-8');

/* ===========================
   HELPERS BÁSICOS
=========================== */

function json_response(bool $ok, $data = null, ?string $error = null, int $http = 200): void {
  http_response_code($http);
  echo json_encode([
    'ok' => $ok,
    'data' => $data,
    'error' => $error,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  return ($v === false || $v === '') ? $default : $v;
}

function safe_str($v): ?string {
  if ($v === null) return null;
  if (is_string($v)) return trim($v);
  if (is_numeric($v)) return (string)$v;
  return null;
}

function normalize_phone(?string $phone): ?string {
  if (!$phone) return null;
  $digits = preg_replace('/\D+/', '', $phone);
  return $digits ?: null;
}

function log_payload(array $payload): void {
  if (env('CHATWOOT_WEBHOOK_LOG', '0') !== '1') return;
  $dir = __DIR__ . '/../logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents(
    $dir . '/chatwoot-webhook.log',
    '[' . date('Y-m-d H:i:s') . '] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND
  );
}

/* ===========================
   SEGURANÇA (CORRIGIDA)
=========================== */

$secret = env('CHATWOOT_WEBHOOK_SECRET');
$signature = $_SERVER['HTTP_X_CHATWOOT_SIGNATURE'] ?? '';

if ($secret && (!$signature || !hash_equals($secret, $signature))) {
  json_response(false, null, 'Unauthorized', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(false, null, 'Method not allowed', 405);
}

/* ===========================
   PAYLOAD
=========================== */

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
  json_response(false, null, 'Invalid JSON', 400);
}

log_payload($payload);

/* ===========================
   PDO
=========================== */

require_once __DIR__ . '/../db.php';
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ===========================
   EXTRAÇÃO
=========================== */

$event = safe_str($payload['event'] ?? $payload['event_name'] ?? 'unknown');

$accountId = (int)($payload['account']['id']
  ?? $payload['conversation']['account_id']
  ?? 1);

$conversation = $payload['conversation'] ?? null;
$message      = $payload['message'] ?? null;
$contact      = $payload['contact'] ?? ($conversation['contact'] ?? null);
$inbox        = $payload['inbox'] ?? ($conversation['inbox'] ?? null);

/* ===========================
   INBOX
=========================== */

$inboxId = null;
if (is_array($inbox) && isset($inbox['id'])) {
  $inboxId = (int)$inbox['id'];

  $pdo->prepare(
    "INSERT INTO chatwoot_inboxes (chatwoot_account_id, chatwoot_inbox_id, name, channel_type)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name=VALUES(name), channel_type=VALUES(channel_type)"
  )->execute([
    $accountId,
    $inboxId,
    safe_str($inbox['name'] ?? null),
    safe_str($inbox['channel_type'] ?? null),
  ]);
}

/* ===========================
   CONTACT
=========================== */

$contactId = null;
$clientId  = null;
$phone     = null;
$email     = null;

if (is_array($contact) && isset($contact['id'])) {
  $contactId = (int)$contact['id'];
  $phone = normalize_phone(safe_str($contact['phone_number'] ?? null));
  $email = safe_str($contact['email'] ?? null);

  $pdo->prepare(
    "INSERT INTO chatwoot_contact_map (chatwoot_account_id, chatwoot_contact_id, phone, email)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE phone=VALUES(phone), email=VALUES(email)"
  )->execute([$accountId, $contactId, $phone, $email]);
}

/* ===========================
   CONVERSATION
=========================== */

$conversationId = null;

if (is_array($conversation) && isset($conversation['id'])) {
  $conversationId = (int)$conversation['id'];

  $pdo->prepare(
    "INSERT INTO chatwoot_conversation_map
     (chatwoot_account_id, chatwoot_conversation_id, chatwoot_inbox_id, status)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE status=VALUES(status)"
  )->execute([
    $accountId,
    $conversationId,
    $inboxId,
    safe_str($conversation['status'] ?? null),
  ]);
}

/* ===========================
   MESSAGE
=========================== */

if (is_array($message) && isset($message['id'])) {

  $msgId = (int)$message['id'];

  // NORMALIZA DIREÇÃO
  $direction = ((int)($message['message_type'] ?? 0) === 1)
    ? 'outgoing'
    : 'incoming';

  $pdo->prepare(
    "INSERT INTO chatwoot_messages
     (chatwoot_account_id, chatwoot_conversation_id, chatwoot_message_id,
      direction, content, sender_name, sender_type, created_at_chatwoot)
     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE content=VALUES(content)"
  )->execute([
    $accountId,
    $conversationId ?? (int)($message['conversation_id'] ?? 0),
    $msgId,
    $direction,
    safe_str($message['content'] ?? null),
    safe_str($message['sender']['name'] ?? null),
    safe_str($message['sender']['type'] ?? null),
  ]);
}

json_response(true, [
  'event' => $event,
  'conversation_id' => $conversationId,
], null, 200);
