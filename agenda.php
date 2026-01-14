<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agenda_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================
 * CONFIGURACAO DA AGENDA
 * ==============================
 */

// Duracao de cada atendimento, em minutos (30, 45, 60...)
$SLOT_INTERVAL_MINUTES = 30;

// Horario de abertura e fechamento da barbearia
$OPEN_TIME  = '09:00';
$CLOSE_TIME = '20:00';

// Horarios bloqueados (fallback se nao houver bloqueios no banco)
$BLOCKED_SLOTS = [
    '11:00',
    '11:30',
    '12:00',
    '12:30',
];

/**
 * ==============================
 * CARREGA EMPRESA E DATA
 * ==============================
 */

// slug da empresa: via GET ou sessao (igual loja.php)
$slug = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');

if (!$slug) {
    echo 'Empresa nao informada.';
    exit;
}

$pdo = get_pdo();

// Carrega empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$stmt->execute([$slug]);
$company = $stmt->fetch();

if (!$company) {
    echo 'Empresa nao encontrada.';
    exit;
}

$companyId = (int)$company['id'];

// Data selecionada
$today = new DateTimeImmutable('today');

$selectedDateStr = $_GET['data'] ?? $_POST['data'] ?? $today->format('Y-m-d');

// Garante formato de data valido
$selectedDate = DateTime::createFromFormat('Y-m-d', $selectedDateStr);
if (!$selectedDate) {
    $selectedDate = DateTime::createFromFormat('Y-m-d', $today->format('Y-m-d'));
    $selectedDateStr = $selectedDate->format('Y-m-d');
}

// Nao deixa marcar datas no passado
if ($selectedDate < $today) {
    $selectedDate = DateTime::createFromFormat('Y-m-d', $today->format('Y-m-d'));
    $selectedDateStr = $selectedDate->format('Y-m-d');
}

$errors  = [];
$success = null;
$showConfirmation = false;

/**
 * ==============================
 * DADOS BASICOS DO FORMULARIO
 * ==============================
 */

$servicesCatalog = agenda_get_services_catalog();
$allBarbers = agenda_get_barbers($pdo, $companyId, false);
$activeBarbers = array_values(array_filter($allBarbers, function ($barber) {
    return (int)$barber['is_active'] === 1;
}));

$barbersById = [];
foreach ($allBarbers as $barber) {
    $barbersById[(int)$barber['id']] = $barber;
}

$nome = trim($_POST['nome'] ?? '');
$telefone = trim($_POST['telefone'] ?? '');
$instagram = trim($_POST['instagram'] ?? '');
$dataPost = trim($_POST['data'] ?? $selectedDateStr);
$horaPost = trim($_POST['hora'] ?? '');

$selectedBarberId = (int)($_POST['barbeiro'] ?? 0);
$selectedServices = agenda_normalize_services($_POST['servicos'] ?? [], $servicesCatalog);

$serviceTotals = agenda_calculate_services($selectedServices, $servicesCatalog);
$slotsNeeded = $serviceTotals['total_minutes'] > 0
    ? agenda_minutes_to_slots($serviceTotals['total_minutes'], $SLOT_INTERVAL_MINUTES)
    : 0;
$slotsNeededForAvailability = $slotsNeeded > 0 ? $slotsNeeded : 1;

/**
 * ==============================
 * SLOT, BLOQUEIOS E OCUPACOES
 * ==============================
 */

$timeSlots = agenda_generate_time_slots($OPEN_TIME, $CLOSE_TIME, $SLOT_INTERVAL_MINUTES);
$slotIndex = agenda_build_time_slot_index($timeSlots);
$blockedSlots = agenda_get_calendar_blocks($pdo, $companyId, $selectedDateStr, $BLOCKED_SLOTS);

$appointments = agenda_get_appointments_for_date($pdo, $companyId, $selectedDateStr);
$occupancy = agenda_build_occupancy_map($appointments, $timeSlots, $SLOT_INTERVAL_MINUTES);

