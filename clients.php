<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

$pdo = get_pdo();

// aplica timezone da sessão do MySQL (não quebra se não existir)
if (function_exists('pdo_apply_timezone')) {
    pdo_apply_timezone($pdo);
}

$companyId = current_company_id();
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!$companyId) {
    flash('error', 'Empresa não definida na sessão.');
    redirect('dashboard.php');
}

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

<<<<<<< HEAD
/**
 * =========================================
 * HELPERS LOCAIS (timezone + urls + telefone)
 * =========================================
 */

function phone_digits(?string $phone): string {
    $p = preg_replace('/\D+/', '', (string)$phone);
    return $p ?: '';
}

function clients_url(string $qs = ''): string {
    $path = 'clients.php';
    return $qs ? ($path . '?' . $qs) : $path;
}

/** Converte datetime do banco (assumido UTC) para fuso do app via helpers.php */
function safe_dt(?string $dt): string {
    if (!$dt) return '';
    if (function_exists('format_datetime_br')) {
        return format_datetime_br($dt, 'UTC');
    }
    // fallback simples (sem conversão)
    return date('d/m/Y H:i', strtotime($dt));
}

/**
 * ===========================
 * CREATE / UPDATE CLIENTE
 * ===========================
 */
=======
// =========================
// Create or update client
// =========================
>>>>>>> f3837e5 (Fix timezone display (dashboard))
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'client') {
    $data = [
        'nome' => trim($_POST['nome'] ?? ''),
        'telefone_principal' => trim($_POST['telefone_principal'] ?? ''),
        'whatsapp' => trim($_POST['whatsapp'] ?? ''),
        'instagram_username' => trim($_POST['instagram_username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'tags' => trim($_POST['tags'] ?? ''),
        'observacoes' => trim($_POST['observacoes'] ?? ''),
    ];

    if ($data['nome'] === '') {
        flash('error', 'Informe o nome do cliente.');
        redirect(clients_url('action=' . ($id ? 'edit&id=' . $id : 'create')));
    }

<<<<<<< HEAD
    $nowUtc = now_utc_datetime();

    if ($id) {
        $stmt = $pdo->prepare('
            UPDATE clients
            SET nome=?,
                telefone_principal=?,
                whatsapp=?,
                instagram_username=?,
                email=?,
                tags=?,
                observacoes=?,
                updated_at=?
            WHERE id=? AND company_id=?
        ');
=======
    $now = now_datetime();

    if ($id) {
        $stmt = $pdo->prepare(
            'UPDATE clients
             SET nome=?, telefone_principal=?, whatsapp=?, instagram_username=?, email=?, tags=?, observacoes=?, updated_at=?
             WHERE id=? AND company_id=?'
        );
>>>>>>> f3837e5 (Fix timezone display (dashboard))
        $stmt->execute([
            $data['nome'],
            $data['telefone_principal'],
            $data['whatsapp'],
            $data['instagram_username'],
            $data['email'],
            $data['tags'],
            $data['observacoes'],
<<<<<<< HEAD
            $nowUtc,
            $id,
            $companyId,
        ]);

        log_action($pdo, (int)$companyId, (int)($_SESSION['user_id'] ?? 0), 'cliente_update', 'Cliente #' . $id);
        flash('success', 'Cliente atualizado com sucesso.');
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO clients
                (company_id, nome, telefone_principal, whatsapp, instagram_username, email, tags, observacoes, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
=======
            $now,
            $id,
            $companyId
        ]);

        log_action($pdo, $companyId, $userId, 'cliente_update', 'Cliente #' . $id);
        flash('success', 'Cliente atualizado com sucesso.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO clients (company_id, nome, telefone_principal, whatsapp, instagram_username, email, tags, observacoes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
>>>>>>> f3837e5 (Fix timezone display (dashboard))
        $stmt->execute([
            $companyId,
            $data['nome'],
            $data['telefone_principal'],
            $data['whatsapp'],
            $data['instagram_username'],
            $data['email'],
            $data['tags'],
            $data['observacoes'],
<<<<<<< HEAD
            $nowUtc,
            $nowUtc,
        ]);

        $id = (int)$pdo->lastInsertId();
        log_action($pdo, (int)$companyId, (int)($_SESSION['user_id'] ?? 0), 'cliente_create', 'Cliente #' . $id);
        flash('success', 'Cliente criado com sucesso.');
    }

    redirect(clients_url('action=view&id=' . (int)$id));
}

/**
 * ===========================
 * DELETE CLIENTE
 * ===========================
 */
=======
            $now,
            $now
        ]);

        $id = (int)$pdo->lastInsertId();
        log_action($pdo, $companyId, $userId, 'cliente_create', 'Cliente #' . $id);
        flash('success', 'Cliente criado com sucesso.');
    }

    redirect('/clients.php?action=view&id=' . $id);
}

