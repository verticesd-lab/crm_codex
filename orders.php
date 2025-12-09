<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'view') && $id) {
    $status = $_POST['status'] ?? 'novo';
    $notes = trim($_POST['notes'] ?? '');
    $pdo->prepare('UPDATE orders SET status=?, notes_internas=?, updated_at=NOW() WHERE id=? AND company_id=?')->execute([$status, $notes, $id, $companyId]);
    log_action($pdo, $companyId, $_SESSION['user_id'], 'atualizar_pedido', 'Pedido #' . $id . ' status ' . $status);
    flash('success', 'Pedido atualizado.');
    redirect('/orders.php?action=view&id=' . $id);
}

include __DIR__ . '/views/partials/header.php';
if ($msg = get_flash('success')) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($msg) . '</div>';

if ($action === 'view' && $id) {
    $stmt = $pdo->prepare('SELECT o.*, c.nome AS cliente_nome, c.telefone_principal, c.whatsapp FROM orders o LEFT JOIN clients c ON c.id=o.client_id WHERE o.id=? AND o.company_id=?');
    $stmt->execute([$id, $companyId]);
    $order = $stmt->fetch();
    if (!$order) {
        echo '<p>Pedido não encontrado.</p>';
        include __DIR__ . '/views/partials/footer.php';
        exit;
    }
    $itemsStmt = $pdo->prepare('SELECT oi.*, p.nome FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll();
    ?>
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-sm text-slate-500">Pedido #<?= $order['id'] ?> • <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
                <h1 class="text-2xl font-semibold">Total <?= format_currency($order['total']) ?></h1>
                <p class="text-sm text-slate-600 mt-1">Origem: <?= sanitize($order['origem']) ?></p>
            </div>
            <a href="/orders.php" class="px-3 py-2 rounded border border-slate-300 text-slate-700">Voltar</a>
        </div>
        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="p-3 rounded border border-slate-100 bg-slate-50">
                <p class="text-sm text-slate-600">Status</p>
                <p class="text-lg font-semibold"><?= sanitize($order['status']) ?></p>
            </div>
            <div class="p-3 rounded border border-slate-100 bg-slate-50">
                <p class="text-sm text-slate-600">Cliente</p>
                <p class="text-lg font-semibold"><?= sanitize($order['cliente_nome'] ?? 'Não informado') ?></p>
            </div>
            <div class="p-3 rounded border border-slate-100 bg-slate-50">
                <p class="text-sm text-slate-600">Contato</p>
                <p class="text-lg font-semibold"><?= sanitize($order['whatsapp'] ?? $order['telefone_principal'] ?? '-') ?></p>
            </div>
        </div>
        <h3 class="text-lg font-semibold mt-6 mb-3">Itens</h3>
        <div class="border border-slate-200 rounded-lg overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-slate-700">
                <tr>
                    <th class="px-4 py-2 text-left">Produto</th>
                    <th class="px-4 py-2 text-left">Qtd</th>
                    <th class="px-4 py-2 text-left">Preço</th>
                    <th class="px-4 py-2 text-left">Subtotal</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-2"><?= sanitize($item['nome']) ?></td>
                        <td class="px-4 py-2"><?= $item['quantidade'] ?></td>
                        <td class="px-4 py-2"><?= format_currency($item['preco_unitario']) ?></td>
                        <td class="px-4 py-2"><?= format_currency($item['subtotal']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Status</label>
                <select name="status" class="w-full rounded border-slate-300">
                    <?php foreach (['novo','em_andamento','concluido','cancelado'] as $status): ?>
                        <option value="<?= $status ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= $status ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Notas internas</label>
                <textarea name="notes" class="w-full rounded border-slate-300" rows="3"><?= sanitize($order['notes_internas']) ?></textarea>
            </div>
            <div class="md:col-span-2">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Salvar alterações</button>
            </div>
        </form>
    </div>
    <?php
    include __DIR__ . '/views/partials/footer.php';
    exit;
}

$status = $_GET['status'] ?? '';
$periodo = $_GET['periodo'] ?? '';
$where = 'o.company_id = ?';
$params = [$companyId];
if ($status) { $where .= ' AND o.status = ?'; $params[] = $status; }
if ($periodo === 'mes') { $where .= ' AND DATE_FORMAT(o.created_at,"%Y-%m") = DATE_FORMAT(NOW(),"%Y-%m")'; }
if ($periodo === 'hoje') { $where .= ' AND DATE(o.created_at) = CURDATE()'; }
if ($periodo === '7d') { $where .= ' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'; }

$stmt = $pdo->prepare("SELECT o.*, c.nome AS cliente_nome FROM orders o LEFT JOIN clients c ON c.id=o.client_id WHERE $where ORDER BY o.created_at DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-2xl font-semibold">Pedidos</h1>
        <p class="text-sm text-slate-600">Pedidos vindos da loja/LP.</p>
    </div>
</div>
<form class="flex flex-wrap gap-3 mb-4">
    <select name="status" class="rounded border-slate-300">
        <option value="">Status</option>
        <?php foreach (['novo','em_andamento','concluido','cancelado'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
    <select name="periodo" class="rounded border-slate-300">
        <option value="">Período</option>
        <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>Mês atual</option>
        <option value="hoje" <?= $periodo === 'hoje' ? 'selected' : '' ?>>Hoje</option>
        <option value="7d" <?= $periodo === '7d' ? 'selected' : '' ?>>Últimos 7 dias</option>
    </select>
    <button class="bg-slate-900 text-white rounded px-4">Filtrar</button>
</form>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-700">
        <tr>
            <th class="px-4 py-2 text-left">ID</th>
            <th class="px-4 py-2 text-left">Cliente</th>
            <th class="px-4 py-2 text-left">Total</th>
            <th class="px-4 py-2 text-left">Status</th>
            <th class="px-4 py-2 text-left">Data</th>
            <th class="px-4 py-2"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
                <td class="px-4 py-2 font-medium">#<?= $order['id'] ?></td>
                <td class="px-4 py-2"><?= sanitize($order['cliente_nome'] ?? 'Não identificado') ?></td>
                <td class="px-4 py-2"><?= format_currency($order['total']) ?></td>
                <td class="px-4 py-2"><?= sanitize($order['status']) ?></td>
                <td class="px-4 py-2"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></td>
                <td class="px-4 py-2 text-right">
                    <a class="text-indigo-600 hover:underline" href="/orders.php?action=view&id=<?= $order['id'] ?>">Detalhes</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?>
            <tr><td colspan="6" class="px-4 py-3 text-center text-slate-500">Nenhum pedido.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
