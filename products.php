<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'product') {
    $data = [
        'nome' => trim($_POST['nome'] ?? ''),
        'descricao' => trim($_POST['descricao'] ?? ''),
        'categoria' => trim($_POST['categoria'] ?? ''),
        'tipo' => $_POST['tipo'] ?? 'produto',
        'preco' => (float)($_POST['preco'] ?? 0),
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
        'destaque' => isset($_POST['destaque']) ? 1 : 0,
        'sizes' => trim($_POST['sizes'] ?? ''),
    ];
    $imagePath = null;
    if (!empty($_FILES['imagem']['name'])) {
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['imagem']['name']);
        $dest = __DIR__ . '/uploads/' . $filename;
        if (move_uploaded_file($_FILES['imagem']['tmp_name'], $dest)) {
            $imagePath = '/uploads/' . $filename;
        }
    }
    if (!$data['nome']) {
        flash('error', 'Informe o nome do produto/serviço.');
        redirect('/products.php?action=' . ($id ? 'edit&id=' . $id : 'create'));
    }
    if ($id) {
        $sql = 'UPDATE products SET nome=?, descricao=?, categoria=?, tipo=?, preco=?, ativo=?, destaque=?, sizes=?, updated_at=NOW()';
        $params = [$data['nome'], $data['descricao'], $data['categoria'], $data['tipo'], $data['preco'], $data['ativo'], $data['destaque'], $data['sizes']];
        if ($imagePath) {
            $sql .= ', imagem=?';
            $params[] = $imagePath;
        }
        $sql .= ' WHERE id=? AND company_id=?';
        $params[] = $id;
        $params[] = $companyId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        log_action($pdo, $companyId, $_SESSION['user_id'], 'produto_update', 'Produto #' . $id);
        flash('success', 'Produto atualizado.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO products (company_id, nome, descricao, categoria, tipo, preco, ativo, destaque, sizes, imagem, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$companyId, $data['nome'], $data['descricao'], $data['categoria'], $data['tipo'], $data['preco'], $data['ativo'], $data['destaque'], $data['sizes'], $imagePath]);
        $newId = (int)$pdo->lastInsertId();
        log_action($pdo, $companyId, $_SESSION['user_id'], 'produto_create', 'Produto #' . $newId);
        flash('success', 'Produto criado.');
    }
    redirect('/products.php');
}

if ($action === 'delete' && $id) {
    $pdo->prepare('DELETE FROM products WHERE id=? AND company_id=?')->execute([$id, $companyId]);
    log_action($pdo, $companyId, $_SESSION['user_id'], 'produto_delete', 'Produto #' . $id);
    flash('success', 'Produto removido.');
    redirect('/products.php');
}

if ($action === 'toggle' && $id) {
    $pdo->prepare('UPDATE products SET ativo = IF(ativo=1,0,1), updated_at=NOW() WHERE id=? AND company_id=?')->execute([$id, $companyId]);
    log_action($pdo, $companyId, $_SESSION['user_id'], 'produto_toggle', 'Produto #' . $id);
    redirect('/products.php');
}

include __DIR__ . '/views/partials/header.php';
if ($msg = get_flash('success')) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($msg) . '</div>';
if ($msg = get_flash('error')) echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($msg) . '</div>';

