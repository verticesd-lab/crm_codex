<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'promo') {
    $data = [
        'titulo' => trim($_POST['titulo'] ?? ''),
        'slug' => trim($_POST['slug'] ?? ''),
        'descricao' => trim($_POST['descricao'] ?? ''),
        'texto_chamada' => trim($_POST['texto_chamada'] ?? ''),
        'destaque_produtos' => trim($_POST['destaque_produtos'] ?? ''),
        'data_inicio' => $_POST['data_inicio'] ?? null,
        'data_fim' => $_POST['data_fim'] ?? null,
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
    ];
    $banner = null;
    if (!empty($_FILES['banner_image']['name'])) {
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['banner_image']['name']);
        $dest = __DIR__ . '/uploads/' . $filename;
        if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $dest)) {
            $banner = '/uploads/' . $filename;
        }
    }
    if (!$data['titulo'] || !$data['slug']) {
        flash('error', 'Informe título e slug.');
        redirect('/promotions.php?action=' . ($id ? 'edit&id=' . $id : 'create'));
    }
    if ($id) {
        $sql = 'UPDATE promotions SET titulo=?, slug=?, descricao=?, texto_chamada=?, destaque_produtos=?, data_inicio=?, data_fim=?, ativo=?, updated_at=NOW()';
        $params = [$data['titulo'], $data['slug'], $data['descricao'], $data['texto_chamada'], $data['destaque_produtos'], $data['data_inicio'], $data['data_fim'], $data['ativo']];
        if ($banner) { $sql .= ', banner_image=?'; $params[] = $banner; }
        $sql .= ' WHERE id=? AND company_id=?';
        $params[] = $id; $params[] = $companyId;
        $pdo->prepare($sql)->execute($params);
        log_action($pdo, $companyId, $_SESSION['user_id'], 'promo_update', 'Promo #' . $id);
        flash('success', 'Promoção atualizada.');
    } else {
        $pdo->prepare('INSERT INTO promotions (company_id, titulo, slug, descricao, banner_image, texto_chamada, destaque_produtos, data_inicio, data_fim, ativo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$companyId, $data['titulo'], $data['slug'], $data['descricao'], $banner, $data['texto_chamada'], $data['destaque_produtos'], $data['data_inicio'], $data['data_fim'], $data['ativo']]);
        $newId = (int)$pdo->lastInsertId();
        log_action($pdo, $companyId, $_SESSION['user_id'], 'promo_create', 'Promo #' . $newId);
        flash('success', 'Promoção criada.');
    }
    redirect('/promotions.php');
}

if ($action === 'delete' && $id) {
    $pdo->prepare('DELETE FROM promotions WHERE id=? AND company_id=?')->execute([$id, $companyId]);
    log_action($pdo, $companyId, $_SESSION['user_id'], 'promo_delete', 'Promo #' . $id);
    flash('success', 'Promoção removida.');
    redirect('/promotions.php');
}

include __DIR__ . '/views/partials/header.php';
if ($msg = get_flash('success')) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($msg) . '</div>';
if ($msg = get_flash('error')) echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($msg) . '</div>';

if ($action === 'create' || ($action === 'edit' && $id)) {
    $promo = [
        'titulo' => '',
        'slug' => '',
        'descricao' => '',
        'texto_chamada' => '',
        'destaque_produtos' => '',
        'data_inicio' => '',
        'data_fim' => '',
        'ativo' => 1,
        'banner_image' => '',
    ];
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM promotions WHERE id=? AND company_id=?');
        $stmt->execute([$id, $companyId]);
        $promo = $stmt->fetch();
    }
    ?>
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm max-w-3xl">
        <h1 class="text-2xl font-semibold mb-2"><?= $id ? 'Editar' : 'Nova' ?> promoção</h1>
        <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="form" value="promo">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Título</label>
                <input name="titulo" value="<?= sanitize($promo['titulo']) ?>" class="w-full rounded border-slate-300" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Slug</label>
                <input name="slug" value="<?= sanitize($promo['slug']) ?>" class="w-full rounded border-slate-300" required>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Descrição</label>
                <textarea name="descricao" class="w-full rounded border-slate-300" rows="3"><?= sanitize($promo['descricao']) ?></textarea>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Texto de chamada</label>
                <input name="texto_chamada" value="<?= sanitize($promo['texto_chamada']) ?>" class="w-full rounded border-slate-300">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Produtos em destaque (IDs separados por vírgula)</label>
                <input name="destaque_produtos" value="<?= sanitize($promo['destaque_produtos']) ?>" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Data início</label>
                <input type="date" name="data_inicio" value="<?= sanitize($promo['data_inicio']) ?>" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Data fim</label>
                <input type="date" name="data_fim" value="<?= sanitize($promo['data_fim']) ?>" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="ativo" <?= $promo['ativo'] ? 'checked' : '' ?>> Ativa
                </label>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Banner (opcional)</label>
                <input type="file" name="banner_image" class="w-full rounded border-slate-300">
                <?php if (!empty($promo['banner_image'])): ?>
                    <img src="<?= sanitize($promo['banner_image']) ?>" class="mt-2 h-24 rounded">
                <?php endif; ?>
            </div>
            <div class="md:col-span-2 flex gap-3">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700"><?= $id ? 'Salvar' : 'Criar' ?></button>
                <a href="/promotions.php" class="px-4 py-2 rounded border border-slate-300 text-slate-700">Cancelar</a>
            </div>
        </form>
    </div>
    <?php
    include __DIR__ . '/views/partials/footer.php';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM promotions WHERE company_id=? ORDER BY created_at DESC');
$stmt->execute([$companyId]);
$promos = $stmt->fetchAll();
?>
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-2xl font-semibold">Promoções</h1>
        <p class="text-sm text-slate-600">Crie LPs com botão de WhatsApp.</p>
    </div>
    <a href="/promotions.php?action=create" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Nova Promoção</a>
</div>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-700">
        <tr>
            <th class="px-4 py-2 text-left">Título</th>
            <th class="px-4 py-2 text-left">Slug</th>
            <th class="px-4 py-2 text-left">Período</th>
            <th class="px-4 py-2 text-left">Status</th>
            <th class="px-4 py-2"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($promos as $promo): ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
                <td class="px-4 py-2 font-medium"><?= sanitize($promo['titulo']) ?></td>
                <td class="px-4 py-2"><?= sanitize($promo['slug']) ?></td>
                <td class="px-4 py-2 text-slate-600"><?= sanitize($promo['data_inicio']) ?> - <?= sanitize($promo['data_fim']) ?></td>
                <td class="px-4 py-2"><?= $promo['ativo'] ? 'Ativa' : 'Inativa' ?></td>
                <td class="px-4 py-2 text-right space-x-2">
                    <a class="text-indigo-600 hover:underline" target="_blank" href="/promo.php?empresa=<?= sanitize($_SESSION['company_slug']) ?>&promo=<?= sanitize($promo['slug']) ?>">LP</a>
                    <a class="text-slate-600 hover:underline" href="/promotions.php?action=edit&id=<?= $promo['id'] ?>">Editar</a>
                    <a class="text-red-600 hover:underline" href="/promotions.php?action=delete&id=<?= $promo['id'] ?>" onclick="return confirm('Excluir promoção?')">Excluir</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($promos)): ?>
            <tr><td colspan="5" class="px-4 py-3 text-center text-slate-500">Nenhuma promoção.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
