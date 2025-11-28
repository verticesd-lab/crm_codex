<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

checkApiToken();

$pdo = get_pdo();
$input = getMergedInput();

$companyId = (int)($input['company_id'] ?? 0);
$phone = trim($input['phone'] ?? ($input['telefone'] ?? ($input['whatsapp'] ?? '')));
$instagram = trim($input['instagram'] ?? ($input['instagram_username'] ?? ''));

if (!$companyId) {
    apiJsonError('company_id obrigatorio');
}

if ($phone === '' && $instagram === '') {
    apiJsonError('Informe phone ou instagram');
}

try {
    $client = null;

    if ($phone !== '') {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND (telefone_principal = ? OR whatsapp = ?) LIMIT 1');
        $stmt->execute([$companyId, $phone, $phone]);
        $client = $stmt->fetch();
    }

    if (!$client && $instagram !== '') {
        $handle = ltrim($instagram, '@');
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND instagram_username = ? LIMIT 1');
        $stmt->execute([$companyId, $handle]);
        $client = $stmt->fetch();
    }

    if (!$client) {
        apiJsonResponse(false, null, 'Cliente nao encontrado');
    }

    $ordersStmt = $pdo->prepare('SELECT id, origem, status, total, created_at FROM orders WHERE company_id = ? AND client_id = ? ORDER BY created_at DESC LIMIT 5');
    $ordersStmt->execute([$companyId, $client['id']]);

    $interactionsStmt = $pdo->prepare('SELECT id, canal, origem, titulo, resumo, atendente, created_at FROM interactions WHERE company_id = ? AND client_id = ? ORDER BY created_at DESC LIMIT 5');
    $interactionsStmt->execute([$companyId, $client['id']]);

    apiJsonResponse(true, [
        'client' => $client,
        'orders' => $ordersStmt->fetchAll(),
        'interactions' => $interactionsStmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    apiJsonError('Erro ao buscar cliente', 500);
}
