<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// só usuários logados podem ver
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

if (!$companyId) {
    echo 'Empresa não encontrada na sessão.';
    exit;
}

// configurações da agenda (devem bater com a agenda pública)
$MAX_PER_SLOT         = 2;       // nº de vagas por horário
$SLOT_INTERVAL_MINUTES = 30;     // duração do atendimento
$OPEN_TIME            = '09:00';
$CLOSE_TIME           = '19:00';

// horários bloqueados (ex.: almoço) – MESMOS da agenda pública
$BLOCKED_SLOTS = [
    // '11:00',
    // '11:30',
    // '12:00',
    // '12:30',
];

// data selecionada
$today = new DateTimeImmutable('today');
$selectedDateStr = $_GET['data'] ?? $today->format('Y-m-d');

$selectedDate = DateTime::createFromFormat('Y-m-d', $selectedDateStr);
if (!$selectedDate) {
    $selectedDate = DateTime::createFromFormat('Y-m-d', $today->format('Y-m-d'));
    $selectedDateStr = $selectedDate->format('Y-m-d');
}

// gera slots entre abertura e fechamento
function generate_time_slots(string $start, string $end, int $intervalMinutes): array
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

$timeSlots = generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);

// carrega empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// carrega agendamentos do dia
$stmt = $pdo->prepare('
    SELECT id, customer_name, phone, instagram, date, time
    FROM appointments
    WHERE company_id = ? AND date = ?
    ORDER BY time ASC, customer_name ASC
');
$stmt->execute([$companyId, $selectedDateStr]);
$appointments = $stmt->fetchAll();

// índice por horário
$appointmentsByTime = [];
foreach ($appointments as $appt) {
    $timeKey = substr($appt['time'], 0, 5); // HH:MM
    if (!isset($appointmentsByTime[$timeKey])) {
        $appointmentsByTime[$timeKey] = [];
    }
    $appointmentsByTime[$timeKey][] = $appt;
}

// url da própria página
$selfUrl = BASE_URL . '/calendar_barbearia.php';
?>
<?php include __DIR__ . '/views/partials/header.php'; ?>
<?php include __DIR__ . '/views/partials/sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Agenda Barbearia</h1>
            <p class="page-subtitle">
                Visualize os horários agendados pela agenda online da barbearia.
            </p>
        </div>
        <div class="page-header-meta">
            <?php if ($company): ?>
                <span class="badge badge-soft">
                    <?= sanitize($company['nome_fantasia']) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form action="<?= sanitize($selfUrl) ?>" method="get" class="form-inline gap-3">
                <div class="form-group">
                    <label class="form-label">Data</label>
                    <input
                        type="date"
                        name="data"
                        value="<?= sanitize($selectedDateStr) ?>"
                        class="form-control"
                    >
                </div>

                <button type="submit" class="btn btn-primary mt-3 mt-md-0">
                    Atualizar
                </button>
            </form>
        </div>
    </div>

    <!-- Visão em grade: horários do dia -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="card-title mb-0">
                    Horários do dia <?= $selectedDate->format('d/m/Y') ?>
                </h2>
                <div class="d-flex gap-2 small text-muted">
                    <div class="d-flex align-items-center gap-1">
                        <span class="legend-square bg-success"></span> Vagas disponíveis
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <span class="legend-square bg-warning"></span> Parcialmente ocupado
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <span class="legend-square bg-danger"></span> Lotado
                    </div>
                </div>
            </div>

            <div class="schedule-grid">
                <?php foreach ($timeSlots as $slot): ?>
                    <?php
                    if (in_array($slot, $BLOCKED_SLOTS, true)) {
                        // horário bloqueado: ignore ou estilize diferente se quiser
                        continue;
                    }

                    $slotAppointments = $appointmentsByTime[$slot] ?? [];
                    $count = count($slotAppointments);

                    if ($count === 0) {
                        $statusClass = 'slot-free';
                        $statusLabel = 'Livre';
                    } elseif ($count < $MAX_PER_SLOT) {
                        $statusClass = 'slot-partial';
                        $statusLabel = $count . ' de ' . $MAX_PER_SLOT . ' vagas';
                    } else {
                        $statusClass = 'slot-full';
                        $statusLabel = 'Lotado';
                    }
                    ?>
                    <div class="schedule-slot <?= $statusClass ?>">
                        <div class="slot-time"><?= sanitize($slot) ?></div>
                        <div class="slot-status"><?= sanitize($statusLabel) ?></div>
                        <?php if ($count > 0): ?>
                            <ul class="slot-list">
                                <?php foreach ($slotAppointments as $appt): ?>
                                    <li>
                                        <?= sanitize($appt['customer_name']) ?>
                                        <?php if (!empty($appt['instagram'])): ?>
                                            <span class="text-muted">(@<?= sanitize(ltrim($appt['instagram'], '@')) ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="text-muted small mt-2">
                Os horários e clientes acima vêm automaticamente dos agendamentos feitos na agenda pública da barbearia.
            </p>
        </div>
    </div>

    <!-- Tabela detalhada -->
    <div class="card">
        <div class="card-body">
            <h2 class="card-title mb-3">Agendamentos do dia</h2>

            <?php if (empty($appointments)): ?>
                <p class="text-muted mb-0">Nenhum agendamento para este dia.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Horário</th>
                            <th>Cliente</th>
                            <th>Telefone</th>
                            <th>Instagram</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($appointments as $appt): ?>
                            <tr>
                                <td><?= substr($appt['time'], 0, 5) ?></td>
                                <td><?= sanitize($appt['customer_name']) ?></td>
                                <td><?= sanitize($appt['phone']) ?></td>
                                <td>
                                    <?php if (!empty($appt['instagram'])): ?>
                                        <?= sanitize($appt['instagram']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
    /* Aproveita o CSS do painel e só complementa a grade da agenda */

    .schedule-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 0.75rem;
    }

    .schedule-slot {
        border-radius: 0.75rem;
        padding: 0.5rem 0.75rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        background: #ffffff;
    }

    .schedule-slot .slot-time {
        font-weight: 600;
        font-size: 0.9rem;
    }

    .schedule-slot .slot-status {
        font-size: 0.75rem;
        margin-top: 0.1rem;
    }

    .schedule-slot .slot-list {
        margin: 0.35rem 0 0;
        padding-left: 1rem;
        font-size: 0.78rem;
    }

    .slot-free {
        border-color: rgba(16, 185, 129, 0.25);
        background: rgba(16, 185, 129, 0.05);
    }

    .slot-partial {
        border-color: rgba(245, 158, 11, 0.25);
        background: rgba(245, 158, 11, 0.05);
    }

    .slot-full {
        border-color: rgba(239, 68, 68, 0.25);
        background: rgba(239, 68, 68, 0.05);
    }

    .legend-square {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        display: inline-block;
    }

    .legend-square.bg-success {
        background: rgba(16, 185, 129, 1);
    }

    .legend-square.bg-warning {
        background: rgba(245, 158, 11, 1);
    }

    .legend-square.bg-danger {
        background: rgba(239, 68, 68, 1);
    }
</style>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
