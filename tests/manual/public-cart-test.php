<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
}

function assertPublicCart(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPublicCartSame(mixed $expected, mixed $actual): void
{
    assertPublicCart(
        $expected === $actual,
        sprintf("Esperado: %s\nRecibido: %s", var_export($expected, true), var_export($actual, true))
    );
}

function publicCartRequest(
    string $method,
    string $route,
    string $session,
    ?array $body = null
): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);
    $request->set_header('X-Veciahorra-Cart-Session', $session);
    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_do_request($request);
}

global $wpdb;

$transaction = $wpdb->query('START TRANSACTION');
assertPublicCart($transaction !== false, 'No se inicio transaccion.');

try {
    $container = new Container();
    $cartRepository = new CartRepository();
    $cartService = new CartService($cartRepository);
    $products = new ProductRepository();
    $stores = new StoreRepository();
    $inventory = new InventoryRepository();
    $now = current_time('mysql');
    $token = 'public-cart-' . bin2hex(random_bytes(5));
    $session = $token . '-guest';
    $otherSession = $token . '-other';
    $storeId = $stores->create([
        'business_name' => 'Minimarket Publico', 'legal_name' => 'Legal',
        'owner_name' => 'Owner', 'rut' => '1-9',
        'email' => $token . '@example.test', 'phone' => '000',
        'mobile' => null, 'address' => null, 'commune' => null,
        'city' => null, 'region' => null, 'status' => 'active',
        'onboarding_status' => 'complete', 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $createProduct = static function (string $suffix) use ($products, $token, $now): int {
        return $products->create([
            'woo_product_id' => null, 'name' => "Producto {$suffix}",
            'slug' => "{$token}-{$suffix}", 'sku' => null,
            'description' => null, 'category_id' => null, 'brand_id' => null,
            'unit_id' => null, 'image_id' => null,
            'status' => Product::STATUS_ACTIVE,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $productA = $createProduct('a');
    $productB = $createProduct('b');
    $inventoryA = $inventory->create([
        'product_id' => $productA, 'minimarket_id' => $storeId,
        'price' => 0.10, 'stock' => 20, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $inventoryB = $inventory->create([
        'product_id' => $productB, 'minimarket_id' => $storeId,
        'price' => 1.25, 'stock' => 20, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $itemsRoute = '/veciahorra/v1/cart/items';
    wp_set_current_user(0);

    $empty = publicCartRequest('GET', '/veciahorra/v1/cart', $session);
    assertPublicCartSame(200, $empty->get_status());
    assertPublicCartSame([], $empty->get_data()['data']);
    assertPublicCartSame('0.00', $empty->get_data()['total']);

    $created = publicCartRequest('POST', $itemsRoute, $session, [
        'inventory_id' => $inventoryA, 'quantity' => 3,
    ]);
    assertPublicCartSame(201, $created->get_status());
    $itemA = (int) $created->get_data()['data']['id'];
    $single = publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data();
    assertPublicCartSame('0.30', $single['data'][0]['subtotal']);
    assertPublicCartSame('0.30', $single['total']);
    foreach ([
        'id', 'session_id', 'user_id', 'inventory_id', 'product_id',
        'minimarket_id', 'quantity', 'unit_price_snapshot', 'created_at',
        'updated_at', 'product_name', 'product_image_id',
        'product_image_url', 'minimarket_name', 'subtotal',
    ] as $field) {
        assertPublicCart(array_key_exists($field, $single['data'][0]), "Falta campo {$field}.");
    }
    assertPublicCartSame('Producto a', $single['data'][0]['product_name']);
    assertPublicCartSame('Minimarket Publico', $single['data'][0]['minimarket_name']);
    assertPublicCartSame(null, $single['data'][0]['product_image_id']);
    assertPublicCartSame(null, $single['data'][0]['product_image_url']);
    $legacyItem = $cartRepository->findBySession($session)[0];
    foreach ([
        'id', 'session_id', 'user_id', 'inventory_id', 'product_id',
        'minimarket_id', 'quantity', 'unit_price_snapshot', 'created_at',
        'updated_at',
    ] as $legacyField) {
        assertPublicCartSame(
            $legacyItem[$legacyField],
            $single['data'][0][$legacyField]
        );
    }

    $cartService->addItem(['session_id' => $session, 'user_id' => null], $inventoryB, 2);
    $multiple = publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data();
    assertPublicCartSame(2, count($multiple['data']));
    assertPublicCartSame('2.50', $multiple['data'][1]['subtotal']);
    assertPublicCartSame('2.80', $multiple['total']);

    $beforeGet = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'va_cart_items WHERE session_id = %s ORDER BY id',
            $session
        ),
        ARRAY_A
    );
    publicCartRequest('GET', '/veciahorra/v1/cart', $session);
    $afterGet = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'va_cart_items WHERE session_id = %s ORDER BY id',
            $session
        ),
        ARRAY_A
    );
    assertPublicCartSame($beforeGet, $afterGet);

    $queriesBefore = $wpdb->num_queries;
    $cartService->getPublicCart(['session_id' => $session, 'user_id' => null]);
    $multiQueries = $wpdb->num_queries - $queriesBefore;
    $queriesBefore = $wpdb->num_queries;
    $cartService->getPublicCart(['session_id' => $otherSession, 'user_id' => null]);
    $emptyQueries = $wpdb->num_queries - $queriesBefore;
    assertPublicCartSame($emptyQueries, $multiQueries);

    $missingProductId = random_int(800000000, 809999999);
    $missingStoreId = random_int(810000000, 819999999);
    $cartRepository->create([
        'session_id' => $session, 'user_id' => null,
        'inventory_id' => random_int(820000000, 829999999),
        'product_id' => $missingProductId, 'minimarket_id' => $missingStoreId,
        'quantity' => 2, 'unit_price_snapshot' => '3.33',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $unresolved = publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data();
    $stale = $unresolved['data'][2];
    assertPublicCartSame($missingProductId, (int) $stale['product_id']);
    assertPublicCartSame($missingStoreId, (int) $stale['minimarket_id']);
    assertPublicCartSame(null, $stale['product_name']);
    assertPublicCartSame(null, $stale['minimarket_name']);
    assertPublicCartSame('6.66', $stale['subtotal']);

    $cartRepository->create([
        'session_id' => $session, 'user_id' => null,
        'inventory_id' => random_int(830000000, 839999999),
        'product_id' => $productB, 'minimarket_id' => $storeId,
        'quantity' => 0, 'unit_price_snapshot' => '2.00',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $corrupt = publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data();
    assertPublicCartSame(null, $corrupt['data'][3]['subtotal']);
    assertPublicCart(
        preg_match('/^\d+\.\d{2}$/', $corrupt['total']) === 1,
        'Datos corruptos produjeron total no monetario.'
    );

    $decimalMethod = new ReflectionMethod(CartService::class, 'decimalToCents');
    $decimalMethod->setAccessible(true);
    foreach ([-1, NAN, INF, -INF, '2.345', 'invalid'] as $invalidMoney) {
        assertPublicCartSame(
            null,
            $decimalMethod->invoke($cartService, $invalidMoney)
        );
    }

    assertPublicCartSame(
        200,
        publicCartRequest('PATCH', $itemsRoute . '/' . $itemA, $session, ['quantity' => 4])->get_status()
    );
    $inventory->update($inventoryA, ['price' => 9.99, 'updated_at' => $now]);
    $priceChanged = publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data();
    assertPublicCartSame('0.10', $priceChanged['data'][0]['unit_price_snapshot']);
    assertPublicCartSame('0.40', $priceChanged['data'][0]['subtotal']);

    $inventory->update($inventoryA, ['stock' => 4, 'updated_at' => $now]);
    assertPublicCartSame(
        422,
        publicCartRequest('PATCH', $itemsRoute . '/' . $itemA, $session, ['quantity' => 5])->get_status()
    );
    $products->update($productA, ['status' => Product::STATUS_INACTIVE, 'updated_at' => $now]);
    assertPublicCartSame(
        422,
        publicCartRequest('PATCH', $itemsRoute . '/' . $itemA, $session, ['quantity' => 2])->get_status()
    );

    assertPublicCartSame([], publicCartRequest('GET', '/veciahorra/v1/cart', $otherSession)->get_data()['data']);
    wp_set_current_user(1);
    $cartService->addItem(['session_id' => null, 'user_id' => 1], $inventoryB, 1);
    $userCart = rest_do_request(new WP_REST_Request('GET', '/veciahorra/v1/cart'))->get_data();
    assertPublicCartSame(1, count($userCart['data']));
    assertPublicCartSame(1, (int) $userCart['data'][0]['user_id']);
    wp_set_current_user(0);

    assertPublicCartSame(200, publicCartRequest('DELETE', $itemsRoute . '/' . $itemA, $session)->get_status());
    assertPublicCartSame(200, publicCartRequest('DELETE', '/veciahorra/v1/cart', $session)->get_status());
    assertPublicCartSame([], publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data()['data']);

    $controller = $container->make(FrontendController::class);
    $html = $controller->renderCart();
    foreach ([
        'data-va-cart', 'data-va-cart-loading', 'data-va-cart-empty',
        'data-va-cart-error', 'data-va-cart-retry', 'data-va-cart-items',
        'data-va-cart-total', 'data-va-cart-clear', 'aria-live="polite"',
    ] as $contract) {
        assertPublicCart(str_contains($html, $contract), "Falta contrato frontend {$contract}.");
    }
    assertPublicCart(shortcode_exists(FrontendController::CART_SHORTCODE), 'No existe shortcode de carrito.');
    global $wp_scripts;
    assertPublicCart(
        wp_script_is(FrontendAssets::CART_SCRIPT_HANDLE, 'enqueued'),
        'No se encolo JavaScript del carrito.'
    );

    $root = dirname(__DIR__, 2);
    $javascript = (string) file_get_contents($root . '/assets/frontend/js/veciahorra-cart.js');
    foreach ([
        "apiRequest('get', '/cart')", "'/cart/items/'",
        "'delete', '/cart'",
        '{ quantity: quantity }', 'REQUEST_TIMEOUT', 'aria-label',
    ] as $contract) {
        assertPublicCart(str_contains($javascript, $contract), "Falta contrato JS {$contract}.");
    }
    foreach (['/checkout', '/orders', '/reservations', '/inventory', '/catalog'] as $forbidden) {
        assertPublicCart(! str_contains($javascript, $forbidden), "Endpoint prohibido {$forbidden}.");
    }
    assertPublicCart(! preg_match('/subtotal\s*[+*\/-]/', $javascript), 'Frontend calcula subtotal.');
    assertPublicCart(! preg_match('/total\s*[+*\/-]/', $javascript), 'Frontend calcula total.');

    echo "PASS public-cart-test\n";
} finally {
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
}
