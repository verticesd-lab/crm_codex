<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

// KPIs
$clientsCount = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE company_id = ?');
$clientsCount->execute([$companyId]);
$clientsCount = $clientsCount->fetchColumn();

$productsCount = $pdo->prepare('SELECT COUNT(*) FROM products WHERE company_id = ? AND ativo = 1');
$productsCount->execute([$companyId]);
$productsCount = $productsCount->fetchColumn();

$interactionsCount = $pdo->prepare('SELECT COUNT(*) FROM interactions WHERE company_id = ? AND DATE_FORMAT(created_at, "%Y-%m") = DATE_FORMAT(NOW(), "%Y-%m")');
$interactionsCount->execute([$companyId]);
$interactionsCount = $interactionsCount->fetchColumn();

$ordersCount = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE company_id = ? AND DATE_FORMAT(created_at, "%Y-%m") = DATE_FORMAT(NOW(), "%Y-%m")');
$ordersCount->execute([$companyId]);
$ordersCount = $ordersCount->fetchColumn();

$ordersPDV = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE company_id=? AND origem='pdv' AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')");
$ordersPDV->execute([$companyId]);
$ordersPDV = $ordersPDV->fetchColumn();

$latestInteractions = $pdo->prepare('SELECT i.*, c.nome AS cliente_nome FROM interactions i JOIN clients c ON c.id = i.client_id WHERE i.company_id = ? ORDER BY i.created_at DESC LIMIT 6');
$latestInteractions->execute([$companyId]);
$latestInteractions = $latestInteractions->fetchAll();

$latestOrders = $pdo->prepare('SELECT o.*, c.nome AS cliente_nome FROM orders o LEFT JOIN clients c ON c.id = o.client_id WHERE o.company_id = ? ORDER BY o.created_at DESC LIMIT 6');
$latestOrders->execute([$companyId]);
$latestOrders = $latestOrders->fetchAll();

include __DIR__ . '/views/partials/header.php';
?>
<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Clientes</p>
        <p class="text-3xl font-semibold"><?= $clientsCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Produtos/Serviços ativos</p>
        <p class="text-3xl font-semibold"><?= $productsCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Atendimentos no mês</p>
        <p class="text-3xl font-semibold"><?= $interactionsCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Pedidos no mês</p>
        <p class="text-3xl font-semibold"><?= $ordersCount ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-slate-500 text-sm">Vendas PDV no mês</p>
        <p class="text-3xl font-semibold"><?= $ordersPDV ?></p>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
    <a href="/clients.php?action=create" class="bg-indigo-600 text-white rounded-xl p-4 shadow hover:bg-indigo-700 transition flex items-center justify-between">
        <div><p class="text-lg font-semibold">Novo Cliente</p><p class="text-sm text-indigo-100">Cadastre um novo contato</p></div><span>→</span>
    </a>
    <a href="/products.php?action=create" class="bg-sky-600 text-white rounded-xl p-4 shadow hover:bg-sky-700 transition flex items-center justify-between">
        <div><p class="text-lg font-semibold">Novo Produto</p><p class="text-sm text-sky-100">Produtos ou serviços</p></div><span>→</span>
    </a>
    <a href="/promotions.php?action=create" class="bg-emerald-600 text-white rounded-xl p-4 shadow hover:bg-emerald-700 transition flex items-center justify-between">
        <div><p class="text-lg font-semibold">Criar Promoção</p><p class="text-sm text-emerald-100">Landing page rápida</p></div><span>→</span>
    </a>
</div>

<div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="p-4 border-b border-slate-100 flex justify-between items-center">
            <div>
                <p class="text-sm text-slate-500">Últimos atendimentos</p>
                <h3 class="text-lg font-semibold">Timeline</h3>
            </div>
            <a class="text-sm text-indigo-600 hover:underline" href="/clients.php">Ver clientes</a>
        </div>
        <div class="p-4 space-y-3">
            <?php foreach ($latestInteractions as $interaction): ?>
                <div class="p-3 rounded-lg border border-slate-100 bg-slate-50">
                    <div class="flex justify-between text-sm text-slate-600">
                        <span><?= sanitize($interaction['cliente_nome']) ?></span>
                        <span><?= date('d/m H:i', strtotime($interaction['created_at'])) ?></span>
                    </div>
                    <p class="font-medium"><?= sanitize($interaction['titulo']) ?></p>
                    <p class="text-sm text-slate-600"><?= sanitize($interaction['resumo']) ?></p>
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
            <a class="text-sm text-indigo-600 hover:underline" href="/orders.php">Ver pedidos</a>
        </div>
        <div class="p-4 space-y-3">
            <?php foreach ($latestOrders as $order): ?>
                <div class="p-3 rounded-lg border border-slate-100 bg-slate-50">
                    <div class="flex justify-between text-sm text-slate-600">
                        <span><?= sanitize($order['cliente_nome'] ?? 'Cliente não identificado') ?></span>
                        <span><?= date('d/m H:i', strtotime($order['created_at'])) ?></span>
                    </div>
                    <p class="font-medium">Origem: <?= sanitize($order['origem']) ?> • Status: <?= sanitize($order['status']) ?></p>
                    <p class="text-sm text-slate-600">Total <?= format_currency($order['total']) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if (empty($latestOrders)): ?>
                <p class="text-sm text-slate-500">Nenhum pedido registrado.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
