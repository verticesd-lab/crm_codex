<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';
require_once __DIR__ . '/barber_services_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_login();

$pdo = get_pdo();
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
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDateStr)) {
    $selectedDateStr = $today->format('Y-m-d');
}

/**
 * ============================
 * DADOS BASE
 * ============================
 */
$timeSlots = agenda_generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);

agenda_seed_default_barbers($pdo, $companyId);
$barbers = agenda_get_barbers($pdo, $companyId, true);

/**
 * Serviços (preferir banco + fallback)
 */
$servicesCatalog = agenda_get_services_catalog($pdo, $companyId, true);

$services = [];
foreach ($servicesCatalog as $key => $v) {
    $services[] = [
        'service_key' => (string)$key,
        'label' => (string)($v['label'] ?? $key),
        'price' => (float)($v['price'] ?? 0),
        'duration_minutes' => (int)($v['duration'] ?? 30),
    ];
}

// Catálogo efetivo de cada barbeiro para o modal de agendamento interno.
$servicesByBarber = [];
foreach ($barbers as $barber) {
    $barberId = (int)($barber['id'] ?? 0);
    if ($barberId <= 0) continue;

    $servicesByBarber[$barberId] = [];
    foreach (get_services_for_barber($pdo, $companyId, $barberId) as $service) {
        $key = (string)($service['service_key'] ?? '');
        if ($key === '') continue;

        $servicesByBarber[$barberId][$key] = [
            'label' => (string)($service['nome'] ?? $key),
            'price' => (float)($service['preco'] ?? 0),
            'duration_minutes' => (int)($service['duracao_min'] ?? 30),
        ];
    }
}

$appointments = agenda_get_appointments_for_date($pdo, $companyId, $selectedDateStr);

/**
 * ✅ BLOQUEIOS (manual)
 * - Geral: barber_id = 0
 * - Por barbeiro: barber_id = X
 */
$blockedGeneral = [];        // ['HH:MM' => true]
$blockedByBarber = [];       // [barberId => ['HH:MM' => true]]

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

        $bid = (int)($r['barber_id'] ?? 0);
        if ($bid === 0) {
            $blockedGeneral[$slot] = true;
        } else {
            if (!isset($blockedByBarber[$bid])) $blockedByBarber[$bid] = [];
            $blockedByBarber[$bid][$slot] = true;
        }
    }
} catch (Throwable $e) {
    // segue sem bloqueios
}

$occupancy = agenda_build_occupancy_map(
    $appointments,
    $timeSlots,
    $SLOT_INTERVAL_MINUTES
);

include __DIR__ . '/views/partials/header.php';
?>

