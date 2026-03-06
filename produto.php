<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// slug da empresa
$slug = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');
if (!$slug) {
    echo 'Empresa não informada.';
    exit;
}

$pdo = get_pdo();

// carrega empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$stmt->execute([$slug]);
$company = $stmt->fetch();

if (!$company) {
    echo 'Empresa não encontrada.';
    exit;
}

// id do produto
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($productId <= 0) {
    echo 'Produto não informado.';
    exit;
}

// carrega produto dessa empresa
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? AND ativo = 1');
$stmt->execute([$productId, $company['id']]);
$product = $stmt->fetch();

if (!$product) {
    echo 'Produto não encontrado ou inativo.';
    exit;
}

/**
 *  🔹 Carrega as variantes (tamanhos) + estoque da loja física
 */
$stmt = $pdo->prepare("
    SELECT
        pv.id,
        pv.size,
        COALESCE(b.quantity, 0) AS quantity
    FROM product_variants pv
    LEFT JOIN stock_balances b
        ON b.product_variant_id = pv.id
       AND b.location = 'loja_fisica'
    WHERE pv.product_id = ?
    ORDER BY pv.size
");
$stmt->execute([$productId]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// verifica se existe pelo menos 1 tamanho com estoque > 0
$hasAvailableSizes = false;
foreach ($variants as $v) {
    if ((int)($v['quantity'] ?? 0) > 0) {
        $hasAvailableSizes = true;
        break;
    }
}

// monta array de imagens (principal + extras se existirem)
$images = [];
if (!empty($product['imagem'])) {
    $images[] = image_url($product['imagem']);
}
if (!empty($product['imagem2'] ?? null)) {
    $images[] = image_url($product['imagem2']);
}
if (!empty($product['imagem3'] ?? null)) {
    $images[] = image_url($product['imagem3']);
}
if (!empty($product['imagem4'] ?? null)) {
    $images[] = image_url($product['imagem4']);
}

// garante pelo menos 1
if (empty($images)) {
    $images[] = 'https://via.placeholder.com/800x600?text=Sem+imagem';
}

// carrinho por empresa
$cartKey = 'cart_' . $company['slug'];
if (!isset($_SESSION[$cartKey])) {
    $_SESSION[$cartKey] = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">

    <title><?= sanitize($product['nome']) ?> - <?= sanitize($company['nome_fantasia']) ?></title>
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

<div class="max-w-5xl mx-auto px-4 py-8 space-y-8">
    <header class="flex items-center justify-between">
        <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>" class="text-sm text-slate-200/80 hover:underline">
            ← Voltar para a loja
        </a>

        <a href="<?= BASE_URL ?>/checkout.php?empresa=<?= urlencode($slug) ?>"
           class="inline-flex items-center gap-2 bg-emerald-500 text-slate-900 px-4 py-2 rounded-full shadow-lg shadow-emerald-500/30 hover:bg-emerald-400">
            Carrinho (<?= array_sum($_SESSION[$cartKey]) ?>)
        </a>
    </header>

    <main class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
        <!-- ── Carrossel de fotos ── -->
        <div>
            <!-- Imagem principal com setas -->
            <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden" id="carousel-wrap">
                <div class="relative" style="aspect-ratio:4/3;">
                    <?php foreach ($images as $idx => $img): ?>
                    <img src="<?= sanitize($img) ?>"
                         id="slide-<?= $idx ?>"
                         alt="<?= sanitize($product['nome']) ?> - foto <?= $idx+1 ?>"
                         class="absolute inset-0 w-full h-full object-contain bg-slate-900 transition-opacity duration-300 <?= $idx === 0 ? 'opacity-100 z-10' : 'opacity-0 z-0' ?>">
                    <?php endforeach; ?>
                </div>

                <?php if (count($images) > 1): ?>
                <!-- Seta esquerda -->
                <button id="btn-prev"
                    class="absolute left-2 top-1/2 -translate-y-1/2 z-20
                           w-9 h-9 rounded-full bg-black/50 border border-white/20
                           flex items-center justify-center text-white
                           hover:bg-brand-600 transition-colors backdrop-blur-sm">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <!-- Seta direita -->
                <button id="btn-next"
                    class="absolute right-2 top-1/2 -translate-y-1/2 z-20
                           w-9 h-9 rounded-full bg-black/50 border border-white/20
                           flex items-center justify-center text-white
                           hover:bg-brand-600 transition-colors backdrop-blur-sm">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </button>

                <!-- Indicadores (dots) -->
                <div class="absolute bottom-2 left-0 right-0 flex justify-center gap-1.5 z-20">
                    <?php foreach ($images as $idx => $_): ?>
                    <button class="carousel-dot w-2 h-2 rounded-full transition-all duration-200
                                   <?= $idx === 0 ? 'bg-white scale-125' : 'bg-white/40' ?>"
                            data-dot="<?= $idx ?>"></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Badge contador -->
                <?php if (count($images) > 1): ?>
                <div id="slide-counter"
                     class="absolute top-2 right-2 z-20 bg-black/50 backdrop-blur-sm
                            text-white text-xs font-semibold px-2 py-1 rounded-full border border-white/20">
                    1 / <?= count($images) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Thumbnails -->
            <?php if (count($images) > 1): ?>
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                <?php foreach ($images as $idx => $img): ?>
                <button class="thumb-btn flex-shrink-0 w-16 h-16 rounded-xl overflow-hidden
                               border-2 transition-all duration-150
                               <?= $idx === 0 ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-white/10 hover:border-white/40' ?>"
                        data-thumb="<?= $idx ?>">
                    <img src="<?= sanitize($img) ?>"
                         class="w-full h-full object-cover"
                         alt="Foto <?= $idx+1 ?>">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="space-y-4">
            <div class="space-y-1">
                <p class="text-xs uppercase tracking-wide text-emerald-200/80">
                    <?= sanitize($product['categoria'] ?? 'Produto') ?>
                </p>
                <h1 class="text-2xl md:text-3xl font-bold leading-tight">
                    <?= sanitize($product['nome']) ?>
                </h1>
            </div>

            <p class="text-2xl font-bold text-emerald-300">
                <?= format_currency($product['preco']) ?>
            </p>

            <?php if ($hasAvailableSizes): ?>
                <div class="mt-3 space-y-1">
                    <p class="text-xs font-semibold text-emerald-300 uppercase tracking-[0.2em]">
                        Tamanhos disponíveis
                    </p>
                    <div class="flex flex-wrap gap-2 mt-1">
                        <?php foreach ($variants as $v): ?>
                            <?php $qtd = (int)($v['quantity'] ?? 0); ?>
                            <?php if ($qtd <= 0) continue; ?>
                            <span
                                class="px-3 py-1 rounded-full text-xs font-medium
                                       bg-emerald-500/15 text-emerald-100 border border-emerald-400/60">
                                <?= sanitize($v['size']) ?>
                                <span class="text-[9px] ml-1 opacity-80">
                                    (<?= $qtd ?> em estoque)
                                </span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-[11px] text-slate-400">
                        Estoque exibido com base na loja física.
                    </p>
                </div>
            <?php endif; ?>

            <div class="prose prose-invert max-w-none text-sm text-slate-200/90">
                <?= nl2br(sanitize($product['descricao'])) ?>
            </div>

            <form method="get" action="<?= BASE_URL ?>/loja.php" class="space-y-2">
                <input type="hidden" name="empresa" value="<?= sanitize($slug) ?>">
                <input type="hidden" name="add" value="<?= (int)$product['id'] ?>">
                <button type="submit"
                        class="inline-flex items-center justify-center w-full bg-brand-600 text-white px-4 py-3 rounded-xl hover:bg-brand-700 font-semibold">
                    Adicionar ao carrinho
                </button>
            </form>

            <p class="text-xs text-slate-400">
                O pagamento ainda é concluído via WhatsApp.
                Você adiciona os produtos ao carrinho e finaliza diretamente com o atendente.
            </p>
        </div>
    </main>
</div>

<script>
(function () {
    const total = <?= count($images) ?>;
    if (total <= 1) return;

    let current = 0;

    const slides  = document.querySelectorAll('[id^="slide-"]');
    const dots    = document.querySelectorAll('[data-dot]');
    const thumbs  = document.querySelectorAll('[data-thumb]');
    const counter = document.getElementById('slide-counter');

    function goTo(n) {
        // Limpa atual
        slides[current].classList.replace('opacity-100','opacity-0');
        slides[current].classList.replace('z-10','z-0');
        dots[current].classList.remove('bg-white','scale-125');
        dots[current].classList.add('bg-white/40');
        thumbs[current].classList.remove('border-brand-500','ring-2','ring-brand-500/30');
        thumbs[current].classList.add('border-white/10');

        current = (n + total) % total;

        // Ativa novo
        slides[current].classList.replace('opacity-0','opacity-100');
        slides[current].classList.replace('z-0','z-10');
        dots[current].classList.add('bg-white','scale-125');
        dots[current].classList.remove('bg-white/40');
        thumbs[current].classList.add('border-brand-500','ring-2','ring-brand-500/30');
        thumbs[current].classList.remove('border-white/10');

        if (counter) counter.textContent = (current + 1) + ' / ' + total;
    }

    document.getElementById('btn-prev').addEventListener('click', () => goTo(current - 1));
    document.getElementById('btn-next').addEventListener('click', () => goTo(current + 1));

    dots.forEach(d => d.addEventListener('click', () => goTo(parseInt(d.dataset.dot))));
    thumbs.forEach(t => t.addEventListener('click', () => goTo(parseInt(t.dataset.thumb))));

    // Swipe touch
    let tx = 0;
    const wrap = document.getElementById('carousel-wrap');
    wrap.addEventListener('touchstart', e => { tx = e.touches[0].clientX; }, {passive:true});
    wrap.addEventListener('touchend',   e => {
        const dx = e.changedTouches[0].clientX - tx;
        if (Math.abs(dx) > 40) goTo(dx < 0 ? current + 1 : current - 1);
    });

    // Teclado
    document.addEventListener('keydown', e => {
        if (e.key === 'ArrowLeft')  goTo(current - 1);
        if (e.key === 'ArrowRight') goTo(current + 1);
    });
})();
</script>
</body>
</html>