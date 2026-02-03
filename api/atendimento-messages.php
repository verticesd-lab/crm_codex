<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

require_login();

$pdo = get_pdo();

$convId = (int)($_GET['conversation_id'] ?? 0);
if ($convId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'conversation_id inválido']);
  exit;
}

$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 1 || $limit > 500) $limit = 200;

$stmt = $pdo->prepare("
  SELECT
    chatwoot_message_id,
    direction,
    content,
    content_type,
    sender_name,
    sender_type,
    created_at_chatwoot,
    created_at
  FROM chatwoot_messages
  WHERE chatwoot_account_id = ?
    AND chatwoot_conversation_id = ?
  ORDER BY COALESCE(created_at_chatwoot, created_at) ASC
  LIMIT {$limit}
");
$stmt->execute([(int)CHATWOOT_ACCOUNT_ID, $convId]);
$rows = $stmt->fetchAll();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
