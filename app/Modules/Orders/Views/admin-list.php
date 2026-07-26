<?php

declare(strict_types=1);

if (! defined('ABSPATH')) exit;
?>
<div id="veciahorra-orders-admin" class="wrap veciahorra-orders-admin">
    <h1><?= esc_html__('Pedidos', 'veciahorra'); ?></h1>
    <p><?= esc_html__('Listado operacional de solo lectura.', 'veciahorra'); ?></p>
    <form id="veciahorra-orders-filters" class="veciahorra-orders-admin__filters" aria-label="<?= esc_attr__('Filtros de pedidos', 'veciahorra'); ?>">
        <label><?= esc_html__('Buscar', 'veciahorra'); ?><input name="search" type="search" maxlength="100"></label>
        <label><?= esc_html__('Store ID', 'veciahorra'); ?><input name="store_id" inputmode="numeric"></label>
        <label><?= esc_html__('Estado', 'veciahorra'); ?><select name="order_status"><option value="">Todos</option><option value="reserved">Reserved</option><option value="paid">Paid</option><option value="delivered">Delivered</option></select></label>
        <label><?= esc_html__('Modalidad', 'veciahorra'); ?><select name="fulfillment_mode"><option value="">Todas</option><option value="pickup">Pickup</option><option value="delivery">Delivery</option></select></label>
        <label><?= esc_html__('Desde', 'veciahorra'); ?><input name="date_from" type="date"></label>
        <label><?= esc_html__('Hasta', 'veciahorra'); ?><input name="date_to" type="date"></label>
        <label><?= esc_html__('Orden', 'veciahorra'); ?><select name="sort"><option value="newest">Más recientes</option><option value="oldest">Más antiguos</option><option value="updated">Actualizados</option><option value="total_desc">Mayor total</option><option value="total_asc">Menor total</option></select></label>
        <label><?= esc_html__('Por página', 'veciahorra'); ?><select name="per_page"><option>20</option><option>50</option><option>100</option></select></label>
        <button type="submit" class="button button-primary"><?= esc_html__('Aplicar', 'veciahorra'); ?></button>
        <button type="button" class="button" data-orders-clear><?= esc_html__('Limpiar', 'veciahorra'); ?></button>
    </form>
    <div id="veciahorra-orders-status" aria-live="polite" aria-atomic="true"></div>
    <main id="veciahorra-orders-list" aria-busy="false"></main>
    <nav id="veciahorra-orders-pagination" aria-label="<?= esc_attr__('Paginación de pedidos', 'veciahorra'); ?>"></nav>
    <noscript><p class="notice notice-warning"><?= esc_html__('Esta pantalla requiere JavaScript para cargar el listado.', 'veciahorra'); ?></p></noscript>
    <script id="veciahorra-orders-config" type="application/json"><?= wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
</div>
