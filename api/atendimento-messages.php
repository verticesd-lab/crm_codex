<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, $data = null, ?string $error = null, int $http = 200): void {
  http_response_code($http);
  echo json_encode(['ok' => $ok, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

require_login();

$companyId = (int)($_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
  http_response_code(403);
  die(json_encode(['ok' => false, 'data' => null, 'error' => 'Forbidden: company_id ausente na sessao'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$conversationId = (int)($_GET['conversation_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 1) $limit = 200;
if ($limit > 500) $limit = 500;

if ($conversationId <= 0) {
  json_response(false, null, 'conversation_id é obrigatório', 400);
}

try {
  $pdo = get_pdo();

  // Pega as últimas N em ordem DESC e depois inverte no PHP para exibir ASC no front
  $st = $pdo->prepare("
    SELECT
      m.id,
      m.conversation_id,
      m.source,
      m.direction,
      m.external_message_id,
      m.sender_name,
      m.content,
      m.content_type,
      m.created_at,
      m.created_at_external
    FROM atd_messages m
    INNER JOIN atd_conversations c ON c.id = m.conversation_id
    WHERE m.conversation_id = :cid
      AND c.company_id = :company_id
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT :lim
  ");
  $st->bindValue(':cid', $conversationId, PDO::PARAM_INT);
  $st->bindValue(':company_id', $companyId, PDO::PARAM_INT);
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);
  $st->execute();

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $rows = array_reverse($rows);

  json_response(true, $rows, null, 200);
} catch (Throwable $e) {
  json_response(false, null, 'Erro ao carregar mensagens: ' . $e->getMessage(), 500);
}
