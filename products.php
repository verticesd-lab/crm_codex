<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

$pdo = get_pdo();

/**
 * Sincroniza as variantes (product_variants) e saldos (stock_balances)
 * a partir da string de tamanhos salva no campo products.sizes.
 *
 * Regra atual (simples):
 * - Cada tamanho vira uma variante com "size" preenchido e "color" NULL.
 * - Variantes antigas do produto são apagadas e recriadas.
 * - Estoque inicial sempre 0 na tabela stock_balances.
 */
function sync_product_variants_from_sizes($pdo, int $productId, string $sizesCsv): void
{
    // explode tamanhos: "P,M,G" -> ["P","M","G"]
    $sizes = array_filter(array_map('trim', explode(',', $sizesCsv)));

    // Busca variantes antigas desse produto
    $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
    $stmt->execute([$productId]);
    $oldVariantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($oldVariantIds)) {
        // Monta placeholders: ?, ?, ? ...
        $placeholders = implode(',', array_fill(0, count($oldVariantIds), '?'));

        // Remove movimentos de estoque ligados às variantes antigas
        $stmtDelMov = $pdo->prepare("DELETE FROM stock_movements WHERE product_variant_id IN ($placeholders)");
        $stmtDelMov->execute($oldVariantIds);

        // Remove saldos de estoque ligados às variantes antigas
        $stmtDelBal = $pdo->prepare("DELETE FROM stock_balances WHERE product_variant_id IN ($placeholders)");
        $stmtDelBal->execute($oldVariantIds);

        // Remove as próprias variantes
        $stmtDelVar = $pdo->prepare("DELETE FROM product_variants WHERE id IN ($placeholders)");
        $stmtDelVar->execute($oldVariantIds);
    }

    // Se não tiver tamanhos, só limpa as variantes e sai
    if (empty($sizes)) {
        return;
    }

    // Cria novas variantes e saldo 0 para cada uma
    foreach ($sizes as $size) {
        if ($size === '') continue;

        // Cria variante
        $stmtVar = $pdo->prepare('
            INSERT INTO product_variants (product_id, size, active, created_at, updated_at)
            VALUES (?, ?, 1, NOW(), NOW())
        ');
        $stmtVar->execute([$productId, $size]);
        $variantId = (int)$pdo->lastInsertId();

        // Cria saldo inicial 0 na loja física
        $stmtBal = $pdo->prepare('
            INSERT INTO stock_balances (product_variant_id, location, quantity, updated_at)
            VALUES (?, ?, 0, NOW())
        ');
        $stmtBal->execute([$variantId, 'loja_fisica']);
    }
}

$companyId = current_company_id();
if (!$companyId) {
    // fallback: primeira empresa
    $stmt = $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1');
    $row = $stmt->fetch();
    if ($row) {
        $companyId = (int)$row['id'];
        $_SESSION['company_id'] = $companyId;
    } else {
        http_response_code(400);
        echo 'Nenhuma empresa configurada.';
        exit;
    }
}

$action       = $_GET['action'] ?? 'list';
$flashSuccess = null;
$flashError   = null;

/**
 * Mantém filtros/paginação ao navegar
 */
$q = trim($_GET['q'] ?? '');
$categoria = trim($_GET['categoria'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$perPage = (int)($_GET['per_page'] ?? 30);
$perPage = in_array($perPage, [30, 60], true) ? $perPage : 30;

$backQueryArr = [
    'q' => $q,
    'categoria' => $categoria,
    'page' => $page,
    'per_page' => $perPage,
];
$backQuery = http_build_query(array_filter($backQueryArr, function ($v) {
    return $v !== '' && $v !== null;
}));

// DELETE
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    // Antes de apagar o produto, apaga variantes e estoque ligado a ele
    $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
    $stmt->execute([$id]);
    $variantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($variantIds)) {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $stmtDelMov = $pdo->prepare("DELETE FROM stock_movements WHERE product_variant_id IN ($placeholders)");
        $stmtDelMov->execute($variantIds);

        $stmtDelBal = $pdo->prepare("DELETE FROM stock_balances WHERE product_variant_id IN ($placeholders)");
        $stmtDelBal->execute($variantIds);

        $stmtDelVar = $pdo->prepare("DELETE FROM product_variants WHERE id IN ($placeholders)");
        $stmtDelVar->execute($variantIds);
    }

    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);

    flash('success', 'Produto removido com sucesso.');
    redirect('products.php' . ($backQuery ? ('?' . $backQuery) : ''));
}