// =========================
// Delete client
// =========================
>>>>>>> f3837e5 (Fix timezone display (dashboard))
if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare('DELETE FROM clients WHERE id=? AND company_id=?');
    $stmt->execute([$id, $companyId]);

<<<<<<< HEAD
    log_action($pdo, (int)$companyId, (int)($_SESSION['user_id'] ?? 0), 'cliente_delete', 'Cliente #' . $id);
=======
    log_action($pdo, $companyId, $userId, 'cliente_delete', 'Cliente #' . $id);
>>>>>>> f3837e5 (Fix timezone display (dashboard))
    flash('success', 'Cliente removido.');
    redirect(clients_url());
}

<<<<<<< HEAD
/**
 * ===========================
 * CRIAR ATENDIMENTO (MANUAL)
 * ===========================
 */
=======
// =========================
// Interaction create (manual)
// =========================
>>>>>>> f3837e5 (Fix timezone display (dashboard))
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'interaction' && $id) {
    $canal  = $_POST['canal'] ?? 'whatsapp';
    $titulo = trim($_POST['titulo'] ?? '');
    $resumo = trim($_POST['resumo'] ?? '');

    if ($titulo !== '' && $resumo !== '') {
<<<<<<< HEAD
        $nowUtc = now_utc_datetime();

        $stmt = $pdo->prepare('
            INSERT INTO interactions
                (company_id, client_id, canal, origem, titulo, resumo, atendente, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ');
=======
        $now = now_datetime();

        $stmt = $pdo->prepare(
            'INSERT INTO interactions (company_id, client_id, canal, origem, titulo, resumo, atendente, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
>>>>>>> f3837e5 (Fix timezone display (dashboard))
        $stmt->execute([
            $companyId,
            $id,
            $canal,
            'manual',
            $titulo,
            $resumo,
            current_user_name(),
<<<<<<< HEAD
            $nowUtc,
        ]);

        $pdo->prepare('
            UPDATE clients
            SET ultimo_atendimento_em=?, updated_at=?
            WHERE id=? AND company_id=?
        ')->execute([$nowUtc, $nowUtc, $id, $companyId]);
=======
            $now
        ]);

        $pdo->prepare('UPDATE clients SET ultimo_atendimento_em=? WHERE id=? AND company_id=?')
            ->execute([$now, $id, $companyId]);
>>>>>>> f3837e5 (Fix timezone display (dashboard))

        flash('success', 'Atendimento registrado.');
    } else {
        flash('error', 'Preencha título e resumo.');
    }

<<<<<<< HEAD
    redirect(clients_url('action=view&id=' . (int)$id));
=======
    redirect('/clients.php?action=view&id=' . $id);
>>>>>>> f3837e5 (Fix timezone display (dashboard))
}

include __DIR__ . '/views/partials/header.php';

// Flash messages
if ($msg = get_flash('success')) {
    echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($msg) . '</div>';
}
if ($msg = get_flash('error')) {
    echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($msg) . '</div>';
}

/**
 * ===========================
 * FORM CREATE / EDIT
 * ===========================
 */
if ($action === 'create' || ($action === 'edit' && $id)) {
    $client = [
        'nome' => '',
        'telefone_principal' => '',
        'whatsapp' => '',
        'instagram_username' => '',
        'email' => '',
        'tags' => '',
        'observacoes' => '',
    ];

    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id=? AND company_id=?');
        $stmt->execute([$id, $companyId]);
<<<<<<< HEAD
        $client = $stmt->fetch();

        if (!$client) {
            echo '<div class="p-3 rounded bg-red-50 text-red-700 border border-red-200">Cliente não encontrado.</div>';
            include __DIR__ . '/views/partials/footer.php';
            exit;
        }
=======
        $client = $stmt->fetch() ?: $client;
>>>>>>> f3837e5 (Fix timezone display (dashboard))
    }
    ?>
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm max-w-3xl">
        <h1 class="text-2xl font-semibold mb-2"><?= $id ? 'Editar Cliente' : 'Novo Cliente' ?></h1>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="form" value="client">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Nome</label>
                <input name="nome" value="<?= sanitize((string)$client['nome']) ?>" placeholder="Ex.: Maria Silva" class="w-full rounded border-slate-300" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Telefone principal</label>
<<<<<<< HEAD
                <input name="telefone_principal" value="<?= sanitize((string)$client['telefone_principal']) ?>" placeholder="Ex.: 5565999999999" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">WhatsApp</label>
                <input name="whatsapp" value="<?= sanitize((string)$client['whatsapp']) ?>" placeholder="Ex.: 5565999999999" class="w-full rounded border-slate-300">
                <p class="text-xs text-slate-500 mt-1">Dica: use só números (DDI+DDD+Número). Ex: 5565999999999</p>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Instagram</label>
                <input name="instagram_username" value="<?= sanitize((string)$client['instagram_username']) ?>" placeholder="Ex.: usuario ou @usuario" class="w-full rounded border-slate-300">
=======
                <input name="telefone_principal" value="<?= sanitize((string)$client['telefone_principal']) ?>" placeholder="Ex.: 559999999999" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">WhatsApp</label>
                <input name="whatsapp" value="<?= sanitize((string)$client['whatsapp']) ?>" placeholder="Ex.: 559999999999" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Instagram</label>
                <input name="instagram_username" value="<?= sanitize((string)$client['instagram_username']) ?>" placeholder="Ex.: @usuario" class="w-full rounded border-slate-300">
>>>>>>> f3837e5 (Fix timezone display (dashboard))
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">E-mail</label>
                <input name="email" value="<?= sanitize((string)$client['email']) ?>" type="email" placeholder="Ex.: contato@cliente.com" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Tags</label>
                <input name="tags" value="<?= sanitize((string)$client['tags']) ?>" placeholder="Ex.: vip, recorrente" class="w-full rounded border-slate-300">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Observações</label>
                <textarea name="observacoes" class="w-full rounded border-slate-300" rows="3"><?= sanitize((string)$client['observacoes']) ?></textarea>
            </div>
            <div class="md:col-span-2 flex gap-3">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700"><?= $id ? 'Salvar' : 'Criar' ?></button>
                <a href="<?= sanitize(clients_url()) ?>" class="px-4 py-2 rounded border border-slate-300 text-slate-700">Cancelar</a>
            </div>
        </form>
    </div>
    <?php
    include __DIR__ . '/views/partials/footer.php';
    exit;
}

/**
 * ===========================
 * VIEW CLIENTE
 * ===========================
 */
if ($action === 'view' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id=? AND company_id=?');
    $stmt->execute([$id, $companyId]);
    $client = $stmt->fetch();

    if (!$client) {
        echo '<div class="p-3 rounded bg-red-50 text-red-700 border border-red-200">Cliente não encontrado.</div>';
        include __DIR__ . '/views/partials/footer.php';
        exit;
    }

<<<<<<< HEAD
    $ordersStmt = $pdo->prepare('
        SELECT COUNT(*) as total_pedidos, COALESCE(SUM(total),0) as total_valor
        FROM orders
        WHERE company_id=? AND client_id=?
    ');
=======
    $ordersStmt = $pdo->prepare('SELECT COUNT(*) as total_pedidos, COALESCE(SUM(total),0) as total_valor FROM orders WHERE company_id=? AND client_id=?');
>>>>>>> f3837e5 (Fix timezone display (dashboard))
    $ordersStmt->execute([$companyId, $id]);
    $orderStats = $ordersStmt->fetch() ?: ['total_pedidos'=>0, 'total_valor'=>0];

<<<<<<< HEAD
    $interactionsStmt = $pdo->prepare('
        SELECT *
        FROM interactions
        WHERE company_id=? AND client_id=?
        ORDER BY created_at DESC
    ');
=======
    $interactionsStmt = $pdo->prepare('SELECT * FROM interactions WHERE company_id=? AND client_id=? ORDER BY created_at DESC');
>>>>>>> f3837e5 (Fix timezone display (dashboard))
    $interactionsStmt->execute([$companyId, $id]);
    $interactions = $interactionsStmt->fetchAll();

    $clientOpps = [];
    try {
        $oppStmt = $pdo->prepare('
            SELECT o.*, p.nome as pipeline_nome, s.nome as stage_nome
            FROM opportunities o
            JOIN pipelines p ON p.id=o.pipeline_id
            JOIN pipeline_stages s ON s.id=o.stage_id
            WHERE o.company_id=? AND o.client_id=?
            ORDER BY o.created_at DESC
        ');
        $oppStmt->execute([$companyId, $id]);
        $clientOpps = $oppStmt->fetchAll();
    } catch (Throwable $e) {
        $clientOpps = [];
    }

    $clientEvents = [];
    try {
        $eventsStmt = $pdo->prepare('
            SELECT ce.*, u.nome as user_nome
            FROM calendar_events ce
            LEFT JOIN users u ON u.id = ce.user_id
            WHERE ce.company_id=? AND ce.client_id=?
            ORDER BY ce.data_inicio DESC
        ');
        $eventsStmt->execute([$companyId, $id]);
        $clientEvents = $eventsStmt->fetchAll();
    } catch (Throwable $e) {
        $clientEvents = [];
    }

    $waNumber = phone_digits($client['whatsapp'] ?? '');
    $waText   = 'Olá, ' . (string)($client['nome'] ?? '');
    $waLink   = $waNumber ? ('https://wa.me/' . $waNumber . '?text=' . urlencode($waText)) : '';

    $igUser = ltrim((string)($client['instagram_username'] ?? ''), '@');
    $igLink = $igUser ? ('https://instagram.com/' . rawurlencode($igUser)) : '';
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm lg:col-span-2">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm text-slate-500">Cliente</p>
                    <h1 class="text-2xl font-semibold"><?= sanitize((string)$client['nome']) ?></h1>
<<<<<<< HEAD
                    <p class="text-sm text-slate-600 mt-1"><?= sanitize((string)($client['email'] ?? '')) ?></p>

                    <div class="mt-3 flex gap-3 text-sm text-slate-600 flex-wrap">
                        <?php if ($waLink): ?>
                            <a class="text-indigo-600 hover:underline" target="_blank" href="<?= sanitize($waLink) ?>">
                                WhatsApp: <?= sanitize($waNumber) ?>
                            </a>
                        <?php endif; ?>

                        <?php if ($igLink): ?>
                            <a class="text-sky-600 hover:underline" target="_blank" href="<?= sanitize($igLink) ?>">
                                Instagram: <?= sanitize($igUser) ?>
=======
                    <p class="text-sm text-slate-600 mt-1"><?= sanitize((string)$client['email']) ?></p>

                    <div class="mt-3 flex gap-2 text-sm text-slate-600">
                        <?php if (!empty($client['whatsapp'])): ?>
                            <a class="text-indigo-600 hover:underline" target="_blank"
                               href="https://api.whatsapp.com/send?phone=<?= urlencode((string)$client['whatsapp']) ?>&text=Ol%C3%A1%2C%20<?= urlencode((string)$client['nome']) ?>">
                               <?= sanitize((string)$client['whatsapp']) ?>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($client['instagram_username'])): ?>
                            <a class="text-sky-600 hover:underline" target="_blank"
                               href="https://instagram.com/<?= ltrim(sanitize((string)$client['instagram_username']), '@') ?>">
                               Instagram
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                            </a>
                        <?php endif; ?>
                    </div>

<<<<<<< HEAD
                    <p class="mt-2 text-sm text-slate-500">Tags: <?= sanitize((string)($client['tags'] ?? '')) ?></p>
=======
                    <p class="mt-2 text-sm text-slate-500">Tags: <?= sanitize((string)$client['tags']) ?></p>
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                </div>

                <div class="flex gap-2">
                    <a href="<?= sanitize(clients_url('action=edit&id=' . (int)$id)) ?>" class="px-3 py-2 rounded border border-slate-300 text-slate-700">Editar</a>
                    <a href="<?= sanitize(clients_url('action=delete&id=' . (int)$id)) ?>" class="px-3 py-2 rounded border border-red-200 text-red-700" onclick="return confirm('Excluir cliente?')">Excluir</a>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                <div class="p-3 rounded-lg border border-slate-100 bg-slate-50">
                    <p class="text-sm text-slate-600">Pedidos</p>
<<<<<<< HEAD
                    <p class="text-xl font-semibold"><?= (int)($orderStats['total_pedidos'] ?? 0) ?></p>
=======
                    <p class="text-xl font-semibold"><?= (int)$orderStats['total_pedidos'] ?></p>
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                </div>
                <div class="p-3 rounded-lg border border-slate-100 bg-slate-50">
                    <p class="text-sm text-slate-600">LTV</p>
                    <p class="text-xl font-semibold"><?= format_currency($orderStats['total_valor'] ?? 0) ?></p>
                </div>
                <div class="p-3 rounded-lg border border-slate-100 bg-slate-50">
                    <p class="text-sm text-slate-600">Último atendimento</p>
<<<<<<< HEAD
                    <p class="text-xl font-semibold"><?= !empty($client['ultimo_atendimento_em']) ? safe_dt($client['ultimo_atendimento_em']) : 'N/A' ?></p>
=======
                    <p class="text-xl font-semibold">
                        <?= !empty($client['ultimo_atendimento_em']) ? format_datetime_br((string)$client['ultimo_atendimento_em']) : 'N/A' ?>
                    </p>
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                </div>
            </div>

            <div class="mt-6 p-3 rounded-lg border border-emerald-100 bg-emerald-50 text-sm text-emerald-800">
                Interações criadas automaticamente pela IA aparecem com origem <span class="font-semibold">"ia"</span>.
            </div>

            <h2 class="text-lg font-semibold mt-4 mb-2">Histórico de atendimentos</h2>
            <div class="overflow-hidden border border-slate-100 rounded-lg bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-2 text-left">Título</th>
                            <th class="px-4 py-2 text-left">Canal</th>
                            <th class="px-4 py-2 text-left">Origem</th>
                            <th class="px-4 py-2 text-left">Atendente</th>
                            <th class="px-4 py-2 text-left">Data</th>
                            <th class="px-4 py-2 text-left">Resumo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($interactions as $interaction): ?>
                            <?php $isIa = strtolower((string)($interaction['origem'] ?? '')) === 'ia'; ?>
                            <tr class="border-t border-slate-100">
<<<<<<< HEAD
                                <td class="px-4 py-2 font-medium"><?= sanitize((string)($interaction['titulo'] ?? '')) ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= sanitize((string)($interaction['canal'] ?? '')) ?></td>
=======
                                <td class="px-4 py-2 font-medium"><?= sanitize((string)$interaction['titulo']) ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= sanitize((string)$interaction['canal']) ?></td>
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                                <td class="px-4 py-2">
                                    <span class="px-2 py-1 rounded text-xs <?= $isIa ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-slate-100 text-slate-700' ?>">
<<<<<<< HEAD
                                        <?= strtoupper(sanitize((string)($interaction['origem'] ?? ''))) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-slate-600"><?= sanitize((string)($interaction['atendente'] ?? '')) ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= safe_dt($interaction['created_at'] ?? null) ?></td>
                                <td class="px-4 py-2 text-slate-700"><?= sanitize((string)($interaction['resumo'] ?? '')) ?></td>
=======
                                        <?= strtoupper(sanitize((string)$interaction['origem'])) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-slate-600"><?= sanitize((string)$interaction['atendente']) ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= format_datetime_br((string)$interaction['created_at']) ?></td>
                                <td class="px-4 py-2 text-slate-700"><?= sanitize((string)$interaction['resumo']) ?></td>
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($interactions)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-3 text-center text-slate-500">Nenhum atendimento ainda.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<<<<<<< HEAD
=======
        <!-- Oportunidades -->
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm mt-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-semibold">Oportunidades do cliente</h3>
                <a class="text-sm text-indigo-600 hover:underline" href="/opportunities.php">Nova oportunidade</a>
            </div>
            <div class="overflow-hidden border border-slate-200 rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 text-slate-700">
                        <tr>
                            <th class="px-3 py-2 text-left">Título</th>
                            <th class="px-3 py-2 text-left">Pipeline/Etapa</th>
                            <th class="px-3 py-2 text-left">Valor</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Datas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientOpps as $op): ?>
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 font-medium"><?= sanitize((string)$op['titulo']) ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= sanitize((string)$op['pipeline_nome']) ?> / <?= sanitize((string)$op['stage_nome']) ?></td>
                                <td class="px-3 py-2"><?= format_currency($op['valor_potencial']) ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= ucfirst((string)$op['status']) ?></td>
                                <td class="px-3 py-2 text-xs text-slate-500">
                                    Criada <?= !empty($op['created_at']) ? date('d/m', strtotime((string)$op['created_at'])) : '' ?>
                                    <?php if (!empty($op['closed_at'])): ?>
                                        • Fechada <?= date('d/m', strtotime((string)$op['closed_at'])) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientOpps)): ?>
                            <tr><td colspan="5" class="px-3 py-3 text-center text-slate-500">Nenhuma oportunidade.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Agenda -->
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm mt-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-semibold">Agenda / Compromissos</h3>
                <a class="text-sm text-indigo-600 hover:underline" href="/calendar.php">Novo evento</a>
            </div>
            <div class="overflow-hidden border border-slate-200 rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 text-slate-700">
                        <tr>
                            <th class="px-3 py-2 text-left">Data</th>
                            <th class="px-3 py-2 text-left">Título</th>
                            <th class="px-3 py-2 text-left">Responsável</th>
                            <th class="px-3 py-2 text-left">Tipo</th>
                            <th class="px-3 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientEvents as $ev): ?>
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 text-slate-600"><?= !empty($ev['data_inicio']) ? format_datetime_br((string)$ev['data_inicio']) : '' ?></td>
                                <td class="px-3 py-2 font-medium"><?= sanitize((string)$ev['titulo']) ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= sanitize((string)$ev['user_nome']) ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= sanitize((string)$ev['tipo']) ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= ucfirst((string)$ev['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientEvents)): ?>
                            <tr><td colspan="5" class="px-3 py-3 text-center text-slate-500">Nenhum evento.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Registrar atendimento -->
>>>>>>> f3837e5 (Fix timezone display (dashboard))
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
            <h3 class="text-lg font-semibold mb-3">Registrar atendimento</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="form" value="interaction">
                <div>
                    <label class="block text-sm text-slate-600 mb-1">Canal</label>
                    <select name="canal" class="w-full rounded border-slate-300">
                        <option value="whatsapp">WhatsApp</option>
                        <option value="instagram">Instagram</option>
                        <option value="telefone">Telefone</option>
                        <option value="presencial">Presencial</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">Título</label>
                    <input name="titulo" class="w-full rounded border-slate-300" required>
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">Resumo</label>
                    <textarea name="resumo" class="w-full rounded border-slate-300" rows="3" required></textarea>
                </div>
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 w-full">Salvar atendimento</button>
            </form>
        </div>
    </div>
    <?php
    include __DIR__ . '/views/partials/footer.php';
    exit;
}

<<<<<<< HEAD
/**
 * ===========================
 * LISTAGEM
 * ===========================
 */
=======
// =========================
// Listagem
// =========================
>>>>>>> f3837e5 (Fix timezone display (dashboard))
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = 'company_id = ?';
$params = [$companyId];

<<<<<<< HEAD
if ($search !== '') {
=======
if ($search) {
    $where .= ' AND (nome LIKE ? OR telefone_principal LIKE ? OR whatsapp LIKE ? OR instagram_username LIKE ? OR tags LIKE ?)';
>>>>>>> f3837e5 (Fix timezone display (dashboard))
    $like = '%' . $search . '%';
    $where .= ' AND (nome LIKE ? OR telefone_principal LIKE ? OR whatsapp LIKE ? OR instagram_username LIKE ? OR tags LIKE ?)';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM clients WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$clients = $stmt->fetchAll();
?>
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-2xl font-semibold">Clientes</h1>
        <p class="text-sm text-slate-600">CRM básico com busca e timeline.</p>
    </div>
    <a href="<?= sanitize(clients_url('action=create')) ?>" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Novo Cliente</a>
</div>

<<<<<<< HEAD
<form class="mb-4" method="GET">
=======
<form class="mb-4">
>>>>>>> f3837e5 (Fix timezone display (dashboard))
    <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Buscar por nome, telefone, Instagram ou tags" class="w-full md:w-96 rounded border-slate-300">
</form>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-700">
            <tr>
                <th class="px-4 py-2 text-left">Nome</th>
                <th class="px-4 py-2 text-left">WhatsApp</th>
                <th class="px-4 py-2 text-left">Instagram</th>
                <th class="px-4 py-2 text-left">Tags</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
<<<<<<< HEAD
                <td class="px-4 py-2 font-medium"><?= sanitize((string)$c['nome']) ?></td>
                <td class="px-4 py-2"><?= sanitize((string)($c['whatsapp'] ?? '')) ?></td>
                <td class="px-4 py-2"><?= sanitize((string)($c['instagram_username'] ?? '')) ?></td>
                <td class="px-4 py-2 text-slate-600"><?= sanitize((string)($c['tags'] ?? '')) ?></td>
                <td class="px-4 py-2 text-right space-x-2">
                    <a class="text-indigo-600 hover:underline" href="<?= sanitize(clients_url('action=view&id=' . (int)$c['id'])) ?>">Ver</a>
                    <a class="text-slate-600 hover:underline" href="<?= sanitize(clients_url('action=edit&id=' . (int)$c['id'])) ?>">Editar</a>
                    <a class="text-red-600 hover:underline" href="<?= sanitize(clients_url('action=delete&id=' . (int)$c['id'])) ?>" onclick="return confirm('Excluir cliente?')">Excluir</a>
=======
                <td class="px-4 py-2 font-medium"><?= sanitize((string)$client['nome']) ?></td>
                <td class="px-4 py-2"><?= sanitize((string)$client['whatsapp']) ?></td>
                <td class="px-4 py-2"><?= sanitize((string)$client['instagram_username']) ?></td>
                <td class="px-4 py-2 text-slate-600"><?= sanitize((string)$client['tags']) ?></td>
                <td class="px-4 py-2 text-right space-x-2">
                    <a class="text-indigo-600 hover:underline" href="/clients.php?action=view&id=<?= (int)$client['id'] ?>">Ver</a>
                    <a class="text-slate-600 hover:underline" href="/clients.php?action=edit&id=<?= (int)$client['id'] ?>">Editar</a>
                    <a class="text-red-600 hover:underline" href="/clients.php?action=delete&id=<?= (int)$client['id'] ?>" onclick="return confirm('Excluir cliente?')">Excluir</a>
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if (empty($clients)): ?>
            <tr>
                <td class="px-4 py-3 text-center text-slate-500" colspan="5">Nenhum cliente encontrado.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$totalPages = max(1, (int)ceil($total / $perPage));
if ($totalPages > 1):
?>
<<<<<<< HEAD
    <div class="mt-4 flex gap-2 flex-wrap">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="px-3 py-1 rounded border <?= $i === $page ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200' ?>"
               href="<?= sanitize(clients_url('page=' . $i . '&q=' . urlencode($search))) ?>">
               <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
=======
<div class="mt-4 flex gap-2">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a class="px-3 py-1 rounded border <?= $i === $page ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200' ?>"
           href="?page=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
>>>>>>> f3837e5 (Fix timezone display (dashboard))
<?php endif; ?>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
