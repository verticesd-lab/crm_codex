<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

checkApiToken();

$pdo = get_pdo();
$input = getMergedInput();

$companyId = (int)($input['company_id'] ?? 0);
$clientId = (int)($input['client_id'] ?? 0);
$canal = trim($input['canal'] ?? 'whatsapp');
$origem = trim($input['origem'] ?? 'ia');
$titulo = trim($input['titulo'] ?? '');
$resumo = trim($input['resumo'] ?? '');
$atendente = trim($input['atendente'] ?? 'IA');

if (!$companyId || !$clientId || $titulo === '' || $resumo === '') {
    apiJsonError('Campos obrigatorios ausentes: company_id, client_id, titulo, resumo');
}

try {
    $clientCheck = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $clientCheck->execute([$clientId, $companyId]);
    if (!$clientCheck->fetch()) {
        apiJsonError('Cliente nao pertence a esta empresa', 404);
    }

    $stmt = $pdo->prepare('INSERT INTO interactions (company_id, client_id, canal, origem, titulo, resumo, atendente, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$companyId, $clientId, $canal, $origem, $titulo, $resumo, $atendente]);
    $interactionId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE clients SET ultimo_atendimento_em = NOW(), updated_at = NOW() WHERE id = ?')->execute([$clientId]);

    $fetch = $pdo->prepare('SELECT id, company_id, client_id, canal, origem, titulo, resumo, atendente, created_at FROM interactions WHERE id = ?');
    $fetch->execute([$interactionId]);

    apiJsonResponse(true, [
        'interaction' => $fetch->fetch(),
    ]);
} catch (Throwable $e) {
    apiJsonError('Erro ao registrar interacao', 500);
}
