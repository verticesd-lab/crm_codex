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

$limit = max(1, min(200, (int)($_GET['limit'] ?? 80)));
$q = trim((string)($_GET['q'] ?? ''));

$companyId = (int)($_SESSION['company_id'] ?? 0);

try {
  $pdo = get_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $where = [];
  $params = [];

  if ($companyId > 0) {
    $where[] = '(company_id = :company_id OR company_id IS NULL)'; // tolerante
    $params[':company_id'] = $companyId;
  }

  if ($q !== '') {
    $where[] = '(contact_phone LIKE :q OR contact_email LIKE :q OR contact_name LIKE :q)';
    $params[':q'] = '%'.$q.'%';
  }

  $sql = "SELECT id, inbox_id, contact_phone AS phone, contact_email AS email, contact_name, status, last_message_at
          FROM atd_conversations
          ".($where ? "WHERE ".implode(' AND ', $where) : "")."
          ORDER BY COALESCE(last_message_at, created_at) DESC
          LIMIT {$limit}";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // adapta pro seu JS atual (que espera chatwoot_conversation_id etc)
  $out = array_map(function($r){
    return [
      'id' => (int)$r['id'],
      'chatwoot_conversation_id' => (int)$r['id'], // usa interno como “id da conversa”
      'chatwoot_inbox_id' => $r['inbox_id'] ? (int)$r['inbox_id'] : null,
      'phone' => $r['phone'],
      'email' => $r['email'],
      'status' => $r['status'] ?? 'open',
      'contact_name' => $r['contact_name'],
      'last_message_at' => $r['last_message_at'],
    ];
  }, $rows);

  json_response(true, $out);
} catch (Throwable $e) {
  json_response(false, null, 'Erro: '.$e->getMessage(), 500);
}
