<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Couriers\Identity\CourierRole;
use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;

const VA_COURIER_DEMO_MARKERS = [
    'CD-01' => 'training-courier-demo-cd-01-v1',
    'CD-02' => 'training-courier-demo-cd-02-v1',
    'CD-03' => 'training-courier-demo-cd-03-v1',
];

function vaCourierDemoAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function vaCourierDemoContext(): array
{
    global $wpdb;
    $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
    $courierUser = get_user_by('login', 'va_demo_diego');
    vaCourierDemoAssert($courierUser instanceof WP_User, 'Courier demo ausente.');
    $courierId = (int) get_user_meta($courierUser->ID, CourierRole::META_KEY, true);
    $courier = $wpdb->get_row($wpdb->prepare("SELECT id,display_name,status FROM {$prefix}couriers WHERE id=%d", $courierId), ARRAY_A);
    vaCourierDemoAssert(
        (int) $courierUser->ID === 209 && $courierId === 16
        && ($courier['display_name'] ?? '') === 'Diego Morales' && ($courier['status'] ?? '') === 'approved',
        'La identidad vinculante del Courier diverge.'
    );

    $customer = get_user_by('login', 'va_demo_carolina');
    vaCourierDemoAssert($customer instanceof WP_User, 'Cliente demo ausente.');
    $store = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}stores WHERE business_name=%s LIMIT 1",
        'Minimarket Los Vecinos'
    ), ARRAY_A);
    vaCourierDemoAssert(
        $store !== null && ($store['status'] ?? '') === 'active'
        && ($store['onboarding_status'] ?? '') === 'complete'
        && trim((string) ($store['address'] ?? '')) !== ''
        && trim((string) ($store['commune'] ?? '')) !== ''
        && trim((string) (($store['mobile'] ?: $store['phone']) ?? '')) !== '',
        'Minimarket Los Vecinos no satisface el contrato de retiro.'
    );
    $inventory = $wpdb->get_row($wpdb->prepare(
        "SELECT i.id inventory_id,i.product_id,i.price,i.stock,i.status inventory_status,p.name,p.slug,p.status product_status
         FROM {$prefix}inventory i JOIN {$prefix}products p ON p.id=i.product_id
         WHERE i.minimarket_id=%d AND p.slug=%s LIMIT 1",
        (int) $store['id'], 'coca-cola-original-15-l'
    ), ARRAY_A);
    vaCourierDemoAssert(
        $inventory !== null && ($inventory['inventory_status'] ?? '') === 'active'
        && ($inventory['product_status'] ?? '') === 'active' && (int) $inventory['stock'] === 12
        && (string) $inventory['price'] === '2190.00',
        'Inventory oficial para fixture Courier inválido.'
    );
    return compact('prefix', 'courierUser', 'courierId', 'courier', 'customer', 'store', 'inventory');
}

function vaCourierDemoOfficialOffers(array $context): array
{
    global $wpdb;
    $expected = [
        'coca-cola-original-15-l' => ['2190.00', 12],
        'tallarines-carozzi-400-g' => ['1050.00', 17],
        'salsa-tomates-carozzi' => ['750.00', 18],
        'super-8' => ['500.00', 11],
    ];
    $actual = [];
    foreach ($expected as $slug => [$price, $stock]) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT i.price,i.stock FROM {$context['prefix']}inventory i JOIN {$context['prefix']}products p ON p.id=i.product_id WHERE i.minimarket_id=%d AND p.slug=%s LIMIT 1",
            (int) $context['store']['id'], $slug
        ), ARRAY_A);
        vaCourierDemoAssert($row !== null && (string) $row['price'] === $price && (int) $row['stock'] === $stock, "Oferta oficial divergente: {$slug}");
        $actual[$slug] = [$price, $stock];
    }
    return $actual;
}

function vaCourierDemoRows(array $context): array
{
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count(VA_COURIER_DEMO_MARKERS), '%s'));
    $sql = $wpdb->prepare(
        "SELECT c.public_id,c.id checkout_id,c.user_id,c.status checkout_status,c.fulfillment_method,c.total_amount,
                co.id checkout_order_id,o.id order_id,o.customer_id,o.minimarket_id,o.total,o.status order_status,
                o.store_fulfillment_status,o.store_confirmed_at,o.store_preparation_started_at,o.store_ready_for_pickup_at,
                oi.id order_item_id,oi.product_id,oi.inventory_id,oi.quantity,oi.unit_price,oi.subtotal,
                d.id delivery_id,d.customer_id delivery_customer_id,d.minimarket_id delivery_minimarket_id,d.courier_id,d.status delivery_status,
                d.delivery_recipient_name,d.delivery_contact_phone,d.delivery_address_line1,d.delivery_commune
         FROM {$context['prefix']}checkouts c
         LEFT JOIN {$context['prefix']}checkout_orders co ON co.checkout_id=c.id
         LEFT JOIN {$context['prefix']}orders o ON o.id=co.order_id
         LEFT JOIN {$context['prefix']}order_items oi ON oi.order_id=o.id
         LEFT JOIN {$context['prefix']}deliveries d ON d.order_id=o.id
         WHERE c.public_id IN ({$placeholders}) ORDER BY c.public_id",
        ...array_values(VA_COURIER_DEMO_MARKERS)
    );
    return $wpdb->get_results($sql, ARRAY_A);
}

