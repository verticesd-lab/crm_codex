<?php
// ============================================================
// ofertas.php — Página pública de Ofertas / Flash Sale
// Padrão idêntico ao loja.php (config, helpers, db, sessão)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$slug = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');
if (!$slug) { echo 'Empresa não informada.'; exit; }

$pdo = get_pdo();

$stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$stmt->execute([$slug]);
$company = $stmt->fetch();
if (!$company) { echo 'Empresa não encontrada.'; exit; }

$companyId = (int)$company['id'];

// ── Tracking ──────────────────────────────────────────────────
$siteTrackerPath = __DIR__ . '/site_analytics.php';
if (file_exists($siteTrackerPath)) {
    require_once $siteTrackerPath;
    if (function_exists('track_site_visit')) {
        track_site_visit($companyId, '/ofertas.php');
    }
}

// ── Configuração da promoção (lê company_settings se existir) ─
$flashConfig = [];
try {
    $cfgStmt = $pdo->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id = ? AND setting_key LIKE 'flash_%'");
    $cfgStmt->execute([$companyId]);
    while ($row = $cfgStmt->fetch()) {
        $flashConfig[$row['setting_key']] = $row['setting_value'];
    }
} catch (\Exception $e) { /* tabela pode não existir ainda */ }

$flashTitle        = $flashConfig['flash_titulo']         ?? 'Liquidação Inédita de Estoque';
$flashSubtitle     = $flashConfig['flash_subtitulo']      ?? 'Peças 100% Originais · Até 60% OFF · Entrega Disponível';
$flashValidade     = $flashConfig['flash_validade']       ?? date('Y-m-d', strtotime('next saturday')) . ' 23:59:59';
$flashEstoqueTotal = (int)($flashConfig['flash_estoque_total'] ?? 200);
$flashAtivo        = ($flashConfig['flash_ativo']         ?? '1') === '1';
// "pares" | "unidades" | "peças" | "itens" — editável via company_settings
$flashUnidade      = $flashConfig['flash_unidade']        ?? 'unidades';
// Chips fixos opcionais: JSON array de {nome, de, por}
// Ex: [{"nome":"Camiseta Básica","de":"R$89","por":"R$39,90"}, ...]
// Se vazio, usa os 4 primeiros produtos em oferta dinamicamente
$flashChipsFixos   = [];
if (!empty($flashConfig['flash_chips'])) {
    $decoded = json_decode($flashConfig['flash_chips'], true);
    if (is_array($decoded)) $flashChipsFixos = array_slice($decoded, 0, 4);
}

// ── WhatsApp ───────────────────────────────────────────────────
$whats = preg_replace('/\D+/', '', (string)($company['whatsapp_principal'] ?? ''));

