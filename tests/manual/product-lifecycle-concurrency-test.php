<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Controllers\ProductController;
use VeciAhorra\Modules\Products\Domain\ProductLifecycleContract;
use VeciAhorra\Modules\Products\Exceptions\ProductConcurrencyException;
use VeciAhorra\Modules\Products\Exceptions\ProductLifecycleException;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Routes\ProductRoutes;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertProductLifecycleSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertProductLifecycleException(
    callable $operation,
    string $exceptionClass
): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw $exception;
    }

    throw new RuntimeException(
        sprintf('Se esperaba %s.', $exceptionClass)
    );
}

global $wpdb;

$transaction = $wpdb->query('START TRANSACTION');

if ($transaction === false) {
    throw new RuntimeException(
        'No fue posible iniciar la transaccion de prueba.'
    );
}

try {
    $lifecycle = new ProductLifecycleContract();
    $lifecycle->assertTransition('draft', 'active');
    $lifecycle->assertTransition('draft', 'inactive');
    $lifecycle->assertTransition('active', 'inactive');
    $lifecycle->assertTransition('inactive', 'active');
    $lifecycle->assertTransition('active', 'active');
    assertProductLifecycleException(
        fn () => $lifecycle->assertTransition('active', 'draft'),
        ProductLifecycleException::class
    );
    assertProductLifecycleException(
        fn () => $lifecycle->assertTransition('archived', 'active'),
        ProductLifecycleException::class
    );
    assertProductLifecycleException(
        fn () => $lifecycle->assertTransition('active', 'archived'),
        ProductLifecycleException::class
    );

    $suffix = bin2hex(random_bytes(6));
    $initialUpdatedAt = '2020-01-01 00:00:00';
    $productsTable =
        $wpdb->prefix . Config::TABLE_PREFIX . 'products';
    $productRepository = new ProductRepository();
    $productId = $productRepository->create([
        'name' => 'Producto lifecycle ' . $suffix,
        'slug' => 'producto-lifecycle-' . $suffix,
        'sku' => 'LIFE-' . strtoupper($suffix),
        'status' => Product::STATUS_DRAFT,
        'created_at' => $initialUpdatedAt,
        'updated_at' => $initialUpdatedAt,
    ]);
    $storeId = (new StoreRepository())->create([
        'business_name' => 'Store lifecycle ' . $suffix,
        'legal_name' => 'Store lifecycle legal ' . $suffix,
        'owner_name' => 'Lifecycle owner',
        'rut' => '1-9',
        'email' => $suffix . '@example.test',
        'phone' => '000000000',
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
        'created_at' => $initialUpdatedAt,
        'updated_at' => $initialUpdatedAt,
    ]);
    $inventoryId = (new InventoryRepository())->create([
        'product_id' => $productId,
        'minimarket_id' => $storeId,
        'price' => 1000,
        'stock' => 5,
        'status' => 'active',
        'created_at' => $initialUpdatedAt,
        'updated_at' => $initialUpdatedAt,
    ]);
    $service = new ProductService();
    $createdId = $service->create([
        'name' => 'Producto creado draft ' . $suffix,
    ]);
    assertProductLifecycleSame(
        Product::STATUS_DRAFT,
        $service->find($createdId)?->status
    );

    assertProductLifecycleException(
        fn () => $productRepository->update(
            $productId,
            ['status' => Product::STATUS_ACTIVE]
        ),
        BadMethodCallException::class
    );

    assertProductLifecycleSame(
        0,
        $productRepository->updateCommercial(
            $productId,
            ['name' => 'CAS que no debe persistir'],
            '2019-01-01 00:00:00',
            '2020-01-01 00:00:01'
        )
    );
    assertProductLifecycleSame(
        'Producto lifecycle ' . $suffix,
        $service->find($productId)?->name
    );

    $activeUpdatedAt = $service->updateStatus(
        $productId,
        Product::STATUS_ACTIVE,
        $initialUpdatedAt
    );
    $active = $service->find($productId);
    assertProductLifecycleSame(Product::STATUS_ACTIVE, $active?->status);
    assertProductLifecycleSame(
        'Producto lifecycle ' . $suffix,
        $active?->name
    );

    assertProductLifecycleException(
        fn () => $service->updateStatus(
            $productId,
            Product::STATUS_DRAFT,
            $activeUpdatedAt
        ),
        ProductLifecycleException::class
    );

    assertProductLifecycleException(
        fn () => $service->update(
            $productId,
            ['name' => 'Sobrescritura obsoleta'],
            $initialUpdatedAt
        ),
        ProductConcurrencyException::class
    );
    assertProductLifecycleException(
        fn () => $service->updateStatus(
            $productId,
            Product::STATUS_INACTIVE,
            $initialUpdatedAt
        ),
        ProductConcurrencyException::class
    );

    assertProductLifecycleSame(
        $activeUpdatedAt,
        $service->updateStatus(
            $productId,
            Product::STATUS_ACTIVE,
            $activeUpdatedAt
        )
    );

    $commercialUpdatedAt = $service->update(
        $productId,
        ['name' => 'Producto comercial ' . $suffix],
        $activeUpdatedAt
    );
    $commercial = $service->find($productId);
    assertProductLifecycleSame(
        Product::STATUS_ACTIVE,
        $commercial?->status
    );
    assertProductLifecycleSame(
        'Producto comercial ' . $suffix,
        $commercial?->name
    );

    $simultaneousUpdatedAt = $productRepository->nextUpdatedAt(
        $commercialUpdatedAt
    );
    assertProductLifecycleSame(
        1,
        $wpdb->update(
            $productsTable,
            [
                'name' => 'Cambio simultaneo ' . $suffix,
                'updated_at' => $simultaneousUpdatedAt,
            ],
            ['id' => $productId]
        )
    );

    $controllerResult = (new ProductController($service))->update(
        $productId,
        [
            'name' => 'Cambio cliente obsoleto',
            'expected_updated_at' => $commercialUpdatedAt,
        ]
    );
    assertProductLifecycleSame(false, $controllerResult['success']);
    assertProductLifecycleSame(
        'product_concurrency_conflict',
        $controllerResult['error']['code']
    );
    assertProductLifecycleSame(
        'Cambio simultaneo ' . $suffix,
        $service->find($productId)?->name
    );

    $inactiveUpdatedAt = $service->updateStatus(
        $productId,
        Product::STATUS_INACTIVE,
        $simultaneousUpdatedAt
    );
    $inactive = $service->find($productId);
    assertProductLifecycleSame(Product::STATUS_INACTIVE, $inactive?->status);
    assertProductLifecycleSame(
        'Cambio simultaneo ' . $suffix,
        $inactive?->name
    );
    assertProductLifecycleSame(
        true,
        strcmp($inactiveUpdatedAt, $simultaneousUpdatedAt) > 0
    );
    $canonicalNow = current_time('mysql');
    assertProductLifecycleSame(
        true,
        strcmp($inactiveUpdatedAt, $canonicalNow) <= 0
    );

    $reactivatedUpdatedAt = $service->updateStatus(
        $productId,
        Product::STATUS_ACTIVE,
        $inactiveUpdatedAt
    );
    assertProductLifecycleSame(
        Product::STATUS_ACTIVE,
        $service->find($productId)?->status
    );
    assertProductLifecycleSame(
        true,
        strcmp($reactivatedUpdatedAt, $inactiveUpdatedAt) > 0
    );

    $draftInactiveId = $productRepository->create([
        'name' => 'Producto draft inactive ' . $suffix,
        'slug' => 'producto-draft-inactive-' . $suffix,
        'status' => Product::STATUS_DRAFT,
        'created_at' => $initialUpdatedAt,
        'updated_at' => $initialUpdatedAt,
    ]);
    $service->updateStatus(
        $draftInactiveId,
        Product::STATUS_INACTIVE,
        $initialUpdatedAt
    );
    assertProductLifecycleSame(
        Product::STATUS_INACTIVE,
        $service->find($draftInactiveId)?->status
    );

    $controller = new ProductController($service);
    $missing = $controller->updateStatus(PHP_INT_MAX, [
        'status' => Product::STATUS_ACTIVE,
        'expected_updated_at' => $initialUpdatedAt,
    ]);
    assertProductLifecycleSame('product_not_found', $missing['error']['code']);

    $commercialWithStatus = $controller->update($productId, [
        'name' => 'No debe aplicar',
        'status' => Product::STATUS_INACTIVE,
        'expected_updated_at' => $reactivatedUpdatedAt,
    ]);
    assertProductLifecycleSame(
        'validation_error',
        $commercialWithStatus['error']['code']
    );
    $lifecycleWithCommercial = $controller->updateStatus($productId, [
        'status' => Product::STATUS_INACTIVE,
        'name' => 'No debe aplicar',
        'expected_updated_at' => $reactivatedUpdatedAt,
    ]);
    assertProductLifecycleSame(
        'validation_error',
        $lifecycleWithCommercial['error']['code']
    );

    $routes = new ProductRoutes($controller);
    $restStatus = static function (
        ProductRoutes $routes,
        int $id,
        array $body
    ): WP_REST_Response {
        $request = new WP_REST_Request(
            'PATCH',
            '/veciahorra/v1/products/' . $id . '/status'
        );
        $request->set_url_params(['id' => (string) $id]);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        return $routes->updateStatus($request);
    };
    assertProductLifecycleSame(
        409,
        $restStatus($routes, $productId, [
            'status' => Product::STATUS_INACTIVE,
            'expected_updated_at' => $initialUpdatedAt,
        ])->get_status()
    );
    assertProductLifecycleSame(
        422,
        $restStatus($routes, $productId, [
            'status' => Product::STATUS_INACTIVE,
        ])->get_status()
    );
    assertProductLifecycleSame(
        422,
        $restStatus($routes, $productId, [
            'status' => Product::STATUS_INACTIVE,
            'expected_updated_at' => 'version-invalida',
        ])->get_status()
    );
    assertProductLifecycleSame(
        400,
        $restStatus($routes, $productId, [
            'status' => 'archived',
            'expected_updated_at' => $reactivatedUpdatedAt,
        ])->get_status()
    );
    assertProductLifecycleSame(
        1,
        $wpdb->update(
            $productsTable,
            ['status' => 'archived'],
            ['id' => $productId]
        )
    );
    $invalidOrigin = $restStatus($routes, $productId, [
        'status' => Product::STATUS_INACTIVE,
        'expected_updated_at' => $reactivatedUpdatedAt,
    ]);
    assertProductLifecycleSame(422, $invalidOrigin->get_status());
    assertProductLifecycleSame(
        'invalid_product_state',
        $invalidOrigin->get_data()['error']['code']
    );
    assertProductLifecycleSame(
        1,
        $wpdb->update(
            $productsTable,
            ['status' => Product::STATUS_ACTIVE],
            ['id' => $productId]
        )
    );

    $bulkA = $productRepository->create([
        'name' => 'Bulk atomico A ' . $suffix,
        'slug' => 'bulk-atomico-a-' . $suffix,
        'status' => Product::STATUS_DRAFT,
        'created_at' => $initialUpdatedAt,
        'updated_at' => $initialUpdatedAt,
    ]);
    $bulkB = $productRepository->create([
        'name' => 'Bulk atomico B ' . $suffix,
        'slug' => 'bulk-atomico-b-' . $suffix,
        'status' => Product::STATUS_DRAFT,
        'created_at' => $initialUpdatedAt,
        'updated_at' => $initialUpdatedAt,
    ]);
    assertProductLifecycleException(
        fn () => $productRepository->updateStatusesAtomically([
            [
                'id' => $bulkA,
                'status' => Product::STATUS_ACTIVE,
                'expected_updated_at' => $initialUpdatedAt,
                'updated_at' => '2020-01-01 00:00:01',
            ],
            [
                'id' => $bulkB,
                'status' => Product::STATUS_ACTIVE,
                'expected_updated_at' => '2019-01-01 00:00:00',
                'updated_at' => '2020-01-01 00:00:01',
            ],
        ]),
        ProductConcurrencyException::class
    );
    assertProductLifecycleSame(
        Product::STATUS_DRAFT,
        $service->find($bulkA)?->status
    );
    assertProductLifecycleSame(
        Product::STATUS_DRAFT,
        $service->find($bulkB)?->status
    );

    $inventory = (new InventoryRepository())->find($inventoryId);
    assertProductLifecycleSame($productId, (int) $inventory['product_id']);
    assertProductLifecycleSame('1000.00', $inventory['price']);
    assertProductLifecycleSame(5, (int) $inventory['stock']);
    assertProductLifecycleSame('active', $inventory['status']);

    echo "OK product lifecycle concurrency\n";
} finally {
    $wpdb->query('ROLLBACK');
}
