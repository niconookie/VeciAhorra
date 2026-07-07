<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCheckoutValidation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCheckoutValidationSame(mixed $expected, mixed $actual): void
{
    assertCheckoutValidation(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function checkoutValidationCodes(array $result): array
{
    return array_values(array_unique(array_column(
        $result['errors'] ?? [],
        'code'
    )));
}

global $wpdb;

$cartRepository = new CartRepository();
$cartService = new CartService($cartRepository);
$inventoryRepository = new InventoryRepository();
$productRepository = new ProductRepository();
$checkoutService = (new Container())->make(CheckoutService::class);
$transaction = $wpdb->query('START TRANSACTION');
assertCheckoutValidation($transaction !== false, 'No se inicio transaccion.');

try {
    $now = current_time('mysql');
    $minimarketId = random_int(57000000, 57999999);
    $sessionId = 'checkout-validation-' . bin2hex(random_bytes(8));
    $owner = ['session_id' => $sessionId, 'user_id' => null];
    $makeProduct = static function (
        string $status
    ) use ($productRepository, $now): int {
        $token = bin2hex(random_bytes(8));

        return $productRepository->create([
            'woo_product_id' => null,
            'name' => 'Checkout product ' . $token,
            'slug' => 'checkout-product-' . $token,
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
    $inventoryOffset = 0;
    $makeInventory = static function (
        int $productId,
        float $price,
        int $stock,
        string $status = 'active'
    ) use (
        $inventoryRepository,
        $minimarketId,
        $now,
        &$inventoryOffset
    ): int {
        $inventoryOffset++;

        return $inventoryRepository->create([
            'product_id' => $productId,
            'minimarket_id' => $minimarketId + $inventoryOffset,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $insertCartItem = static function (
        int $inventoryId,
        int $productId,
        int $quantity,
        mixed $snapshot
    ) use (
        $cartRepository,
        $sessionId,
        $minimarketId,
        $now
    ): int {
        return $cartRepository->create([
            'session_id' => $sessionId,
            'user_id' => null,
            'inventory_id' => $inventoryId,
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'quantity' => $quantity,
            'unit_price_snapshot' => $snapshot,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };

    $empty = $checkoutService->validate([
        'session_id' => 'empty-' . bin2hex(random_bytes(8)),
        'user_id' => null,
    ]);
    assertCheckoutValidationSame(false, $empty['valid']);
    assertCheckoutValidationSame('empty_cart', $empty['errors'][0]['code']);
    assertCheckoutValidationSame('0.00', $empty['summary']['total']);

    $activeProductId = $makeProduct(Product::STATUS_ACTIVE);
    $inactiveProductId = $makeProduct(Product::STATUS_INACTIVE);

    $validInventoryId = $makeInventory($activeProductId, 1000.0, 10);
    $cartService->addItem($owner, $validInventoryId, 2);

    $missingInventoryId = random_int(900000000, 999999999);
    $insertCartItem($missingInventoryId, $activeProductId, 1, 500.0);

    $inactiveInventoryId = $makeInventory(
        $activeProductId,
        600.0,
        10,
        'inactive'
    );
    $cartService->addItem($owner, $inactiveInventoryId, 1);

    $invalidQuantityInventoryId = $makeInventory(
        $activeProductId,
        700.0,
        10
    );
    $insertCartItem(
        $invalidQuantityInventoryId,
        $activeProductId,
        0,
        700.0
    );

    $insufficientInventoryId = $makeInventory(
        $activeProductId,
        800.0,
        1
    );
    $cartService->addItem($owner, $insufficientInventoryId, 2);

    $changedPriceInventoryId = $makeInventory(
        $activeProductId,
        900.0,
        10
    );
    $cartService->addItem($owner, $changedPriceInventoryId, 1);
    $inventoryRepository->update($changedPriceInventoryId, [
        'price' => 950.0,
        'updated_at' => current_time('mysql'),
    ]);

    $inactiveProductInventoryId = $makeInventory(
        $inactiveProductId,
        1100.0,
        10
    );
    $cartService->addItem($owner, $inactiveProductInventoryId, 1);

    $ordersTable = $wpdb->prefix . Config::TABLE_PREFIX . 'orders';
    $reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
    $inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
    $ordersBefore = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable}"
    );
    $reservationsBefore = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$reservationsTable}"
    );
    $stocksBefore = $wpdb->get_results(
        "SELECT id, stock FROM {$inventoryTable} ORDER BY id",
        ARRAY_A
    );

    $result = $checkoutService->validate($owner);
    $codes = checkoutValidationCodes($result);

    assertCheckoutValidationSame(false, $result['valid']);
    assertCheckoutValidationSame(7, $result['summary']['item_count']);
    assertCheckoutValidationSame(1, $result['summary']['valid_item_count']);
    assertCheckoutValidationSame(6, $result['summary']['invalid_item_count']);
    assertCheckoutValidationSame('2000.00', $result['summary']['total']);
    assertCheckoutValidationSame(7, count($result['items']));

    foreach ([
        'inventory_not_found',
        'inventory_inactive',
        'invalid_quantity',
        'insufficient_stock',
        'price_changed',
        'product_inactive',
    ] as $code) {
        assertCheckoutValidation(
            in_array($code, $codes, true),
            "Falta el error {$code}."
        );
    }

    $validItems = array_values(array_filter(
        $result['items'],
        static fn (array $item): bool => $item['valid'] === true
    ));
    assertCheckoutValidationSame(1, count($validItems));
    assertCheckoutValidationSame('2000.00', $validItems[0]['subtotal']);
    assertCheckoutValidationSame('1000.00', $validItems[0]['unit_price_snapshot']);

    $request = new WP_REST_Request(
        'POST',
        '/veciahorra/v1/checkout/validate'
    );
    $request->set_query_params(['session_id' => $sessionId]);
    $request->set_header('content-type', 'application/json');
    $request->set_body('{}');
    $response = rest_do_request($request);
    assertCheckoutValidationSame(200, $response->get_status());
    assertCheckoutValidationSame(
        $result['summary'],
        $response->get_data()['data']['summary'] ?? null
    );

    assertCheckoutValidationSame(
        $ordersBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ordersTable}")
    );
    assertCheckoutValidationSame(
        $reservationsBefore,
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reservationsTable}")
    );
    assertCheckoutValidationSame(
        $stocksBefore,
        $wpdb->get_results(
            "SELECT id, stock FROM {$inventoryTable} ORDER BY id",
            ARRAY_A
        )
    );

    echo "PASS checkout-validation-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