/**
 * ==============================
 * PROCESSA AGENDAMENTO (POST)
 * ==============================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'preview';

    // Validacoes basicas
    if ($nome === '') {
        $errors[] = 'Informe seu nome.';
    }
    if ($telefone === '') {
        $errors[] = 'Informe seu WhatsApp/telefone.';
    }

    $dataObj = DateTime::createFromFormat('Y-m-d', $dataPost);
    if (!$dataObj) {
        $errors[] = 'Data invalida.';
    } else {
        if ($dataObj < $today) {
            $errors[] = 'Nao e possivel agendar em datas passadas.';
        }
    }

    if ($selectedBarberId <= 0 || !isset($barbersById[$selectedBarberId])) {
        $errors[] = 'Escolha o barbeiro desejado.';
    } elseif ((int)$barbersById[$selectedBarberId]['is_active'] !== 1) {
        $errors[] = 'Este barbeiro ainda nao esta disponivel.';
    }

    if (empty($selectedServices)) {
        $errors[] = 'Escolha pelo menos um servico.';
    }

    if ($slotsNeeded <= 0) {
        $errors[] = 'Duracao de servicos invalida.';
    }

    if ($horaPost === '') {
        $errors[] = 'Selecione um horario disponivel.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $horaPost)) {
        $errors[] = 'Horario invalido.';
    } elseif (!isset($slotIndex[$horaPost])) {
        $errors[] = 'Horario fora do funcionamento da barbearia.';
    }

    if (empty($errors) && $selectedBarberId > 0 && $slotsNeeded > 0 && $horaPost !== '') {
        $isAvailable = agenda_is_barber_available(
            $selectedBarberId,
            $horaPost,
            $slotsNeeded,
            $timeSlots,
            $blockedSlots,
            $occupancy,
            $slotIndex
        );

        if (!$isAvailable) {
            $errors[] = 'Esse horario nao esta mais disponivel para o barbeiro escolhido.';
        }
    }

    if (empty($errors) && $dataObj) {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) AS total
            FROM appointments
            WHERE company_id = ? AND date = ? AND time = ? AND barber_id = ? AND phone = ? AND status = "agendado"
        ');
        $stmt->execute([
            $companyId,
            $dataObj->format('Y-m-d'),
            $horaPost . ':00',
            $selectedBarberId,
            $telefone,
        ]);
        $jaCliente = (int)$stmt->fetchColumn();

        if ($jaCliente > 0) {
            $errors[] = 'Este telefone ja possui agendamento neste horario para o barbeiro escolhido.';
        }
    }

    if (empty($errors) && $dataObj) {
        if ($action === 'confirm') {
            $servicesJson = json_encode($selectedServices, JSON_UNESCAPED_UNICODE);
            $totalPrice = $serviceTotals['total_price'];
            $totalMinutes = $serviceTotals['total_minutes'];
            $endsAtTime = agenda_calculate_end_time($horaPost, $totalMinutes);

            $stmt = $pdo->prepare('
                INSERT INTO appointments
                    (company_id, barber_id, customer_name, phone, instagram, services_json, total_price,
                     total_duration_minutes, ends_at_time, date, time, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "agendado", NOW())
            ');
            $stmt->execute([
                $companyId,
                $selectedBarberId,
                $nome,
                $telefone,
                $instagram !== '' ? $instagram : null,
                $servicesJson,
                $totalPrice,
                $totalMinutes,
                $endsAtTime,
                $dataObj->format('Y-m-d'),
                $horaPost . ':00',
            ]);

            $appointmentId = (int)$pdo->lastInsertId();

            $barberName = $barbersById[$selectedBarberId]['name'] ?? 'Barbeiro';
            $servicesLabel = $serviceTotals['labels'] ? implode(', ', $serviceTotals['labels']) : '';
            $message = "Agendamento confirmado na " . $company['nome_fantasia'] . ":\n";
            $message .= "Data: " . $dataObj->format('d/m/Y') . "\n";
            $message .= "Hora: " . $horaPost . "\n";
            $message .= "Barbeiro: " . $barberName;
            if ($servicesLabel !== '') {
                $message .= "\nServicos: " . $servicesLabel;
            }
            if ($totalPrice > 0) {
                $message .= "\nTotal: " . format_currency($totalPrice);
            }

            if (send_whatsapp_message($telefone, $message)) {
                $update = $pdo->prepare('UPDATE appointments SET confirmed_message_sent_at = ? WHERE id = ?');
                $update->execute([now_utc_datetime(), $appointmentId]);
            }

            $success = 'Agendamento criado com sucesso! Voce sera atendido em '
                . $dataObj->format('d/m/Y')
                . ' as ' . $horaPost . 'h.';

            // Atualiza data selecionada para a data marcada
            $selectedDate = $dataObj;
            $selectedDateStr = $selectedDate->format('Y-m-d');

            // Limpa POST pra nao repopular o formulario
            $_POST = [];
            $nome = '';
            $telefone = '';
            $instagram = '';
            $horaPost = '';
            $selectedBarberId = 0;
            $selectedServices = [];
            $serviceTotals = agenda_calculate_services([], $servicesCatalog);
            $slotsNeeded = 0;
            $slotsNeededForAvailability = 1;
            $showConfirmation = false;
        } else {
            $showConfirmation = true;
        }
    }
}

/**
 * ==============================
 * DISPONIBILIDADE PARA EXIBICAO
 * ==============================
 */

