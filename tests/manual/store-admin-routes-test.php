<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Stores\Services\StoreService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertStoreAdminTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertStoreAdminSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function storeAdminRequest(array $query = []): WP_REST_Response
{
    $request = new WP_REST_Request(
        'GET',
        '/veciahorra/v1/stores'
    );
    $request->set_query_params($query);

    return rest_do_request($request);
}

function routeAllowsStoreAdminGet(array $routes): bool
{
    foreach ($routes['/veciahorra/v1/stores'] ?? [] as $handler) {
        if (($handler['methods']['GET'] ?? false) === true) {
            return true;
        }
    }

    return false;
}

function insertStoreAdminFixture(
    string $table,
    array $values
): int {
    global $wpdb;

    $now = current_time('mysql');
    $inserted = $wpdb->insert($table, $values + [
        'legal_name' => 'Legal privada ' . $values['business_name'],
        'rut' => '99.999.999-9',
        'mobile' => '+56900000000',
        'address' => 'Direccion privada 123',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    assertStoreAdminSame(1, $inserted);

    return (int) $wpdb->insert_id;
}

global $wpdb;

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);

assertStoreAdminTrue(
    $administratorIds !== [],
    'La prueba requiere al menos un administrador.'
);

$temporaryUserId = 0;
$nonAdministratorIds = get_users([
    'role__not_in' => ['administrator'],
    'number' => 1,
    'fields' => 'ids',
]);

if ($nonAdministratorIds === []) {
    $temporaryUserId = wp_insert_user([
        'user_login' => 'va-store-test-' . uniqid(),
        'user_pass' => wp_generate_password(24),
        'user_email' => uniqid('va-store-test-', true) . '@example.test',
        'role' => 'subscriber',
    ]);

    assertStoreAdminTrue(
        is_int($temporaryUserId) && $temporaryUserId > 0,
        'No fue posible crear el usuario sin permisos para la prueba.'
    );
    $nonAdministratorIds = [$temporaryUserId];
}

$table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
$transactionStarted = $wpdb->query('START TRANSACTION');

assertStoreAdminTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion de prueba.'
);

