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

// valida time e normaliza (HH:MM -> HH:MM:SS)
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
    http_response_code(400);
    echo 'Hora inválida.';
    exit;
}
if (strlen($time) === 5) $time .= ':00';

// regra: geral = barber_id 0
if ($scope === 'general') {
    $barberId = 0;
} else {
    if ($barberId <= 0) {
        http_response_code(400);
        echo 'Barbeiro inválido.';
        exit;
    }
}

try {
    // Se for bloqueio geral, remove bloqueios por barbeiro no mesmo horário (opcional, mas evita “mistura”)
    if ($barberId === 0) {
        $del = $pdo->prepare('
            DELETE FROM calendar_blocks
            WHERE company_id = ? AND date = ? AND time = ? AND barber_id <> 0
        ');
        $del->execute([$companyId, $date, $time]);
    }

    // INSERT sem created_at (pra não quebrar em tabelas que não tenham essa coluna)
    // Com UNIQUE (company_id,date,time,barber_id), duplicado vira "ok" e não dá erro.
    $ins = $pdo->prepare('
        INSERT INTO calendar_blocks (company_id, barber_id, date, time)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE barber_id = barber_id
    ');
    $ins->execute([$companyId, $barberId, $date, $time]);

    header('Location: ' . BASE_URL . '/calendar_barbearia.php?data=' . urlencode($date));
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    // DICA rápida pra você ver o erro real (depois pode voltar pra mensagem curta):
    // echo 'Erro: ' . $e->getMessage();
    echo 'Erro ao criar bloqueio.';
    exit;
}