$availableBarbersBySlot = [];
foreach ($timeSlots as $slot) {
    if (in_array($slot, $blockedSlots, true)) {
        $availableBarbersBySlot[$slot] = 0;
        continue;
    }
    $availableBarbersBySlot[$slot] = agenda_count_available_barbers(
        $activeBarbers,
        $slot,
        $slotsNeededForAvailability,
        $timeSlots,
        $blockedSlots,
        $occupancy,
        $slotIndex
    );
}

$availableSlotsForBarber = [];
if ($selectedBarberId > 0 && $slotsNeeded > 0) {
    foreach ($timeSlots as $slot) {
        if (agenda_is_barber_available(
            $selectedBarberId,
            $slot,
            $slotsNeeded,
            $timeSlots,
            $blockedSlots,
            $occupancy,
            $slotIndex
        )) {
            $availableSlotsForBarber[] = $slot;
        }
    }
}

// url base da pagina
$selfUrl = BASE_URL . '/agenda.php?empresa=' . urlencode($slug);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda - <?= sanitize($company['nome_fantasia']) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Space Grotesk"', 'Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: {
                            500: '#7c3aed',
                            600: '#6d28d9',
                            700: '#5b21b6',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-950 text-white min-h-screen">
<div class="absolute inset-0 -z-10 overflow-hidden">
    <div class="absolute -top-24 -left-10 h-80 w-80 bg-brand-600/30 rounded-full blur-3xl"></div>
    <div class="absolute top-20 right-0 h-96 w-96 bg-emerald-500/20 rounded-full blur-3xl"></div>
</div>

