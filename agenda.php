<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================
 * CONFIGURAÇÃO DA AGENDA
 * ==============================
 */

// 👉 Quantos clientes por horário (nº de barbeiros)
$MAX_PER_SLOT = 2;

// 👉 Duração de cada atendimento, em minutos (30, 45, 60...)
$SLOT_INTERVAL_MINUTES = 30;

// 👉 Horário de abertura e fechamento da barbearia
$OPEN_TIME  = '09:00';
$CLOSE_TIME = '19:00';

// 👉 Horários bloqueados (ex.: almoço)
// Com intervalo de 30min, de 11h às 13h:
$BLOCKED_SLOTS = [
    '11:00',
    '11:30',
    '12:00',
    '12:30',
];
// Se mudar o intervalo para 45min, por exemplo,
// ajuste a lista pra bater com os novos horários.

/**
 * ==============================
 * CARREGA EMPRESA E DATA
 * ==============================
 */

// slug da empresa: via GET ou sessão (igual loja.php)
$slug = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');

if (!$slug) {
    echo 'Empresa não informada.';
    exit;
}

$pdo = get_pdo();

// Carrega empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$stmt->execute([$slug]);
$company = $stmt->fetch();

if (!$company) {
    echo 'Empresa não encontrada.';
    exit;
}

$companyId = (int)$company['id'];

// Data selecionada
$today = new DateTimeImmutable('today');

$selectedDateStr = $_GET['data'] ?? $_POST['data'] ?? $today->format('Y-m-d');

// Garante formato de data válido
$selectedDate = DateTime::createFromFormat('Y-m-d', $selectedDateStr);
if (!$selectedDate) {
    $selectedDate = DateTime::createFromFormat('Y-m-d', $today->format('Y-m-d'));
    $selectedDateStr = $selectedDate->format('Y-m-d');
}

// Não deixa marcar datas no passado
if ($selectedDate < $today) {
    $selectedDate = DateTime::createFromFormat('Y-m-d', $today->format('Y-m-d'));
    $selectedDateStr = $selectedDate->format('Y-m-d');
}

$errors  = [];
$success = null;

/**
 * ==============================
 * PROCESSA AGENDAMENTO (POST)
 * ==============================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome      = trim($_POST['nome'] ?? '');
    $telefone  = trim($_POST['telefone'] ?? '');
    $instagram = trim($_POST['instagram'] ?? '');
    $dataPost  = trim($_POST['data'] ?? '');
    $horaPost  = trim($_POST['hora'] ?? '');

    // Validações básicas
    if ($nome === '') {
        $errors[] = 'Informe seu nome.';
    }
    if ($telefone === '') {
        $errors[] = 'Informe seu WhatsApp/telefone.';
    }

    $dataObj = DateTime::createFromFormat('Y-m-d', $dataPost);
    if (!$dataObj) {
        $errors[] = 'Data inválida.';
    } else {
        if ($dataObj < $today) {
            $errors[] = 'Não é possível agendar em datas passadas.';
        }
    }

    // Validação simples da hora no formato HH:MM
    if (!preg_match('/^\d{2}:\d{2}$/', $horaPost)) {
        $errors[] = 'Horário inválido.';
    }

    // Não deixa agendar em horário bloqueado (ex.: almoço)
    if (in_array($horaPost, $BLOCKED_SLOTS, true)) {
        $errors[] = 'Este horário não está disponível para agendamento.';
    }

    if (empty($errors) && $dataObj) {
        // Verificar se o horário ainda tem vagas (independente de quem seja)
        $stmt = $pdo->prepare('
            SELECT COUNT(*) AS total
            FROM appointments
            WHERE company_id = ? AND date = ? AND time = ?
        ');
        $stmt->execute([
            $companyId,
            $dataObj->format('Y-m-d'),
            $horaPost . ':00' // TIME no MySQL é HH:MM:SS
        ]);
        $row          = $stmt->fetch();
        $totalMarcado = (int)($row['total'] ?? 0);

        if ($totalMarcado >= $MAX_PER_SLOT) {
            $errors[] = 'Esse horário acabou de ficar cheio. Escolha outro horário.';
        } else {
            // 🚫 NOVO: impede o MESMO telefone de agendar 2x no mesmo horário
            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS total
                FROM appointments
                WHERE company_id = ? AND date = ? AND time = ? AND phone = ?
            ');
            $stmt->execute([
                $companyId,
                $dataObj->format('Y-m-d'),
                $horaPost . ':00',
                $telefone,
            ]);
            $jaCliente = (int)$stmt->fetchColumn();

            if ($jaCliente > 0) {
                $errors[] = 'Este telefone já possui agendamento neste horário.';
            } else {
                // Inserir agendamento
                $stmt = $pdo->prepare('
                    INSERT INTO appointments
                        (company_id, customer_name, phone, instagram, date, time, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([
                    $companyId,
                    $nome,
                    $telefone,
                    $instagram !== '' ? $instagram : null,
                    $dataObj->format('Y-m-d'),
                    $horaPost . ':00',
                ]);

                $success = 'Agendamento criado com sucesso! Você será atendido em '
                    . $dataObj->format('d/m/Y')
                    . ' às ' . $horaPost . 'h.';

                // Atualiza data selecionada para a data marcada
                $selectedDate    = $dataObj;
                $selectedDateStr = $selectedDate->format('Y-m-d');

                // Limpa POST pra não repopular o formulário
                $_POST = [];
            }
        }
    }
}

/**
 * ==============================
 * CARREGA AGENDAMENTOS DO DIA
 * ==============================
 */

