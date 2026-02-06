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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_response(false, null, 'Method not allowed', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = $_POST;

$token = trim((string)($body['token'] ?? ''));
if ($token === '') {
  json_response(false, null, 'token obrigatório', 401);
}
if (!hash_equals((string)API_TOKEN_IA, $token)) {
  json_response(false, null, 'Unauthorized', 401);
}

$companyId = (int)($body['company_id'] ?? 0);
if ($companyId <= 0) {
  json_response(false, null, 'company_id obrigatório', 400);
}

$phone = preg_replace('/\D+/', '', (string)($body['phone'] ?? ''));
if ($phone === '') json_response(false, null, 'phone obrigatório', 400);

try {
  $pdo = get_pdo();

  $st = $pdo->prepare("
    SELECT *
    FROM atd_conversations
    WHERE contact_phone = :p
      AND company_id = :cid
    LIMIT 1
  ");
  $st->execute([
    ':p' => $phone,
    ':cid' => $companyId,
  ]);
  $conv = $st->fetch(PDO::FETCH_ASSOC);

  // conversa nova: libera (o cooldown só passa a existir depois que a IA responder e setar ai_next_allowed_at)
  if (!$conv) {
    json_response(true, [
      'allow' => true,
      'reason' => 'new_conversation',
      'company_id' => $companyId,
      'cooldown_minutes' => 120,
      'next_allowed_at' => null,
      'conversation_id' => null,
    ]);
  }

  $conversationId = (int)$conv['id'];

  $enabled = (int)($conv['ai_enabled'] ?? 1) === 1;
  $cooldown = (int)($conv['ai_cooldown_minutes'] ?? 120);
  if ($cooldown < 5) $cooldown = 5;

  if (!$enabled) {
    json_response(true, [
      'allow' => false,
      'reason' => 'ai_disabled',
      'conversation_id' => $conversationId,
      'next_allowed_at' => $conv['ai_next_allowed_at'] ?? null,
      'cooldown_minutes' => $cooldown,
    ]);
  }

  // 1) Cooldown da IA (ciclo principal)
  $next = $conv['ai_next_allowed_at'] ?? null;
  if ($next) {
    $st = $pdo->prepare("SELECT NOW() < :next AS blocked, NOW() n");
    $st->execute([':next' => $next]);
    $t = $st->fetch(PDO::FETCH_ASSOC);

    if ((int)$t['blocked'] === 1) {
      json_response(true, [
        'allow' => false,
        'reason' => 'ai_cooldown',
        'conversation_id' => $conversationId,
        'next_allowed_at' => $next,
        'cooldown_minutes' => $cooldown,
        'now' => (string)$t['n'],
      ]);
    }
  }

  // 2) Bloqueio por humano (modo híbrido)
  $humanLast = $conv['human_last_reply_at'] ?? null;
  $humanBlockMins = (int)($conv['human_block_minutes'] ?? 60);
  if ($humanBlockMins < 0) $humanBlockMins = 0;

  if ($humanLast && $humanBlockMins > 0) {
    $st = $pdo->prepare("
      SELECT
        NOW() n,
        DATE_ADD(:hl, INTERVAL :mins MINUTE) until_block,
        NOW() < DATE_ADD(:hl, INTERVAL :mins MINUTE) AS blocked
    ");
    $st->execute([':hl' => $humanLast, ':mins' => $humanBlockMins]);
    $t = $st->fetch(PDO::FETCH_ASSOC);

    if ((int)$t['blocked'] === 1) {
      json_response(true, [
        'allow' => false,
        'reason' => 'human_block',
        'conversation_id' => $conversationId,
        'human_last_reply_at' => $humanLast,
        'human_block_minutes' => $humanBlockMins,
        'human_block_until' => (string)$t['until_block'],
        'now' => (string)$t['n'],
      ]);
    }
  }

  // liberado
  json_response(true, [
    'allow' => true,
    'reason' => 'ok',
    'conversation_id' => $conversationId,
    'cooldown_minutes' => $cooldown,
    'next_allowed_at' => $next,
  ]);

} catch (Throwable $e) {
  json_response(false, null, 'Erro: ' . $e->getMessage(), 500);
}
