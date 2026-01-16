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
if (!$companyId) die('Empresa não encontrada.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/calendar_barbearia.php');
    exit;
}

$date     = trim($_POST['date'] ?? '');
$time     = trim($_POST['time'] ?? '');
$scope    = trim($_POST['scope'] ?? 'barber'); // 'barber' | 'general'
$barberId = (int)($_POST['barber_id'] ?? 0);

// validações
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die('Data inválida.');
}
if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    die('Hora inválida.');
}

$timeDb = $time . ':00';

// se for geral, barber_id = NULL
$barberIdDb = null;
if ($scope === 'barber') {
    if ($barberId <= 0) die('Barbeiro inválido.');
    $barberIdDb = $barberId;
} elseif ($scope === 'general') {
    $barberIdDb = null;
} else {
    die('Escopo inválido.');
}

try {
    // evita duplicar: se já existir, não cria de novo
    $st = $pdo->prepare('
        SELECT id FROM calendar_blocks
        WHERE company_id = ? AND date = ? AND time = ?
          AND ((barber_id IS NULL AND ? IS NULL) OR barber_id = ?)
        LIMIT 1
    ');
    $st->execute([$companyId, $date, $timeDb, $barberIdDb, $barberIdDb]);
    $exists = $st->fetchColumn();

    if (!$exists) {
        $ins = $pdo->prepare('
            INSERT INTO calendar_blocks (company_id, date, time, barber_id, created_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $ins->execute([$companyId, $date, $timeDb, $barberIdDb, now_utc_datetime()]);
    }

} catch (Throwable $e) {
    die('Erro ao criar bloqueio.');
}

header('Location: ' . BASE_URL . '/calendar_barbearia.php?data=' . urlencode($date));
exit;
