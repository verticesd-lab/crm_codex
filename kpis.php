<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

$period = $_GET['period'] ?? 'mes';
$dateFilter = '';
if ($period === 'hoje') {
    $dateFilter = 'AND DATE(o.created_at) = CURDATE()';
} elseif ($period === '7d') {
    $dateFilter = 'AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} else {
    $dateFilter = 'AND DATE_FORMAT(o.created_at,"%Y-%m")=DATE_FORMAT(NOW(),"%Y-%m")';
}

$ordersByOrigem = $pdo->prepare("SELECT origem, COUNT(*) as total, SUM(total) as soma FROM orders o WHERE o.company_id=? $dateFilter GROUP BY origem");
$ordersByOrigem->execute([$companyId]);
$ordersByOrigem = $ordersByOrigem->fetchAll();

$tags = $pdo->prepare("SELECT tags FROM clients WHERE company_id=? AND tags IS NOT NULL AND tags <> ''");
$tags->execute([$companyId]);
$tagCounts = [];
foreach ($tags->fetchAll() as $row) {
    $parts = array_map('trim', explode(',', $row['tags']));
    foreach ($parts as $tag) {
        if ($tag) {
            $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
        }
    }
}
arsort($tagCounts);

$topProducts = $pdo->prepare("SELECT p.nome, SUM(oi.quantidade) as qtd, SUM(oi.subtotal) as valor FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id WHERE o.company_id=? $dateFilter GROUP BY p.id ORDER BY valor DESC LIMIT 5");
$topProducts->execute([$companyId]);
$topProducts = $topProducts->fetchAll();

$recentLogs = $pdo->prepare("SELECT l.*, u.nome FROM action_logs l JOIN users u ON u.id=l.user_id WHERE l.company_id=? ORDER BY l.created_at DESC LIMIT 10");
$recentLogs->execute([$companyId]);
$recentLogs = $recentLogs->fetchAll();

include __DIR__ . '/views/partials/header.php';
?>
<div class="flex items-center justify-between mb-3">
    <h1 class="text-2xl font-semibold">KPIs</h1>
    <form class="flex gap-2 items-center">
        <input type="hidden" name="period" value="<?= sanitize($period) ?>">
        <select name="period" onchange="this.form.submit()" class="rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700 text-sm">
            <option value="mes" <?= $period === 'mes' ? 'selected' : '' ?>>Mês atual</option>
            <option value="hoje" <?= $period === 'hoje' ? 'selected' : '' ?>>Hoje</option>
            <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>Últimos 7 dias</option>
        </select>
    </form>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">KPIs por origem</h1>
            <span class="text-xs text-slate-500">Mês atual</span>
        </div>
        <div class="mt-3 space-y-2 text-sm">
            <?php if ($ordersByOrigem): foreach ($ordersByOrigem as $row): ?>
                <div class="flex items-center justify-between">
                    <span class="font-medium"><?= sanitize($row['origem']) ?></span>
                    <span><?= (int)$row['total'] ?> • <?= format_currency($row['soma']) ?></span>
                </div>
            <?php endforeach; else: ?>
                <p class="text-slate-500 text-sm">Sem dados.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold">Tags recorrentes</h2>
            <span class="text-xs text-slate-500">Clientes</span>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <?php if ($tagCounts): foreach (array_slice($tagCounts,0,10) as $tag => $count): ?>
                <span class="px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-200"><?= sanitize($tag) ?> (<?= $count ?>)</span>
            <?php endforeach; else: ?>
                <p class="text-slate-500 text-sm">Sem tags.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold">Top produtos/serviços</h2>
            <span class="text-xs text-slate-500">Mês atual</span>
        </div>
        <div class="mt-3 space-y-2 text-sm">
            <?php if ($topProducts): foreach ($topProducts as $row): ?>
                <div class="flex items-center justify-between">
                    <span class="font-medium"><?= sanitize($row['nome']) ?></span>
                    <span><?= (int)$row['qtd'] ?> • <?= format_currency($row['valor']) ?></span>
                </div>
            <?php endforeach; else: ?>
                <p class="text-slate-500 text-sm">Sem vendas no mês.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold">Log de ações</h2>
            <span class="text-xs text-slate-500">10 mais recentes</span>
        </div>
        <div class="mt-3 space-y-2 text-sm">
            <?php if ($recentLogs): foreach ($recentLogs as $log): ?>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium"><?= sanitize($log['action']) ?></p>
                        <p class="text-slate-500 text-xs"><?= sanitize($log['nome']) ?> • <?= date('d/m H:i', strtotime($log['created_at'])) ?></p>
                    </div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs"><?= sanitize(mb_strimwidth($log['details'],0,40,'...')) ?></p>
                </div>
            <?php endforeach; else: ?>
                <p class="text-slate-500 text-sm">Sem eventos.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