// ── Produtos em oferta ─────────────────────────────────────────
$ofertasStmt = $pdo->prepare("
    SELECT * FROM products
    WHERE company_id = ?
      AND ativo = 1
      AND em_oferta = 1
      AND (oferta_validade IS NULL OR oferta_validade > NOW())
    ORDER BY oferta_estoque ASC, preco_oferta ASC
");
$ofertasStmt->execute([$companyId]);
$ofertas = $ofertasStmt->fetchAll();

// Estoque total restante
$estoqueRestante = array_sum(array_column($ofertas, 'oferta_estoque')) ?: $flashEstoqueTotal;
$pctEstoque      = $flashEstoqueTotal > 0 ? min(100, round(($estoqueRestante / $flashEstoqueTotal) * 100)) : 50;

// Categorias únicas dos produtos em oferta (para filtros)
$marcas = [];
foreach ($ofertas as $o) {
    $cat = trim($o['categoria'] ?? '');
    if ($cat && !in_array($cat, $marcas, true)) $marcas[] = $cat;
}

// ── Carrinho (mesmo padrão do loja.php) ───────────────────────
$cartKey = 'cart_' . $company['slug'];
if (!isset($_SESSION[$cartKey])) $_SESSION[$cartKey] = [];

if (isset($_GET['add'])) {
    $productId = (int)$_GET['add'];
    $_SESSION[$cartKey][$productId] = ($_SESSION[$cartKey][$productId] ?? 0) + 1;
    redirect(BASE_URL . '/ofertas.php?empresa=' . urlencode($slug));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
    <title>⚡ Ofertas — <?= sanitize($company['nome_fantasia']) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Space Grotesk"', 'Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: { 500:'#7c3aed', 600:'#6d28d9', 700:'#5b21b6' }
                    }
                }
            }
        }
    </script>

    <style>
        /* ── Ticker ───────────────────────── */
        .ticker-wrap { overflow: hidden; white-space: nowrap; }
        .ticker-inner { display: inline-block; animation: ticker 28s linear infinite; }
        @keyframes ticker { from{transform:translateX(0)} to{transform:translateX(-50%)} }

        /* ── Countdown ────────────────────── */
        .cd-block { min-width: 60px; }

        /* ── Stock fill ───────────────────── */
        .stock-fill { height:100%; border-radius:9999px; transition: width 1.2s ease; }

        /* ── Card glow on hover ───────────── */
        .offer-card:hover { box-shadow: 0 0 0 1.5px rgba(245,158,11,.3), 0 8px 32px rgba(0,0,0,.4); }

        /* ── Price strikethrough ──────────── */
        .de { text-decoration:line-through; color:#475569; }

        /* ── Pulse (live badge) ───────────── */
        @keyframes pulse-ring {
            0%,100%{ box-shadow:0 0 0 0 rgba(239,68,68,.5); }
            50%    { box-shadow:0 0 0 8px rgba(239,68,68,0); }
        }
        .pulse-badge { animation: pulse-ring 2s ease-in-out infinite; }

        /* ── Float button ─────────────────── */
        .float-btn { transition: transform .2s; }
        .float-btn:hover { transform: scale(1.08); }

        /* ── Filter btn states ────────────── */
        .filter-btn.active {
            background: #f59e0b;
            border-color: #f59e0b;
            color: #0f172a;
        }
    </style>
</head>
<body class="bg-slate-950 text-white min-h-screen font-sans">

    <!-- BG blobs (idêntico ao loja.php) -->
    <div class="absolute inset-0 -z-10 overflow-hidden pointer-events-none">
        <div class="absolute -top-24 -left-10 h-80 w-80 bg-brand-600/30 rounded-full blur-3xl"></div>
        <div class="absolute top-20 right-0 h-96 w-96 bg-amber-500/15 rounded-full blur-3xl"></div>
    </div>

<?php if (!$flashAtivo): ?>
    <!-- ── OFERTA ENCERRADA ──────────────────────────────────── -->
    <div class="max-w-2xl mx-auto px-4 py-24 text-center space-y-4">
        <p class="text-5xl">🏁</p>
        <h1 class="text-3xl font-bold">Esta oferta foi encerrada</h1>
        <p class="text-slate-400">Fique de olho nas próximas promoções.</p>
        <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
           class="inline-block mt-4 px-6 py-2.5 rounded-full bg-brand-600 hover:bg-brand-700 font-semibold">
            Ver catálogo completo →
        </a>
    </div>

<?php else: ?>

    <!-- ── TICKER ───────────────────────────────────────────── -->
    <div class="ticker-wrap bg-amber-400 text-slate-900 py-1.5 text-xs font-bold tracking-wide">
        <div class="ticker-inner px-4">
            <?php
            $ti = ['⚡ ' . strtoupper($flashTitle), '🔥 ATÉ 60% OFF', '👟 ' . $flashEstoqueTotal . ' PARES DISPONÍVEIS',
                   '✅ TÊNIS 100% ORIGINAIS', '🚚 ENTREGA DISPONÍVEL', '📲 COMPRE PELO WHATSAPP', '⏰ SOMENTE ATÉ SÁBADO'];
            $ts = implode('<span class="mx-8 opacity-50">|</span>', array_merge($ti, $ti));
            echo "<span>$ts</span>";
            ?>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 py-8 space-y-8">

        <!-- ── HEADER (mesmo padrão loja.php) ────────────────── -->
        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4">
                <?php if (!empty($company['logo'])): ?>
                    <img src="<?= sanitize(image_url($company['logo'])) ?>"
                         class="h-12 w-12 rounded-full border border-white/20 object-cover">
                <?php else: ?>
                    <div class="h-12 w-12 rounded-full bg-brand-600 flex items-center justify-center text-base font-bold">
                        <?= strtoupper(substr($company['nome_fantasia'], 0, 2)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-widest">Oferta especial</p>
                    <h1 class="text-2xl font-bold tracking-tight"><?= sanitize($company['nome_fantasia']) ?></h1>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= BASE_URL ?>/checkout.php?empresa=<?= urlencode($slug) ?>"
                   class="inline-flex items-center gap-2 bg-emerald-500 text-slate-900 px-4 py-2 rounded-full shadow-lg shadow-emerald-500/30 hover:bg-emerald-400 font-semibold text-sm">
                    Carrinho (<?= (int)array_sum($_SESSION[$cartKey]) ?>)
                </a>
                <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
                   class="text-sm text-slate-400 hover:text-white border border-white/10 hover:border-white/30 px-4 py-2 rounded-full">
                    ← Catálogo completo
                </a>
            </div>
        </header>

        <!-- ── HERO BANNER ───────────────────────────────────── -->
        <div class="relative bg-white/5 border border-white/10 rounded-2xl overflow-hidden shadow-xl">
            <div class="absolute inset-0 bg-gradient-to-r from-amber-600/20 via-red-600/10 to-brand-600/20 blur-3xl pointer-events-none"></div>
            <div class="relative p-6 md:p-10 text-center space-y-4">
                <span class="inline-block bg-red-500 text-white text-xs font-black tracking-widest uppercase px-4 py-1.5 rounded-full pulse-badge">
                    ⚡ Flash Sale · Oferta por tempo limitado
                </span>
                <h2 class="text-4xl md:text-6xl font-black tracking-tight leading-none">
                    <?= sanitize($flashTitle) ?>
                </h2>
                <p class="text-slate-300 text-base md:text-lg max-w-xl mx-auto">
                    <?= sanitize($flashSubtitle) ?>
                </p>

                <!-- Price chips — dinâmicos (4 primeiros produtos em oferta)
                     OU fixos se flash_chips estiver configurado no company_settings -->
                <?php
                // Monta a lista de chips
                if (!empty($flashChipsFixos)) {
                    // Modo fixo: configurado manualmente via company_settings
                    $heroChips = $flashChipsFixos;
                } else {
                    // Modo dinâmico: pega os 4 primeiros produtos em oferta
                    // Prioriza os que têm destaque=1, depois ordena por preco_oferta ASC
                    $heroChips = [];
                    $sorted = $ofertas;
                    usort($sorted, fn($a,$b) => ((int)$b['destaque'] <=> (int)$a['destaque']) ?: ((float)$a['preco_oferta'] <=> (float)$b['preco_oferta']));
                    foreach (array_slice($sorted, 0, 4) as $chip) {
                        $heroChips[] = [
                            'nome' => $chip['nome'],
                            'de'   => !empty($chip['preco_original']) ? 'R$' . number_format((float)$chip['preco_original'], 2, ',', '.') : '',
                            'por'  => 'R$' . number_format((float)$chip['preco_oferta'], 2, ',', '.'),
                        ];
                    }
                }
                ?>
                <?php if (!empty($heroChips)): ?>
                <div class="flex flex-wrap justify-center gap-3 pt-2">
                    <?php foreach ($heroChips as $chip): ?>
                    <div class="bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-center min-w-[110px]">
                        <p class="text-xs font-bold tracking-widest text-slate-400 uppercase mb-1">
                            <?= sanitize($chip['nome'] ?? '') ?>
                        </p>
                        <?php if (!empty($chip['de'])): ?>
                        <p class="text-xs de"><?= sanitize($chip['de']) ?></p>
                        <?php endif; ?>
                        <p class="text-2xl font-black text-amber-400 leading-tight">
                            <?= sanitize($chip['por'] ?? '') ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($whats): ?>
                <a href="#produtos"
                   class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white font-bold px-6 py-2.5 rounded-full shadow-lg shadow-brand-600/30 mt-2">
                    Ver ofertas ↓
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── COUNTDOWN + STOCK ─────────────────────────────── -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            <!-- Countdown -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5 space-y-3">
                <p class="text-xs font-bold tracking-widest text-red-400 uppercase text-center">⏰ Oferta encerra em</p>
                <div class="flex justify-center gap-3" id="countdown">
                    <?php foreach (['dias'=>'Dias','horas'=>'Horas','min'=>'Min','seg'=>'Seg'] as $id=>$label): ?>
                    <div class="cd-block bg-white/5 border border-white/10 rounded-xl px-3 py-3 text-center">
                        <span class="block text-3xl md:text-4xl font-black text-amber-400 leading-none tabular-nums" id="cd-<?= $id ?>">--</span>
                        <span class="text-xs text-slate-500 uppercase tracking-widest"><?= $label ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Stock + Parcelamento -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5 flex flex-col justify-center space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold tracking-widest text-slate-400 uppercase">📦 Estoque disponível</p>
                    <p class="text-sm font-bold text-red-400"><?= $estoqueRestante ?> <?= sanitize($flashUnidade) ?></p>
                </div>
                <div class="h-2.5 bg-white/5 rounded-full border border-white/10 overflow-hidden">
                    <div class="stock-fill <?= $pctEstoque < 30 ? 'bg-red-500' : 'bg-emerald-500' ?>"
                         style="width:<?= $pctEstoque ?>%"></div>
                </div>
                <p class="text-xs text-slate-500">⚠️ Oferta encerra quando o estoque acabar ou no sábado</p>
                <div class="flex gap-3 pt-1">
                    <div class="flex-1 bg-white/5 border border-white/10 rounded-xl p-3 text-center">
                        <p class="text-xs text-slate-500 mb-0.5">Até R$200</p>
                        <p class="text-2xl font-black text-emerald-400 leading-none">2x</p>
                        <p class="text-xs text-slate-500">sem juros</p>
                    </div>
                    <div class="flex-1 bg-white/5 border border-white/10 rounded-xl p-3 text-center">
                        <p class="text-xs text-slate-500 mb-0.5">Acima de R$200</p>
                        <p class="text-2xl font-black text-emerald-400 leading-none">3x</p>
                        <p class="text-xs text-slate-500">sem juros</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── FILTROS ────────────────────────────────────────── -->
        <?php if (!empty($marcas)): ?>
        <div class="flex flex-wrap gap-2">
            <button onclick="filtrar('todos',this)"
                    class="filter-btn active px-4 py-1.5 rounded-full text-xs font-bold tracking-wide border border-amber-400 bg-amber-400 text-slate-900 transition">
                Todos
            </button>
            <?php foreach ($marcas as $m): ?>
            <button onclick="filtrar(<?= json_encode($m) ?>,this)"
                    class="filter-btn px-4 py-1.5 rounded-full text-xs font-bold tracking-wide border border-white/10 text-slate-400 hover:border-amber-400/60 hover:text-amber-300 transition">
                <?= sanitize($m) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── PRODUTOS ───────────────────────────────────────── -->
        <section id="produtos" class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold">Produtos em Oferta</h2>
                <p class="text-sm text-slate-400"><?= count($ofertas) ?> disponível(is)</p>
            </div>

            <?php if (empty($ofertas)): ?>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-12 text-center space-y-3">
                <p class="text-4xl">👟</p>
                <h3 class="text-lg font-semibold text-slate-300">Produtos sendo cadastrados</h3>
                <p class="text-sm text-slate-500">Em breve os produtos desta promoção estarão disponíveis aqui.</p>
                <?php if ($whats): ?>
                <a href="https://wa.me/<?= $whats ?>?text=<?= urlencode('Olá! Quero saber sobre a ' . $flashTitle) ?>"
                   target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 mt-2 bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold px-5 py-2.5 rounded-full">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.117 1.526 5.845L0 24l6.334-1.498A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.896 0-3.67-.505-5.2-1.386l-.374-.217-3.758.888.928-3.637-.243-.388A9.96 9.96 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
                    Consultar no WhatsApp
                </a>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="grid-ofertas">
                <?php foreach ($ofertas as $p):
                    $desconto   = 0;
                    if (!empty($p['preco_original']) && (float)$p['preco_original'] > 0) {
                        $desconto = round((1 - (float)$p['preco_oferta'] / (float)$p['preco_original']) * 100);
                    }
                    $estq       = $p['oferta_estoque'] ?? null;
                    $parcelas   = (int)($p['oferta_parcelas'] ?? ($p['preco_oferta'] > 200 ? 3 : 2));
                    $valParcela = number_format((float)$p['preco_oferta'] / max(1,$parcelas), 2, ',', '.');
                    $catEsc     = htmlspecialchars($p['categoria'] ?? '', ENT_QUOTES);
                    $waMsg      = urlencode('Olá! Tenho interesse no ' . $p['nome'] . ' por ' . format_currency($p['preco_oferta']) . ' da ' . $flashTitle . '. Ainda disponível?');
                ?>
                <div class="offer-card group bg-white/5 border border-white/10 rounded-xl overflow-hidden transition transform hover:-translate-y-1"
                     data-cat="<?= $catEsc ?>">

                    <!-- Imagem + badge de desconto -->
                    <div class="relative">
                        <?php if (!empty($p['imagem'])): ?>
                            <div class="h-48 w-full bg-white/5 flex items-center justify-center overflow-hidden">
                                <img src="<?= sanitize(image_url($p['imagem'])) ?>"
                                     alt="<?= sanitize($p['nome']) ?>"
                                     class="h-full w-full object-contain p-3 group-hover:scale-105 transition duration-300">
                            </div>
                        <?php else: ?>
                            <div class="h-48 w-full bg-white/10 flex items-center justify-center text-5xl">👟</div>
                        <?php endif; ?>

                        <?php if ($desconto > 0): ?>
                        <div class="absolute top-3 left-3 bg-red-500 text-white text-xs font-black px-2.5 py-1 rounded-lg shadow">
                            -<?= $desconto ?>%
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Corpo -->
                    <div class="p-4 space-y-2">
                        <p class="text-xs font-bold tracking-widest text-amber-400 uppercase">
                            <?= sanitize($p['categoria'] ?? '') ?>
                        </p>
                        <h3 class="text-base font-bold leading-tight"><?= sanitize($p['nome']) ?></h3>

                        <?php if (!empty($p['descricao'])): ?>
                        <p class="text-xs text-slate-400 line-clamp-2"><?= sanitize($p['descricao']) ?></p>
                        <?php endif; ?>

                        <!-- Preços -->
                        <div class="flex items-baseline gap-2 pt-1">
                            <?php if (!empty($p['preco_original'])): ?>
                            <span class="text-sm de"><?= format_currency($p['preco_original']) ?></span>
                            <?php endif; ?>
                            <span class="text-2xl font-black text-amber-400"><?= format_currency($p['preco_oferta']) ?></span>
                        </div>
                        <p class="text-xs text-slate-500">
                            Em até <span class="text-emerald-400 font-semibold"><?= $parcelas ?>x de R$<?= $valParcela ?></span> sem juros
                        </p>

                        <!-- Estoque -->
                        <?php if ($estq !== null): ?>
                        <p class="text-xs font-bold <?= $estq<=5 ? 'text-red-400' : ($estq<=15 ? 'text-amber-400' : 'text-emerald-400') ?>">
                            <?= $estq<=5 ? "🔥 Últimas $estq unidades!" : ($estq<=15 ? "⚠️ Restam $estq unidades" : "✅ $estq disponíveis") ?>
                        </p>
                        <?php endif; ?>

                        <!-- Ações -->
                        <div class="flex flex-col gap-2 pt-1">
                            <?php if ($whats): ?>
                            <a href="https://wa.me/<?= $whats ?>?text=<?= $waMsg ?>"
                               target="_blank" rel="noopener"
                               class="inline-flex items-center justify-center gap-2 w-full bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold text-sm py-2.5 rounded-lg transition">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.117 1.526 5.845L0 24l6.334-1.498A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.896 0-3.67-.505-5.2-1.386l-.374-.217-3.758.888.928-3.637-.243-.388A9.96 9.96 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
                                Quero este!
                            </a>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/loja.php?<?= http_build_query(['empresa'=>$slug,'add'=>(int)$p['id']]) ?>"
                               class="inline-flex items-center justify-center w-full border border-brand-600 text-brand-500 hover:bg-brand-600 hover:text-white font-semibold text-sm py-2 rounded-lg transition">
                                + Adicionar ao carrinho
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── RODAPÉ ─────────────────────────────────────────── -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-5 text-center text-sm text-slate-400 space-y-1">
            <p>🚚 Entrega disponível · 📲 Pagamento via Pix, cartão ou dinheiro</p>
            <p class="text-xs pt-1">
                <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>" class="text-brand-500 hover:underline">
                    ← Ver catálogo completo
                </a>
            </p>
        </div>

    </div><!-- /container -->

    <!-- ── FLOATING WHATSAPP ─────────────────────────────────── -->
    <?php if ($whats): ?>
    <a href="https://wa.me/<?= $whats ?>?text=<?= urlencode('Olá! Vim da oferta ' . $flashTitle . ' e quero saber mais!') ?>"
       target="_blank" rel="noopener"
       class="float-btn fixed bottom-5 right-5 z-50 flex items-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-semibold px-5 py-3 rounded-full shadow-lg shadow-emerald-500/40">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.117 1.526 5.845L0 24l6.334-1.498A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.896 0-3.67-.505-5.2-1.386l-.374-.217-3.758.888.928-3.637-.243-.388A9.96 9.96 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
        WhatsApp
    </a>
    <?php endif; ?>

<?php endif; // flashAtivo ?>

    <script>
    // ── Countdown ────────────────────────────────────────────────
    const validade = new Date('<?= $flashValidade ?>').getTime();
    function updateCD() {
        const diff = validade - Date.now();
        if (diff <= 0) {
            const el = document.getElementById('countdown');
            if (el) el.innerHTML = '<p class="text-red-400 font-bold text-sm">⏰ Esta oferta encerrou!</p>';
            return;
        }
        const pad = n => String(n).padStart(2,'0');
        document.getElementById('cd-dias').textContent  = pad(Math.floor(diff/86400000));
        document.getElementById('cd-horas').textContent = pad(Math.floor((diff%86400000)/3600000));
        document.getElementById('cd-min').textContent   = pad(Math.floor((diff%3600000)/60000));
        document.getElementById('cd-seg').textContent   = pad(Math.floor((diff%60000)/1000));
    }
    setInterval(updateCD, 1000);
    updateCD();

    // ── Filtros ──────────────────────────────────────────────────
    function filtrar(cat, btn) {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.offer-card').forEach(card => {
            card.style.display = (cat === 'todos' || card.dataset.cat === cat) ? '' : 'none';
        });
    }
    </script>

</body>
</html>