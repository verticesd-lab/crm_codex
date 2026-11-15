<?php /* Topo enxuto da loja (faixa, marca e carrinho) para produto e carrinho. Espera $company, $slug, $cartKey. */ ?>
    <div class="store-announcement">Catálogo oficial • Atendimento rápido pelo WhatsApp</div>

    <header class="store-header">
        <div class="store-container store-header-main">
            <a class="store-brand" href="<?= sanitize(public_route_url('loja', $slug)) ?>" aria-label="Página inicial de <?= sanitize($company['nome_fantasia']) ?>">
                <?php if (!empty($company['logo'])): ?>
                    <img src="<?= sanitize(image_url($company['logo'])) ?>" class="store-brand-logo" alt="Logo de <?= sanitize($company['nome_fantasia']) ?>">
                <?php endif; ?>
                <span class="store-brand-copy">
                    <span class="store-brand-kicker">Catálogo oficial</span>
                    <span class="store-brand-name"><?= sanitize($company['nome_fantasia']) ?></span>
                </span>
            </a>
            <span></span>
            <div class="store-header-actions">
                <a class="store-cart" href="<?= BASE_URL ?>/checkout.php?empresa=<?= urlencode($slug) ?>">
                    Carrinho
                    <span class="store-cart-count"><?= (int)array_sum($_SESSION[$cartKey]) ?></span>
                </a>
            </div>
        </div>
    </header>
