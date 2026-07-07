<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertReservationOrder(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertReservationOrderSame(mixed $expected, mixed $actual): void
{
    assertReservationOrder(
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
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
$reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
$transaction = $wpdb->query('START TRANSACTION');
assertReservationOrder($transaction !== false, 'No se inicio la transaccion.');

try {
    $now = current_time('mysql');
    $minimarketId = random_int(37000000, 37999999);
    $makeInventory = static function (
        int $productId,
        int $stock
    ) use ($inventoryRepository, $minimarketId, $now): int {
        return $inventoryRepository->create([
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'price' => 1000.0,
            'stock' => $stock,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $stock = static fn (int $id): int => (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT stock FROM {$inventoryTable} WHERE id = %d",
            $id
        )
    );
    $firstProductId = random_int(38000000, 38999999);
    $secondProductId = random_int(39000000, 39999999);
    $firstInventoryId = $makeInventory($firstProductId, 10);
    $secondInventoryId = $makeInventory($secondProductId, 5);
    $service = new OrderService();
    $customerId = random_int(40000000, 40999999);

    $order = $service->create([
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'items' => [
            [
                'product_id' => $firstProductId,
                'inventory_id' => $firstInventoryId,
                'quantity' => 3,
                'unit_price' => 1000.0,
            ],
            [
                'product_id' => $secondProductId,
                'inventory_id' => $secondInventoryId,
                'quantity' => 2,
                'unit_price' => 500.0,
            ],
        ],
    ]);
    $orderId = (int) $order['id'];
    $reservations = $reservationRepository->findByOrderId($orderId);

    assertReservationOrderSame(2, count($reservations));
    assertReservationOrderSame(2, count($order['reservations']));
    assertReservationOrderSame('active', $reservations[0]['status']);
    assertReservationOrderSame('active', $reservations[1]['status']);
    assertReservationOrderSame(
        15 * MINUTE_IN_SECONDS,
        strtotime($reservations[0]['expires_at'])
        - strtotime($reservations[0]['reserved_at'])
    );
    assertReservationOrderSame(7, $stock($firstInventoryId));
    assertReservationOrderSame(3, $stock($secondInventoryId));
    assertReservationOrderSame(
        2,
        count(array_unique(array_column($reservations, 'id')))
    );

    $ordersBeforeInsufficient = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable}"
    );

    try {
        $service->create([
            'customer_id' => $customerId + 1,
            'minimarket_id' => $minimarketId,
            'items' => [[
                'product_id' => $secondProductId,
                'inventory_id' => $secondInventoryId,
                'quantity' => 4,
                'unit_price' => 500.0,
            ]],
        ]);
        throw new RuntimeException('Se esperaba stock insuficiente.');
    } catch (InvalidArgumentException) {
    }

    assertReservationOrderSame(3, $stock($secondInventoryId));
    assertReservationOrderSame(
        $ordersBeforeInsufficient,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );

    $failureInventoryId = $makeInventory(
        random_int(41000000, 41999999),
        6
    );
    $failingReservationRepository = new class extends ReservationRepository {
        public function create(array $data): int
        {
            throw new PersistenceException('Fallo de reserva simulado.');
        }
    };
    $reservationFailureService = new OrderService(
        null,
        new ReservationService($failingReservationRepository)
    );

    try {
        $reservationFailureService->create([
            'customer_id' => $customerId + 2,
            'minimarket_id' => $minimarketId,
            'items' => [[
                'product_id' => 41000001,
                'inventory_id' => $failureInventoryId,
                'quantity' => 4,
                'unit_price' => 100.0,
            ]],
        ]);
        throw new RuntimeException('Se esperaba fallo de reserva.');
    } catch (RuntimeException $exception) {
        assertReservationOrder(
            $exception->getPrevious() instanceof PersistenceException,
            'No se conservo el fallo de reserva.'
        );
    }
    assertReservationOrderSame(6, $stock($failureInventoryId));
    assertReservationOrderSame(
        0,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ordersTable} WHERE customer_id = %d",
            $customerId + 2
        ))
    );

    $orderFailureInventoryId = $makeInventory(
        random_int(42000000, 42999999),
        8
    );
    $failingOrderRepository = new class extends OrderRepository {
        public function create(array $order): int
        {
            throw new PersistenceException('Fallo de pedido simulado.');
        }
    };
    $orderFailureService = new OrderService($failingOrderRepository);

    try {
        $orderFailureService->create([
            'customer_id' => $customerId + 3,
            'minimarket_id' => $minimarketId,
            'items' => [[
                'product_id' => 42000001,
                'inventory_id' => $orderFailureInventoryId,
                'quantity' => 5,
                'unit_price' => 100.0,
            ]],
        ]);
        throw new RuntimeException('Se esperaba fallo de pedido.');
    } catch (RuntimeException $exception) {
        assertReservationOrder(
            $exception->getPrevious() instanceof PersistenceException,
            'No se conservo el fallo de pedido.'
        );
    }
    assertReservationOrderSame(8, $stock($orderFailureInventoryId));
    assertReservationOrderSame(
        0,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ordersTable} WHERE customer_id = %d",
            $customerId + 3
        ))
    );
    assertReservationOrderSame(
        0,
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$reservationsTable} r
             LEFT JOIN {$ordersTable} o ON o.id = r.order_id
             WHERE o.id IS NULL
               AND r.inventory_id IN (%d, %d)",
            $failureInventoryId,
            $orderFailureInventoryId
        ))
    );
    assertReservationOrder(
        $stock($firstInventoryId) >= 0
        && $stock($secondInventoryId) >= 0
        && $stock($failureInventoryId) >= 0
        && $stock($orderFailureInventoryId) >= 0,
        'El flujo produjo stock negativo.'
    );

    echo "PASS reservation-order-integration-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
