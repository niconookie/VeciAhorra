<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div id="veciahorra-inventory-admin" class="wrap veciahorra-inventory-admin">
    <header class="veciahorra-inventory-admin__header">
        <h1 class="wp-heading-inline">
            <?= esc_html__('Inventario', 'veciahorra'); ?>
        </h1>
        <button type="button" class="page-title-action">
            <?= esc_html__('Nuevo inventario', 'veciahorra'); ?>
        </button>
    </header>

    <hr class="wp-header-end">

    <div
        id="veciahorra-inventory-messages"
        class="veciahorra-inventory-admin__messages"
        role="status"
        aria-live="polite"
    ></div>

    <main id="veciahorra-inventory-app" class="veciahorra-inventory-admin__main">
        <section
            id="veciahorra-inventory-toolbar"
            class="veciahorra-inventory-admin__toolbar"
            aria-label="<?= esc_attr__('Filtros de inventario', 'veciahorra'); ?>"
        ></section>

        <section
            id="veciahorra-inventory-table"
            class="veciahorra-inventory-admin__table"
            aria-label="<?= esc_attr__('Tabla de inventario', 'veciahorra'); ?>"
        ></section>

        <nav
            id="veciahorra-inventory-pagination"
            class="veciahorra-inventory-admin__pagination"
            aria-label="<?= esc_attr__('Paginacion de inventario', 'veciahorra'); ?>"
        ></nav>
    </main>

    <noscript>
        <div class="notice notice-warning inline">
            <p><?= esc_html__('Esta pantalla requiere JavaScript.', 'veciahorra'); ?></p>
        </div>
    </noscript>

    <script id="veciahorra-inventory-config" type="application/json"><?= wp_json_encode(
        $config,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?></script>
</div>