function vaCourierDemoValidate(array $context): array
{
    global $wpdb;
    $rows = vaCourierDemoRows($context);
    vaCourierDemoAssert(count($rows) === 3, 'El fixture no contiene exactamente tres grafos 1:1 completos.');
    $byMarker = array_column($rows, null, 'public_id');
    $expected = [
        VA_COURIER_DEMO_MARKERS['CD-01'] => ['pending', null],
        VA_COURIER_DEMO_MARKERS['CD-02'] => ['assigned', 16],
        VA_COURIER_DEMO_MARKERS['CD-03'] => ['picked_up', 16],
    ];
    foreach ($expected as $marker => [$status, $courierId]) {
        $row = $byMarker[$marker] ?? null;
        vaCourierDemoAssert($row !== null, "Escenario ausente: {$marker}");
        vaCourierDemoAssert(
            (int) $row['user_id'] === (int) $context['customer']->ID
            && $row['checkout_status'] === 'payment_completed' && $row['fulfillment_method'] === 'delivery'
            && $row['order_status'] === 'paid' && $row['store_fulfillment_status'] === 'ready_for_pickup'
            && trim((string) $row['store_confirmed_at']) !== ''
            && trim((string) $row['store_preparation_started_at']) !== ''
            && trim((string) $row['store_ready_for_pickup_at']) !== ''
            && (int) $row['customer_id'] === (int) $context['customer']->ID
            && (int) $row['minimarket_id'] === (int) $context['store']['id']
            && (int) $row['product_id'] === (int) $context['inventory']['product_id']
            && (int) $row['inventory_id'] === (int) $context['inventory']['inventory_id']
            && (int) $row['quantity'] === 1 && (string) $row['unit_price'] === '2190.00'
            && (string) $row['subtotal'] === '2190.00' && (string) $row['total'] === '2190.00'
            && (string) $row['total_amount'] === '2190.00'
            && (int) $row['delivery_customer_id'] === (int) $context['customer']->ID
            && (int) $row['delivery_minimarket_id'] === (int) $context['store']['id']
            && $row['delivery_status'] === $status
            && ($row['courier_id'] === null ? null : (int) $row['courier_id']) === $courierId
            && trim((string) $row['delivery_recipient_name']) !== ''
            && trim((string) $row['delivery_contact_phone']) !== ''
            && trim((string) $row['delivery_address_line1']) !== ''
            && trim((string) $row['delivery_commune']) !== '',
            "Invariantes inválidas: {$marker}"
        );
        $payments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$context['prefix']}payments WHERE checkout_id=%d", (int) $row['checkout_id']));
        vaCourierDemoAssert($payments === 0, "El fixture no debe inventar Payment: {$marker}");
    }
    $available = $byMarker[VA_COURIER_DEMO_MARKERS['CD-01']];
    vaCourierDemoAssert($available['delivery_status'] === 'pending', 'CD-01 no satisface Delivery.status=pending.');
    vaCourierDemoAssert($available['courier_id'] === null, 'CD-01 no satisface Delivery.courier_id IS NULL.');
    vaCourierDemoAssert($available['order_status'] === 'paid', 'CD-01 no satisface Order.status=paid.');
    vaCourierDemoAssert($available['store_fulfillment_status'] === 'ready_for_pickup', 'CD-01 no satisface Order.store_fulfillment_status=ready_for_pickup.');
    vaCourierDemoAssert($available['fulfillment_method'] === 'delivery', 'CD-01 no satisface Checkout.fulfillment_method=delivery.');
    vaCourierDemoAssert(trim((string) $context['store']['business_name']) !== '', 'CD-01 no tiene nombre de minimarket disponible.');
    vaCourierDemoAssert(trim((string) $context['store']['address']) !== '', 'CD-01 no tiene direccion de retiro.');
    vaCourierDemoAssert(trim((string) $context['store']['commune']) !== '', 'CD-01 no tiene comuna de retiro.');
    vaCourierDemoAssert(trim((string) ($context['store']['mobile'] ?: $context['store']['phone'])) !== '', 'CD-01 no tiene contacto de minimarket.');
    vaCourierDemoAssert(trim((string) $available['delivery_recipient_name']) !== '', 'CD-01 no tiene destinatario.');
    vaCourierDemoAssert(trim((string) $available['delivery_contact_phone']) !== '', 'CD-01 no tiene telefono de entrega.');
    vaCourierDemoAssert(trim((string) $available['delivery_address_line1']) !== '', 'CD-01 no tiene direccion de entrega.');
    vaCourierDemoAssert(trim((string) $available['delivery_commune']) !== '', 'CD-01 no tiene comuna de entrega.');

    return $rows;
}

function vaCourierDemoCourierProjection(array $rows, int $courierId): array
{
    $repository = new CourierDeliveryRepository();
    $fixtureIds = array_map(static fn(array $row): int => (int) $row['delivery_id'], $rows);
    $available = array_values(array_filter($repository->available(), static fn(array $row): bool => in_array((int) $row['id'], $fixtureIds, true)));
    $owned = array_values(array_filter($repository->owned($courierId), static fn(array $row): bool => in_array((int) $row['id'], $fixtureIds, true)));
    $assigned = array_values(array_filter($owned, static fn(array $row): bool => $row['status'] === 'assigned'));
    $inProgress = array_values(array_filter($owned, static fn(array $row): bool => $row['status'] === 'picked_up'));
    $byMarker = array_column($rows, null, 'public_id');

    vaCourierDemoAssert(count($available) === 1, 'AVAILABLE demo debe ser 1 segun CourierDeliveryRepository::available().');
    vaCourierDemoAssert((int) $available[0]['id'] === (int) $byMarker[VA_COURIER_DEMO_MARKERS['CD-01']]['delivery_id'], 'AVAILABLE demo no corresponde a CD-01.');
    vaCourierDemoAssert(count($assigned) === 1, 'ASSIGNED demo debe ser 1.');
    vaCourierDemoAssert(count($inProgress) === 1, 'IN_PROGRESS demo debe ser 1.');

    return compact('fixtureIds', 'available', 'owned', 'assigned', 'inProgress');
}
