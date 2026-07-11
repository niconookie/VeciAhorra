<?php
/** @var string $instanceId */
$titleId = $instanceId . '-title';
?>
<section class="va-public-cart" data-va-cart aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header class="va-public-cart__header">
        <div>
            <p class="va-product-detail__eyebrow"><?php esc_html_e('Compra', 'veciahorra'); ?></p>
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
        <button class="va-button" type="button" data-va-cart-retry><?php esc_html_e('Reintentar', 'veciahorra'); ?></button>
    </div>

    <div class="va-empty-state" data-va-cart-empty hidden>
        <h2 class="va-empty-state__title"><?php esc_html_e('Tu carrito está vacío', 'veciahorra'); ?></h2>
        <p class="va-empty-state__message"><?php esc_html_e('Agrega productos desde sus fichas públicas.', 'veciahorra'); ?></p>
    </div>

    <div class="va-public-cart__content" data-va-cart-content hidden>
        <div class="va-cart-table-wrap">
            <table class="va-cart-table">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Producto', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Minimarket', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Precio unitario', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Cantidad', 'veciahorra'); ?></th>
                    <th scope="col"><?php esc_html_e('Subtotal', 'veciahorra'); ?></th>
                    <th scope="col"><span class="va-visually-hidden"><?php esc_html_e('Acciones', 'veciahorra'); ?></span></th>
                </tr></thead>
                <tbody data-va-cart-items></tbody>
            </table>
        </div>
        <footer class="va-public-cart__total">
            <strong><?php esc_html_e('Total', 'veciahorra'); ?></strong>
            <strong data-va-cart-total></strong>
        </footer>
    </div>
    <p class="va-visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-va-cart-status></p>
</section>
