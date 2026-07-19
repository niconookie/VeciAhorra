<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPublicCategories(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPublicCategoriesSame(mixed $expected, mixed $actual): void
{
    assertPublicCategories(
        $expected === $actual,
        sprintf("Esperado: %s\nRecibido: %s", var_export($expected, true), var_export($actual, true))
    );
}

function publicCategoriesRequest(string $route): WP_REST_Response
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

function assertPublicCategoriesNoSensitiveData(array $payload): void
{
    $forbidden = [
        'owner_name', 'legal_name', 'email', 'phone', 'mobile', 'address',
        'commune', 'city', 'region', 'rut', 'status', 'onboarding_status',
        'approved_at', 'inventory_id', 'minimarket_id', 'stock', 'price',
    ];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            assertPublicCategories(! in_array($key, $forbidden, true), "Campo sensible expuesto: {$key}.");
        }
        if (is_array($value)) {
            assertPublicCategoriesNoSensitiveData($value);
        }
    }
}

global $wpdb;

assertPublicCategories(
    isset(rest_get_server()->get_routes()['/veciahorra/v1/catalog/categories']),
    'No se registró el endpoint público de categorías.'
);
assertPublicCategoriesSame(
    200,
    publicCategoriesRequest('/veciahorra/v1/catalog/categories')->get_status()
);

$transaction = $wpdb->query('START TRANSACTION');
assertPublicCategories($transaction !== false, 'No se inició la transacción.');

