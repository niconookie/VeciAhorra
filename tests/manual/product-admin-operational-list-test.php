<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function operationalAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function operationalRequest(array $query): WP_REST_Response
{
    $request = new WP_REST_Request(
        'GET',
        '/veciahorra/v1/products'
    );
    $request->set_query_params($query);

    return rest_do_request($request);
}

function operationalRow(array $body, int $id): array
{
    foreach ($body['data'] ?? [] as $row) {
        if (($row['id'] ?? null) === $id) {
            return $row;
        }
    }

    throw new RuntimeException("No se encontro Product {$id}.");
}

global $wpdb;

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
operationalAssert($administratorIds !== [], 'Se requiere administrador.');
wp_set_current_user((int) $administratorIds[0]);

$products = $wpdb->prefix . Config::TABLE_PREFIX . 'products';
$inventory = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$stores = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
$started = $wpdb->query('START TRANSACTION');
operationalAssert($started !== false, 'No se inicio la transaccion.');

try {
    $suffix = strtolower(str_replace('.', '', uniqid('oplist', true)));
    $now = current_time('mysql');
    $category = wp_insert_term('Categoria ' . $suffix, 'product_cat');
    $brand = wp_insert_term('Marca ' . $suffix, 'product_brand');
    $unit = wp_insert_term('Unidad ' . $suffix, 'pa_unidad');
    operationalAssert(! is_wp_error($category), 'No se creo categoria.');
    operationalAssert(! is_wp_error($brand), 'No se creo marca.');
    operationalAssert(! is_wp_error($unit), 'No se creo unidad.');
    $categoryId = (int) $category['term_id'];
    $brandId = (int) $brand['term_id'];
    $unitId = (int) $unit['term_id'];

    $storeIds = [];
    foreach (['active', 'inactive', 'active'] as $index => $status) {
        operationalAssert($wpdb->insert($stores, [
            'business_name' => "Store {$index} {$suffix}",
            'legal_name' => "Legal {$index}",
            'owner_name' => 'Owner',
            'rut' => "1{$index}.111.111-1",
            'email' => "{$suffix}{$index}@example.test",
            'phone' => '+5620000000',
            'status' => $status,
            'onboarding_status' => 'complete',
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1, 'No se creo Store.');
        $storeIds[] = (int) $wpdb->insert_id;
    }

    $productIds = [];
    foreach ([
        ['Publico', 'active', $categoryId, $brandId, $unitId],
        ['Condiciones invalidas', 'active', $categoryId, $brandId, $unitId],
        ['Product inactive', 'inactive', $categoryId, $brandId, $unitId],
        ['Sin ofertas', 'active', 999999991, null, null],
    ] as $index => [$label, $status, $categoryValue, $brandValue, $unitValue]) {
        operationalAssert($wpdb->insert($products, [
            'name' => "{$label} {$suffix}",
            'slug' => sanitize_title("{$label} {$suffix}"),
            'sku' => strtoupper("SKU-{$index}-{$suffix}"),
            'category_id' => $categoryValue,
            'brand_id' => $brandValue,
            'unit_id' => $unitValue,
            'image_id' => $index === 3 ? 999999992 : null,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1, 'No se creo Product.');
        $productIds[] = (int) $wpdb->insert_id;
    }

    $offers = [
        [$productIds[0], $storeIds[0], 1000, 5, 'active'],
        [$productIds[0], $storeIds[1], 1000, 5, 'inactive'],
        [$productIds[0], $storeIds[2], 1000, 5, 'unexpected'],
        [$productIds[1], $storeIds[0], 1000, 0, 'active'],
        [$productIds[1], $storeIds[1], 1000, 5, 'active'],
        [$productIds[1], $storeIds[2], 0, 5, 'active'],
        [$productIds[2], $storeIds[0], 1000, 5, 'active'],
    ];
    foreach ($offers as [$productId, $storeId, $price, $stock, $status]) {
        operationalAssert($wpdb->insert($inventory, [
            'product_id' => $productId,
            'minimarket_id' => $storeId,
            'price' => $price,
            'stock' => $stock,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1, 'No se creo Inventory.');
    }

    $attachmentIds = [];
    foreach ([0, 1] as $index) {
        $attachmentId = wp_insert_attachment([
            'guid' => "https://example.test/{$suffix}-{$index}.jpg",
            'post_mime_type' => 'image/jpeg',
            'post_title' => "Imagen {$suffix} {$index}",
            'post_status' => 'inherit',
        ]);
        operationalAssert(
            is_int($attachmentId) && $attachmentId > 0,
            'No se creo attachment.'
        );
        $attachmentIds[] = $attachmentId;
        update_post_meta(
            $attachmentId,
            '_wp_attached_file',
            "{$suffix}-{$index}.jpg"
        );
        wp_update_attachment_metadata($attachmentId, [
            'width' => 1,
            'height' => 1,
            'file' => "{$suffix}-{$index}.jpg",
            'sizes' => [],
        ]);
        operationalAssert(
            $wpdb->update(
                $products,
                ['image_id' => $attachmentId],
                ['id' => $productIds[$index]]
            ) === 1,
            'No se asigno attachment.'
        );
        clean_post_cache($attachmentId);
    }

    $capturedQueries = [];
    $queryCapture = static function (string $sql) use (&$capturedQueries): string {
        $capturedQueries[] = $sql;

        return $sql;
    };
    add_filter('query', $queryCapture);
    $response = operationalRequest([
        'term' => $suffix,
        'per_page' => 20,
        'order_by' => 'name',
        'direction' => 'ASC',
    ]);
    remove_filter('query', $queryCapture);
    $body = $response->get_data();
    operationalAssert($response->get_status() === 200, 'Listado no retorno 200.');
    operationalAssert(count($body['data'] ?? []) === 4, 'Listado no retorno cuatro Products distintos.');
    operationalAssert(($body['meta']['total'] ?? null) === 4, 'Total incorrecto.');
    operationalAssert(
        $response->get_headers()['Cache-Control'] === 'private, no-store',
        'Falta Cache-Control privado.'
    );
    $aggregateQueries = array_filter(
        $capturedQueries,
        static fn (string $sql): bool =>
            str_contains($sql, "FROM {$inventory} i")
    );
    operationalAssert(
        count($aggregateQueries) === 1,
        'Inventory no fue agregado en una unica consulta.'
    );
    $attachmentBatchQueries = array_filter(
        $capturedQueries,
        static fn (string $sql): bool =>
            str_contains($sql, $wpdb->posts)
            && str_contains(strtoupper($sql), ' IN (')
    );
    operationalAssert(
        count($attachmentBatchQueries) <= 1,
        'Attachments generaron consultas individuales.'
    );

    $public = operationalRow($body, $productIds[0]);
    operationalAssert($public['inventory'] === [
        'total' => 3,
        'active' => 1,
        'inactive' => 1,
    ], 'Agregados no distinguen estado desconocido.');
    operationalAssert($public['publicly_available'] === true, 'Oferta publica no detectada.');
    operationalAssert($public['image_url'] !== null, 'Imagen valida no fue resuelta.');
    operationalAssert($public['category']['name'] !== null, 'Categoria valida ausente.');
    operationalAssert($public['brand']['name'] !== null, 'Marca valida ausente.');
    operationalAssert($public['unit']['name'] !== null, 'Unidad valida ausente.');
    operationalAssert($public['allowed_statuses'] === ['inactive'], 'Lifecycle active incorrecto.');

    $invalid = operationalRow($body, $productIds[1]);
    operationalAssert($invalid['inventory']['total'] === 3, 'Total de ofertas invalidas incorrecto.');
    operationalAssert($invalid['publicly_available'] === false, 'Stock/precio/Store invalidos publicaron Product.');

    $inactive = operationalRow($body, $productIds[2]);
    operationalAssert($inactive['publicly_available'] === false, 'Product inactive aparecio publico.');
    operationalAssert($inactive['allowed_statuses'] === ['active'], 'Lifecycle inactive incorrecto.');

    $empty = operationalRow($body, $productIds[3]);
    operationalAssert($empty['inventory']['total'] === 0, 'Product sin Inventory tiene ofertas.');
    operationalAssert($empty['image_url'] === null, 'Attachment inexistente no uso fallback.');
    operationalAssert($empty['category']['available'] === false, 'Taxonomia huerfana no fue diferenciada.');

    $combined = operationalRequest([
        'term' => $suffix,
        'status' => 'active',
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'page' => 1,
        'per_page' => 1,
        'order_by' => 'name',
        'direction' => 'ASC',
    ])->get_data();
    operationalAssert(($combined['meta']['total'] ?? null) === 2, 'Filtros combinados no coinciden en data/total.');
    operationalAssert(count($combined['data'] ?? []) === 1, 'Paginacion no aplico per_page.');
    $pageTwo = operationalRequest([
        'term' => $suffix,
        'status' => 'active',
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'page' => 2,
        'per_page' => 1,
        'order_by' => 'name',
        'direction' => 'ASC',
    ])->get_data();
    operationalAssert(
        $combined['data'][0]['id'] !== $pageTwo['data'][0]['id'],
        'Paginacion duplico Product.'
    );

    foreach ([
        $public['name'],
        $public['slug'],
        $public['sku'],
        (string) $public['id'],
    ] as $needle) {
        $found = operationalRequest([
            'term' => $needle,
            'category_id' => $categoryId,
        ])->get_data();
        operationalAssert(($found['meta']['total'] ?? 0) >= 1, "Busqueda fallo para {$needle}.");
    }
    $partialId = substr((string) $public['id'], 0, -1);
    $partial = operationalRequest([
        'term' => $partialId,
        'category_id' => $categoryId,
    ])->get_data();
    foreach ($partial['data'] ?? [] as $row) {
        operationalAssert(
            $row['id'] !== $public['id'],
            'ID tuvo coincidencia parcial.'
        );
    }

    $anonymous = 0;
    wp_set_current_user($anonymous);
    operationalAssert(
        operationalRequest(['term' => $suffix])->get_status() === 401,
        'Llamada anonima no fue rechazada.'
    );
    $subscriberIds = get_users([
        'role' => 'subscriber',
        'number' => 1,
        'fields' => 'ids',
    ]);
    if ($subscriberIds !== []) {
        wp_set_current_user((int) $subscriberIds[0]);
        operationalAssert(
            operationalRequest(['term' => $suffix])->get_status() === 403,
            'Usuario sin manage_options no fue rechazado.'
        );
    }

    echo "PASS product-admin-operational-list-test 37 assertions\n";
} finally {
    wp_set_current_user((int) $administratorIds[0]);
    $wpdb->query('ROLLBACK');
}
