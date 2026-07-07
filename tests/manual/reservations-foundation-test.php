<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Core\Config;
use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreateReservationsTable;
use VeciAhorra\Modules\Reservations\Routes\ReservationRoutes;
use VeciAhorra\Modules\Reservations\Service\ReservationService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertReservation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;

$migration = new CreateReservationsTable();
$migration->up();
$migration->up();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
assertReservation(
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table,
    'No existe la tabla de reservas.'
);
$columns = array_column($wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A), 'Field');
foreach ([
    'id', 'order_id', 'inventory_id', 'product_id', 'minimarket_id',
    'quantity', 'status', 'reserved_at', 'expires_at', 'released_at',
    'created_at', 'updated_at',
] as $column) {
    assertReservation(in_array($column, $columns, true), "Falta la columna {$column}.");
}
$method = new ReflectionMethod(MigrationManager::class, 'migrations');
$method->setAccessible(true);
assertReservation(
    count(array_filter(
        $method->invoke(null),
        static fn (object $item): bool => $item instanceof CreateReservationsTable
    )) === 1,
    'La migracion de reservas debe registrarse exactamente una vez.'
);

$service = new ReservationService();
$transaction = $wpdb->query('START TRANSACTION');
assertReservation($transaction !== false, 'No se inicio la transaccion.');

try {
    $orderId = random_int(20000000, 20999999);
    $now = current_time('mysql');
    $inventoryId = (new InventoryRepository())->create([
        'product_id' => 201, 'minimarket_id' => 301,
        'price' => 1000.0, 'stock' => 10, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $created = $service->create([
        'order_id' => $orderId,
        'inventory_id' => $inventoryId,
        'product_id' => 201,
        'minimarket_id' => 301,
        'quantity' => 2,
    ]);

    assertReservation((int) $created['id'] > 0, 'No se creo la reserva.');
    assertReservation($created['status'] === 'active', 'Estado inicial incorrecto.');
    assertReservation(
        strtotime($created['expires_at']) - strtotime($created['reserved_at']) === 15 * MINUTE_IN_SECONDS,
        'La reserva no expira en 15 minutos.'
    );
    $byOrder = $service->findByOrderId($orderId);
    assertReservation(count($byOrder) === 1, 'No se resolvio la reserva por order_id.');

    foreach (['active', 'released', 'expired', 'consumed'] as $status) {
        $service->assertAllowedStatus($status);
    }

    try {
        $service->assertAllowedStatus('invalid');
        throw new RuntimeException('Se acepto un estado invalido.');
    } catch (InvalidArgumentException) {
    }

    assertReservation(
        (new Container())->make(ReservationRoutes::class) instanceof ReservationRoutes,
        'El modulo Reservations no carga desde el contenedor.'
    );

    echo "PASS reservations-foundation-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
