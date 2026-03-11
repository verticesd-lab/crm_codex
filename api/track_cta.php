<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../analytics_tracker.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    $data = [];
}

$event = isset($data['event']) ? substr(trim((string)$data['event']), 0, 100) : 'cta_click';
$event = strtolower($event);
$event = preg_replace('/[^a-z0-9_\-]/', '', $event) ?? '';
if ($event === '') {
    $event = 'cta_click';
}

$utm_source = isset($data['utm_source']) ? substr(trim((string)$data['utm_source']), 0, 50) : '';
$company_id = max(1, (int)($data['company_id'] ?? 1));
$pageKey = 'landing/cta/' . $event;

if ($utm_source !== '') {
    $_GET['utm_source'] = $utm_source;
}

try {
    $pdo = get_pdo();
    track_page_view($pdo, $company_id, $pageKey);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
