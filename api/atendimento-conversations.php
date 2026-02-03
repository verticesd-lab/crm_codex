<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

require_login();

$pdo = get_pdo();

$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1 || $limit > 200) $limit = 50;

$q = trim((string)($_GET['q'] ?? ''));

$sql = "
SELECT
  cm.chatwoot_account_id,
  cm.chatwoot_conversation_id,
  cm.chatwoot_inbox_id,
  cm.client_id,
  cm.status,
  cm.last_message_at,
  cm.created_at,
  ctm.chatwoot_contact_id,
  ctm.phone,
  ctm.email
FROM chatwoot_conversation_map cm
LEFT JOIN chatwoot_contact_map ctm
  ON ctm.chatwoot_account_id = cm.chatwoot_account_id
 AND ctm.client_id = cm.client_id
WHERE cm.chatwoot_account_id = ?
";

$params = [ (int)CHATWOOT_ACCOUNT_ID ];

if ($q !== '') {
  $sql .= " AND (ctm.phone LIKE ? OR ctm.email LIKE ? OR cm.chatwoot_conversation_id LIKE ?) ";
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY COALESCE(cm.last_message_at, cm.created_at) DESC LIMIT {$limit} ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
