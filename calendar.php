<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

$clients = $users = [];
$missingTables = false;
$missingMessage = '';
try {
    $clientsStmt = $pdo->prepare('SELECT id, nome FROM clients WHERE company_id=? ORDER BY nome');
    $clientsStmt->execute([$companyId]);
    $clients = $clientsStmt->fetchAll();

    $usersStmt = $pdo->prepare('SELECT id, nome FROM users WHERE company_id=? ORDER BY nome');
    $usersStmt->execute([$companyId]);
    $users = $usersStmt->fetchAll();
} catch (Throwable $e) {
    // tabelas base existem; se calendar_events faltar, tratamos mais abaixo
}

$flashError = $flashSuccess = null;

if (!$missingTables && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $inicio = $_POST['data_inicio'] ?? '';
        $fim = $_POST['data_fim'] ?? '';
        $tipo = trim($_POST['tipo'] ?? '');
        $origem = trim($_POST['origem'] ?? 'manual');
        $status = trim($_POST['status'] ?? 'agendado');

        if ($titulo === '' || !$inicio || !$fim) {
            $flashError = 'Informe titulo, inicio e fim.';
        } else {
            $pdo->prepare('INSERT INTO calendar_events (company_id, client_id, user_id, titulo, descricao, data_inicio, data_fim, tipo, origem, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$companyId, $clientId ?: null, $userId ?: null, $titulo, $descricao, $inicio, $fim, $tipo, $origem, $status]);
            $flashSuccess = 'Evento criado.';
        }
    }
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($id && $status) {
            $pdo->prepare('UPDATE calendar_events SET status=?, updated_at=NOW() WHERE id=? AND company_id=?')
                ->execute([$status, $id, $companyId]);
            $flashSuccess = 'Evento atualizado.';
        }
    }
}

// filtros
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterTipo = trim($_GET['tipo'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterData = trim($_GET['data'] ?? '');

$where = 'company_id = ?';
$params = [$companyId];
if ($filterUser) { $where .= ' AND user_id = ?'; $params[] = $filterUser; }
$ifTipo = $filterTipo !== '';
if ($ifTipo) { $where .= ' AND tipo = ?'; $params[] = $filterTipo; }
if ($filterStatus !== '') { $where .= ' AND status = ?'; $params[] = $filterStatus; }
if ($filterData !== '') { $where .= ' AND DATE(data_inicio) = ?'; $params[] = $filterData; }

$events = [];
try {
    $eventsStmt = $pdo->prepare("SELECT ce.*, c.nome as client_nome, u.nome as user_nome FROM calendar_events ce
        LEFT JOIN clients c ON c.id = ce.client_id
        LEFT JOIN users u ON u.id = ce.user_id
        WHERE $where
        ORDER BY ce.data_inicio DESC");
    $eventsStmt->execute($params);
    $events = $eventsStmt->fetchAll();
} catch (Throwable $e) {
    $missingTables = true;
    $missingMessage = 'Tabela calendar_events não existe. Importe o arquivo database_funnel_agenda.sql.';
}

include __DIR__ . '/views/partials/header.php';
if ($flashSuccess) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($flashSuccess) . '</div>';
if ($flashError) echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($flashError) . '</div>';
?>
<div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">Agenda / Compromissos</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">Agende atendimentos e retornos.</p>
        </div>
    </div>

    <?php if ($missingTables): ?>
        <div class="p-4 rounded border border-amber-300 bg-amber-50 text-amber-800">
            <?= sanitize($missingMessage) ?>
        </div>
    <?php else: ?>

    <form class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-4">
        <div>
            <label class="text-xs text-slate-500">Data</label>
            <input type="date" name="data" value="<?= sanitize($filterData) ?>" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
        </div>
        <div>
            <label class="text-xs text-slate-500">Responsavel</label>
            <select name="user_id" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                <option value="">Todos</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>><?= sanitize($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-500">Tipo</label>
            <input name="tipo" value="<?= sanitize($filterTipo) ?>" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700" placeholder="atendimento, retorno...">
        </div>
        <div>
            <label class="text-xs text-slate-500">Status</label>
            <select name="status" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                <option value="">Todos</option>
                <?php foreach (['agendado','concluido','cancelado','no-show'] as $st): ?>
                    <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end">
            <button class="w-full bg-indigo-600 text-white rounded py-2 hover:bg-indigo-700">Filtrar</button>
        </div>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-4">
            <h3 class="font-semibold mb-3">Novo Evento</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="create">
                <div>
                    <label class="text-xs text-slate-500">Cliente (opcional)</label>
                    <select name="client_id" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                        <option value="">Nenhum</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Responsavel</label>
                    <select name="user_id" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                        <option value="">Nenhum</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= sanitize($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Titulo</label>
                    <input name="titulo" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Descricao</label>
                    <textarea name="descricao" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" rows="2"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-slate-500">Inicio</label>
                        <input type="datetime-local" name="data_inicio" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Fim</label>
                        <input type="datetime-local" name="data_fim" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-slate-500">Tipo</label>
                        <input name="tipo" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" placeholder="atendimento, retorno...">
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Status</label>
                        <select name="status" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                            <?php foreach (['agendado','concluido','cancelado','no-show'] as $st): ?>
                                <option value="<?= $st ?>"><?= ucfirst($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Origem</label>
                    <input name="origem" value="manual" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                </div>
                <button class="w-full bg-indigo-600 text-white rounded py-2 hover:bg-indigo-700">Salvar evento</button>
            </form>
        </div>

        <div class="lg:col-span-2">
            <div class="overflow-hidden border border-slate-200 dark:border-slate-700 rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                        <tr>
                            <th class="px-3 py-2 text-left">Inicio - Fim</th>
                            <th class="px-3 py-2 text-left">Titulo</th>
                            <th class="px-3 py-2 text-left">Cliente</th>
                            <th class="px-3 py-2 text-left">Responsavel</th>
                            <th class="px-3 py-2 text-left">Tipo</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $ev): ?>
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="px-3 py-2 text-slate-700 dark:text-slate-200">
                                    <?= date('d/m H:i', strtotime($ev['data_inicio'])) ?> - <?= date('d/m H:i', strtotime($ev['data_fim'])) ?>
                                </td>
                                <td class="px-3 py-2 font-medium"><?= sanitize($ev['titulo']) ?></td>
                                <td class="px-3 py-2">
                                    <?php if ($ev['client_id']): ?>
                                        <a class="text-indigo-600 hover:underline" href="/clients.php?action=view&id=<?= $ev['client_id'] ?>"><?= sanitize($ev['client_nome']) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= sanitize($ev['user_nome']) ?></td>
                                <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= sanitize($ev['tipo']) ?></td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-1 rounded text-xs <?= $ev['status'] === 'concluido' ? 'bg-emerald-50 text-emerald-700' : ($ev['status'] === 'cancelado' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-700') ?>"><?= ucfirst($ev['status']) ?></span>
                                </td>
                                <td class="px-3 py-2">
                                    <form method="POST" class="flex items-center gap-2 text-xs">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= $ev['id'] ?>">
                                        <select name="status" class="rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                                            <?php foreach (['agendado','concluido','cancelado','no-show'] as $st): ?>
                                                <option value="<?= $st ?>" <?= $ev['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="px-2 py-1 rounded bg-slate-200 dark:bg-slate-800">Salvar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($events)): ?>
                            <tr><td colspan="7" class="px-3 py-4 text-center text-slate-500">Nenhum evento.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
<?php endif; ?>
