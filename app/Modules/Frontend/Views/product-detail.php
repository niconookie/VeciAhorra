<?php
/** @var string $instanceId */
/** @var int $productId */
/** @var string $cartUrl */
$titleId = $instanceId . '-product-title';
$offersLabelId = $instanceId . '-offers-label';
$selectionTitleId = $instanceId . '-selection-title';
?>
<article class="va-product-detail" data-va-product-detail data-product-id="<?php echo esc_attr((string) $productId); ?>" aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header class="va-product-detail__header">
        <div class="va-product-detail__media">
            <img class="va-product-detail__image" data-va-product-image alt="" hidden>
            <span class="va-product-detail__image-missing" data-va-product-image-missing><?php esc_html_e('Imagen no disponible', 'veciahorra'); ?></span>
        </div>
        <div class="va-product-detail__intro">
        <p class="va-product-detail__eyebrow"><?php esc_html_e('Producto', 'veciahorra'); ?></p>
        <h1 id="<?php echo esc_attr($titleId); ?>" data-va-product-name><?php esc_html_e('Cargando producto…', 'veciahorra'); ?></h1>
        <p class="va-product-detail__description" data-va-product-description></p>
        </div>
    </header>

    <div class="va-loader" role="status" data-va-product-loading>
        <span class="va-loader__indicator" aria-hidden="true"></span>
        <span class="va-loader__label"><?php esc_html_e('Cargando ofertas', 'veciahorra'); ?></span>
    </div>
    <div class="va-alert va-alert--error" role="alert" data-va-product-error hidden></div>

    <section class="va-offer-section" data-va-offer-section hidden>
        <h2 id="<?php echo esc_attr($offersLabelId); ?>"><?php esc_html_e('Ofertas disponibles', 'veciahorra'); ?></h2>
        <p class="va-help-text"><?php esc_html_e('Selecciona el minimarket donde deseas comprar.', 'veciahorra'); ?></p>
        <div class="va-offer-grid" role="radiogroup" aria-labelledby="<?php echo esc_attr($offersLabelId); ?>" data-va-offer-list></div>
        <div class="va-empty-state" data-va-offers-empty hidden>
            <h3 class="va-empty-state__title"><?php esc_html_e('Producto sin ofertas', 'veciahorra'); ?></h3>
            <p class="va-empty-state__message"><?php esc_html_e('No hay ofertas disponibles en este momento.', 'veciahorra'); ?></p>
        </div>
    </section>

    <section class="va-selection-summary" aria-labelledby="<?php echo esc_attr($selectionTitleId); ?>" aria-live="polite" data-va-selection-summary hidden>
        <h2 id="<?php echo esc_attr($selectionTitleId); ?>"><?php esc_html_e('Oferta seleccionada', 'veciahorra'); ?></h2>
        <p data-va-selection-status><?php esc_html_e('Aún no has seleccionado una oferta.', 'veciahorra'); ?></p>
        <dl class="va-selection-summary__values" data-va-selection-values hidden>
            <div><dt><?php esc_html_e('Minimarket', 'veciahorra'); ?></dt><dd data-va-selected-store></dd></div>
            <div><dt><?php esc_html_e('Precio', 'veciahorra'); ?></dt><dd data-va-selected-price></dd></div>
            <div><dt><?php esc_html_e('Stock disponible', 'veciahorra'); ?></dt><dd data-va-selected-stock></dd></div>
        </dl>
    </section>

    <section class="va-cart-action" aria-labelledby="<?php echo esc_attr($instanceId . '-cart-title'); ?>">
        <h2 id="<?php echo esc_attr($instanceId . '-cart-title'); ?>"><?php esc_html_e('Agregar al carrito', 'veciahorra'); ?></h2>
        <button class="va-button va-cart-action__button" type="button" data-va-add-to-cart disabled aria-busy="false">
            <span data-va-add-label><?php esc_html_e('Agregar al carrito', 'veciahorra'); ?></span>
            <span data-va-add-loading hidden><?php esc_html_e('Agregando…', 'veciahorra'); ?></span>
        </button>
        <p class="va-cart-action__message va-cart-action__message--success" role="status" aria-live="polite" data-va-cart-success hidden></p>
        <a class="va-button va-cart-action__link" href="<?php echo esc_url($cartUrl); ?>" data-va-view-cart hidden><?php esc_html_e('Ver carrito', 'veciahorra'); ?></a>
        <p class="va-cart-action__message va-cart-action__message--error" role="alert" data-va-cart-error hidden></p>
    </section>
</article>
