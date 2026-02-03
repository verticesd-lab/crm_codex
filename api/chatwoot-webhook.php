<?php
declare(strict_types=1);

/**
 * Chatwoot -> CRM Webhook (PDO)
 * Rota: /api/chatwoot-webhook.php
 *
 * Requisitos:
 * - Tabelas: chatwoot_inboxes, chatwoot_contact_map, chatwoot_conversation_map, chatwoot_messages
 * - PDO disponível no projeto
 *
 * Segurança:
 * - Envie header: Authorization: Bearer SEU_TOKEN
 * - Defina o token via ENV: CHATWOOT_WEBHOOK_TOKEN
 *
 * Log (opcional):
 * - ENV CHATWOOT_WEBHOOK_LOG=1 para salvar payload em /logs/chatwoot-webhook.log
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

function env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  if ($v === false || $v === '') return $default;
  return $v;
}

function get_bearer_token(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h) return null;
  if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
  return null;
}

function safe_str($v): ?string {
  if ($v === null) return null;
  if (is_string($v)) return trim($v);
  if (is_numeric($v)) return (string)$v;
  return null;
}

function normalize_phone(?string $phone): ?string {
  if (!$phone) return null;
  // Mantém dígitos
  $digits = preg_replace('/\D+/', '', $phone);
  if (!$digits) return null;

  // Normalização simples BR:
  // - remove 55 duplicado se vier "5555..."
  // - mantém com DDI se vier completo
  // Você pode adaptar depois conforme seu padrão.
  return $digits;
}

function log_payload(array $payload): void {
  if (env('CHATWOOT_WEBHOOK_LOG', '0') !== '1') return;
  $dir = __DIR__ . '/../logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = $dir . '/chatwoot-webhook.log';
  $line = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  @file_put_contents($file, $line, FILE_APPEND);
}

/**
 * Carrega PDO do seu projeto
 * Ajuste automático tentando arquivos comuns do seu repo.
 */
function load_pdo(): PDO {
  $candidates = [
    __DIR__ . '/../db.pdo',
    __DIR__ . '/../includes/db.pdo',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../db.php',
  ];

  foreach ($candidates as $path) {
    if (!file_exists($path)) continue;

    // Caso você tenha um arquivo que retorne $pdo
    require_once $path;

    // Se o arquivo definir $pdo global:
    if (isset($pdo) && $pdo instanceof PDO) {
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      return $pdo;
    }

    // Se o arquivo definir uma função get_pdo()
    if (function_exists('get_pdo')) {
      $p = get_pdo();
      if ($p instanceof PDO) {
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $p;
      }
    }
  }

  throw new RuntimeException('Não encontrei o PDO. Ajuste o load_pdo() para apontar para o seu arquivo de conexão.');
}

/**
 * Tenta achar client_id no seu CRM por telefone/email.
 * Como seu schema pode variar, eu deixei tolerante:
 * - tenta tabela "clients" com colunas comuns
 * - se falhar, retorna null
 */
function find_client_id(PDO $pdo, ?string $phoneDigits, ?string $email): ?int {
  $email = $email ? strtolower(trim($email)) : null;

  // Ajuste se seu CRM usa outra tabela/colunas.
  $tries = [
    // (table, phone columns)
    ['clients', ['phone', 'whatsapp', 'telefone', 'celular']],
    ['client',  ['phone', 'whatsapp', 'telefone', 'celular']],
  ];

  foreach ($tries as [$table, $phoneCols]) {
    // tenta por email primeiro (mais exato)
    if ($email) {
      try {
        $sql = "SELECT id FROM {$table} WHERE LOWER(email)=:email LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':email' => $email]);
        $row = $st->fetch();
        if ($row && isset($row['id'])) return (int)$row['id'];
      } catch (Throwable $e) {
        // ignora e tenta próximos
      }
    }

    // tenta por telefone (pode estar salvo com máscara ou só dígitos)
    if ($phoneDigits) {
      foreach ($phoneCols as $col) {
        try {
          // remove máscara do banco “na marra” comparando só dígitos
          // Obs: isso é mais pesado; para produção, ideal é salvar phone_normalized em coluna separada.
          $sql = "SELECT id FROM {$table}
                  WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$col},'(',''),')',''),'-',''),' ',''),'+',''),'.','') LIKE :p
                  LIMIT 1";
          $st = $pdo->prepare($sql);
          $st->execute([':p' => '%' . $phoneDigits]);
          $row = $st->fetch();
          if ($row && isset($row['id'])) return (int)$row['id'];
        } catch (Throwable $e) {
          // ignora e tenta próximos
        }
      }
    }
  }

  return null;
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