<div class="max-w-5xl mx-auto px-4 py-8 space-y-8">
    <!-- Cabecalho -->
    <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-4">
            <?php if (!empty($company['logo'])): ?>
                <img src="<?= sanitize(image_url($company['logo'])) ?>"
                     class="h-12 w-12 rounded-full border border-white/20 object-cover" alt="Logo">
            <?php else: ?>
                <div class="h-12 w-12 rounded-full bg-brand-600 flex items-center justify-center text-lg font-semibold">
                    <?= strtoupper(substr($company['nome_fantasia'], 0, 2)) ?>
                </div>
            <?php endif; ?>
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-200/80">
                    Agenda online
                </p>
                <h1 class="text-2xl md:text-3xl font-bold tracking-tight">
                    Agende seu horario
                </h1>
                <p class="text-sm text-slate-300 mt-1">
                    Escolha o dia e o horario disponiveis para seu atendimento na barbearia.
                </p>
            </div>
        </div>

        <div class="text-right space-y-1">
            <p class="text-xs text-slate-400">Loja</p>
            <p class="font-semibold"><?= sanitize($company['nome_fantasia']) ?></p>
            <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
               class="inline-flex text-sm font-semibold text-emerald-300 hover:text-emerald-200 underline">
                Ver produtos da loja
            </a>
        </div>
    </header>

    <!-- Alertas -->
    <?php if (!empty($errors)): ?>
        <div class="bg-red-500/10 border border-red-500/40 text-red-100 px-4 py-3 rounded-xl text-sm space-y-1">
            <?php foreach ($errors as $msg): ?>
                <p><?= sanitize($msg) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/40 text-emerald-100 px-4 py-3 rounded-xl text-sm">
            <?= sanitize($success) ?>
        </div>
    <?php endif; ?>

    <!-- Layout principal: lado esquerdo horarios, lado direito formulario -->
    <div class="grid grid-cols-1 lg:grid-cols-[1.3fr,1fr] gap-6 items-start">
        <!-- Coluna: selecao de data e lista de horarios -->
        <section class="bg-white/5 border border-white/10 rounded-2xl p-5 space-y-4 shadow-xl">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Escolha o dia</h2>
                    <p class="text-xs text-slate-300">
                        Selecione a data para ver os horarios disponiveis.
                    </p>
                </div>
                <form action="<?= sanitize($selfUrl) ?>" method="get" class="flex items-center gap-2">
                    <input type="hidden" name="empresa" value="<?= sanitize($slug) ?>">
                    <input
                        type="date"
                        name="data"
                        value="<?= sanitize($selectedDateStr) ?>"
                        min="<?= $today->format('Y-m-d') ?>"
                        class="rounded-lg border border-white/20 bg-slate-900/60 text-sm px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                    <button
                        type="submit"
                        class="px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-sm font-semibold">
                        Atualizar
                    </button>
                </form>
            </div>

            <div class="border-t border-white/10 pt-4 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-300">
                        Horarios para <span class="font-semibold">
                            <?= $selectedDate->format('d/m/Y') ?>
                        </span>
                    </p>
                    <p class="text-xs text-slate-400">
                        Max. <?= count($activeBarbers) ?> barbeiro(s) por horario
                    </p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                    <?php if (empty($timeSlots)): ?>
                        <p class="text-sm text-slate-300 col-span-full">
                            Nenhum horario configurado.
                        </p>
                    <?php else: ?>
                        <?php foreach ($timeSlots as $slot): ?>
                            <?php
                            $availableCount = $availableBarbersBySlot[$slot] ?? 0;
                            $isBlocked = in_array($slot, $blockedSlots, true);
                            $isFull = $availableCount <= 0;
                            ?>
                            <div
                                class="flex flex-col items-center justify-center px-3 py-2 rounded-xl border text-xs
                                <?=
                                    $isBlocked
                                        ? 'border-yellow-500/40 bg-yellow-500/10 text-yellow-100'
                                        : (
                                            $isFull
                                                ? 'border-red-500/40 bg-red-500/10 text-red-100'
                                                : 'border-white/15 bg-white/5 text-slate-100'
                                        )
                                ?>">
                                <span class="font-semibold"><?= sanitize($slot) ?></span>
                                <?php if ($isBlocked): ?>
                                    <span class="mt-1 text-[10px] uppercase tracking-wide">
                                        Indisponivel
                                    </span>
                                <?php elseif ($isFull): ?>
                                    <span class="mt-1 text-[10px] uppercase tracking-wide">
                                        Lotado
                                    </span>
                                <?php else: ?>
                                    <span class="mt-1 text-[10px] text-emerald-200">
                                        <?= $availableCount ?> barbeiro(s) livre(s)
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p class="text-[11px] text-slate-400">
                    Para agendar, escolha o barbeiro e os servicos ao lado.
                </p>

                <a
                    href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
                    class="inline-flex mt-2 items-center justify-center px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-xs font-semibold text-white"
                >
                    👉 Ver produtos da For Men Store
                </a>
            </div>
        </section>

        <!-- Coluna: formulario de agendamento -->
        <section class="bg-white/5 border border-white/10 rounded-2xl p-5 space-y-4 shadow-xl">
            <h2 class="text-lg font-semibold">Dados para agendamento</h2>
            <p class="text-sm text-slate-300">
                Escolha o barbeiro, selecione servicos e finalize seu horario.
            </p>

            <form method="post" action="<?= sanitize($selfUrl) ?>" class="space-y-4">
                <input type="hidden" name="data" value="<?= sanitize($selectedDateStr) ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-200 mb-2">
                        Barbeiro desejado <span class="text-red-400">*</span>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <?php if (empty($allBarbers)): ?>
                            <p class="text-xs text-slate-400 col-span-full">
                                Nenhum barbeiro cadastrado para esta empresa.
                            </p>
                        <?php else: ?>
                            <?php foreach ($allBarbers as $barber): ?>
                                <?php
                                $barberId = (int)$barber['id'];
                                $isActive = (int)$barber['is_active'] === 1;
                                $isChecked = $selectedBarberId === $barberId;
                                ?>
                                <label class="barber-button flex flex-col items-start gap-1 px-3 py-2 rounded-xl border text-xs cursor-pointer
                                    <?= $isActive ? 'border-white/15 bg-white/5 text-slate-100 hover:border-brand-500 hover:bg-brand-500/20' : 'border-white/10 bg-white/5 text-slate-500 opacity-60 cursor-not-allowed' ?>">
                                    <input
                                        type="radio"
                                        name="barbeiro"
                                        value="<?= $barberId ?>"
                                        class="hidden"
                                        <?= $isActive ? '' : 'disabled' ?>
                                        <?= $isChecked ? 'checked' : '' ?>
                                    >
                                    <span class="font-semibold"><?= sanitize($barber['name']) ?></span>
                                    <?php if (!$isActive): ?>
                                        <span class="text-[10px] uppercase tracking-wide text-yellow-200">
                                            Em breve
                                        </span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-200 mb-2">
                        Servicos desejados <span class="text-red-400">*</span>
                    </label>
                    <div class="space-y-2">
                        <?php foreach ($servicesCatalog as $key => $service): ?>
                            <?php $checked = in_array($key, $selectedServices, true); ?>
                            <label class="flex items-start gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="servicos[]"
                                    value="<?= sanitize($key) ?>"
                                    class="mt-1 h-4 w-4 rounded border-white/30 bg-slate-900 text-brand-500 focus:ring-brand-500"
                                    <?= $checked ? 'checked' : '' ?>
                                >
                                <div class="flex flex-col">
                                    <span class="font-medium text-slate-100"><?= sanitize($service['label']) ?></span>
                                    <span class="text-[11px] text-slate-400">
                                        <?= format_currency($service['price']) ?> · <?= (int)$service['duration'] ?> min
                                    </span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($serviceTotals['total_minutes'] > 0): ?>
                        <p class="text-[11px] text-emerald-200 mt-2">
                            Duracao total: <?= (int)$serviceTotals['total_minutes'] ?> min
                            (<?= $slotsNeeded ?> bloco(s) de <?= $SLOT_INTERVAL_MINUTES ?> min)
                        </p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-200 mb-2">
                        Horario desejado <span class="text-red-400">*</span>
                    </label>

                    <?php if ($selectedBarberId <= 0 || empty($selectedServices)): ?>
                        <p class="text-[11px] text-slate-400">
                            Selecione o barbeiro e os servicos para liberar os horarios disponiveis.
                        </p>
                    <?php else: ?>
                        <?php if (empty($availableSlotsForBarber)): ?>
                            <p class="text-[11px] text-slate-400">
                                Nenhum horario disponivel para este barbeiro na data selecionada.
                            </p>
                        <?php else: ?>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-60 overflow-y-auto pr-1">
                                <?php foreach ($availableSlotsForBarber as $slot): ?>
                                    <?php
                                    $inputId = 'hora_' . str_replace(':', '', $slot);
                                    ?>
                                    <label for="<?= $inputId ?>"
                                           class="slot-button flex flex-col items-center justify-center px-3 py-2 rounded-xl border text-xs cursor-pointer
                                           border-white/15 bg-white/5 text-slate-100 hover:border-brand-500 hover:bg-brand-500/20">
                                        <input
                                            type="radio"
                                            id="<?= $inputId ?>"
                                            name="hora"
                                            value="<?= sanitize($slot) ?>"
                                            class="hidden"
                                            <?= ($horaPost === $slot) ? 'checked' : '' ?>
                                        >
                                        <span class="font-semibold"><?= sanitize($slot) ?></span>
                                        <span class="mt-1 text-[10px] text-emerald-200">
                                            Disponivel
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-200 mb-1">
                        Nome completo <span class="text-red-400">*</span>
                    </label>
                    <input
                        type="text"
                        name="nome"
                        required
                        class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        placeholder="Seu nome"
                        value="<?= sanitize($nome) ?>"
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-200 mb-1">
                        Telefone / WhatsApp <span class="text-red-400">*</span>
                    </label>
                    <input
                        type="text"
                        name="telefone"
                        required
                        class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        placeholder="(00) 00000-0000"
                        value="<?= sanitize($telefone) ?>"
                    >
                    <p class="text-[11px] text-slate-400 mt-1">
                        Usaremos esse numero apenas para contato sobre o seu horario.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-200 mb-1">
                        Instagram <span class="text-slate-400 text-xs">(opcional)</span>
                    </label>
                    <input
                        type="text"
                        name="instagram"
                        class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        placeholder="@usuario"
                        value="<?= sanitize($instagram) ?>"
                    >
                </div>

                <?php if ($showConfirmation && empty($errors)): ?>
                    <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-100 space-y-1">
                        <p class="font-semibold">Confira os dados do seu agendamento:</p>
                        <p>Data: <?= $selectedDate->format('d/m/Y') ?> - <?= sanitize($horaPost) ?></p>
                        <p>Barbeiro: <?= sanitize($barbersById[$selectedBarberId]['name'] ?? 'Barbeiro') ?></p>
                        <p>Servicos: <?= sanitize(implode(', ', $serviceTotals['labels'])) ?></p>
                        <p>Total: <?= format_currency($serviceTotals['total_price']) ?></p>
                    </div>
                <?php endif; ?>

                <div class="pt-2 space-y-2">
                    <?php if ($showConfirmation && empty($errors)): ?>
                        <button
                            type="submit"
                            name="action"
                            value="confirm"
                            class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 text-slate-900 font-semibold text-sm px-4 py-3 hover:bg-emerald-400 shadow-lg shadow-emerald-500/30">
                            Confirmar agendamento
                        </button>
                    <?php else: ?>
                        <button
                            type="submit"
                            name="action"
                            value="preview"
                            class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 text-slate-900 font-semibold text-sm px-4 py-3 hover:bg-emerald-400 shadow-lg shadow-emerald-500/30">
                            Revisar agendamento
                        </button>
                    <?php endif; ?>
                    <p class="text-[11px] text-slate-400 text-center">
                        Voce recebera a confirmacao do seu horario pelo WhatsApp ou Instagram informado.
                    </p>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function bindToggle(name, className) {
        const radios = document.querySelectorAll('input[name="' + name + '"]');
        const buttons = document.querySelectorAll('.' + className);

        function clearSelection() {
            buttons.forEach(btn => {
                btn.classList.remove('ring-2', 'ring-brand-500', 'bg-brand-500/20');
            });
        }

        radios.forEach(radio => {
            radio.addEventListener('change', function () {
                clearSelection();
                const label = radio.closest('label');
                if (label) {
                    label.classList.add('ring-2', 'ring-brand-500', 'bg-brand-500/20');
                }
            });

            if (radio.checked) {
                const label = radio.closest('label');
                if (label) {
                    label.classList.add('ring-2', 'ring-brand-500', 'bg-brand-500/20');
                }
            }
        });
    }

    bindToggle('hora', 'slot-button');
    bindToggle('barbeiro', 'barber-button');
});
</script>

</body>
</html>
