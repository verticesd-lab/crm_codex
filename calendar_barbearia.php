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
    echo 'Empresa nao encontrada na sessao.';
    exit;
}

// Carrega empresa para mostrar nome no topo
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

/**
 * ============================
 * CONFIG DA AGENDA BARBEARIA
 * ============================
 */

// Duracao de cada atendimento em minutos
$SLOT_INTERVAL_MINUTES = 60;

// Horario de funcionamento
$OPEN_TIME  = '09:00';
$CLOSE_TIME = '20:00';

// ✅ Agora o padrão é: NADA bloqueado.
// Bloqueios só vêm do banco (calendar_blocks).
$BLOCKED_SLOTS_FALLBACK = [];

/**
 * ============================
 * DATA SELECIONADA
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
 * GERA SLOTS DE HORARIO
 * ============================
 */
$timeSlots = agenda_generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);

/**
 * ============================
 * BARBEIROS
 * ============================
 */
agenda_seed_default_barbers($pdo, (int)$companyId);
$barbers = agenda_get_barbers($pdo, (int)$companyId, false);

$activeBarbers = array_values(array_filter($barbers, function ($barber) {
    return (int)($barber['is_active'] ?? 0) === 1;
}));
$activeBarberCount = count($activeBarbers);

/**
 * ============================
 * BLOQUEIOS MANUAIS (POST)
 * ============================
 */