try {
    $products = new ProductRepository();
    $inventory = new InventoryRepository();
    $stores = new StoreRepository();
    $token = 'public-category-' . bin2hex(random_bytes(5));
    $now = current_time('mysql');
    $firstTerm = wp_insert_term('Zulu ' . $token, 'product_cat', ['slug' => 'zulu-' . $token]);
    $secondTerm = wp_insert_term('Alpha ' . $token, 'product_cat', ['slug' => 'alpha-' . $token]);
    $emptyTerm = wp_insert_term('Empty ' . $token, 'product_cat', ['slug' => 'empty-' . $token]);
    $brandTerm = wp_insert_term('Brand ' . $token, 'product_brand', ['slug' => 'brand-' . $token]);
    assertPublicCategories(
        ! is_wp_error($firstTerm) && ! is_wp_error($secondTerm)
            && ! is_wp_error($emptyTerm) && ! is_wp_error($brandTerm),
        'No se crearon términos de prueba.'
    );
    $zuluCategory = (int) $firstTerm['term_id'];
    $alphaCategory = (int) $secondTerm['term_id'];
    $emptyCategory = (int) $emptyTerm['term_id'];
    $brandId = (int) $brandTerm['term_id'];
    $storeSeed = random_int(810000000, 819999900);

    $createStore = static function (int $id, string $status) use ($stores, $now, $token): void {
        $stores->create([
            'id' => $id, 'business_name' => "{$token} {$id}", 'legal_name' => 'Private',
            'owner_name' => 'Private', 'rut' => '1-9', 'email' => "{$id}@example.test",
            'phone' => '000', 'mobile' => null, 'address' => null, 'commune' => null,
            'city' => null, 'region' => null, 'status' => $status,
            'onboarding_status' => 'complete', 'approved_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $createStore($storeSeed, 'active');
    $createStore($storeSeed + 1, 'active');
    $createStore($storeSeed + 2, 'inactive');

    $createProduct = static function (string $suffix, string $status, int $categoryId, ?int $brand = null) use ($products, $now, $token): int {
        return $products->create([
            'woo_product_id' => null, 'name' => "{$token} {$suffix}",
            'slug' => "{$token}-{$suffix}", 'sku' => null, 'description' => null,
            'category_id' => $categoryId, 'brand_id' => $brand, 'unit_id' => null,
            'image_id' => null, 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $createInventory = static function (int $productId, int $storeId, float $price, int $stock, string $status = 'active') use ($inventory, $now): void {
        $inventory->create([
            'product_id' => $productId, 'minimarket_id' => $storeId, 'price' => $price,
            'stock' => $stock, 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    $zuluPublic = $createProduct('zulu-public', Product::STATUS_ACTIVE, $zuluCategory);
    $createInventory($zuluPublic, $storeSeed, 1000, 2);
    $createInventory($zuluPublic, $storeSeed + 1, 900, 3);
    $alphaPublic = $createProduct('alpha-public', Product::STATUS_ACTIVE, $alphaCategory, $brandId);
    $createInventory($alphaPublic, $storeSeed, 800, 1);
    $inactiveProduct = $createProduct('inactive-product', Product::STATUS_INACTIVE, $alphaCategory);
    $createInventory($inactiveProduct, $storeSeed, 700, 1);
    $inactiveInventory = $createProduct('inactive-inventory', Product::STATUS_ACTIVE, $alphaCategory);
    $createInventory($inactiveInventory, $storeSeed, 700, 1, 'inactive');
    $zeroStock = $createProduct('zero-stock', Product::STATUS_ACTIVE, $alphaCategory);
    $createInventory($zeroStock, $storeSeed, 700, 0);
    $zeroPrice = $createProduct('zero-price', Product::STATUS_ACTIVE, $alphaCategory);
    $createInventory($zeroPrice, $storeSeed, 0, 2);
    $inactiveStore = $createProduct('inactive-store', Product::STATUS_ACTIVE, $alphaCategory);
    $createInventory($inactiveStore, $storeSeed + 2, 700, 2);

    wp_set_current_user(0);
    $response = publicCategoriesRequest('/veciahorra/v1/catalog/categories');
    assertPublicCategoriesSame(200, $response->get_status());
    $body = $response->get_data();
    assertPublicCategoriesSame(true, $body['success'] ?? null);
    $testItems = array_values(array_filter(
        $body['data'] ?? [],
        static fn (array $item): bool => in_array($item['id'] ?? 0, [$alphaCategory, $zuluCategory], true)
    ));
    assertPublicCategoriesSame([$alphaCategory, $zuluCategory], array_column($testItems, 'id'));
    assertPublicCategoriesSame([1, 1], array_column($testItems, 'products_count'));
    assertPublicCategories(
        ! in_array($emptyCategory, array_column($body['data'] ?? [], 'id'), true),
        'Se expuso una categoría sin Products públicos.'
    );
    foreach ($testItems as $item) {
        $keys = array_keys($item);
        sort($keys);
        assertPublicCategoriesSame(['id', 'name', 'products_count', 'slug'], $keys);
    }
    assertPublicCategoriesNoSensitiveData($body);

    $unfiltered = publicCategoriesRequest('/veciahorra/v1/catalog/products?search=' . rawurlencode($token));
    assertPublicCategoriesSame(2, $unfiltered->get_data()['meta']['total'] ?? null);
    $filtered = publicCategoriesRequest('/veciahorra/v1/catalog/products?category=' . $alphaCategory . '&search=' . rawurlencode($token) . '&order_by=price');
    assertPublicCategoriesSame(1, $filtered->get_data()['meta']['total'] ?? null);
    assertPublicCategoriesSame($alphaPublic, $filtered->get_data()['data'][0]['id'] ?? null);
    $withBrand = publicCategoriesRequest('/veciahorra/v1/catalog/products?category=' . $alphaCategory . '&brand=' . $brandId . '&search=' . rawurlencode($token) . '&order_by=name');
    assertPublicCategoriesSame(1, $withBrand->get_data()['meta']['total'] ?? null);
    assertPublicCategoriesSame(0, publicCategoriesRequest('/veciahorra/v1/catalog/products?category=' . $emptyCategory)->get_data()['meta']['total'] ?? null);
    assertPublicCategoriesSame(0, publicCategoriesRequest('/veciahorra/v1/catalog/products?category=999999999999&search=' . rawurlencode($token))->get_data()['meta']['total'] ?? null);
    assertPublicCategoriesSame(422, publicCategoriesRequest('/veciahorra/v1/catalog/products?category=not-a-number')->get_status());

    echo "PASS catalog-public-categories-test\n";
} finally {
    $wpdb->query('ROLLBACK');
    clean_term_cache([], 'product_cat');
    wp_set_current_user(0);
}
