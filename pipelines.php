<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

$flashError = $flashSuccess = null;
$missingTables = false;
$missingMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // só processa se tabelas existirem
    try {
        $pdo->query('SELECT 1 FROM pipelines LIMIT 1');
        $pdo->query('SELECT 1 FROM pipeline_stages LIMIT 1');
    } catch (Throwable $e) {
        $missingTables = true;
        $missingMessage = 'Tabelas de funil não existem. Importe database_funnel_agenda.sql (pipelines, pipeline_stages, opportunities).';
    }
    if (!$missingTables) {
        $action = $_POST['action'] ?? '';
        if ($action === 'pipeline_save') {
            $id = (int)($_POST['id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            $ativo = isset($_POST['ativo']) ? 1 : 0;
            if ($nome === '') {
                $flashError = 'Informe um nome para o pipeline.';
            } else {
                if ($id) {
                    $pdo->prepare('UPDATE pipelines SET nome=?, descricao=?, ativo=?, updated_at=NOW() WHERE id=? AND company_id=?')
                        ->execute([$nome, $descricao, $ativo, $id, $companyId]);
                    $flashSuccess = 'Pipeline atualizado.';
                } else {
                    $pdo->prepare('INSERT INTO pipelines (company_id, nome, descricao, ativo, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
                        ->execute([$companyId, $nome, $descricao, $ativo]);
                    $flashSuccess = 'Pipeline criado.';
                }
            }
        }
        if ($action === 'pipeline_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $pdo->prepare('DELETE FROM pipelines WHERE id=? AND company_id=?')->execute([$id, $companyId]);
                $flashSuccess = 'Pipeline removido.';
            }
        }
        if ($action === 'stage_save') {
            $id = (int)($_POST['id'] ?? 0);
            $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $ordem = (int)($_POST['ordem'] ?? 0);
            if ($pipelineId && $nome !== '') {
                if ($id) {
                    $pdo->prepare('UPDATE pipeline_stages SET nome=?, ordem=?, updated_at=NOW() WHERE id=? AND pipeline_id=?')
                        ->execute([$nome, $ordem, $id, $pipelineId]);
                    $flashSuccess = 'Etapa atualizada.';
                } else {
                    $pdo->prepare('INSERT INTO pipeline_stages (pipeline_id, nome, ordem, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
                        ->execute([$pipelineId, $nome, $ordem]);
                    $flashSuccess = 'Etapa criada.';
                }
            } else {
                $flashError = 'Informe pipeline e nome da etapa.';
            }
        }
        if ($action === 'stage_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $pdo->prepare('DELETE FROM pipeline_stages WHERE id=?')->execute([$id]);
                $flashSuccess = 'Etapa removida.';
            }
        }
    }
}

$pipelines = $stages = [];
if (!$missingTables) {
    try {
        $stmtP = $pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM opportunities o WHERE o.company_id=? AND o.pipeline_id=p.id AND o.status="aberta") as total_abertas FROM pipelines p WHERE p.company_id=? ORDER BY p.nome');
        $stmtP->execute([$companyId, $companyId]);
        $pipelines = $stmtP->fetchAll();

        $stagesStmt = $pdo->prepare('SELECT ps.*, p.nome as pipeline_nome FROM pipeline_stages ps JOIN pipelines p ON p.id = ps.pipeline_id WHERE p.company_id=? ORDER BY p.nome, ps.ordem');
        $stagesStmt->execute([$companyId]);
        $stages = $stagesStmt->fetchAll();
    } catch (Throwable $e) {
        $missingTables = true;
        $missingMessage = 'Tabelas de funil não existem. Importe database_funnel_agenda.sql (pipelines, pipeline_stages, opportunities).';
    }
}

include __DIR__ . '/views/partials/header.php';
if ($flashSuccess) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($flashSuccess) . '</div>';
if ($flashError) echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($flashError) . '</div>';
?>
<div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Pipelines e Etapas</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">Estruture o funil de vendas da empresa.</p>
        </div>
        <a href="/opportunities.php" class="text-sm text-indigo-600 hover:underline">Voltar para Oportunidades</a>
    </div>

    <?php if ($missingTables): ?>
        <div class="p-4 rounded border border-amber-300 bg-amber-50 text-amber-800">
            <?= sanitize($missingMessage) ?>
        </div>
    <?php else: ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-4">
            <h3 class="font-semibold mb-3">Novo / Editar Pipeline</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="pipeline_save">
                <input type="hidden" name="id" value="">
                <div>
                    <label class="text-xs text-slate-500">Nome</label>
                    <input name="nome" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                </div>
                <div>
                    <label class="text-xs text-slate-500">Descrição</label>
                    <textarea name="descricao" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" rows="2"></textarea>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="ativo" checked class="rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700"> Ativo
                </label>
                <button class="w-full bg-indigo-600 text-white rounded py-2 hover:bg-indigo-700">Salvar pipeline</button>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                    <tr>
                        <th class="px-3 py-2 text-left">Nome</th>
                        <th class="px-3 py-2 text-left">Oportunidades</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pipelines as $p): ?>
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-3 py-2 font-medium"><?= sanitize($p['nome']) ?></td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= (int)$p['total_abertas'] ?> abertas</td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></td>
                            <td class="px-3 py-2">
                                <form method="POST" class="flex gap-2">
                                    <input type="hidden" name="action" value="pipeline_delete">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button class="text-red-600 text-sm" onclick="return confirm('Remover pipeline?')">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pipelines)): ?>
                        <tr><td colspan="4" class="px-3 py-3 text-center text-slate-500">Nenhum pipeline cadastrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold">Etapas</h3>
        </div>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
            <input type="hidden" name="action" value="stage_save">
            <input type="hidden" name="id" value="">
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
                <label class="text-xs text-slate-500">Nome</label>
                <input name="nome" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>
            <div>
                <label class="text-xs text-slate-500">Ordem</label>
                <input name="ordem" type="number" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" value="0">
            </div>
            <div class="flex items-end">
                <button class="w-full bg-indigo-600 text-white rounded py-2 hover:bg-indigo-700">Salvar etapa</button>
            </div>
        </form>

        <div class="overflow-hidden border border-slate-200 dark:border-slate-700 rounded-lg">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                    <tr>
                        <th class="px-3 py-2 text-left">Pipeline</th>
                        <th class="px-3 py-2 text-left">Nome</th>
                        <th class="px-3 py-2 text-left">Ordem</th>
                        <th class="px-3 py-2 text-left">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stages as $st): ?>
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= sanitize($st['pipeline_nome']) ?></td>
                            <td class="px-3 py-2 font-medium"><?= sanitize($st['nome']) ?></td>
                            <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= (int)$st['ordem'] ?></td>
                            <td class="px-3 py-2">
                                <form method="POST" onsubmit="return confirm('Excluir etapa?');">
                                    <input type="hidden" name="action" value="stage_delete">
                                    <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                    <button class="text-sm text-red-600 hover:underline">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stages)): ?>
                        <tr><td colspan="4" class="px-3 py-3 text-center text-slate-500">Nenhuma etapa cadastrada.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
<?php endif; ?>
