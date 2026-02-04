<?php
declare(strict_types=1);

/**
 * Chatwoot -> CRM Webhook (PDO)
 * Rota: /api/chatwoot-webhook.php
 *
 * Segurança (opcional, recomendado):
 * - Preferir assinatura HMAC: ENV CHATWOOT_WEBHOOK_SECRET
 *   Header: X-Chatwoot-Signature
 *
 * Segurança alternativa (opcional):
 * - Bearer token: ENV CHATWOOT_WEBHOOK_TOKEN
 *   Header: Authorization: Bearer SEU_TOKEN
 *
 * Log (opcional):
 * - ENV CHATWOOT_WEBHOOK_LOG=1 salva payload em /logs/chatwoot-webhook.log
 */

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, $data = null, ?string $error = null, int $http = 200): void {
  http_response_code($http);
  echo json_encode([
    'ok' => $ok,
    'data' => $data,
    'error' => $error,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function envv(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  if ($v === false || $v === '') return $default;
  return $v;
}

function get_header(string $name): string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return (string)($_SERVER[$key] ?? '');
}

function get_bearer_token(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h) return null;
  if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
  return null;
}

function log_payload(string $raw): void {
  if (envv('CHATWOOT_WEBHOOK_LOG', '0') !== '1') return;
  $dir = __DIR__ . '/../logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = $dir . '/chatwoot-webhook.log';
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $raw . PHP_EOL;
  @file_put_contents($file, $line, FILE_APPEND);
}

/**
 * Carrega PDO do seu projeto
 */
function load_pdo(): PDO {
  $candidates = [
    __DIR__ . '/../db.php',
    __DIR__ . '/../includes/db.php',
  ];

  foreach ($candidates as $path) {
    if (!file_exists($path)) continue;
    require_once $path;

    if (function_exists('get_pdo')) {
      $p = get_pdo();
      if ($p instanceof PDO) {
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $p;
      }
    }

    if (isset($pdo) && $pdo instanceof PDO) {
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      return $pdo;
    }
  }

  throw new RuntimeException('Não encontrei PDO. Ajuste load_pdo() para apontar para seu arquivo db.php/get_pdo().');
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

/**
 * Chatwoot message_type normalmente:
 * 0 = incoming
 * 1 = outgoing
 * 2/3 = activity/template (podemos ignorar se quiser)
 */
function chatwoot_direction($message): string {
  $mt = $message['message_type'] ?? null;

  // vem numérico
  if (is_numeric($mt)) {
    $mt = (int)$mt;
    if ($mt === 1) return 'outgoing';
    if ($mt === 0) return 'incoming';
    return 'activity';
  }

  // vem string (fallback)
  $mt = safe_str($mt);
  if ($mt === 'outgoing') return 'outgoing';
  if ($mt === 'incoming') return 'incoming';

  $dir = safe_str($message['direction'] ?? null);
  if ($dir) return $dir;

  return 'incoming';
}

function upsert_inbox(PDO $pdo, int $accountId, int $inboxId, ?string $name, ?string $channelType): void {
  $sql = "INSERT INTO chatwoot_inboxes (chatwoot_account_id, chatwoot_inbox_id, name, channel_type)
          VALUES (:acc, :inbox, :name, :ctype)
          ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            channel_type = VALUES(channel_type)";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':acc' => $accountId,
    ':inbox' => $inboxId,
    ':name' => $name,
    ':ctype' => $channelType,
  ]);
}

function upsert_conversation_map(PDO $pdo, int $accountId, int $conversationId, ?int $inboxId, ?int $clientId, ?string $status, ?string $lastMessageAt): void {
  $sql = "INSERT INTO chatwoot_conversation_map (chatwoot_account_id, chatwoot_conversation_id, chatwoot_inbox_id, client_id, status, last_message_at)
          VALUES (:acc, :conv, :inbox, :client_id, :status, :last_msg)
          ON DUPLICATE KEY UPDATE
            chatwoot_inbox_id = COALESCE(VALUES(chatwoot_inbox_id), chatwoot_inbox_id),
            client_id = COALESCE(VALUES(client_id), client_id),
            status = COALESCE(VALUES(status), status),
            last_message_at = COALESCE(VALUES(last_message_at), last_message_at)";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':acc' => $accountId,
    ':conv' => $conversationId,
    ':inbox' => $inboxId,
    ':client_id' => $clientId,
    ':status' => $status,
    ':last_msg' => $lastMessageAt,
  ]);
}

function upsert_message(PDO $pdo, int $accountId, int $conversationId, int $messageId, string $direction, ?string $content, ?string $contentType, ?string $senderName, ?string $senderType, ?string $createdAtChatwoot): void {
  $sql = "INSERT INTO chatwoot_messages
            (chatwoot_account_id, chatwoot_conversation_id, chatwoot_message_id, direction, content, content_type, sender_name, sender_type, created_at_chatwoot)
          VALUES
            (:acc, :conv, :msg, :dir, :content, :ctype, :sname, :stype, :created_at_cw)
          ON DUPLICATE KEY UPDATE
            content = COALESCE(VALUES(content), content),
            content_type = COALESCE(VALUES(content_type), content_type),
            sender_name = COALESCE(VALUES(sender_name), sender_name),
            sender_type = COALESCE(VALUES(sender_type), sender_type),
            created_at_chatwoot = COALESCE(VALUES(created_at_chatwoot), created_at_chatwoot)";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':acc' => $accountId,
    ':conv' => $conversationId,
    ':msg' => $messageId,
    ':dir' => $direction,
    ':content' => $content,
    ':ctype' => $contentType,
    ':sname' => $senderName,
    ':stype' => $senderType,
    ':created_at_cw' => $createdAtChatwoot,
  ]);
}