if ($action === 'create' || ($action === 'edit' && $id)) {
    $product = [
        'nome' => '',
        'descricao' => '',
        'categoria' => '',
        'tipo' => 'produto',
        'preco' => '0.00',
        'ativo' => 1,
        'destaque' => 0,
        'imagem' => '',
        'sizes' => '',
    ];
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id=? AND company_id=?');
        $stmt->execute([$id, $companyId]);
        $product = $stmt->fetch();
    }
    ?>
    <style>
        .size-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .size-option-check {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .size-option-check input[type="checkbox"] {
            margin: 0;
        }
        .product-sizes {
            margin-top: 6px;
        }
        .product-sizes .size-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background-color: #f1f5f9;
            color: #334155;
            font-size: 0.75rem;
            margin-right: 4px;
        }
    </style>
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm max-w-3xl">
        <h1 class="text-2xl font-semibold mb-2"><?= $id ? 'Editar' : 'Novo' ?> produto/serviço</h1>
        <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="form" value="product">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Nome</label>
                <input name="nome" value="<?= sanitize($product['nome']) ?>" class="w-full rounded border-slate-300" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Categoria</label>
                <input name="categoria" value="<?= sanitize($product['categoria']) ?>" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Tipo</label>
                <select name="tipo" class="w-full rounded border-slate-300">
                    <option value="produto" <?= $product['tipo'] === 'produto' ? 'selected' : '' ?>>Produto</option>
                    <option value="servico" <?= $product['tipo'] === 'servico' ? 'selected' : '' ?>>Serviço</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Preço</label>
                <input name="preco" type="number" step="0.01" value="<?= sanitize($product['preco']) ?>" class="w-full rounded border-slate-300">
            </div>
            <div class="md:col-span-2">
                <label for="sizePreset" class="block text-sm text-slate-600 mb-1">Tamanhos disponíveis</label>
                <select id="sizePreset" class="w-full rounded border-slate-300">
                    <option value="">Selecione o tipo de tamanho</option>
                    <option value="roupa">Roupas – P, M, G, GG</option>
                    <option value="calcado">Calçados – 37 ao 45</option>
                    <option value="custom">Personalizado</option>
                </select>
                <div id="sizeOptions" class="size-options mt-2"></div>
                <input type="hidden" name="sizes" id="sizesHidden" value="<?= sanitize($product['sizes'] ?? '') ?>">
                <p class="text-xs text-slate-500 mt-1">Esses tamanhos são exibidos no anúncio para o cliente saber se há disponibilidade.</p>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Descrição</label>
                <textarea name="descricao" class="w-full rounded border-slate-300" rows="3"><?= sanitize($product['descricao']) ?></textarea>
            </div>
            <div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="ativo" <?= $product['ativo'] ? 'checked' : '' ?>> Ativo
                </label>
            </div>
            <div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="destaque" <?= $product['destaque'] ? 'checked' : '' ?>> Destaque
                </label>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Imagem (opcional)</label>
                <input type="file" name="imagem" class="w-full rounded border-slate-300">
                <?php if (!empty($product['imagem'])): ?>
                    <img src="<?= sanitize($product['imagem']) ?>" class="mt-2 h-24 rounded">
                <?php endif; ?>
            </div>
            <div class="md:col-span-2 flex gap-3">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700"><?= $id ? 'Salvar' : 'Criar' ?></button>
                <a href="/products.php" class="px-4 py-2 rounded border border-slate-300 text-slate-700">Cancelar</a>
            </div>
        </form>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sizePreset = document.getElementById('sizePreset');
            const sizeOptions = document.getElementById('sizeOptions');
            const sizesHidden = document.getElementById('sizesHidden');

            if (!sizePreset || !sizeOptions || !sizesHidden) return;

            function updateHidden() {
                const checked = sizeOptions.querySelectorAll('input[type=\"checkbox\"]:checked');
                const values = Array.from(checked).map(function (el) { return el.value; });
                sizesHidden.value = values.join(',');
            }

            function renderOptions(preset, initialValues) {
                if (!Array.isArray(initialValues)) initialValues = [];
                sizeOptions.innerHTML = '';

                let sizes = [];

                if (preset === 'roupa') {
                    sizes = ['P', 'M', 'G', 'GG'];
                } else if (preset === 'calcado') {
                    sizes = ['37', '38', '39', '40', '41', '42', '43', '44', '45'];
                } else if (preset === 'custom') {
                    sizeOptions.innerHTML = '<small>Modo personalizado ainda será implementado futuramente.</small>';
                    sizesHidden.value = '';
                    return;
                } else {
                    sizesHidden.value = '';
                    return;
                }

                sizes.forEach(function (size) {
                    const isChecked = initialValues.indexOf(size) !== -1;

                    const label = document.createElement('label');
                    label.className = 'size-option-check';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.value = size;
                    checkbox.checked = isChecked;
                    checkbox.addEventListener('change', updateHidden);

                    const span = document.createElement('span');
                    span.textContent = size;

                    label.appendChild(checkbox);
                    label.appendChild(span);
                    sizeOptions.appendChild(label);
                });

                updateHidden();
            }

            sizePreset.addEventListener('change', function () {
                renderOptions(this.value);
            });

            const initial = sizesHidden.value;
            if (initial) {
                const arr = initial.split(',').map(function (v) { return v.trim(); }).filter(Boolean);
                let preset = '';
                if (arr.some(function (v) { return ['P', 'M', 'G', 'GG'].indexOf(v) !== -1; })) {
                    preset = 'roupa';
                } else if (arr.some(function (v) { return ['37', '38', '39', '40', '41', '42', '43', '44', '45'].indexOf(v) !== -1; })) {
                    preset = 'calcado';
                }

                if (preset) {
                    sizePreset.value = preset;
                    renderOptions(preset, arr);
                }
            }
        });
    </script>
    <?php
    include __DIR__ . '/views/partials/footer.php';
    exit;
}

