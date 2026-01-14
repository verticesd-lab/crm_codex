<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';

$pdo = get_pdo();

$now = new DateTime('now', new DateTimeZone(app_timezone()));
$windowStart = (clone $now)->modify('+85 minutes');
$windowEnd = (clone $now)->modify('+95 minutes');

$catalog = agenda_get_services_catalog();

function agenda_fetch_reminder_candidates(PDO $pdo, string $date, string $startTime, string $endTime): array
{
    $stmt = $pdo->prepare('
        SELECT a.id, a.company_id, a.customer_name, a.phone, a.date, a.time,
               a.services_json, a.total_price, a.barber_id,
               b.name AS barber_name, c.nome_fantasia AS company_name
        FROM appointments a
        LEFT JOIN barbers b ON b.id = a.barber_id
        LEFT JOIN companies c ON c.id = a.company_id
        WHERE a.status = "agendado"
          AND a.reminder_sent_at IS NULL
          AND a.date = ?
          AND a.time BETWEEN ? AND ?
        ORDER BY a.time ASC
    ');
    $stmt->execute([$date, $startTime, $endTime]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$appointments = [];
if ($windowStart->format('Y-m-d') === $windowEnd->format('Y-m-d')) {
    $appointments = agenda_fetch_reminder_candidates(
        $pdo,
        $windowStart->format('Y-m-d'),
        $windowStart->format('H:i:s'),
        $windowEnd->format('H:i:s')
    );
} else {
    $appointments = array_merge(
        agenda_fetch_reminder_candidates(
            $pdo,
            $windowStart->format('Y-m-d'),
            $windowStart->format('H:i:s'),
            '23:59:59'
        ),
        agenda_fetch_reminder_candidates(
            $pdo,
            $windowEnd->format('Y-m-d'),
            '00:00:00',
            $windowEnd->format('H:i:s')
        )
    );
}

if (empty($appointments)) {
    return;
}

$update = $pdo->prepare('UPDATE appointments SET reminder_sent_at = ? WHERE id = ?');

foreach ($appointments as $appt) {
    $services = agenda_services_from_json($appt['services_json'] ?? '', $catalog);
    $servicesLabel = $services ? implode(', ', $services) : 'Servico nao informado';

    $dateObj = DateTime::createFromFormat('Y-m-d', (string)$appt['date']);
    $dateLabel = $dateObj ? $dateObj->format('d/m/Y') : (string)$appt['date'];
    $timeLabel = substr((string)$appt['time'], 0, 5);

    $barberName = $appt['barber_name'] ? (string)$appt['barber_name'] : 'Barbeiro';
    $companyName = $appt['company_name'] ? (string)$appt['company_name'] : 'Barbearia';
    $totalPrice = isset($appt['total_price']) ? format_currency($appt['total_price']) : '';

    $message = "Lembrete do seu horario na {$companyName}:\n";
    $message .= "Data: {$dateLabel}\n";
    $message .= "Hora: {$timeLabel}\n";
    $message .= "Barbeiro: {$barberName}\n";
    $message .= "Servicos: {$servicesLabel}";
    if ($totalPrice !== '') {
        $message .= "\nTotal: {$totalPrice}";
    }

    $sent = send_whatsapp_message((string)$appt['phone'], $message);
    if ($sent) {
        $update->execute([now_utc_datetime(), (int)$appt['id']]);
    }
}
