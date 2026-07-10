<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Catalog\Service\CatalogService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCatalogDetail(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertCatalogDetailSame(mixed $expected, mixed $actual): void
{
    assertCatalogDetail(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function catalogDetailRequest(string $method, string $route): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request($method, $route));
}

function catalogDetailAssertNoSensitiveKeys(array $payload): void
{
    $forbidden = [
        'status', 'created_at', 'updated_at', 'owner_name', 'legal_name',
        'email', 'phone', 'mobile', 'address', 'commune', 'city', 'region',
        'rut', 'onboarding_status', 'approved_at', 'cost', 'user_id',
    ];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            assertCatalogDetail(
                ! in_array($key, $forbidden, true),
                "El detalle expone el campo sensible {$key}."
            );
        }

        if (is_array($value)) {
            catalogDetailAssertNoSensitiveKeys($value);
        }
    }
}

global $wpdb;

$transaction = $wpdb->query('START TRANSACTION');
assertCatalogDetail($transaction !== false, 'No se inicio transaccion.');

try {
    $products = new ProductRepository();
    $inventory = new InventoryRepository();
    $stores = new StoreRepository();
    $now = current_time('mysql');
    $token = 'detail-' . bin2hex(random_bytes(5));
    $categoryId = random_int(700000000, 709999999);
    $otherCategoryId = $categoryId + 1;
    $storeSeed = random_int(500000000, 509999900);

    $createStore = static function (
        int $id,
        string $status = 'active'
    ) use ($stores, $now, $token): int {
        return $stores->create([
            'id' => $id,
            'business_name' => "{$token} Store {$id}",
            'legal_name' => "{$token} Legal {$id}",
            'owner_name' => 'Private owner',
            'rut' => '1-9',
            'email' => "private-{$id}@example.test",
            'phone' => '111111111',
            'mobile' => '222222222',
            'address' => 'Private address',
            'commune' => 'Private commune',
            'city' => 'Private city',
            'region' => 'Private region',
            'status' => $status,
            'onboarding_status' => 'complete',
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $createProduct = static function (
        string $suffix,
        string $status = Product::STATUS_ACTIVE,
        ?int $category = null
    ) use ($products, $now, $token): int {
        return $products->create([
            'woo_product_id' => null,
            'name' => "{$token} {$suffix}",
            'slug' => "{$token}-{$suffix}",
            'sku' => null,
            'description' => '<p>Descripcion <strong>publica</strong> completa.</p>',
            'category_id' => $category,
            'brand_id' => null,
            'unit_id' => null,
            'image_id' => null,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $createInventory = static function (
        int $productId,
        int $storeId,
        mixed $price,
        int $stock,
        string $status = 'active'
    ) use ($inventory, $now): int {
        return $inventory->create([
            'product_id' => $productId,
            'minimarket_id' => $storeId,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };

    foreach (range($storeSeed, $storeSeed + 20) as $storeId) {
        $createStore($storeId);
    }
    $inactiveStoreId = $storeSeed + 21;
    $createStore($inactiveStoreId, 'inactive');

    $targetId = $createProduct('target', Product::STATUS_ACTIVE, $categoryId);
    $noInventoryId = $createProduct('no-inventory', Product::STATUS_ACTIVE, $categoryId);
    $draftId = $createProduct('draft', Product::STATUS_DRAFT, $categoryId);
    $inactiveId = $createProduct('inactive', Product::STATUS_INACTIVE, $categoryId);
    $onlyInvalidId = $createProduct('invalid-price', Product::STATUS_ACTIVE, $categoryId);

    $lowestId = $createInventory($targetId, $storeSeed, 5.00, 1);
    $tieLowStockId = $createInventory($targetId, $storeSeed + 1, 10.00, 3);
    $tieHighStockFirstId = $createInventory($targetId, $storeSeed + 2, 10.00, 8);
    $tieHighStockSecondId = $createInventory($targetId, $storeSeed + 3, 10.00, 8);
    $createInventory($targetId, $storeSeed + 4, 1.00, 99, 'inactive');
    $createInventory($targetId, $storeSeed + 5, 2.00, 0);
    $createInventory($targetId, $storeSeed + 6, 0.00, 9);
    $createInventory($targetId, $storeSeed + 7, -3.00, 9);
    $createInventory($targetId, $inactiveStoreId, 4.00, 9);
    $createInventory($onlyInvalidId, $storeSeed + 8, 0.00, 9);
    $createInventory($draftId, $storeSeed + 9, 7.00, 2);
    $createInventory($inactiveId, $storeSeed + 10, 7.00, 2);

    $relatedIds = [];

    foreach (range(1, 7) as $number) {
        $relatedId = $createProduct(
            sprintf('related-%02d', $number),
            Product::STATUS_ACTIVE,
            $categoryId
        );
        $relatedIds[] = $relatedId;
        $createInventory(
            $relatedId,
            $storeSeed + 10 + $number,
            20.00 + $number,
            2
        );
    }

    $otherCategoryProductId = $createProduct(
        'other-category',
        Product::STATUS_ACTIVE,
        $otherCategoryId
    );
    $createInventory($otherCategoryProductId, $storeSeed + 20, 6.00, 2);

    $priceMethod = new ReflectionMethod(CatalogService::class, 'normalizePrice');
    $priceMethod->setAccessible(true);
    $catalogService = (new Container())->make(CatalogService::class);
    assertCatalogDetailSame(null, $priceMethod->invoke($catalogService, 'not-numeric'));
    assertCatalogDetailSame(null, $priceMethod->invoke($catalogService, INF));
    assertCatalogDetailSame(null, $priceMethod->invoke($catalogService, 0));
    assertCatalogDetailSame(null, $priceMethod->invoke($catalogService, -1));

    wp_set_current_user(0);
    $before = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, stock, status FROM ' . $wpdb->prefix
                . 'va_inventory WHERE product_id = %d ORDER BY id',
            $targetId
        ),
        ARRAY_A
    );
    $response = catalogDetailRequest(
        'GET',
        '/veciahorra/v1/catalog/products/' . $targetId
    );
    assertCatalogDetailSame(200, $response->get_status());
    $data = $response->get_data()['data'] ?? [];

    foreach ([
        'id', 'slug', 'name', 'short_description', 'description', 'image',
        'category', 'brand', 'unit', 'availability', 'price', 'offers',
        'related_products', 'meta', 'min_price', 'available_minimarkets',
    ] as $field) {
        assertCatalogDetail(array_key_exists($field, $data), "Falta {$field}.");
    }
    assertCatalogDetailSame('Descripcion publica completa.', $data['description']);
    assertCatalogDetailSame('in_stock', $data['availability']);
    assertCatalogDetailSame('5.00', $data['price']['min']);
    assertCatalogDetailSame('10.00', $data['price']['max']);
    assertCatalogDetailSame(4, $data['price']['offers']);
    assertCatalogDetailSame('5.00', $data['min_price']);
    assertCatalogDetailSame(4, $data['available_minimarkets']);
    assertCatalogDetailSame(4, count($data['offers']));
    assertCatalogDetailSame(
        [$lowestId, $tieHighStockFirstId, $tieHighStockSecondId, $tieLowStockId],
        array_column($data['offers'], 'inventory_id')
    );

    foreach ($data['offers'] as $offer) {
        $keys = array_keys($offer);
        sort($keys);
        assertCatalogDetailSame(
            ['inventory_id', 'minimarket', 'minimarket_id', 'price', 'stock'],
            $keys
        );
        assertCatalogDetail(
            ! str_contains($offer['minimarket'], 'Private'),
            'La oferta expone datos privados del minimarket.'
        );
    }

    assertCatalogDetailSame(6, count($data['related_products']));
    $returnedRelatedIds = array_column($data['related_products'], 'id');
    assertCatalogDetail(
        ! in_array($targetId, $returnedRelatedIds, true),
        'El producto actual aparece como relacionado.'
    );
    assertCatalogDetail(
        ! in_array($otherCategoryProductId, $returnedRelatedIds, true),
        'Se incluyo un producto de otra categoria.'
    );
    assertCatalogDetailSame(array_slice($relatedIds, 0, 6), $returnedRelatedIds);
    catalogDetailAssertNoSensitiveKeys($data);
    assertCatalogDetailSame(
        $before,
        $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, stock, status FROM ' . $wpdb->prefix
                    . 'va_inventory WHERE product_id = %d ORDER BY id',
                $targetId
            ),
            ARRAY_A
        )
    );

    foreach ([$noInventoryId, $draftId, $inactiveId, $onlyInvalidId, 999999999] as $hiddenId) {
        assertCatalogDetailSame(
            404,
            catalogDetailRequest(
                'GET',
                '/veciahorra/v1/catalog/products/' . $hiddenId
            )->get_status()
        );
    }
    assertCatalogDetailSame(
        400,
        catalogDetailRequest('GET', '/veciahorra/v1/catalog/products/0')->get_status()
    );
    assertCatalogDetail(
        in_array(
            catalogDetailRequest(
                'POST',
                '/veciahorra/v1/catalog/products/' . $targetId
            )->get_status(),
            [404, 405],
            true
        ),
        'El detalle acepta escrituras.'
    );

    echo "PASS catalog-public-detail-test\n";
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);
}
