<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
if (!$companyId) die('Empresa não encontrada.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . BASE_URL . '/calendar_barbearia.php');
  exit;
}

$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
$barberId = (int)($_POST['barber_id'] ?? 0);

$customerName = trim($_POST['customer_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$instagram = trim($_POST['instagram'] ?? '');
$serviceKeys = $_POST['services'] ?? [];

if ($date === '' || $time === '' || $barberId <= 0 || $customerName === '' || $phone === '') {
  die('Dados inválidos.');
}

// Catálogo de serviços (banco com fallback)
$catalog = [];
try {
  $st = $pdo->prepare("SELECT service_key, label, price, duration_minutes
                       FROM services
                       WHERE company_id = ? AND is_active = 1");
  $st->execute([$companyId]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $catalog[$s['service_key']] = [
      'label' => $s['label'],
      'price' => (float)$s['price'],
      'duration' => (int)$s['duration_minutes'],
    ];
  }
} catch (Throwable $e) {}

if (!$catalog) {
  $catalog = agenda_get_services_catalog();
}

$serviceKeys = agenda_normalize_services($serviceKeys, $catalog);
$calc = agenda_calculate_services($serviceKeys, $catalog);

$totalPrice = (float)$calc['total_price'];
$totalMinutes = (int)$calc['total_minutes'];
if ($totalMinutes <= 0) $totalMinutes = 30;

$endsAt = substr(agenda_calculate_end_time($time, $totalMinutes), 0, 8); // HH:ii:ss
$servicesJson = json_encode($serviceKeys, JSON_UNESCAPED_UNICODE);

// 1) Checar bloqueio (por barbeiro ou geral)
try {
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM calendar_blocks
    WHERE company_id = ? AND date = ? AND time = ?
      AND (barber_id = ? OR barber_id IS NULL)
  ");
  $st->execute([$companyId, $date, $time . ':00', $barberId]);
  if ((int)$st->fetchColumn() > 0) {
    die('Horário bloqueado.');
  }
} catch (Throwable $e) {
  // se tabela não existir, ignora
}

// 2) Checar conflito com outro agendamento (sobreposição simples por range)
try {
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM appointments
    WHERE company_id = ? AND date = ? AND barber_id = ?
      AND status = 'agendado'
      AND (
        (time < ? AND ends_at_time > ?)
        OR (time = ?)
      )
  ");
  $st->execute([$companyId, $date, $barberId, $endsAt, $time . ':00', $time . ':00']);
  if ((int)$st->fetchColumn() > 0) {
    die('Conflito: barbeiro já ocupado nesse horário.');
  }
} catch (Throwable $e) {
  // se ends_at_time não existir no banco, cai pra validação mínima
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM appointments
    WHERE company_id = ? AND date = ? AND barber_id = ?
      AND status = 'agendado' AND time = ?
  ");
  $st->execute([$companyId, $date, $barberId, $time . ':00']);
  if ((int)$st->fetchColumn() > 0) die('Conflito: horário já ocupado.');
}

// 3) Upsert customer por telefone
$customerId = null;
try {
  $st = $pdo->prepare("SELECT id FROM customers WHERE company_id = ? AND phone = ? LIMIT 1");
  $st->execute([$companyId, $phone]);
  $customerId = $st->fetchColumn() ?: null;

  if (!$customerId) {
    $st = $pdo->prepare("INSERT INTO customers (company_id, name, phone, instagram, created_at)
                         VALUES (?, ?, ?, ?, ?)");
    $st->execute([$companyId, $customerName, $phone, $instagram ?: null, now_utc_datetime()]);
    $customerId = (int)$pdo->lastInsertId();
  }
} catch (Throwable $e) {
  $customerId = null; // se a tabela ainda não existir, segue sem
}

// 4) Inserir appointment
try {
  $st = $pdo->prepare("
    INSERT INTO appointments
      (company_id, barber_id, customer_id, customer_name, phone, instagram, date, time,
       services_json, total_price, total_duration_minutes, ends_at_time, status, created_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'agendado', ?)
  ");
  $st->execute([
    $companyId, $barberId, $customerId,
    $customerName, $phone, $instagram ?: null,
    $date, $time . ':00',
    $servicesJson, $totalPrice, $totalMinutes, $endsAt,
    now_utc_datetime()
  ]);

  $apptId = (int)$pdo->lastInsertId();

  // 5) (por enquanto) NÃO lança no caixa aqui — entra no próximo passo
  // (quando você confirmar, eu adiciono o INSERT em cash_movements)

} catch (Throwable $e) {
  die('Erro ao salvar agendamento: ' . $e->getMessage());
}

// volta pra agenda do dia
header('Location: ' . BASE_URL . '/calendar_barbearia.php?data=' . urlencode($date));
exit;
