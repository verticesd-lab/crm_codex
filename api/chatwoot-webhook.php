<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

function json_response(bool $ok, $data=null, ?string $error=null, int $http=200): void {
  http_response_code($http);
  echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function envv(string $k, ?string $d=null): ?string {
  $v = getenv($k);
  if ($v === false || $v === '') return $d;
  return $v;
}
function get_bearer(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h) return null;
  if (stripos($h,'Bearer ') === 0) return trim(substr($h,7));
  return null;
}
function auth_or_401(): void {
  $expected = envv('CHATWOOT_WEBHOOK_TOKEN');
  if (!$expected) return; // sem token = aberto
  $q = (string)($_GET['token'] ?? '');
  $b = (string)(get_bearer() ?? '');
  $ok = ($q && hash_equals($expected, $q)) || ($b && hash_equals($expected, $b));
  if (!$ok) json_response(false, null, 'Unauthorized', 401);
}
function safe_str($v): ?string {
  if ($v === null) return null;
  if (is_string($v)) return trim($v);
  if (is_numeric($v)) return (string)$v;
  return null;
}
function dt_from_epoch($v): ?string {
  if ($v === null) return null;
  if (is_numeric($v)) return gmdate('Y-m-d H:i:s', (int)$v);
  $s = safe_str($v);
  return $s ?: null;
}
function normalize_digits(?string $s): ?string {
  if (!$s) return null;
  $d = preg_replace('/\D+/', '', $s);
  return $d ?: null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
  json_response(true, ['status'=>'ok','hint'=>'use POST from Chatwoot'], null, 200);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_response(false, null, 'Method not allowed', 405);
}

auth_or_401();

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) json_response(false, null, 'Invalid JSON payload', 400);

$event = safe_str($payload['event'] ?? $payload['event_name'] ?? null) ?? 'unknown';

$accountId = (int)($payload['account']['id']
  ?? $payload['account_id']
  ?? $payload['conversation']['account_id']
  ?? $payload['message']['account_id']
  ?? 1);

$conversation = is_array($payload['conversation'] ?? null) ? $payload['conversation'] : null;
$message      = is_array($payload['message'] ?? null) ? $payload['message'] : null;
$contact      = is_array($payload['contact'] ?? null) ? $payload['contact'] : ($conversation['contact'] ?? null);
$inbox        = is_array($payload['inbox'] ?? null) ? $payload['inbox'] : ($conversation['inbox'] ?? null);

$convIdExt = $conversation['id'] ?? ($message['conversation_id'] ?? null);
$convIdExt = $convIdExt ? (string)$convIdExt : null;

$inboxId = isset($inbox['id']) ? (int)$inbox['id'] : null;

$contactPhone = normalize_digits(safe_str($contact['phone_number'] ?? $contact['phone'] ?? null));
$contactEmail = safe_str($contact['email'] ?? null);
$contactEmail = $contactEmail ? strtolower(trim($contactEmail)) : null;
$contactName  = safe_str($contact['name'] ?? $contact['available_name'] ?? null);

$status = safe_str($conversation['status'] ?? null) ?? 'open';

