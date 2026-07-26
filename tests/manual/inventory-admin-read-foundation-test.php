<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Repositories\InventoryReferenceInspector;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function inventoryReadAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function inventoryAdminRequest(
    string $path,
    array $query = [],
    ?string $nonce = null
): WP_REST_Response {
    $request = new WP_REST_Request('GET', $path);
    $request->set_query_params($query);

    if ($nonce !== null) {
        $request->set_header('X-WP-Nonce', $nonce);
    }

    return rest_do_request($request);
}

global $wpdb;
$admins = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
inventoryReadAssert($admins !== [], 'Se requiere administrador.');
$adminId = (int) $admins[0];
wp_set_current_user($adminId);
$nonce = wp_create_nonce('wp_rest');
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
inventoryReadAssert(
    $wpdb->query('START TRANSACTION') !== false,
    'No se inicio la transaccion.'
);

try {
    $suffix = strtolower(str_replace('.', '', uniqid('invread', true)));
    $now = current_time('mysql');
    $approvedAt = '2026-01-01 12:00:00';

    inventoryReadAssert($wpdb->insert($prefix . 'products', [
        'name' => "Product {$suffix}",
        'slug' => "product-{$suffix}",
        'sku' => "SKU-{$suffix}",
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Product.');
    $productId = (int) $wpdb->insert_id;

    inventoryReadAssert($wpdb->insert($prefix . 'stores', [
        'business_name' => "Store {$suffix}",
        'legal_name' => 'Legal',
        'owner_name' => 'Persona privada',
        'rut' => '99.999.999-9',
        'email' => "private-{$suffix}@example.test",
        'phone' => '+5620000000',
        'commune' => 'Santiago',
        'city' => 'Santiago',
        'region' => 'Metropolitana',
        'status' => 'active',
        'onboarding_status' => 'complete',
        'approved_at' => $approvedAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Store.');
    $storeId = (int) $wpdb->insert_id;
    inventoryReadAssert($wpdb->insert($prefix . 'stores', [
        'business_name' => "Store secondary {$suffix}",
        'legal_name' => 'Legal secundaria',
        'owner_name' => 'Persona privada secundaria',
        'rut' => '88.888.888-8',
        'email' => "private-secondary-{$suffix}@example.test",
        'phone' => '+5620000001',
        'commune' => 'Providencia',
        'city' => 'Santiago',
        'region' => 'Metropolitana',
        'status' => 'active',
        'onboarding_status' => 'complete',
        'approved_at' => $approvedAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Store secundaria.');
    $secondaryStoreId = (int) $wpdb->insert_id;

    inventoryReadAssert($wpdb->insert($prefix . 'inventory', [
        'product_id' => $productId,
        'minimarket_id' => $storeId,
        'price' => '1250.00',
        'stock' => 7,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Inventory.');
    $inventoryId = (int) $wpdb->insert_id;
    inventoryReadAssert($wpdb->insert($prefix . 'inventory', [
        'product_id' => $productId,
        'minimarket_id' => $secondaryStoreId,
        'price' => '1300.00',
        'stock' => 4,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo segunda oferta del mismo Product.');
    $secondaryInventoryId = (int) $wpdb->insert_id;

    inventoryReadAssert($wpdb->insert($prefix . 'products', [
        'name' => "Unknown state {$suffix}",
        'slug' => "unknown-state-{$suffix}",
        'sku' => "UNKNOWN-{$suffix}",
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Product defensivo.');
    $unknownProductId = (int) $wpdb->insert_id;
    inventoryReadAssert($wpdb->insert($prefix . 'inventory', [
        'product_id' => $unknownProductId,
        'minimarket_id' => $storeId,
        'price' => '900.00',
        'stock' => 1,
        'status' => 'unexpected',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Inventory con estado desconocido.');
    $unknownInventoryId = (int) $wpdb->insert_id;
    inventoryReadAssert($wpdb->insert($prefix . 'inventory', [
        'product_id' => 999999997,
        'minimarket_id' => $storeId,
        'price' => '800.00',
        'stock' => 1,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Inventory con Product ausente.');
    $missingProductInventoryId = (int) $wpdb->insert_id;

    inventoryReadAssert($wpdb->insert($prefix . 'cart_items', [
        'session_id' => 'session-' . $suffix,
        'user_id' => null,
        'inventory_id' => $inventoryId,
        'product_id' => $productId,
        'minimarket_id' => $storeId,
        'quantity' => 1,
        'unit_price_snapshot' => '1250.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo CartItem.');
    inventoryReadAssert($wpdb->insert($prefix . 'cart_items', [
        'session_id' => 'mismatch-' . $suffix,
        'user_id' => null,
        'inventory_id' => $inventoryId,
        'product_id' => $unknownProductId,
        'minimarket_id' => $storeId,
        'quantity' => 2,
        'unit_price_snapshot' => '1250.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo CartItem con referencia contradictoria.');

    foreach ([
        ['active', 3],
        ['released', 2],
    ] as [$status, $quantity]) {
        inventoryReadAssert($wpdb->insert($prefix . 'reservations', [
            'order_id' => null,
            'inventory_id' => $inventoryId,
            'product_id' => $productId,
            'minimarket_id' => $storeId,
            'quantity' => $quantity,
            'status' => $status,
            'reserved_at' => $now,
            'expires_at' => '2027-01-01 00:00:00',
            'released_at' => $status === 'released' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1, 'No se creo Reservation.');
    }
    inventoryReadAssert($wpdb->insert($prefix . 'reservations', [
        'order_id' => null,
        'inventory_id' => $unknownInventoryId,
        'product_id' => $unknownProductId,
        'minimarket_id' => $storeId,
        'quantity' => 0,
        'status' => 'active',
        'reserved_at' => $now,
        'expires_at' => '2027-01-01 00:00:00',
        'released_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo Reservation activa de cantidad cero.');

    inventoryReadAssert($wpdb->insert($prefix . 'order_items', [
        'order_id' => 999999,
        'product_id' => $productId,
        'inventory_id' => $inventoryId,
        'quantity' => 1,
        'unit_price' => '1250.00',
        'subtotal' => '1250.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se creo OrderItem.');

    $listQuery = [
        'search' => "product:{$productId}",
        'availability' => 'public',
        'status' => 'active',
        'page' => '1',
        'per_page' => '20',
        'order_by' => 'updated_at',
        'direction' => 'DESC',
    ];
    $warmup = inventoryAdminRequest(
        '/veciahorra/v1/inventory/admin',
        $listQuery,
        $nonce
    );
    inventoryReadAssert($warmup->get_status() === 200, 'Warmup REST fallo.');
    $listStart = (int) $wpdb->num_queries;
    $list = inventoryAdminRequest(
        '/veciahorra/v1/inventory/admin',
        $listQuery,
        $nonce
    );
    $listQueries = (int) $wpdb->num_queries - $listStart;
    $listBody = $list->get_data();
    inventoryReadAssert($list->get_status() === 200, 'Listado no retorno 200.');
    inventoryReadAssert(
        ($list->get_headers()['Cache-Control'] ?? null)
            === 'private, no-store',
        'Listado sin Cache-Control privado.'
    );
    inventoryReadAssert($listQueries <= 3, "Listado uso {$listQueries} queries.");
    inventoryReadAssert(
        ($listBody['meta']['snapshot_consistent'] ?? null) === false
        && ($listBody['meta']['total'] ?? 0) >= 2
        && count($listBody['data'] ?? []) >= 2,
        'Metadatos del listado incompletos.'
    );
    $listed = array_values(array_filter(
        $listBody['data'] ?? [],
        static fn (array $item): bool =>
            (int) ($item['id'] ?? 0) === $inventoryId
    ))[0] ?? null;
    inventoryReadAssert(is_array($listed), 'Inventory no aparecio en listado.');
    inventoryReadAssert(
        $listed['product']['name'] === "Product {$suffix}"
        && $listed['store']['name'] === "Store {$suffix}"
        && $listed['availability']['is_publicly_available'] === true,
        'DTO de listado incompleto.'
    );
    inventoryReadAssert(
        $listed['references']['has_cart_items'] === true
        && $listed['references']['has_active_reservations'] === true
        && $listed['references']['has_history'] === true,
        'Flags referenciales incorrectos.'
    );
    inventoryReadAssert(
        ! isset($listed['product']['image']),
        'El listado resolvio imagen por fila.'
    );

    $detailStart = (int) $wpdb->num_queries;
    $detail = inventoryAdminRequest(
        "/veciahorra/v1/inventory/{$inventoryId}/admin",
        [],
        $nonce
    );
    $detailQueries = (int) $wpdb->num_queries - $detailStart;
    $detailBody = $detail->get_data();
    $data = $detailBody['data'] ?? [];
    inventoryReadAssert($detail->get_status() === 200, 'Detalle no retorno 200.');
    inventoryReadAssert(
        ($detail->get_headers()['Cache-Control'] ?? null)
            === 'private, no-store',
        'Detalle sin Cache-Control privado.'
    );
    inventoryReadAssert(
        $detailQueries <= 2,
        "Detalle sin imagen uso {$detailQueries} queries."
    );
    inventoryReadAssert(
        $data['identity']['id'] === $inventoryId
        && $data['offer']['product_id'] === $productId
        && $data['offer']['minimarket_id'] === $storeId,
        'Identidad del detalle incorrecta.'
    );
    inventoryReadAssert(
        ($data['product']['image']['status'] ?? null) === 'absent',
        'Imagen no asignada no fue diferenciada.'
    );
    inventoryReadAssert(
        $data['references']['cart']['total'] === 2
        && $data['references']['reservations']['active'] === 1
        && $data['references']['reservations']['active_quantity'] === 3
        && $data['references']['reservations']['released'] === 1
        && $data['references']['order_items']['total'] === 1
        && $data['references']['classification'] === 'unknown'
        && in_array(
            'reference_mismatch',
            $data['references']['warning_codes'],
            true
        ),
        'Inspector agregado incorrecto.'
    );
    inventoryReadAssert(
        $listed['id'] === $data['identity']['id']
        && $listed['product']['id'] === $data['offer']['product_id']
        && $listed['product']['name'] === $data['product']['name']
        && $listed['product']['status'] === $data['product']['status']
        && $listed['store']['id'] === $data['offer']['minimarket_id']
        && $listed['store']['name'] === $data['store']['name']
        && $listed['store']['status'] === $data['store']['status']
        && $listed['price'] === $data['offer']['price']
        && $listed['stock'] === $data['offer']['stock']
        && $listed['status'] === $data['offer']['status']
        && $listed['availability']['primary_cause']['code']
            === $data['availability']['primary_cause']['code']
        && $listed['created_at'] === $data['identity']['created_at']
        && $listed['updated_at'] === $data['identity']['updated_at']
        && $listed['version'] === $data['concurrency']['version'],
        'Listado y detalle divergieron en campos equivalentes.'
    );
    inventoryReadAssert(
        $data['concurrency']['mode'] === 'last_write_wins'
        && $data['actions']['view'] === true
        && ! array_key_exists('delete', $data['actions']),
        'Concurrencia o acciones incorrectas.'
    );
    $encoded = wp_json_encode($detailBody);
    inventoryReadAssert(
        is_string($encoded)
        && ! str_contains($encoded, 'private-')
        && ! str_contains($encoded, 'Persona privada')
        && ! str_contains($encoded, '999999')
        && ! str_contains(strtolower($encoded), 'select '),
        'El detalle expuso PII, pedidos o SQL.'
    );

    $unknownDetail = inventoryAdminRequest(
        "/veciahorra/v1/inventory/{$unknownInventoryId}/admin",
        [],
        $nonce
    )->get_data()['data'] ?? [];
    inventoryReadAssert(
        ($unknownDetail['availability']['primary_cause']['code'] ?? null)
            === 'inventory_status_unknown'
        && ($unknownDetail['lifecycle']['allowed_actions'] ?? null) === []
        && (
            $unknownDetail['references']['reservations']['active_quantity']
                ?? null
        ) === 0,
        'El detalle no preservo el estado Inventory desconocido.'
    );
    $missingProductDetail = inventoryAdminRequest(
        "/veciahorra/v1/inventory/{$missingProductInventoryId}/admin",
        [],
        $nonce
    )->get_data()['data'] ?? [];
    inventoryReadAssert(
        ($missingProductDetail['product']['exists'] ?? null) === false
        && (
            $missingProductDetail['availability']['primary_cause']['code']
                ?? null
        ) === 'product_missing'
        && ($missingProductDetail['actions']['edit'] ?? null) === false,
        'La referencia Product faltante no fue diagnosticada.'
    );

    inventoryReadAssert(
        $wpdb->update(
            $prefix . 'products',
            ['image_id' => 999999996],
            ['id' => $productId]
        ) !== false,
        'No se asigno imagen inexistente.'
    );
    $missingImage = inventoryAdminRequest(
        "/veciahorra/v1/inventory/{$inventoryId}/admin",
        [],
        $nonce
    )->get_data()['data']['product']['image'] ?? [];
    inventoryReadAssert(
        ($missingImage['status'] ?? null) === 'missing_attachment',
        'Imagen inexistente no fue diferenciada.'
    );

    $nonImageId = wp_insert_attachment([
        'post_title' => "Documento {$suffix}",
        'post_status' => 'inherit',
        'post_mime_type' => 'application/pdf',
        'guid' => "https://example.test/{$suffix}.pdf",
    ]);
    inventoryReadAssert(
        is_int($nonImageId) && $nonImageId > 0,
        'No se creo attachment no imagen.'
    );
    $wpdb->update(
        $prefix . 'products',
        ['image_id' => $nonImageId],
        ['id' => $productId]
    );
    $nonImage = inventoryAdminRequest(
        "/veciahorra/v1/inventory/{$inventoryId}/admin",
        [],
        $nonce
    )->get_data()['data']['product']['image'] ?? [];
    inventoryReadAssert(
        ($nonImage['status'] ?? null) === 'unavailable',
        'Attachment no imagen no fue rechazado.'
    );

    $imageId = wp_insert_attachment([
        'post_title' => "Imagen {$suffix}",
        'post_status' => 'inherit',
        'post_mime_type' => 'image/jpeg',
        'guid' => "https://example.test/{$suffix}.jpg",
    ]);
    inventoryReadAssert(
        is_int($imageId) && $imageId > 0,
        'No se creo attachment imagen.'
    );
    update_post_meta($imageId, '_wp_attached_file', "2026/01/{$suffix}.jpg");
    $wpdb->update(
        $prefix . 'products',
        ['image_id' => $imageId],
        ['id' => $productId]
    );
    clean_post_cache($imageId);
    $imageStart = (int) $wpdb->num_queries;
    $validImageResponse = inventoryAdminRequest(
        "/veciahorra/v1/inventory/{$inventoryId}/admin",
        [],
        $nonce
    );
    $imageQueries = (int) $wpdb->num_queries - $imageStart;
    $validImage = $validImageResponse->get_data()['data']['product']['image']
        ?? [];
    inventoryReadAssert(
        ($validImage['status'] ?? null) === 'valid'
        && is_string($validImage['url'] ?? null)
        && $imageQueries <= 4,
        'Imagen valida no fue resuelta.'
    );

    $emptyStart = (int) $wpdb->num_queries;
    $empty = inventoryAdminRequest(
        '/veciahorra/v1/inventory/admin',
        ['search' => 'inventory:999999998'],
        $nonce
    );
    $emptyQueries = (int) $wpdb->num_queries - $emptyStart;
    inventoryReadAssert(
        $empty->get_status() === 200
        && ($empty->get_data()['data'] ?? null) === []
        && $emptyQueries <= 2,
        "Listado vacio invalido o uso {$emptyQueries} queries."
    );

    $causeFiltered = inventoryAdminRequest(
        '/veciahorra/v1/inventory/admin',
        [
            'availability' => 'diagnostic_error',
            'cause' => 'product_missing',
            'product_id' => (string) 999999997,
        ],
        $nonce
    )->get_data()['data'] ?? [];
    inventoryReadAssert(
        count($causeFiltered) === 1
        && (int) $causeFiltered[0]['id'] === $missingProductInventoryId,
        'Filtro por causa no coincide con effective-v1.'
    );
    $referenceFiltered = inventoryAdminRequest(
        '/veciahorra/v1/inventory/admin',
        [
            'reference' => 'active_reservation',
            'order_by' => 'price',
            'direction' => 'ASC',
        ],
        $nonce
    )->get_data()['data'] ?? [];
    inventoryReadAssert(
        array_filter(
            $referenceFiltered,
            static fn (array $item): bool =>
                (int) $item['id'] === $inventoryId
        ) !== [],
        'Filtro referencial no encontro la reserva activa.'
    );

    foreach ([
        ['query' => ['nonce' => 'secret'], 'status' => 422],
        [
            'query' => [
                'availability' => 'public',
                'cause' => 'out_of_stock',
            ],
            'status' => 422,
        ],
    ] as $invalid) {
        $response = inventoryAdminRequest(
            '/veciahorra/v1/inventory/admin',
            $invalid['query'],
            $nonce
        );
        inventoryReadAssert(
            $response->get_status() === $invalid['status'],
            'Consulta invalida no fue rechazada.'
        );
    }

    inventoryReadAssert(
        inventoryAdminRequest(
            '/veciahorra/v1/inventory/0/admin',
            [],
            $nonce
        )->get_status() === 422,
        'ID invalido no retorno 422.'
    );
    inventoryReadAssert(
        inventoryAdminRequest(
            '/veciahorra/v1/inventory/999999998/admin',
            [],
            $nonce
        )->get_status() === 404,
        'Inventory ausente no retorno 404.'
    );
    $missingNonce = inventoryAdminRequest(
        '/veciahorra/v1/inventory/admin',
        [],
        null
    );
    inventoryReadAssert(
        $missingNonce->get_status() === 403,
        'Nonce ausente no fue rechazado.'
    );
    inventoryReadAssert(
        inventoryAdminRequest(
            '/veciahorra/v1/inventory/admin',
            [],
            'nonce-invalido'
        )->get_status() === 403,
        'Nonce invalido no fue rechazado.'
    );
    inventoryReadAssert(
        inventoryAdminRequest(
            "/veciahorra/v1/inventory/{$inventoryId}/admin",
            ['redirect' => 'https://evil.test/'],
            $nonce
        )->get_status() === 422,
        'Detalle acepto parametros funcionales.'
    );

    $emptyInspection = (new InventoryReferenceInspector())->inspect(999999998);
    inventoryReadAssert(
        $emptyInspection['classification'] === 'unreferenced'
        && $emptyInspection['reservations']['active_quantity'] === null
        && $emptyInspection['cart']['total'] === 0
        && $emptyInspection['order_items']['total'] === 0,
        'Inspector vacio no preservo nulos o ceros.'
    );

    $queryFilter = static function (string $query): string {
        return str_contains($query, 'references_by_inventory')
            ? str_replace('cart_items', 'missing_inventory_refs', $query)
            : $query;
    };
    add_filter('query', $queryFilter);
    $wpdb->suppress_errors(true);
    try {
        (new InventoryReferenceInspector())->inspect($inventoryId);
        throw new RuntimeException('El inspector oculto el fallo SQL.');
    } catch (PersistenceException) {
        // Esperado.
    } finally {
        $wpdb->suppress_errors(false);
        remove_filter('query', $queryFilter);
    }

    foreach ([
        'list' => static fn (string $query): string =>
            str_contains($query, 'ORDER BY i.updated_at')
            && str_contains($query, 'LIMIT 20 OFFSET 0')
                ? str_replace(
                    $prefix . 'inventory',
                    $prefix . 'missing_inventory_admin',
                    $query
                )
                : $query,
        'count' => static fn (string $query): string =>
            str_contains($query, 'SELECT COUNT(*)')
            && str_contains($query, $prefix . 'inventory i')
                ? str_replace(
                    $prefix . 'inventory',
                    $prefix . 'missing_inventory_admin',
                    $query
                )
                : $query,
        'detail' => static fn (string $query): string =>
            str_contains($query, "WHERE i.id = {$inventoryId}")
                ? str_replace(
                    $prefix . 'inventory',
                    $prefix . 'missing_inventory_admin',
                    $query
                )
                : $query,
    ] as $failure => $failureFilter) {
        add_filter('query', $failureFilter);
        $wpdb->suppress_errors(true);
        try {
            $failed = $failure === 'detail'
                ? inventoryAdminRequest(
                    "/veciahorra/v1/inventory/{$inventoryId}/admin",
                    [],
                    $nonce
                )
                : inventoryAdminRequest(
                    '/veciahorra/v1/inventory/admin',
                    $listQuery,
                    $nonce
                );
            $failedBody = $failed->get_data();
            $failedJson = wp_json_encode($failedBody);
            inventoryReadAssert(
                $failed->get_status() === 500
                && ($failedBody['error']['code'] ?? null)
                    === 'inventory_read_failed'
                && is_string($failedJson)
                && ! str_contains(strtolower($failedJson), 'select ')
                && ! str_contains(
                    $failedJson,
                    'missing_inventory_admin'
                ),
                "El fallo SQL {$failure} no fue seguro."
            );
        } finally {
            $wpdb->suppress_errors(false);
            remove_filter('query', $failureFilter);
        }
    }

    wp_set_current_user(0);
    $anonymous = inventoryAdminRequest(
        '/veciahorra/v1/inventory/admin',
        [],
        null
    );
    inventoryReadAssert(
        $anonymous->get_status() === 401,
        'Anonimo no fue rechazado.'
    );

    $subscribers = get_users([
        'role' => 'subscriber',
        'number' => 1,
        'fields' => 'ids',
    ]);
    if ($subscribers !== []) {
        wp_set_current_user((int) $subscribers[0]);
        $subscriberNonce = wp_create_nonce('wp_rest');
        inventoryReadAssert(
            inventoryAdminRequest(
                '/veciahorra/v1/inventory/admin',
                [],
                $subscriberNonce
            )->get_status() === 403,
            'Usuario sin manage_options no fue rechazado.'
        );
    }

    echo "PASS inventory-admin-read-foundation-test"
        . " (list_queries={$listQueries}, detail_queries={$detailQueries},"
        . " image_queries={$imageQueries}, empty_queries={$emptyQueries})\n";
} finally {
    wp_set_current_user($adminId);
    $wpdb->query('ROLLBACK');
}
