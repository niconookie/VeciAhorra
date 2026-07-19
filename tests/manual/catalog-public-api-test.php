<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPublicCatalog(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPublicCatalogSame(mixed $expected, mixed $actual): void
{
    assertPublicCatalog(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function publicCatalogRequest(string $route): WP_REST_Response
{
    $path = (string) wp_parse_url($route, PHP_URL_PATH);
    $request = new WP_REST_Request('GET', $path);
    $query = (string) (wp_parse_url($route, PHP_URL_QUERY) ?? '');

    if ($query !== '') {
        parse_str($query, $params);
        $request->set_query_params($params);
    }

    return rest_do_request($request);
}

global $wpdb;

$routes = rest_get_server()->get_routes();
assertPublicCatalog(
    isset($routes['/veciahorra/v1/catalog/products']),
    'No se registro el listado publico.'
);
assertPublicCatalog(
    isset($routes['/veciahorra/v1/catalog/products/(?P<id>\d+)']),
    'No se registro el detalle publico.'
);

$transaction = $wpdb->query('START TRANSACTION');
assertPublicCatalog($transaction !== false, 'No se inicio transaccion.');

try {
    $products = new ProductRepository();
    $inventory = new InventoryRepository();
    $stores = new StoreRepository();
    $token = 'catalog-' . bin2hex(random_bytes(5));
    $now = current_time('mysql');
    $createProduct = static function (
        string $suffix,
        string $status
    ) use ($products, $token, $now): int {
        return $products->create([
            'woo_product_id' => null,
            'name' => "{$token} {$suffix}",
            'slug' => "{$token}-{$suffix}",
            'sku' => null,
            'description' => '<strong>Descripcion publica</strong>',
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'image_id' => null,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $availableId = $createProduct('available', Product::STATUS_ACTIVE);
    $noStockId = $createProduct('no-stock', Product::STATUS_ACTIVE);
    $inactiveId = $createProduct('inactive', Product::STATUS_INACTIVE);
    $draftId = $createProduct('draft', Product::STATUS_DRAFT);
    $createInventory = static function (
        int $productId,
        int $minimarketId,
        float $price,
        int $stock,
        string $status = 'active'
    ) use ($inventory, $now): int {
        return $inventory->create([
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $seed = random_int(800000000, 899999990);
    $createStore = static function (int $id) use ($stores, $now): void {
        $stores->create([
            'id' => $id,
            'business_name' => 'Catalog store ' . $id,
            'legal_name' => 'Catalog store legal ' . $id,
            'owner_name' => 'Catalog owner',
            'rut' => '1-9',
            'email' => "catalog-{$id}@example.test",
            'phone' => '000000000',
            'mobile' => null,
            'address' => null,
            'commune' => null,
            'city' => null,
            'region' => null,
            'status' => 'active',
            'onboarding_status' => 'complete',
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };

    foreach (range($seed, $seed + 6) as $storeId) {
        $createStore($storeId);
    }

    $createInventory($availableId, $seed, 12.50, 4);
    $createInventory($availableId, $seed + 1, 8.50, 2);
    $createInventory($availableId, $seed + 2, 4.00, 9, 'inactive');
    $createInventory($availableId, $seed + 6, 0.00, 9);
    $createInventory($noStockId, $seed + 3, 6.00, 0);
    $createInventory($inactiveId, $seed + 4, 3.00, 8);
    $createInventory($draftId, $seed + 5, 2.00, 8);

    wp_set_current_user(0);
    $response = publicCatalogRequest(
        '/veciahorra/v1/catalog/products?search=' . rawurlencode($token)
    );
    assertPublicCatalogSame(200, $response->get_status());
    $payload = $response->get_data();
    assertPublicCatalogSame(true, $payload['success'] ?? null);
    assertPublicCatalogSame(1, count($payload['data'] ?? []));
    assertPublicCatalogSame(1, $payload['meta']['total'] ?? null);
    $product = $payload['data'][0];
    $allowed = [
        'id', 'name', 'slug', 'short_description', 'image', 'category',
        'brand', 'unit', 'min_price', 'available_minimarkets',
    ];
    $keys = array_keys($product);
    sort($allowed);
    sort($keys);
    assertPublicCatalogSame($allowed, $keys);
    assertPublicCatalogSame($availableId, $product['id']);
    assertPublicCatalogSame('8.50', $product['min_price']);
    assertPublicCatalogSame(2, $product['available_minimarkets']);
    assertPublicCatalog(
        is_int($product['available_minimarkets']),
        'El conteo de minimarkets no es entero.'
    );
    assertPublicCatalog(
        ! str_contains($product['short_description'], '<strong>'),
        'Descripcion publica contiene HTML.'
    );

    $detail = publicCatalogRequest(
        '/veciahorra/v1/catalog/products/' . $availableId
    );
    assertPublicCatalogSame(200, $detail->get_status());
    assertPublicCatalogSame($availableId, $detail->get_data()['data']['id']);
    assertPublicCatalogSame(
        404,
        publicCatalogRequest(
            '/veciahorra/v1/catalog/products/' . $noStockId
        )->get_status()
    );
    assertPublicCatalogSame(
        404,
        publicCatalogRequest(
            '/veciahorra/v1/catalog/products/' . $inactiveId
        )->get_status()
    );
    assertPublicCatalogSame(
        404,
        publicCatalogRequest(
            '/veciahorra/v1/catalog/products/' . $draftId
        )->get_status()
    );
    assertPublicCatalogSame(
        404,
        publicCatalogRequest(
            '/veciahorra/v1/catalog/products/999999999'
        )->get_status()
    );
    assertPublicCatalogSame(
        200,
        publicCatalogRequest(
            '/veciahorra/v1/catalog/products?search=' . rawurlencode($token)
                . '&order_by=price&page=1&per_page=1'
        )->get_status()
    );
    assertPublicCatalogSame(
        422,
        publicCatalogRequest(
            '/veciahorra/v1/catalog/products?order_by=private'
        )->get_status()
    );
    foreach ([
        'order_by=min_price',
        'page=0',
        'per_page=101',
        'category=anything',
        'brand=-1',
    ] as $invalidQuery) {
        assertPublicCatalogSame(
            422,
            publicCatalogRequest(
                '/veciahorra/v1/catalog/products?' . $invalidQuery
            )->get_status()
        );
    }

    $frontendSource = (string) file_get_contents(
        dirname(__DIR__, 2) . '/assets/frontend/js/veciahorra-frontend.js'
    );
    assertPublicCatalog(
        ! str_contains($frontendSource, '/catalog/products'),
        'El frontend consume Catalog automaticamente.'
    );

    echo "PASS catalog-public-api-test\n";
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);
}
