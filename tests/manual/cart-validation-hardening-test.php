<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Cart\Requests\CartItemCreateRequest;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCartHardening(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCartHardeningSame(mixed $expected, mixed $actual): void
{
    assertCartHardening(
        $expected === $actual,
        sprintf("Esperado: %s\nRecibido: %s", var_export($expected, true), var_export($actual, true))
    );
}

function cartHardeningRequest(
    string $method,
    string $route,
    array $body,
    string $session
): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);
    $request->set_header('content-type', 'application/json');
    $request->set_header('X-Veciahorra-Cart-Session', $session);
    $request->set_body(wp_json_encode($body));

    return rest_do_request($request);
}

global $wpdb;

$transaction = $wpdb->query('START TRANSACTION');
assertCartHardening($transaction !== false, 'No se inicio transaccion.');

try {
    $products = new ProductRepository();
    $inventory = new InventoryRepository();
    $stores = new StoreRepository();
    $now = current_time('mysql');
    $token = 'hardening-' . bin2hex(random_bytes(5));
    $createProduct = static function (string $suffix, string $status) use ($products, $now, $token): int {
        return $products->create([
            'woo_product_id' => null, 'name' => "{$token} {$suffix}",
            'slug' => "{$token}-{$suffix}", 'sku' => null,
            'description' => null, 'category_id' => null, 'brand_id' => null,
            'unit_id' => null, 'image_id' => null, 'status' => $status,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $createStore = static function (string $suffix, string $status) use ($stores, $now, $token): int {
        return $stores->create([
            'business_name' => "{$token} {$suffix}", 'legal_name' => "{$token} legal",
            'owner_name' => 'Owner', 'rut' => '1-9',
            'email' => "{$token}-{$suffix}@example.test", 'phone' => '000',
            'mobile' => null, 'address' => null, 'commune' => null,
            'city' => null, 'region' => null, 'status' => $status,
            'onboarding_status' => 'complete', 'approved_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $createInventory = static function (
        int $productId,
        int $storeId,
        mixed $price,
        mixed $stock,
        string $status = 'active'
    ) use ($inventory, $now): int {
        return $inventory->create([
            'product_id' => $productId, 'minimarket_id' => $storeId,
            'price' => $price, 'stock' => $stock, 'status' => $status,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $activeProduct = $createProduct('active', Product::STATUS_ACTIVE);
    $draftProduct = $createProduct('draft', Product::STATUS_DRAFT);
    $inactiveProduct = $createProduct('inactive', Product::STATUS_INACTIVE);
    $activeStore = $createStore('active', 'active');
    $inactiveStore = $createStore('inactive', 'inactive');
    $validInventory = $createInventory($activeProduct, $activeStore, 1290.50, 5);
    $cases = [
        $createInventory($activeProduct, $createStore('inventory-inactive', 'active'), 100, 5, 'inactive'),
        $createInventory($draftProduct, $activeStore, 100, 5),
        $createInventory($inactiveProduct, $activeStore, 100, 5),
        $createInventory(random_int(800000000, 809999999), $activeStore, 100, 5),
        $createInventory($activeProduct, random_int(810000000, 819999999), 100, 5),
        $createInventory($activeProduct, $inactiveStore, 100, 5),
        $createInventory($activeProduct, $createStore('price-zero', 'active'), 0, 5),
        $createInventory($activeProduct, $createStore('price-negative', 'active'), -1, 5),
        $createInventory($activeProduct, $createStore('stock-zero', 'active'), 100, 0),
        $createInventory($activeProduct, $createStore('stock-negative', 'active'), 100, -1),
    ];
    $itemsRoute = '/veciahorra/v1/cart/items';
    wp_set_current_user(0);

    assertCartHardeningSame(
        422,
        cartHardeningRequest('POST', $itemsRoute, ['inventory_id' => 999999999, 'quantity' => 1], $token)->get_status()
    );

    foreach ($cases as $index => $inventoryId) {
        assertCartHardeningSame(
            422,
            cartHardeningRequest(
                'POST',
                $itemsRoute,
                ['inventory_id' => $inventoryId, 'quantity' => 1],
                $token . '-case-' . $index
            )->get_status()
        );
    }

    foreach ([0, -1, 1.5, 'one', false, true, [], new stdClass(), '2abc', '2.5'] as $quantity) {
        assertCartHardeningSame(
            422,
            cartHardeningRequest(
                'POST',
                $itemsRoute,
                ['inventory_id' => $validInventory, 'quantity' => $quantity],
                $token . '-quantity-' . md5(serialize($quantity))
            )->get_status()
        );
    }
    foreach ([0, -1, 1.5, 'inventory'] as $invalidInventory) {
        assertCartHardeningSame(
            422,
            cartHardeningRequest(
                'POST',
                $itemsRoute,
                ['inventory_id' => $invalidInventory, 'quantity' => 1],
                $token . '-inventory-' . md5(serialize($invalidInventory))
            )->get_status()
        );
    }
    assertCartHardeningSame(
        422,
        cartHardeningRequest(
            'POST',
            $itemsRoute,
            ['inventory_id' => $validInventory, 'quantity' => 6],
            $token . '-too-many'
        )->get_status()
    );

    $session = $token . '-valid';
    $forged = [
        'inventory_id' => $validInventory, 'quantity' => 3,
        'product_id' => 1, 'minimarket_id' => 2, 'unit_price' => 0.01,
        'unit_price_snapshot' => 0.02, 'stock' => 999999,
        'subtotal' => 0.03, 'total' => 0.04, 'status' => 'deleted',
    ];
    $created = cartHardeningRequest('POST', $itemsRoute, $forged, $session);
    assertCartHardeningSame(201, $created->get_status());
    $itemId = (int) ($created->get_data()['data']['id'] ?? 0);
    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'va_cart_items WHERE id = %d', $itemId),
        ARRAY_A
    );
    assertCartHardeningSame($activeProduct, (int) $row['product_id']);
    assertCartHardeningSame($activeStore, (int) $row['minimarket_id']);
    assertCartHardeningSame('1290.50', $row['unit_price_snapshot']);
    assertCartHardeningSame(3, (int) $row['quantity']);

    assertCartHardeningSame(
        422,
        cartHardeningRequest('POST', $itemsRoute, ['inventory_id' => $validInventory, 'quantity' => 3], $session)->get_status()
    );
    assertCartHardeningSame(
        3,
        (int) $wpdb->get_var($wpdb->prepare('SELECT quantity FROM ' . $wpdb->prefix . 'va_cart_items WHERE id = %d', $itemId))
    );
    assertCartHardeningSame(
        '1290.50',
        $wpdb->get_var($wpdb->prepare('SELECT unit_price_snapshot FROM ' . $wpdb->prefix . 'va_cart_items WHERE id = %d', $itemId))
    );

    $otherSession = $token . '-other-owner';
    assertCartHardeningSame(
        404,
        cartHardeningRequest('PATCH', $itemsRoute . '/' . $itemId, ['quantity' => 1], $otherSession)->get_status()
    );
    assertCartHardeningSame(
        404,
        cartHardeningRequest('DELETE', $itemsRoute . '/' . $itemId, [], $otherSession)->get_status()
    );

    $inventory->update($validInventory, ['price' => 1500, 'stock' => 4, 'updated_at' => $now]);
    assertCartHardeningSame(
        200,
        cartHardeningRequest('PATCH', $itemsRoute . '/' . $itemId, ['quantity' => 4], $session)->get_status()
    );
    $updated = $wpdb->get_row(
        $wpdb->prepare('SELECT quantity, unit_price_snapshot FROM ' . $wpdb->prefix . 'va_cart_items WHERE id = %d', $itemId),
        ARRAY_A
    );
    assertCartHardeningSame(4, (int) $updated['quantity']);
    assertCartHardeningSame('1500.00', $updated['unit_price_snapshot']);
    assertCartHardeningSame(
        422,
        cartHardeningRequest('PATCH', $itemsRoute . '/' . $itemId, ['quantity' => 5], $session)->get_status()
    );
    $beforeStaleUpdate = $wpdb->get_row(
        $wpdb->prepare('SELECT quantity, unit_price_snapshot FROM ' . $wpdb->prefix . 'va_cart_items WHERE id = %d', $itemId),
        ARRAY_A
    );
    $inventory->update($validInventory, ['status' => 'inactive', 'updated_at' => $now]);
    assertCartHardeningSame(
        422,
        cartHardeningRequest('PATCH', $itemsRoute . '/' . $itemId, ['quantity' => 1], $session)->get_status()
    );
    assertCartHardeningSame(
        $beforeStaleUpdate,
        $wpdb->get_row(
            $wpdb->prepare('SELECT quantity, unit_price_snapshot FROM ' . $wpdb->prefix . 'va_cart_items WHERE id = %d', $itemId),
            ARRAY_A
        )
    );

    $service = (new Container())->make(CartService::class);
    $priceMethod = new ReflectionMethod(CartService::class, 'normalizedPrice');
    $priceMethod->setAccessible(true);

    foreach (['not-numeric', NAN, INF, -INF, 0, -1, false, true, [], new stdClass()] as $invalidPrice) {
        try {
            $priceMethod->invoke($service, $invalidPrice);
            throw new RuntimeException('Se acepto precio invalido.');
        } catch (ReflectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $cause = $exception instanceof ReflectionException
                ? $exception
                : ($exception->getPrevious() ?? $exception);
            assertCartHardening(
                $cause instanceof InvalidArgumentException,
                'Precio invalido no produjo validacion.'
            );
        }
    }

    $integerMethod = new ReflectionMethod(CartService::class, 'integerValue');
    $integerMethod->setAccessible(true);

    foreach (['not-integer', '1.5', '2abc', 1.5, NAN, INF, -INF, false, true, [], new stdClass()] as $invalidStock) {
        try {
            $integerMethod->invoke($service, $invalidStock, 'stock', false);
            throw new RuntimeException('Se acepto stock no entero.');
        } catch (Throwable $exception) {
            $cause = $exception->getPrevious() ?? $exception;
            assertCartHardening(
                $cause instanceof InvalidArgumentException,
                'Stock invalido no produjo validacion.'
            );
        }
    }

    $request = new CartItemCreateRequest(['inventory_id' => $validInventory, 'quantity' => 1]);
    assertCartHardeningSame(
        ['inventory_id' => $validInventory, 'quantity' => 1],
        $request->validated()
    );

    echo "PASS cart-validation-hardening-test\n";
} finally {
    wp_set_current_user(0);
    $wpdb->query('ROLLBACK');
}
