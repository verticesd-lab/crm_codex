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
    echo 'Empresa não encontrada na sessão.';
    exit;
}

// Carrega empresa para mostrar nome no topo
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

/**
 * ============================
 * CONFIG DA AGENDA BARBEARIA
 * (mesmos parâmetros da agenda pública)
 * ============================
 */

// Nº de vagas por horário (nº de barbeiros)
$MAX_PER_SLOT = 2;

// Duração de cada atendimento em minutos (30, 45, 60...)
$SLOT_INTERVAL_MINUTES = 30;

// Horário de funcionamento da barbearia
$OPEN_TIME  = '09:00';
$CLOSE_TIME = '19:00';

// Horários bloqueados (ex.: almoço) – se usar na agenda pública, repete aqui
$BLOCKED_SLOTS = [
    // '11:00',
    // '11:30',
    // '12:00',
    // '12:30',
];

/**
 * ============================
 * DATA SELECIONADA
 * ============================
 */
$today = new DateTimeImmutable('today');
$selectedDateStr = $_GET['data'] ?? $today->format('Y-m-d');

$selectedDate = DateTime::createFromFormat('Y-m-d', $selectedDateStr);
if (!$selectedDate) {
    $selectedDate = DateTime::createFromFormat('Y-m-d', $today->format('Y-m-d'));
    $selectedDateStr = $selectedDate->format('Y-m-d');
}

/**
 * ============================
 * GERA SLOTS DE HORÁRIO
 * ============================
 */
function generate_time_slots(string $start, string $end, int $intervalMinutes): array
{
    $slots   = [];
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

$timeSlots = generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);

/**
 * ============================
 * CARREGA AGENDAMENTOS DO DIA
 * ============================
 */
$stmt = $pdo->prepare('
    SELECT id, customer_name, phone, instagram, date, time
    FROM appointments
    WHERE company_id = ? AND date = ?
    ORDER BY time ASC, customer_name ASC
');
$stmt->execute([$companyId, $selectedDateStr]);
$appointments = $stmt->fetchAll();

// Índice por horário HH:MM
$appointmentsByTime = [];
foreach ($appointments as $appt) {
    $timeKey = substr($appt['time'], 0, 5);
    if (!isset($appointmentsByTime[$timeKey])) {
        $appointmentsByTime[$timeKey] = [];
    }
    $appointmentsByTime[$timeKey][] = $appt;
}

$selfUrl = BASE_URL . '/calendar_barbearia.php';

include __DIR__ . '/views/partials/header.php';
include __DIR__ . '/views/partials/sidebar.php';
?>

<main class="flex-1 bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto px-6 py-6 space-y-6">
        <!-- Cabeçalho da página -->
        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">
                    Agenda da barbearia
                </h1>
                <p class="text-sm text-slate-500 mt-1">
                    Visualize os horários agendados pela agenda online da barbearia.
                </p>
            </div>
            <?php if ($company): ?>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-slate-400">Empresa</p>
                    <p class="font-semibold text-slate-800">
                        <?= sanitize($company['nome_fantasia']) ?>
                    </p>
                </div>
            <?php endif; ?>
        </header>

        <!-- Filtro por data -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <form action="<?= sanitize($selfUrl) ?>" method="get" class="flex flex-col sm:flex-row sm:items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Data
                    </label>
                    <input
                        type="date"
                        name="data"
                        value="<?= sanitize($selectedDateStr) ?>"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 min-w-[180px]"
                    >
                </div>

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-lg bg-indigo-600 text-white text-sm font-semibold px-4 py-2 hover:bg-indigo-500">
                    Atualizar
                </button>
            </form>
        </section>

        <!-- Grade de horários -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">
                        Horários do dia <?= $selectedDate->format('d/m/Y') ?>
                    </h2>
                    <p class="text-xs text-slate-500 mt-1">
                        Cada horário comporta até <span class="font-semibold"><?= $MAX_PER_SLOT ?></span> atendimento(s).
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                    <div class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Livre
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-amber-400"></span> Parcialmente ocupado
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-rose-500"></span> Lotado
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                <?php if (empty($timeSlots)): ?>
                    <p class="text-sm text-slate-500 col-span-full">
                        Nenhum horário configurado para esta agenda.
                    </p>
                <?php else: ?>
                    <?php foreach ($timeSlots as $slot): ?>
                        <?php
                        // pula horários bloqueados (ex.: almoço)
                        if (in_array($slot, $BLOCKED_SLOTS, true)) {
                            continue;
                        }

                        $slotAppointments = $appointmentsByTime[$slot] ?? [];
                        $count = count($slotAppointments);
                        $isFull = $count >= $MAX_PER_SLOT;

                        if ($count === 0) {
                            $border = 'border-emerald-300/50';
                            $bg     = 'bg-emerald-50';
                            $dot    = 'bg-emerald-400';
                            $label  = 'Livre';
                        } elseif ($isFull) {
                            $border = 'border-rose-300/60';
                            $bg     = 'bg-rose-50';
                            $dot    = 'bg-rose-500';
                            $label  = 'Lotado';
                        } else {
                            $border = 'border-amber-300/70';
                            $bg     = 'bg-amber-50';
                            $dot    = 'bg-amber-400';
                            $label  = $count . ' de ' . $MAX_PER_SLOT . ' vagas';
                        }
                        ?>
                        <div class="rounded-xl border <?= $border ?> <?= $bg ?> px-3 py-2.5 flex flex-col gap-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-slate-900">
                                    <?= sanitize($slot) ?>
                                </span>
                                <span class="inline-flex items-center gap-1 text-[11px] text-slate-600">
                                    <span class="h-1.5 w-1.5 rounded-full <?= $dot ?>"></span>
                                    <?= sanitize($label) ?>
                                </span>
                            </div>

                            <?php if ($count > 0): ?>
                                <ul class="space-y-0.5 text-[11px] text-slate-700">
                                    <?php foreach ($slotAppointments as $appt): ?>
                                        <li class="flex flex-col">
                                            <span class="font-medium">
                                                <?= sanitize($appt['customer_name']) ?>
                                            </span>
                                            <span class="text-slate-500">
                                                <?= sanitize($appt['phone']) ?>
                                                <?php if (!empty($appt['instagram'])): ?>
                                                    • @<?= sanitize(ltrim($appt['instagram'], '@')) ?>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-[11px] text-slate-500">
                                    Nenhum agendamento neste horário.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p class="text-[11px] text-slate-500 mt-2">
                Os horários e clientes acima vêm automaticamente dos agendamentos feitos na agenda pública da barbearia
                (tabela <code class="font-mono">appointments</code>).
            </p>
        </section>
    </div>
</main>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
