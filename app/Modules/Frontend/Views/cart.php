<?php
/** @var string $instanceId */
/** @var string $checkoutUrl */
/** @var string $catalogUrl */
$titleId = $instanceId . '-title';
?>
<section class="veciahorra-frontend va-design-system va-public-cart" data-va-cart aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header class="va-public-cart__header">
        <div class="va-section-heading">
            <p class="va-product-detail__eyebrow va-eyebrow"><?php esc_html_e('Compra', 'veciahorra'); ?></p>
            <h1 id="<?php echo esc_attr($titleId); ?>"><?php esc_html_e('Tu carrito', 'veciahorra'); ?></h1>
        </div>
        <button class="va-button va-button--secondary" type="button" data-va-cart-clear hidden>
            <?php esc_html_e('Vaciar carrito', 'veciahorra'); ?>
        </button>
    </header>

    <div class="va-loader" role="status" data-va-cart-loading>
        <span class="va-loader__indicator" aria-hidden="true"></span>
        <span class="va-loader__label"><?php esc_html_e('Cargando carrito', 'veciahorra'); ?></span>
    </div>

    <div class="va-alert va-alert--error" role="alert" data-va-cart-error hidden>
        <p data-va-cart-error-message></p>
        <button class="va-button va-button--primary" type="button" data-va-cart-retry><?php esc_html_e('Reintentar', 'veciahorra'); ?></button>
    </div>

    <div class="va-empty-state" data-va-cart-empty hidden>
        <h2 class="va-empty-state__title"><?php esc_html_e('Tu carrito está vacío', 'veciahorra'); ?></h2>
        <p class="va-empty-state__message"><?php esc_html_e('Agrega productos desde sus fichas públicas.', 'veciahorra'); ?></p>
    </div>

    <div class="va-public-cart__content va-card" data-va-cart-content hidden>
        <div class="va-cart-table-wrap">
            <table class="va-cart-table">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Producto', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Oferta', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Precio unitario', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Cantidad', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Subtotal', 'veciahorra'); ?></th>
                    <th scope="col"><span class="va-visually-hidden"><?php esc_html_e('Acciones', 'veciahorra'); ?></span></th>
                </tr></thead>
                <tbody data-va-cart-items></tbody>
            </table>
        </div>
        <footer class="va-public-cart__total" data-va-cart-breakdown>
            <span><?php esc_html_e('Subtotal productos', 'veciahorra'); ?></span><strong data-va-cart-product-subtotal></strong>
            <span><?php esc_html_e('Cargo por uso de VeciAhorra', 'veciahorra'); ?></span><strong data-va-cart-platform-fee></strong>
            <span><?php esc_html_e('Despacho', 'veciahorra'); ?></span><strong data-va-cart-delivery-fee></strong>
            <strong><?php esc_html_e('Total a pagar', 'veciahorra'); ?></strong><strong data-va-cart-total></strong>
        </footer>
        <div class="va-public-cart__checkout">
            <a class="va-button va-button--secondary" href="<?php echo esc_url($catalogUrl); ?>" data-va-cart-continue-shopping><?php esc_html_e('Seguir comprando', 'veciahorra'); ?></a>
            <?php if (! (new \VeciAhorra\Core\LaunchGate())->commerceEnabled()) : ?>
                <span class="va-help-text" role="status" data-va-cart-checkout-unavailable>Disponible desde el 1 de septiembre</span>
            <?php elseif ($checkoutUrl !== '') : ?>
                <a class="va-button va-button--primary" href="<?php echo esc_url($checkoutUrl); ?>" data-va-cart-checkout><?php esc_html_e('Continuar al checkout', 'veciahorra'); ?></a>
            <?php else : ?>
                <span class="va-help-text" role="status" data-va-cart-checkout-unavailable><?php esc_html_e('Checkout no disponible temporalmente.', 'veciahorra'); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <p class="va-visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-va-cart-status></p>
</section>
