<?php

declare(strict_types=1);

use VeciAhorra\Exceptions\CatalogUnavailableException;
use VeciAhorra\Exceptions\CatalogValidationException;
use VeciAhorra\Modules\ProductCatalogs\Repositories\TaxonomyCatalogRepository;
use VeciAhorra\Modules\Products\Services\CatalogValidator;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Products\Controllers\ProductController;
use VeciAhorra\Modules\Products\Routes\ProductRoutes;
use VeciAhorra\Core\Config;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertSameCatalogValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertCatalogError(
    callable $callback,
    string $code,
    string $message
): void {
    try {
        $callback();
    } catch (CatalogValidationException $exception) {
        assertSameCatalogValue($code, $exception->errorCode());
        assertSameCatalogValue($message, $exception->getMessage());

        return;
    }

    throw new RuntimeException(
        'Se esperaba CatalogValidationException.'
    );
}

$registeredForTest = [];
$termIds = [];
$postIds = [];
$productIds = [];

try {
    foreach (
        ['product_cat', 'product_brand', 'pa_unidad']
        as $taxonomy
    ) {
        if (! taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'post');
            $registeredForTest[] = $taxonomy;
        }

        $term = wp_insert_term(
            'Validacion ' . $taxonomy . ' ' . uniqid(),
            $taxonomy
        );

        if (is_wp_error($term)) {
            throw new RuntimeException($term->get_error_message());
        }

        $termIds[$taxonomy] = (int) $term['term_id'];
    }

    $attachmentId = wp_insert_post([
        'post_title' => 'Attachment validacion catalogo',
        'post_status' => 'inherit',
        'post_type' => 'attachment',
    ]);
    $regularPostId = wp_insert_post([
        'post_title' => 'Post validacion catalogo',
        'post_status' => 'draft',
        'post_type' => 'post',
    ]);

    if (is_wp_error($attachmentId) || is_wp_error($regularPostId)) {
        throw new RuntimeException('No fue posible crear los posts de prueba.');
    }

    $postIds = [(int) $attachmentId, (int) $regularPostId];
    $validator = new CatalogValidator();
    global $wpdb;
    $productsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'products';

    $validator->validate([
        'category_id' => $termIds['product_cat'],
        'brand_id' => $termIds['product_brand'],
        'unit_id' => $termIds['pa_unidad'],
        'image_id' => (int) $attachmentId,
    ]);
    $validator->validate([]);
    $validator->validate([
        'category_id' => null,
        'brand_id' => null,
        'unit_id' => null,
        'image_id' => null,
    ]);

    assertCatalogError(
        fn () => $validator->validate(['category_id' => PHP_INT_MAX]),
        'invalid_category_id',
        'La categoría indicada no existe.'
    );
    assertCatalogError(
        fn () => $validator->validate(['brand_id' => PHP_INT_MAX]),
        'invalid_brand_id',
        'La marca indicada no existe.'
    );
    assertCatalogError(
        fn () => $validator->validate(['unit_id' => PHP_INT_MAX]),
        'invalid_unit_id',
        'La unidad indicada no existe.'
    );
    assertCatalogError(
        fn () => $validator->validate(['image_id' => PHP_INT_MAX]),
        'invalid_image_id',
        'La imagen indicada no existe.'
    );
    assertCatalogError(
        fn () => $validator->validate(['image_id' => (int) $regularPostId]),
        'invalid_image_id',
        'El identificador de imagen no corresponde a un attachment.'
    );

    $service = new ProductService($validator);

    $createdId = $service->create([
        'name' => 'Producto validacion catalogo ' . uniqid(),
        'category_id' => $termIds['product_cat'],
        'brand_id' => $termIds['product_brand'],
        'unit_id' => $termIds['pa_unidad'],
        'image_id' => (int) $attachmentId,
    ]);
    $productIds[] = $createdId;
    $createdRow = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$productsTable} WHERE id = %d",
            $createdId
        ),
        ARRAY_A
    );
    assertSameCatalogValue(
        $termIds['product_cat'],
        (int) $createdRow['category_id']
    );
    assertSameCatalogValue(
        $termIds['product_brand'],
        (int) $createdRow['brand_id']
    );
    assertSameCatalogValue(
        $termIds['pa_unidad'],
        (int) $createdRow['unit_id']
    );
    assertSameCatalogValue(
        (int) $attachmentId,
        (int) $createdRow['image_id']
    );

    foreach (
        [
            [
                ['category_id' => PHP_INT_MAX],
                'invalid_category_id',
                'La categoría indicada no existe.',
            ],
            [
                ['brand_id' => PHP_INT_MAX],
                'invalid_brand_id',
                'La marca indicada no existe.',
            ],
            [
                ['unit_id' => PHP_INT_MAX],
                'invalid_unit_id',
                'La unidad indicada no existe.',
            ],
            [
                ['image_id' => PHP_INT_MAX],
                'invalid_image_id',
                'La imagen indicada no existe.',
            ],
            [
                ['image_id' => (int) $regularPostId],
                'invalid_image_id',
                'El identificador de imagen no corresponde a un attachment.',
            ],
        ] as [$invalidData, $errorCode, $errorMessage]
    ) {
        $countBefore = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$productsTable}"
        );
        assertCatalogError(
            fn () => $service->create([
                'name' => 'Producto invalido ' . uniqid(),
                ...$invalidData,
            ]),
            $errorCode,
            $errorMessage
        );
        assertSameCatalogValue(
            $countBefore,
            (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$productsTable}"
            )
        );
    }

    $service->update($createdId, [
        'name' => 'Producto actualizado sin relaciones',
    ]);
    $partialRow = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$productsTable} WHERE id = %d",
            $createdId
        ),
        ARRAY_A
    );
    assertSameCatalogValue(
        $termIds['product_cat'],
        (int) $partialRow['category_id']
    );
    assertSameCatalogValue(
        (int) $attachmentId,
        (int) $partialRow['image_id']
    );

    $service->update($createdId, [
        'category_id' => null,
        'brand_id' => null,
        'unit_id' => null,
        'image_id' => null,
    ]);
    $nullRow = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT category_id, brand_id, unit_id, image_id
             FROM {$productsTable} WHERE id = %d",
            $createdId
        ),
        ARRAY_A
    );
    assertSameCatalogValue(
        [
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'image_id' => null,
        ],
        $nullRow
    );

    $beforeInvalidUpdate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$productsTable} WHERE id = %d",
            $createdId
        ),
        ARRAY_A
    );
    assertCatalogError(
        fn () => $service->update($createdId, [
            'category_id' => PHP_INT_MAX,
        ]),
        'invalid_category_id',
        'La categoría indicada no existe.'
    );
    assertSameCatalogValue(
        $beforeInvalidUpdate,
        $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$productsTable} WHERE id = %d",
                $createdId
            ),
            ARRAY_A
        )
    );

    $service->update($createdId, [
        'image_id' => (int) $attachmentId,
    ]);
    assertSameCatalogValue(
        (int) $attachmentId,
        (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT image_id FROM {$productsTable} WHERE id = %d",
                $createdId
            )
        )
    );

    assertCatalogError(
        fn () => $service->bulkUpdateCategory([1], PHP_INT_MAX),
        'invalid_category_id',
        'La categoría indicada no existe.'
    );

    $controller = new ProductController($service);
    $controllerResult = $controller->bulkUpdateBrand([
        'ids' => [1],
        'brand_id' => PHP_INT_MAX,
    ]);
    assertSameCatalogValue(
        'invalid_brand_id',
        $controllerResult['error']['code'] ?? null
    );

    $routes = new ProductRoutes($controller);
    $errorStatus = (new ReflectionClass($routes))
        ->getMethod('errorStatus');
    assertSameCatalogValue(
        422,
        $errorStatus->invoke($routes, 'invalid_image_id')
    );
    assertSameCatalogValue(
        503,
        $errorStatus->invoke($routes, 'catalog_unavailable')
    );

    $missingCatalog = new class extends TaxonomyCatalogRepository {
        protected function taxonomy(): string
        {
            return 'veciahorra_missing_catalog';
        }
    };

    try {
        $missingCatalog->exists(1);
        throw new RuntimeException(
            'Se esperaba CatalogUnavailableException.'
        );
    } catch (CatalogUnavailableException $exception) {
        assertSameCatalogValue(
            'La taxonomía del catálogo no está registrada.',
            $exception->getMessage()
        );
    }

    echo "OK product-catalog-validation-test\n";
} finally {
    foreach ($productIds as $productId) {
        $wpdb->delete($productsTable, ['id' => $productId], ['%d']);
    }

    foreach ($postIds as $postId) {
        wp_delete_post($postId, true);
    }

    foreach ($termIds as $taxonomy => $termId) {
        wp_delete_term($termId, $taxonomy);
    }

    foreach ($registeredForTest as $taxonomy) {
        unregister_taxonomy($taxonomy);
    }
}
