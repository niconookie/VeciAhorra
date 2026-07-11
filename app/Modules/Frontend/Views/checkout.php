<?php
/** @var string $instanceId */
$titleId = $instanceId . '-title';
$deliveryLegendId = $instanceId . '-delivery-legend';
?>
<section class="va-checkout" data-va-checkout aria-labelledby="<?php echo esc_attr($titleId); ?>">
    <header>
        <p class="va-product-detail__eyebrow"><?php esc_html_e('Compra', 'veciahorra'); ?></p>
        <h1 id="<?php echo esc_attr($titleId); ?>"><?php esc_html_e('Finalizar compra', 'veciahorra'); ?></h1>
    </header>

    <div class="va-loader" role="status" data-va-checkout-loading>
        <span class="va-loader__indicator" aria-hidden="true"></span>
        <span><?php esc_html_e('Cargando resumen', 'veciahorra'); ?></span>
    </div>
    <div class="va-alert va-alert--error" role="alert" data-va-checkout-error hidden>
        <p data-va-checkout-error-message></p>
        <button class="va-button" type="button" data-va-checkout-retry><?php esc_html_e('Reintentar', 'veciahorra'); ?></button>
    </div>
    <div class="va-empty-state" data-va-checkout-empty hidden>
        <h2 class="va-empty-state__title"><?php esc_html_e('Tu carrito está vacío', 'veciahorra'); ?></h2>
        <p class="va-empty-state__message"><?php esc_html_e('Agrega productos antes de continuar.', 'veciahorra'); ?></p>
    </div>

    <div class="va-checkout__content" data-va-checkout-content hidden>
        <section class="va-checkout__summary" aria-labelledby="<?php echo esc_attr($instanceId . '-summary-title'); ?>">
            <h2 id="<?php echo esc_attr($instanceId . '-summary-title'); ?>"><?php esc_html_e('Resumen', 'veciahorra'); ?></h2>
            <div data-va-checkout-groups></div>
            <p class="va-checkout__total"><strong><?php esc_html_e('Total checkout', 'veciahorra'); ?></strong> <strong data-va-checkout-total></strong></p>
            <p class="va-alert va-alert--info" data-va-checkout-reservation><?php esc_html_e('La disponibilidad y el tiempo de reserva se confirmarán al continuar.', 'veciahorra'); ?></p>
        </section>

        <section class="va-alert va-alert--error va-checkout-validation-errors" tabindex="-1" aria-live="assertive" data-va-checkout-validation-errors hidden>
            <h2><?php esc_html_e('Revisa los problemas de tu compra', 'veciahorra'); ?></h2>
            <ul data-va-checkout-validation-error-list></ul>
        </section>

        <form class="va-checkout-form" data-va-checkout-form novalidate>
            <section aria-labelledby="<?php echo esc_attr($instanceId . '-customer-title'); ?>">
                <h2 id="<?php echo esc_attr($instanceId . '-customer-title'); ?>"><?php esc_html_e('Datos del cliente', 'veciahorra'); ?></h2>
                <div class="va-checkout-form__grid">
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-first-name'); ?>"><?php esc_html_e('Nombre', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-first-name'); ?>" name="first_name" autocomplete="given-name" required data-va-field><p class="va-field__error" id="<?php echo esc_attr($instanceId . '-first-name-error'); ?>" data-va-field-error hidden></p></div>
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-last-name'); ?>"><?php esc_html_e('Apellido', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-last-name'); ?>" name="last_name" autocomplete="family-name" required data-va-field><p class="va-field__error" id="<?php echo esc_attr($instanceId . '-last-name-error'); ?>" data-va-field-error hidden></p></div>
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-phone'); ?>"><?php esc_html_e('Teléfono', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-phone'); ?>" name="phone" type="tel" autocomplete="tel" required data-va-field><p class="va-field__error" id="<?php echo esc_attr($instanceId . '-phone-error'); ?>" data-va-field-error hidden></p></div>
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-email'); ?>"><?php esc_html_e('Correo electrónico', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-email'); ?>" name="email" type="email" autocomplete="email" required data-va-field><p class="va-field__error" id="<?php echo esc_attr($instanceId . '-email-error'); ?>" data-va-field-error hidden></p></div>
                </div>
            </section>

            <fieldset class="va-checkout-delivery" aria-labelledby="<?php echo esc_attr($deliveryLegendId); ?>">
                <legend id="<?php echo esc_attr($deliveryLegendId); ?>"><?php esc_html_e('Método de entrega', 'veciahorra'); ?></legend>
                <div data-va-delivery-options></div>
                <p class="va-help-text" data-va-delivery-minimum hidden></p>
            </fieldset>

            <section class="va-checkout-address" data-va-delivery-fields hidden aria-labelledby="<?php echo esc_attr($instanceId . '-address-title'); ?>">
                <h2 id="<?php echo esc_attr($instanceId . '-address-title'); ?>"><?php esc_html_e('Datos de despacho', 'veciahorra'); ?></h2>
                <div class="va-checkout-form__grid">
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-address'); ?>"><?php esc_html_e('Dirección', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-address'); ?>" name="address" autocomplete="street-address" data-va-field><p class="va-field__error" id="<?php echo esc_attr($instanceId . '-address-error'); ?>" data-va-field-error hidden></p></div>
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-commune'); ?>"><?php esc_html_e('Comuna', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-commune'); ?>" name="commune" data-va-field><p class="va-field__error" id="<?php echo esc_attr($instanceId . '-commune-error'); ?>" data-va-field-error hidden></p></div>
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-reference'); ?>"><?php esc_html_e('Referencia', 'veciahorra'); ?></label><input id="<?php echo esc_attr($instanceId . '-reference'); ?>" name="reference" data-va-field></div>
                    <div class="va-field"><label for="<?php echo esc_attr($instanceId . '-notes'); ?>"><?php esc_html_e('Observaciones', 'veciahorra'); ?></label><textarea id="<?php echo esc_attr($instanceId . '-notes'); ?>" name="notes" rows="3" data-va-field></textarea></div>
                </div>
            </section>

            <button class="va-button va-checkout-form__submit" type="submit" data-va-checkout-submit disabled><?php esc_html_e('Continuar al pago', 'veciahorra'); ?></button>
            <p class="va-alert va-alert--info" role="status" aria-live="polite" data-va-checkout-status hidden></p>
        </form>
    </div>
</section>
