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

$companyId = (int)$company['id'];

/**
 * =====================================================
 * ✅ TRACKING (Site Analytics)
 * =====================================================
 */
$siteTrackerPath = __DIR__ . '/site_analytics.php';
if (file_exists($siteTrackerPath)) {
    require_once $siteTrackerPath;
    if (function_exists('track_site_visit')) {
        $pagePath = parse_url($_SERVER['REQUEST_URI'] ?? '/loja.php', PHP_URL_PATH) ?: '/loja.php';
        track_site_visit($companyId, $pagePath);
    }
}

// Filtros
$search    = trim($_GET['q'] ?? '');
$categoria = trim($_GET['categoria'] ?? '');

$where  = 'company_id = ? AND ativo = 1';
$params = [$companyId];

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

// Destaques
$featuredStmt = $pdo->prepare('
    SELECT * FROM products
    WHERE company_id = ? AND ativo = 1 AND destaque = 1
    ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
    LIMIT 3
');
$featuredStmt->execute([$companyId]);
$featured = $featuredStmt->fetchAll();

// Categorias
$categoriesStmt = $pdo->prepare('
    SELECT DISTINCT categoria
    FROM products
    WHERE company_id = ? AND ativo = 1 AND categoria IS NOT NULL AND categoria <> ""
    ORDER BY categoria
');
$categoriesStmt->execute([$companyId]);
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

    $backUrl = BASE_URL . '/loja.php?' . http_build_query([
        'empresa'   => $slug,
        'q'         => $search,
        'categoria' => $categoria,
        'page'      => $page,
        'per_page'  => $perPage,
    ]);

    redirect($backUrl);
}

// WhatsApp fixo
$whats = preg_replace('/\D+/', '', (string)($company['whatsapp_principal'] ?? ''));
$msg   = 'Olá, vim da loja online!';
$offersUrl = BASE_URL . '/ofertas.php?empresa=' . urlencode($slug);

/**
 * Resolve mídias públicas da loja sem alterar assets ou rotas da aplicação.
 * MEDIA_BASE_URL é usada apenas para caminhos normalizados em /uploads/.
 */
function store_public_media_url(?string $path): string {
    $url = image_url($path);
    $mediaBaseUrl = trim((string)(getenv('MEDIA_BASE_URL') ?: ''));

    if ($url === '' || $mediaBaseUrl === '' || !str_starts_with($url, '/uploads/')) {
        return $url;
    }

    $baseParts = parse_url($mediaBaseUrl);
    if (!is_array($baseParts)) {
        return $url;
    }

    $scheme = strtolower((string)($baseParts['scheme'] ?? ''));
    if (
        !in_array($scheme, ['http', 'https'], true)
        || empty($baseParts['host'])
        || isset($baseParts['user'])
        || isset($baseParts['pass'])
        || isset($baseParts['query'])
        || isset($baseParts['fragment'])
    ) {
        return $url;
    }

    return rtrim($mediaBaseUrl, '/') . $url;
}

/**
 * Helper: renderiza os chips de tamanho a partir da string "P,M,G,GG"
 * Retorna HTML pronto ou string vazia se não houver tamanhos.
 */
function render_sizes(string $sizesStr): string {
    $sizes = array_filter(array_map('trim', explode(',', $sizesStr)));
    if (empty($sizes)) return '';

    $html = '<div class="sizes-row">';
    foreach ($sizes as $sz) {
        $html .= '<span class="sz-pill">' . htmlspecialchars($sz, ENT_QUOTES) . '</span>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
    <title>Loja - <?= sanitize($company['nome_fantasia']) ?></title>
    <meta name="description" content="Catálogo oficial de <?= sanitize($company['nome_fantasia']) ?>. Confira produtos, ofertas e finalize seu atendimento pelo WhatsApp.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/loja-v2.css">
    <script src="<?= BASE_URL ?>/assets/js/loja-v2.js" defer></script>
</head>
<body class="store-body">
    <div class="store-announcement">Catálogo oficial • Atendimento rápido pelo WhatsApp</div>

    <header class="store-header">
        <div class="store-container store-header-main">
            <a class="store-brand" href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>" aria-label="Página inicial de <?= sanitize($company['nome_fantasia']) ?>">
                <?php if (!empty($company['logo'])): ?>
                    <img src="<?= sanitize(store_public_media_url($company['logo'])) ?>" class="store-brand-logo" alt="Logo de <?= sanitize($company['nome_fantasia']) ?>">
                <?php else: ?>
                    <span class="store-brand-fallback" aria-hidden="true"><?= strtoupper(substr($company['nome_fantasia'], 0, 2)) ?></span>
                <?php endif; ?>
                <span class="store-brand-copy">
                    <span class="store-brand-kicker">Catálogo oficial</span>
                    <span class="store-brand-name"><?= sanitize($company['nome_fantasia']) ?></span>
                </span>
            </a>

            <nav class="store-nav" aria-label="Navegação principal">
                <a class="store-nav-link" href="#featured">Destaques</a>
                <a class="store-nav-link" href="#products">Produtos</a>
                <a class="store-nav-link is-promo" href="<?= $offersUrl ?>">Ofertas</a>
                <a class="store-nav-link" href="<?= BASE_URL ?>/promo.php?empresa=<?= urlencode($slug) ?>">Promoções</a>
            </nav>

            <div class="store-header-actions">
                <?php if (!empty($company['instagram_usuario'])): ?>
                    <a class="store-instagram" target="_blank" rel="noopener" href="https://instagram.com/<?= ltrim(sanitize($company['instagram_usuario']), '@') ?>" aria-label="Instagram">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>
                    </a>
                <?php endif; ?>
                <button class="store-menu-toggle" id="store-menu-toggle" type="button" aria-controls="store-mobile-menu" aria-expanded="false" aria-label="Abrir menu">
                    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <a class="store-cart" href="<?= BASE_URL ?>/checkout.php?empresa=<?= urlencode($slug) ?>">
                    <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
                    Carrinho
                    <span class="store-cart-count"><?= (int)array_sum($_SESSION[$cartKey]) ?></span>
                </a>
            </div>
        </div>
        <nav class="store-container store-mobile-menu" id="store-mobile-menu" aria-label="Navegação móvel">
            <a class="store-nav-link" href="#featured">Destaques</a>
            <a class="store-nav-link" href="#products">Produtos</a>
            <a class="store-nav-link is-promo" href="<?= $offersUrl ?>">Ofertas</a>
            <a class="store-nav-link" href="<?= BASE_URL ?>/promo.php?empresa=<?= urlencode($slug) ?>">Promoções</a>
            <?php if (!empty($company['instagram_usuario'])): ?>
                <a class="store-nav-link" target="_blank" rel="noopener" href="https://instagram.com/<?= ltrim(sanitize($company['instagram_usuario']), '@') ?>">Instagram</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="store-main">
        <div class="store-container">
            <section class="store-hero" aria-labelledby="store-hero-title">
                <div class="store-hero-copy">
                    <span class="store-eyebrow">Novidades e destaques</span>
                    <h1 id="store-hero-title">Seu próximo favorito <span>está aqui.</span></h1>
                    <p class="store-hero-description">Descubra a seleção de <?= sanitize($company['nome_fantasia']) ?>, escolha seus produtos e finalize com atendimento direto e personalizado.</p>
                    <div class="store-hero-actions">
                        <a class="store-button store-button-primary" href="#featured">
                            Ver destaques
                            <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </a>
                        <a class="store-button store-button-amber" href="<?= $offersUrl ?>">
                            Conferir ofertas
                            <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41 11 3.83V3H4v7h.83l9.58 9.59a2 2 0 0 0 2.82 0l3.36-3.36a2 2 0 0 0 0-2.82Z"/><circle cx="7.5" cy="6.5" r="1"/></svg>
                        </a>
                    </div>
                    <div class="store-trust-list" aria-label="Vantagens da loja">
                        <span class="store-trust-item"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m5 12 4 4L19 6"/></svg> Produtos selecionados</span>
                        <span class="store-trust-item"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m5 12 4 4L19 6"/></svg> Atendimento humano</span>
                        <span class="store-trust-item"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m5 12 4 4L19 6"/></svg> Finalização via WhatsApp</span>
                    </div>
                </div>

                <div class="store-hero-showcase">
                    <?php $itensCarrossel = $featured ?: array_slice($products, 0, 3); ?>
                    <div id="hero-carousel" aria-label="Produtos em destaque">
                        <?php foreach ($itensCarrossel as $idx => $item): ?>
                            <a href="<?= BASE_URL ?>/produto.php?empresa=<?= urlencode($slug) ?>&id=<?= (int)$item['id'] ?>" data-slide class="store-slide <?= $idx === 0 ? 'is-active' : '' ?>" aria-hidden="<?= $idx === 0 ? 'false' : 'true' ?>">
                                <div class="store-slide-media">
                                    <span class="store-slide-badge"><?= sanitize($item['categoria']) ?></span>
                                    <?php if (!empty($item['imagem'])): ?>
                                        <img src="<?= sanitize(store_public_media_url($item['imagem'])) ?>" alt="<?= sanitize($item['nome']) ?>" <?= $idx === 0 ? '' : 'loading="lazy"' ?>>
                                    <?php else: ?>
                                        <div class="store-slide-placeholder" aria-hidden="true">◇</div>
                                    <?php endif; ?>
                                </div>
                                <div class="store-slide-content">
                                    <h2><?= sanitize($item['nome']) ?></h2>
                                    <div class="store-slide-meta">
                                        <span class="store-slide-price"><?= format_currency($item['preco']) ?></span>
                                        <span class="store-slide-link">Ver produto →</span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>

                        <?php if (empty($itensCarrossel)): ?>
                            <div class="store-carousel-empty">
                                <strong>Novidades chegando</strong>
                                <span>Explore as categorias ou fale com nosso atendimento.</span>
                            </div>
                        <?php endif; ?>

                        <?php if (count($itensCarrossel) > 1): ?>
                            <div id="hero-dots" aria-label="Selecionar produto do destaque">
                                <?php foreach ($itensCarrossel as $di => $_): ?>
                                    <button class="store-carousel-dot <?= $di === 0 ? 'is-active' : '' ?>" type="button" aria-label="Mostrar destaque <?= $di + 1 ?>" aria-current="<?= $di === 0 ? 'true' : 'false' ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="store-benefits" aria-label="Informações comerciais">
            <div class="store-benefit">
                <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                <div><strong>Catálogo oficial</strong><span>Produtos ativos e atualizados</span></div>
            </div>
            <div class="store-benefit">
                <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/></svg>
                <div><strong>Atendimento direto</strong><span>Tire dúvidas pelo WhatsApp</span></div>
            </div>
            <div class="store-benefit">
                <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7h-9M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></svg>
                <div><strong>Compra simples</strong><span>Escolha, adicione e finalize</span></div>
            </div>
        </div>

        <div class="store-container">
            <section class="store-category-bar" aria-labelledby="categories-title">
                <div class="store-category-head">
                    <div>
                        <span class="store-section-kicker">Encontre mais rápido</span>
                        <h2 class="store-section-title" id="categories-title">Compre por categoria</h2>
                    </div>
                    <p class="store-section-note">Deslize para explorar todas as opções</p>
                </div>
                <div class="store-category-list">
                    <a href="<?= $offersUrl ?>" class="store-category-chip is-offer">
                        <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41 11 3.83V3H4v7h.83l9.58 9.59a2 2 0 0 0 2.82 0l3.36-3.36a2 2 0 0 0 0-2.82Z"/></svg>
                        Ofertas
                    </a>
                    <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>" class="store-category-chip <?= $categoria === '' ? 'is-active' : '' ?>">Todas</a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>&categoria=<?= urlencode($cat) ?>" class="store-category-chip <?= $categoria === $cat ? 'is-active' : '' ?>"><?= sanitize($cat) ?></a>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="store-catalog-layout">
                <aside class="store-filter-panel" aria-labelledby="filter-title">
                    <h2 id="filter-title">Encontre seu produto</h2>
                    <p>Busque pelo nome ou refine pela categoria.</p>
                    <form class="store-filter-form">
                        <input type="hidden" name="empresa" value="<?= sanitize($slug) ?>">
                        <input type="hidden" name="page" value="1">
                        <div class="store-field">
                            <label for="store-search">Produto</label>
                            <input class="store-input" id="store-search" name="q" value="<?= sanitize($search) ?>" placeholder="Ex.: camiseta, tênis...">
                        </div>
                        <div class="store-field">
                            <label for="store-category">Categoria</label>
                            <input class="store-input" id="store-category" name="categoria" value="<?= sanitize($categoria) ?>" placeholder="Digite a categoria">
                        </div>
                        <div class="store-field">
                            <label for="store-per-page">Itens por página</label>
                            <select class="store-select" id="store-per-page" name="per_page">
                                <option value="30" <?= $perPage === 30 ? 'selected' : '' ?>>30 / página</option>
                                <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 / página</option>
                            </select>
                        </div>
                        <button class="store-filter-submit" type="submit">Aplicar filtros</button>
                    </form>
                    <?php if ($search !== '' || $categoria !== ''): ?>
                        <a class="store-filter-reset" href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>">Limpar filtros</a>
                    <?php endif; ?>
                    <div class="store-filter-info"><?= (int)$totalProducts ?> produto<?= $totalProducts === 1 ? '' : 's' ?> encontrado<?= $totalProducts === 1 ? '' : 's' ?>. Finalização diretamente pelo WhatsApp.</div>
                </aside>

                <div class="store-products-column">
                    <section class="store-section" id="featured" aria-labelledby="featured-title">
                        <div class="store-section-head">
                            <div>
                                <span class="store-section-kicker">Seleção especial</span>
                                <h2 class="store-section-title" id="featured-title">Destaques da semana</h2>
                            </div>
                            <p class="store-section-note">Escolhas para decidir mais rápido</p>
                        </div>

                        <div class="store-products-grid">
                            <?php foreach ($featured ?: array_slice($products, 0, 3) as $product): ?>
                                <article class="product-card">
                                    <a href="<?= BASE_URL ?>/produto.php?empresa=<?= urlencode($slug) ?>&id=<?= (int)$product['id'] ?>" class="product-card-link">
                                        <div class="product-card-media">
                                            <span class="product-card-status">Destaque</span>
                                            <?php if (!empty($product['imagem'])): ?>
                                                <img src="<?= sanitize(store_public_media_url($product['imagem'])) ?>" alt="<?= sanitize($product['nome']) ?>" loading="lazy">
                                            <?php else: ?>
                                                <span class="product-card-placeholder">Sem imagem</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-card-body">
                                            <p class="product-card-category"><?= sanitize($product['categoria']) ?></p>
                                            <h3 class="product-card-title"><?= sanitize($product['nome']) ?></h3>
                                            <p class="product-card-description"><?= sanitize($product['descricao']) ?></p>
                                            <?php if (!empty($product['sizes'])): ?>
                                                <div class="sizes-block">
                                                    <span class="sizes-label">Tamanhos disponíveis</span>
                                                    <?= render_sizes((string)$product['sizes']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="product-card-footer">
                                                <span>
                                                    <span class="product-card-price-label">Por</span>
                                                    <span class="product-card-price"><?= format_currency($product['preco']) ?></span>
                                                </span>
                                                <span class="product-card-detail">Ver detalhes →</span>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="<?= BASE_URL ?>/loja.php?<?= http_build_query([
                                        'empresa'   => $slug,
                                        'q'         => $search,
                                        'categoria' => $categoria,
                                        'page'      => $page,
                                        'per_page'  => $perPage,
                                        'add'       => (int)$product['id']
                                    ]) ?>" class="product-card-cta">
                                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                        Adicionar ao carrinho
                                    </a>
                                </article>
                            <?php endforeach; ?>

                            <?php if (empty($featured) && empty($products)): ?>
                                <div class="store-empty"><strong>Nenhum destaque disponível.</strong><span>Explore as categorias ou fale com nosso atendimento.</span></div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="store-section" id="products" aria-labelledby="products-title">
                        <div class="store-section-head">
                            <div>
                                <span class="store-section-kicker">Catálogo completo</span>
                                <h2 class="store-section-title" id="products-title">Todos os produtos</h2>
                            </div>
                            <?php if ($categoria): ?>
                                <p class="store-section-note">Filtrando por: <?= sanitize($categoria) ?></p>
                            <?php else: ?>
                                <p class="store-section-note"><?= (int)$totalProducts ?> itens disponíveis</p>
                            <?php endif; ?>
                        </div>

                        <div class="store-products-grid">
                            <?php foreach ($products as $product): ?>
                                <article class="product-card">
                                    <a href="<?= BASE_URL ?>/produto.php?empresa=<?= urlencode($slug) ?>&id=<?= (int)$product['id'] ?>" class="product-card-link">
                                        <div class="product-card-media">
                                            <span class="product-card-status">Disponível</span>
                                            <?php if (!empty($product['imagem'])): ?>
                                                <img src="<?= sanitize(store_public_media_url($product['imagem'])) ?>" alt="<?= sanitize($product['nome']) ?>" loading="lazy">
                                            <?php else: ?>
                                                <span class="product-card-placeholder">Sem imagem</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-card-body">
                                            <p class="product-card-category"><?= sanitize($product['categoria']) ?></p>
                                            <h3 class="product-card-title"><?= sanitize($product['nome']) ?></h3>
                                            <p class="product-card-description"><?= sanitize($product['descricao']) ?></p>
                                            <?php if (!empty($product['sizes'])): ?>
                                                <div class="sizes-block">
                                                    <span class="sizes-label">Tamanhos disponíveis</span>
                                                    <?= render_sizes((string)$product['sizes']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="product-card-footer">
                                                <span>
                                                    <span class="product-card-price-label">Por</span>
                                                    <span class="product-card-price"><?= format_currency($product['preco']) ?></span>
                                                </span>
                                                <span class="product-card-detail">Ver detalhes →</span>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="<?= BASE_URL ?>/loja.php?<?= http_build_query([
                                        'empresa'   => $slug,
                                        'q'         => $search,
                                        'categoria' => $categoria,
                                        'page'      => $page,
                                        'per_page'  => $perPage,
                                        'add'       => (int)$product['id']
                                    ]) ?>" class="product-card-cta">
                                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                        Adicionar ao carrinho
                                    </a>
                                </article>
                            <?php endforeach; ?>

                            <?php if (empty($products)): ?>
                                <div class="store-empty"><strong>Nenhum produto encontrado.</strong><span>Tente ajustar sua busca ou escolher outra categoria.</span></div>
                            <?php endif; ?>
                        </div>

                        <?php
                        $queryBase = [
                            'empresa'   => $slug,
                            'q'         => $search,
                            'categoria' => $categoria,
                            'per_page'  => $perPage
                        ];
                        $makeUrl = function ($p) use ($queryBase) {
                            return BASE_URL . '/loja.php?' . http_build_query(array_merge($queryBase, ['page' => $p]));
                        };
                        ?>

                        <?php if ($totalPages > 1): ?>
                            <nav class="store-pagination" aria-label="Paginação dos produtos">
                                <a href="<?= $makeUrl(max(1, $page - 1)) ?>" class="store-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>">Anterior</a>
                                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                    <a href="<?= $makeUrl($p) ?>" class="store-page-link <?= $p === $page ? 'is-active' : '' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= (int)$p ?></a>
                                <?php endfor; ?>
                                <a href="<?= $makeUrl(min($totalPages, $page + 1)) ?>" class="store-page-link <?= $page >= $totalPages ? 'is-disabled' : '' ?>">Próxima</a>
                            </nav>
                            <p class="store-page-summary">Mostrando página <?= (int)$page ?> de <?= (int)$totalPages ?> — <?= (int)$totalProducts ?> produtos</p>
                        <?php endif; ?>
                    </section>
                </div>
            </div>

            <section class="store-commercial-cta" aria-labelledby="commercial-cta-title">
                <div>
                    <span class="store-section-kicker">Precisa de ajuda?</span>
                    <h2 id="commercial-cta-title">Fale com quem entende dos produtos.</h2>
                    <p>Nosso atendimento ajuda você a escolher e concluir seu pedido.</p>
                </div>
                <div class="store-commercial-actions">
                    <?php if ($whats): ?>
                        <a class="store-button store-button-primary" href="https://wa.me/<?= $whats ?>?text=<?= urlencode($msg) ?>" target="_blank" rel="noopener">Chamar no WhatsApp</a>
                    <?php endif; ?>
                    <a class="store-button store-button-secondary" href="<?= $offersUrl ?>">Ver ofertas</a>
                </div>
            </section>
        </div>
    </main>

    <footer class="store-footer">
        <div class="store-container store-footer-inner">
            <div>
                <div class="store-footer-brand"><?= sanitize($company['nome_fantasia']) ?></div>
                <p class="store-footer-copy">Catálogo oficial • <?= date('Y') ?></p>
            </div>
            <nav class="store-footer-links" aria-label="Links do rodapé">
                <a href="#featured">Destaques</a>
                <a href="#products">Produtos</a>
                <a href="<?= $offersUrl ?>">Ofertas</a>
                <a href="<?= BASE_URL ?>/promo.php?empresa=<?= urlencode($slug) ?>">Promoções</a>
                <?php if (!empty($company['instagram_usuario'])): ?>
                    <a target="_blank" rel="noopener" href="https://instagram.com/<?= ltrim(sanitize($company['instagram_usuario']), '@') ?>">Instagram</a>
                <?php endif; ?>
            </nav>
        </div>
    </footer>

    <?php if ($whats): ?>
        <a href="https://wa.me/<?= $whats ?>?text=<?= urlencode($msg) ?>" target="_blank" rel="noopener" class="store-whatsapp-float" aria-label="Falar no WhatsApp">
            <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2a9.84 9.84 0 0 0-8.47 14.84L2 22l5.3-1.54A9.95 9.95 0 1 0 12.04 2Zm5.81 13.96c-.25.7-1.45 1.34-2 1.42-.51.08-1.16.11-1.87-.11-.43-.14-.99-.32-1.7-.63-2.99-1.29-4.94-4.29-5.09-4.49-.15-.2-1.22-1.62-1.22-3.09 0-1.47.77-2.19 1.04-2.49.27-.3.6-.37.8-.37h.57c.18.01.43-.07.67.51.25.6.85 2.08.92 2.23.08.15.13.32.03.52-.1.2-.15.32-.3.5-.15.17-.31.39-.45.52-.15.15-.3.31-.13.61.17.3.77 1.28 1.66 2.07 1.14 1.02 2.1 1.34 2.4 1.49.3.15.47.12.65-.08.17-.2.77-.87.97-1.17.2-.3.4-.25.67-.15.28.1 1.75.83 2.05.98.3.15.5.22.57.35.08.12.08.72-.17 1.42Z"/></svg>
            WhatsApp
        </a>
    <?php endif; ?>
</body>
</html>
