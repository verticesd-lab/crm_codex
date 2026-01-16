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
if (!$companyId) {
    http_response_code(400);
    echo 'Empresa não encontrada.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/calendar_barbearia.php');
    exit;
}

$date     = trim((string)($_POST['date'] ?? ''));
$time     = trim((string)($_POST['time'] ?? ''));
$scope    = trim((string)($_POST['scope'] ?? 'barber')); // barber | general
$barberId = (int)($_POST['barber_id'] ?? 0);

// valida date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo 'Data inválida.';
    exit;
}

// normaliza time (aceita HH:MM ou HH:MM:SS)
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
    http_response_code(400);
    echo 'Hora inválida.';
    exit;
}
if (strlen($time) === 5) $time .= ':00';

// regra: geral = barber_id 0
if ($scope === 'general') {
    $barberIdDb = 0;
} elseif ($scope === 'barber') {
    if ($barberId <= 0) {
        http_response_code(400);
        echo 'Barbeiro inválido.';
        exit;
    }
    $barberIdDb = $barberId;
} else {
    http_response_code(400);
    echo 'Escopo inválido.';
    exit;
}

try {
    $del = $pdo->prepare('
        DELETE FROM calendar_blocks
        WHERE company_id = ? AND date = ? AND time = ? AND barber_id = ?
        LIMIT 1
    ');
    $del->execute([$companyId, $date, $time, $barberIdDb]);

    header('Location: ' . BASE_URL . '/calendar_barbearia.php?data=' . urlencode($date));
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erro ao remover bloqueio.';
    exit;
}