// Carrega agendamentos da data selecionada para montar os slots
$appointmentsByTime = [];
$stmt = $pdo->prepare('
    SELECT time, COUNT(*) AS total
    FROM appointments
    WHERE company_id = ? AND date = ?
    GROUP BY time
');
$stmt->execute([$companyId, $selectedDateStr]);
foreach ($stmt->fetchAll() as $row) {
    // time vem como "HH:MM:SS" → converte pra "HH:MM"
    $timeKey = substr($row['time'], 0, 5);
    $appointmentsByTime[$timeKey] = (int)$row['total'];
}

/**
 * Gera todos os horários entre OPEN_TIME e CLOSE_TIME, respeitando o intervalo
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

// url base da página
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
    <!-- Cabeçalho -->
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
                    Agende seu horário
                </h1>
                <p class="text-sm text-slate-300 mt-1">
                    Escolha o dia e o horário disponíveis para seu atendimento na barbearia.
                </p>
            </div>
        </div>

        <div class="text-right space-y-1">
            <p class="text-xs text-slate-400">Loja</p>
            <p class="font-semibold"><?= sanitize($company['nome_fantasia']) ?></p>
            <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
               class="inline-flex text-sm text-emerald-300 hover:text-emerald-200 underline">
                ← Voltar à loja
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

    <!-- Layout principal: lado esquerdo horários, lado direito formulário -->
    <div class="grid grid-cols-1 lg:grid-cols-[1.3fr,1fr] gap-6 items-start">
        <!-- Coluna: seleção de data e lista de horários -->
        <section class="bg-white/5 border border-white/10 rounded-2xl p-5 space-y-4 shadow-xl">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Escolha o dia</h2>
                    <p class="text-xs text-slate-300">
                        Selecione a data para ver os horários disponíveis.
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
                        Horários para <span class="font-semibold">
                            <?= $selectedDate->format('d/m/Y') ?>
                        </span>
                    </p>
                    <p class="text-xs text-slate-400">
                        Máx. <?= $MAX_PER_SLOT ?> agendamento(s) por horário
                    </p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                    <?php if (empty($timeSlots)): ?>
                        <p class="text-sm text-slate-300 col-span-full">
                            Nenhum horário configurado.
                        </p>
                    <?php else: ?>
                        <?php foreach ($timeSlots as $slot): ?>
                            <?php
                            $qtd       = $appointmentsByTime[$slot] ?? 0;
                            $isFull    = $qtd >= $MAX_PER_SLOT;
                            $isBlocked = in_array($slot, $BLOCKED_SLOTS, true);
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
                                        Indisponível
                                    </span>
                                <?php elseif ($isFull): ?>
                                    <span class="mt-1 text-[10px] uppercase tracking-wide">
                                        Lotado
                                    </span>
                                <?php else: ?>
                                    <span class="mt-1 text-[10px] text-emerald-200">
                                        <?= $MAX_PER_SLOT - $qtd ?> vaga(s)
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p class="text-[11px] text-slate-400">
                    Para agendar, escolha o horário desejado ao lado e preencha seus dados.
                </p>
            </div>
        </section>

        <!-- Coluna: formulário de agendamento -->
        <section class="bg-white/5 border border-white/10 rounded-2xl p-5 space-y-4 shadow-xl">
            <h2 class="text-lg font-semibold">Dados para agendamento</h2>
            <p class="text-sm text-slate-300">
                Preencha seus dados e escolha um horário disponível.
            </p>

            <form method="post" action="<?= sanitize($selfUrl) ?>" class="space-y-4">
                <input type="hidden" name="data" value="<?= sanitize($selectedDateStr) ?>">

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
                        value="<?= sanitize($_POST['nome'] ?? '') ?>"
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
                        value="<?= sanitize($_POST['telefone'] ?? '') ?>"
                    >
                    <p class="text-[11px] text-slate-400 mt-1">
                        Usaremos esse número apenas para contato sobre o seu horário.
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
                        value="<?= sanitize($_POST['instagram'] ?? '') ?>"
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-200 mb-2">
                        Horário desejado <span class="text-red-400">*</span>
                    </label>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-60 overflow-y-auto pr-1">
                        <?php foreach ($timeSlots as $slot): ?>
                            <?php
                            $qtd       = $appointmentsByTime[$slot] ?? 0;
                            $isFull    = $qtd >= $MAX_PER_SLOT;
                            $isBlocked = in_array($slot, $BLOCKED_SLOTS, true);

                            // Só mostra horários realmente disponíveis
                            if ($isFull || $isBlocked) {
                                continue;
                            }

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
                                    <?= (($_POST['hora'] ?? '') === $slot) ? 'checked' : '' ?>
                                >
                                <span class="font-semibold"><?= sanitize($slot) ?></span>
                                <span class="mt-1 text-[10px] text-emerald-200">
                                    <?= $MAX_PER_SLOT - $qtd ?> vaga(s)
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pt-2 space-y-2">
                    <button
                        type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 text-slate-900 font-semibold text-sm px-4 py-3 hover:bg-emerald-400 shadow-lg shadow-emerald-500/30">
                        Confirmar agendamento
                    </button>
                    <p class="text-[11px] text-slate-400 text-center">
                        Você receberá a confirmação do seu horário pelo WhatsApp ou Instagram informado.
                    </p>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const radios  = document.querySelectorAll('input[name="hora"]');
    const buttons = document.querySelectorAll('.slot-button');

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

        // Se já vier marcado (ex.: depois de erro de validação), mantém destacado
        if (radio.checked) {
            const label = radio.closest('label');
            if (label) {
                label.classList.add('ring-2', 'ring-brand-500', 'bg-brand-500/20');
            }
        }
    });
});
</script>

</body>
</html>
