<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
if (!$companyId) die('Empresa não encontrada.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/calendar_barbearia.php');
    exit;
}

$date     = trim((string)($_POST['date'] ?? ''));
$time     = trim((string)($_POST['time'] ?? ''));
$scope    = trim((string)($_POST['scope'] ?? 'barber')); // barber | general
$barberId = (int)($_POST['barber_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) die('Data inválida.');
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) die('Hora inválida.');
if (strlen($time) === 5) $time .= ':00';

$barberIdDb = 0;
if ($scope === 'general') {
    $barberIdDb = 0;
} else {
    if ($barberId <= 0) die('Barbeiro inválido.');
    $barberIdDb = $barberId;
}

try {
    $del = $pdo->prepare('
        DELETE FROM calendar_blocks
        WHERE company_id = ? AND date = ? AND time = ? AND barber_id = ?
        LIMIT 1
    ');
    $del->execute([$companyId, $date, $time, $barberIdDb]);
} catch (Throwable $e) {
    die('Erro ao remover bloqueio.');
}

header('Location: ' . BASE_URL . '/calendar_barbearia.php?data=' . urlencode($date));
exit;
