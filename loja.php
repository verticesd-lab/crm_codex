<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// Garante sessão (caso não tenha sido iniciada ainda)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// slug da empresa: via GET ou sessão
$slug = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');

if (!$slug) {
    echo 'Empresa não informada.';
    exit;
}

$pdo = get_pdo();

// Carrega empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$stmt->execute([$slug]);
$company = $stmt->fetch();

if (!$company) {
    echo 'Empresa não encontrada.';
    exit;
}

// Filtros
$search    = trim($_GET['q'] ?? '');
$categoria = trim($_GET['categoria'] ?? '');

$where  = 'company_id = ? AND ativo = 1';
$params = [$company['id']];

if ($search !== '') {
    $where .= ' AND nome LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($categoria !== '') {
    $where .= ' AND categoria = ?';
    $params[] = $categoria;
}

// ==============================
// PAGINAÇÃO
// ==============================
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 30);
$perPage = in_array($perPage, [30, 50], true) ? $perPage : 30;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $where");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalProducts / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

// Produtos (paginados)
$productsStmt = $pdo->prepare("
  SELECT * FROM products
  WHERE $where
  ORDER BY destaque DESC, created_at DESC
  LIMIT $perPage OFFSET $offset
");
$productsStmt->execute($params);
$products = $productsStmt->fetchAll();

// Destaques (mantém como estava)
$featuredStmt = $pdo->prepare('
    SELECT * FROM products
    WHERE company_id = ? AND ativo = 1 AND destaque = 1
    ORDER BY updated_at DESC
    LIMIT 5
');
$featuredStmt->execute([$company['id']]);
$featured = $featuredStmt->fetchAll();

// Categorias
$categoriesStmt = $pdo->prepare('
    SELECT DISTINCT categoria
    FROM products
    WHERE company_id = ? AND ativo = 1 AND categoria IS NOT NULL AND categoria <> ""
    ORDER BY categoria
');
$categoriesStmt->execute([$company['id']]);
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Carrinho por empresa
$cartKey = 'cart_' . $company['slug'];
if (!isset($_SESSION[$cartKey])) {
    $_SESSION[$cartKey] = [];
}

// Adicionar ao carrinho
if (isset($_GET['add'])) {
    $productId = (int)$_GET['add'];
    $_SESSION[$cartKey][$productId] = ($_SESSION[$cartKey][$productId] ?? 0) + 1;
    redirect('loja.php?empresa=' . urlencode($slug));
}

// WhatsApp fixo (limpa número)
$whats = preg_replace('/\D+/', '', (string)($company['whatsapp_principal'] ?? ''));
$msg   = 'Olá, vim da loja online!';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">

    <title>Loja - <?= sanitize($company['nome_fantasia']) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Space Grotesk"', 'Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: {
                            500: '#7c3aed',
                            600: '#6d28d9',
                            700: '#5b21b6',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-950 text-white min-h-screen">
    <div class="absolute inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-24 -left-10 h-80 w-80 bg-brand-600/30 rounded-full blur-3xl"></div>
        <div class="absolute top-20 right-0 h-96 w-96 bg-emerald-500/20 rounded-full blur-3xl"></div>
    </div>

    <div class="max-w-6xl mx-auto px-4 py-8 space-y-10">
        <header class="flex flex-col gap-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <?php if (!empty($company['logo'])): ?>
                        <img src="<?= sanitize(image_url($company['logo'])) ?>" class="h-14 w-14 rounded-full border border-white/20 object-cover">
                    <?php else: ?>
                        <div class="h-14 w-14 rounded-full bg-brand-600 flex items-center justify-center text-lg font-semibold">
                            <?= strtoupper(substr($company['nome_fantasia'],0,2)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <p class="text-sm text-slate-200/70">Catálogo oficial</p>
                        <h1 class="text-3xl font-bold tracking-tight"><?= sanitize($company['nome_fantasia']) ?></h1>
                        <?php if (!empty($company['instagram_usuario'])): ?>
                            <a class="text-sm text-sky-300 hover:underline"
                               target="_blank" rel="noopener"
                               href="https://instagram.com/<?= ltrim(sanitize($company['instagram_usuario']), '@') ?>">
                                Instagram
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>/checkout.php?empresa=<?= urlencode($slug) ?>"
                   class="inline-flex items-center gap-2 bg-emerald-500 text-slate-900 px-4 py-2 rounded-full shadow-lg shadow-emerald-500/30 hover:bg-emerald-400">
                    Carrinho (<?= array_sum($_SESSION[$cartKey]) ?>)
                </a>
            </div>

            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden shadow-xl">
                <div class="absolute inset-0 bg-gradient-to-r from-brand-600/30 via-indigo-600/20 to-emerald-500/20 blur-3xl"></div>
                <div class="relative p-6 flex flex-col md:flex-row gap-6">
                    <div class="flex-1 space-y-3">
                        <p class="text-sm text-emerald-200/80 uppercase tracking-wide">Destaques</p>
                        <h2 class="text-2xl font-bold">Seleção especial da semana</h2>
                        <p class="text-slate-200/80">
                            Confira os produtos em destaque e aproveite as melhores condições direto pelo WhatsApp.
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <a class="px-5 py-2 rounded-full bg-brand-600 hover:bg-brand-700 font-semibold shadow-lg shadow-brand-600/30" href="#featured">
                                Ver destaques
                            </a>
                            <a class="px-5 py-2 rounded-full border border-white/20 hover:border-white/40"
                               href="<?= BASE_URL ?>/promo.php?empresa=<?= urlencode($slug) ?>">
                                Promoções
                            </a>
                        </div>
                    </div>
                    <div class="w-full md:w-80">
                        <div class="relative h-48 rounded-xl border border-white/10 overflow-hidden" id="hero-carousel">
                            <?php foreach ($featured ?: array_slice($products, 0, 3) as $idx => $item): ?>
                                <div data-slide
                                     class="absolute inset-0 <?= $idx === 0 ? 'opacity-100' : 'opacity-0' ?> transition-opacity duration-700 ease-in-out bg-white/10 backdrop-blur flex flex-col justify-center p-4">
                                    <p class="text-xs uppercase tracking-wide text-emerald-200/80">
                                        <?= sanitize($item['categoria']) ?>
                                    </p>
                                    <h3 class="text-xl font-semibold"><?= sanitize($item['nome']) ?></h3>
                                    <p class="text-sm text-slate-200/80 line-clamp-2"><?= sanitize($item['descricao']) ?></p>
                                    <p class="mt-2 text-lg font-bold text-emerald-300"><?= format_currency($item['preco']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
<<<<<<< HEAD
            <!-- sticky só no desktop -->
=======

            <!-- ✅ CORREÇÃO AQUI: sticky só no desktop (lg) -->
>>>>>>> f3837e5 (Fix timezone display (dashboard))
            <aside class="lg:col-span-1 bg-white/5 border border-white/10 rounded-2xl p-4 space-y-3 h-fit lg:sticky lg:top-4">
                <p class="text-sm text-slate-200/80 font-semibold">Categorias</p>
                <div class="flex flex-col gap-2">
                    <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
                       class="px-3 py-2 rounded-lg border border-white/10 <?= $categoria === '' ? 'bg-brand-600 text-white' : 'hover:border-white/30' ?>">
                        Todas
                    </a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>&categoria=<?= urlencode($cat) ?>"
                           class="px-3 py-2 rounded-lg border border-white/10 <?= $categoria === $cat ? 'bg-brand-600 text-white' : 'hover:border-white/30' ?>">
                            <?= sanitize($cat) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Atendimento (WhatsApp sai daqui; fica fixo no canto) -->
                <div class="pt-4 border-t border-white/10 space-y-2">
                    <p class="text-sm text-slate-200/80 font-semibold">Atendimento</p>

                    <?php if (!empty($company['instagram_usuario'])): ?>
                        <a class="inline-flex items-center gap-2 px-3 py-2 rounded-full border border-white/20 hover:border-white/40"
                           target="_blank" rel="noopener"
                           href="https://instagram.com/<?= ltrim(sanitize($company['instagram_usuario']), '@') ?>">
                            Instagram
                        </a>
                    <?php endif; ?>
                </div>
            </aside>

            <div class="lg:col-span-3 space-y-6">
                <div class="bg-white/5 border border-white/10 rounded-2xl p-4 backdrop-blur">
                    <form class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <input type="hidden" name="empresa" value="<?= sanitize($slug) ?>">
                        <input type="hidden" name="page" value="1">
                        <input name="q" value="<?= sanitize($search) ?>" placeholder="Buscar produto"
                               class="rounded-lg border border-white/10 bg-white/5 text-white px-3 py-2 placeholder:text-slate-300">
                        <input name="categoria" value="<?= sanitize($categoria) ?>" placeholder="Categoria"
                               class="rounded-lg border border-white/10 bg-white/5 text-white px-3 py-2 placeholder:text-slate-300">

                        <div class="grid grid-cols-2 gap-3">
                            <select name="per_page" class="rounded-lg border border-white/10 bg-white/5 text-white px-3 py-2">
                                <option value="30" <?= $perPage === 30 ? 'selected' : '' ?>>30 / página</option>
                                <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 / página</option>
                            </select>
                            <button class="rounded-lg px-4 py-2 bg-brand-600 hover:bg-brand-700 font-semibold">Filtrar</button>
                        </div>
                    </form>

                    <p class="mt-3 text-sm text-slate-200/80">
                        Itens disponíveis agora. Adicione ao carrinho e finalize pelo WhatsApp.
                    </p>
                </div>

                <!-- DESTAQUES -->
                <section id="featured" class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-semibold">Destaques</h2>
                        <p class="text-sm text-slate-200/70">Curadoria para você decidir rápido</p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php foreach ($featured ?: array_slice($products, 0, 3) as $product): ?>
                            <div class="group bg-white/5 border border-white/10 rounded-xl shadow-lg hover:-translate-y-1 transition transform overflow-hidden">
                                <a href="<?= BASE_URL ?>/produto.php?empresa=<?= urlencode($slug) ?>&id=<?= (int)$product['id'] ?>" class="block">
                                    <?php if (!empty($product['imagem'])): ?>
                                        <!-- ✅ imagem sem cortar -->
                                        <div class="h-44 w-full bg-white/5 flex items-center justify-center">
                                            <img src="<?= sanitize(image_url($product['imagem'])) ?>"
                                                 alt="<?= sanitize($product['nome']) ?>"
                                                 class="h-full w-full object-contain p-2 group-hover:scale-105 transition">
                                        </div>
                                    <?php else: ?>
                                        <div class="h-44 w-full bg-white/10 flex items-center justify-center text-slate-200/70">Sem imagem</div>
                                    <?php endif; ?>

                                    <div class="p-4 space-y-2">
                                        <p class="text-xs uppercase tracking-wide text-emerald-200/80">
                                            <?= sanitize($product['categoria']) ?>
                                        </p>
                                        <h3 class="text-lg font-semibold"><?= sanitize($product['nome']) ?></h3>
                                        <p class="text-sm text-slate-200/80 line-clamp-2"><?= sanitize($product['descricao']) ?></p>
                                        <div class="flex items-center justify-between">
                                            <p class="text-xl font-bold text-emerald-300"><?= format_currency($product['preco']) ?></p>
                                            <span class="text-xs px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-100">Destaque</span>
                                        </div>
                                    </div>
                                </a>

<<<<<<< HEAD
                                <a href="<?= BASE_URL ?>/loja.php?<?= http_build_query([
                                    'empresa' => $slug,
                                    'q' => $search,
                                    'categoria' => $categoria,
                                    'page' => $page,
                                    'per_page' => $perPage,
                                    'add' => (int)$product['id']
                                ]) ?>"
=======
                                <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>&add=<?= (int)$product['id'] ?>"
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                                   class="inline-flex items-center justify-center w-full bg-brand-600 text-white px-4 py-2 rounded-lg hover:bg-brand-700 font-semibold">
                                    Adicionar ao carrinho
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($featured) && empty($products)): ?>
                            <p class="text-sm text-slate-200">Nenhum produto ativo.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- LISTA GERAL -->
                <section class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-semibold">Produtos</h2>
                        <?php if ($categoria): ?>
                            <p class="text-sm text-slate-200/70">Filtrando por: <?= sanitize($categoria) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php foreach ($products as $product): ?>
                            <div class="group bg-white/5 border border-white/10 rounded-xl shadow-lg hover:-translate-y-1 transition transform overflow-hidden">
                                <a href="<?= BASE_URL ?>/produto.php?empresa=<?= urlencode($slug) ?>&id=<?= (int)$product['id'] ?>" class="block">
                                    <?php if (!empty($product['imagem'])): ?>
                                        <!-- ✅ imagem sem cortar -->
                                        <div class="h-44 w-full bg-white/5 flex items-center justify-center">
                                            <img src="<?= sanitize(image_url($product['imagem'])) ?>"
                                                 alt="<?= sanitize($product['nome']) ?>"
                                                 class="h-full w-full object-contain p-2 group-hover:scale-105 transition">
                                        </div>
                                    <?php else: ?>
                                        <div class="h-44 w-full bg-white/10 flex items-center justify-center text-slate-200/70">Sem imagem</div>
                                    <?php endif; ?>

                                    <div class="p-4 space-y-2">
                                        <p class="text-xs uppercase tracking-wide text-emerald-200/80">
                                            <?= sanitize($product['categoria']) ?>
                                        </p>
                                        <h3 class="text-lg font-semibold"><?= sanitize($product['nome']) ?></h3>
                                        <p class="text-sm text-slate-200/80 line-clamp-2"><?= sanitize($product['descricao']) ?></p>
                                        <div class="flex items-center justify-between">
                                            <p class="text-xl font-bold text-emerald-300"><?= format_currency($product['preco']) ?></p>
                                            <span class="text-xs px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-100">Disponível</span>
                                        </div>
                                    </div>
                                </a>

<<<<<<< HEAD
                                <a href="<?= BASE_URL ?>/loja.php?<?= http_build_query([
                                    'empresa' => $slug,
                                    'q' => $search,
                                    'categoria' => $categoria,
                                    'page' => $page,
                                    'per_page' => $perPage,
                                    'add' => (int)$product['id']
                                ]) ?>"
