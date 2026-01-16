<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();

$pdo = get_pdo();
$companyId = current_company_id();
if (!$companyId) {
    echo 'Empresa nao encontrada.';
    exit;
}

/**
 * ============================
 * CONFIG
 * ============================
 */
$SLOT_INTERVAL_MINUTES = 60;
$OPEN_TIME  = '09:00';
$CLOSE_TIME = '20:00';

/**
 * ============================
 * DATA
 * ============================
 */
$today = new DateTimeImmutable('today');
$selectedDateStr = $_GET['data'] ?? $_POST['data'] ?? $today->format('Y-m-d');
$selectedDate = DateTime::createFromFormat('Y-m-d', $selectedDateStr) ?: new DateTime($today->format('Y-m-d'));

/**
 * ============================
 * DADOS BASE
 * ============================
 */
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$timeSlots = agenda_generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);

agenda_seed_default_barbers($pdo, $companyId);
$barbers = agenda_get_barbers($pdo, $companyId, false);
$activeBarbers = array_filter($barbers, fn($b) => (int)$b['is_active'] === 1);

$appointments = agenda_get_appointments_for_date($pdo, $companyId, $selectedDateStr);
$occupancy = agenda_build_occupancy_map($appointments, $timeSlots, $SLOT_INTERVAL_MINUTES);

/**
 * ============================
 * BLOQUEIOS (POST)
 * ============================
 */
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'block') {
    $barberId = (int)($_POST['barber_id'] ?? 0);
    $slots = $_POST['slots'] ?? [];
    $reason = trim($_POST['reason'] ?? '');

    if ($barberId <= 0) {
        $errors[] = 'Barbeiro invalido.';
    }
    if (empty($slots)) {
        $errors[] = 'Selecione ao menos um horario.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('
            INSERT INTO calendar_blocks
                (company_id, barber_id, date, time, reason, created_by_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE reason = VALUES(reason)
        ');
        foreach ($slots as $slot) {
            $stmt->execute([
                $companyId,
                $barberId,
                $selectedDateStr,
                $slot . ':00',
                $reason ?: null,
                $_SESSION['user_id'] ?? null,
                now_utc_datetime()
            ]);
        }
        $success = 'Bloqueio aplicado com sucesso.';
    }
}

/**
 * ============================
 * BLOQUEIOS POR BARBEIRO
 * ============================
 */
$blocksByBarber = [];
foreach ($activeBarbers as $barber) {
    $stmt = $pdo->prepare('
        SELECT time FROM calendar_blocks
        WHERE company_id = ? AND date = ? AND barber_id = ?
    ');
    $stmt->execute([$companyId, $selectedDateStr, $barber['id']]);
    $blocksByBarber[$barber['id']] = array_map(
        fn($r) => substr($r['time'], 0, 5),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

include __DIR__ . '/views/partials/header.php';
?>

<main class="flex-1 bg-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto px-6 py-6 space-y-6">

<header class="flex justify-between items-center">
    <h1 class="text-2xl font-bold">Agenda Interna</h1>
    <form method="get">
        <input type="date" name="data" value="<?= sanitize($selectedDateStr) ?>"
               class="border rounded px-3 py-2">
        <button class="bg-indigo-600 text-white px-4 py-2 rounded">Atualizar</button>
    </form>
</header>

<?php if ($errors): ?>
<div class="bg-red-100 border border-red-300 p-3 rounded">
    <?php foreach ($errors as $e): ?><p><?= sanitize($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="bg-green-100 border border-green-300 p-3 rounded">
    <?= sanitize($success) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-[120px_repeat(auto-fit,minmax(220px,1fr))] gap-2">

<!-- COLUNA HORARIOS -->
<div>
    <div class="font-semibold text-center mb-2">Hora</div>
    <?php foreach ($timeSlots as $slot): ?>
        <div class="h-16 flex items-center justify-center border bg-white">
            <?= sanitize($slot) ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- COLUNAS BARBEIROS -->
<?php foreach ($activeBarbers as $barber): ?>
<div>
    <div class="font-semibold text-center mb-2"><?= sanitize($barber['name']) ?></div>

    <?php foreach ($timeSlots as $slot): ?>
        <?php
        $isBlocked = in_array($slot, $blocksByBarber[$barber['id']] ?? [], true);
        $isOccupied = isset($occupancy[$barber['id']][$slot]);
        ?>

        <div class="h-16 border flex items-center justify-center text-xs
            <?= $isBlocked ? 'bg-amber-200' : ($isOccupied ? 'bg-rose-200' : 'bg-emerald-100') ?>">
            <?php if ($isBlocked): ?>
                Bloqueado
            <?php elseif ($isOccupied): ?>
                Ocupado
            <?php else: ?>
                Livre
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- FORM BLOQUEIO -->
    <form method="post" class="mt-2 p-2 border rounded bg-white space-y-2">
        <input type="hidden" name="action" value="block">
        <input type="hidden" name="barber_id" value="<?= (int)$barber['id'] ?>">
        <input type="hidden" name="data" value="<?= sanitize($selectedDateStr) ?>">

        <select name="slots[]" multiple class="w-full border rounded text-xs">
            <?php foreach ($timeSlots as $slot): ?>
                <option value="<?= sanitize($slot) ?>"><?= sanitize($slot) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="reason" placeholder="Motivo (opcional)"
               class="w-full border rounded px-2 py-1 text-xs">

        <button class="w-full bg-indigo-600 text-white py-1 rounded text-xs">
            Bloquear horários
        </button>
    </form>
</div>
<?php endforeach; ?>

</div>
</div>
</main>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
