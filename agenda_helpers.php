<?php
require_once __DIR__ . '/helpers.php';

/**
 * =========================
 * Compat (PHP < 8)
 * =========================
 */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/**
 * =========================
 * Fallbacks seguros de tempo
 * =========================
 */
if (!function_exists('now_utc_datetime')) {
    function now_utc_datetime(): string {
        try {
            return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return gmdate('Y-m-d H:i:s');
        }
    }
}

if (!function_exists('now_app_datetime')) {
    function now_app_datetime(): string {
        try {
            $tz = function_exists('app_timezone') ? app_timezone() : 'America/Cuiaba';
            return (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }
}

/**
 * =========================
 * Serviços (catálogo padrão)
 * =========================
 * Usado como fallback e também para seed inicial no banco.
 */
function agenda_default_services_catalog(): array
{
    return [
        'corte' => [
            'label' => 'Corte',
            'price' => 45.00,
            'duration' => 50,
        ],
        'barba' => [
            'label' => 'Barba',
            'price' => 40.00,
            'duration' => 30,
        ],
        'sobrancelha' => [
            'label' => 'Sobrancelha',
            'price' => 10.00,
            'duration' => 20,
        ],
        'relaxamento' => [
            'label' => 'Relaxamento',
            'price' => 60.00,
            'duration' => 60,
        ],
        'selagem' => [
            'label' => 'Selagem',
            'price' => 160.00,
            'duration' => 120,
        ],
    ];
}

/**
 * =========================
 * Serviços (catálogo) - NOVO
 * =========================
 * - Sem PDO/empresa => retorna catálogo padrão fixo (compat)
 * - Com ($pdo, $companyId) => lê do banco (services) e faz seed automático se vazio
 *
 * Retorno:
 * [
 *   'service_key' => ['label'=>..., 'price'=>..., 'duration'=>..., 'is_active'=>1],
 *   ...
 * ]
 */
function agenda_get_services_catalog(PDO $pdo = null, int $companyId = 0, bool $onlyActive = true): array
{
    if (!$pdo || $companyId <= 0) {
        return agenda_default_services_catalog();
    }

    try {
        // Seed se não houver nenhum serviço
        agenda_seed_default_services_if_empty($pdo, $companyId);

        $sql = 'SELECT service_key, label, price, duration_minutes, is_active
                FROM services
                WHERE company_id = ?';

        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $catalog = [];
        foreach ($rows as $r) {
            $key = (string)($r['service_key'] ?? '');
            if ($key === '') continue;

            $catalog[$key] = [
                'label' => (string)($r['label'] ?? $key),
                'price' => (float)($r['price'] ?? 0),
                'duration' => (int)($r['duration_minutes'] ?? 30),
                'is_active' => (int)($r['is_active'] ?? 1),
            ];
        }

        if (empty($catalog)) {
            return agenda_default_services_catalog();
        }

        return $catalog;
    } catch (Throwable $e) {
        return agenda_default_services_catalog();
    }
}

/**
 * Seed inicial de serviços no banco (se não houver nenhum serviço pra empresa)
 */
function agenda_seed_default_services_if_empty(PDO $pdo, int $companyId): void
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM services WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) return;

        $defaults = agenda_default_services_catalog();
        $now = now_utc_datetime();

        $insert = $pdo->prepare('
            INSERT INTO services (company_id, service_key, label, price, duration_minutes, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        ');

        foreach ($defaults as $key => $svc) {
            $insert->execute([
                $companyId,
                (string)$key,
                (string)($svc['label'] ?? $key),
                (float)($svc['price'] ?? 0),
                (int)($svc['duration'] ?? 30),
                $now,
                $now,
            ]);
        }
    } catch (Throwable $e) {
        // ignora
    }
}

/**
 * CRUD básico de serviços (internal)
 */
function agenda_service_create_or_update(PDO $pdo, int $companyId, string $serviceKey, string $label, float $price, int $durationMinutes, bool $isActive = true): bool
{
    $serviceKey = trim($serviceKey);
    $label = trim($label);
    if ($companyId <= 0 || $serviceKey === '' || $label === '') return false;

    $durationMinutes = max(5, (int)$durationMinutes);
    $price = max(0, (float)$price);
    $now = now_utc_datetime();
    $activeInt = $isActive ? 1 : 0;

    try {
        $stmt = $pdo->prepare('
            INSERT INTO services (company_id, service_key, label, price, duration_minutes, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                price = VALUES(price),
                duration_minutes = VALUES(duration_minutes),
                is_active = VALUES(is_active),
                updated_at = VALUES(updated_at)
        ');
        $stmt->execute([$companyId, $serviceKey, $label, $price, $durationMinutes, $activeInt, $now, $now]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function agenda_service_delete(PDO $pdo, int $companyId, string $serviceKey): bool
{
    $serviceKey = trim($serviceKey);
    if ($companyId <= 0 || $serviceKey === '') return false;

    try {
        $stmt = $pdo->prepare('DELETE FROM services WHERE company_id = ? AND service_key = ?');
        $stmt->execute([$companyId, $serviceKey]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * =========================
 * Clientes (customers)
 * =========================
 * Cria/atualiza pelo telefone e retorna customer_id.
 */
function agenda_upsert_customer(PDO $pdo, int $companyId, string $name, string $phone, ?string $instagram = null): ?int
{
    $name = trim($name);
    $phone = trim($phone);
    $instagram = $instagram !== null ? trim($instagram) : null;

    if ($companyId <= 0 || $name === '' || $phone === '') return null;

    try {
        $now = now_utc_datetime();

        $stmt = $pdo->prepare('
            INSERT INTO customers (company_id, name, phone, instagram, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                instagram = VALUES(instagram),
                updated_at = VALUES(updated_at)
        ');
        $stmt->execute([$companyId, $name, $phone, ($instagram !== '' ? $instagram : null), $now, $now]);

        $stmt2 = $pdo->prepare('SELECT id FROM customers WHERE company_id = ? AND phone = ? LIMIT 1');
        $stmt2->execute([$companyId, $phone]);
        $id = $stmt2->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * =========================
 * Caixa (cash_movements)
 * =========================
 */
function agenda_create_cash_income_for_appointment(
    PDO $pdo,
    int $companyId,
    float $amount,
    string $description,
    int $appointmentId,
    string $status = 'pending'
): ?int {
    $amount = max(0, (float)$amount);
    $description = trim($description);
    if ($companyId <= 0 || $amount <= 0 || $description === '' || $appointmentId <= 0) return null;

    $allowed = ['pending', 'paid', 'canceled'];
    if (!in_array($status, $allowed, true)) $status = 'pending';

    try {
        $stmt = $pdo->prepare('
            INSERT INTO cash_movements (company_id, type, amount, description, status, reference_type, reference_id, created_at)
            VALUES (?, "income", ?, ?, ?, "appointment", ?, NOW())
        ');
        $stmt->execute([$companyId, $amount, $description, $status, $appointmentId]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * =========================
 * Utilidades de serviços
 * =========================
 */
function agenda_normalize_services($input, array $catalog): array
{
    if (!is_array($input)) return [];

    $valid = [];
    foreach ($input as $key) {
        $key = trim((string)$key);
        if ($key !== '' && isset($catalog[$key])) {
            $valid[] = $key;
        }
    }

    return array_values(array_unique($valid));
}

function agenda_calculate_services(array $serviceKeys, array $catalog): array
{
    $totalPrice = 0.0;
    $totalMinutes = 0;
    $labels = [];

    foreach ($serviceKeys as $key) {
        if (!isset($catalog[$key])) continue;

        $service = $catalog[$key];
        $totalPrice += (float)($service['price'] ?? 0);
        $totalMinutes += (int)($service['duration'] ?? 0);
        $labels[] = (string)($service['label'] ?? $key);
    }

    return [
        'total_price' => $totalPrice,
        'total_minutes' => $totalMinutes,
        'labels' => $labels,
    ];
}

/**
 * =========================
 * Slots / horários
 * =========================
 */
function agenda_minutes_to_slots(int $minutes, int $intervalMinutes): int
{
    if ($intervalMinutes <= 0) return 1;
    $minutes = max(0, $minutes);
    return max(1, (int)ceil($minutes / $intervalMinutes));
}

/**
 * Inclui o horário final (ex.: 20:00)
 * Ex: start=09:00 end=20:00 interval=60 => 09:00 ... 20:00
 */
function agenda_generate_time_slots(string $start, string $end, int $intervalMinutes): array
{
    $slots = [];
    $current = DateTime::createFromFormat('H:i', $start);
    $endTime = DateTime::createFromFormat('H:i', $end);

    if (!$current || !$endTime || $intervalMinutes <= 0) return $slots;

    while ($current <= $endTime) {
        $slots[] = $current->format('H:i');
        $current->modify('+' . $intervalMinutes . ' minutes');
    }

    return $slots;
}

function agenda_build_time_slot_index(array $timeSlots): array
{
    $index = [];
    foreach ($timeSlots as $i => $slot) $index[$slot] = $i;
    return $index;
}

function agenda_calculate_end_time(string $startTime, int $durationMinutes): string
{
    $start = DateTime::createFromFormat('H:i', $startTime);
    if (!$start) return $startTime . ':00';

    $start->modify('+' . max(0, $durationMinutes) . ' minutes');
    return $start->format('H:i:s');
}

/**
 * =========================
 * Barbeiros
 * =========================
 */
function agenda_seed_default_barbers(PDO $pdo, int $companyId): void
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM barbers WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) return;

        $now = now_utc_datetime();
        $seed = [
            ['Pedro', 1],
            ['Samuel', 1],
            ['Barbeiro 3', 0],
        ];

        $insert = $pdo->prepare('
            INSERT INTO barbers (company_id, name, is_active, created_at)
            VALUES (?, ?, ?, ?)
        ');

        foreach ($seed as $row) {
            $insert->execute([$companyId, $row[0], $row[1], $now]);
        }
    } catch (Throwable $e) {
        // ignora
    }
}

function agenda_get_barbers(PDO $pdo, int $companyId, bool $onlyActive = true): array
{
    $sql = 'SELECT id, name, is_active FROM barbers WHERE company_id = ?';
    if ($onlyActive) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY id ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * =========================
 * Bloqueios (calendar_blocks)
 * =========================
 * Retorna os horários bloqueados no formato "HH:ii"
 */
function agenda_get_calendar_blocks(PDO $pdo, int $companyId, string $date, array $fallbackBlocks = []): array
{
    try {
        $stmt = $pdo->prepare('
            SELECT time
            FROM calendar_blocks
            WHERE company_id = ? AND date = ?
        ');
        $stmt->execute([$companyId, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $blocks = [];
        foreach ($rows as $row) {
            $blocks[] = substr((string)($row['time'] ?? ''), 0, 5);
        }

        return array_values(array_unique(array_filter($blocks)));
    } catch (Throwable $e) {
        return $fallbackBlocks;
    }
}

/**
 * =========================
 * Agendamentos / ocupação
 * =========================
 */
function agenda_get_appointments_for_date(PDO $pdo, int $companyId, string $date): array
{
    $stmt = $pdo->prepare('
        SELECT
            a.id,
            a.company_id,
            a.barber_id,
            a.customer_id,
            a.customer_name,
            a.phone,
            a.instagram,
            a.date,
            a.time,
            a.ends_at_time,
            a.services_json,
            a.total_price,
            a.total_duration_minutes,
            a.status,
            b.name AS barber_name
        FROM appointments a
        LEFT JOIN barbers b ON b.id = a.barber_id
        WHERE a.company_id = ? AND a.date = ? AND a.status = "agendado"
        ORDER BY a.time ASC, a.customer_name ASC
    ');
    $stmt->execute([$companyId, $date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function agenda_build_occupancy_map(array $appointments, array $timeSlots, int $intervalMinutes): array
{
    $index = agenda_build_time_slot_index($timeSlots);
    $occupancy = [];

    foreach ($appointments as $appt) {
        $barberId = (int)($appt['barber_id'] ?? 0);
        $start = substr((string)($appt['time'] ?? ''), 0, 5);

        if (!isset($index[$start])) continue;

        $duration = (int)($appt['total_duration_minutes'] ?? 0);
        if ($duration <= 0) $duration = $intervalMinutes;

        $slotsNeeded = agenda_minutes_to_slots($duration, $intervalMinutes);
        $startIndex = $index[$start];

        for ($i = 0; $i < $slotsNeeded; $i++) {
            $slotIndex = $startIndex + $i;
            if (!isset($timeSlots[$slotIndex])) break;

            $slot = $timeSlots[$slotIndex];
            $occupancy[$barberId][$slot] = [
                'appointment' => $appt,
                'is_start' => $i === 0,
                'slots_needed' => $slotsNeeded,
                'duration_minutes' => $duration,
                'start_time' => $start,
            ];
        }
    }

    return $occupancy;
}

function agenda_is_barber_available(
    int $barberId,
    string $slot,
    int $slotsNeeded,
    array $timeSlots,
    array $blockedSlots,
    array $occupancy,
    ?array $slotIndex = null
): bool {
    if ($barberId <= 0) return false;

    $slotIndex = $slotIndex ?? agenda_build_time_slot_index($timeSlots);
    if (!isset($slotIndex[$slot])) return false;

    $startIndex = $slotIndex[$slot];

    for ($i = 0; $i < $slotsNeeded; $i++) {
        $currentIndex = $startIndex + $i;
        if (!isset($timeSlots[$currentIndex])) return false;

        $currentSlot = $timeSlots[$currentIndex];

        if (in_array($currentSlot, $blockedSlots, true)) return false;
        if (isset($occupancy[$barberId][$currentSlot])) return false;
    }

    return true;
}

function agenda_count_available_barbers(
    array $barbers,
    string $slot,
    int $slotsNeeded,
    array $timeSlots,
    array $blockedSlots,
    array $occupancy,
    ?array $slotIndex = null
): int {
    $count = 0;
    foreach ($barbers as $barber) {
        $barberId = (int)($barber['id'] ?? 0);
        if (agenda_is_barber_available($barberId, $slot, $slotsNeeded, $timeSlots, $blockedSlots, $occupancy, $slotIndex)) {
            $count++;
        }
    }
    return $count;
}

function agenda_services_from_json(?string $servicesJson, array $catalog): array
{
    if (!$servicesJson) return [];

    $decoded = json_decode($servicesJson, true);
    if (!is_array($decoded)) return [];

    $labels = [];
    foreach ($decoded as $key) {
        $key = (string)$key;
        if (isset($catalog[$key])) {
            $labels[] = (string)($catalog[$key]['label'] ?? $key);
        }
    }

    return $labels;
}

/**
 * =========================
 * WhatsApp (env)
 * =========================
 */
function send_whatsapp_message(string $phone, string $message): bool
{
    $apiUrl = getenv('WHATSAPP_API_URL') ?: '';
    $apiToken = getenv('WHATSAPP_API_TOKEN') ?: '';

    if ($apiUrl === '' || $apiToken === '') {
        agenda_log_whatsapp_fallback($phone, $message);
        return false;
    }

    $payload = json_encode([
        'phone' => $phone,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        agenda_log_whatsapp_fallback($phone, $message);
        return false;
    }

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiToken,
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status >= 200 && $status < 300) return true;

            agenda_log_whatsapp_fallback($phone, $message, is_string($response) ? $response : '');
            return false;
        }

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiToken}\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ];
        $context = stream_context_create($opts);
        $result = file_get_contents($apiUrl, false, $context);

        if ($result !== false) return true;

        agenda_log_whatsapp_fallback($phone, $message);
    } catch (Throwable $e) {
        agenda_log_whatsapp_fallback($phone, $message, $e->getMessage());
    }

    return false;
}

function agenda_log_whatsapp_fallback(string $phone, string $message, string $extra = ''): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $line = '[' . now_app_datetime() . '] ' . $phone . ' - ' . $message;
    if ($extra !== '') $line .= ' | ' . $extra;
    $line .= PHP_EOL;

    @file_put_contents($dir . '/whatsapp.log', $line, FILE_APPEND);
}
