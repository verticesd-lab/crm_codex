<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1) Slug da empresa (via GET ou sessão)
$slugEmpresa = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');
if (!$slugEmpresa) {
    echo 'Empresa não informada.';
    exit;
}

$pdo = get_pdo();

// 2) Carregar empresa
$companyStmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$companyStmt->execute([$slugEmpresa]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    echo 'Empresa não encontrada.';
    exit;
}

$companyId = (int)$company['id'];

// 3) Slug da promoção (opcional)
//    - se vier ?promo=..., usa ela
//    - se NÃO vier, busca a promoção ativa da empresa
$slugPromo = $_GET['promo'] ?? '';

if ($slugPromo) {
    // Promoção específica (como era antes)
    $promoStmt = $pdo->prepare('
        SELECT *
        FROM promotions
        WHERE slug = ?
          AND company_id = ?
          AND ativo = 1
        LIMIT 1
    ');
    $promoStmt->execute([$slugPromo, $companyId]);
} else {
    // Promoção ativa no momento (sem ?promo= na URL)
    $promoStmt = $pdo->prepare('
        SELECT *
        FROM promotions
        WHERE company_id = ?
          AND ativo = 1
          AND (data_inicio IS NULL OR data_inicio <= CURDATE())
          AND (data_fim IS NULL OR data_fim >= CURDATE())
        ORDER BY data_inicio DESC, id DESC
        LIMIT 1
    ');
    $promoStmt->execute([$companyId]);
}

$promo = $promoStmt->fetch(PDO::FETCH_ASSOC);

if (!$promo) {
    echo 'Promoção não encontrada.';
    exit;
}

// Garante que sempre temos o slug correto da promoção
$slugPromo = $promo['slug'];

// 4) Carregar produtos em destaque da promoção (igual você já fazia)
$products = [];
if (!empty($promo['destaque_produtos'])) {
    $ids = array_filter(array_map('trim', explode(',', $promo['destaque_produtos'])));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $productStmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND ativo = 1");
        $productStmt->execute($ids);
        $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 5) Mensagem pro WhatsApp, com link da própria promo
$baseUrl = BASE_URL ?: '';
$promoUrl = rtrim($baseUrl, '/') . "/promo.php?empresa={$slugEmpresa}&promo={$slugPromo}";
$mensagem = urlencode("Quero a oferta: {$promo['titulo']} da empresa {$company['nome_fantasia']} - {$promoUrl}");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($promo['titulo']) ?> - <?= sanitize($company['nome_fantasia']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Space Grotesk"', 'Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: {
                            50: '#eef2ff',
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
        <div class="absolute -top-40 -right-20 h-96 w-96 bg-brand-600/40 rounded-full blur-3xl"></div>
        <div class="absolute top-10 -left-10 h-80 w-80 bg-emerald-500/30 rounded-full blur-3xl"></div>
    </div>
    <div class="max-w-6xl mx-auto px-4 py-10 space-y-10">
        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div class="flex items-center gap-4">
                <?php if (!empty($company['logo'])): ?>
                    <img src="<?= sanitize($company['logo']) ?>" class="h-12 w-12 rounded-full border border-white/20 object-cover">
                <?php else: ?>
                    <div class="h-12 w-12 rounded-full bg-brand-600 flex items-center justify-center text-lg font-semibold">
                        <?= strtoupper(substr($company['nome_fantasia'],0,2)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="text-sm text-slate-200/70">Oferta exclusiva · <?= sanitize($company['nome_fantasia']) ?></p>
                    <h1 class="text-3xl font-bold tracking-tight"><?= sanitize($promo['titulo']) ?></h1>
                </div>
            </div>
            <div class="flex gap-3">
                <?php if (!empty($company['instagram_usuario'])): ?>
                    <a class="px-4 py-2 rounded-full border border-white/20 text-sm hover:border-white/40"
                       target="_blank"
                       href="https://instagram.com/<?= ltrim(sanitize($company['instagram_usuario']), '@') ?>">
                        Instagram
                    </a>
                <?php endif; ?>
                <a class="px-4 py-2 rounded-full bg-emerald-500 text-slate-900 font-semibold shadow-lg shadow-emerald-500/30 hover:bg-emerald-400"
                   href="https://api.whatsapp.com/send?phone=<?= urlencode($company['whatsapp_principal']) ?>&text=<?= $mensagem ?>">
                    Falar no WhatsApp
                </a>
            </div>
        </header>

        <!-- resto do teu HTML ORIGINAL daqui pra baixo, igual estava -->
        <!-- (mantive tudo igual ao arquivo que você mandou) -->

        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-center">
            <div class="space-y-6">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-sm text-slate-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Oferta ativa agora
                </div>
                <p class="text-xl text-slate-200 leading-relaxed"><?= nl2br(sanitize($promo['descricao'])) ?></p>
                <p class="text-2xl font-semibold text-emerald-300"><?= sanitize($promo['texto_chamada']) ?></p>
                <div class="flex flex-wrap gap-3">
                    <a class="px-6 py-3 rounded-full bg-brand-600 hover:bg-brand-700 font-semibold shadow-lg shadow-brand-600/30"
                       href="https://api.whatsapp.com/send?phone=<?= urlencode($company['whatsapp_principal']) ?>&text=<?= $mensagem ?>">
                        Quero essa oferta
                    </a>
                    <a class="px-6 py-3 rounded-full border border-white/20 hover:border-white/40"
                       href="tel:<?= urlencode($company['whatsapp_principal']) ?>">
                        Ligar agora
                    </a>
                </div>
                <div class="flex flex-wrap gap-4 text-sm text-slate-200/80">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Atendimento humano ou WhatsApp
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Estoque e agenda atualizados
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Resposta rápida
                    </div>
                </div>
            </div>
            <div class="relative">
                <div class="absolute inset-0 bg-gradient-to-br from-brand-600/30 via-indigo-600/20 to-emerald-500/20 blur-3xl -z-10"></div>
                <div class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur-xl shadow-2xl overflow-hidden">
                    <?php if (!empty($promo['banner_image'])): ?>
                        <img src="<?= sanitize($promo['banner_image']) ?>" class="w-full h-64 object-cover">
                    <?php else: ?>
                        <div class="w-full h-64 bg-gradient-to-r from-brand-600/60 to-emerald-500/60 flex items-center justify-center text-xl font-semibold">
                            Oferta Especial
                        </div>
                    <?php endif; ?>
                    <div class="p-6 space-y-3">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-full bg-emerald-500/20 border border-emerald-300/30 flex items-center justify-center text-emerald-200 font-semibold">%</div>
                            <div>
                                <p class="text-sm text-slate-200/70">Válido enquanto durar o estoque</p>
                                <p class="font-semibold text-lg"><?= sanitize($promo['titulo']) ?></p>
                            </div>
                        </div>
                        <p class="text-sm text-slate-200/80">
                            Clique para falar com nossa equipe e garanta sua condição especial.
                        </p>
                        <a href="https://api.whatsapp.com/send?phone=<?= urlencode($company['whatsapp_principal']) ?>&text=<?= $mensagem ?>"
                           class="inline-flex items-center gap-2 px-5 py-3 rounded-full bg-emerald-500 text-slate-900 font-semibold shadow-md shadow-emerald-500/40 hover:bg-emerald-400">
                            Falar com atendimento
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($products): ?>
            <section class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-2xl font-semibold tracking-tight">Itens em destaque</h2>
                    <p class="text-sm text-slate-200/80">Selecionados para esta campanha</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach ($products as $product): ?>
                        <div class="group bg-white/5 border border-white/10 rounded-xl overflow-hidden shadow-lg hover:-translate-y-1 transition transform">
                            <?php if (!empty($product['imagem'])): ?>
                                <img src="<?= sanitize($product['imagem']) ?>" class="h-40 w-full object-cover group-hover:scale-105 transition">
                            <?php else: ?>
                                <div class="h-40 w-full bg-white/10 flex items-center justify-center text-slate-200/70">Sem imagem</div>
                            <?php endif; ?>
                            <div class="p-4 space-y-2">
                                <p class="text-xs uppercase tracking-wide text-emerald-200/80"><?= sanitize($product['categoria']) ?></p>
                                <h3 class="text-lg font-semibold"><?= sanitize($product['nome']) ?></h3>
                                <p class="text-sm text-slate-200/80 line-clamp-2"><?= sanitize($product['descricao']) ?></p>
                                <div class="flex items-center justify-between">
                                    <p class="text-xl font-bold text-emerald-300"><?= format_currency($product['preco']) ?></p>
                                    <span class="text-xs px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-100">Disponível</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <footer class="pt-8 border-t border-white/10 text-sm text-slate-200/70">
            <p>
                <?= sanitize($company['nome_fantasia']) ?> · Atendimento pelo WhatsApp ·
                <?php if (!empty($company['instagram_usuario'])): ?>
                    @<?= sanitize($company['instagram_usuario']) ?>
                <?php else: ?>
                    Siga no Instagram
                <?php endif; ?>
            </p>
        </footer>
    </div>
</body>
</html>
