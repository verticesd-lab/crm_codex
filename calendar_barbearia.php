<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';

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

/**
 * ✅ SERVIÇOS (BANCO) com fallback/seed (usa agenda_helpers novo)
 * - $servicesCatalog: para converter services_json -> labels
 * - $services: para listar no modal com preço/minutos
 */
$servicesCatalog = agenda_get_services_catalog($pdo, $companyId, true); // <- importante: passa $pdo e companyId

$services = [];
foreach ($servicesCatalog as $key => $v) {
    $services[] = [
        'service_key' => (string)$key,
        'label' => (string)($v['label'] ?? $key),
        'price' => (float)($v['price'] ?? 0),
        'duration_minutes' => (int)($v['duration'] ?? 30),
    ];
}

$appointments = agenda_get_appointments_for_date($pdo, $companyId, $selectedDateStr);

/**
 * ✅ BLOQUEIOS (agora suporta geral + por barbeiro)
 * Estrutura:
 * - $blockedGeneral['HH:ii'] = true
 * - $blockedByBarber[barberId]['HH:ii'] = true
 */
$blockedGeneral = [];
$blockedByBarber = [];
try {
    $st = $pdo->prepare('
        SELECT barber_id, time
        FROM calendar_blocks
        WHERE company_id = ? AND date = ?
    ');
    $st->execute([$companyId, $selectedDateStr]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $slot = substr((string)($r['time'] ?? ''), 0, 5);
        if ($slot === '') continue;

        $bid = $r['barber_id'];
        if ($bid === null) {
            $blockedGeneral[$slot] = true;
        } else {
            $bid = (int)$bid;
            if ($bid > 0) {
                if (!isset($blockedByBarber[$bid])) $blockedByBarber[$bid] = [];
                $blockedByBarber[$bid][$slot] = true;
            }
        }
    }
} catch (Throwable $e) {
    // Se tabela não existir ainda, segue sem bloqueios
}

/**
 * Ocupação (agendamentos)
 */
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
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Agenda Interna</h1>
                <p class="text-sm text-slate-500">Clique em um horário livre para agendar. Arraste para bloquear em lote.</p>
            </div>

            <div class="flex items-center gap-3">
                <a href="<?= BASE_URL ?>/services_admin.php"
                   class="bg-slate-900 text-white px-4 py-2 rounded text-sm font-semibold hover:bg-slate-800">
                    Serviços (Admin)
                </a>

                <form method="get" class="flex items-center gap-2">
                    <input type="date" name="data" value="<?= sanitize($selectedDateStr) ?>"
                           class="border rounded px-3 py-2 text-sm">
                    <button class="bg-indigo-600 text-white px-4 py-2 rounded text-sm font-semibold hover:bg-indigo-500">
                        Atualizar
                    </button>
                </form>
            </div>
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
                        <?= sanitize($slot) ?>
                    </div>

                    <?php foreach ($barbers as $barber): ?>
                        <?php
                        $barberId = (int)$barber['id'];
                        $entry = $occupancy[$barberId][$slot] ?? null;

                        $isBlockedGeneral = isset($blockedGeneral[$slot]);
                        $isBlockedBarber = isset($blockedByBarber[$barberId]) && isset($blockedByBarber[$barberId][$slot]);
                        $isBlocked = $isBlockedGeneral || $isBlockedBarber;

                        $bg = 'bg-emerald-50';
                        if ($isBlocked) $bg = 'bg-amber-100';
                        if ($entry) $bg = 'bg-rose-100';

                        // Para seleção por arraste (somente slots livres e não bloqueados)
                        $selectable = (!$entry && !$isBlocked);
                        ?>

                        <div
                            class="border-t p-2 <?= $bg ?> min-h-[92px] text-xs relative js-slot <?= $selectable ? 'cursor-crosshair' : '' ?>"
                            data-date="<?= sanitize($selectedDateStr) ?>"
                            data-time="<?= sanitize($slot) ?>"
                            data-barber-id="<?= (int)$barberId ?>"
                            data-barber-name="<?= sanitize($barber['name']) ?>"
                            data-selectable="<?= $selectable ? '1' : '0' ?>"
                        >

                            <?php if ($isBlocked): ?>
                                <p class="text-amber-700 font-semibold">Bloqueado</p>
                                <?php if ($isBlockedGeneral): ?>
                                    <p class="text-[11px] text-amber-700/80">Geral</p>
                                <?php else: ?>
                                    <p class="text-[11px] text-amber-700/80">Barbeiro</p>
                                <?php endif; ?>

                            <?php elseif ($entry): ?>
                                <?php
                                $appt = $entry['appointment'];

                                $servicesLabels = agenda_services_from_json($appt['services_json'] ?? null, $servicesCatalog);

                                $endsAt = !empty($appt['ends_at_time'])
                                    ? substr((string)$appt['ends_at_time'], 0, 5)
                                    : substr(
                                        agenda_calculate_end_time(
                                            substr((string)($appt['time'] ?? ''), 0, 5),
                                            (int)($appt['total_duration_minutes'] ?? 0)
                                        ),
                                        0,
                                        5
                                    );

                                $phone = trim((string)($appt['phone'] ?? ''));
                                $instagram = trim((string)($appt['instagram'] ?? ''));
                                ?>

                                <?php if (!empty($entry['is_start'])): ?>
                                    <p class="font-semibold text-slate-900">
                                        <?= sanitize((string)($appt['customer_name'] ?? 'Cliente')) ?>
                                    </p>

                                    <?php if (!empty($servicesLabels)): ?>
                                        <p class="text-slate-600">
                                            <?= sanitize(implode(', ', $servicesLabels)) ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="text-slate-600">Serviços: (não informado)</p>
                                    <?php endif; ?>

                                    <p class="text-slate-600">
                                        <?= format_currency((float)($appt['total_price'] ?? 0)) ?> · até <?= sanitize($endsAt) ?>
                                    </p>

                                    <?php if ($phone !== ''): ?>
                                        <p class="text-slate-700 mt-1">
                                            <span class="text-slate-500">Tel:</span> <?= sanitize($phone) ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($instagram !== ''): ?>
                                        <p class="text-slate-700">
                                            <span class="text-slate-500">IG:</span> <?= sanitize($instagram) ?>
                                        </p>
                                    <?php endif; ?>

                                    <a href="<?= BASE_URL ?>/cancel_appointment.php?id=<?= (int)$appt['id'] ?>&data=<?= sanitize($selectedDateStr) ?>"
                                       class="text-rose-600 text-[11px] inline-block mt-1"
                                       onclick="return confirm('Cancelar agendamento?')">
                                        Cancelar
                                    </a>
                                <?php else: ?>
                                    <p class="text-slate-600">Em atendimento</p>
                                <?php endif; ?>

                            <?php else: ?>
                                <!-- ✅ LIVRE CLICÁVEL -->
                                <button
                                    type="button"
                                    class="text-emerald-700 underline js-open-appt"
                                    data-date="<?= sanitize($selectedDateStr) ?>"
                                    data-time="<?= sanitize($slot) ?>"
                                    data-barber-id="<?= (int)$barberId ?>"
                                    data-barber-name="<?= sanitize($barber['name']) ?>"
                                >
                                    Livre (agendar)
                                </button>
                                <p class="text-[11px] text-slate-500 mt-1">ou arraste para bloquear</p>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            </div>
        </div>

        <p class="text-xs text-slate-500">
            Agenda interna baseada nos agendamentos reais. Bloqueios podem ser gerais ou por barbeiro.
        </p>
    </div>
</main>

<!-- ===========================
     MODAL: Agendamento interno
     =========================== -->
<div id="apptModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 p-4 z-50">
    <div class="w-full max-w-xl bg-white rounded-xl shadow border p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-bold">Agendar (interno)</h2>
            <button type="button" id="apptClose" class="text-slate-500 text-xl leading-none">&times;</button>
        </div>

        <div class="text-sm text-slate-600 mb-3">
            <span id="apptInfo"></span>
        </div>

        <!-- ✅ Totais em tempo real -->
        <div class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm flex items-center justify-between mb-3">
            <div class="text-slate-700">
                <span class="font-semibold">Total:</span> <span id="apptTotalPrice">R$ 0,00</span>
            </div>
            <div class="text-slate-700">
                <span class="font-semibold">Minutos:</span> <span id="apptTotalMinutes">0</span>
            </div>
        </div>

        <form method="post" action="<?= BASE_URL ?>/create_appointment_internal.php" class="space-y-3">
            <input type="hidden" name="date" id="apptDate">
            <input type="hidden" name="time" id="apptTime">
            <input type="hidden" name="barber_id" id="apptBarberId">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-slate-700">Nome</label>
                    <input name="customer_name" required class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-700">Telefone</label>
                    <input name="phone" required class="w-full border rounded px-3 py-2 text-sm" placeholder="(65) 99999-9999" />
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-700">Instagram (opcional)</label>
                <input name="instagram" class="w-full border rounded px-3 py-2 text-sm" placeholder="@cliente" />
            </div>

            <div>
                <div class="text-xs font-semibold text-slate-700 mb-2">Serviços</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <?php foreach ($services as $s): ?>
                        <label class="flex items-center gap-2 border rounded px-3 py-2 text-sm">
                            <input
                                type="checkbox"
                                class="js-service"
                                name="services[]"
                                value="<?= sanitize($s['service_key']) ?>"
                                data-price="<?= (float)$s['price'] ?>"
                                data-minutes="<?= (int)$s['duration_minutes'] ?>"
                            >
                            <span class="font-medium"><?= sanitize($s['label']) ?></span>
                            <span class="ml-auto text-slate-500">
                                R$ <?= number_format((float)$s['price'], 2, ',', '.') ?> · <?= (int)$s['duration_minutes'] ?>min
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-[11px] text-slate-500 mt-2">Os totais acima atualizam automaticamente conforme você seleciona.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="apptCancel" class="px-4 py-2 rounded border">Cancelar</button>
                <button class="px-4 py-2 rounded bg-indigo-600 text-white font-semibold hover:bg-indigo-500">
                    Salvar agendamento
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================
     BARRA: Bloqueio por arraste
     =========================== -->
<div id="blockBar" class="fixed bottom-4 left-4 right-4 hidden z-50">
    <div class="max-w-4xl mx-auto bg-white border shadow rounded-xl p-3 flex items-center gap-3">
        <div class="text-sm text-slate-700">
            <span class="font-semibold">Selecionados:</span> <span id="blockCount">0</span>
        </div>

        <label class="text-sm text-slate-700 inline-flex items-center gap-2">
            <input type="checkbox" id="blockGeneral" class="h-4 w-4">
            Bloqueio geral (todos barbeiros)
        </label>

        <div class="ml-auto flex items-center gap-2">
            <button type="button" id="blockClear" class="px-3 py-2 rounded border text-sm">
                Limpar
            </button>
            <button type="button" id="blockSubmit" class="px-3 py-2 rounded bg-amber-600 text-white font-semibold text-sm hover:bg-amber-500">
                Bloquear selecionados
            </button>
        </div>
    </div>
</div>

<script>
    // ===== Modal agendamento =====
    const modal = document.getElementById('apptModal');
    const apptInfo = document.getElementById('apptInfo');
    const apptDate = document.getElementById('apptDate');
    const apptTime = document.getElementById('apptTime');
    const apptBarberId = document.getElementById('apptBarberId');

    const totalPriceEl = document.getElementById('apptTotalPrice');
    const totalMinEl = document.getElementById('apptTotalMinutes');

    function brMoney(v){
        const n = Number(v || 0);
        return 'R$ ' + n.toFixed(2).replace('.', ',');
    }

    function recalcTotals(){
        let price = 0;
        let minutes = 0;
        document.querySelectorAll('.js-service:checked').forEach(chk => {
            price += Number(chk.dataset.price || 0);
            minutes += Number(chk.dataset.minutes || 0);
        });
        totalPriceEl.textContent = brMoney(price);
        totalMinEl.textContent = String(minutes);
    }

    function resetModal(){
        // limpa serviços + totais
        document.querySelectorAll('.js-service').forEach(chk => chk.checked = false);
        recalcTotals();
    }

    function openModal(btn){
        apptDate.value = btn.dataset.date;
        apptTime.value = btn.dataset.time;
        apptBarberId.value = btn.dataset.barberId;
        apptInfo.textContent = `${btn.dataset.date} às ${btn.dataset.time} — ${btn.dataset.barberName}`;

        resetModal();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeModal(){
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.querySelectorAll('.js-open-appt').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn));
    });

    document.getElementById('apptClose').addEventListener('click', closeModal);
    document.getElementById('apptCancel').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    document.querySelectorAll('.js-service').forEach(chk => {
        chk.addEventListener('change', recalcTotals);
    });

    // ===== Bloqueio por arraste =====
    const blockBar = document.getElementById('blockBar');
    const blockCount = document.getElementById('blockCount');
    const blockGeneral = document.getElementById('blockGeneral');
    const blockClear = document.getElementById('blockClear');
    const blockSubmit = document.getElementById('blockSubmit');

    // selecionados: chave = `${barberId}__${time}`
    const selected = new Map();
    let isDragging = false;

    function updateBlockBar(){
        const c = selected.size;
        blockCount.textContent = String(c);
        if (c > 0) blockBar.classList.remove('hidden');
        else blockBar.classList.add('hidden');
    }

    function toggleCell(cell){
        if (cell.dataset.selectable !== '1') return;

        const barberId = cell.dataset.barberId;
        const time = cell.dataset.time;
        const key = `${barberId}__${time}`;

        if (selected.has(key)) {
            selected.delete(key);
            cell.classList.remove('ring-2', 'ring-amber-500');
        } else {
            selected.set(key, { barberId: Number(barberId), time });
            cell.classList.add('ring-2', 'ring-amber-500');
        }
        updateBlockBar();
    }

    function clearSelection(){
        document.querySelectorAll('.js-slot.ring-2').forEach(c => c.classList.remove('ring-2', 'ring-amber-500'));
        selected.clear();
        updateBlockBar();
    }

    document.querySelectorAll('.js-slot').forEach(cell => {
        cell.addEventListener('mousedown', (e) => {
            if (cell.dataset.selectable !== '1') return;
            isDragging = true;
            e.preventDefault();
            toggleCell(cell);
        });

        cell.addEventListener('mouseenter', () => {
            if (!isDragging) return;
            toggleCell(cell);
        });
    });

    document.addEventListener('mouseup', () => {
        isDragging = false;
    });

    blockClear.addEventListener('click', clearSelection);

    blockSubmit.addEventListener('click', async () => {
        if (selected.size === 0) return;

        const date = document.querySelector('.js-slot')?.dataset.date || '<?= sanitize($selectedDateStr) ?>';
        const general = blockGeneral.checked ? 1 : 0;

        // monta payload
        // - se general=1: manda times únicos (barber_id = null no backend)
        // - se general=0: manda pares barber_id + time
        let payload = { date, general, items: [] };

        if (general) {
            const uniq = new Set();
            selected.forEach(v => uniq.add(v.time));
            uniq.forEach(t => payload.items.push({ time: t }));
        } else {
            selected.forEach(v => payload.items.push({ barber_id: v.barberId, time: v.time }));
        }

        // ✅ endpoint para criar blocks em lote (arquivo abaixo)
        const url = '<?= rtrim(BASE_URL, "/") ?>/create_calendar_blocks_batch.php';

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await res.json().catch(() => null);

            if (!res.ok || !data || !data.ok) {
                alert('Falha ao bloquear. Verifique logs.');
                return;
            }

            // recarrega para mostrar bloqueios
            window.location.reload();
        } catch (err) {
            alert('Falha de rede ao bloquear.');
        }
    });
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