$search = trim($_GET['q'] ?? '');
$cat = trim($_GET['categoria'] ?? '');
$tipo = trim($_GET['tipo'] ?? '');
$params = [$companyId];
$where = 'company_id = ?';
if ($search) { $where .= ' AND nome LIKE ?'; $params[] = '%' . $search . '%'; }
if ($cat) { $where .= ' AND categoria = ?'; $params[] = $cat; }
if ($tipo) { $where .= ' AND tipo = ?'; $params[] = $tipo; }

$stmt = $pdo->prepare("SELECT * FROM products WHERE $where ORDER BY created_at DESC");
$stmt->execute($params);
$products = $stmt->fetchAll();
?>
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-2xl font-semibold">Produtos/Serviços</h1>
        <p class="text-sm text-slate-600">Controle itens do catálogo.</p>
    </div>
    <a href="/products.php?action=create" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Novo Produto</a>
</div>
<form class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
    <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Buscar por nome" class="rounded border-slate-300">
    <input type="text" name="categoria" value="<?= sanitize($cat) ?>" placeholder="Categoria" class="rounded border-slate-300">
    <select name="tipo" class="rounded border-slate-300">
        <option value="">Tipo</option>
        <option value="produto" <?= $tipo === 'produto' ? 'selected' : '' ?>>Produto</option>
        <option value="servico" <?= $tipo === 'servico' ? 'selected' : '' ?>>Serviço</option>
    </select>
    <button class="bg-slate-900 text-white rounded px-4">Filtrar</button>
</form>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-700">
        <tr>
            <th class="px-4 py-2 text-left">Nome</th>
            <th class="px-4 py-2 text-left">Categoria</th>
            <th class="px-4 py-2 text-left">Tipo</th>
            <th class="px-4 py-2 text-left">Preço</th>
            <th class="px-4 py-2 text-left">Status</th>
            <th class="px-4 py-2"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
                <td class="px-4 py-2 font-medium"><?= sanitize($product['nome']) ?></td>
                <td class="px-4 py-2"><?= sanitize($product['categoria']) ?></td>
                <td class="px-4 py-2"><?= sanitize($product['tipo']) ?></td>
                <td class="px-4 py-2"><?= format_currency($product['preco']) ?></td>
                <td class="px-4 py-2"><?= $product['ativo'] ? 'Ativo' : 'Inativo' ?></td>
                <td class="px-4 py-2 text-right space-x-2">
                    <a class="text-indigo-600 hover:underline" href="/products.php?action=edit&id=<?= $product['id'] ?>">Editar</a>
                    <a class="text-slate-600 hover:underline" href="/products.php?action=toggle&id=<?= $product['id'] ?>"><?= $product['ativo'] ? 'Desativar' : 'Ativar' ?></a>
                    <a class="text-red-600 hover:underline" href="/products.php?action=delete&id=<?= $product['id'] ?>" onclick="return confirm('Excluir?')">Excluir</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($products)): ?>
            <tr><td class="px-4 py-3 text-center text-slate-500" colspan="6">Nenhum produto.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
