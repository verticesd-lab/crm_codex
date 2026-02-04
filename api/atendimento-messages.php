<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

function out(bool $ok, $data = null, ?string $error = null, int $http = 200): void {
  http_response_code($http);
  echo json_encode([
    'ok' => $ok,
    'data' => $data,
    'error' => $error,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$conversationId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
if ($conversationId <= 0) out(false, null, 'conversation_id inválido', 400);

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 400;
if ($limit <= 0) $limit = 400;
if ($limit > 1000) $limit = 1000;

try {
  $pdo = get_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  out(false, null, 'DB error: ' . $e->getMessage(), 500);
}

/**
 * Fonte da verdade da timeline:
 * chatwoot_messages.chatwoot_conversation_id = :conversation_id
 *
 * Importante:
 * - ordenação ASC para renderizar como chat
 * - created_at_chatwoot pode vir null em alguns casos, então usa id como fallback
 */
$sql = "
SELECT
  id,
  chatwoot_message_id,
  direction,
  content,
  content_type,
  sender_name,
  sender_type,
  created_at_chatwoot
FROM chatwoot_messages
WHERE chatwoot_conversation_id = :cid
ORDER BY
  COALESCE(created_at_chatwoot, '1970-01-01 00:00:00') ASC,
  id ASC
LIMIT {$limit}
";

try {
  $st = $pdo->prepare($sql);
  $st->execute([':cid' => $conversationId]);
  $rows = $st->fetchAll();

  $data = array_map(function(array $r) {
    return [
      'id'                 => (int)$r['id'],
      'chatwoot_message_id' => isset($r['chatwoot_message_id']) ? (int)$r['chatwoot_message_id'] : null,
      'direction'          => (string)($r['direction'] ?? 'incoming'), // incoming|outgoing
      'content'            => (string)($r['content'] ?? ''),
      'content_type'       => (string)($r['content_type'] ?? ''),
      'sender_name'        => (string)($r['sender_name'] ?? ''),
      'sender_type'        => (string)($r['sender_type'] ?? ''),
      'created_at'         => (string)($r['created_at_chatwoot'] ?? ''),
    ];
  }, $rows);

  out(true, $data, null, 200);
} catch (Throwable $e) {
  out(false, null, 'Query error: ' . $e->getMessage(), 500);
}
