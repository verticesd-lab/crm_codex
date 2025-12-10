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
        <div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-3">
                <img id="main-img"
                     src="<?= sanitize($images[0]) ?>"
                     alt="<?= sanitize($product['nome']) ?>"
                     class="w-full h-auto rounded-xl object-contain bg-slate-900">
            </div>

            <?php if (count($images) > 1): ?>
                <div class="mt-3 flex gap-3 overflow-x-auto">
                    <?php foreach ($images as $idx => $img): ?>
                        <img src="<?= sanitize($img) ?>"
                             data-index="<?= $idx ?>"
                             class="w-20 h-20 rounded-xl object-cover cursor-pointer border border-white/10 <?= $idx === 0 ? 'ring-2 ring-brand-500' : '' ?>">
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
    const mainImg = document.getElementById('main-img');
    const thumbs = document.querySelectorAll('[data-index]');
    thumbs.forEach(thumb => {
        thumb.addEventListener('click', () => {
            mainImg.src = thumb.src;
            thumbs.forEach(t => t.classList.remove('ring-2', 'ring-brand-500'));
            thumb.classList.add('ring-2', 'ring-brand-500');
        });
    });
</script>
</body>
</html>
