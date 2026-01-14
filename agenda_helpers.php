<?php
require_once __DIR__ . '/helpers.php';

function agenda_get_services_catalog(): array
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

function agenda_normalize_services($input, array $catalog): array
{
    if (!is_array($input)) {
        return [];
    }

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
        if (!isset($catalog[$key])) {
            continue;
        }

        $service = $catalog[$key];
        $totalPrice += (float)$service['price'];
        $totalMinutes += (int)$service['duration'];
        $labels[] = $service['label'];
    }

    return [
        'total_price' => $totalPrice,
        'total_minutes' => $totalMinutes,
        'labels' => $labels,
    ];
}

function agenda_minutes_to_slots(int $minutes, int $intervalMinutes): int
{
    if ($intervalMinutes <= 0) {
        return 1;
    }
    $minutes = max(0, $minutes);
    return max(1, (int)ceil($minutes / $intervalMinutes));
}

function agenda_generate_time_slots(string $start, string $end, int $intervalMinutes): array
{
    $slots = [];
    $current = DateTime::createFromFormat('H:i', $start);
    $endTime = DateTime::createFromFormat('H:i', $end);

    if (!$current || !$endTime) {
        return $slots;
    }

    while ($current < $endTime) {
        $slots[] = $current->format('H:i');
        $current->modify('+' . $intervalMinutes . ' minutes');
    }

    return $slots;
}

function agenda_build_time_slot_index(array $timeSlots): array
{
    $index = [];
    foreach ($timeSlots as $i => $slot) {
        $index[$slot] = $i;
    }
    return $index;
}

function agenda_calculate_end_time(string $startTime, int $durationMinutes): string
{
    $start = DateTime::createFromFormat('H:i', $startTime);
    if (!$start) {
        return $startTime . ':00';
    }

    $start->modify('+' . max(0, $durationMinutes) . ' minutes');
    return $start->format('H:i:s');
}

function agenda_seed_default_barbers(PDO $pdo, int $companyId): void
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM barbers WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            return;
        }

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
        // Ignora se a tabela ainda nao existir.
    }
}

function agenda_get_barbers(PDO $pdo, int $companyId, bool $onlyActive = true): array
{
    $sql = 'SELECT id, name, is_active FROM barbers WHERE company_id = ?';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY id ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function agenda_get_calendar_blocks(PDO $pdo, int $companyId, string $date, array $fallbackBlocks): array
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
            $blocks[] = substr((string)$row['time'], 0, 5);
        }

        if (!empty($blocks)) {
            return array_values(array_unique($blocks));
        }
    } catch (Throwable $e) {
        return $fallbackBlocks;
    }

    return $fallbackBlocks;
}

function agenda_get_appointments_for_date(PDO $pdo, int $companyId, string $date): array
{
    $stmt = $pdo->prepare('
        SELECT a.id, a.customer_name, a.phone, a.instagram, a.date, a.time,
               a.barber_id, a.services_json, a.total_price, a.total_duration_minutes,
               a.ends_at_time, b.name AS barber_name
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
        $start = substr((string)$appt['time'], 0, 5);

        if (!isset($index[$start])) {
            continue;
        }

        $duration = (int)($appt['total_duration_minutes'] ?? 0);
        if ($duration <= 0) {
            $duration = $intervalMinutes;
        }

        $slotsNeeded = agenda_minutes_to_slots($duration, $intervalMinutes);
        $startIndex = $index[$start];

        for ($i = 0; $i < $slotsNeeded; $i++) {
            $slotIndex = $startIndex + $i;
            if (!isset($timeSlots[$slotIndex])) {
                break;
            }

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
    if ($barberId <= 0) {
        return false;
    }

    $slotIndex = $slotIndex ?? agenda_build_time_slot_index($timeSlots);
    if (!isset($slotIndex[$slot])) {
        return false;
    }

    $startIndex = $slotIndex[$slot];
    for ($i = 0; $i < $slotsNeeded; $i++) {
        $currentIndex = $startIndex + $i;
        if (!isset($timeSlots[$currentIndex])) {
            return false;
        }

        $currentSlot = $timeSlots[$currentIndex];
        if (in_array($currentSlot, $blockedSlots, true)) {
            return false;
        }

        if (isset($occupancy[$barberId][$currentSlot])) {
            return false;
        }
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
        $barberId = (int)$barber['id'];
        if (agenda_is_barber_available($barberId, $slot, $slotsNeeded, $timeSlots, $blockedSlots, $occupancy, $slotIndex)) {
            $count++;
        }
    }
    return $count;
}

function agenda_services_from_json(?string $servicesJson, array $catalog): array
{
    if (!$servicesJson) {
        return [];
    }

    $decoded = json_decode($servicesJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $labels = [];
    foreach ($decoded as $key) {
        if (isset($catalog[$key])) {
            $labels[] = $catalog[$key]['label'];
        }
    }

    return $labels;
}

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

            if ($status >= 200 && $status < 300) {
                return true;
            }

            agenda_log_whatsapp_fallback($phone, $message, $response);
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
        if ($result !== false) {
            return true;
        }

        agenda_log_whatsapp_fallback($phone, $message);
    } catch (Throwable $e) {
        agenda_log_whatsapp_fallback($phone, $message, $e->getMessage());
    }

    return false;
}

function agenda_log_whatsapp_fallback(string $phone, string $message, string $extra = ''): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $line = '[' . now_app_datetime() . '] ' . $phone . ' - ' . $message;
    if ($extra !== '') {
        $line .= ' | ' . $extra;
    }
    $line .= PHP_EOL;

    @file_put_contents($dir . '/whatsapp.log', $line, FILE_APPEND);
}
