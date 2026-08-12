<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/support/training-courier-demo-scenario-support.php';

global $wpdb;
$context = vaCourierDemoContext();
$prefix = $context['prefix'];
$officialBefore = vaCourierDemoOfficialOffers($context);
$now = current_time('mysql', true);
$expires = '2099-12-31 23:59:59';
$states = [
    'CD-01' => ['pending', null, 'Av. Capacitación 101', 'Disponible para aceptar'],
    'CD-02' => ['assigned', 16, 'Av. Capacitación 202', 'Asignada a Diego Morales'],
    'CD-03' => ['picked_up', 16, 'Av. Capacitación 303', 'Pedido retirado del minimarket'],
];

$wpdb->query('START TRANSACTION');
try {
    foreach ($states as $scenario => [$status, $courierId, $address, $notes]) {
        $marker = VA_COURIER_DEMO_MARKERS[$scenario];
        $matches = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$prefix}checkouts WHERE public_id=%s", $marker), ARRAY_A);
        vaCourierDemoAssert(count($matches) <= 1, "Colisión múltiple de marcador {$marker}");
        if ($matches === []) {
            vaCourierDemoAssert($wpdb->insert($prefix . 'orders', [
                'customer_id' => (int) $context['customer']->ID, 'minimarket_id' => (int) $context['store']['id'],
                'total' => '2190.00', 'status' => 'paid', 'reservation_expires_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]) === 1, "No se creó Order {$scenario}");
            $orderId = (int) $wpdb->insert_id;
            vaCourierDemoAssert($wpdb->insert($prefix . 'order_items', [
                'order_id' => $orderId, 'product_id' => (int) $context['inventory']['product_id'],
                'inventory_id' => (int) $context['inventory']['inventory_id'], 'quantity' => 1,
                'unit_price' => '2190.00', 'subtotal' => '2190.00', 'created_at' => $now, 'updated_at' => $now,
            ]) === 1, "No se creó OrderItem {$scenario}");
            vaCourierDemoAssert($wpdb->insert($prefix . 'checkouts', [
                'public_id' => $marker, 'owner_type' => 'user', 'user_id' => (int) $context['customer']->ID,
                'session_id' => null, 'status' => 'payment_completed', 'fulfillment_method' => 'delivery',
                'delivery_recipient_name' => 'Carolina Soto', 'delivery_contact_phone' => '+56955553001',
                'delivery_address_line1' => $address, 'delivery_commune' => 'San Miguel',
                'delivery_reference' => $scenario . ' · Escenario capacitación Courier', 'delivery_notes' => $notes,
                'idempotency_owner_key' => 'training-courier-demo', 'idempotency_key' => strtolower($scenario) . '-v1',
                'request_fingerprint' => hash('sha256', $marker), 'currency' => 'CLP', 'total_amount' => '2190.00',
                'created_at' => $now, 'updated_at' => $now, 'expires_at' => $expires,
            ]) === 1, "No se creó Checkout {$scenario}");
            $checkoutId = (int) $wpdb->insert_id;
            vaCourierDemoAssert($wpdb->insert($prefix . 'checkout_orders', ['checkout_id' => $checkoutId, 'order_id' => $orderId, 'created_at' => $now]) === 1, "No se vinculó Checkout/Order {$scenario}");
            vaCourierDemoAssert($wpdb->insert($prefix . 'deliveries', [
                'order_id' => $orderId, 'customer_id' => (int) $context['customer']->ID,
                'minimarket_id' => (int) $context['store']['id'], 'courier_id' => $courierId, 'status' => $status,
                'delivery_recipient_name' => 'Carolina Soto', 'delivery_contact_phone' => '+56955553001',
                'delivery_address_line1' => $address, 'delivery_commune' => 'San Miguel',
                'delivery_reference' => $scenario . ' · Escenario capacitación Courier', 'delivery_notes' => $notes,
                'created_at' => $now, 'updated_at' => $now,
            ]) === 1, "No se creó Delivery {$scenario}");
        } else {
            $checkout = $matches[0];
            $links = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$prefix}checkout_orders WHERE checkout_id=%d", (int) $checkout['id']), ARRAY_A);
            vaCourierDemoAssert(count($links) === 1, "Grafo propio incompleto {$scenario}");
            $orderId = (int) $links[0]['order_id'];
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}orders WHERE id=%d", $orderId), ARRAY_A);
            $delivery = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}deliveries WHERE order_id=%d", $orderId), ARRAY_A);
            vaCourierDemoAssert($order !== null && $delivery !== null
                && (int) $order['customer_id'] === (int) $context['customer']->ID
                && (int) $order['minimarket_id'] === (int) $context['store']['id']
                && (int) $delivery['customer_id'] === (int) $context['customer']->ID
                && (int) $delivery['minimarket_id'] === (int) $context['store']['id'], "Colisión con datos ajenos {$scenario}");
            vaCourierDemoAssert($wpdb->update($prefix . 'orders', ['status' => 'paid', 'updated_at' => $now], ['id' => $orderId]) !== false, "No se restauró Order {$scenario}");
            vaCourierDemoAssert($wpdb->update($prefix . 'deliveries', ['courier_id' => $courierId, 'status' => $status, 'updated_at' => $now], ['id' => (int) $delivery['id']]) !== false, "No se restauró Delivery {$scenario}");
        }
    }
    $rows = vaCourierDemoValidate($context);
    vaCourierDemoAssert(vaCourierDemoOfficialOffers($context) === $officialBefore, 'El fixture alteró ofertas oficiales.');
    $wpdb->query('COMMIT');
} catch (Throwable $exception) {
    $wpdb->query('ROLLBACK');
    throw $exception;
}

echo 'PASS COURIER_ID=16 DEMO_ORDERS=3 DEMO_DELIVERIES=3 AVAILABLE=1 ASSIGNED=1 IN_PROGRESS=1', PHP_EOL;
echo 'TRAINING_COURIER_ORDER_IDS=[' . implode(',', array_map(static fn(array $row): int => (int) $row['order_id'], $rows)) . ']', PHP_EOL;
echo 'TRAINING_COURIER_DELIVERY_IDS=[' . implode(',', array_map(static fn(array $row): int => (int) $row['delivery_id'], $rows)) . ']', PHP_EOL;
echo 'OFFICIAL_4_4=PASS INVENTORY_MUTATIONS=0', PHP_EOL;
