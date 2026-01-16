<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';

require_login();

$pdo = get_pdo();
$companyId = current_company_id();
if (!$companyId) {
    http_response_code(400);
    exit('Empresa não encontrada.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/calendar_barbearia.php');
}

/**
 * Helpers locais
 */
function normalize_phone(string $phone): string {
    $phone = trim($phone);
    // Mantém só dígitos e alguns símbolos comuns
    $phone = preg_replace('/[^0-9()+\-\s]/', '', $phone);
    // Remove espaços duplicados
    $phone = preg_replace('/\s+/', ' ', $phone);
    return trim($phone);
}

function normalize_instagram(string $ig): string {
    $ig = trim($ig);
    $ig = ltrim($ig, '@');
    // remove espaços e caracteres estranhos, mantém letras/números/._ (padrão comum)
    $ig = preg_replace('/[^a-zA-Z0-9._]/', '', $ig);
    return $ig;
}

function normalize_date_ymd(string $date): string {
    $date = trim($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    return $date;
}

function normalize_time_hm(string $time): string {
    $time = trim($time);
    // aceita HH:MM ou HH:MM:SS e normaliza para HH:MM
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return substr($time, 0, 5);
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) return '';
    return $time;
}

$date = normalize_date_ymd($_POST['date'] ?? '');
$timeHm = normalize_time_hm($_POST['time'] ?? '');
$barberId = (int)($_POST['barber_id'] ?? 0);

$customerName = trim((string)($_POST['customer_name'] ?? ''));
$phone = normalize_phone((string)($_POST['phone'] ?? ''));
$instagram = normalize_instagram((string)($_POST['instagram'] ?? ''));

// services pode vir array ou string única
$serviceKeys = $_POST['services'] ?? [];
if (!is_array($serviceKeys)) $serviceKeys = [$serviceKeys];

if ($date === '' || $timeHm === '' || $barberId <= 0 || $customerName === '' || $phone === '') {
    http_response_code(422);
    exit('Dados inválidos.');
}

$timeDb = $timeHm . ':00';

/**
 * Catálogo de serviços (prioriza banco; fallback no catálogo fixo)
 */
$catalog = [];
try {
    $st = $pdo->prepare("
        SELECT service_key, label, price, duration_minutes
        FROM services
        WHERE company_id = ? AND is_active = 1
    ");
    $st->execute([$companyId]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $key = (string)$s['service_key'];
        $catalog[$key] = [
            'label'    => (string)$s['label'],
            'price'    => (float)$s['price'],
            'duration' => (int)$s['duration_minutes'],
        ];
    }
} catch (Throwable $e) {
    // ignora e cai no fallback
}

if (!$catalog) {
    $catalog = agenda_get_services_catalog();
}

/**
 * Normaliza serviços e calcula total
 */
$serviceKeys = agenda_normalize_services($serviceKeys, $catalog);
$calc = agenda_calculate_services($serviceKeys, $catalog);

$totalPrice = (float)($calc['total_price'] ?? 0);
$totalMinutes = (int)($calc['total_minutes'] ?? 0);
if ($totalMinutes <= 0) $totalMinutes = 30;

$endsAt = substr(agenda_calculate_end_time($timeHm, $totalMinutes), 0, 8); // HH:ii:ss
$servicesJson = json_encode(array_values($serviceKeys), JSON_UNESCAPED_UNICODE);

/**
 * 1) Checar bloqueio (por barbeiro ou geral)
 */
try {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM calendar_blocks
        WHERE company_id = ? AND date = ? AND time = ?
          AND (barber_id = ? OR barber_id IS NULL)
    ");
    $st->execute([$companyId, $date, $timeDb, $barberId]);
    if ((int)$st->fetchColumn() > 0) {
        http_response_code(409);
        exit('Horário bloqueado.');
    }
} catch (Throwable $e) {
    // se tabela não existir, ignora
}

/**
 * 2) Checar conflito com outro agendamento (sobreposição por intervalo)
 */
$hasEndsColumn = true;
try {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments
        WHERE company_id = ? AND date = ? AND barber_id = ?
          AND status = 'agendado'
          AND (
            (time < ? AND ends_at_time > ?)
            OR (time = ?)
          )
    ");
    $st->execute([$companyId, $date, $barberId, $endsAt, $timeDb, $timeDb]);
    if ((int)$st->fetchColumn() > 0) {
        http_response_code(409);
        exit('Conflito: barbeiro já ocupado nesse horário.');
    }
} catch (Throwable $e) {
    $hasEndsColumn = false;
}

if (!$hasEndsColumn) {
    // fallback mínimo
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments
        WHERE company_id = ? AND date = ? AND barber_id = ?
          AND status = 'agendado' AND time = ?
    ");
    $st->execute([$companyId, $date, $barberId, $timeDb]);
    if ((int)$st->fetchColumn() > 0) {
        http_response_code(409);
        exit('Conflito: horário já ocupado.');
    }
}

/**
 * 3) Upsert customer por telefone (se existir tabela)
 */
$customerId = null;
try {
    $st = $pdo->prepare("SELECT id FROM customers WHERE company_id = ? AND phone = ? LIMIT 1");
    $st->execute([$companyId, $phone]);
    $customerId = $st->fetchColumn() ?: null;

    if (!$customerId) {
        $st = $pdo->prepare("
            INSERT INTO customers (company_id, name, phone, instagram, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $st->execute([$companyId, $customerName, $phone, ($instagram !== '' ? $instagram : null), now_utc_datetime()]);
        $customerId = (int)$pdo->lastInsertId();
    } else {
        // atualiza nome/instagram se vier preenchido (sem forçar apagar)
        $st = $pdo->prepare("
            UPDATE customers
            SET name = ?, instagram = COALESCE(NULLIF(?, ''), instagram)
            WHERE id = ? AND company_id = ?
        ");
        $st->execute([$customerName, $instagram, (int)$customerId, $companyId]);
    }
} catch (Throwable $e) {
    $customerId = null; // se a tabela ainda não existir, segue sem
}

/**
 * 4) Inserir appointment
 * OBS: aqui mantive seus nomes de coluna: phone / instagram
 * (se no seu banco for customer_phone/customer_instagram, me fala que eu ajusto)
 */
try {
    $st = $pdo->prepare("
        INSERT INTO appointments
            (company_id, barber_id, customer_id,
             customer_name, phone, instagram,
             date, time,
             services_json, total_price, total_duration_minutes,
             ends_at_time, status, created_at)
        VALUES
            (?, ?, ?,
             ?, ?, ?,
             ?, ?,
             ?, ?, ?,
             ?, 'agendado', ?)
    ");

    $st->execute([
        $companyId,
        $barberId,
        $customerId,

        $customerName,
        $phone,
        ($instagram !== '' ? $instagram : null),

        $date,
        $timeDb,

        $servicesJson,
        $totalPrice,
        $totalMinutes,

        $endsAt,
        now_utc_datetime(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Erro ao salvar agendamento: ' . $e->getMessage());
}

// volta pra agenda do dia
redirect('/calendar_barbearia.php?data=' . urlencode($date));
