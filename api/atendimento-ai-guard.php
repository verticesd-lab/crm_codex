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

// Segurança simples por token (recomendado)
$expected = getenv('ATD_GUARD_TOKEN') ?: '';
if ($expected !== '') {
  $got = $_GET['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
  $got = str_replace('Bearer ', '', (string)$got);
  if (!hash_equals($expected, trim((string)$got))) {
    json_response(false, null, 'Unauthorized', 401);
  }
}

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = $_POST;

$phone = preg_replace('/\D+/', '', (string)($body['phone'] ?? ''));
if ($phone === '') json_response(false, null, 'phone obrigatório', 400);

try {
  $pdo = get_pdo();

  // pega conversa pela phone
  $st = $pdo->prepare("SELECT * FROM atd_conversations WHERE contact_phone = :p LIMIT 1");
  $st->execute([':p' => $phone]);
  $conv = $st->fetch(PDO::FETCH_ASSOC);

  // Se não existir conversa ainda, libera e cria “next_allowed” após a primeira resposta
  if (!$conv) {
    json_response(true, [
      'allow' => true,
      'reason' => 'new_conversation',
      'cooldown_minutes' => 180,
      'next_allowed_at' => null,
      'conversation_id' => null,
    ]);
  }

  $enabled = (int)($conv['ai_enabled'] ?? 1) === 1;
  $cooldown = (int)($conv['ai_cooldown_minutes'] ?? 180);
  if ($cooldown < 5) $cooldown = 5;

  if (!$enabled) {
    json_response(true, [
      'allow' => false,
      'reason' => 'ai_disabled',
      'conversation_id' => (int)$conv['id'],
      'next_allowed_at' => $conv['ai_next_allowed_at'] ?? null,
      'cooldown_minutes' => $cooldown,
    ]);
  }
    // Bloqueio por humano (modo híbrido)
  $humanLast = $conv['human_last_reply_at'] ?? null;
  $humanBlockMins = (int)($conv['human_block_minutes'] ?? 60);
  if ($humanBlockMins < 5) $humanBlockMins = 5;

  if ($humanLast) {
    // se NOW() < human_last_reply_at + humanBlockMins => bloqueia IA
    $st = $pdo->prepare("SELECT NOW() n, DATE_ADD(:hl, INTERVAL :mins MINUTE) until_block");
    $st->execute([
      ':hl' => $humanLast,
      ':mins' => $humanBlockMins
    ]);
    $t = $st->fetch(PDO::FETCH_ASSOC);

    $now = (string)$t['n'];
    $until = (string)$t['until_block'];

    if ($now < $until) {
      json_response(true, [
        'allow' => false,
        'reason' => 'human_recent_reply',
        'conversation_id' => (int)$conv['id'],
        'human_last_reply_at' => $humanLast,
        'human_block_minutes' => $humanBlockMins,
        'human_block_until' => $until,
      ]);
    }
  }
  // Bloqueio por humano (modo híbrido)
  $humanLast = $conv['human_last_reply_at'] ?? null;
  $humanBlockMins = (int)($conv['human_block_minutes'] ?? 60);
  if ($humanBlockMins < 5) $humanBlockMins = 5;

  if ($humanLast) {
    // se NOW() < human_last_reply_at + humanBlockMins => bloqueia IA
    $st = $pdo->prepare("SELECT NOW() n, DATE_ADD(:hl, INTERVAL :mins MINUTE) until_block");
    $st->execute([
      ':hl' => $humanLast,
      ':mins' => $humanBlockMins
    ]);
    $t = $st->fetch(PDO::FETCH_ASSOC);

    $now = (string)$t['n'];
    $until = (string)$t['until_block'];

    if ($now < $until) {
      json_response(true, [
        'allow' => false,
        'reason' => 'human_recent_reply',
        'conversation_id' => (int)$conv['id'],
        'human_last_reply_at' => $humanLast,
        'human_block_minutes' => $humanBlockMins,
        'human_block_until' => $until,
      ]);
    }
  }


  // Se estiver em cooldown
  $next = $conv['ai_next_allowed_at'] ?? null;
  if ($next) {
    $stNow = $pdo->query("SELECT NOW() n")->fetch(PDO::FETCH_ASSOC);
    $now = (string)$stNow['n'];

    if ($now < $next) {
      json_response(true, [
        'allow' => false,
        'reason' => 'cooldown',
        'conversation_id' => (int)$conv['id'],
        'next_allowed_at' => $next,
        'cooldown_minutes' => $cooldown,
      ]);
    }
  }

  // liberado
  json_response(true, [
    'allow' => true,
    'reason' => 'ok',
    'conversation_id' => (int)$conv['id'],
    'cooldown_minutes' => $cooldown,
    'next_allowed_at' => $next,
  ]);

} catch (Throwable $e) {
  json_response(false, null, 'Erro: ' . $e->getMessage(), 500);
}
