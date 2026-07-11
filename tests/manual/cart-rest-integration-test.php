<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
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
    $firstInventoryId = $makeInventory(
        $makeProduct('first'),
        1490.50
    );
    $secondInventoryId = $makeInventory(
        $makeProduct('second'),
        800.0
    );
    $thirdInventoryId = $makeInventory(
        $makeProduct('third'),
        500.0
    );
    $userInventoryId = $makeInventory(
        $makeProduct('user'),
        2000.0
    );
    $collection = '/veciahorra/v1/cart';
    $items = $collection . '/items';
    $sessionA = 'rest-a-' . bin2hex(random_bytes(8));
    $sessionB = 'rest-b-' . bin2hex(random_bytes(8));

    wp_set_current_user(0);

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

    $invalidQuantity = cartRestRequest(
        'POST',
        $items,
        ['inventory_id' => $firstInventoryId, 'quantity' => 0],
        $sessionA
    );
    assertCartRestSame(422, $invalidQuantity->get_status());

    $created = cartRestRequest(
        'POST',
        $items,
        ['inventory_id' => $firstInventoryId, 'quantity' => 2],
        $sessionA
    );
    assertCartRestSame(201, $created->get_status());
    assertCartRestSame(true, $created->get_data()['success'] ?? null);
    $firstItemId = (int) ($created->get_data()['data']['id'] ?? 0);
    assertCartRest($firstItemId > 0, 'POST no retorno ID valido.');

    $incremented = cartRestRequest(
        'POST',
        $items,
        ['inventory_id' => $firstInventoryId, 'quantity' => 3],
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
    assertCartRestSame($firstItemId, (int) $cartAItems[0]['id']);
    assertCartRestSame($firstInventoryId, (int) $cartAItems[0]['inventory_id']);
    assertCartRestSame(5, (int) $cartAItems[0]['quantity']);
    assertCartRestSame('1490.50', $cartAItems[0]['unit_price_snapshot']);

    $createdB = cartRestRequest(
        'POST',
        $items,
        ['inventory_id' => $secondInventoryId, 'quantity' => 1],
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
        ['inventory_id' => $thirdInventoryId, 'quantity' => 2],
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
    $userCreated = cartRestRequest('POST', $items, [
        'inventory_id' => $userInventoryId,
        'quantity' => 1,
    ], 'ignored-session');
    assertCartRestSame(201, $userCreated->get_status());
    $userCart = cartRestRequest('GET', $collection, null, 'ignored-session');
    assertCartRestSame(200, $userCart->get_status());
    assertCartRestSame(1, count($userCart->get_data()['data'] ?? []));
    assertCartRestSame(
        (int) $administratorIds[0],
        (int) ($userCart->get_data()['data'][0]['user_id'] ?? 0)
    );
    assertCartRestSame(
        null,
        $userCart->get_data()['data'][0]['session_id'] ?? null
    );

    echo "PASS cart-rest-integration-test\n";
} finally {
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
}
