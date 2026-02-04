<?php
declare(strict_types=1);

/**
 * Chatwoot -> CRM Webhook (PDO)
 * Rota: /api/chatwoot-webhook.php
 */

header('Content-Type: application/json; charset=utf-8');

/* ================= HELPER FUNCTIONS ================= */

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

function log_raw(string $raw): void {
    if (envv('CHATWOOT_WEBHOOK_LOG', '0') !== '1') return;
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir.'/chatwoot-webhook-raw.log', '['.date('Y-m-d H:i:s')."] ".$raw.PHP_EOL, FILE_APPEND);
}

function log_payload(array $payload): void {
    if (envv('CHATWOOT_WEBHOOK_LOG', '0') !== '1') return;
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/chatwoot-webhook.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND);
}

function normalize_phone(?string $phone): ?string {
    if (!$phone) return null;
    $digits = preg_replace('/\D+/', '', $phone);
    return $digits ?: null;
}

function safe_str($v): ?string {
    if ($v === null) return null;
    if (is_string($v)) return trim($v);
    if (is_numeric($v)) return (string)$v;
    return null;
}

/* ================= DB / PDO ================= */

function load_pdo(): PDO {
    $path = __DIR__ . '/../db.php';
    if (!file_exists($path)) throw new RuntimeException('db.php não encontrado');
    require_once $path;
    if (!function_exists('get_pdo')) throw new RuntimeException('Função get_pdo() não existe');
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function find_client_id(PDO $pdo, ?string $phoneDigits, ?string $email): ?int {
    $email = $email ? strtolower(trim($email)) : null;
    $tries = [
        ['clients', ['phone', 'whatsapp', 'telefone', 'celular']],
        ['client',  ['phone', 'whatsapp', 'telefone', 'celular']],
    ];
    foreach ($tries as [$table, $phoneCols]) {
        if ($email) {
            try {
                $st = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(email)=:email LIMIT 1");
                $st->execute([':email' => $email]);
                $row = $st->fetch();
                if ($row) return (int)$row['id'];
            } catch (Throwable $e) {}
        }
        if ($phoneDigits) {
            foreach ($phoneCols as $col) {
                try {
                    $sql = "SELECT id FROM {$table} WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$col},'(',''),')',''),'-',''),' ',''),'+',''),'.','') LIKE :p LIMIT 1";
                    $st = $pdo->prepare($sql);
                    $st->execute([':p' => '%' . $phoneDigits]);
                    $row = $st->fetch();
                    if ($row) return (int)$row['id'];
                } catch (Throwable $e) {}
            }
        }
    }
    return null;
}

/* ================= UPSERTS ================= */

function upsert_inbox(PDO $pdo, int $accountId, int $inboxId, ?string $name, ?string $channelType): void {
    $sql = "INSERT INTO chatwoot_inboxes (chatwoot_account_id, chatwoot_inbox_id, name, channel_type)
            VALUES (:acc, :inbox, :name, :ctype)
            ON DUPLICATE KEY UPDATE name = VALUES(name), channel_type = VALUES(channel_type)";
    $pdo->prepare($sql)->execute([':acc' => $accountId, ':inbox' => $inboxId, ':name' => $name, ':ctype' => $channelType]);
}

function upsert_contact_map(PDO $pdo, int $accountId, int $contactId, ?int $clientId, ?string $phoneDigits, ?string $email): void {
    $sql = "INSERT INTO chatwoot_contact_map (client_id, chatwoot_contact_id, chatwoot_account_id, phone, email)
            VALUES (:client_id, :contact_id, :acc, :phone, :email)
            ON DUPLICATE KEY UPDATE 
                client_id = COALESCE(VALUES(client_id), client_id),
                phone = COALESCE(VALUES(phone), phone),
                email = COALESCE(VALUES(email), email)";
    $pdo->prepare($sql)->execute([':client_id' => $clientId, ':contact_id' => $contactId, ':acc' => $accountId, ':phone' => $phoneDigits, ':email' => $email]);
}

function upsert_conversation_map(PDO $pdo, int $accountId, int $conversationId, ?int $inboxId, ?int $clientId, ?string $status, ?string $lastMessageAt): void {
    $sql = "INSERT INTO chatwoot_conversation_map (chatwoot_account_id, chatwoot_conversation_id, chatwoot_inbox_id, client_id, status, last_message_at)
            VALUES (:acc, :conv, :inbox, :client_id, :status, :last_msg)
            ON DUPLICATE KEY UPDATE 
                chatwoot_inbox_id = COALESCE(VALUES(chatwoot_inbox_id), chatwoot_inbox_id),
                client_id = COALESCE(VALUES(client_id), client_id),
                status = COALESCE(VALUES(status), status),
                last_message_at = COALESCE(VALUES(last_message_at), last_message_at)";
    $pdo->prepare($sql)->execute([':acc' => $accountId, ':conv' => $conversationId, ':inbox' => $inboxId, ':client_id' => $clientId, ':status' => $status, ':last_msg' => $lastMessageAt]);
}

function upsert_message(PDO $pdo, int $accountId, int $conversationId, int $messageId, string $direction, ?string $content, ?string $contentType, ?string $senderName, ?string $senderType, ?string $createdAtChatwoot): void {
    $sql = "INSERT INTO chatwoot_messages (chatwoot_account_id, chatwoot_conversation_id, chatwoot_message_id, direction, content, content_type, sender_name, sender_type, created_at_chatwoot)
            VALUES (:acc, :conv, :msg, :dir, :content, :ctype, :sname, :stype, :created_at_cw)
            ON DUPLICATE KEY UPDATE 
                direction = VALUES(direction), content = COALESCE(VALUES(content), content), sender_name = COALESCE(VALUES(sender_name), sender_name)";
    $pdo->prepare($sql)->execute([':acc' => $accountId, ':conv' => $conversationId, ':msg' => $messageId, ':dir' => $direction, ':content' => $content, ':ctype' => $contentType, ':sname' => $senderName, ':stype' => $senderType, ':created_at_cw' => $createdAtChatwoot]);
}

/* ================= MAIN FLOW ================= */

// 1. Healthcheck
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    json_response(true, ['status' => 'ok', 'hint' => 'use POST'], null, 200);
}

