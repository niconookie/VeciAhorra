<?php
/** @var array<string,mixed> $snapshot */
$metrics = $snapshot['metrics'];
$money = static fn (string $amount): string => '$' . number_format((float) $amount, 0, ',', '.');
$number = static fn (int $value): string => number_format_i18n($value);
$statusLabels = ['reserved' => 'Reservado', 'paid' => 'Pagado', 'delivered' => 'Entregado'];
$fulfillmentLabels = ['pickup' => 'Retiro', 'delivery' => 'Despacho'];
$cards = [
    ['Ventas de hoy', $money((string) $metrics['sales_today']), 'Pagos aplicados hoy'],
    ['Pedidos de hoy', $number((int) $metrics['orders_today']), 'Orders creadas hoy'],
    ['Pedidos pagados', $number((int) $metrics['paid_orders']), 'Total acumulado'],
    ['Despachos pendientes', $number((int) $metrics['deliveries_pending']), 'Sin repartidor asignado'],
];
?>
<div class="wrap va-dashboard">
    <header class="va-dashboard__header">
        <div><h1><?php esc_html_e('Dashboard VeciAhorra', 'veciahorra'); ?></h1><p><?php esc_html_e('Resumen operativo en tiempo real.', 'veciahorra'); ?></p></div>
        <p class="va-dashboard__date"><?php echo esc_html(wp_date('j \d\e F \d\e Y')); ?><br><small><?php echo esc_html((string) $snapshot['timezone']); ?></small></p>
    </header>

    <section aria-labelledby="va-summary-title"><h2 id="va-summary-title"><?php esc_html_e('Resumen de hoy', 'veciahorra'); ?></h2>
        <div class="va-dashboard__grid va-dashboard__grid--summary">
            <?php foreach ($cards as [$label, $value, $description]) : ?><article class="va-dashboard-card"><p><?php echo esc_html($label); ?></p><strong><?php echo esc_html($value); ?></strong><small><?php echo esc_html($description); ?></small></article><?php endforeach; ?>
        </div>
    </section>

    <section aria-labelledby="va-deliveries-title"><h2 id="va-deliveries-title"><?php esc_html_e('Despachos', 'veciahorra'); ?></h2>
        <div class="va-dashboard__grid">
            <?php foreach ([['Pendientes','deliveries_pending'],['Asignados','deliveries_assigned'],['Retirados','deliveries_picked_up'],['Entregados','deliveries_delivered']] as [$label,$key]) : ?><article class="va-dashboard-card va-dashboard-card--compact"><p><?php echo esc_html($label); ?></p><strong><?php echo esc_html($number((int) $metrics[$key])); ?></strong></article><?php endforeach; ?>
        </div>
    </section>

    <section aria-labelledby="va-network-title"><h2 id="va-network-title"><?php esc_html_e('Red VeciAhorra', 'veciahorra'); ?></h2>
        <div class="va-dashboard__grid va-dashboard__grid--network">
            <?php foreach ([['Minimarkets activos','active_stores'],['Productos activos','active_products'],['Ofertas publicadas','public_offers'],['Repartidores aprobados','approved_couriers'],['Prestadores publicados','published_service_providers']] as [$label,$key]) : ?><article class="va-dashboard-card va-dashboard-card--compact"><p><?php echo esc_html($label); ?></p><strong><?php echo esc_html($number((int) $metrics[$key])); ?></strong></article><?php endforeach; ?>
        </div>
    </section>

    <section aria-labelledby="va-orders-title"><h2 id="va-orders-title"><?php esc_html_e('Pedidos recientes', 'veciahorra'); ?></h2>
        <div class="va-dashboard-table-wrap"><table class="widefat striped"><thead><tr><th>Pedido</th><th>Fecha</th><th>Cliente</th><th>Minimarket</th><th>Total</th><th>Estado</th><th>Entrega</th><th>Acción</th></tr></thead><tbody>
        <?php if ($snapshot['recent_orders'] === []) : ?><tr><td colspan="8"><?php esc_html_e('No hay pedidos registrados.', 'veciahorra'); ?></td></tr><?php endif; ?>
        <?php foreach ($snapshot['recent_orders'] as $order) : $detailUrl = add_query_arg(['page'=>'veciahorra-orders','action'=>'view','order_id'=>(int)$order['order_id']], admin_url('admin.php')); ?>
            <tr><th scope="row">#<?php echo esc_html((string) $order['order_id']); ?></th><td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $order['created_at'])); ?></td><td><?php echo esc_html(trim((string) $order['customer_name']) ?: 'Cliente no disponible'); ?></td><td><?php echo esc_html(trim((string) $order['store_name']) ?: 'Minimarket no disponible'); ?></td><td><?php echo esc_html($money((string) $order['total'])); ?></td><td><?php echo esc_html($statusLabels[$order['order_status']] ?? (string) $order['order_status']); ?></td><td><?php echo esc_html($fulfillmentLabels[$order['fulfillment_method']] ?? 'No informado'); ?></td><td><a href="<?php echo esc_url($detailUrl); ?>"><?php esc_html_e('Ver detalle', 'veciahorra'); ?></a></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </section>
    <p class="va-dashboard__meta"><?php echo esc_html(sprintf('Actualizado: %s · %d consultas de dashboard', wp_date('H:i:s'), (int) $snapshot['query_count'])); ?></p>
</div>
