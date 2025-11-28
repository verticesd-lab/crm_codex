<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
$missingTables = false;
$missingMessage = '';

// helpers to load dropdowns
$clients = $pdo->prepare('SELECT id, nome FROM clients WHERE company_id = ? ORDER BY nome');
$clients->execute([$companyId]);
$clients = $clients->fetchAll();

$users = $pdo->prepare('SELECT id, nome FROM users WHERE company_id = ? ORDER BY nome');
$users->execute([$companyId]);
$users = $users->fetchAll();

$pipelines = $stages = $stagesByPipeline = [];
try {
    $pipelinesStmt = $pdo->prepare('SELECT id, nome FROM pipelines WHERE company_id = ? AND ativo = 1 ORDER BY nome');
    $pipelinesStmt->execute([$companyId]);
    $pipelines = $pipelinesStmt->fetchAll();

    $stagesStmt = $pdo->prepare('SELECT ps.*, p.nome as pipeline_nome FROM pipeline_stages ps JOIN pipelines p ON p.id = ps.pipeline_id WHERE p.company_id = ? ORDER BY p.nome, ps.ordem');
    $stagesStmt->execute([$companyId]);
    $stages = $stagesStmt->fetchAll();
    foreach ($stages as $st) {
        $stagesByPipeline[$st['pipeline_id']][] = $st;
    }
} catch (Throwable $e) {
    $missingTables = true;
    $missingMessage = 'Tabelas de funil não existem. Importe o arquivo database_funnel_agenda.sql no seu banco (pipelines, pipeline_stages, opportunities).';
}

$flashError = $flashSuccess = null;

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $valor = (float)($_POST['valor_potencial'] ?? 0);
        $fonte = trim($_POST['fonte'] ?? '');
        $prob = (int)($_POST['probabilidade'] ?? 0);
        $resp = (int)($_POST['responsavel_user_id'] ?? 0);
        $obs = trim($_POST['observacoes'] ?? '');

        if (!$pipelineId || !$stageId || !$clientId || $titulo === '') {
            $flashError = 'Informe pipeline, etapa, cliente e título.';
        } else {
            $pdo->prepare('INSERT INTO opportunities (company_id, pipeline_id, stage_id, client_id, titulo, valor_potencial, fonte, probabilidade, responsavel_user_id, observacoes, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "aberta", NOW(), NOW())')
                ->execute([$companyId, $pipelineId, $stageId, $clientId, $titulo, $valor, $fonte, $prob ?: null, $resp ?: null, $obs]);
            $flashSuccess = 'Oportunidade criada.';
        }
    }
    if ($action === 'update') {
        $oppId = (int)($_POST['id'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'aberta');
        if ($oppId && $stageId) {
            $closedAt = ($status === 'ganha' || $status === 'perdida') ? 'NOW()' : 'NULL';
            $sql = 'UPDATE opportunities SET stage_id=?, status=?, closed_at=' . $closedAt . ', updated_at=NOW() WHERE id=? AND company_id=?';
            $pdo->prepare($sql)->execute([$stageId, $status, $oppId, $companyId]);
            $flashSuccess = 'Oportunidade atualizada.';
        }
    }
}

