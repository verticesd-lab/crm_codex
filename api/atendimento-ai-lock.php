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

$conversationId = (int)($body['conversation_id'] ?? 0);
if ($conversationId <= 0) json_response(false, null, 'conversation_id obrigatório', 400);

try {
  $pdo = get_pdo();

  $st = $pdo->prepare("SELECT ai_cooldown_minutes FROM atd_conversations WHERE id = :id LIMIT 1");
  $st->execute([':id' => $conversationId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_response(false, null, 'Conversa não encontrada', 404);

  $cooldown = (int)($row['ai_cooldown_minutes'] ?? 180);
  if ($cooldown < 5) $cooldown = 5;

  $pdo->prepare("
    UPDATE atd_conversations
    SET ai_last_reply_at = NOW(),
        ai_next_allowed_at = DATE_ADD(NOW(), INTERVAL :mins MINUTE)
    WHERE id = :id
  ")->execute([':mins' => $cooldown, ':id' => $conversationId]);

  json_response(true, [
    'conversation_id' => $conversationId,
    'cooldown_minutes' => $cooldown,
  ]);

} catch (Throwable $e) {
  json_response(false, null, 'Erro: ' . $e->getMessage(), 500);
}
