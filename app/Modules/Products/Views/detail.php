<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div id="veciahorra-product-detail" class="wrap veciahorra-product-detail">
    <div id="veciahorra-product-detail-messages" role="status" aria-live="polite"></div>
    <main id="veciahorra-product-detail-main" aria-busy="true">
        <p class="veciahorra-product-detail__loading">
            <?= esc_html__('Cargando detalle del producto…', 'veciahorra'); ?>
        </p>
    </main>
    <script id="veciahorra-products-config" type="application/json"><?= wp_json_encode(
        $config,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?></script>
</div>
