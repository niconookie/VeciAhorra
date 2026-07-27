<?php

declare(strict_types=1);

if (! defined('ABSPATH')) exit;
?>
<div id="veciahorra-order-detail" class="wrap veciahorra-orders-admin veciahorra-order-detail" aria-busy="true">
    <nav aria-label="<?= esc_attr__('Navegación del detalle de pedido', 'veciahorra'); ?>">
        <a href="<?= esc_url($returnUrl); ?>">&larr; <?= esc_html__('Volver a pedidos', 'veciahorra'); ?></a>
    </nav>
    <header>
        <h1><?= esc_html__('Detalle administrativo de pedido', 'veciahorra'); ?></h1>
        <p><?= esc_html__('Vista operacional de solo lectura.', 'veciahorra'); ?></p>
    </header>
    <div id="veciahorra-order-detail-loading" role="status" aria-live="polite" aria-atomic="true">
        <p><?= esc_html__('Detalle pendiente de carga.', 'veciahorra'); ?></p>
    </div>
    <div id="veciahorra-order-detail-error" role="alert" aria-live="assertive" tabindex="-1" hidden></div>
    <main id="veciahorra-order-detail-content" aria-label="<?= esc_attr__('Contenido del detalle de pedido', 'veciahorra'); ?>" hidden></main>
    <noscript>
        <p class="notice notice-warning inline"><?= esc_html__('El detalle requiere JavaScript en un microhito posterior. Puedes volver al listado con el enlace superior.', 'veciahorra'); ?></p>
    </noscript>
    <script id="veciahorra-order-detail-config">
        window.VeciAhorra = window.VeciAhorra || {};
        window.VeciAhorra.ordersAdminDetail = Object.assign(
            window.VeciAhorra.ordersAdminDetail || {},
            <?= wp_json_encode($detailConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
        );
    </script>
</div>
