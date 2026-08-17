<?php
/** @var string $titleId */
/** @var bool $hasEffectiveSector */
/** @var string $catalogUrl */
/** @var int $limit */
?>
<section
    class="veciahorra-frontend va-design-system va-home-products"
    data-va-home-products
    data-has-effective-sector="<?php echo $hasEffectiveSector ? '1' : '0'; ?>"
    data-catalog-url="<?php echo esc_url($catalogUrl); ?>"
    data-product-limit="<?php echo esc_attr((string) $limit); ?>"
    aria-labelledby="<?php echo esc_attr($titleId); ?>"
>
    <div class="va-home-products__inner">
        <header class="va-home-products__heading">
            <h2 id="<?php echo esc_attr($titleId); ?>">Productos cerca de ti</h2>
            <p class="va-home-products__intro">Descubre productos disponibles en minimarkets de tu sector.</p>
        </header>
        <div
            class="va-home-products__status"
            data-va-home-products-status
            aria-live="polite"
            aria-atomic="true"
        ></div>
        <div class="va-home-products__grid" data-va-home-products-grid></div>
        <?php if ($catalogUrl !== '') : ?>
            <a class="va-button va-button--primary va-home-products__catalog-link" href="<?php echo esc_url($catalogUrl); ?>">
                Explorar catálogo
            </a>
        <?php endif; ?>
    </div>
</section>
