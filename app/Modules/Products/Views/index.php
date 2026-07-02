<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div
    id="veciahorra-products-admin"
    class="wrap veciahorra-products-admin"
>
    <header class="veciahorra-products-admin__header">
        <h1 class="wp-heading-inline">
            <?= esc_html__('Productos', 'veciahorra'); ?>
        </h1>

        <button
            type="button"
            class="page-title-action"
            disabled
            aria-disabled="true"
        >
            <?= esc_html__('Nuevo producto', 'veciahorra'); ?>
        </button>
    </header>

    <hr class="wp-header-end">

    <div
        id="veciahorra-products-messages"
        class="veciahorra-products-admin__messages"
        role="status"
        aria-live="polite"
    ></div>

    <main
        id="veciahorra-products-app"
        class="veciahorra-products-admin__main"
    >
        <section
            id="veciahorra-products-toolbar"
            class="veciahorra-products-admin__toolbar"
            aria-label="<?= esc_attr__('Herramientas de productos', 'veciahorra'); ?>"
        ></section>

        <section
            id="veciahorra-products-table"
            class="veciahorra-products-admin__table"
            aria-label="<?= esc_attr__('Tabla de productos', 'veciahorra'); ?>"
        ></section>

        <nav
            id="veciahorra-products-pagination"
            class="veciahorra-products-admin__pagination"
            aria-label="<?= esc_attr__('Paginación de productos', 'veciahorra'); ?>"
        ></nav>
    </main>

    <noscript>
        <div class="notice notice-warning inline">
            <p>
                <?= esc_html__('Esta pantalla requiere JavaScript.', 'veciahorra'); ?>
            </p>
        </div>
    </noscript>

    <script
        id="veciahorra-products-config"
        type="application/json"
    ><?= wp_json_encode(
        $config,
        JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ); ?></script>
</div>
