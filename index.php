<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

$pdo = get_pdo();

// garante que o MySQL trabalhe em UTC (DB padrão)
if (function_exists('pdo_apply_timezone')) {
    pdo_apply_timezone($pdo, '+00:00');
}

$companyId = current_company_id();
if (!$companyId) {
    flash('error', 'Empresa não definida na sessão.');
    redirect('dashboard.php');
}

/**
 * Formata datetime vindo do banco (UTC) para o fuso do app (Cuiabá)
 */
function safe_dt(?string $dt): string {
    if (!$dt) return '';
    if (function_exists('format_datetime_br')) {
        return format_datetime_br($dt); // assume UTC -> app tz
    }
    // fallback simples
    return date('d/m H:i', strtotime($dt));
}

// =========================
// KPIs
// =========================
$clientsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE company_id = ?');
$clientsCountStmt->execute([$companyId]);
$clientsCount = (int)$clientsCountStmt->fetchColumn();

$productsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE company_id = ? AND ativo = 1');
$productsCountStmt->execute([$companyId]);
$productsCount = (int)$productsCountStmt->fetchColumn();

// "no mês" usando UTC_TIMESTAMP() (evita +4h/-4h)
$interactionsCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM interactions
    WHERE company_id = ?
      AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m')
");
$interactionsCountStmt->execute([$companyId]);
$interactionsCount = (int)$interactionsCountStmt->fetchColumn();

$ordersCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders
    WHERE company_id = ?
      AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m')
");
$ordersCountStmt->execute([$companyId]);
$ordersCount = (int)$ordersCountStmt->fetchColumn();

$ordersPDVStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders
    WHERE company_id = ?
      AND origem = 'pdv'
      AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m')
");
$ordersPDVStmt->execute([$companyId]);
$ordersPDV = (int)$ordersPDVStmt->fetchColumn();

// =========================
// Listas do dashboard
// =========================
$latestInteractionsStmt = $pdo->prepare("
    SELECT i.*, c.nome AS cliente_nome
    FROM interactions i
    JOIN clients c ON c.id = i.client_id
    WHERE i.company_id = ?
    ORDER BY i.created_at DESC
    LIMIT 6
");
$latestInteractionsStmt->execute([$companyId]);
$latestInteractions = $latestInteractionsStmt->fetchAll();

$latestOrdersStmt = $pdo->prepare("
    SELECT o.*, c.nome AS cliente_nome
    FROM orders o
    LEFT JOIN clients c ON c.id = o.client_id
    WHERE o.company_id = ?
    ORDER BY o.created_at DESC
    LIMIT 6
");
$latestOrdersStmt->execute([$companyId]);
$latestOrders = $latestOrdersStmt->fetchAll();

include __DIR__ . '/views/partials/header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Clientes</p>
        <p class="text-3xl font-semibold"><?= (int)$clientsCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Produtos/Serviços ativos</p>
        <p class="text-3xl font-semibold"><?= (int)$productsCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Atendimentos no mês</p>
        <p class="text-3xl font-semibold"><?= (int)$interactionsCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Pedidos no mês</p>
        <p class="text-3xl font-semibold"><?= (int)$ordersCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Vendas PDV no mês</p>
        <p class="text-3xl font-semibold"><?= (int)$ordersPDV ?></p>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
    <a href="<?= BASE_URL ?>/clients.php?action=create" class="bg-indigo-600 text-white rounded-xl p-4 shadow hover:bg-indigo-700 transition flex items-center justify-between">
        <div>
            <p class="text-lg font-semibold">Novo Cliente</p>
            <p class="text-sm text-indigo-100">Cadastre um novo contato</p>
        </div>
        <span>→</span>
    </a>
    <a href="<?= BASE_URL ?>/products.php?action=create" class="bg-sky-600 text-white rounded-xl p-4 shadow hover:bg-sky-700 transition flex items-center justify-between">
        <div>
            <p class="text-lg font-semibold">Novo Produto</p>
            <p class="text-sm text-sky-100">Produtos ou serviços</p>
        </div>
        <span>→</span>
    </a>
    <a href="<?= BASE_URL ?>/promotions.php?action=create" class="bg-emerald-600 text-white rounded-xl p-4 shadow hover:bg-emerald-700 transition flex items-center justify-between">
        <div>
            <p class="text-lg font-semibold">Criar Promoção</p>
            <p class="text-sm text-emerald-100">Landing page rápida</p>
        </div>
        <span>→</span>
    </a>
</div>

<div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="p-4 border-b border-slate-100 flex justify-between items-center">
            <div>
                <p class="text-sm text-slate-500">Últimos atendimentos</p>
                <h3 class="text-lg font-semibold">Timeline</h3>
            </div>
            <a class="text-sm text-indigo-600 hover:underline" href="<?= BASE_URL ?>/clients.php">Ver clientes</a>
        </div>
        <div class="p-4 space-y-3">
            <?php foreach ($latestInteractions as $interaction): ?>
                <div class="p-3 rounded-lg border border-slate-100 bg-slate-50">
                    <div class="flex justify-between text-sm text-slate-600">
                        <span><?= sanitize((string)($interaction['cliente_nome'] ?? '')) ?></span>
                        <span><?= sanitize(safe_dt($interaction['created_at'] ?? null)) ?></span>
                    </div>
                    <p class="font-medium"><?= sanitize((string)($interaction['titulo'] ?? '')) ?></p>
                    <p class="text-sm text-slate-600"><?= sanitize((string)($interaction['resumo'] ?? '')) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if (empty($latestInteractions)): ?>
                <p class="text-sm text-slate-500">Nenhum atendimento registrado.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="p-4 border-b border-slate-100 flex justify-between items-center">
            <div>
                <p class="text-sm text-slate-500">Últimos pedidos</p>
                <h3 class="text-lg font-semibold">Pedidos</h3>
            </div>
            <a class="text-sm text-indigo-600 hover:underline" href="<?= BASE_URL ?>/orders.php">Ver pedidos</a>
        </div>
        <div class="p-4 space-y-3">
            <?php foreach ($latestOrders as $order): ?>
                <div class="p-3 rounded-lg border border-slate-100 bg-slate-50">
                    <div class="flex justify-between text-sm text-slate-600">
                        <span><?= sanitize((string)($order['cliente_nome'] ?? 'Cliente não identificado')) ?></span>
                        <span><?= sanitize(safe_dt($order['created_at'] ?? null)) ?></span>
                    </div>
                    <p class="font-medium">Origem: <?= sanitize((string)($order['origem'] ?? '')) ?> • Status: <?= sanitize((string)($order['status'] ?? '')) ?></p>
                    <p class="text-sm text-slate-600">Total <?= format_currency($order['total'] ?? 0) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if (empty($latestOrders)): ?>
                <p class="text-sm text-slate-500">Nenhum pedido registrado.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
