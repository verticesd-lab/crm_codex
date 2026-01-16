<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

if (!$companyId) {
    die('Empresa não encontrada.');
}

/**
 * ============================
 * CONFIGURAÇÃO DA AGENDA
 * ============================
 */
$SLOT_INTERVAL_MINUTES = 30;
$OPEN_TIME  = '09:00';
$CLOSE_TIME = '20:00';

/**
 * ============================
 * DATA
 * ============================
 */
$today = new DateTimeImmutable('today');
$selectedDateStr = $_GET['data'] ?? $today->format('Y-m-d');
$selectedDate = DateTime::createFromFormat('Y-m-d', $selectedDateStr) ?: new DateTime();

/**
 * ============================
 * DADOS BASE
 * ============================
 */
$timeSlots = agenda_generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);

agenda_seed_default_barbers($pdo, $companyId);
$barbers = agenda_get_barbers($pdo, $companyId, true);

$servicesCatalog = agenda_get_services_catalog();
$appointments = agenda_get_appointments_for_date($pdo, $companyId, $selectedDateStr);
$blockedSlots = agenda_get_calendar_blocks($pdo, $companyId, $selectedDateStr);

$occupancy = agenda_build_occupancy_map(
    $appointments,
    $timeSlots,
    $SLOT_INTERVAL_MINUTES
);

$selfUrl = BASE_URL . '/calendar_barbearia.php';

include __DIR__ . '/views/partials/header.php';
?>

<main class="bg-slate-100 min-h-screen p-6">
    <div class="max-w-7xl mx-auto space-y-6">

        <!-- TOPO -->
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-slate-900">Agenda Interna</h1>

            <form method="get" class="flex items-center gap-2">
                <input type="date" name="data" value="<?= $selectedDateStr ?>"
                       class="border rounded px-3 py-2 text-sm">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded text-sm font-semibold">
                    Atualizar
                </button>
            </form>
        </div>

        <!-- AGENDA -->
        <div class="bg-white rounded-xl shadow border overflow-x-auto">
            <div class="grid" style="grid-template-columns: 100px repeat(<?= count($barbers) ?>, 1fr);">

                <!-- HEADER -->
                <div class="border-b p-3 font-semibold">Hora</div>
                <?php foreach ($barbers as $barber): ?>
                    <div class="border-b p-3 font-semibold text-center">
                        <?= sanitize($barber['name']) ?>
                    </div>
                <?php endforeach; ?>

                <!-- SLOTS -->
                <?php foreach ($timeSlots as $slot): ?>
                    <div class="border-t p-3 text-sm font-semibold text-slate-700">
                        <?= $slot ?>
                    </div>

                    <?php foreach ($barbers as $barber): ?>
                        <?php
                        $barberId = (int)$barber['id'];
                        $entry = $occupancy[$barberId][$slot] ?? null;
                        $isBlocked = in_array($slot, $blockedSlots, true);

                        $bg = 'bg-emerald-50';
                        if ($isBlocked) $bg = 'bg-amber-100';
                        if ($entry) $bg = 'bg-rose-100';
                        ?>

                        <div class="border-t p-2 <?= $bg ?> min-h-[80px] text-xs">

                            <?php if ($isBlocked): ?>
                                <p class="text-amber-700 font-semibold">Bloqueado</p>

                            <?php elseif ($entry): ?>
                                <?php
                                $appt = $entry['appointment'];
                                $services = agenda_services_from_json($appt['services_json'], $servicesCatalog);
                                $endsAt = $appt['ends_at_time']
                                    ? substr($appt['ends_at_time'], 0, 5)
                                    : substr(
                                        agenda_calculate_end_time(
                                            substr($appt['time'], 0, 5),
                                            $appt['total_duration_minutes']
                                        ),
                                        0,
                                        5
                                    );
                                ?>

                                <?php if ($entry['is_start']): ?>
                                    <p class="font-semibold text-slate-900">
                                        <?= sanitize($appt['customer_name']) ?>
                                    </p>
                                    <p class="text-slate-600">
                                        <?= implode(', ', $services) ?>
                                    </p>
                                    <p class="text-slate-600">
                                        <?= format_currency($appt['total_price']) ?> · até <?= $endsAt ?>
                                    </p>
                                    <a href="<?= BASE_URL ?>/cancel_appointment.php?id=<?= (int)$appt['id'] ?>&data=<?= $selectedDateStr ?>"
                                       class="text-rose-600 text-[11px]"
                                       onclick="return confirm('Cancelar agendamento?')">
                                        Cancelar
                                    </a>
                                <?php else: ?>
                                    <p class="text-slate-600">Em atendimento</p>
                                <?php endif; ?>

                            <?php else: ?>
                                <p class="text-emerald-700">Livre</p>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            </div>
        </div>

        <p class="text-xs text-slate-500">
            Agenda interna baseada nos agendamentos reais. Bloqueios e ocupação são independentes por barbeiro.
        </p>
    </div>
</main>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