<main class="bg-slate-100 min-h-screen p-6">
  <div class="max-w-7xl mx-auto space-y-6">

    <!-- TOPO -->
    <div class="flex items-center justify-between gap-4 flex-wrap">
      <div class="space-y-1">
        <h1 class="text-2xl font-bold text-slate-900">Agenda Interna</h1>
        <p class="text-sm text-slate-500">
          Clique em um horário livre para agendar. Bloqueio é manual por slot.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <a href="<?= BASE_URL ?>/services_admin.php" class="text-sm font-semibold text-indigo-700 underline">
          Administrar serviços
        </a>

        <form method="get" class="flex items-center gap-2">
          <input type="date" name="data" value="<?= sanitize($selectedDateStr) ?>"
                 class="border rounded px-3 py-2 text-sm">
          <button class="bg-indigo-600 text-white px-4 py-2 rounded text-sm font-semibold">
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
              $isBlockedBarber  = isset($blockedByBarber[$barberId]) && isset($blockedByBarber[$barberId][$slot]);
              $isBlocked = $isBlockedGeneral || $isBlockedBarber;

              $bg = 'bg-emerald-50';
              if ($isBlocked) $bg = 'bg-amber-100';
              if ($entry) $bg = 'bg-rose-100';
            ?>

            <div class="border-t p-2 <?= $bg ?> min-h-[92px] text-xs">

              <?php if ($isBlocked): ?>
                <p class="text-amber-700 font-semibold">Bloqueado</p>
                <p class="text-amber-700/80 text-[11px]">
                  <?= $isBlockedGeneral ? 'Geral' : 'Barbeiro' ?>
                </p>

                <form method="post" action="<?= BASE_URL ?>/delete_calendar_block.php" class="m-0 mt-2">
                  <input type="hidden" name="date" value="<?= sanitize($selectedDateStr) ?>">
                  <input type="hidden" name="time" value="<?= sanitize($slot) ?>">
                  <input type="hidden" name="scope" value="<?= $isBlockedGeneral ? 'general' : 'barber' ?>">
                  <input type="hidden" name="barber_id" value="<?= $isBlockedGeneral ? 0 : (int)$barberId ?>">
                  <button class="text-[11px] text-amber-800 underline"
                          onclick="return confirm('Remover bloqueio de <?= sanitize($slot) ?>?')">
                    Remover bloqueio
                  </button>
                </form>

              <?php elseif ($entry): ?>
                <?php
                  $appt = $entry['appointment'];
                  $servicesLabels = agenda_services_from_json($appt['services_json'] ?? '', $servicesCatalog);

                  $endsAt = !empty($appt['ends_at_time'])
                    ? substr((string)$appt['ends_at_time'], 0, 5)
                    : substr(
                        agenda_calculate_end_time(
                          substr((string)($appt['time'] ?? ''), 0, 5),
                          (int)($appt['total_duration_minutes'] ?? 0)
                        ),
                        0, 5
                      );

                  $phone = trim((string)($appt['phone'] ?? ''));
                  $ig = trim((string)($appt['instagram'] ?? ''));
                ?>

                <?php if (!empty($entry['is_start'])): ?>
                  <p class="font-semibold text-slate-900">
                    <?= sanitize((string)($appt['customer_name'] ?? '')) ?>
                  </p>

                  <?php if (!empty($servicesLabels)): ?>
                    <p class="text-slate-600"><?= sanitize(implode(', ', $servicesLabels)) ?></p>
                  <?php else: ?>
                    <p class="text-slate-600">(Sem serviços)</p>
                  <?php endif; ?>

                  <p class="text-slate-600">
                    <?= format_currency((float)($appt['total_price'] ?? 0)) ?>
                    · <?= (int)($appt['total_duration_minutes'] ?? 0) ?>min
                    · até <?= sanitize($endsAt) ?>
                  </p>

                  <?php if ($phone !== ''): ?><p class="text-slate-600">📞 <?= sanitize($phone) ?></p><?php endif; ?>
                  <?php if ($ig !== ''): ?><p class="text-slate-600">📷 <?= sanitize($ig) ?></p><?php endif; ?>

                  <a href="<?= BASE_URL ?>/cancel_appointment.php?id=<?= (int)($appt['id'] ?? 0) ?>&data=<?= sanitize($selectedDateStr) ?>"
                     class="text-rose-600 text-[11px]"
                     onclick="return confirm('Cancelar agendamento?')">
                    Cancelar
                  </a>
                <?php else: ?>
                  <p class="text-slate-600">Em atendimento</p>
                <?php endif; ?>

              <?php else: ?>
                <!-- LIVRE -->
                <div class="flex flex-col gap-2">
                  <button
                    type="button"
                    class="text-emerald-700 underline js-open-appt text-left"
                    data-date="<?= sanitize($selectedDateStr) ?>"
                    data-time="<?= sanitize($slot) ?>"
                    data-barber-id="<?= (int)$barberId ?>"
                    data-barber-name="<?= sanitize($barber['name']) ?>"
                  >
                    Livre (agendar)
                  </button>

                  <!-- Bloqueio manual por barbeiro (botão padronizado tipo "Atualizar") -->
                  <form method="post" action="<?= BASE_URL ?>/create_calendar_block.php" class="m-0">
                    <input type="hidden" name="date" value="<?= sanitize($selectedDateStr) ?>">
                    <input type="hidden" name="time" value="<?= sanitize($slot) ?>">
                    <input type="hidden" name="barber_id" value="<?= (int)$barberId ?>">
                    <input type="hidden" name="scope" value="barber">
                    <button
                      class="bg-indigo-600 text-white px-4 py-2 rounded text-sm font-semibold w-full"
                      onclick="return confirm('Bloquear <?= sanitize($slot) ?> para <?= sanitize($barber['name']) ?>?')"
                    >
                      Bloquear horário
                    </button>
                  </form>

                </div>
              <?php endif; ?>

            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>

      </div>
    </div>

    <p class="text-xs text-slate-500">
      Agenda interna baseada nos agendamentos reais. Bloqueios podem ser gerais (0) ou por barbeiro.
    </p>
  </div>
</main>