function upsert_contact_map(PDO $pdo, int $accountId, int $contactId, ?int $clientId, ?string $phoneDigits, ?string $email): void {
  $sql = "INSERT INTO chatwoot_contact_map (client_id, chatwoot_contact_id, chatwoot_account_id, phone, email)
          VALUES (:client_id, :contact_id, :acc, :phone, :email)
          ON DUPLICATE KEY UPDATE
            client_id = COALESCE(VALUES(client_id), client_id),
            phone = COALESCE(VALUES(phone), phone),
            email = COALESCE(VALUES(email), email)";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':client_id' => $clientId,
    ':contact_id' => $contactId,
    ':acc' => $accountId,
    ':phone' => $phoneDigits,
    ':email' => $email,
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

$expectedToken = env('CHATWOOT_WEBHOOK_TOKEN');
if ($expectedToken) {
  $got = get_bearer_token();
  if (!$got || !hash_equals($expectedToken, $got)) {
    json_response(false, null, 'Unauthorized', 401);
  }
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
  json_response(false, null, 'Invalid JSON payload', 400);
}

log_payload($payload);

try {
  $pdo = load_pdo();
} catch (Throwable $e) {
  json_response(false, null, $e->getMessage(), 500);
}

$event = safe_str($payload['event'] ?? $payload['event_name'] ?? null) ?? 'unknown';

// Tenta extrair “account_id”
$accountId = (int)($payload['account']['id']
  ?? $payload['account_id']
  ?? $payload['conversation']['account_id']
  ?? $payload['message']['account_id']
  ?? 0);

if ($accountId <= 0) {
  // Chatwoot quase sempre manda, mas se não vier, não aborta totalmente
  $accountId = 1;
}

// Inbox / Contact / Conversation / Message (tolerante)
$conversation = $payload['conversation'] ?? null;
$message = $payload['message'] ?? null;
$contact = $payload['contact'] ?? ($conversation['contact'] ?? null);
$inbox = $payload['inbox'] ?? ($conversation['inbox'] ?? null);

// Inbox
$inboxId = null;
if (is_array($inbox) && isset($inbox['id'])) {
  $inboxId = (int)$inbox['id'];
  $inboxName = safe_str($inbox['name'] ?? null);
  $channelType = safe_str($inbox['channel_type'] ?? $inbox['channel'] ?? null);
  try {
    upsert_inbox($pdo, $accountId, $inboxId, $inboxName, $channelType);
  } catch (Throwable $e) {
    // não aborta o webhook por falha no nome/canal
  }
}

// Contact
$contactId = null;
$phoneDigits = null;
$email = null;
$clientId = null;

if (is_array($contact) && isset($contact['id'])) {
  $contactId = (int)$contact['id'];
  $phoneDigits = normalize_phone(safe_str($contact['phone_number'] ?? $contact['phone'] ?? null));
  $email = safe_str($contact['email'] ?? null);

  // tenta mapear client_id no CRM
  try {
    $clientId = find_client_id($pdo, $phoneDigits, $email);
  } catch (Throwable $e) {
    $clientId = null;
  }

  try {
    upsert_contact_map($pdo, $accountId, $contactId, $clientId, $phoneDigits, $email);
  } catch (Throwable $e) {
    // segue
  }
}

// Conversation
$conversationId = null;
if (is_array($conversation) && isset($conversation['id'])) {
  $conversationId = (int)$conversation['id'];

  // status pode ser "open/pending/resolved" etc
  $status = safe_str($conversation['status'] ?? null);

  // last_activity_at costuma vir epoch; last_message_at às vezes vem como string
  $lastMessageAt = null;
  if (isset($conversation['last_activity_at']) && is_numeric($conversation['last_activity_at'])) {
    $lastMessageAt = date('Y-m-d H:i:s', (int)$conversation['last_activity_at']);
  } elseif (isset($conversation['last_message_at']) && is_numeric($conversation['last_message_at'])) {
    $lastMessageAt = date('Y-m-d H:i:s', (int)$conversation['last_message_at']);
  } else {
    $lastMessageAt = safe_str($conversation['last_message_at'] ?? null);
  }

  try {
    upsert_conversation_map(
      $pdo,
      $accountId,
      $conversationId,
      $inboxId ? (int)$inboxId : null,
      $clientId ? (int)$clientId : null,
      $status,
      $lastMessageAt
    );
  } catch (Throwable $e) {
    // segue
  }
}

// Message
if (is_array($message) && isset($message['id'])) {
  $msgId = (int)$message['id'];

  // direction: incoming/outgoing
  $direction = safe_str($message['message_type'] ?? null);
  if (!$direction) {
    // fallback: message_type no Chatwoot pode ser "incoming" / "outgoing"
    $direction = safe_str($message['direction'] ?? null) ?? 'incoming';
  }

  // conteúdo
  $content = safe_str($message['content'] ?? null);

  // tipo
  $contentType = safe_str($message['content_type'] ?? null);

  // sender
  $sender = $message['sender'] ?? null;
  $senderName = is_array($sender) ? safe_str($sender['name'] ?? $sender['available_name'] ?? null) : null;
  $senderType = is_array($sender) ? safe_str($sender['type'] ?? $sender['role'] ?? null) : null;

  // created_at
  $createdAtChatwoot = null;
  if (isset($message['created_at']) && is_numeric($message['created_at'])) {
    $createdAtChatwoot = date('Y-m-d H:i:s', (int)$message['created_at']);
  } elseif (isset($message['created_at'])) {
    $createdAtChatwoot = safe_str($message['created_at']);
  }

  // conversa pode estar dentro da message ou no payload
  if (!$conversationId && isset($message['conversation_id'])) {
    $conversationId = (int)$message['conversation_id'];
  }
  if (!$conversationId) {
    // não dá pra salvar mensagem sem conversa
    json_response(true, ['event' => $event, 'saved' => false, 'reason' => 'message sem conversation_id'], null, 200);
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
  'contact_id' => $contactId,
  'conversation_id' => $conversationId,
  'client_id' => $clientId,
], null, 200);
