<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

require_login();

function json_response(bool $ok, $data=null, ?string $error=null, int $http=200): void {
  http_response_code($http);
  echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$convId = (int)($_GET['conversation_id'] ?? 0);
$limit  = max(1, min(800, (int)($_GET['limit'] ?? 400)));

if ($convId <= 0) json_response(false, null, 'conversation_id inválido', 400);

try {
  $pdo = get_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $st = $pdo->prepare("
    SELECT
      id,
      source,
      direction,
      sender_name,
      content,
      content_type,
      created_at,
      created_at_external
    FROM atd_messages
    WHERE conversation_id = :cid
    ORDER BY COALESCE(created_at_external, created_at) ASC
    LIMIT {$limit}
  ");
  $st->execute([':cid'=>$convId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // adapta pro seu JS atual
  $out = array_map(function($m){
    return [
      'id' => (int)$m['id'],
      'direction' => $m['direction'],
      'sender_name' => $m['sender_name'],
      'content' => $m['content'],
      'content_type' => $m['content_type'],
      'source' => $m['source'],
      'created_at' => $m['created_at_external'] ?? $m['created_at'],
    ];
  }, $rows);

  json_response(true, $out);
} catch (Throwable $e) {
  json_response(false, null, 'Erro: '.$e->getMessage(), 500);
}
