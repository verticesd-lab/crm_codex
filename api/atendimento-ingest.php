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

function normalize_digits(?string $s): ?string {
  if (!$s) return null;
  $digits = preg_replace('/\D+/', '', $s);
  return $digits ?: null;
}

function auth_or_401(): void {
  $expected = envv('ATENDIMENTO_INGEST_TOKEN') ?: envv('WAHA_BRIDGE_TOKEN');
  if (!$expected) return; // se não setar token, fica aberto (não recomendo)
  $got = $_GET['token'] ?? '';
  if (!$got || !hash_equals($expected, (string)$got)) {
    json_response(false, null, 'Unauthorized', 401);
  }
}

function dt_from_unix(?int $ts): ?string {
  if (!$ts || $ts <= 0) return null;
  return gmdate('Y-m-d H:i:s', $ts);
}

auth_or_401();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_response(false, null, 'Method not allowed', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
  json_response(false, null, 'Invalid JSON', 400);
}

/**
 * Aceita 2 formatos:
 * A) Normalizado (recomendado)
 * {
 *   source:"waha", message:{id,content,direction,timestamp}, contact:{name,phone,email}, company_id?, inbox_id?
 * }
 * B) Payload bruto do WAHA (como você mostrou) (fallback)
 */
$source = (string)($payload['source'] ?? 'waha');

$companyId = isset($payload['company_id']) ? (int)$payload['company_id'] : (int)($_SESSION['company_id'] ?? 0);
$inboxId   = isset($payload['inbox_id']) ? (int)$payload['inbox_id'] : null;

// Extração (normalizado)
$msgId      = $payload['message']['id'] ?? null;
$content    = $payload['message']['content'] ?? null;
$direction  = $payload['message']['direction'] ?? null;
$ts         = isset($payload['message']['timestamp']) ? (int)$payload['message']['timestamp'] : null;

$contactName  = $payload['contact']['name'] ?? null;
$contactPhone = $payload['contact']['phone'] ?? null;
$contactEmail = $payload['contact']['email'] ?? null;

// Fallback: WAHA bruto
if (!$content && isset($payload['body']['payload']['body'])) {
  $source = 'waha';
  $content = (string)($payload['body']['payload']['body'] ?? '');
  $fromMe = (bool)($payload['body']['payload']['fromMe'] ?? false);
  $direction = $fromMe ? 'outgoing' : 'incoming';
  $ts = (int)($payload['body']['payload']['timestamp'] ?? 0);

  $contactName = $payload['body']['payload']['_data']['pushName']
    ?? $payload['body']['payload']['_data']['notifyName']
    ?? null;

  $jidAlt = $payload['body']['payload']['_data']['key']['remoteJidAlt'] ?? null;
  $contactPhone = $jidAlt ? normalize_digits((string)$jidAlt) : null;

  $msgId = $payload['body']['payload']['_data']['key']['id'] ?? $payload['body']['payload']['id'] ?? null;
}

$content = is_string($content) ? trim($content) : null;
$direction = is_string($direction) ? trim($direction) : null;

if (!$direction || !in_array($direction, ['incoming','outgoing'], true)) {
  json_response(false, null, 'direction inválido (incoming/outgoing)', 400);
}
if (!$content) {
  json_response(false, null, 'content vazio', 400);
}

$contactPhone = normalize_digits(is_string($contactPhone) ? $contactPhone : null);
$contactEmail = is_string($contactEmail) ? strtolower(trim($contactEmail)) : null;

if (!$contactPhone && !$contactEmail) {
  json_response(false, null, 'Sem contato (phone/email).', 400);
}

$externalCreatedAt = dt_from_unix($ts);

try {
  $pdo = get_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

  // 1) encontra/gera conversa por phone/email (por company)
  $where = [];
  $params = [];
  if ($companyId > 0) { $where[] = 'company_id = :company_id'; $params[':company_id'] = $companyId; }
  if ($contactPhone) { $where[] = 'contact_phone = :phone'; $params[':phone'] = $contactPhone; }
  elseif ($contactEmail) { $where[] = 'contact_email = :email'; $params[':email'] = $contactEmail; }

  $sqlFind = 'SELECT id FROM atd_conversations WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1';
  $st = $pdo->prepare($sqlFind);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if ($row && isset($row['id'])) {
    $convId = (int)$row['id'];
    $stUp = $pdo->prepare("
      UPDATE atd_conversations
      SET inbox_id = COALESCE(:inbox_id, inbox_id),
          contact_name = COALESCE(:name, contact_name),
          contact_email = COALESCE(:email, contact_email),
          status = COALESCE(status, 'open')
      WHERE id = :id
    ");
    $stUp->execute([
      ':inbox_id' => $inboxId,
      ':name' => $contactName ? trim((string)$contactName) : null,
      ':email' => $contactEmail,
      ':id' => $convId
    ]);
  } else {
    $stIns = $pdo->prepare("
      INSERT INTO atd_conversations (company_id, inbox_id, contact_phone, contact_email, contact_name, status, last_message_at)
      VALUES (:company_id, :inbox_id, :phone, :email, :name, 'open', NULL)
    ");
    $stIns->execute([
      ':company_id' => $companyId > 0 ? $companyId : null,
      ':inbox_id' => $inboxId,
      ':phone' => $contactPhone,
      ':email' => $contactEmail,
      ':name' => $contactName ? trim((string)$contactName) : null,
    ]);
    $convId = (int)$pdo->lastInsertId();
  }

  // 2) salva mensagem (dedup por source+external_message_id)
  $stMsg = $pdo->prepare("
    INSERT INTO atd_messages
      (conversation_id, source, direction, external_message_id, sender_name, content, content_type, created_at_external)
    VALUES
      (:conv, :source, :dir, :ext_id, :sender, :content, :ctype, :created_at_external)
    ON DUPLICATE KEY UPDATE
      content = VALUES(content),
      sender_name = COALESCE(VALUES(sender_name), sender_name),
      created_at_external = COALESCE(VALUES(created_at_external), created_at_external)
  ");

  $stMsg->execute([
    ':conv' => $convId,
    ':source' => $source ?: 'waha',
    ':dir' => $direction,
    ':ext_id' => $msgId ? (string)$msgId : null,
    ':sender' => $contactName ? trim((string)$contactName) : null,
    ':content' => $content,
    ':ctype' => 'text',
    ':created_at_external' => $externalCreatedAt,
  ]);

  // 3) atualiza last_message_at
  $stLast = $pdo->prepare("
    UPDATE atd_conversations
    SET last_message_at = COALESCE(:dt, NOW())
    WHERE id = :id
  ");
  $stLast->execute([
    ':dt' => $externalCreatedAt,
    ':id' => $convId
  ]);

  $pdo->commit();

  json_response(true, [
    'conversation_id' => $convId,
    'saved' => true,
    'source' => $source,
    'phone' => $contactPhone,
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_response(false, null, 'Erro: '.$e->getMessage(), 500);
}
