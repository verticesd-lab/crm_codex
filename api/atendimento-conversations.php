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

try {
  $pdo = get_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  out(false, null, 'DB error: ' . $e->getMessage(), 500);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 80;
if ($limit <= 0) $limit = 80;
if ($limit > 300) $limit = 300;

$q = trim((string)($_GET['q'] ?? ''));

/**
 * Estratégia:
 * - Lista de chatwoot_conversation_map (fonte da verdade)
 * - Puxa o último texto da conversa via subquery em chatwoot_messages
 * - Sem depender de client_id (porque pode ser null no seu fluxo atual)
 */
$sql = "
SELECT
  c.chatwoot_conversation_id,
  c.chatwoot_inbox_id,
  c.status,
  c.last_message_at,
  (
    SELECT m2.content
    FROM chatwoot_messages m2
    WHERE m2.chatwoot_conversation_id = c.chatwoot_conversation_id
    ORDER BY COALESCE(m2.created_at_chatwoot, m2.id) DESC
    LIMIT 1
  ) AS last_content,
  (
    SELECT m3.sender_name
    FROM chatwoot_messages m3
    WHERE m3.chatwoot_conversation_id = c.chatwoot_conversation_id
    ORDER BY COALESCE(m3.created_at_chatwoot, m3.id) DESC
    LIMIT 1
  ) AS last_sender_name
FROM chatwoot_conversation_map c
";

$params = [];

if ($q !== '') {
  // Busca simples por: id da conversa, status, inbox
  // (sem depender de contato/cliente ainda)
  $sql .= " WHERE
    CAST(c.chatwoot_conversation_id AS CHAR) LIKE :q
    OR COALESCE(c.status,'') LIKE :q2
    OR CAST(COALESCE(c.chatwoot_inbox_id,0) AS CHAR) LIKE :q3
  ";
  $params[':q']  = '%' . $q . '%';
  $params[':q2'] = '%' . $q . '%';
  $params[':q3'] = '%' . $q . '%';
}

$sql .= " ORDER BY COALESCE(c.last_message_at, '1970-01-01 00:00:00') DESC, c.id DESC LIMIT {$limit}";

try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();

  // Ajusta nomes de campos esperados pelo JS
  $data = array_map(function(array $r) {
    return [
      'chatwoot_conversation_id' => (int)$r['chatwoot_conversation_id'],
      'chatwoot_inbox_id'        => isset($r['chatwoot_inbox_id']) ? (int)$r['chatwoot_inbox_id'] : null,
      'status'                   => (string)($r['status'] ?? ''),
      'last_message_at'          => (string)($r['last_message_at'] ?? ''),
      // esses 2 ajudam a dar “cara de CRM”
      'last_content'             => (string)($r['last_content'] ?? ''),
      'last_sender_name'         => (string)($r['last_sender_name'] ?? ''),
      // compat com seu JS (pode vir vazio por enquanto)
      'phone'                    => '',
      'email'                    => '',
    ];
  }, $rows);

  out(true, $data, null, 200);
} catch (Throwable $e) {
  out(false, null, 'Query error: ' . $e->getMessage(), 500);
}
