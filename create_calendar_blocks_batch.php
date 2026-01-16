<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'company_not_found']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$date = trim((string)($data['date'] ?? ''));
$general = (int)($data['general'] ?? 0);
$items = $data['items'] ?? [];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

try {
    // cria tabela? (assumindo que já existe pela sua migration)
    // calendar_blocks(company_id, date, time, barber_id NULL, created_at...)

    $pdo->beginTransaction();

    $ins = $pdo->prepare('
        INSERT IGNORE INTO calendar_blocks (company_id, date, time, barber_id, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ');

    foreach ($items as $it) {
        $time = trim((string)($it['time'] ?? ''));
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) continue;

        $timeDb = $time . ':00';

        if ($general === 1) {
            $ins->execute([$companyId, $date, $timeDb, null]);
        } else {
            $barberId = (int)($it['barber_id'] ?? 0);
            if ($barberId <= 0) continue;
            $ins->execute([$companyId, $date, $timeDb, $barberId]);
        }
    }

    $pdo->commit();

    echo json_encode(['ok' => true]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit;
}