=======
                                <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>&add=<?= (int)$product['id'] ?>"
>>>>>>> f3837e5 (Fix timezone display (dashboard))
                                   class="inline-flex items-center justify-center w-full bg-brand-600 text-white px-4 py-2 rounded-lg hover:bg-brand-700 font-semibold">
                                    Adicionar ao carrinho
                                </a>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($products)): ?>
                            <p class="text-sm text-slate-200">Nenhum produto ativo.</p>
                        <?php endif; ?>
                    </div>

                    <!-- ✅ Paginação -->
                    <?php
                    $queryBase = [
                        'empresa' => $slug,
                        'q' => $search,
                        'categoria' => $categoria,
                        'per_page' => $perPage
                    ];
                    $makeUrl = function($p) use ($queryBase) {
                        return BASE_URL . '/loja.php?' . http_build_query(array_merge($queryBase, ['page' => $p]));
                    };
                    ?>

                    <?php if ($totalPages > 1): ?>
                        <div class="flex flex-wrap items-center justify-center gap-2 pt-6">
                            <a href="<?= $makeUrl(max(1, $page - 1)) ?>"
                               class="px-4 py-2 rounded-full border border-white/15 hover:border-white/30 <?= $page <= 1 ? 'opacity-40 pointer-events-none' : '' ?>">
                                Anterior
                            </a>

                            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                <a href="<?= $makeUrl($p) ?>"
                                   class="px-4 py-2 rounded-full border border-white/15 hover:border-white/30 <?= $p === $page ? 'bg-brand-600 text-white border-transparent' : '' ?>">
                                    <?= $p ?>
                                </a>
                            <?php endfor; ?>

                            <a href="<?= $makeUrl(min($totalPages, $page + 1)) ?>"
                               class="px-4 py-2 rounded-full border border-white/15 hover:border-white/30 <?= $page >= $totalPages ? 'opacity-40 pointer-events-none' : '' ?>">
                                Próxima
                            </a>
                        </div>

                        <p class="text-center text-xs text-slate-200/60 pt-3">
                            Mostrando página <?= (int)$page ?> de <?= (int)$totalPages ?> — <?= (int)$totalProducts ?> produtos
                        </p>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>

    <!-- ✅ BOTÃO WHATSAPP FIXO -->
    <?php if ($whats): ?>
        <a
            href="https://wa.me/<?= $whats ?>?text=<?= urlencode($msg) ?>"
            target="_blank"
            rel="noopener"
            class="
                fixed bottom-5 right-5 z-50
                flex items-center gap-2
                bg-emerald-500 hover:bg-emerald-400
                text-slate-900 font-semibold
                px-5 py-3 rounded-full
                shadow-lg shadow-emerald-500/40
            "
        >
            WhatsApp
        </a>
    <?php endif; ?>

    <script>
        const slides = document.querySelectorAll('[data-slide]');
        let idx = 0;
        if (slides.length > 1) {
            setInterval(() => {
                slides[idx].classList.add('opacity-0');
                slides[idx].classList.remove('opacity-100');
                idx = (idx + 1) % slides.length;
                slides[idx].classList.remove('opacity-0');
                slides[idx].classList.add('opacity-100');
            }, 3500);
        }
    </script>
</body>
</html>
