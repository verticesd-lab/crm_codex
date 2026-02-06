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

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = $_POST;

$conversationId = (int)($body['conversation_id'] ?? 0);
$content = trim((string)($body['content'] ?? ''));

if ($conversationId <= 0) json_response(false, null, 'conversation_id é obrigatório', 400);
if ($content === '') json_response(false, null, 'content é obrigatório', 400);

try {
  $pdo = get_pdo();

  // Pega telefone/email da conversa
  $stc = $pdo->prepare("
    SELECT id, contact_phone, contact_email, contact_name
    FROM atd_conversations
    WHERE id = :id AND company_id = :company_id
    LIMIT 1
  ");
  $stc->execute([
    ':id' => $conversationId,
    ':company_id' => $companyId,
  ]);
  $conv = $stc->fetch(PDO::FETCH_ASSOC);

  if (!$conv) json_response(false, null, 'Conversa não encontrada', 404);

  $senderName = (string)($_SESSION['nome'] ?? 'CRM');

  // Salva no banco
  $stm = $pdo->prepare("
    INSERT INTO atd_messages
      (conversation_id, source, direction, external_message_id, external_conversation_id, sender_name, content, content_type, created_at_external)
    VALUES
      (:cid, 'crm', 'outgoing', NULL, NULL, :sender, :content, 'text', NULL)
  ");
  $stm->execute([
    ':cid' => $conversationId,
    ':sender' => $senderName,
    ':content' => $content,
  ]);

  // Atualiza last_message_at
  $pdo->prepare("UPDATE atd_conversations SET last_message_at = NOW() WHERE id = :id AND company_id = :company_id")->execute([
    ':id' => $conversationId,
    ':company_id' => $companyId,
  ]);

  // Dispara pro ActivePieces (opcional, mas recomendado)
  $webhook = getenv('ATD_OUTGOING_WEBHOOK_URL') ?: '';
  $dispatched = false;
  $dispatchError = null;

  if ($webhook) {
    $payload = [
      'conversation_id' => (int)$conversationId,
      'to_phone' => (string)($conv['contact_phone'] ?? ''),
      'to_email' => (string)($conv['contact_email'] ?? ''),
      'contact_name' => (string)($conv['contact_name'] ?? ''),
      'content' => $content,
      'sender_name' => $senderName,
    ];

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT => 12,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
      $dispatchError = $err;
    } elseif ($code >= 200 && $code < 300) {
      $dispatched = true;
    } else {
      $dispatchError = "HTTP $code: " . (string)$resp;
    }
  }

  json_response(true, [
    'saved' => true,
    'conversation_id' => $conversationId,
    'dispatched' => $dispatched,
    'dispatch_error' => $dispatchError,
  ]);
} catch (Throwable $e) {
  json_response(false, null, 'Erro ao enviar: ' . $e->getMessage(), 500);
}
