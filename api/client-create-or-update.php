<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

checkApiToken();

$pdo = get_pdo();
$input = getMergedInput();

$companyId = (int)($input['company_id'] ?? 0);
$nome = trim($input['nome'] ?? '');
$telefone = trim($input['telefone'] ?? ($input['whatsapp'] ?? ''));
$instagram = trim($input['instagram_username'] ?? ($input['instagram'] ?? ''));
$email = trim($input['email'] ?? '');
$tags = trim($input['tags'] ?? '');

if (!$companyId || $nome === '') {
    apiJsonError('Informe company_id e nome');
}

$instagramHandle = $instagram !== '' ? ltrim($instagram, '@') : '';

try {
    $client = null;

    if ($telefone !== '') {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND (telefone_principal = ? OR whatsapp = ?) LIMIT 1');
        $stmt->execute([$companyId, $telefone, $telefone]);
        $client = $stmt->fetch();
    }

    if (!$client && $instagramHandle !== '') {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND instagram_username = ? LIMIT 1');
        $stmt->execute([$companyId, $instagramHandle]);
        $client = $stmt->fetch();
    }

    if ($client) {
        $newTelefone = $telefone !== '' ? $telefone : ($client['telefone_principal'] ?? '');
        $newInstagram = $instagramHandle !== '' ? $instagramHandle : ($client['instagram_username'] ?? '');
        $newEmail = $email !== '' ? $email : ($client['email'] ?? '');
        $newTags = $tags !== '' ? $tags : ($client['tags'] ?? '');

        $update = $pdo->prepare('UPDATE clients SET nome = ?, telefone_principal = ?, whatsapp = ?, instagram_username = ?, email = ?, tags = ?, updated_at = NOW() WHERE id = ? AND company_id = ?');
        $update->execute([
            $nome,
            $newTelefone,
            $newTelefone,
            $newInstagram,
            $newEmail,
            $newTags,
            $client['id'],
            $companyId,
        ]);

        $clientId = (int)$client['id'];
        $action = 'updated';
    } else {
        $insert = $pdo->prepare('INSERT INTO clients (company_id, nome, telefone_principal, whatsapp, instagram_username, email, tags, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $insert->execute([
            $companyId,
            $nome,
            $telefone,
            $telefone,
            $instagramHandle,
            $email,
            $tags,
        ]);
        $clientId = (int)$pdo->lastInsertId();
        $action = 'created';
    }

    $fetch = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND company_id = ?');
    $fetch->execute([$clientId, $companyId]);
    $clientData = $fetch->fetch();

    apiJsonResponse(true, [
        'client_id' => $clientId,
        'action' => $action,
        'client' => $clientData,
    ]);
} catch (Throwable $e) {
    apiJsonError('Erro ao criar ou atualizar cliente', 500);
}