try {
    $suffix = str_replace('.', '', uniqid('storeadmin', true));
    $common = 'grupo-' . $suffix;
    $firstId = insertStoreAdminFixture($table, [
        'business_name' => 'Alfa ' . $common,
        'owner_name' => 'Propietario Nombre ' . $suffix,
        'email' => 'alfa-' . $suffix . '@example.test',
        'phone' => '+56210000001',
        'commune' => 'Santiago',
        'city' => 'Santiago',
        'region' => 'Metropolitana',
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
    ]);
    $secondId = insertStoreAdminFixture($table, [
        'business_name' => 'Alfa ' . $common,
        'owner_name' => 'Propietario Dos ' . $suffix,
        'email' => 'dos-' . $suffix . '@example.test',
        'phone' => '+56210000002',
        'commune' => 'Providencia',
        'city' => 'Santiago',
        'region' => 'Metropolitana',
        'status' => 'inactive',
        'onboarding_status' => 'complete',
        'approved_at' => current_time('mysql'),
    ]);
    insertStoreAdminFixture($table, [
        'business_name' => 'Beta ' . $common,
        'owner_name' => 'Busqueda Propietario ' . $suffix,
        'email' => 'beta-' . $suffix . '@example.test',
        'phone' => '+56210000003',
        'commune' => null,
        'city' => null,
        'region' => null,
        'status' => 'pending',
        'onboarding_status' => 'draft',
        'approved_at' => null,
    ]);
    insertStoreAdminFixture($table, [
        'business_name' => 'Gamma ' . $common,
        'owner_name' => 'Propietario Cuatro ' . $suffix,
        'email' => 'search-email-' . $suffix . '@example.test',
        'phone' => '+56210000004',
        'commune' => 'Nunoa',
        'city' => 'Santiago',
        'region' => 'Metropolitana',
        'status' => 'rejected',
        'onboarding_status' => 'complete',
        'approved_at' => null,
    ]);
    insertStoreAdminFixture($table, [
        'business_name' => "Especial %_ O'Brien Ñandú " . $suffix,
        'owner_name' => 'Propietario Especial ' . $suffix,
        'email' => 'especial-' . $suffix . '@example.test',
        'phone' => '+56210000005',
        'commune' => 'Ñuñoa',
        'city' => 'Santiago',
        'region' => 'Metropolitana',
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
    ]);

    wp_set_current_user((int) $administratorIds[0]);
    $routes = rest_get_server()->get_routes();
    assertStoreAdminTrue(
        routeAllowsStoreAdminGet($routes),
        'GET /veciahorra/v1/stores no esta registrado.'
    );

    $pageOne = storeAdminRequest([
        'search' => $common,
        'page' => '1',
        'per_page' => '2',
    ]);
    $body = $pageOne->get_data();
    assertStoreAdminSame(200, $pageOne->get_status());
    assertStoreAdminSame(true, $body['success'] ?? null);
    assertStoreAdminSame(2, count($body['data'] ?? []));
    assertStoreAdminSame(1, $body['meta']['page'] ?? null);
    assertStoreAdminSame(2, $body['meta']['per_page'] ?? null);
    assertStoreAdminSame(4, $body['meta']['total'] ?? null);
    assertStoreAdminSame(2, $body['meta']['total_pages'] ?? null);
    assertStoreAdminSame(true, $body['meta']['has_next'] ?? null);
    assertStoreAdminSame($firstId, $body['data'][0]['id'] ?? null);
    assertStoreAdminSame($secondId, $body['data'][1]['id'] ?? null);
    assertStoreAdminSame(
        'private, no-store',
        $pageOne->get_headers()['Cache-Control'] ?? null
    );

    $allowedKeys = [
        'id',
        'name',
        'status',
        'onboarding_status',
        'approved_at',
        'location',
    ];
    $actualKeys = array_keys($body['data'][0]);
    sort($allowedKeys);
    sort($actualKeys);
    assertStoreAdminSame($allowedKeys, $actualKeys);
    assertStoreAdminSame(
        ['commune', 'city', 'region'],
        array_keys($body['data'][0]['location'])
    );
    assertStoreAdminSame('draft', $body['data'][0]['onboarding_status']);
    assertStoreAdminSame(null, $body['data'][0]['approved_at']);
    assertStoreAdminSame('inactive', $body['data'][1]['status']);
    assertStoreAdminTrue(
        is_string($body['data'][1]['approved_at']),
        'La fecha de aprobacion presente no fue serializada.'
    );

    $serialized = wp_json_encode($body['data']);

    foreach ([
        'legal_name',
        'owner_name',
        'rut',
        'email',
        'phone',
        'mobile',
        'address',
        'created_at',
        'updated_at',
        'password',
        'secret',
        'bank',
    ] as $privateField) {
        assertStoreAdminTrue(
            ! str_contains($serialized, '"' . $privateField . '"'),
            "El DTO expone el campo privado {$privateField}."
        );
    }

    $pageTwo = storeAdminRequest([
        'search' => $common,
        'page' => '2',
        'per_page' => '2',
    ]);
    assertStoreAdminSame(200, $pageTwo->get_status());
    assertStoreAdminSame(
        false,
        $pageTwo->get_data()['meta']['has_next'] ?? null
    );

    $emptyPage = storeAdminRequest([
        'search' => $common,
        'page' => '9',
        'per_page' => '2',
    ]);
    assertStoreAdminSame(200, $emptyPage->get_status());
    assertStoreAdminSame([], $emptyPage->get_data()['data'] ?? null);

    $emptySearch = storeAdminRequest(['search' => '   ']);
    assertStoreAdminSame(200, $emptySearch->get_status());
    assertStoreAdminSame(1, $emptySearch->get_data()['meta']['page'] ?? null);
    assertStoreAdminSame(
        20,
        $emptySearch->get_data()['meta']['per_page'] ?? null
    );

    $onePerPage = storeAdminRequest([
        'search' => $common,
        'per_page' => '1',
    ]);
    assertStoreAdminSame(200, $onePerPage->get_status());
    assertStoreAdminSame(1, count($onePerPage->get_data()['data'] ?? []));
    assertStoreAdminSame(4, $onePerPage->get_data()['meta']['total_pages'] ?? null);

    $nonMultiple = storeAdminRequest([
        'search' => $common,
        'per_page' => '3',
    ]);
    assertStoreAdminSame(2, $nonMultiple->get_data()['meta']['total_pages'] ?? null);

    foreach (
        [
            'business_name' => 'Beta ' . $common,
            'owner_name' => 'Busqueda Propietario ' . $suffix,
            'email' => 'search-email-' . $suffix,
            'phone' => '+56210000004',
        ] as $field => $term
    ) {
        $search = storeAdminRequest(['search' => $term]);
        assertStoreAdminSame(200, $search->get_status());
        assertStoreAdminSame(
            1,
            $search->get_data()['meta']['total'] ?? null
        );
    }

    $special = storeAdminRequest([
        'search' => "%_ O'Brien Ñandú " . $suffix,
    ]);
    assertStoreAdminSame(200, $special->get_status());
    assertStoreAdminSame(1, $special->get_data()['meta']['total'] ?? null);

    $noResults = storeAdminRequest(['search' => 'missing-' . $suffix]);
    assertStoreAdminSame(200, $noResults->get_status());
    assertStoreAdminSame(0, $noResults->get_data()['meta']['total'] ?? null);
    assertStoreAdminSame(0, $noResults->get_data()['meta']['total_pages'] ?? null);

    $descendingTie = storeAdminRequest([
        'search' => 'Alfa ' . $common,
        'order_by' => 'business_name',
        'direction' => 'DESC',
    ]);
    assertStoreAdminSame($firstId, $descendingTie->get_data()['data'][0]['id'] ?? null);
    assertStoreAdminSame($secondId, $descendingTie->get_data()['data'][1]['id'] ?? null);

    $defaultServiceRows = (new StoreService())->paginate(
        1,
        4,
        $common
    )->toArray();
    assertStoreAdminTrue(
        (int) $defaultServiceRows[0]['id'] > (int) $defaultServiceRows[1]['id'],
        'El orden predeterminado id DESC del consumidor StoreService cambio.'
    );

    foreach (['active', 'inactive', 'pending', 'rejected'] as $status) {
        $filtered = storeAdminRequest([
            'search' => $common,
            'status' => $status,
        ]);
        assertStoreAdminSame(200, $filtered->get_status());
        assertStoreAdminSame(1, $filtered->get_data()['meta']['total'] ?? null);
        assertStoreAdminSame(
            $status,
            $filtered->get_data()['data'][0]['status'] ?? null
        );
    }

    $normalized = storeAdminRequest([
        'search' => '  Alfa ' . $common . '  ',
        'page' => '01',
        'status' => ' INACTIVE ',
        'order_by' => ' BUSINESS_NAME ',
        'direction' => ' desc ',
    ]);
    assertStoreAdminSame(200, $normalized->get_status());
    assertStoreAdminSame(1, $normalized->get_data()['meta']['page'] ?? null);
    assertStoreAdminSame(1, $normalized->get_data()['meta']['total'] ?? null);

    $emptyStatus = storeAdminRequest([
        'search' => $common,
        'status' => '   ',
    ]);
    assertStoreAdminSame(4, $emptyStatus->get_data()['meta']['total'] ?? null);

    foreach (
        [
            ['page' => '0'],
            ['page' => '-1'],
            ['page' => '1.5'],
            ['page' => true],
            ['page' => ['1', '2']],
            ['page' => '1000001'],
            ['page' => str_repeat('9', 100)],
            ['per_page' => '0'],
            ['per_page' => '1.5'],
            ['per_page' => false],
            ['per_page' => ['20', '30']],
            ['per_page' => '101'],
            ['search' => ['not-a-string']],
            ['search' => true],
            ['search' => str_repeat('x', 101)],
            ['status' => 'unknown'],
            ['status' => ['active', 'inactive']],
            ['order_by' => 'owner_name'],
            ['order_by' => ['business_name']],
            ['direction' => 'SIDEWAYS'],
            ['direction' => ['ASC']],
        ] as $invalidQuery
    ) {
        $invalid = storeAdminRequest($invalidQuery);
        assertStoreAdminSame(422, $invalid->get_status());
        assertStoreAdminSame(
            'validation_error',
            $invalid->get_data()['error']['code'] ?? null
        );
        assertStoreAdminTrue(
            is_string($invalid->get_data()['error']['details']['field'] ?? null),
            'El error 422 no identifica el parametro invalido.'
        );
        assertStoreAdminSame(
            'private, no-store',
            $invalid->get_headers()['Cache-Control'] ?? null
        );
    }

    $maximum = storeAdminRequest([
        'search' => $common,
        'per_page' => '100',
    ]);
    assertStoreAdminSame(200, $maximum->get_status());
    assertStoreAdminSame(100, $maximum->get_data()['meta']['per_page'] ?? null);

    $storeService = new StoreService();
    assertStoreAdminSame(
        1,
        $storeService->search('Busqueda Propietario ' . $suffix)->count()
    );
    $crudId = $storeService->create([
        'business_name' => 'CRUD ' . $suffix,
        'legal_name' => 'CRUD Legal ' . $suffix,
        'owner_name' => 'CRUD Owner',
        'rut' => '88.888.888-8',
        'email' => 'crud-' . $suffix . '@example.test',
        'phone' => '+56210000999',
        'mobile' => null,
        'address' => null,
        'commune' => null,
        'city' => null,
        'region' => null,
        'status' => 'pending',
        'onboarding_status' => 'draft',
        'approved_at' => null,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);
    assertStoreAdminTrue($crudId > 0, 'StoreService::create fallo.');
    assertStoreAdminSame(
        'CRUD ' . $suffix,
        $storeService->find($crudId)?->business_name
    );
    $storeService->update($crudId, [
        'business_name' => 'CRUD Editado ' . $suffix,
        'updated_at' => current_time('mysql'),
    ]);
    assertStoreAdminSame(
        'CRUD Editado ' . $suffix,
        $storeService->find($crudId)?->business_name
    );
    assertStoreAdminSame(
        1,
        $storeService->bulkUpdateStatus([$crudId], 'inactive')
    );
    assertStoreAdminSame(
        'inactive',
        $storeService->find($crudId)?->status
    );
    $storeService->delete($crudId);
    assertStoreAdminSame(null, $storeService->find($crudId));

    wp_set_current_user((int) $nonAdministratorIds[0]);
    assertStoreAdminSame(403, storeAdminRequest()->get_status());

    wp_set_current_user(0);
    assertStoreAdminSame(401, storeAdminRequest()->get_status());

    wp_set_current_user((int) $administratorIds[0]);
    global $wp_rest_auth_cookie;
    $previousNonce = $_SERVER['HTTP_X_WP_NONCE'] ?? null;
    $previousAuthCookie = $wp_rest_auth_cookie ?? null;
    $wp_rest_auth_cookie = true;
    $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
    assertStoreAdminSame(true, rest_cookie_check_errors(null));

    $_SERVER['HTTP_X_WP_NONCE'] = 'invalid-store-admin-nonce';
    $nonceResult = rest_cookie_check_errors(null);
    assertStoreAdminTrue(
        is_wp_error($nonceResult),
        'WordPress no rechazo el nonce REST invalido.'
    );
    assertStoreAdminSame(
        'rest_cookie_invalid_nonce',
        $nonceResult->get_error_code()
    );

    unset($_SERVER['HTTP_X_WP_NONCE']);
    wp_set_current_user((int) $administratorIds[0]);
    $missingNonce = rest_cookie_check_errors(null);
    assertStoreAdminSame(true, $missingNonce);
    assertStoreAdminSame(0, get_current_user_id());
    assertStoreAdminSame(401, storeAdminRequest()->get_status());

    wp_set_current_user((int) $administratorIds[0]);
    assertStoreAdminSame(true, rest_cookie_check_errors(true));

    if ($previousNonce === null) {
        unset($_SERVER['HTTP_X_WP_NONCE']);
    } else {
        $_SERVER['HTTP_X_WP_NONCE'] = $previousNonce;
    }

    $wp_rest_auth_cookie = $previousAuthCookie;

    wp_set_current_user((int) $administratorIds[0]);
    $originalPrefix = $wpdb->prefix;
    $previousSuppressErrors = $wpdb->suppress_errors(true);

    try {
        $wpdb->prefix = 'missing_store_admin_' . uniqid() . '_';
        $failureResponse = storeAdminRequest();
    } finally {
        $wpdb->prefix = $originalPrefix;
        $wpdb->suppress_errors($previousSuppressErrors);
    }

    $failure = $failureResponse->get_data();
    assertStoreAdminSame(503, $failureResponse->get_status());
    assertStoreAdminSame(false, $failure['success'] ?? null);
    assertStoreAdminSame(
        'store_admin_unavailable',
        $failure['error']['code'] ?? null
    );
    assertStoreAdminSame(
        'private, no-store',
        $failureResponse->get_headers()['Cache-Control'] ?? null
    );
    assertStoreAdminTrue(
        ! str_contains(wp_json_encode($failure), 'missing_store_admin_'),
        'El error expone nombres internos de tablas.'
    );

    $application = file_get_contents(
        dirname(__DIR__, 2) . '/app/Core/Application.php'
    );
    assertStoreAdminSame(
        1,
        substr_count($application, '$storeRoutes = $this->container->make')
    );
    assertStoreAdminSame(
        1,
        substr_count($application, "[\$storeRoutes, 'register']")
    );

    $routesFile = file_get_contents(
        dirname(__DIR__, 2) . '/app/Modules/Stores/Routes/StoreRoutes.php'
    );
    assertStoreAdminTrue(
        ! str_contains($routesFile, '$wpdb'),
        'StoreRoutes accede directamente a la base de datos.'
    );
    assertStoreAdminTrue(
        preg_match('/\b(SELECT|INSERT INTO|UPDATE .* SET|DELETE FROM)\b/i', $routesFile)
            !== 1,
        'StoreRoutes contiene SQL.'
    );

    echo "PASS store-admin-routes-test\n";
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);

    if ($temporaryUserId > 0) {
        wp_delete_user($temporaryUserId);
    }
}
