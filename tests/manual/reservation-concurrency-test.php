<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertReservationConcurrency(
    bool $condition,
    string $message
): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertReservationConcurrencySame(
    mixed $expected,
    mixed $actual
): void {
    assertReservationConcurrency(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

global $wpdb;

$inventoryRepository = new InventoryRepository();
$reservationRepository = new ReservationRepository();
$firstWorker = new OrderService();
$secondWorker = new OrderService();
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
$reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
$transaction = $wpdb->query('START TRANSACTION');
assertReservationConcurrency(
    $transaction !== false,
    'No se inicio la transaccion.'
);

try {
    $now = current_time('mysql');
    $productId = random_int(43000000, 43999999);
    $minimarketId = random_int(44000000, 44999999);
    $inventoryId = $inventoryRepository->create([
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => 750.0,
        'stock' => 2,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $firstCustomerId = random_int(45000000, 45999999);
    $secondCustomerId = random_int(46000000, 46999999);
    $payload = static fn (int $customerId): array => [
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'items' => [[
            'product_id' => $productId,
            'inventory_id' => $inventoryId,
            'quantity' => 2,
            'unit_price' => 750.0,
        ]],
    ];

    $firstOrder = $firstWorker->create($payload($firstCustomerId));
    $secondRejected = false;

    try {
        $secondWorker->create($payload($secondCustomerId));
    } catch (InvalidArgumentException) {
        $secondRejected = true;
    }

    assertReservationConcurrency(
        $secondRejected,
        'El segundo intento obtuvo el mismo stock.'
    );
    $stock = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT stock FROM {$inventoryTable} WHERE id = %d",
        $inventoryId
    ));
    assertReservationConcurrencySame(0, $stock);
    assertReservationConcurrency($stock >= 0, 'El stock quedo negativo.');

    $firstReservations = $reservationRepository->findByOrderId(
        (int) $firstOrder['id']
    );
    assertReservationConcurrencySame(1, count($firstReservations));
    assertReservationConcurrencySame(
        'active',
        $firstReservations[0]['status']
    );
    assertReservationConcurrencySame(
        1,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$reservationsTable}
             WHERE inventory_id = %d",
            $inventoryId
        ))
    );
    assertReservationConcurrencySame(
        0,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$ordersTable}
             WHERE customer_id = %d",
            $secondCustomerId
        ))
    );

    echo "PASS reservation-concurrency-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
