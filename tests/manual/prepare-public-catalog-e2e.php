<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Stores\Services\StoreService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

if (PHP_SAPI !== 'cli') {
    status_header(404);
    exit;
}

$stores = new StoreService();
$products = new ProductService();
$productRepository = new ProductRepository();
$inventory = new InventoryService();
$inventoryRepository = new InventoryRepository();
$now = current_time('mysql');

$storeEmail = 'e2e-catalogo@veciahorra.test';
$store = null;
$matchingStores = [];

foreach ($stores->search($storeEmail) as $candidate) {
    if ($candidate->email === $storeEmail) {
        $matchingStores[] = $candidate;
        $store = $candidate;
    }
}

if (count($matchingStores) > 1) {
    throw new RuntimeException('Existen minimarkets E2E duplicados.');
}

$storeData = [
    'business_name' => 'Minimarket VeciAhorra E2E',
    'legal_name' => 'Datos de desarrollo VeciAhorra',
    'owner_name' => 'Equipo de desarrollo',
    'rut' => '11.111.111-1',
    'email' => $storeEmail,
    'phone' => '+56911111111',
    'mobile' => '+56911111111',
    'address' => 'Dirección de prueba 123',
    'commune' => 'Santiago',
    'city' => 'Santiago',
    'region' => 'Metropolitana',
];

if ($store === null) {
    $storeId = $stores->create($storeData + [
        'status' => 'pending',
        'onboarding_status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
} else {
    $storeId = (int) $store->id;
}

$store = $stores->find($storeId);
$desiredStore = $storeData + [
    'status' => 'active',
    'onboarding_status' => 'complete',
    'approved_at' => $store?->approved_at ?: $now,
];

foreach ($desiredStore as $field => $value) {
    if ($store?->{$field} !== $value) {
        $stores->update($storeId, $desiredStore + ['updated_at' => $now]);
        break;
    }
}

$productDefinitions = [
    [
        'sku' => 'VA-E2E-RETIRO-001',
        'name' => 'Producto VeciAhorra Retiro E2E',
        'description' => 'Producto propio de desarrollo para probar retiro bajo $8.000.',
        'price' => 3500,
        'stock' => 20,
        'page_slug' => 'producto-veciahorra-retiro',
    ],
    [
        'sku' => 'VA-E2E-DESPACHO-001',
        'name' => 'Producto VeciAhorra Despacho E2E',
        'description' => 'Producto propio de desarrollo para probar despacho desde $8.000.',
        'price' => 4500,
        'stock' => 20,
        'page_slug' => 'producto-veciahorra-despacho',
    ],
];

$preparedProducts = [];

foreach ($productDefinitions as $definition) {
    $product = $productRepository->findBySku($definition['sku']);
    $productData = [
        'woo_product_id' => null,
        'name' => $definition['name'],
        'sku' => $definition['sku'],
        'description' => $definition['description'],
        'category_id' => null,
        'brand_id' => null,
        'unit_id' => null,
        'image_id' => null,
    ];

    if ($product === null) {
        $productId = $products->create($productData);
    } else {
        $productId = (int) $product->id;

        foreach ($productData as $field => $value) {
            if ($product->{$field} !== $value) {
                $products->update($productId, $productData);
                break;
            }
        }
    }

    $products->updateStatus($productId, 'active');
    $inventoryRow = $inventoryRepository->findByProductAndMinimarket(
        $productId,
        $storeId
    );
    $inventoryData = [
        'price' => $definition['price'],
        'stock' => $definition['stock'],
        'status' => 'active',
    ];

    if ($inventoryRow === null) {
        $inventoryId = $inventory->create($inventoryData + [
            'product_id' => $productId,
            'minimarket_id' => $storeId,
        ]);
    } else {
        $inventoryId = (int) $inventoryRow['id'];

        if (
            (float) $inventoryRow['price'] !== (float) $inventoryData['price']
            || (int) $inventoryRow['stock'] !== $inventoryData['stock']
            || $inventoryRow['status'] !== $inventoryData['status']
        ) {
            $inventory->update($inventoryId, $inventoryData);
        }
    }

    $preparedProduct = $products->find($productId);
    $preparedInventory = $inventory->find($inventoryId);

    if (
        $preparedProduct?->status !== 'active'
        || ($preparedInventory['status'] ?? null) !== 'active'
        || (int) ($preparedInventory['product_id'] ?? 0) !== $productId
        || (int) ($preparedInventory['minimarket_id'] ?? 0) !== $storeId
        || (float) ($preparedInventory['price'] ?? 0) !== (float) $definition['price']
        || (int) ($preparedInventory['stock'] ?? 0) !== $definition['stock']
    ) {
        throw new RuntimeException('La preparación durable del producto no coincide con el contrato.');
    }

    $preparedProducts[] = $definition + [
        'id' => $productId,
        'status' => $preparedProduct->status,
        'woo_product_id' => $preparedProduct->woo_product_id,
        'inventory_id' => $inventoryId,
        'inventory_status' => $preparedInventory['status'],
        'minimarket_id' => (int) $preparedInventory['minimarket_id'],
    ];
}

$publishPage = static function (
    string $slug,
    string $title,
    string $content
): int {
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $payload = [
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => $content,
    ];
    if (
        $existing instanceof WP_Post
        && $existing->post_status === $payload['post_status']
        && $existing->post_title === $payload['post_title']
        && $existing->post_content === $payload['post_content']
    ) {
        return (int) $existing->ID;
    }

    $result = $existing instanceof WP_Post
        ? wp_update_post($payload + ['ID' => $existing->ID], true)
        : wp_insert_post($payload, true);

    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }

    return (int) $result;
};

$productPageIds = [];

foreach ($preparedProducts as $product) {
    $productPageIds[$product['id']] = $publishPage(
        $product['page_slug'],
        $product['name'],
        sprintf('[veciahorra_frontend product_id="%d"]', $product['id'])
    );
}

$catalogLinks = array_map(
    static fn (array $product): string => sprintf(
        '<li><a href="%s">%s — $%s</a></li>',
        esc_url(get_permalink($productPageIds[$product['id']])),
        esc_html($product['name']),
        esc_html(number_format_i18n($product['price'], 0))
    ),
    $preparedProducts
);
$pageIds = [
    'catalog' => $publishPage(
        'catalogo-veciahorra',
        'Catálogo VeciAhorra',
        '<p>Catálogo propio para pruebas end-to-end.</p><ul>'
            . implode('', $catalogLinks)
            . '</ul>'
    ),
    'cart' => $publishPage(
        'carrito-veciahorra',
        'Carrito VeciAhorra',
        '[veciahorra_cart]'
    ),
    'checkout' => $publishPage(
        'checkout',
        'Checkout VeciAhorra',
        '[veciahorra_checkout]'
    ),
];
$pages = [];

foreach ($pageIds as $key => $pageId) {
    $page = get_post($pageId);
    $pages[$key] = [
        'id' => $pageId,
        'status' => $page instanceof WP_Post ? $page->post_status : null,
        'url' => get_permalink($pageId),
    ];
}
$preparedStore = $stores->find($storeId);

echo wp_json_encode([
    'store' => $preparedStore?->toArray(),
    'products' => $preparedProducts,
    'product_page_ids' => $productPageIds,
    'pages' => $pages,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
