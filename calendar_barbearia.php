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
$companyId = (int) current_company_id();

if (!$companyId) {
    echo 'Empresa nao encontrada na sessao.';
    exit;
}

// Empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

/**
 * ============================
 * CONFIG
 * ============================
 * OBS: aqui mantemos 30min porque você quer permitir bloqueio de frações (30min).
 */
$SLOT_INTERVAL_MINUTES = 30;
$OPEN_TIME  = '09:00';
$CLOSE_TIME = '20:00';

// Global blocks (feriado/almoço) - agora o padrão é: nada bloqueado
$BLOCKED_SLOTS_FALLBACK = [];

/**
 * ============================
 * DATA
 * ============================
 */
$today           = new DateTimeImmutable('today');
$selectedDateStr = $_GET['data'] ?? $_POST['data'] ?? $today->format('Y-m-d');

$selectedDate = DateTime::createFromFormat('Y-m-d', $selectedDateStr);
if (!$selectedDate) {
    $selectedDate     = DateTime::createFromFormat('Y-m-d', $today->format('Y-m-d'));
    $selectedDateStr  = $selectedDate->format('Y-m-d');
}

/**
 * ============================
 * SLOTS
 * ============================
 */
$timeSlots = agenda_generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);
$slotIndex = agenda_build_time_slot_index($timeSlots);

/**
 * ============================
 * BARBEIROS
 * ============================
 */
agenda_seed_default_barbers($pdo, $companyId);
$barbers = agenda_get_barbers($pdo, $companyId, false);

// Filtra só ativos pra virar colunas (Pedro/Samuel)
$activeBarbers = array_values(array_filter($barbers, function ($b) {
    return (int)($b['is_active'] ?? 0) === 1;
}));

/**
 * ============================
 * FUNÇÕES LOCAIS (blocks por barbeiro)
 * ============================
 */
