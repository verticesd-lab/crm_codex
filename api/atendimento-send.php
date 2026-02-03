<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
  exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$convId = (int)($in['conversation_id'] ?? 0);
$content = trim((string)($in['content'] ?? ''));

if ($convId <= 0 || $content === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'conversation_id/content inválidos']);
  exit;
}

$base = rtrim((string)CHATWOOT_BASE_URL, '/');
$url  = $base . '/api/v1/accounts/' . (int)CHATWOOT_ACCOUNT_ID . '/conversations/' . $convId . '/messages';

$payload = json_encode([
  'content' => $content,
  'message_type' => 'outgoing',
  // 'private' => false, // se quiser nota interna, true
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'api_access_token: ' . (string)CHATWOOT_API_TOKEN,
  ],
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_TIMEOUT => 20,
]);

$res = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($res === false) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'curl error: '.$err]);
  exit;
}

if ($code < 200 || $code >= 300) {
  http_response_code(502);
  echo json_encode(['ok'=>false,'error'=>'Chatwoot API HTTP '.$code, 'details'=>$res]);
  exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'data'=>json_decode($res, true)], JSON_UNESCAPED_UNICODE);
