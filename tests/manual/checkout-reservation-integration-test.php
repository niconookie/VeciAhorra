<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Container;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Checkout\Service\CheckoutValidationService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCheckoutReservation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCheckoutReservationSame(mixed $expected, mixed $actual): void
{
    assertCheckoutReservation(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

global $wpdb;

$cartRepository = new CartRepository();
$cartService = new CartService($cartRepository);
$inventoryRepository = new InventoryRepository();
$productRepository = new ProductRepository();
$storeRepository = new StoreRepository();
$checkoutService = (new Container())->make(CheckoutService::class);
$validationService = (new Container())->make(
    CheckoutValidationService::class
);
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
$orderItemsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'order_items';
$reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
$transaction = $wpdb->query('START TRANSACTION');
assertCheckoutReservation($transaction !== false, 'No se inicio transaccion.');

try {
    $now = current_time('mysql');
    $minimarketBase = random_int(58000000, 58999999);
    $minimarketOffset = 0;
    $makeProduct = static function (
        string $status = Product::STATUS_ACTIVE
    ) use ($productRepository, $now): int {
        $token = bin2hex(random_bytes(8));

        return $productRepository->create([
            'woo_product_id' => null,
            'name' => 'Reservation checkout ' . $token,
            'slug' => 'reservation-checkout-' . $token,
            'sku' => null,
            'description' => null,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'image_id' => null,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $makeInventory = static function (
        int $productId,
        float $price,
        int $stock,
        string $status = 'active'
    ) use (
        $inventoryRepository,
        $storeRepository,
        $minimarketBase,
        $now,
        &$minimarketOffset
    ): int {
        $minimarketOffset++;
        $storeId = $minimarketBase + $minimarketOffset;
        $storeRepository->create([
            'id' => $storeId, 'business_name' => 'Reservation store ' . $storeId,
            'legal_name' => 'Reservation legal', 'owner_name' => 'Owner',
            'rut' => '1-9', 'email' => "reservation-{$storeId}@example.test",
            'phone' => '000', 'mobile' => null, 'address' => null,
            'commune' => null, 'city' => null, 'region' => null,
            'status' => 'active', 'onboarding_status' => 'complete',
            'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $inventoryRepository->create([
            'product_id' => $productId,
            'minimarket_id' => $storeId,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $stock = static fn (int $inventoryId): int => (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT stock FROM {$inventoryTable} WHERE id = %d",
            $inventoryId
        )
    );
    $reservationCount = static fn (array $inventoryIds): int =>
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$reservationsTable}
             WHERE inventory_id IN ("
                . implode(', ', array_fill(0, count($inventoryIds), '%d'))
                . ')',
            ...$inventoryIds
        ));
    $seedStaleCartItem = static function (
        array $owner,
        int $inventoryId,
        int $productId,
        int $quantity,
        string $snapshot
    ) use ($cartRepository, $inventoryRepository, $now): int {
        $inventoryRow = $inventoryRepository->find($inventoryId);

        return $cartRepository->create([
            'session_id' => $owner['session_id'],
            'user_id' => $owner['user_id'],
            'inventory_id' => $inventoryId,
            'product_id' => $productId,
            'minimarket_id' => (int) $inventoryRow['minimarket_id'],
            'quantity' => $quantity,
            'unit_price_snapshot' => $snapshot,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };

    $emptyOwner = [
        'session_id' => 'empty-checkout-' . bin2hex(random_bytes(8)),
        'user_id' => null,
    ];
    $reservationsBeforeEmpty = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$reservationsTable}"
    );
    $empty = $checkoutService->initialize($emptyOwner);
    assertCheckoutReservationSame(false, $empty['valid']);
    assertCheckoutReservationSame(false, $empty['reservation_created']);
    assertCheckoutReservationSame([], $empty['reservations']);
    assertCheckoutReservationSame(
        $reservationsBeforeEmpty,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reservationsTable}")
    );

    $inactiveProductId = $makeProduct();
    $inactiveInventoryId = $makeInventory(
        $inactiveProductId,
        500.0,
        5,
        'inactive'
    );
    $inactiveOwner = [
        'session_id' => 'inactive-checkout-' . bin2hex(random_bytes(8)),
        'user_id' => null,
    ];
    $seedStaleCartItem(
        $inactiveOwner,
        $inactiveInventoryId,
        $inactiveProductId,
        1,
        '500.00'
    );
    $inactive = $checkoutService->initialize($inactiveOwner);
    assertCheckoutReservationSame(false, $inactive['valid']);
    assertCheckoutReservationSame(false, $inactive['reservation_created']);
    assertCheckoutReservationSame(5, $stock($inactiveInventoryId));
    assertCheckoutReservationSame(0, $reservationCount([$inactiveInventoryId]));

    $insufficientProductId = $makeProduct();
    $insufficientInventoryId = $makeInventory(
        $insufficientProductId,
        600.0,
        1
    );
    $insufficientOwner = [
        'session_id' => 'stock-checkout-' . bin2hex(random_bytes(8)),
        'user_id' => null,
    ];
    $seedStaleCartItem(
        $insufficientOwner,
        $insufficientInventoryId,
        $insufficientProductId,
        2,
        '600.00'
    );
    $insufficient = $checkoutService->initialize($insufficientOwner);
    assertCheckoutReservationSame(false, $insufficient['valid']);
    assertCheckoutReservationSame(false, $insufficient['reservation_created']);
    assertCheckoutReservationSame(1, $stock($insufficientInventoryId));
    assertCheckoutReservationSame(
        0,
        $reservationCount([$insufficientInventoryId])
    );

    $firstProductId = $makeProduct();
    $secondProductId = $makeProduct();
    $firstInventoryId = $makeInventory($firstProductId, 1000.0, 5);
    $secondInventoryId = $makeInventory($secondProductId, 750.0, 4);
    $administratorIds = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    ]);
    assertCheckoutReservation($administratorIds !== [], 'Falta administrador.');
    $customerId = (int) $administratorIds[0];
    $successOwner = ['session_id' => null, 'user_id' => $customerId];
    $cartService->clearCart($successOwner);
    $cartService->addItem($successOwner, $firstInventoryId, 2);
    $cartService->addItem($successOwner, $secondInventoryId, 1);
    $ordersBefore = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable}"
    );

    wp_set_current_user($customerId);
    $request = new WP_REST_Request('POST', '/veciahorra/v1/checkout');
    $request->set_header('content-type', 'application/json');
    $request->set_header('Idempotency-Key', 'checkout-reservation-key-0001');
    $request->set_body('{"fulfillment_method":"pickup"}');
    $response = rest_do_request($request);
    $success = $response->get_data()['data'] ?? [];

    assertCheckoutReservationSame(201, $response->get_status());
    assertCheckoutReservationSame(true, $success['valid']);
    assertCheckoutReservationSame(true, $success['reservation_created']);
    assertCheckoutReservationSame(true, $success['order_created']);
    assertCheckoutReservationSame(2, count($success['orders']));
    assertCheckoutReservationSame(2, count($success['reservations']));
    assertCheckoutReservationSame('2750.00', $success['summary']['total']);
    assertCheckoutReservationSame(3, $stock($firstInventoryId));
    assertCheckoutReservationSame(3, $stock($secondInventoryId));
    assertCheckoutReservationSame(
        $ordersBefore + 2,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );

    foreach ($success['orders'] as $order) {
        assertCheckoutReservationSame('reserved', $order['status']);
        assertCheckoutReservationSame(1, count($order['items']));
        $item = $order['items'][0];
        $persistedItem = $wpdb->get_row($wpdb->prepare(
            "SELECT unit_price, subtotal
             FROM {$orderItemsTable}
             WHERE order_id = %d AND inventory_id = %d",
            (int) $order['id'],
            (int) $item['inventory_id']
        ), ARRAY_A);
        assertCheckoutReservation(is_array($persistedItem), 'Falta order_item.');
        assertCheckoutReservationSame(
            number_format((float) $item['unit_price'], 2, '.', ''),
            number_format((float) $persistedItem['unit_price'], 2, '.', '')
        );
        assertCheckoutReservationSame(
            number_format((float) $item['subtotal'], 2, '.', ''),
            number_format((float) $persistedItem['subtotal'], 2, '.', '')
        );
        assertCheckoutReservationSame(
            number_format((float) $item['subtotal'], 2, '.', ''),
            number_format((float) $order['total'], 2, '.', '')
        );
    }

    foreach ($success['reservations'] as $reservation) {
        assertCheckoutReservationSame('active', $reservation['status']);
        assertCheckoutReservation(
            (int) $reservation['order_id'] > 0,
            'La reserva no fue asociada al pedido.'
        );
        assertCheckoutReservationSame(
            15 * MINUTE_IN_SECONDS,
            strtotime($reservation['expires_at'])
                - strtotime($reservation['reserved_at'])
        );
    }
    assertCheckoutReservationSame(
        min(array_column($success['reservations'], 'expires_at')),
        $success['expires_at']
    );

    $rollbackFirstProduct = $makeProduct();
    $rollbackSecondProduct = $makeProduct();
    $rollbackFirstInventory = $makeInventory(
        $rollbackFirstProduct,
        300.0,
        6
    );
    $rollbackSecondInventory = $makeInventory(
        $rollbackSecondProduct,
        400.0,
        7
    );
    $rollbackOwner = [
        'session_id' => null,
        'user_id' => $customerId,
    ];
    $cartService->addItem($rollbackOwner, $rollbackFirstInventory, 2);
    $cartService->addItem($rollbackOwner, $rollbackSecondInventory, 3);
    $failingRepository = new class extends ReservationRepository {
        private int $attempts = 0;

        public function create(array $data): int
        {
            $this->attempts++;

            if ($this->attempts === 2) {
                throw new PersistenceException(
                    'Fallo intermedio de reserva simulado.'
                );
            }

            return parent::create($data);
        }
    };
    $failingCheckout = new CheckoutService(
        $validationService,
        new ReservationService($failingRepository),
        (new Container())->make(OrderService::class)
    );

    try {
        $failingCheckout->initialize($rollbackOwner);
        throw new RuntimeException('Se esperaba fallo intermedio.');
    } catch (RuntimeException $exception) {
        assertCheckoutReservation(
            $exception->getPrevious() instanceof PersistenceException,
            'No se conservo la causa del fallo intermedio.'
        );
    }

    assertCheckoutReservationSame(6, $stock($rollbackFirstInventory));
    assertCheckoutReservationSame(7, $stock($rollbackSecondInventory));
    assertCheckoutReservationSame(
        0,
        $reservationCount([
            $rollbackFirstInventory,
            $rollbackSecondInventory,
        ])
    );
    assertCheckoutReservationSame(
        $ordersBefore + 2,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );

    $makeOrderFailureScenario = static function (
        int $customerId,
        OrderRepository $orderRepository,
        int $numberOfItems
    ) use (
        $cartService,
        $validationService,
        $makeProduct,
        $makeInventory,
        $stock,
        $reservationCount,
        $wpdb,
        $ordersTable,
        $orderItemsTable
    ): void {
        $owner = ['session_id' => null, 'user_id' => $customerId];
        $inventoryIds = [];
        $stocks = [];
        $orphanItemsBefore = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$orderItemsTable} oi
             LEFT JOIN {$ordersTable} o ON o.id = oi.order_id
             WHERE o.id IS NULL"
        );

        for ($index = 0; $index < $numberOfItems; $index++) {
            $inventoryId = $makeInventory(
                $makeProduct(),
                225.0 + $index,
                8 + $index
            );
            $inventoryIds[] = $inventoryId;
            $stocks[$inventoryId] = 8 + $index;
            $cartService->addItem($owner, $inventoryId, 2);
        }

        $failingCheckout = new CheckoutService(
            $validationService,
            new ReservationService(),
            new OrderService($orderRepository)
        );

        try {
            $failingCheckout->initialize($owner);
            throw new RuntimeException('Se esperaba fallo creando pedido.');
        } catch (RuntimeException $exception) {
            assertCheckoutReservation(
                $exception->getPrevious() instanceof PersistenceException,
                'No se conservo la causa del fallo de pedido.'
            );
        }

        foreach ($stocks as $inventoryId => $expectedStock) {
            assertCheckoutReservationSame($expectedStock, $stock($inventoryId));
        }
        assertCheckoutReservationSame(0, $reservationCount($inventoryIds));
        assertCheckoutReservationSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$ordersTable} WHERE customer_id = %d",
                $customerId
            ))
        );
        assertCheckoutReservationSame(
            $orphanItemsBefore,
            (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$orderItemsTable} oi
                 LEFT JOIN {$ordersTable} o ON o.id = oi.order_id
                 WHERE o.id IS NULL"
            )
        );
    };

    $firstOrderFailureRepository = new class extends OrderRepository {
        public function createItems(int $orderId, array $items): void
        {
            throw new PersistenceException(
                'Fallo de order_items simulado.'
            );
        }
    };
    $makeOrderFailureScenario(
        random_int(700000000, 799999999),
        $firstOrderFailureRepository,
        1
    );

    $secondOrderFailureRepository = new class extends OrderRepository {
        private int $attempts = 0;

        public function create(array $order): int
        {
            $this->attempts++;

            if ($this->attempts === 2) {
                throw new PersistenceException(
                    'Fallo de segundo pedido simulado.'
                );
            }

            return parent::create($order);
        }
    };
    $makeOrderFailureScenario(
        random_int(800000000, 899999999),
        $secondOrderFailureRepository,
        2
    );

    $checkoutSource = file_get_contents(
        dirname(__DIR__, 2) . '/app/Modules/Checkout/Service/CheckoutService.php'
    );
    assertCheckoutReservation(
        is_string($checkoutSource)
        && ! str_contains($checkoutSource, 'commitStock')
        && ! str_contains($checkoutSource, '$wpdb'),
        'CheckoutService contiene integraciones fuera de alcance.'
    );

    echo "PASS checkout-reservation-integration-test\n";
} finally {
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
}
