<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, $data = null, ?string $error = null, int $http = 200): void {
  http_response_code($http);
  echo json_encode(
    ['ok' => $ok, 'data' => $data, 'error' => $error],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  exit;
}

require_login();

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 80);
if ($limit < 1) $limit = 80;
if ($limit > 200) $limit = 200;

try {
  $pdo = get_pdo();

  $where = '';
  $params = [];

  if ($q !== '') {
    $where = "WHERE (
      c.contact_phone LIKE :q OR
      c.contact_email LIKE :q OR
      c.contact_name  LIKE :q
    )";
    $params[':q'] = '%' . $q . '%';
  }

  $sql = "
    SELECT
      c.id AS conversation_id,          -- << PADRÃO pro frontend
      c.id,
      c.company_id,
      c.inbox_id,
      c.contact_phone,
      c.contact_email,
      c.contact_name,
      c.status,
      c.last_message_at,
      c.human_last_reply_at,
      c.human_block_minutes,
      c.ai_next_allowed_at,
      c.created_at,
      c.updated_at,

      (
        SELECT m.content
        FROM atd_messages m
        WHERE m.conversation_id = c.id
        ORDER BY COALESCE(m.created_at_external, m.created_at) DESC, m.id DESC
        LIMIT 1
      ) AS last_content,

      (
        SELECT COALESCE(m.created_at_external, m.created_at)
        FROM atd_messages m
        WHERE m.conversation_id = c.id
        ORDER BY COALESCE(m.created_at_external, m.created_at) DESC, m.id DESC
        LIMIT 1
      ) AS last_message_at_calc

    FROM atd_conversations c
    $where
    ORDER BY COALESCE(c.last_message_at, last_message_at_calc, c.updated_at, c.created_at) DESC, c.id DESC
    LIMIT :lim
  ";

  $st = $pdo->prepare($sql);

  foreach ($params as $k => $v) {
    $st->bindValue($k, $v, PDO::PARAM_STR);
  }
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);

  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // garante last_message_at final (se estiver NULL na conversa)
  foreach ($rows as &$r) {
    if (empty($r['last_message_at']) && !empty($r['last_message_at_calc'])) {
      $r['last_message_at'] = $r['last_message_at_calc'];
    }
    unset($r['last_message_at_calc']); // remove campo auxiliar
  }

  json_response(true, $rows, null, 200);

} catch (Throwable $e) {
  json_response(false, null, 'Erro ao listar conversas: ' . $e->getMessage(), 500);
}
