<?php /* Card de produto da loja. Espera $product e $cardStatus, além das variáveis de página de loja.php. */ ?>
<article class="product-card">
    <a href="<?= BASE_URL ?>/produto.php?empresa=<?= urlencode($slug) ?>&id=<?= (int)$product['id'] ?>" class="product-card-link">
        <div class="product-card-media">
            <span class="product-card-status"><?= sanitize($cardStatus) ?></span>
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
    <a href="<?= sanitize(public_route_url('loja', $slug, [
        'q'         => $search,
        'categoria' => $categoria,
        'page'      => $page,
        'per_page'  => $perPage,
        'add'       => (int)$product['id']
    ])) ?>" class="product-card-cta">
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
        Adicionar ao carrinho
    </a>
</article>