// filtros
$filterPipeline = (int)($_GET['pipeline_id'] ?? 0);
$filterStage = (int)($_GET['stage_id'] ?? 0);
$filterResp = (int)($_GET['responsavel_user_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

$opps = [];
$pipelineSummary = [];
if (!$missingTables) {
    $where = 'o.company_id = ?';
    $params = [$companyId];
    if ($filterPipeline) { $where .= ' AND o.pipeline_id = ?'; $params[] = $filterPipeline; }
    if ($filterStage) { $where .= ' AND o.stage_id = ?'; $params[] = $filterStage; }
    if ($filterResp) { $where .= ' AND o.responsavel_user_id = ?'; $params[] = $filterResp; }
    if ($filterStatus !== '') { $where .= ' AND o.status = ?'; $params[] = $filterStatus; }

    $oppStmt = $pdo->prepare("SELECT o.*, c.nome as client_nome, p.nome as pipeline_nome, s.nome as stage_nome, u.nome as responsavel_nome
        FROM opportunities o
        JOIN clients c ON c.id = o.client_id
        JOIN pipelines p ON p.id = o.pipeline_id
        JOIN pipeline_stages s ON s.id = o.stage_id
        LEFT JOIN users u ON u.id = o.responsavel_user_id
        WHERE $where
        ORDER BY o.created_at DESC");
    $oppStmt->execute($params);
    $opps = $oppStmt->fetchAll();

    foreach ($pipelines as $pl) {
        $pipelineSummary[$pl['id']] = [];
        foreach ($stagesByPipeline[$pl['id']] ?? [] as $st) {
            $pipelineSummary[$pl['id']][$st['id']] = ['stage' => $st, 'count' => 0, 'total' => 0];
        }
    }
    foreach ($opps as $op) {
        if ($op['status'] !== 'aberta') continue;
        if (isset($pipelineSummary[$op['pipeline_id']][$op['stage_id']])) {
            $pipelineSummary[$op['pipeline_id']][$op['stage_id']]['count'] += 1;
            $pipelineSummary[$op['pipeline_id']][$op['stage_id']]['total'] += (float)$op['valor_potencial'];
        }
    }
}

include __DIR__ . '/views/partials/header.php';
if ($flashSuccess) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($flashSuccess) . '</div>';
if ($flashError) echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($flashError) . '</div>';
?>
<div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">Funil / Oportunidades</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">Organize seus negócios por pipeline e etapa.</p>
        </div>
        <div class="flex gap-2">
            <a href="/pipelines.php" class="px-3 py-2 rounded border border-slate-200 dark:border-slate-700 text-sm">Gerenciar Pipelines</a>
        </div>
    </div>

    <?php if ($missingTables): ?>
        <div class="p-4 rounded border border-amber-300 bg-amber-50 text-amber-800">
            <?= sanitize($missingMessage) ?>
        </div>
    <?php else: ?>

    <form class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-4">
        <input type="hidden" name="page" value="opportunities">
        <div>
            <label class="text-xs text-slate-500">Pipeline</label>
            <select name="pipeline_id" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                <option value="">Todos</option>
                <?php foreach ($pipelines as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $filterPipeline === (int)$p['id'] ? 'selected' : '' ?>><?= sanitize($p['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-500">Etapa</label>
            <select name="stage_id" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                <option value="">Todas</option>
                <?php foreach ($stages as $st): ?>
                    <option value="<?= $st['id'] ?>" <?= $filterStage === (int)$st['id'] ? 'selected' : '' ?>><?= sanitize($st['nome']) ?> (<?= sanitize($st['pipeline_nome']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-500">Responsável</label>
            <select name="responsavel_user_id" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                <option value="">Todos</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterResp === (int)$u['id'] ? 'selected' : '' ?>><?= sanitize($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-500">Status</label>
            <select name="status" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                <option value="">Todos</option>
                <?php foreach (['aberta','ganha','perdida'] as $st): ?>
                    <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end">
            <button class="px-4 py-2 rounded bg-indigo-600 text-white w-full">Filtrar</button>
        </div>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-4">
            <h3 class="font-semibold mb-2">Nova Oportunidade</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="create">
                <div>
                    <label class="text-xs text-slate-500">Pipeline</label>
                    <select name="pipeline_id" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                        <option value="">Selecione</option>
                        <?php foreach ($pipelines as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Etapa</label>
                    <select name="stage_id" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                        <?php foreach ($stages as $st): ?>
                            <option value="<?= $st['id'] ?>"><?= sanitize($st['nome']) ?> (<?= sanitize($st['pipeline_nome']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Cliente</label>
                    <select name="client_id" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                        <option value="">Selecione</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Título</label>
                    <input name="titulo" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-slate-500">Valor potencial</label>
                        <input name="valor_potencial" type="number" step="0.01" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Probabilidade (%)</label>
                        <input name="probabilidade" type="number" min="0" max="100" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                    </div>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Fonte</label>
                    <select name="fonte" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                        <?php foreach (['whatsapp','instagram','loja','indicação','outro'] as $f): ?>
                            <option value="<?= $f ?>"><?= ucfirst($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Responsável</label>
                    <select name="responsavel_user_id" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                        <option value="">Nenhum</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= sanitize($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Observações</label>
                    <textarea name="observacoes" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" rows="2"></textarea>
                </div>
                <button class="w-full bg-indigo-600 text-white rounded py-2 hover:bg-indigo-700">Salvar oportunidade</button>
            </form>
        </div>

        <div class="lg:col-span-2 space-y-4">
            <div class="overflow-hidden border border-slate-200 dark:border-slate-700 rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                        <tr>
                            <th class="px-3 py-2 text-left">Cliente</th>
                            <th class="px-3 py-2 text-left">Título</th>
                            <th class="px-3 py-2 text-left">Pipeline / Etapa</th>
                            <th class="px-3 py-2 text-left">Valor</th>
                            <th class="px-3 py-2 text-left">Fonte</th>
                            <th class="px-3 py-2 text-left">Responsável</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Datas</th>
                            <th class="px-3 py-2 text-left">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($opps as $op): ?>
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="px-3 py-2">
                                    <a class="text-indigo-600 hover:underline" href="/clients.php?action=view&id=<?= $op['client_id'] ?>"><?= sanitize($op['client_nome']) ?></a>
                                </td>
                                <td class="px-3 py-2"><?= sanitize($op['titulo']) ?></td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                                    <div><?= sanitize($op['pipeline_nome']) ?></div>
                                    <div class="text-xs text-slate-500"><?= sanitize($op['stage_nome']) ?></div>
                                </td>
                                <td class="px-3 py-2"><?= format_currency($op['valor_potencial']) ?></td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= sanitize($op['fonte']) ?></td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= sanitize($op['responsavel_nome']) ?></td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-1 rounded text-xs <?= $op['status'] === 'ganha' ? 'bg-emerald-50 text-emerald-700' : ($op['status'] === 'perdida' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-700') ?>">
                                        <?= ucfirst($op['status']) ?>
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-500">
                                    Criada: <?= date('d/m H:i', strtotime($op['created_at'])) ?><br>
                                    <?php if ($op['closed_at']): ?>Fechada: <?= date('d/m H:i', strtotime($op['closed_at'])) ?><?php endif; ?>
                                </td>
                                <td class="px-3 py-2">
                                    <form method="POST" class="space-y-2">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= $op['id'] ?>">
                                        <select name="stage_id" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 mb-1 text-xs">
                                            <?php foreach ($stagesByPipeline[$op['pipeline_id']] ?? [] as $st): ?>
                                                <option value="<?= $st['id'] ?>" <?= (int)$op['stage_id'] === (int)$st['id'] ? 'selected' : '' ?>><?= sanitize($st['nome']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="status" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 text-xs mb-1">
                                            <?php foreach (['aberta','ganha','perdida'] as $st): ?>
                                                <option value="<?= $st ?>" <?= $op['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="w-full text-xs bg-slate-200 dark:bg-slate-800 rounded py-1">Salvar</button>
                                        <a class="text-xs text-indigo-600 hover:underline block text-center" href="/orders.php">Gerar Pedido</a>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($opps)): ?>
                            <tr><td colspan="9" class="px-3 py-4 text-center text-slate-500">Nenhuma oportunidade.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-4">
                <h3 class="font-semibold mb-3">Visão por funil</h3>
                <div class="space-y-4">
                    <?php foreach ($pipelines as $p): ?>
                        <div>
                            <p class="text-sm font-semibold mb-2"><?= sanitize($p['nome']) ?></p>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                <?php foreach ($pipelineSummary[$p['id']] ?? [] as $col): ?>
                                    <div class="rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-3">
                                        <p class="font-medium"><?= sanitize($col['stage']['nome']) ?></p>
                                        <p class="text-xs text-slate-500">Abertas: <?= $col['count'] ?></p>
                                        <p class="text-sm font-semibold"><?= format_currency($col['total']) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
<?php endif; ?>
