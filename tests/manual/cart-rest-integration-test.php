<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('cart_rest_cli_required');
}
if (defined('VECIAHORRA_PUBLIC_COMMERCE_ENABLED')) {
    throw new RuntimeException('cart_rest_commerce_predefined');
}
if (
    define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', true) !== true
    || constant('VECIAHORRA_PUBLIC_COMMERCE_ENABLED') !== true
) {
    throw new RuntimeException('cart_rest_commerce_define_failed');
}

use VeciAhorra\Core\Config;
use VeciAhorra\Core\LaunchGate;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Catalog\Security\PublicOfferToken;
use VeciAhorra\Modules\Checkout\Service\CheckoutValidationService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\ServiceZoneRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCartRest(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCartRestSame(mixed $expected, mixed $actual): void
{
    assertCartRest(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function cartRestRequest(
    string $method,
    string $route,
    ?array $body = null,
    ?string $sessionId = null
): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);

    if ($sessionId !== null) {
        $request->set_query_params(['session_id' => $sessionId]);
    }

    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_do_request($request);
}

global $wpdb;

function cartRestPersistenceSnapshot(): string
{
    global $wpdb;
    $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
    $snapshot = [];
    foreach (['service_zones', 'store_service_zones', 'stores', 'products', 'inventory', 'cart_items', 'orders'] as $suffix) {
        $snapshot[$suffix] = $wpdb->get_results("SELECT * FROM {$prefix}{$suffix} ORDER BY 1, 2", ARRAY_A);
    }

    return hash('sha256', serialize($snapshot));
}

assertCartRest((new LaunchGate())->commerceEnabled(), 'El comercio no quedo abierto en el proceso CLI.');
assertCartRest(
    LaunchGate::evaluate(true, false, 'production') === false,
    'El control cerrado del gate de comercio no fue fail-closed.'
);
assertCartRest(session_status() === PHP_SESSION_NONE, 'La prueba heredo una sesion activa.');
$oldSessionPath = session_save_path();
$sessionPath = sys_get_temp_dir() . '/va-cart-rest-' . bin2hex(random_bytes(8));
assertCartRest(mkdir($sessionPath, 0700), 'No se creo session.save_path aislado.');
session_save_path($sessionPath);
session_id('');
unset($_COOKIE[session_name()]);
$persistenceBaseline = cartRestPersistenceSnapshot();

$inventoryRepository = new InventoryRepository();
$productRepository = new ProductRepository();
$storeRepository = new StoreRepository();
$transaction = $wpdb->query('START TRANSACTION');
assertCartRest($transaction !== false, 'No se inicio la transaccion.');

try {
    $now = current_time('mysql');
    $token = 'cart-rest-' . bin2hex(random_bytes(5));
    $minimarketId = $storeRepository->create([
        'business_name' => $token, 'legal_name' => $token,
        'owner_name' => 'Owner', 'rut' => '1-9',
        'email' => $token . '@example.test', 'phone' => '000',
        'mobile' => null, 'address' => null, 'commune' => null,
        'city' => null, 'region' => null, 'status' => 'active',
        'onboarding_status' => 'complete', 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $otherMinimarketId = $storeRepository->create([
        'business_name' => $token . '-other', 'legal_name' => $token . '-other',
        'owner_name' => 'Other Owner', 'rut' => '2-7',
        'email' => $token . '-other@example.test', 'phone' => '000',
        'mobile' => null, 'address' => null, 'commune' => null,
        'city' => null, 'region' => null, 'status' => 'active',
        'onboarding_status' => 'complete', 'approved_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $makeProduct = static function (string $suffix) use ($productRepository, $token, $now): int {
        return $productRepository->create([
            'woo_product_id' => null, 'name' => "{$token} {$suffix}",
            'slug' => "{$token}-{$suffix}", 'sku' => null,
            'description' => null, 'category_id' => null, 'brand_id' => null,
            'unit_id' => null, 'image_id' => null, 'status' => Product::STATUS_ACTIVE,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $makeInventory = static function (
        int $productId,
        float $price
    ) use ($inventoryRepository, $minimarketId, $now): int {
        return $inventoryRepository->create([
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'price' => $price,
            'stock' => 20,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $firstProductId = $makeProduct('first');
    $secondProductId = $makeProduct('second');
    $thirdProductId = $makeProduct('third');
    $userProductId = $makeProduct('user');
    $firstInventoryId = $makeInventory($firstProductId, 1490.50);
    $secondInventoryId = $makeInventory($secondProductId, 800.0);
    $thirdInventoryId = $makeInventory($thirdProductId, 500.0);
    $userInventoryId = $makeInventory($userProductId, 2000.0);
    $collection = '/veciahorra/v1/cart';
    $items = $collection . '/items';
    $sessionA = hash('sha256', $token . '-a');
    $sessionB = hash('sha256', $token . '-b');
    $zones = new ServiceZoneRepository();
    $activeZones = $zones->active();
    assertCartRest($activeZones !== [], 'Se requiere una zona activa existente.');
    $zoneId = (int) ($activeZones[0]['id'] ?? 0);
    assertCartRest($zoneId > 0, 'La zona activa no tiene un ID valido.');
    $currentSector = new CurrentSector($zones);
    $offerTokens = new PublicOfferToken();

    wp_set_current_user(0);
    assertCartRestSame(0, $currentSector->id());

    $missingIdentity = cartRestRequest('POST', $items, [
        'inventory_id' => $firstInventoryId,
        'quantity' => 1,
    ]);
    assertCartRestSame(400, $missingIdentity->get_status());
    assertCartRestSame(
        'cart_identity_required',
        $missingIdentity->get_data()['error']['code'] ?? null
    );

    $missingInventory = cartRestRequest(
        'POST',
        $items,
        ['quantity' => 1],
        $sessionA
    );
    assertCartRestSame(422, $missingInventory->get_status());

    $invalidToken = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => 'invalid', 'quantity' => 1],
        $sessionA
    );
    assertCartRestSame(422, $invalidToken->get_status());

    $historicalPayload = cartRestRequest(
        'POST',
        $items,
        ['inventory_id' => $firstInventoryId, 'quantity' => 1],
        $sessionA
    );
    assertCartRestSame(422, $historicalPayload->get_status());

    $firstOfferA = $offerTokens->issue(
        $firstInventoryId,
        $firstProductId,
        $zoneId,
        ['session_id' => $sessionA, 'user_id' => null]
    );

    $invalidQuantity = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $firstOfferA, 'quantity' => 0],
        $sessionA
    );
    assertCartRestSame(422, $invalidQuantity->get_status());

    $withoutSector = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $firstOfferA, 'quantity' => 1],
        $sessionA
    );
    assertCartRestSame(422, $withoutSector->get_status());
    assertCartRestSame('validation_error', $withoutSector->get_data()['error']['code'] ?? null);

    $currentSector->set($zoneId);
    assertCartRestSame($zoneId, $currentSector->id());

    $withoutLink = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $firstOfferA, 'quantity' => 1],
        $sessionA
    );
    assertCartRestSame(422, $withoutLink->get_status());
    assertCartRestSame('validation_error', $withoutLink->get_data()['error']['code'] ?? null);

    $linkTable = $wpdb->prefix . Config::TABLE_PREFIX . 'store_service_zones';
    assertCartRest($wpdb->insert($linkTable, [
        'store_id' => $otherMinimarketId,
        'zone_id' => $zoneId,
        'assigned_by' => 0,
        'assigned_at' => $now,
    ]) !== false, 'No se creo el vinculo negativo con otro minimarket.');
    $wrongStore = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $firstOfferA, 'quantity' => 1],
        $sessionA
    );
    assertCartRestSame(422, $wrongStore->get_status());
    assertCartRestSame('validation_error', $wrongStore->get_data()['error']['code'] ?? null);
    assertCartRest($wpdb->delete($linkTable, [
        'store_id' => $otherMinimarketId,
        'zone_id' => $zoneId,
    ]) !== false, 'No se retiro el vinculo negativo.');

    assertCartRest($wpdb->insert($linkTable, [
        'store_id' => $minimarketId,
        'zone_id' => $zoneId,
        'assigned_by' => 0,
        'assigned_at' => $now,
    ]) !== false, 'No se creo el vinculo sectorial del fixture.');
    assertCartRest($zones->storeAllowed($zoneId, $minimarketId), 'El vinculo sectorial no quedo efectivo.');
    assertCartRestSame($zoneId, $currentSector->id());

    $mismatchedOffer = $offerTokens->issue(
        $secondInventoryId,
        $firstProductId,
        $zoneId,
        ['session_id' => $sessionA, 'user_id' => null]
    );
    $wrongOffer = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $mismatchedOffer, 'quantity' => 1],
        $sessionA
    );
    assertCartRestSame(422, $wrongOffer->get_status());
    assertCartRestSame('validation_error', $wrongOffer->get_data()['error']['code'] ?? null);

    $created = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $firstOfferA, 'quantity' => 2],
        $sessionA
    );
    assertCartRestSame(201, $created->get_status());
    assertCartRestSame(true, $created->get_data()['success'] ?? null);
    $firstItemId = (int) ($created->get_data()['data']['id'] ?? 0);
    assertCartRest($firstItemId > 0, 'POST no retorno ID valido.');

    $incremented = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $firstOfferA, 'quantity' => 3],
        $sessionA
    );
    assertCartRestSame(200, $incremented->get_status());
    assertCartRestSame(
        $firstItemId,
        (int) ($incremented->get_data()['data']['id'] ?? 0)
    );

    $cartA = cartRestRequest('GET', $collection, null, $sessionA);
    assertCartRestSame(200, $cartA->get_status());
    $cartAItems = $cartA->get_data()['data'] ?? [];
    assertCartRestSame(1, count($cartAItems));
    $checkoutValidation = (new CheckoutValidationService(
        new CartService(new CartRepository()),
        new InventoryService(),
        new ProductService(),
        new StoreRepository()
    ))->validate(['session_id' => $sessionA, 'user_id' => null]);
    $checkoutItem = $checkoutValidation['items'][0] ?? [];
    assertCartRestSame($firstItemId, (int) $cartAItems[0]['id']);
    assertCartRestSame($firstProductId, (int) $cartAItems[0]['product_id']);
    assertCartRestSame($token . ' first', $cartAItems[0]['product_name']);
    assertCartRestSame($checkoutItem['offer_group'] ?? null, $cartAItems[0]['offer_group']);
    assertCartRestSame('5', $cartAItems[0]['quantity']);
    assertCartRestSame('1490.50', $cartAItems[0]['unit_price_snapshot']);
    assertCartRestSame('7452.50', $cartAItems[0]['subtotal']);
    assertCartRestSame(true, $cartAItems[0]['sector_compatible']);
    foreach (['inventory_id', 'minimarket_id', 'session_id', 'user_id', 'offer_token'] as $privateField) {
        assertCartRest(
            ! array_key_exists($privateField, $cartAItems[0]),
            "La respuesta publica expone {$privateField}."
        );
    }

    $createdB = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $offerTokens->issue($secondInventoryId, $secondProductId, $zoneId, ['session_id' => $sessionB, 'user_id' => null]), 'quantity' => 1],
        $sessionB
    );
    $secondItemId = (int) ($createdB->get_data()['data']['id'] ?? 0);
    assertCartRestSame(201, $createdB->get_status());

    $patched = cartRestRequest(
        'PATCH',
        $items . '/' . $firstItemId,
        ['quantity' => 7],
        $sessionA
    );
    assertCartRestSame(200, $patched->get_status());

    $invalidPatch = cartRestRequest(
        'PATCH',
        $items . '/' . $firstItemId,
        ['quantity' => 0],
        $sessionA
    );
    assertCartRestSame(422, $invalidPatch->get_status());

    $foreignPatch = cartRestRequest(
        'PATCH',
        $items . '/' . $firstItemId,
        ['quantity' => 9],
        $sessionB
    );
    assertCartRestSame(404, $foreignPatch->get_status());
    assertCartRestSame(
        'cart_item_not_found',
        $foreignPatch->get_data()['error']['code'] ?? null
    );

    $foreignDelete = cartRestRequest(
        'DELETE',
        $items . '/' . $firstItemId,
        null,
        $sessionB
    );
    assertCartRestSame(404, $foreignDelete->get_status());

    $deleted = cartRestRequest(
        'DELETE',
        $items . '/' . $firstItemId,
        null,
        $sessionA
    );
    assertCartRestSame(200, $deleted->get_status());

    $remainingA = cartRestRequest(
        'POST',
        $items,
        ['offer_token' => $offerTokens->issue($thirdInventoryId, $thirdProductId, $zoneId, ['session_id' => $sessionA, 'user_id' => null]), 'quantity' => 2],
        $sessionA
    );
    assertCartRestSame(201, $remainingA->get_status());
    $cleared = cartRestRequest('DELETE', $collection, null, $sessionA);
    assertCartRestSame(200, $cleared->get_status());
    assertCartRestSame(
        1,
        (int) ($cleared->get_data()['data']['deleted'] ?? -1)
    );
    assertCartRestSame(
        [],
        cartRestRequest('GET', $collection, null, $sessionA)
            ->get_data()['data']
    );
    assertCartRestSame(
        $secondItemId,
        (int) (cartRestRequest('GET', $collection, null, $sessionB)
            ->get_data()['data'][0]['id'] ?? 0)
    );

    $administratorIds = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    ]);
    assertCartRest($administratorIds !== [], 'Se requiere un administrador.');
    wp_set_current_user((int) $administratorIds[0]);
    (new CurrentSector($zones))->set($zoneId);
    assertCartRestSame($zoneId, (new CurrentSector($zones))->id());
    $userCreated = cartRestRequest('POST', $items, [
        'offer_token' => $offerTokens->issue($userInventoryId, $userProductId, $zoneId, ['session_id' => null, 'user_id' => (int) $administratorIds[0]]),
        'quantity' => 1,
    ], 'ignored-session');
    assertCartRestSame(201, $userCreated->get_status());
    $userCart = cartRestRequest('GET', $collection, null, 'ignored-session');
    assertCartRestSame(200, $userCart->get_status());
    assertCartRestSame(1, count($userCart->get_data()['data'] ?? []));
    assertCartRest(
        ! array_key_exists('user_id', $userCart->get_data()['data'][0])
        && ! array_key_exists('session_id', $userCart->get_data()['data'][0]),
        'El carrito autenticado expone identidad privada.'
    );
    $ownedUserCart = (new CartService(new CartRepository()))->getCart([
        'session_id' => null,
        'user_id' => (int) $administratorIds[0],
    ]);
    assertCartRestSame(1, count($ownedUserCart));
    assertCartRestSame(
        (int) $administratorIds[0],
        (int) ($ownedUserCart[0]['user_id'] ?? 0)
    );
    assertCartRestSame(
        null,
        $ownedUserCart[0]['session_id'] ?? null
    );

} finally {
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }
    session_id('');
    session_save_path($oldSessionPath);
    foreach (glob($sessionPath . '/*') ?: [] as $sessionFile) {
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }
    }
    rmdir($sessionPath);
}

assertCartRestSame($persistenceBaseline, cartRestPersistenceSnapshot());
assertCartRest(! is_dir($sessionPath), 'Quedo residuo del directorio de sesion.');
echo "PASS cart-rest-integration-test\n";