<!-- MODAL -->
<div id="apptModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 p-4 z-50">
  <!-- ✅ max-h + overflow-hidden + flex-col para footer sempre visível -->
  <div class="w-full max-w-xl bg-white rounded-xl shadow border max-h-[90vh] overflow-hidden flex flex-col">

    <div class="p-4 border-b">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold">Agendar (interno)</h2>
        <button type="button" id="apptClose" class="text-slate-500 text-xl leading-none">&times;</button>
      </div>

      <div class="text-sm text-slate-600 mt-2">
        <span id="apptInfo"></span>
      </div>

      <div class="rounded-lg border border-slate-200 p-3 bg-slate-50 mt-3">
        <div class="flex items-center justify-between text-sm">
          <div class="font-semibold text-slate-800">
            Total: <span id="apptTotalPrice">R$ 0,00</span>
          </div>
          <div class="font-semibold text-slate-800">
            Minutos: <span id="apptTotalMinutes">0</span> min
          </div>
        </div>
        <p class="text-[11px] text-slate-500 mt-1">
          Atualiza automaticamente conforme seleciona os serviços.
        </p>
      </div>
    </div>

    <!-- ✅ Form ocupa altura e cria área rolável interna -->
    <form method="post" action="<?= BASE_URL ?>/create_appointment_internal.php" class="flex flex-col flex-1 min-h-0">
      <input type="hidden" name="date" id="apptDate">
      <input type="hidden" name="time" id="apptTime">
      <input type="hidden" name="barber_id" id="apptBarberId">

      <!-- ✅ Conteúdo rolável -->
      <div class="p-4 space-y-3 flex-1 min-h-0 overflow-y-auto">
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

          <!-- ✅ Se quiser rolar só a lista de serviços (além do scroll geral), mantenha este bloco -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-[45vh] overflow-y-auto pr-1">
            <?php foreach ($services as $s): ?>
              <?php
                $k = (string)($s['service_key'] ?? '');
                $label = (string)($s['label'] ?? $k);
                $price = (float)($s['price'] ?? 0);
                $mins  = (int)($s['duration_minutes'] ?? 0);
              ?>
              <label class="js-svc-row flex items-center gap-2 border rounded px-3 py-2 text-sm bg-white"
                     data-service-key="<?= sanitize($k) ?>">
                <input
                  type="checkbox"
                  class="js-svc"
                  name="services[]"
                  value="<?= sanitize($k) ?>"
                  data-price="<?= htmlspecialchars((string)$price, ENT_QUOTES, 'UTF-8') ?>"
                  data-minutes="<?= (int)$mins ?>"
                >
                <span class="js-svc-label font-medium"><?= sanitize($label) ?></span>
                <span class="js-svc-details ml-auto text-slate-500">
                  R$ <?= number_format($price, 2, ',', '.') ?> · <?= (int)$mins ?>min
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ✅ Footer fixo (fora do scroll) -->
      <div class="p-4 border-t bg-white flex items-center justify-end gap-2">
        <button type="button" id="apptCancel" class="px-4 py-2 rounded border">Cancelar</button>
        <button class="px-4 py-2 rounded bg-indigo-600 text-white font-semibold hover:bg-indigo-500">
          Salvar agendamento
        </button>
      </div>
    </form>

  </div>
</div>

<script>
(function(){
  const servicesByBarber = <?= json_encode(
    $servicesByBarber,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?>;
  const modal = document.getElementById('apptModal');
  const apptInfo = document.getElementById('apptInfo');
  const apptDate = document.getElementById('apptDate');
  const apptTime = document.getElementById('apptTime');
  const apptBarberId = document.getElementById('apptBarberId');

  const totalPriceEl = document.getElementById('apptTotalPrice');
  const totalMinutesEl = document.getElementById('apptTotalMinutes');

  function formatBRL(v){
    try {
      return v.toLocaleString('pt-BR', { style:'currency', currency:'BRL' });
    } catch(e){
      return 'R$ ' + (Math.round(v * 100)/100).toFixed(2).replace('.', ',');
    }
  }

  function updateTotals(){
    let totalPrice = 0;
    let totalMinutes = 0;

    document.querySelectorAll('#apptModal .js-svc:checked').forEach(chk => {
      const p = parseFloat(chk.dataset.price || '0') || 0;
      const m = parseInt(chk.dataset.minutes || '0', 10) || 0;
      totalPrice += p;
      totalMinutes += m;
    });

    totalPriceEl.textContent = formatBRL(totalPrice);
    totalMinutesEl.textContent = String(totalMinutes);
  }

  function resetServices(){
    document.querySelectorAll('#apptModal .js-svc').forEach(chk => chk.checked = false);
    updateTotals();
  }

  function applyBarberServices(barberId){
    const catalog = servicesByBarber[String(barberId)] || {};

    document.querySelectorAll('#apptModal .js-svc-row').forEach(row => {
      const key = row.dataset.serviceKey || '';
      const service = catalog[key];
      const checkbox = row.querySelector('.js-svc');

      if (!service) {
        row.classList.add('hidden');
        checkbox.checked = false;
        checkbox.disabled = true;
        return;
      }

      const price = Number(service.price || 0);
      const minutes = Number.parseInt(service.duration_minutes || 30, 10);
      row.classList.remove('hidden');
      checkbox.disabled = false;
      checkbox.dataset.price = String(price);
      checkbox.dataset.minutes = String(minutes);
      row.querySelector('.js-svc-label').textContent = service.label || key;
      row.querySelector('.js-svc-details').textContent = `${formatBRL(price)} · ${minutes}min`;
    });
  }

  function openModal(btn){
    apptDate.value = btn.dataset.date;
    apptTime.value = btn.dataset.time;
    apptBarberId.value = btn.dataset.barberId;
    apptInfo.textContent = `${btn.dataset.date} às ${btn.dataset.time} — ${btn.dataset.barberName}`;

    applyBarberServices(btn.dataset.barberId);
    resetServices();

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

  document.querySelectorAll('#apptModal .js-svc').forEach(chk => {
    chk.addEventListener('change', updateTotals);
  });
})();
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
