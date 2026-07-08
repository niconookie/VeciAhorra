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
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

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
$checkoutService = (new Container())->make(CheckoutService::class);
$validationService = (new Container())->make(
    CheckoutValidationService::class
);
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
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
        $minimarketBase,
        $now,
        &$minimarketOffset
    ): int {
        $minimarketOffset++;

        return $inventoryRepository->create([
            'product_id' => $productId,
            'minimarket_id' => $minimarketBase + $minimarketOffset,
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
    $cartService->addItem($inactiveOwner, $inactiveInventoryId, 1);
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
    $cartService->addItem($insufficientOwner, $insufficientInventoryId, 2);
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
    $successSession = 'success-checkout-' . bin2hex(random_bytes(8));
    $successOwner = ['session_id' => $successSession, 'user_id' => null];
    $cartService->addItem($successOwner, $firstInventoryId, 2);
    $cartService->addItem($successOwner, $secondInventoryId, 1);
    $ordersBefore = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable}"
    );

    $request = new WP_REST_Request('POST', '/veciahorra/v1/checkout');
    $request->set_query_params(['session_id' => $successSession]);
    $request->set_header('content-type', 'application/json');
    $request->set_body('{}');
    $response = rest_do_request($request);
    $success = $response->get_data()['data'] ?? [];

    assertCheckoutReservationSame(201, $response->get_status());
    assertCheckoutReservationSame(true, $success['valid']);
    assertCheckoutReservationSame(true, $success['reservation_created']);
    assertCheckoutReservationSame(2, count($success['reservations']));
    assertCheckoutReservationSame('2750.00', $success['summary']['total']);
    assertCheckoutReservationSame(3, $stock($firstInventoryId));
    assertCheckoutReservationSame(3, $stock($secondInventoryId));
    assertCheckoutReservationSame(
        $ordersBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );

    foreach ($success['reservations'] as $reservation) {
        assertCheckoutReservationSame('active', $reservation['status']);
        assertCheckoutReservationSame(null, $reservation['order_id']);
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
        'session_id' => 'rollback-checkout-' . bin2hex(random_bytes(8)),
        'user_id' => null,
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
        new ReservationService($failingRepository)
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
        $ordersBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );

    $checkoutSource = file_get_contents(
        dirname(__DIR__, 2) . '/app/Modules/Checkout/Service/CheckoutService.php'
    );
    assertCheckoutReservation(
        is_string($checkoutSource)
        && ! str_contains($checkoutSource, 'OrderService')
        && ! str_contains($checkoutSource, 'commitStock')
        && ! str_contains($checkoutSource, '$wpdb'),
        'CheckoutService contiene integraciones fuera de alcance.'
    );

    echo "PASS checkout-reservation-integration-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
