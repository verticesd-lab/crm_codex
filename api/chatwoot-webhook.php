<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, $data=null, ?string $error=null, int $http=200): void {
  http_response_code($http);
  echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function log_cw(string $msg): void {
  $enabled = (string)(getenv('CHATWOOT_WEBHOOK_LOG') ?: '0');
  if ($enabled !== '1') return;

  $line = '['.date('Y-m-d H:i:s').'] '.$msg."\n";
  $paths = [
    '/app/logs/chatwoot-webhook.log',
    __DIR__ . '/../logs/chatwoot-webhook.log',
    sys_get_temp_dir() . '/chatwoot-webhook.log',
  ];

  foreach ($paths as $p) {
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (@file_put_contents($p, $line, FILE_APPEND) !== false) return;
  }
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

function get_header(string $name): string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return (string)($_SERVER[$key] ?? '');
}

/**
 * Validação do Chatwoot:
 * - Preferencial: HMAC SHA256 (X-Chatwoot-Signature) com CHATWOOT_WEBHOOK_SECRET
 * - Token simples é OPCIONAL (só se você ativar CHATWOOT_REQUIRE_TOKEN=1)
 */
function validate_webhook(string $rawBody): void {
  $secret = (string)(getenv('CHATWOOT_WEBHOOK_SECRET') ?: '');
  $sigHdr = trim((string)get_header('X-Chatwoot-Signature'));

  // Alguns builds mandam "sha256=<hash>"
  if (stripos($sigHdr, 'sha256=') === 0) {
    $sigHdr = substr($sigHdr, 7);
  }

  // 1) Se veio assinatura, valida e encerra (é o caminho do Chatwoot)
  if ($secret !== '' && $sigHdr !== '') {
    $calc = hash_hmac('sha256', $rawBody, $secret);
    if (!hash_equals($calc, $sigHdr)) {
      log_cw("UNAUTHORIZED signature_mismatch");
      json_response(false, null, 'Unauthorized', 401);
    }
    return;
  }

  // 2) Se NÃO veio assinatura:
  // Por padrão, NÃO bloqueia o Chatwoot.
  // Só exige token se você setar CHATWOOT_REQUIRE_TOKEN=1
  $requireToken = (string)(getenv('CHATWOOT_REQUIRE_TOKEN') ?: '0');
  if ($requireToken !== '1') {
    log_cw("AUTH no_signature allow (require_token=0)");
    return;
  }

  $expected = (string)(getenv('CHATWOOT_WEBHOOK_TOKEN') ?: '');
  if ($expected === '') {
    log_cw("AUTH require_token=1 but CHATWOOT_WEBHOOK_TOKEN empty");
    json_response(false, null, 'Unauthorized', 401);
  }

  $tokenHeader =
    (string)(get_header('X-Chatwoot-Webhook-Token') ?: '') ?:
    (string)(get_header('X-Webhook-Token') ?: '');

  $got =
    (string)($_GET['token'] ?? '') ?:
    (string)(get_header('Authorization') ?: '') ?:
    (string)$tokenHeader;

  $got = str_replace('Bearer ', '', trim((string)$got));

  if (!hash_equals(trim($expected), trim($got))) {
    log_cw("UNAUTHORIZED token_mismatch");
    json_response(false, null, 'Unauthorized', 401);
  }
}

/**
 * Extrai telefone e conteúdo do payload (robusto para variações).
 */
function extract_phone(array $p): string {
  $candidates = [
    $p['conversation']['meta']['sender']['phone_number'] ?? null,
    $p['conversation']['contact_inbox']['contact']['phone_number'] ?? null,
    $p['conversation']['contact']['phone_number'] ?? null,
    $p['conversation']['contact']['phone_number'] ?? null,
    $p['contact']['phone_number'] ?? null,
    $p['sender']['phone_number'] ?? null,
    $p['message']['sender']['phone_number'] ?? null,
  ];

  foreach ($candidates as $v) {
    if (!is_string($v) || trim($v) === '') continue;
    $digits = preg_replace('/\D+/', '', $v);
    if ($digits !== '') return $digits;
  }
  return '';
}

function extract_name(array $p): string {
  $candidates = [
    $p['conversation']['meta']['sender']['name'] ?? null,
    $p['conversation']['contact']['name'] ?? null,
    $p['contact']['name'] ?? null,
    $p['sender']['name'] ?? null,
  ];
  foreach ($candidates as $v) {
    if (is_string($v) && trim($v) !== '') return trim($v);
  }
  return '';
}

function extract_content(array $p): string {
  $candidates = [
    $p['message']['content'] ?? null,
    $p['content'] ?? null,
  ];
  foreach ($candidates as $v) {
    if (is_string($v) && trim($v) !== '') return (string)$v;
  }
  return '';
}

function is_outgoing_human(array $p): bool {
  $event = (string)($p['event'] ?? $p['type'] ?? '');
  if ($event !== '' && $event !== 'message_created') return false;

  $mt = (string)($p['message']['message_type'] ?? $p['message_type'] ?? '');
  if ($mt === '') $mt = (string)($p['message']['direction'] ?? '');

  if ($mt !== 'outgoing') return false;

  $isPrivate = (bool)($p['message']['private'] ?? false);
  if ($isPrivate) return false;

  $senderType = (string)($p['message']['sender_type'] ?? $p['sender_type'] ?? '');
  if ($senderType !== '' && strtolower($senderType) === 'bot') return false;

  return true;
}

/**
 * Atualiza CRM: marca último reply humano e bloqueia IA por human_block_minutes.
 */
function mark_human_reply(PDO $pdo, string $phone, string $name, string $content): void {
  $st = $pdo->prepare("SELECT id, human_block_minutes FROM atd_conversations WHERE contact_phone = :p LIMIT 1");
  $st->execute([':p' => $phone]);
  $conv = $st->fetch(PDO::FETCH_ASSOC);

  if (!$conv) {
    try {
      $ins = $pdo->prepare("
        INSERT INTO atd_conversations (contact_phone, contact_name, status, last_message_at, last_content, human_last_reply_at, human_block_minutes, created_at, updated_at)
        VALUES (:phone, :name, 'open', NOW(), :content, NOW(), 60, NOW(), NOW())
      ");
      $ins->execute([
        ':phone' => $phone,
        ':name' => ($name !== '' ? $name : $phone),
        ':content' => mb_substr($content, 0, 500),
      ]);
      $convId = (int)$pdo->lastInsertId();
      log_cw("CRM conversation created id={$convId} phone={$phone}");
      $conv = ['id' => $convId, 'human_block_minutes' => 60];
    } catch (Throwable $e) {
      log_cw("CRM create conversation FAILED phone={$phone} err=".$e->getMessage());
      return;
    }
  }

  $convId = (int)$conv['id'];
  $humanBlock = (int)($conv['human_block_minutes'] ?? 60);
  if ($humanBlock < 5) $humanBlock = 5;

  $up = $pdo->prepare("
    UPDATE atd_conversations
    SET
      last_message_at = NOW(),
      last_content = :content,
      contact_name = COALESCE(NULLIF(:name,''), contact_name),
      human_last_reply_at = NOW(),
      ai_next_allowed_at = GREATEST(
        COALESCE(ai_next_allowed_at, '1970-01-01 00:00:00'),
        DATE_ADD(NOW(), INTERVAL :mins MINUTE)
      ),
      updated_at = NOW()
    WHERE id = :id
  ");
  $up->execute([
    ':content' => mb_substr($content, 0, 500),
    ':name' => $name,
    ':mins' => $humanBlock,
    ':id' => $convId,
  ]);

  try {
    $insm = $pdo->prepare("
      INSERT INTO atd_messages (conversation_id, source, direction, external_message_id, sender_name, content, content_type, created_at, created_at_external)
      VALUES (:cid, 'chatwoot', 'outgoing', NULL, :sender, :content, 'text', NOW(), NOW())
    ");
    $insm->execute([
      ':cid' => $convId,
      ':sender' => ($name !== '' ? $name : 'Agente'),
      ':content' => $content,
    ]);
  } catch (Throwable $e) {
    log_cw("CRM insert message skipped/failed conv={$convId} err=".$e->getMessage());
  }
}

/**
 * Envia mensagem pro WhatsApp (ActivePieces ou WAHA).
 */
function send_to_whatsapp(string $phone, string $content): array {
  $ap = (string)(getenv('AP_OUT_WEBHOOK_URL') ?: '');
  if ($ap !== '') {
    $r = http_json('POST', $ap, ['Content-Type' => 'application/json'], [
      'phone' => $phone,
      'content' => $content,
      'source' => 'chatwoot',
    ], 20);
    return ['mode'=>'activepieces', 'ok'=>$r['ok'], 'http'=>$r['code'], 'resp'=>$r['json'] ?? $r['raw']];
  }

  $wahaUrl = (string)(getenv('WAHA_API_URL') ?: '');
  $wahaKey = (string)(getenv('WAHA_API_KEY') ?: '');
  $session = (string)(getenv('WAHA_SESSION') ?: 'default');

  if ($wahaUrl !== '') {
    $headers = ['Content-Type' => 'application/json'];
    if ($wahaKey !== '') $headers['X-Api-Key'] = $wahaKey;

    $payload = [
      'session' => $session,
      'chatId' => $phone . '@c.us',
      'text' => $content,
    ];

    $r = http_json('POST', $wahaUrl, $headers, $payload, 20);
    return ['mode'=>'waha', 'ok'=>$r['ok'], 'http'=>$r['code'], 'resp'=>$r['json'] ?? $r['raw']];
  }

  return ['mode'=>'none', 'ok'=>true, 'http'=>200, 'resp'=>'No WAHA/AP out configured'];
}

// =====================
// MAIN
// =====================
$rawBody = (string)file_get_contents('php://input');
validate_webhook($rawBody);

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
  log_cw("INVALID_JSON raw=".substr($rawBody,0,200));
  json_response(false, null, 'Invalid JSON', 400);
}

if (!is_outgoing_human($payload)) {
  json_response(true, ['ignored'=>true], null, 200);
}

$phone = extract_phone($payload);
$name = extract_name($payload);
$content = extract_content($payload);

if ($phone === '' || $content === '') {
  log_cw("MISSING_FIELDS phone=" . $phone . " content_len=" . strlen($content));
  json_response(true, ['ignored'=>true,'reason'=>'missing_phone_or_content'], null, 200);
}

try {
  $pdo = get_pdo();

  mark_human_reply($pdo, $phone, $name, $content);

  $send = send_to_whatsapp($phone, $content);

  log_cw("OUTGOING_OK phone={$phone} mode={$send['mode']} http={$send['http']}");

  json_response(true, [
    'processed' => true,
    'phone' => $phone,
    'mode' => $send['mode'],
    'send_ok' => (bool)$send['ok'],
    'send_http' => (int)$send['http'],
  ], null, 200);

} catch (Throwable $e) {
  log_cw("ERROR " . $e->getMessage());
  json_response(false, null, 'Erro: ' . $e->getMessage(), 500);
}