$blockErrors  = [];
$blockSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['block', 'unblock'], true)) {
        $datePost = trim($_POST['data'] ?? $selectedDateStr);
        $dateObj  = DateTime::createFromFormat('Y-m-d', $datePost);

        if (!$dateObj) {
            $blockErrors[] = 'Data invalida para bloqueio.';
        } else {
            $selectedDate    = $dateObj;
            $selectedDateStr = $dateObj->format('Y-m-d');
        }

        $slots = $_POST['slots'] ?? [];
        $slots = is_array($slots) ? $slots : [];

        // valida contra os slots permitidos da agenda
        $slots = array_values(array_unique(array_filter($slots, function ($slot) use ($timeSlots) {
            return in_array($slot, $timeSlots, true);
        })));

        if (empty($slots)) {
            $blockErrors[] = 'Selecione ao menos um horario.';
        }

        if (empty($blockErrors)) {
            try {
                if ($action === 'block') {
                    $reason = trim($_POST['reason'] ?? '');
                    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                    $now    = now_utc_datetime();

                    $insert = $pdo->prepare('
                        INSERT INTO calendar_blocks
                            (company_id, date, time, reason, created_by_user_id, created_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            reason = VALUES(reason),
                            created_by_user_id = VALUES(created_by_user_id)
                    ');

                    foreach ($slots as $slot) {
                        $insert->execute([
                            $companyId,
                            $selectedDateStr,
                            $slot . ':00',
                            $reason !== '' ? $reason : null,
                            $userId,
                            $now,
                        ]);
                    }

                    $blockSuccess = 'Horarios bloqueados com sucesso.';
                } else {
                    $delete = $pdo->prepare('
                        DELETE FROM calendar_blocks
                        WHERE company_id = ? AND date = ? AND time = ?
                    ');

                    foreach ($slots as $slot) {
                        $delete->execute([$companyId, $selectedDateStr, $slot . ':00']);
                    }

                    $blockSuccess = 'Horarios desbloqueados com sucesso.';
                }
            } catch (Throwable $e) {
                $blockErrors[] = 'Falha ao atualizar os bloqueios. Verifique se a migracao foi aplicada.';
            }
        }
    }
}

/**
 * ============================
 * BLOQUEIOS E AGENDAMENTOS
 * ============================
 */
$blockedSlots     = agenda_get_calendar_blocks($pdo, (int)$companyId, $selectedDateStr, $BLOCKED_SLOTS_FALLBACK);
$servicesCatalog  = agenda_get_services_catalog();
$appointments     = agenda_get_appointments_for_date($pdo, (int)$companyId, $selectedDateStr);
$occupancy        = agenda_build_occupancy_map($appointments, $timeSlots, $SLOT_INTERVAL_MINUTES);

$selfUrl = BASE_URL . '/calendar_barbearia.php';

// Importa so o header
include __DIR__ . '/views/partials/header.php';
?>

<main class="flex-1 bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto px-6 py-6 space-y-6">
        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Agenda da barbearia</h1>
                <p class="text-sm text-slate-500 mt-1">Visualize os horarios agendados e bloqueios da agenda online.</p>
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

        <?php if (!empty($blockErrors)): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm space-y-1">
                <?php foreach ($blockErrors as $msg): ?>
                    <p><?= sanitize($msg) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($blockSuccess): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-sm">
                <?= sanitize($blockSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Bloqueios manuais -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Bloquear horarios</h2>
                <p class="text-xs text-slate-500 mt-1">
                    Selecione horarios para bloquear ou desbloquear na data escolhida.
                </p>
            </div>

            <form method="post" action="<?= sanitize($selfUrl) ?>" class="space-y-4">
                <input type="hidden" name="data" value="<?= sanitize($selectedDateStr) ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Horarios</label>

                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                        <?php foreach ($timeSlots as $slot): ?>
                            <?php $isBlocked = in_array($slot, $blockedSlots, true); ?>
                            <label class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs cursor-pointer
                                <?= $isBlocked ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-300' ?>">
                                <input
                                    type="checkbox"
                                    name="slots[]"
                                    value="<?= sanitize($slot) ?>"
                                    class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    <?= $isBlocked ? 'checked' : '' ?>
                                >
                                <span class="font-semibold"><?= sanitize($slot) ?></span>
                                <?php if ($isBlocked): ?>
                                    <span class="text-[10px] uppercase tracking-wide">Bloqueado</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="text-[11px] text-slate-500 mt-2">
                        Dica: para <b>desbloquear</b>, deixe marcados os horários que estão como “Bloqueado” e clique em “Desbloquear selecionados”.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Motivo (opcional)</label>
                    <input
                        type="text"
                        name="reason"
                        placeholder="Almoco, feriado, manutencao..."
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <div class="flex flex-col sm:flex-row gap-2">
                    <button
                        type="submit"
                        name="action"
                        value="block"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 text-white text-sm font-semibold px-4 py-2 hover:bg-indigo-500">
                        Bloquear selecionados
                    </button>
                    <button
                        type="submit"
                        name="action"
                        value="unblock"
                        class="inline-flex items-center justify-center rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold px-4 py-2 hover:border-slate-400">
                        Desbloquear selecionados
                    </button>
                </div>
            </form>
        </section>

        <!-- Grade de horarios -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">
                        Horarios do dia <?= $selectedDate->format('d/m/Y') ?>
                    </h2>
                    <p class="text-xs text-slate-500 mt-1">
                        Cada horario comporta ate <span class="font-semibold"><?= $activeBarberCount ?></span> barbeiro(s) ativo(s).
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                    <div class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Livre
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-amber-400"></span> Parcial
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-rose-500"></span> Lotado
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-amber-500"></span> Bloqueado
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <?php if (empty($timeSlots)): ?>
                    <p class="text-sm text-slate-500 col-span-full">Nenhum horario configurado para esta agenda.</p>
                <?php else: ?>
                    <?php foreach ($timeSlots as $slot): ?>
                        <?php
                        $slotIsBlocked = in_array($slot, $blockedSlots, true);
                        $occupiedCount = 0;

                        foreach ($activeBarbers as $barber) {
                            $barberId = (int)$barber['id'];
                            if (isset($occupancy[$barberId][$slot])) {
                                $occupiedCount++;
                            }
                        }

                        if ($slotIsBlocked) {
                            $border = 'border-amber-200';
                            $bg     = 'bg-amber-50';
                            $dot    = 'bg-amber-500';
                            $label  = 'Bloqueado';
                        } elseif ($occupiedCount === 0) {
                            $border = 'border-emerald-300/50';
                            $bg     = 'bg-emerald-50';
                            $dot    = 'bg-emerald-400';
                            $label  = 'Livre';
                        } elseif ($activeBarberCount > 0 && $occupiedCount >= $activeBarberCount) {
                            $border = 'border-rose-300/60';
                            $bg     = 'bg-rose-50';
                            $dot    = 'bg-rose-500';
                            $label  = 'Lotado';
                        } else {
                            $border = 'border-amber-300/70';
                            $bg     = 'bg-amber-50';
                            $dot    = 'bg-amber-400';
                            $label  = $occupiedCount . ' de ' . max(1, $activeBarberCount) . ' ocupados';
                        }
                        ?>

                        <div class="rounded-xl border <?= $border ?> <?= $bg ?> px-3 py-2.5 flex flex-col gap-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-slate-900"><?= sanitize($slot) ?></span>
                                <span class="inline-flex items-center gap-1 text-[11px] text-slate-600">
                                    <span class="h-1.5 w-1.5 rounded-full <?= $dot ?>"></span>
                                    <?= sanitize($label) ?>
                                </span>
                            </div>

                            <?php if (empty($barbers)): ?>
                                <p class="text-[11px] text-slate-500">Nenhum barbeiro cadastrado.</p>
                            <?php else: ?>
                                <div class="space-y-2">
                                    <?php foreach ($barbers as $barber): ?>
                                        <?php
                                        $barberId = (int)$barber['id'];
                                        $entry    = $occupancy[$barberId][$slot] ?? null;
                                        ?>
                                        <div class="rounded-lg border border-slate-200 bg-white px-2 py-2 text-[11px] text-slate-700">
                                            <p class="text-xs font-semibold text-slate-800">
                                                <?= sanitize($barber['name']) ?>
                                                <?php if ((int)$barber['is_active'] !== 1): ?>
                                                    <span class="text-[10px] text-slate-400">(inativo)</span>
                                                <?php endif; ?>
                                            </p>

                                            <?php if ($entry): ?>
                                                <?php
                                                $appt          = $entry['appointment'];
                                                $isStart       = $entry['is_start'];
                                                $servicesLabel = agenda_services_from_json($appt['services_json'] ?? '', $servicesCatalog);
                                                $servicesText  = $servicesLabel ? implode(', ', $servicesLabel) : 'Servicos nao informados';

                                                $endsAt = $appt['ends_at_time']
                                                    ? substr((string)$appt['ends_at_time'], 0, 5)
                                                    : substr(agenda_calculate_end_time($entry['start_time'], $entry['duration_minutes']), 0, 5);
                                                ?>

                                                <?php if ($isStart): ?>
                                                    <p class="font-medium text-slate-900"><?= sanitize($appt['customer_name']) ?></p>
                                                    <p class="text-slate-500">
                                                        <?= sanitize($appt['phone']) ?>
                                                        <?php if (!empty($appt['instagram'])): ?>
                                                            · @<?= sanitize(ltrim($appt['instagram'], '@')) ?>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p class="text-slate-500">
                                                        <?= sanitize($servicesText) ?>
                                                        <?php if (!empty($appt['total_price'])): ?>
                                                            · <?= format_currency($appt['total_price']) ?>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p class="text-slate-500">Ate <?= sanitize($endsAt) ?></p>

                                                    <div class="text-[11px] mt-1">
                                                        <a
                                                            href="<?= BASE_URL ?>/cancel_appointment.php?id=<?= (int)$appt['id'] ?>&data=<?= urlencode($selectedDateStr) ?>"
                                                            class="inline-flex items-center gap-1 text-rose-600 hover:text-rose-500"
                                                            onclick="return confirm('Cancelar este agendamento?');"
                                                        >
                                                            🗑 Cancelar
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <p class="text-slate-500">
                                                        Em atendimento: <?= sanitize($appt['customer_name']) ?> (ate <?= sanitize($endsAt) ?>)
                                                    </p>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p class="text-slate-500">
                                                    <?= $slotIsBlocked ? 'Horario bloqueado.' : 'Livre.' ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p class="text-[11px] text-slate-500 mt-2">
                Os horarios e clientes acima vem automaticamente dos agendamentos feitos na agenda publica da barbearia
                (tabela <code class="font-mono">appointments</code>).
            </p>
        </section>
    </div>
</main>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
