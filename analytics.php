<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

$period = $_GET['period'] ?? '7d'; // hoje | 7d | mes | ano

$where = 'company_id = ?';
$params = [$companyId];

if ($period === 'hoje') {
    $where .= ' AND DATE(created_at) = CURDATE()';
} elseif ($period === '7d') {
    $where .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($period === 'ano') {
    $where .= ' AND YEAR(created_at) = YEAR(NOW())';
} else { // mes
    $where .= ' AND DATE_FORMAT(created_at,"%Y-%m") = DATE_FORMAT(NOW(),"%Y-%m")';
}

/** Cards principais */
$st = $pdo->prepare("SELECT COUNT(*) FROM site_visits WHERE $where");
$st->execute($params);
$pageviews = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM site_visits WHERE $where");
$st->execute($params);
$uniqueVisitors = (int)$st->fetchColumn();

/** Gráfico por dia (últimos 30 dias do filtro) */
$daily = [];
$st = $pdo->prepare("
    SELECT DATE(created_at) as d,
           COUNT(*) as views,
           COUNT(DISTINCT visitor_hash) as uniques
    FROM site_visits
    WHERE $where
    GROUP BY DATE(created_at)
    ORDER BY d DESC
    LIMIT 30
");
$st->execute($params);
$daily = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);

/** Top páginas */
$st = $pdo->prepare("
    SELECT page, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as uniques
    FROM site_visits
    WHERE $where
    GROUP BY page
    ORDER BY views DESC
    LIMIT 10
");
$st->execute($params);
$topPages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/** Origens */
$st = $pdo->prepare("
    SELECT COALESCE(origin,'(sem origem)') as origin, COUNT(*) as views
    FROM site_visits
    WHERE $where
    GROUP BY origin
    ORDER BY views DESC
    LIMIT 10
");
$st->execute($params);
$origins = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/views/partials/header.php';
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-semibold">Analytics do site</h1>

    <form class="flex items-center gap-2">
        <select name="period" onchange="this.form.submit()"
                class="rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700 text-sm">
            <option value="hoje" <?= $period==='hoje'?'selected':'' ?>>Hoje</option>
            <option value="7d" <?= $period==='7d'?'selected':'' ?>>Últimos 7 dias</option>
            <option value="mes" <?= $period==='mes'?'selected':'' ?>>Mês atual</option>
            <option value="ano" <?= $period==='ano'?'selected':'' ?>>Ano atual</option>
        </select>
    </form>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <p class="text-sm text-slate-500">Visitantes únicos</p>
        <p class="text-3xl font-bold"><?= (int)$uniqueVisitors ?></p>
    </div>

    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <p class="text-sm text-slate-500">Visualizações (pageviews)</p>
        <p class="text-3xl font-bold"><?= (int)$pageviews ?></p>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <h2 class="text-lg font-semibold mb-3">Top páginas</h2>
        <?php if (!$topPages): ?>
            <p class="text-slate-500 text-sm">Sem dados ainda.</p>
        <?php else: ?>
            <div class="space-y-2 text-sm">
                <?php foreach ($topPages as $r): ?>
                    <div class="flex items-center justify-between gap-4">
                        <span class="truncate max-w-[70%]"><?= sanitize($r['page']) ?></span>
                        <span class="text-right whitespace-nowrap">
                            <?= (int)$r['views'] ?> views • <?= (int)$r['uniques'] ?> únicos
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
        <h2 class="text-lg font-semibold mb-3">Origens</h2>
        <?php if (!$origins): ?>
            <p class="text-slate-500 text-sm">Sem dados ainda.</p>
        <?php else: ?>
            <div class="space-y-2 text-sm">
                <?php foreach ($origins as $r): ?>
                    <div class="flex items-center justify-between">
                        <span class="font-medium"><?= sanitize($r['origin']) ?></span>
                        <span><?= (int)$r['views'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mt-6 bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-4 shadow-sm">
    <h2 class="text-lg font-semibold mb-3">Evolução (até 30 dias)</h2>
    <?php if (!$daily): ?>
        <p class="text-slate-500 text-sm">Sem dados ainda.</p>
    <?php else: ?>
        <div class="space-y-2 text-sm">
            <?php foreach ($daily as $r): ?>
                <div class="flex items-center justify-between">
                    <span><?= date('d/m/Y', strtotime($r['d'])) ?></span>
                    <span><?= (int)$r['views'] ?> views • <?= (int)$r['uniques'] ?> únicos</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
