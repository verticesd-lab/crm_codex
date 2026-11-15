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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,500;0,600;0,700;1,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/loja-v2.css">
</head>
<body class="store-body">
<?php include __DIR__ . '/views/partials/store_header_simple.php'; ?>

    <main class="store-main store-container">
        <a class="store-back" href="<?= sanitize(public_route_url('loja', $slug)) ?>">← Voltar para a loja</a>

        <div class="store-product">
        <!-- ── Carrossel de fotos ── -->
        <div id="carousel-wrap">

            <!-- Container principal: posição relativa, sem altura forçada -->
            <div style="position:relative; border-radius:2px; overflow:hidden; background:#17191c; border:1px solid rgba(255,255,255,.1);">

                <!-- Slides: display block/none — sem absolute, sem h-full -->
                <?php foreach ($images as $idx => $img): ?>
                <div id="slide-<?= $idx ?>"
                     style="<?= $idx === 0 ? 'display:block' : 'display:none' ?>">
                    <img src="<?= sanitize($img) ?>"
                         alt="<?= sanitize($product['nome']) ?>"
                         style="width:100%; max-height:480px; height:auto; object-fit:contain; display:block; background:#17191c;">
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
                        style="flex-shrink:0; width:60px; height:60px; border-radius:2px;
                               overflow:hidden; padding:0; cursor:pointer;
                               border:2px solid <?= $idx===0 ? '#d7ff3e' : 'rgba(255,255,255,.2)' ?>;
                               box-shadow:<?= $idx===0 ? '0 0 0 3px rgba(215,255,62,.3)' : 'none' ?>;
                               transition:border-color .15s;">
                    <img src="<?= sanitize($img) ?>"
                         style="width:100%; height:100%; object-fit:cover; display:block;"
                         alt="Foto <?= $idx+1 ?>">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>

            <div class="store-product-info">
                <span class="store-section-kicker"><?= sanitize($product['categoria'] ?? 'Produto') ?></span>
                <h1 class="store-product-title"><?= sanitize($product['nome']) ?></h1>
                <p class="store-product-price"><?= format_currency($product['preco']) ?></p>

                <?php if ($hasAvailableSizes): ?>
                    <div class="sizes-block">
                        <span class="sizes-label">Tamanhos disponíveis</span>
                        <div class="sizes-row">
                            <?php foreach ($variants as $v): ?>
                                <?php $qtd = (int)($v['quantity'] ?? 0); ?>
                                <?php if ($qtd <= 0) continue; ?>
                                <span class="sz-pill sz-pill-lg"><?= sanitize($v['size']) ?> <small>(<?= $qtd ?> em estoque)</small></span>
                            <?php endforeach; ?>
                        </div>
                        <p class="store-product-note">Estoque exibido com base na loja física.</p>
                    </div>
                <?php endif; ?>

                <div class="store-product-description"><?= nl2br(sanitize($product['descricao'])) ?></div>

                <form method="get" action="<?= sanitize(public_route_url('loja', $slug)) ?>">
                    <?php if (!uses_friendly_public_route($slug)): ?>
                    <input type="hidden" name="empresa" value="<?= sanitize($slug) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="add" value="<?= (int)$product['id'] ?>">
                    <button type="submit" class="store-button store-button-primary store-product-add">Adicionar ao carrinho</button>
                </form>

                <p class="store-product-note">
                    O pagamento é concluído via WhatsApp: você adiciona os produtos ao carrinho e finaliza diretamente com o atendente.
                </p>
            </div>
        </div>
    </main>

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
        thumbs[current].style.borderColor = '#d7ff3e';
        thumbs[current].style.boxShadow   = '0 0 0 3px rgba(215,255,62,.3)';

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
<?php $chatProductName = (string)($product['nome'] ?? ''); include __DIR__ . '/views/partials/store_chat.php'; ?>
</body>
</html>