function barber_blocks_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM barber_blocks LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function get_barber_blocks(PDO $pdo, int $companyId, int $barberId, string $date): array
{
    if (!barber_blocks_table_exists($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT time
        FROM barber_blocks
        WHERE company_id = ? AND barber_id = ? AND date = ?
    ');
    $stmt->execute([$companyId, $barberId, $date]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = substr((string)$row['time'], 0, 5);
    }
    return array_values(array_unique($out));
}

function add_barber_blocks(PDO $pdo, int $companyId, int $barberId, string $date, string $startTimeHHMM, int $minutes, ?string $reason, ?int $userId): void
{
    if (!barber_blocks_table_exists($pdo)) {
        throw new RuntimeException('Tabela barber_blocks nao existe. Rode a migracao.');
    }

    $minutes = max(30, $minutes);
    $chunks  = (int) ceil($minutes / 30);

    $start = DateTime::createFromFormat('H:i', $startTimeHHMM);
    if (!$start) {
        throw new RuntimeException('Horario invalido.');
    }

    $now = now_utc_datetime();

    $ins = $pdo->prepare('
        INSERT INTO barber_blocks (company_id, barber_id, date, time, reason, created_by_user_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by_user_id = VALUES(created_by_user_id)
    ');

    for ($i = 0; $i < $chunks; $i++) {
        $t = $start->format('H:i') . ':00';
        $ins->execute([$companyId, $barberId, $date, $t, ($reason !== '' ? $reason : null), $userId, $now]);
        $start->modify('+30 minutes');
    }
}

function remove_barber_blocks(PDO $pdo, int $companyId, int $barberId, string $date, string $startTimeHHMM, int $minutes): void
{
    if (!barber_blocks_table_exists($pdo)) {
        return;
    }

    $minutes = max(30, $minutes);
    $chunks  = (int) ceil($minutes / 30);

    $start = DateTime::createFromFormat('H:i', $startTimeHHMM);
    if (!$start) {
        return;
    }

    $del = $pdo->prepare('
        DELETE FROM barber_blocks
        WHERE company_id = ? AND barber_id = ? AND date = ? AND time = ?
    ');

    for ($i = 0; $i < $chunks; $i++) {
        $t = $start->format('H:i') . ':00';
        $del->execute([$companyId, $barberId, $date, $t]);
        $start->modify('+30 minutes');
    }
}

/**
 * ============================
 * POST (criar agendamento manual / bloquear / desbloquear por barbeiro)
 * ============================
 */
$uiErrors  = [];
$uiSuccess = null;

$servicesCatalog = agenda_get_services_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    // Sempre valida a data primeiro
    $datePost = trim((string)($_POST['data'] ?? $selectedDateStr));
    $dateObj  = DateTime::createFromFormat('Y-m-d', $datePost);
    if ($dateObj) {
        $selectedDate    = $dateObj;
        $selectedDateStr = $dateObj->format('Y-m-d');
    } else {
        $uiErrors[] = 'Data invalida.';
    }

    $barberId = (int)($_POST['barber_id'] ?? 0);
    $timeHHMM = trim((string)($_POST['time'] ?? ''));

    if (!preg_match('/^\d{2}:\d{2}$/', $timeHHMM) || !isset($slotIndex[$timeHHMM])) {
        $uiErrors[] = 'Horario invalido.';
    }

    // Barbeiro válido?
    $barberMap = [];
    foreach ($activeBarbers as $b) {
        $barberMap[(int)$b['id']] = $b;
    }
    if ($barberId <= 0 || !isset($barberMap[$barberId])) {
        $uiErrors[] = 'Barbeiro invalido.';
    }

    if (empty($uiErrors)) {
        try {
            if ($action === 'block_barber') {
                $minutes = (int)($_POST['block_minutes'] ?? 30);
                $reason  = trim((string)($_POST['reason'] ?? ''));
                $userId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

                add_barber_blocks($pdo, $companyId, $barberId, $selectedDateStr, $timeHHMM, $minutes, $reason, $userId);
                $uiSuccess = 'Horario bloqueado para este barbeiro.';

            } elseif ($action === 'unblock_barber') {
                // remove bloqueios a partir do horário por X minutos
                $minutes = (int)($_POST['block_minutes'] ?? 30);
                remove_barber_blocks($pdo, $companyId, $barberId, $selectedDateStr, $timeHHMM, $minutes);
                $uiSuccess = 'Bloqueio removido para este barbeiro.';

            } elseif ($action === 'book_admin') {
                // Cria um agendamento manual (admin)
                $customerName = trim((string)($_POST['customer_name'] ?? ''));
                $phone        = trim((string)($_POST['phone'] ?? ''));
                $instagram    = trim((string)($_POST['instagram'] ?? ''));

                $selectedServices = agenda_normalize_services($_POST['services'] ?? [], $servicesCatalog);
                $extraMinutes = (int)($_POST['extra_minutes'] ?? 0);
                $extraMinutes = max(0, $extraMinutes);

                if ($customerName === '') {
                    $uiErrors[] = 'Informe o nome do cliente.';
                }
                if ($phone === '') {
                    $uiErrors[] = 'Informe o WhatsApp/telefone.';
                }
                if (empty($selectedServices) && $extraMinutes <= 0) {
                    $uiErrors[] = 'Selecione ao menos um servico ou informe minutos extras.';
                }

                if (empty($uiErrors)) {
                    $calc = agenda_calculate_services($selectedServices, $servicesCatalog);
                    $totalMinutes = (int)$calc['total_minutes'] + $extraMinutes;
                    $totalPrice   = (float)$calc['total_price'];
                    $endsAtTime   = agenda_calculate_end_time($timeHHMM, $totalMinutes);

                    // Recarrega tudo pra validar disponibilidade corretamente
                    $globalBlockedSlots = agenda_get_calendar_blocks($pdo, $companyId, $selectedDateStr, $BLOCKED_SLOTS_FALLBACK);
                    $appointments = agenda_get_appointments_for_date($pdo, $companyId, $selectedDateStr);
                    $occupancy    = agenda_build_occupancy_map($appointments, $timeSlots, $SLOT_INTERVAL_MINUTES);

                    // Barber blocks entram como "ocupado"
                    $barberBlocks = get_barber_blocks($pdo, $companyId, $barberId, $selectedDateStr);
                    $blockedMerged = array_values(array_unique(array_merge($globalBlockedSlots, $barberBlocks)));

                    $slotsNeeded = agenda_minutes_to_slots($totalMinutes > 0 ? $totalMinutes : 30, $SLOT_INTERVAL_MINUTES);

                    if (!agenda_is_barber_available($barberId, $timeHHMM, $slotsNeeded, $timeSlots, $blockedMerged, $occupancy, $slotIndex)) {
                        $uiErrors[] = 'Este horario nao esta disponivel para este barbeiro (ocupado ou bloqueado).';
                    } else {
                        $servicesJson = json_encode($selectedServices, JSON_UNESCAPED_UNICODE);

                        $stmt = $pdo->prepare('
                            INSERT INTO appointments
                                (company_id, barber_id, customer_name, phone, instagram, services_json, total_price,
                                 total_duration_minutes, ends_at_time, date, time, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "agendado", NOW())
                        ');
                        $stmt->execute([
                            $companyId,
                            $barberId,
                            $customerName,
                            $phone,
                            ($instagram !== '' ? $instagram : null),
                            $servicesJson,
                            $totalPrice,
                            $totalMinutes,
                            $endsAtTime,
                            $selectedDateStr,
                            $timeHHMM . ':00',
                        ]);

                        $uiSuccess = 'Agendamento criado com sucesso para este barbeiro.';
                    }
                }
            }
        } catch (Throwable $e) {
            $uiErrors[] = 'Erro ao processar: ' . $e->getMessage();
        }
    }
}

/**
 * ============================
 * CARREGA DADOS DO DIA (para render)
 * ============================
 */
$globalBlockedSlots = agenda_get_calendar_blocks($pdo, $companyId, $selectedDateStr, $BLOCKED_SLOTS_FALLBACK);

$appointments = agenda_get_appointments_for_date($pdo, $companyId, $selectedDateStr);
$occupancy    = agenda_build_occupancy_map($appointments, $timeSlots, $SLOT_INTERVAL_MINUTES);

// Barber blocks por barbeiro
$barberBlocksByBarberId = [];
foreach ($activeBarbers as $b) {
    $bid = (int)$b['id'];
    $barberBlocksByBarberId[$bid] = get_barber_blocks($pdo, $companyId, $bid, $selectedDateStr);
}

$selfUrl = BASE_URL . '/calendar_barbearia.php';

include __DIR__ . '/views/partials/header.php';
?>

<main class="flex-1 bg-slate-100 min-h-screen">
    <div class="max-w-7xl mx-auto px-6 py-6 space-y-6">

        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Agenda da barbearia</h1>
                <p class="text-sm text-slate-500 mt-1">
                    Visualizacao por colunas (um barbeiro por coluna). Clique em um horario livre para agendar ou bloquear.
                </p>
            </div>
            <?php if ($company): ?>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-slate-400">Empresa</p>
                    <p class="font-semibold text-slate-800"><?= sanitize($company['nome_fantasia']) ?></p>
                </div>
            <?php endif; ?>
        </header>

        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <form action="<?= sanitize($selfUrl) ?>" method="get" class="flex flex-col sm:flex-row sm:items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Data</label>
                    <input type="date" name="data" value="<?= sanitize($selectedDateStr) ?>"
                           class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 min-w-[180px]">
                </div>
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 text-white text-sm font-semibold px-4 py-2 hover:bg-indigo-500">
                    Atualizar
                </button>
            </form>
        </section>

        <?php if (!empty($uiErrors)): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm space-y-1">
                <?php foreach ($uiErrors as $msg): ?>
                    <p><?= sanitize($msg) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($uiSuccess): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-sm">
                <?= sanitize($uiSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- LEGENDA -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex flex-wrap items-center gap-4 text-[12px] text-slate-600">
                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span> Livre</div>
                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-rose-500"></span> Ocupado (agendamento)</div>
                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span> Bloqueado (barbeiro)</div>
                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span> Bloqueio global (feriado/geral)</div>
            </div>
        </section>

        <!-- AGENDA EM COLUNAS -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="w-full overflow-auto">
                <div class="min-w-[900px]">

                    <!-- Header row -->
                    <div class="grid"
                         style="grid-template-columns: 110px repeat(<?= max(1, count($activeBarbers)) ?>, minmax(220px, 1fr));">
                        <div class="sticky left-0 z-20 bg-slate-50 border-b border-slate-200 px-4 py-3">
                            <p class="text-xs font-semibold text-slate-600 uppercase tracking-wide">Horarios</p>
                        </div>

                        <?php foreach ($activeBarbers as $b): ?>
                            <div class="bg-slate-50 border-b border-slate-200 px-4 py-3">
                                <p class="text-sm font-semibold text-slate-900"><?= sanitize($b['name']) ?></p>
                                <p class="text-[11px] text-slate-500">Coluna independente</p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Body -->
                    <?php foreach ($timeSlots as $slot): ?>
                        <div class="grid border-b border-slate-100"
                             style="grid-template-columns: 110px repeat(<?= max(1, count($activeBarbers)) ?>, minmax(220px, 1fr));">

                            <!-- Time column -->
                            <div class="sticky left-0 z-10 bg-white px-4 py-3 border-r border-slate-200">
                                <p class="text-sm font-semibold text-slate-800"><?= sanitize($slot) ?></p>
                            </div>

                            <?php foreach ($activeBarbers as $b): ?>
                                <?php
                                $bid = (int)$b['id'];

                                $isGlobalBlocked = in_array($slot, $globalBlockedSlots, true);
                                $isBarberBlocked = in_array($slot, $barberBlocksByBarberId[$bid] ?? [], true);

                                $entry = $occupancy[$bid][$slot] ?? null;

                                $cellState = 'free';
                                if ($isGlobalBlocked) $cellState = 'global_block';
                                if ($isBarberBlocked) $cellState = 'barber_block';
                                if ($entry) $cellState = 'busy';

                                $cellBg = 'bg-emerald-50';
                                $cellBorder = 'border-emerald-200';
                                if ($cellState === 'busy') { $cellBg = 'bg-rose-50'; $cellBorder = 'border-rose-200'; }
                                if ($cellState === 'barber_block') { $cellBg = 'bg-amber-50'; $cellBorder = 'border-amber-200'; }
                                if ($cellState === 'global_block') { $cellBg = 'bg-slate-100'; $cellBorder = 'border-slate-200'; }

                                $clickable = ($cellState === 'free' || $cellState === 'barber_block');
                                ?>

                                <div class="px-3 py-2">
                                    <div
                                        class="rounded-xl border <?= $cellBorder ?> <?= $cellBg ?> px-3 py-2.5 text-sm flex flex-col gap-1.5 <?= $clickable ? 'cursor-pointer hover:shadow-sm' : '' ?>"
                                        <?= $clickable ? 'data-clickable="1"' : 'data-clickable="0"' ?>
                                        data-barber-id="<?= (int)$bid ?>"
                                        data-barber-name="<?= sanitize($b['name']) ?>"
                                        data-time="<?= sanitize($slot) ?>"
                                    >
                                        <?php if ($cellState === 'global_block'): ?>
                                            <p class="text-xs font-semibold text-slate-600">Bloqueio global</p>
                                            <p class="text-[11px] text-slate-500">Gerenciado pelo bloqueio geral do dia.</p>

                                        <?php elseif ($cellState === 'barber_block'): ?>
                                            <p class="text-xs font-semibold text-amber-800">Bloqueado (barbeiro)</p>
                                            <p class="text-[11px] text-amber-700">Clique para gerenciar (desbloquear / editar).</p>

                                        <?php elseif ($cellState === 'busy'): ?>
                                            <?php
                                            $appt = $entry['appointment'];
                                            $isStart = (bool)$entry['is_start'];
                                            $servicesLabel = agenda_services_from_json($appt['services_json'] ?? '', $servicesCatalog);
                                            $servicesText = $servicesLabel ? implode(', ', $servicesLabel) : 'Servicos nao informados';
                                            $endsAt = $appt['ends_at_time']
                                                ? substr((string)$appt['ends_at_time'], 0, 5)
                                                : substr(agenda_calculate_end_time(substr((string)$appt['time'], 0, 5), (int)($appt['total_duration_minutes'] ?? $SLOT_INTERVAL_MINUTES)), 0, 5);
                                            ?>
                                            <?php if ($isStart): ?>
                                                <p class="text-xs font-semibold text-rose-700">Ocupado</p>
                                                <p class="font-semibold text-slate-900"><?= sanitize($appt['customer_name']) ?></p>
                                                <p class="text-[11px] text-slate-600">
                                                    <?= sanitize($appt['phone']) ?>
                                                    <?php if (!empty($appt['instagram'])): ?>
                                                        · @<?= sanitize(ltrim($appt['instagram'], '@')) ?>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="text-[11px] text-slate-600">
                                                    <?= sanitize($servicesText) ?>
                                                    <?php if (!empty($appt['total_price'])): ?>
                                                        · <?= format_currency($appt['total_price']) ?>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="text-[11px] text-slate-600">Ate <?= sanitize($endsAt) ?></p>
                                                <div class="text-[11px] mt-1">
                                                    <a
                                                        href="<?= BASE_URL ?>/cancel_appointment.php?id=<?= (int)$appt['id'] ?>&data=<?= urlencode($selectedDateStr) ?>"
                                                        class="inline-flex items-center gap-1 text-rose-700 hover:text-rose-600"
                                                        onclick="return confirm('Cancelar este agendamento?');"
                                                    >
                                                        🗑 Cancelar
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-[11px] text-slate-600">
                                                    Em atendimento: <b><?= sanitize($appt['customer_name']) ?></b> (ate <?= sanitize($endsAt) ?>)
                                                </p>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <p class="text-xs font-semibold text-emerald-700">Livre</p>
                                            <p class="text-[11px] text-emerald-700">Clique para agendar ou bloquear.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </section>

        <p class="text-[11px] text-slate-500">
            Dica: bloqueio global (calendar_blocks) trava todos os barbeiros. Bloqueio por barbeiro (barber_blocks) trava apenas a coluna escolhida.
        </p>

    </div>
</main>

<!-- MODAL -->
<div id="cellModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-close="1"></div>

    <div class="relative mx-auto mt-10 w-[95%] max-w-2xl bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">
        <div class="flex items-start justify-between px-5 py-4 border-b border-slate-200">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Gerenciar horario</p>
                <h3 class="text-lg font-semibold text-slate-900" id="modalTitle">Horario</h3>
                <p class="text-sm text-slate-600" id="modalSubtitle"></p>
            </div>
            <button class="text-slate-500 hover:text-slate-700" data-close="1">✕</button>
        </div>

        <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <button class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left hover:border-indigo-300"
                        data-tab="book">
                    <p class="text-sm font-semibold text-slate-900">Criar agendamento</p>
                    <p class="text-[11px] text-slate-500">Selecione serviços e confirme.</p>
                </button>

                <button class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left hover:border-indigo-300"
                        data-tab="block">
                    <p class="text-sm font-semibold text-slate-900">Bloquear / Desbloquear</p>
                    <p class="text-[11px] text-slate-500">Só para este barbeiro (30min+).</p>
                </button>
            </div>

            <!-- BOOK -->
            <form id="formBook" method="post" action="<?= sanitize($selfUrl) ?>" class="space-y-3 hidden">
                <input type="hidden" name="action" value="book_admin">
                <input type="hidden" name="data" value="<?= sanitize($selectedDateStr) ?>">
                <input type="hidden" name="barber_id" id="book_barber_id" value="">
                <input type="hidden" name="time" id="book_time" value="">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Cliente</label>
                        <input type="text" name="customer_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="Nome do cliente" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">WhatsApp</label>
                        <input type="text" name="phone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="(00) 00000-0000" required>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Servicos</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php foreach ($servicesCatalog as $key => $svc): ?>
                            <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                                <input type="checkbox" name="services[]" value="<?= sanitize($key) ?>"
                                       class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <div class="flex flex-col">
                                    <span class="font-medium text-slate-900"><?= sanitize($svc['label']) ?></span>
                                    <span class="text-[11px] text-slate-500"><?= format_currency($svc['price']) ?> · <?= (int)$svc['duration'] ?> min</span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Minutos extras (opcional)</label>
                        <input type="number" name="extra_minutes" min="0" step="10"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="Ex.: 30">
                        <p class="text-[11px] text-slate-500 mt-1">Use para complementar serviços não cadastrados.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Instagram (opcional)</label>
                        <input type="text" name="instagram"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="@usuario">
                    </div>
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-emerald-600 text-white font-semibold px-4 py-3 hover:bg-emerald-500">
                    Salvar agendamento
                </button>
            </form>

            <!-- BLOCK -->
            <form id="formBlock" method="post" action="<?= sanitize($selfUrl) ?>" class="space-y-3 hidden">
                <input type="hidden" name="data" value="<?= sanitize($selectedDateStr) ?>">
                <input type="hidden" name="barber_id" id="block_barber_id" value="">
                <input type="hidden" name="time" id="block_time" value="">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Duracao do bloqueio</label>
                        <select name="block_minutes" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="30">30 min</option>
                            <option value="60">60 min</option>
                            <option value="90">90 min</option>
                            <option value="120">120 min</option>
                            <option value="150">150 min</option>
                            <option value="180">180 min</option>
                        </select>
                        <p class="text-[11px] text-slate-500 mt-1">Bloqueia em blocos de 30 min a partir do horario.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Motivo (opcional)</label>
                        <input type="text" name="reason"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                               placeholder="Pigmentacao, atraso, pausa...">
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-2">
                    <button type="submit" name="action" value="block_barber"
                            class="flex-1 rounded-xl bg-indigo-600 text-white font-semibold px-4 py-3 hover:bg-indigo-500">
                        Bloquear este barbeiro
                    </button>
                    <button type="submit" name="action" value="unblock_barber"
                            class="flex-1 rounded-xl border border-slate-300 text-slate-700 font-semibold px-4 py-3 hover:border-slate-400 bg-white">
                        Desbloquear este barbeiro
                    </button>
                </div>

                <p class="text-[11px] text-slate-500">
                    Observacao: desbloquear remove o bloqueio deste barbeiro a partir do horario, pela duracao escolhida.
                </p>
            </form>

        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('cellModal');
    const title = document.getElementById('modalTitle');
    const sub   = document.getElementById('modalSubtitle');

    const formBook  = document.getElementById('formBook');
    const formBlock = document.getElementById('formBlock');

    const bookBarberId = document.getElementById('book_barber_id');
    const bookTime     = document.getElementById('book_time');

    const blockBarberId = document.getElementById('block_barber_id');
    const blockTime     = document.getElementById('block_time');

    function openModal({barberId, barberName, time}) {
        title.textContent = time + ' — ' + barberName;
        sub.textContent   = 'Escolha o que deseja fazer neste horario.';

        bookBarberId.value = barberId;
        bookTime.value     = time;

        blockBarberId.value = barberId;
        blockTime.value     = time;

        // default tab
        showTab('book');

        modal.classList.remove('hidden');
    }

    function closeModal() {
        modal.classList.add('hidden');
    }

    function showTab(tab) {
        formBook.classList.add('hidden');
        formBlock.classList.add('hidden');

        if (tab === 'book') formBook.classList.remove('hidden');
        if (tab === 'block') formBlock.classList.remove('hidden');
    }

    document.querySelectorAll('[data-close="1"]').forEach(el => {
        el.addEventListener('click', closeModal);
    });

    // tab buttons
    document.querySelectorAll('[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => showTab(btn.getAttribute('data-tab')));
    });

    // clickable cells
    document.querySelectorAll('[data-clickable="1"]').forEach(cell => {
        cell.addEventListener('click', () => {
            openModal({
                barberId: cell.getAttribute('data-barber-id'),
                barberName: cell.getAttribute('data-barber-name'),
                time: cell.getAttribute('data-time'),
            });
        });
    });
})();
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
