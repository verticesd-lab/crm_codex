<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

checkApiToken();

$pdo = get_pdo();
$input = getMergedInput();

$companyId = (int)($input['company_id'] ?? 0);
$clientId = (int)($input['client_id'] ?? 0);

if (!$companyId || !$clientId) {
    apiJsonError('Informe company_id e client_id');
}

try {
    $clientStmt = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $clientStmt->execute([$clientId, $companyId]);
    $client = $clientStmt->fetch();

    if (!$client) {
        apiJsonError('Cliente nao encontrado nesta empresa', 404);
    }

    $interactionsStmt = $pdo->prepare('SELECT id, canal, origem, titulo, resumo, atendente, created_at FROM interactions WHERE company_id = ? AND client_id = ? ORDER BY created_at DESC');
    $interactionsStmt->execute([$companyId, $clientId]);

    $ordersStmt = $pdo->prepare('SELECT id, origem, status, total, created_at FROM orders WHERE company_id = ? AND client_id = ? ORDER BY created_at DESC LIMIT 5');
    $ordersStmt->execute([$companyId, $clientId]);

    apiJsonResponse(true, [
        'client' => $client,
        'interactions' => $interactionsStmt->fetchAll(),
        'orders' => $ordersStmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    apiJsonError('Erro ao montar timeline do cliente', 500);
}
