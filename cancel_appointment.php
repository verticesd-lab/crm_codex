<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

if (!$companyId) {
    exit('Empresa não encontrada na sessão.');
}

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$data = $_GET['data'] ?? null; // para voltar pro mesmo dia da agenda

if ($id <= 0) {
    exit('Agendamento inválido.');
}

// Marca como cancelado (não apaga do histórico)
$stmt = $pdo->prepare('
    UPDATE appointments
    SET status = "cancelado", cancelled_at = NOW()
    WHERE id = ? AND company_id = ?
');
$stmt->execute([$id, $companyId]);

// Volta para a agenda interna da barbearia
$redirect = BASE_URL . '/calendar_barbearia.php';
if ($data) {
    $redirect .= '?data=' . urlencode($data);
}

redirect($redirect);