// CREATE/UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = (int)($_POST['id'] ?? 0);
    $nome      = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $precoRaw  = str_replace(['.', ','], ['', '.'], $_POST['preco'] ?? '0');
    $preco     = (float)$precoRaw;
    $categoriaPost = trim($_POST['categoria'] ?? '');
    $ativo     = isset($_POST['ativo']) ? 1 : 0;
    $destaque  = isset($_POST['destaque']) ? 1 : 0;
    $sizes     = trim($_POST['sizes'] ?? '');
    $oldImage  = trim($_POST['current_image'] ?? '');

    // retorno preservando filtros/paginação
    $returnQ = trim($_POST['return_q'] ?? '');
    $returnCategoria = trim($_POST['return_categoria'] ?? '');
    $returnPage = max(1, (int)($_POST['return_page'] ?? 1));
    $returnPerPage = (int)($_POST['return_per_page'] ?? 30);
    $returnPerPage = in_array($returnPerPage, [30, 60], true) ? $returnPerPage : 30;

    $returnArr = [
        'q' => $returnQ,
        'categoria' => $returnCategoria,
        'page' => $returnPage,
        'per_page' => $returnPerPage,
    ];
    $returnQuery = http_build_query(array_filter($returnArr, function ($v) {
        return $v !== '' && $v !== null;
    }));

    if ($nome === '' || $preco <= 0) {
        $flashError = 'Informe ao menos o nome e um preço válido.';
    } else {
        // Upload de imagem (opcional)
        $imgPath = $oldImage;
        if (!empty($_FILES['imagem']['name'])) {
            $uploaded = upload_image_optimized('imagem', 'uploads/products');
            if ($uploaded === null) {
                $flashError = 'Falha no upload da imagem. Verifique tipo (JPG/PNG/WEBP) e tamanho.';
            } else {
                $imgPath = $uploaded;
            }
        }

        if ($flashError === null) {
            if ($id > 0) {
                // UPDATE
                $stmt = $pdo->prepare('
                    UPDATE products
                    SET nome = ?, descricao = ?, preco = ?, categoria = ?, sizes = ?, imagem = ?, ativo = ?, destaque = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ');
                $stmt->execute([
                    $nome,
                    $descricao,
                    $preco,
                    $categoriaPost,
                    $sizes,
                    $imgPath,
                    $ativo,
                    $destaque,
                    $id,
                    $companyId
                ]);
                $productId = $id;
                flash('success', 'Produto atualizado com sucesso.');
            } else {
                // INSERT
                $stmt = $pdo->prepare('
                    INSERT INTO products (company_id, nome, descricao, preco, categoria, sizes, imagem, ativo, destaque, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ');
                $stmt->execute([
                    $companyId,
                    $nome,
                    $descricao,
                    $preco,
                    $categoriaPost,
                    $sizes,
                    $imgPath,
                    $ativo,
                    $destaque
                ]);
                $productId = (int)$pdo->lastInsertId();
                flash('success', 'Produto cadastrado com sucesso.');
            }

            // Sincroniza variantes e estoque com base nos tamanhos
            sync_product_variants_from_sizes($pdo, $productId, $sizes);

            redirect('products.php' . ($returnQuery ? ('?' . $returnQuery) : ''));
        }
    }
}

// Carrega produto para edição (se for o caso)
$editingProduct = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $editingProduct = $stmt->fetch();
    if (!$editingProduct) {
        $flashError = 'Produto não encontrado para edição.';
    }
}

/**
 * Categorias dinâmicas (para o menu lateral)
 */
$categoriesStmt = $pdo->prepare('
    SELECT DISTINCT categoria
    FROM products
    WHERE company_id = ?
      AND categoria IS NOT NULL
      AND categoria <> ""
    ORDER BY categoria
');
$categoriesStmt->execute([$companyId]);
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

/**
 * Lista de produtos (COM FILTRO + PAGINAÇÃO)
 */
$where = 'company_id = ?';
$params = [$companyId];

if ($q !== '') {
    $where .= ' AND nome LIKE ?';
    $params[] = '%' . $q . '%';
}

if ($categoria !== '') {
    $where .= ' AND categoria = ?';
    $params[] = $categoria;
}

// total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $where");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalProducts / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

// itens paginados (recentes primeiro)
$listStmt = $pdo->prepare("
    SELECT *
    FROM products
    WHERE $where
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
");
$listStmt->execute($params);
$products = $listStmt->fetchAll();

// Pega flash de sucesso se veio de redirect
$flashSuccess = get_flash('success') ?? $flashSuccess;

include __DIR__ . '/views/partials/header.php';
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
</style>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Produtos / Serviços</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">
                Cadastre produtos e serviços para usar no PDV, na loja pública e nas campanhas.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/products.php?action=create&<?= sanitize($backQuery) ?>"
           class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
            + Novo produto
        </a>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
            <?= sanitize($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="p-3 rounded bg-red-50 text-red-700 border border-red-200">
            <?= sanitize($flashError) ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'create' || $action === 'edit'): ?>
        <?php
        $prod = $editingProduct ?? [
            'id'        => 0,
            'nome'      => '',
            'descricao' => '',
            'preco'     => '0.00',
            'categoria' => '',
            'imagem'    => '',
            'ativo'     => 1,
            'destaque'  => 0,
            'sizes'     => '',
        ];
        ?>
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">
            <h2 class="text-lg font-semibold mb-4">
                <?= $prod['id'] ? 'Editar produto' : 'Novo produto' ?>
            </h2>

            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="id" value="<?= (int)$prod['id'] ?>">
                <input type="hidden" name="current_image" value="<?= sanitize($prod['imagem'] ?? '') ?>">

                <!-- manter retorno para a mesma página/filtros -->
                <input type="hidden" name="return_q" value="<?= sanitize($q) ?>">
                <input type="hidden" name="return_categoria" value="<?= sanitize($categoria) ?>">
                <input type="hidden" name="return_page" value="<?= (int)$page ?>">
                <input type="hidden" name="return_per_page" value="<?= (int)$perPage ?>">

                <div class="grid grid-cols-1 md:grid-cols-[auto,1fr] gap-6 items-start">
                    <div class="flex flex-col items-center gap-3">
                        <div class="h-24 w-24 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden border border-slate-200 dark:border-slate-700">
                            <?php if (!empty($prod['imagem'])): ?>
                                <img src="<?= sanitize(image_url($prod['imagem'])) ?>" class="h-full w-full object-contain p-2" alt="Produto">
                            <?php else: ?>
                                <span class="text-xs text-slate-400 text-center px-2">
                                    Sem imagem
                                </span>
                            <?php endif; ?>
                        </div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">
                            Imagem do produto
                        </label>
                        <input type="file" name="imagem" class="text-xs text-slate-600 dark:text-slate-300">
                        <p class="text-[11px] text-slate-400 text-center">
                            JPG, PNG ou WEBP até <?= MAX_UPLOAD_SIZE_MB ?>MB.
                        </p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                                Nome *
                            </label>
                            <input
                                type="text"
                                name="nome"
                                required
                                value="<?= sanitize($prod['nome'] ?? '') ?>"
                                class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                                Descrição
                            </label>
                            <textarea
                                name="descricao"
                                rows="3"
                                class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 text-sm"
                            ><?= sanitize($prod['descricao'] ?? '') ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                                    Preço (R$) *
                                </label>
                                <input
                                    type="text"
                                    name="preco"
                                    value="<?= sanitize(number_format((float)$prod['preco'], 2, ',', '')) ?>"
                                    class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 text-sm"
                                >
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                                    Categoria
                                </label>
                                <input
                                    type="text"
                                    name="categoria"
                                    value="<?= sanitize($prod['categoria'] ?? '') ?>"
                                    class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 text-sm"
                                    placeholder="Ex: Camisetas, Serviços..."
                                >
                            </div>
                            <div class="flex flex-col justify-center gap-1">
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                    <input type="checkbox" name="ativo" value="1" <?= ($prod['ativo'] ?? 1) ? 'checked' : '' ?>>
                                    Ativo
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                    <input type="checkbox" name="destaque" value="1" <?= ($prod['destaque'] ?? 0) ? 'checked' : '' ?>>
                                    Destaque
                                </label>
                            </div>
                        </div>

                        <div>
                            <label for="sizePreset" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                                Tamanhos disponíveis
                            </label>
                            <select id="sizePreset" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 text-sm">
                                <option value="">Selecione o tipo de tamanho</option>
                                <option value="roupa">Roupas – P, M, G, GG</option>
                                <option value="calcado">Calçados – 37 ao 45</option>
                                <option value="custom">Personalizado</option>
                            </select>
                            <div id="sizeOptions" class="size-options mt-2"></div>
                            <input type="hidden" name="sizes" id="sizesHidden" value="<?= sanitize($prod['sizes'] ?? '') ?>">
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                                Use para marcar rapidamente quais tamanhos estão disponíveis neste produto.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between mt-4">
                    <a href="<?= BASE_URL ?>/products.php<?= $backQuery ? ('?' . $backQuery) : '' ?>"
                       class="text-sm text-slate-600 dark:text-slate-300 hover:underline">
                        Voltar
                    </a>
                    <button type="submit"
                            class="px-6 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- SIDEBAR CATEGORIAS (igual ao site) -->
            <aside class="lg:col-span-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 space-y-4 h-fit lg:sticky lg:top-4">
                <div class="space-y-2">
                    <p class="text-sm text-slate-600 dark:text-slate-300 font-semibold">Categorias</p>

                    <a
                        href="<?= BASE_URL ?>/products.php?<?= http_build_query(['q' => $q, 'categoria' => '', 'page' => 1, 'per_page' => $perPage]) ?>"
                        class="block px-3 py-2 rounded-lg border <?= $categoria === '' ? 'bg-indigo-600 text-white border-transparent' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600' ?>"
                    >
                        Todas
                    </a>

                    <?php foreach ($categories as $cat): ?>
                        <a
                            href="<?= BASE_URL ?>/products.php?<?= http_build_query(['q' => $q, 'categoria' => $cat, 'page' => 1, 'per_page' => $perPage]) ?>"
                            class="block px-3 py-2 rounded-lg border <?= $categoria === $cat ? 'bg-indigo-600 text-white border-transparent' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600' ?>"
                        >
                            <?= sanitize($cat) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="pt-4 border-t border-slate-200 dark:border-slate-800 space-y-3">
                    <p class="text-sm text-slate-600 dark:text-slate-300 font-semibold">Buscar</p>

                    <form method="GET" class="space-y-2">
                        <input type="hidden" name="categoria" value="<?= sanitize($categoria) ?>">
                        <input type="hidden" name="page" value="1">

                        <input
                            name="q"
                            value="<?= sanitize($q) ?>"
                            placeholder="Buscar por nome..."
                            class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
                        >

                        <div class="flex items-center gap-2">
                            <select name="per_page" class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm">
                                <option value="30" <?= $perPage === 30 ? 'selected' : '' ?>>30 / página</option>
                                <option value="60" <?= $perPage === 60 ? 'selected' : '' ?>>60 / página</option>
                            </select>
                            <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                                Aplicar
                            </button>
                        </div>

                        <?php if ($q !== '' || $categoria !== ''): ?>
                            <a
                                href="<?= BASE_URL ?>/products.php?<?= http_build_query(['q' => '', 'categoria' => '', 'page' => 1, 'per_page' => $perPage]) ?>"
                                class="block text-center px-4 py-2 rounded-lg border border-slate-200 dark:border-slate-700 text-sm hover:border-slate-300 dark:hover:border-slate-600"
                            >
                                Limpar filtros
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </aside>

            <!-- CONTEÚDO -->
            <div class="lg:col-span-3">
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-lg font-semibold">Lista de produtos</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Total: <?= (int)$totalProducts ?> • Página <?= (int)$page ?> de <?= (int)$totalPages ?>
                            </p>
                        </div>

                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            Mostrando <?= (int)$perPage ?>/página
                        </div>
                    </div>

                    <?php if (empty($products)): ?>
                        <p class="text-sm text-slate-500">Nenhum produto encontrado.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <?php foreach ($products as $p): ?>
                                <?php
                                    $itemQuery = http_build_query([
                                        'q' => $q,
                                        'categoria' => $categoria,
                                        'page' => $page,
                                        'per_page' => $perPage,
                                    ]);
                                ?>
                                <div class="bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden flex flex-col">
                                    <!-- IMAGEM: NÃO CORTA -->
                                    <div class="h-32 w-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden">
                                        <?php if (!empty($p['imagem'])): ?>
                                            <img src="<?= sanitize(image_url($p['imagem'])) ?>" class="h-full w-full object-contain p-2" alt="<?= sanitize($p['nome']) ?>">
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">Sem imagem</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="p-3 flex flex-col gap-2">
                                        <div class="flex-1 space-y-1">
                                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                <?= sanitize($p['categoria'] ?? '') ?>
                                            </p>
                                            <h3 class="text-sm font-semibold">
                                                <?= sanitize($p['nome']) ?>
                                            </h3>
                                            <p class="text-sm font-bold text-emerald-600 dark:text-emerald-400">
                                                <?= format_currency($p['preco']) ?>
                                            </p>

                                            <?php if (!empty($p['sizes'])): ?>
                                                <p class="text-[11px] text-slate-500 mt-1">
                                                    Tamanhos: <?= sanitize($p['sizes']) ?>
                                                </p>
                                            <?php endif; ?>

                                            <div class="flex flex-wrap gap-1 mt-1">
                                                <?php if ($p['ativo']): ?>
                                                    <span class="text-[11px] px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">
                                                        Ativo
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                                                        Inativo
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($p['destaque']): ?>
                                                    <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-100">
                                                        Destaque
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="flex justify-between items-center pt-2 border-t border-slate-200 dark:border-slate-700 mt-2">
                                            <div class="flex gap-3">
                                                <a href="<?= BASE_URL ?>/products.php?action=edit&id=<?= (int)$p['id'] ?>&<?= sanitize($itemQuery) ?>"
                                                   class="text-xs text-indigo-600 hover:underline">
                                                    Editar
                                                </a>
                                                <a href="<?= BASE_URL ?>/product_stock.php?id=<?= (int)$p['id'] ?>"
                                                   class="text-xs text-emerald-600 hover:underline">
                                                    Estoque
                                                </a>
                                            </div>
                                            <a href="<?= BASE_URL ?>/products.php?action=delete&id=<?= (int)$p['id'] ?>&<?= sanitize($itemQuery) ?>"
                                               class="text-xs text-red-600 hover:underline"
                                               onclick="return confirm('Remover este produto?');">
                                                Remover
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- PAGINAÇÃO -->
                        <?php if ($totalPages > 1): ?>
                            <?php
                                $makePageUrl = function(int $p) use ($q, $categoria, $perPage) {
                                    return BASE_URL . '/products.php?' . http_build_query([
                                        'q' => $q,
                                        'categoria' => $categoria,
                                        'per_page' => $perPage,
                                        'page' => $p
                                    ]);
                                };
                            ?>
                            <div class="flex flex-wrap items-center justify-center gap-2 mt-6">
                                <a href="<?= $makePageUrl(max(1, $page-1)) ?>"
                                   class="px-3 py-2 rounded border border-slate-200 dark:border-slate-700 <?= $page<=1 ? 'opacity-40 pointer-events-none' : 'hover:border-slate-300 dark:hover:border-slate-600' ?>">
                                    Anterior
                                </a>

                                <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
                                    <a href="<?= $makePageUrl($p) ?>"
                                       class="px-3 py-2 rounded border border-slate-200 dark:border-slate-700 <?= $p===$page ? 'bg-indigo-600 text-white border-transparent' : 'hover:border-slate-300 dark:hover:border-slate-600' ?>">
                                        <?= $p ?>
                                    </a>
                                <?php endfor; ?>

                                <a href="<?= $makePageUrl(min($totalPages, $page+1)) ?>"
                                   class="px-3 py-2 rounded border border-slate-200 dark:border-slate-700 <?= $page>=$totalPages ? 'opacity-40 pointer-events-none' : 'hover:border-slate-300 dark:hover:border-slate-600' ?>">
                                    Próxima
                                </a>
                            </div>

                            <p class="text-center text-xs text-slate-500 dark:text-slate-400 mt-2">
                                Página <?= (int)$page ?> de <?= (int)$totalPages ?> — <?= (int)$totalProducts ?> produtos
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const sizePreset = document.getElementById('sizePreset');
        const sizeOptions = document.getElementById('sizeOptions');
        const sizesHidden = document.getElementById('sizesHidden');

        if (!sizePreset || !sizeOptions || !sizesHidden) return;

        function updateHidden() {
            const checked = sizeOptions.querySelectorAll('input[type="checkbox"]:checked');
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

<?php include __DIR__ . '/views/partials/footer.php'; ?>