// 2. Captura Raw e Log
$raw = file_get_contents('php://input');
log_raw((string)$raw);

// 3. Decode e Validação
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    json_response(false, null, 'Invalid JSON payload', 400);
}

/**
 * ✅ Normalização: Suporta formato raiz e formato com chave ['payload']
 */
if (isset($payload['payload']) && is_array($payload['payload'])) {
    $payload = array_merge($payload, $payload['payload']);
}

log_payload($payload);

try {
    $pdo = load_pdo();
} catch (Throwable $e) {
    json_response(false, null, $e->getMessage(), 500);
}

$event = safe_str($payload['event'] ?? $payload['event_name'] ?? null) ?? 'unknown';

// Resolve Account ID
$accountId = (int)($payload['account']['id'] ?? $payload['account_id'] ?? $payload['conversation']['account_id'] ?? 1);

$conversation = $payload['conversation'] ?? null;
$message      = $payload['message'] ?? null;
$contact      = $payload['contact'] ?? ($conversation['contact'] ?? null);
$inbox        = $payload['inbox'] ?? ($conversation['inbox'] ?? null);

// --- Processamento de Inbox ---
$inboxId = null;
if (is_array($inbox) && isset($inbox['id'])) {
    $inboxId = (int)$inbox['id'];
    upsert_inbox($pdo, $accountId, $inboxId, safe_str($inbox['name']), safe_str($inbox['channel_type'] ?? $inbox['channel']));
}

// --- Processamento de Contato/Cliente ---
$contactId = null; $phoneDigits = null; $email = null; $clientId = null;
if (is_array($contact) && isset($contact['id'])) {
    $contactId = (int)$contact['id'];
    $phoneDigits = normalize_phone(safe_str($contact['phone_number'] ?? $contact['phone']));
    $email = safe_str($contact['email']);
    $clientId = find_client_id($pdo, $phoneDigits, $email);
    upsert_contact_map($pdo, $accountId, $contactId, $clientId, $phoneDigits, $email);
}

// --- Processamento de Conversa ---
$conversationId = null;
if (is_array($conversation) && isset($conversation['id'])) {
    $conversationId = (int)$conversation['id'];
    $lastMsgAt = isset($conversation['last_activity_at']) ? date('Y-m-d H:i:s', (int)$conversation['last_activity_at']) : safe_str($conversation['last_message_at']);
    upsert_conversation_map($pdo, $accountId, $conversationId, $inboxId, $clientId, safe_str($conversation['status']), $lastMsgAt);
}

// --- Processamento de Mensagem ---
if (is_array($message) && isset($message['id'])) {
    $direction = ((int)($message['message_type'] ?? 0) === 1) ? 'outgoing' : 'incoming';
    $sender = $message['sender'] ?? null;
    $createdAt = isset($message['created_at']) && is_numeric($message['created_at']) ? date('Y-m-d H:i:s', (int)$message['created_at']) : safe_str($message['created_at']);
    
    upsert_message(
        $pdo, $accountId, (int)($conversationId ?? $message['conversation_id']), 
        (int)$message['id'], $direction, safe_str($message['content']), 
        safe_str($message['content_type']), 
        is_array($sender) ? safe_str($sender['name'] ?? $sender['available_name']) : null,
        is_array($sender) ? safe_str($sender['type'] ?? $sender['role']) : null,
        $createdAt
    );
}

json_response(true, ['event' => $event, 'account_id' => $accountId, 'conversation_id' => $conversationId]);