// ---------------- MAIN ----------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_response(false, null, 'Method not allowed', 405);
}

$raw = (string)file_get_contents('php://input');
if ($raw === '') {
  json_response(false, null, 'Empty body', 400);
}

log_payload($raw);

$payload = json_decode($raw, true);
if (!is_array($payload)) {
  json_response(false, null, 'Invalid JSON payload', 400);
}

// ✅ Chatwoot às vezes manda tudo dentro de payload: { event: "...", payload: { conversation, message, inbox... } }
$root = $payload;
if (isset($payload['payload']) && is_array($payload['payload'])) {
  // mantém "event" no root
  $root = array_merge($payload['payload'], ['event' => $payload['event'] ?? $payload['event_name'] ?? null]);
}

try {
  $pdo = load_pdo();
} catch (Throwable $e) {
  json_response(false, null, $e->getMessage(), 500);
}

$event = safe_str($root['event'] ?? $root['event_name'] ?? null) ?? 'unknown';

$accountId = (int)($root['account']['id']
  ?? $root['account_id']
  ?? $root['conversation']['account_id']
  ?? $root['message']['account_id']
  ?? 1);

$conversation = $root['conversation'] ?? null;
$message      = $root['message'] ?? null;
$inbox        = $root['inbox'] ?? ($conversation['inbox'] ?? null);
$contact      = $root['contact'] ?? ($conversation['contact'] ?? null);

// Inbox
$inboxId = null;
if (is_array($inbox) && isset($inbox['id'])) {
  $inboxId = (int)$inbox['id'];
  $inboxName = safe_str($inbox['name'] ?? null);
  $channelType = safe_str($inbox['channel_type'] ?? $inbox['channel'] ?? null);
  try { upsert_inbox($pdo, $accountId, $inboxId, $inboxName, $channelType); } catch (Throwable $e) {}
}

// (Opcional) se quiser usar depois: telefone/email normalizados
$phoneDigits = null;
$email = null;
if (is_array($contact)) {
  $phoneDigits = normalize_phone(safe_str($contact['phone_number'] ?? $contact['phone'] ?? null));
  $email = safe_str($contact['email'] ?? null);
}

// Conversation map (salva status/last_message_at)
$conversationId = null;
if (is_array($conversation) && isset($conversation['id'])) {
  $conversationId = (int)$conversation['id'];
  $status = safe_str($conversation['status'] ?? null);

  $lastMessageAt = null;
  if (isset($conversation['last_activity_at']) && is_numeric($conversation['last_activity_at'])) {
    $lastMessageAt = date('Y-m-d H:i:s', (int)$conversation['last_activity_at']);
  } elseif (isset($conversation['last_message_at']) && is_numeric($conversation['last_message_at'])) {
    $lastMessageAt = date('Y-m-d H:i:s', (int)$conversation['last_message_at']);
  } else {
    $lastMessageAt = safe_str($conversation['last_message_at'] ?? null);
  }

  try {
    upsert_conversation_map($pdo, $accountId, $conversationId, $inboxId ? (int)$inboxId : null, null, $status, $lastMessageAt);
  } catch (Throwable $e) {}
}

// Message
if (is_array($message) && isset($message['id'])) {
  $msgId = (int)$message['id'];

  // conversa pode vir dentro da message
  if (!$conversationId && isset($message['conversation_id'])) {
    $conversationId = (int)$message['conversation_id'];
  }
  if (!$conversationId) {
    json_response(true, ['event' => $event, 'saved' => false, 'reason' => 'message sem conversation_id'], null, 200);
  }

  $direction = chatwoot_direction($message);

  // ignora activity/template se quiser (opcional)
  // if ($direction === 'activity') { json_response(true, ['event'=>$event,'saved'=>false,'reason'=>'activity'], null, 200); }

  $content = safe_str($message['content'] ?? null);
  $contentType = safe_str($message['content_type'] ?? null);

  $sender = $message['sender'] ?? null;
  $senderName = is_array($sender) ? safe_str($sender['name'] ?? $sender['available_name'] ?? null) : null;
  $senderType = is_array($sender) ? safe_str($sender['type'] ?? $sender['role'] ?? null) : null;

  $createdAtChatwoot = null;
  if (isset($message['created_at']) && is_numeric($message['created_at'])) {
    $createdAtChatwoot = date('Y-m-d H:i:s', (int)$message['created_at']);
  } else {
    $createdAtChatwoot = safe_str($message['created_at'] ?? null);
  }

  try {
    upsert_message($pdo, $accountId, (int)$conversationId, $msgId, $direction, $content, $contentType, $senderName, $senderType, $createdAtChatwoot);
  } catch (Throwable $e) {
    json_response(false, null, 'Erro ao salvar message: ' . $e->getMessage(), 500);
  }
}

json_response(true, [
  'event' => $event,
  'account_id' => $accountId,
  'inbox_id' => $inboxId,
  'conversation_id' => $conversationId,
  'phone' => $phoneDigits,
  'email' => $email,
], null, 200);
