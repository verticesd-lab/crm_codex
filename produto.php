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
        <div id="carousel-wrap">

            <!-- Container principal: posição relativa, sem altura forçada -->
            <div style="position:relative; border-radius:16px; overflow:hidden; background:#0f172a; border:1px solid rgba(255,255,255,.1);">

                <!-- Slides: display block/none — sem absolute, sem h-full -->
                <?php foreach ($images as $idx => $img): ?>
                <div id="slide-<?= $idx ?>"
                     style="<?= $idx === 0 ? 'display:block' : 'display:none' ?>">
                    <img src="<?= sanitize($img) ?>"
                         alt="<?= sanitize($product['nome']) ?>"
                         style="width:100%; max-height:480px; height:auto; object-fit:contain; display:block; background:#0f172a;">
                </div>
                <?php endforeach; ?>

                <?php if (count($images) > 1): ?>
                <!-- Seta esquerda -->
                <button id="btn-prev"
                        style="position:absolute; left:10px; top:50%; transform:translateY(-50%);
                               width:36px; height:36px; border-radius:50%;
                               background:rgba(0,0,0,.6); border:1px solid rgba(255,255,255,.25);
                               color:#fff; cursor:pointer; display:flex;
                               align-items:center; justify-content:center; z-index:10;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <!-- Seta direita -->
                <button id="btn-next"
                        style="position:absolute; right:10px; top:50%; transform:translateY(-50%);
                               width:36px; height:36px; border-radius:50%;
                               background:rgba(0,0,0,.6); border:1px solid rgba(255,255,255,.25);
                               color:#fff; cursor:pointer; display:flex;
                               align-items:center; justify-content:center; z-index:10;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </button>

                <!-- Dots -->
                <div style="position:absolute; bottom:10px; left:0; right:0;
                            display:flex; justify-content:center; gap:6px; z-index:10;">
                    <?php foreach ($images as $idx => $_): ?>
                    <button class="car-dot" data-dot="<?= $idx ?>"
                            style="width:8px; height:8px; border-radius:50%; border:none; padding:0; cursor:pointer;
                                   background:<?= $idx===0 ? '#fff' : 'rgba(255,255,255,.35)' ?>;
                                   transform:<?= $idx===0 ? 'scale(1.3)' : 'scale(1)' ?>;
                                   transition:all .2s;"></button>
                    <?php endforeach; ?>
                </div>

                <!-- Contador -->
                <div id="car-counter"
                     style="position:absolute; top:10px; right:10px;
                            background:rgba(0,0,0,.55); color:#fff;
                            font-size:11px; font-weight:600; padding:3px 9px;
                            border-radius:20px; border:1px solid rgba(255,255,255,.2); z-index:10;">
                    1 / <?= count($images) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Thumbnails clicáveis -->
            <?php if (count($images) > 1): ?>
            <div style="display:flex; gap:8px; margin-top:10px; overflow-x:auto; padding-bottom:4px;">
                <?php foreach ($images as $idx => $img): ?>
                <button class="car-thumb" data-thumb="<?= $idx ?>"
                        style="flex-shrink:0; width:60px; height:60px; border-radius:10px;
                               overflow:hidden; padding:0; cursor:pointer;
                               border:2px solid <?= $idx===0 ? '#7c3aed' : 'rgba(255,255,255,.2)' ?>;
                               box-shadow:<?= $idx===0 ? '0 0 0 3px rgba(124,58,237,.35)' : 'none' ?>;
                               transition:border-color .15s;">
                    <img src="<?= sanitize($img) ?>"
                         style="width:100%; height:100%; object-fit:cover; display:block;"
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
    const slides  = Array.from({length: total}, (_, i) => document.getElementById('slide-' + i));
    const dots    = Array.from(document.querySelectorAll('.car-dot'));
    const thumbs  = Array.from(document.querySelectorAll('.car-thumb'));
    const counter = document.getElementById('car-counter');

    function goTo(n) {
        // Esconde slide atual
        slides[current].style.display    = 'none';
        dots[current].style.background   = 'rgba(255,255,255,.35)';
        dots[current].style.transform    = 'scale(1)';
        thumbs[current].style.borderColor = 'rgba(255,255,255,.2)';
        thumbs[current].style.boxShadow   = 'none';

        current = (n + total) % total;

        // Mostra novo slide
        slides[current].style.display    = 'block';
        dots[current].style.background   = '#fff';
        dots[current].style.transform    = 'scale(1.3)';
        thumbs[current].style.borderColor = '#7c3aed';
        thumbs[current].style.boxShadow   = '0 0 0 3px rgba(124,58,237,.35)';

        if (counter) counter.textContent = (current + 1) + ' / ' + total;
    }

    document.getElementById('btn-prev').addEventListener('click', () => goTo(current - 1));
    document.getElementById('btn-next').addEventListener('click', () => goTo(current + 1));
    dots.forEach(d   => d.addEventListener('click', () => goTo(+d.dataset.dot)));
    thumbs.forEach(t => t.addEventListener('click', () => goTo(+t.dataset.thumb)));

    // Swipe no mobile
    let tx = 0;
    const wrap = document.getElementById('carousel-wrap');
    wrap.addEventListener('touchstart', e => { tx = e.touches[0].clientX; }, {passive:true});
    wrap.addEventListener('touchend',   e => {
        const dx = e.changedTouches[0].clientX - tx;
        if (Math.abs(dx) > 40) goTo(current + (dx < 0 ? 1 : -1));
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