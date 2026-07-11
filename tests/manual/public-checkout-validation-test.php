<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Checkout\Requests\CheckoutRequest;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPublicCheckoutValidation(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPublicCheckoutValidationSame(mixed $expected, mixed $actual): void
{
    assertPublicCheckoutValidation(
        $expected === $actual,
        sprintf("Esperado: %s\nRecibido: %s", var_export($expected, true), var_export($actual, true))
    );
}

global $wpdb;

$transaction = $wpdb->query('START TRANSACTION');
assertPublicCheckoutValidation($transaction !== false, 'No se inicio transaccion.');

try {
    $now = current_time('mysql');
    $token = 'public-checkout-validation-' . bin2hex(random_bytes(5));
    $session = $token . '-guest';
    $products = new ProductRepository();
    $stores = new StoreRepository();
    $inventory = new InventoryRepository();
    $cartRepository = new CartRepository();
    $cartService = new CartService($cartRepository);
    $storeId = $stores->create([
        'business_name' => 'Checkout validation store',
        'legal_name' => 'Checkout validation legal', 'owner_name' => 'Owner',
        'rut' => '1-9', 'email' => $token . '@example.test', 'phone' => '000',
        'mobile' => null, 'address' => null, 'commune' => null, 'city' => null,
        'region' => null, 'status' => 'active', 'onboarding_status' => 'complete',
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $productId = $products->create([
        'woo_product_id' => null, 'name' => 'Checkout validation product',
        'slug' => $token, 'sku' => null, 'description' => null,
        'category_id' => null, 'brand_id' => null, 'unit_id' => null,
        'image_id' => null, 'status' => Product::STATUS_ACTIVE,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $inventoryId = $inventory->create([
        'product_id' => $productId, 'minimarket_id' => $storeId,
        'price' => 4000.0, 'stock' => 5, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $cartService->addItem(
        ['session_id' => $session, 'user_id' => null],
        $inventoryId,
        2
    );

    assertPublicCheckoutValidationSame([], (new CheckoutRequest([]))->validated());
    try {
        (new CheckoutRequest(['first_name' => 'Manipulado']))->validated();
        throw new RuntimeException('CheckoutRequest acepto campos.');
    } catch (InvalidArgumentException) {
        // Contrato esperado: objeto JSON vacío.
    }

    $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
    $tables = [
        'cart' => $prefix . 'cart_items',
        'orders' => $prefix . 'orders',
        'reservations' => $prefix . 'reservations',
        'payments' => $prefix . 'payments',
        'inventory' => $prefix . 'inventory',
    ];
    $before = [
        'cart' => $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['cart']} WHERE session_id = %s ORDER BY id", $session),
            ARRAY_A
        ),
        'orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['orders']}"),
        'reservations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['reservations']}"),
        'payments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['payments']}"),
        'stock' => $wpdb->get_var(
            $wpdb->prepare("SELECT stock FROM {$tables['inventory']} WHERE id = %d", $inventoryId)
        ),
    ];

    wp_set_current_user(0);
    $request = new WP_REST_Request('POST', '/veciahorra/v1/checkout/validate');
    $request->set_header('content-type', 'application/json');
    $request->set_header('X-Veciahorra-Cart-Session', $session);
    $request->set_body('{}');
    $response = rest_do_request($request);
    $payload = $response->get_data();

    assertPublicCheckoutValidationSame(200, $response->get_status());
    assertPublicCheckoutValidationSame(true, $payload['success']);
    assertPublicCheckoutValidationSame(true, $payload['data']['valid']);
    assertPublicCheckoutValidationSame([], $payload['data']['errors']);
    assertPublicCheckoutValidationSame('8000.00', $payload['data']['summary']['total']);
    assertPublicCheckoutValidationSame('4000.00', $payload['data']['items'][0]['unit_price_snapshot']);
    assertPublicCheckoutValidationSame('8000.00', $payload['data']['items'][0]['subtotal']);

    $extra = new WP_REST_Request('POST', '/veciahorra/v1/checkout/validate');
    $extra->set_header('content-type', 'application/json');
    $extra->set_header('X-Veciahorra-Cart-Session', $session);
    $extra->set_body('{"first_name":"No admitido"}');
    assertPublicCheckoutValidationSame(422, rest_do_request($extra)->get_status());

    $stores->update($storeId, [
        'status' => 'inactive',
        'updated_at' => current_time('mysql'),
    ]);
    $inactiveStoreRequest = new WP_REST_Request(
        'POST',
        '/veciahorra/v1/checkout/validate'
    );
    $inactiveStoreRequest->set_header('content-type', 'application/json');
    $inactiveStoreRequest->set_header(
        'X-Veciahorra-Cart-Session',
        $session
    );
    $inactiveStoreRequest->set_body('{}');
    $inactiveStorePayload = rest_do_request(
        $inactiveStoreRequest
    )->get_data();
    assertPublicCheckoutValidationSame(
        'minimarket_inactive',
        $inactiveStorePayload['data']['errors'][0]['code'] ?? null
    );

    assertPublicCheckoutValidationSame(
        $before['cart'],
        $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['cart']} WHERE session_id = %s ORDER BY id", $session),
            ARRAY_A
        )
    );
    assertPublicCheckoutValidationSame($before['orders'], (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['orders']}"));
    assertPublicCheckoutValidationSame($before['reservations'], (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['reservations']}"));
    assertPublicCheckoutValidationSame($before['payments'], (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['payments']}"));
    assertPublicCheckoutValidationSame(
        $before['stock'],
        $wpdb->get_var($wpdb->prepare("SELECT stock FROM {$tables['inventory']} WHERE id = %d", $inventoryId))
    );

    echo "PASS public-checkout-validation-test\n";
} finally {
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
}
