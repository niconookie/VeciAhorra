<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('public_cart_cli_required');
}
if (defined('VECIAHORRA_PUBLIC_COMMERCE_ENABLED')) {
    throw new RuntimeException('public_cart_commerce_predefined');
}
$commerceDefined = define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', true);
if ($commerceDefined !== true || constant('VECIAHORRA_PUBLIC_COMMERCE_ENABLED') !== true) {
    throw new RuntimeException('public_cart_commerce_define_failed');
}

use VeciAhorra\Core\Config;
use VeciAhorra\Core\Container;
use VeciAhorra\Core\LaunchGate;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Catalog\Security\PublicOfferToken;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/support/SectorizationFixture.php';

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

function publicCartPersistenceSnapshot(): string
{
    global $wpdb;
    $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
    $snapshot = [];
    foreach (['service_zones', 'store_service_zones', 'stores', 'products', 'inventory', 'cart_items', 'orders'] as $suffix) {
        $snapshot[$suffix] = $wpdb->get_results("SELECT * FROM {$prefix}{$suffix} ORDER BY 1, 2", ARRAY_A);
    }
    return hash('sha256', serialize($snapshot));
}

assertPublicCart((new LaunchGate())->commerceEnabled(), 'El comercio no quedo abierto en el proceso CLI.');
assertPublicCart(session_status() === PHP_SESSION_NONE, 'La prueba heredo una sesion activa.');
$oldSessionPath = session_save_path();
$sessionPath = sys_get_temp_dir() . '/va-public-cart-' . bin2hex(random_bytes(8));
assertPublicCart(mkdir($sessionPath, 0700), 'No se creo session.save_path aislado.');
session_save_path($sessionPath);
session_id('');
unset($_COOKIE[session_name()]);
$persistenceBaseline = publicCartPersistenceSnapshot();

$transaction = $wpdb->query('START TRANSACTION');
assertPublicCart($transaction !== false, 'No se inicio transaccion.');
$customerId = 0;

try {
    $container = new Container();
    $cartRepository = new CartRepository();
    $cartService = new CartService($cartRepository);
    $products = new ProductRepository();
    $stores = new StoreRepository();
    $inventory = new InventoryRepository();
    $now = current_time('mysql');
    $token = 'public-cart-' . bin2hex(random_bytes(5));
    $createdUser = wp_insert_user([
        'user_login' => $token,
        'user_pass' => wp_generate_password(24, true, true),
        'user_email' => $token . '-customer@example.test',
        'role' => 'subscriber',
    ]);
    assertPublicCart(! is_wp_error($createdUser), 'No se creó cliente aislado para el carrito.');
    $customerId = (int) $createdUser;
    $session = hash('sha256', $token . '-guest');
    $otherSession = hash('sha256', $token . '-other');
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
    sectorizationFixtureClearCurrent();

    $empty = publicCartRequest('GET', '/veciahorra/v1/cart', $session);
    assertPublicCartSame(200, $empty->get_status());
    assertPublicCart(
        str_contains((string) ($empty->get_headers()['Cache-Control'] ?? ''), 'private')
            && str_contains((string) ($empty->get_headers()['Cache-Control'] ?? ''), 'no-store'),
        'El carrito anónimo permite caché compartida.'
    );
    assertPublicCartSame([], $empty->get_data()['data']);
    assertPublicCartSame('0.00', $empty->get_data()['total']);

    $withoutSector = publicCartRequest('POST', $itemsRoute, $session, [
        'offer_token' => (new PublicOfferToken())->issue($inventoryA,$productA,999999,['session_id'=>$session,'user_id'=>null]), 'quantity' => 1,
    ]);
    assertPublicCartSame(422, $withoutSector->get_status());
    assertPublicCartSame([], publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data()['data']);

    $zoneId = sectorizationFixtureSelect([$storeId], $token);

    $created = publicCartRequest('POST', $itemsRoute, $session, [
        'offer_token' => (new PublicOfferToken())->issue($inventoryA,$productA,$zoneId,['session_id'=>$session,'user_id'=>null]), 'quantity' => 3,
    ]);
    assertPublicCartSame(201, $created->get_status());
    $itemA = (int) $created->get_data()['data']['id'];
    $single = publicCartRequest('GET', '/veciahorra/v1/cart', $session)->get_data();
    assertPublicCartSame('0.30', $single['data'][0]['subtotal']);
    assertPublicCartSame('0.30', $single['total']);
    foreach ([
        'id', 'product_id', 'offer_group', 'quantity', 'unit_price_snapshot', 'created_at',
        'updated_at', 'product_name', 'product_image_id',
        'product_image_url', 'subtotal', 'sector_compatible',
    ] as $field) {
        assertPublicCart(array_key_exists($field, $single['data'][0]), "Falta campo {$field}.");
    }
    assertPublicCartSame('Producto a', $single['data'][0]['product_name']);
    assertPublicCartSame(true, $single['data'][0]['sector_compatible']);
    assertPublicCartSame(null, $single['data'][0]['product_image_id']);
    assertPublicCartSame(null, $single['data'][0]['product_image_url']);
    foreach (['session_id','user_id','inventory_id','minimarket_id','minimarket_name'] as $privateField) assertPublicCart(!array_key_exists($privateField,$single['data'][0]),"Filtración pública: {$privateField}");

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
    assertPublicCart(!isset($stale['minimarket_id'],$stale['inventory_id']), 'El carrito público expone referencias internas en filas obsoletas.');
    assertPublicCartSame(null, $stale['product_name']);
    assertPublicCartSame(false, $stale['sector_compatible']);
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
    $products->updateStatus(
        $productA,
        Product::STATUS_INACTIVE,
        $now,
        $products->nextUpdatedAt($now)
    );
    assertPublicCartSame(
        422,
        publicCartRequest('PATCH', $itemsRoute . '/' . $itemA, $session, ['quantity' => 2])->get_status()
    );

    assertPublicCartSame([], publicCartRequest('GET', '/veciahorra/v1/cart', $otherSession)->get_data()['data']);
    wp_set_current_user($customerId);
    (new CurrentSector())->set($zoneId);
    $cartService->addItem(['session_id' => null, 'user_id' => $customerId], $inventoryB, 1);
    $userCart = rest_do_request(new WP_REST_Request('GET', '/veciahorra/v1/cart'))->get_data();
    assertPublicCartSame(1, count($userCart['data']));
    assertPublicCart(!isset($userCart['data'][0]['user_id']), 'El carrito autenticado expone user_id.');
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

} finally {
    if ($customerId > 0) {
        clean_user_cache($customerId);
    }
    sectorizationFixtureClearCurrent();
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    session_id('');
    session_save_path($oldSessionPath);
    foreach (glob($sessionPath . '/*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    rmdir($sessionPath);
}

assertPublicCartSame($persistenceBaseline, publicCartPersistenceSnapshot());
assertPublicCartSame(0, (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . $wpdb->users . ' WHERE user_login=%s',
    $token
)));
assertPublicCart(! is_dir($sessionPath), 'Quedo session.save_path temporal.');

echo "PASS public-cart-test commerce=in_process rollback=pass\n";
ob_end_flush();