try {
  $pdo = get_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

  // 1) garante conversa interna (por map ou por phone/email)
  $convInternalId = null;

  if ($convIdExt) {
    $stMap = $pdo->prepare("SELECT conversation_id FROM atd_id_map WHERE source='chatwoot' AND external_conversation_id=:ext LIMIT 1");
    $stMap->execute([':ext'=>$convIdExt]);
    $m = $stMap->fetch(PDO::FETCH_ASSOC);
    if ($m && isset($m['conversation_id'])) $convInternalId = (int)$m['conversation_id'];
  }

  if (!$convInternalId) {
    // tenta achar por phone/email (último recurso)
    if ($contactPhone || $contactEmail) {
      $where = [];
      $p = [];
      if ($contactPhone) { $where[] = 'contact_phone = :phone'; $p[':phone']=$contactPhone; }
      else { $where[] = 'contact_email = :email'; $p[':email']=$contactEmail; }
      $stFind = $pdo->prepare("SELECT id FROM atd_conversations WHERE ".implode(' AND ', $where)." ORDER BY id DESC LIMIT 1");
      $stFind->execute($p);
      $r = $stFind->fetch(PDO::FETCH_ASSOC);
      if ($r && isset($r['id'])) $convInternalId = (int)$r['id'];
    }
  }

  if (!$convInternalId) {
    $stIns = $pdo->prepare("
      INSERT INTO atd_conversations (company_id, inbox_id, contact_phone, contact_email, contact_name, status, last_message_at)
      VALUES (NULL, :inbox_id, :phone, :email, :name, :status, NULL)
    ");
    $stIns->execute([
      ':inbox_id'=>$inboxId,
      ':phone'=>$contactPhone,
      ':email'=>$contactEmail,
      ':name'=>$contactName,
      ':status'=>$status
    ]);
    $convInternalId = (int)$pdo->lastInsertId();
  } else {
    $stUp = $pdo->prepare("
      UPDATE atd_conversations
      SET inbox_id = COALESCE(:inbox_id, inbox_id),
          contact_name = COALESCE(:name, contact_name),
          contact_email = COALESCE(:email, contact_email),
          contact_phone = COALESCE(:phone, contact_phone),
          status = COALESCE(:status, status)
      WHERE id = :id
    ");
    $stUp->execute([
      ':inbox_id'=>$inboxId,
      ':name'=>$contactName,
      ':email'=>$contactEmail,
      ':phone'=>$contactPhone,
      ':status'=>$status,
      ':id'=>$convInternalId
    ]);
  }

  // 2) grava map source chatwoot (conversa externa -> interna)
  if ($convIdExt) {
    $stMapUp = $pdo->prepare("
      INSERT INTO atd_id_map (conversation_id, source, external_conversation_id, inbox_id, company_id)
      VALUES (:cid, 'chatwoot', :ext, :inbox_id, NULL)
      ON DUPLICATE KEY UPDATE
        conversation_id = VALUES(conversation_id),
        inbox_id = COALESCE(VALUES(inbox_id), inbox_id)
    ");
    $stMapUp->execute([
      ':cid'=>$convInternalId,
      ':ext'=>$convIdExt,
      ':inbox_id'=>$inboxId
    ]);
  }

  // 3) se tiver message, salva na timeline unificada
  if ($message && isset($message['id'])) {
    $msgExtId = (string)$message['id'];
    $content  = safe_str($message['content'] ?? null);
    $ctype    = safe_str($message['content_type'] ?? null) ?? 'text';
    $created  = dt_from_epoch($message['created_at'] ?? null);

    // Chatwoot: message_type costuma ser "incoming"/"outgoing"
    $dir = safe_str($message['message_type'] ?? $message['direction'] ?? null) ?? 'incoming';
    $dir = ($dir === 'outgoing') ? 'outgoing' : 'incoming';

    $sender = is_array($message['sender'] ?? null) ? $message['sender'] : null;
    $senderName = $sender ? safe_str($sender['name'] ?? $sender['available_name'] ?? null) : null;

    $stMsg = $pdo->prepare("
      INSERT INTO atd_messages
        (conversation_id, source, direction, external_message_id, external_conversation_id, sender_name, content, content_type, created_at_external)
      VALUES
        (:conv, 'chatwoot', :dir, :msgid, :convid, :sender, :content, :ctype, :created_at)
      ON DUPLICATE KEY UPDATE
        content = COALESCE(VALUES(content), content),
        sender_name = COALESCE(VALUES(sender_name), sender_name),
        created_at_external = COALESCE(VALUES(created_at_external), created_at_external)
    ");
    $stMsg->execute([
      ':conv'=>$convInternalId,
      ':dir'=>$dir,
      ':msgid'=>$msgExtId,
      ':convid'=>$convIdExt,
      ':sender'=>$senderName,
      ':content'=>$content,
      ':ctype'=>$ctype,
      ':created_at'=>$created
    ]);

    $stLast = $pdo->prepare("UPDATE atd_conversations SET last_message_at = COALESCE(:dt, NOW()) WHERE id=:id");
    $stLast->execute([':dt'=>$created, ':id'=>$convInternalId]);
  }

  $pdo->commit();

  json_response(true, [
    'event'=>$event,
    'conversation_id'=>$convInternalId,
    'chatwoot_conversation_id'=>$convIdExt,
    'saved'=>true
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_response(false, null, 'Erro: '.$e->getMessage(), 500);
}
