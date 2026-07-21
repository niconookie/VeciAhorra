<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Services\StoreService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function operationalSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\n" . var_export([$expected, $actual], true));
    }
}

function operationalRequest(array $query, string $nonce): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/veciahorra/v1/stores');
    $request->set_header('X-WP-Nonce', $nonce);
    $request->set_query_params($query);
    return rest_do_request($request);
}

global $wpdb;
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
operationalSame(true, $admins !== [], 'Se requiere administrador.');
wp_set_current_user((int) $admins[0]);
$nonce = wp_create_nonce('wp_rest');
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
$service = new StoreService();
$contract = new StoreLifecycleContract();
operationalSame(false, $wpdb->query('START TRANSACTION') === false, 'No se inicio transaccion.');

try {
    $token = str_replace('.', '', uniqid('operational', true));
    $states = [
        'draft' => ['pending', 'draft', null],
        'in_review' => ['pending', 'complete', null],
        'rejected' => ['rejected', 'complete', null],
        'approved_inactive' => ['inactive', 'complete', current_time('mysql')],
        'active' => ['active', 'complete', current_time('mysql')],
        'invalid' => ['active', 'draft', null],
    ];
    $ids = [];
    foreach ($states as $state => [$status, $onboarding, $approvedAt]) {
        $id = $service->create([
            'business_name' => 'Lista ' . $token,
            'legal_name' => 'Legal ' . $state . ' ' . $token,
            'owner_name' => 'Persona no listada',
            'rut' => 'RUT-' . count($ids) . '-' . substr($token, -8),
            'email' => $state . '-' . $token . '@example.test',
            'phone' => '+562' . str_pad((string) count($ids), 8, '0', STR_PAD_LEFT),
            'mobile' => '+56900000000',
            'address' => 'Direccion secreta ' . $state,
            'commune' => 'Comuna ' . $state,
            'city' => 'Ciudad ' . $token,
            'region' => 'Region privada',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        operationalSame(true, $wpdb->update($table, [
            'status' => $status,
            'onboarding_status' => $onboarding,
            'approved_at' => $approvedAt,
        ], ['id' => $id]) !== false, 'No se preparo lifecycle.');
        $ids[$state] = $id;
    }
    operationalSame(1, $wpdb->update($table, [
        'legal_name' => "Especial %_ O'Brien {$token}",
    ], ['id' => $ids['draft']]), 'No se preparo busqueda especial.');

    $base = ['context' => 'admin_list', 'search' => $token, 'per_page' => '100'];
    $response = operationalRequest($base, $nonce);
    operationalSame(200, $response->get_status(), 'Listado rico fallo.');
    $body = $response->get_data();
    operationalSame(6, $body['meta']['total'] ?? null, 'Total rico incorrecto.');
    $allowedKeys = ['id', 'business_name', 'legal_name', 'rut', 'email', 'phone', 'commune', 'city', 'status', 'onboarding_status', 'approved_at', 'lifecycle_state', 'allowed_actions', 'created_at', 'updated_at'];
    operationalSame($allowedKeys, array_keys($body['data'][0] ?? []), 'DTO rico cambio.');
    $encoded = (string) wp_json_encode($body['data']);
    foreach (['owner_name', 'mobile', 'address', 'region'] as $private) {
        operationalSame(false, str_contains($encoded, '"' . $private . '"'), 'DTO expone ' . $private . '.');
    }

    foreach ($states as $state => [$status, $onboarding, $approvedAt]) {
        $filtered = operationalRequest(array_merge($base, ['lifecycle_state' => $state]), $nonce);
        operationalSame(1, $filtered->get_data()['meta']['total'] ?? null, 'Filtro ' . $state . ' incorrecto.');
        $item = $filtered->get_data()['data'][0] ?? [];
        operationalSame($state, $item['lifecycle_state'] ?? null, 'Lifecycle serializado incorrecto.');
        if ($state !== 'invalid') {
            operationalSame($contract->allowedActions($status, $onboarding, $approvedAt), $item['allowed_actions'] ?? null, 'Acciones incorrectas.');
        }
    }

    foreach (['business_name' => $token, 'legal_name' => 'Legal active ' . $token, 'rut' => 'RUT-4-' . substr($token, -8), 'email' => 'active-' . $token, 'commune' => 'Comuna active', 'city' => 'Ciudad ' . $token] as $field => $term) {
        $search = operationalRequest(['context' => 'admin_list', 'search' => $term], $nonce);
        operationalSame(200, $search->get_status(), 'Busqueda ' . $field . ' fallo.');
        operationalSame(true, ($search->get_data()['meta']['total'] ?? 0) > 0, 'Busqueda ' . $field . ' sin resultado.');
    }
    operationalSame(0, operationalRequest(['context' => 'admin_list', 'search' => 'missing-' . $token], $nonce)->get_data()['meta']['total'] ?? null, 'Busqueda ausente encontro filas.');
    operationalSame(1, operationalRequest(['context' => 'admin_list', 'search' => "%_ O'Brien {$token}"], $nonce)->get_data()['meta']['total'] ?? null, 'Comodines o apostrofe no se escaparon.');
    operationalSame(0, operationalRequest(array_merge($base, ['status' => 'active', 'lifecycle_state' => 'rejected']), $nonce)->get_data()['meta']['total'] ?? null, 'Combinacion imposible encontro filas.');
    operationalSame(1, operationalRequest(array_merge($base, ['status' => 'active', 'lifecycle_state' => 'active', 'search' => 'Legal active']), $nonce)->get_data()['meta']['total'] ?? null, 'Combinacion de todos los filtros fallo.');

    foreach (['business_name' => 'ASC', 'created_at' => 'DESC', 'updated_at' => 'DESC'] as $order => $direction) {
        operationalSame(200, operationalRequest(array_merge($base, ['order_by' => $order, 'direction' => $direction]), $nonce)->get_status(), 'Orden rechazado.');
    }
    operationalSame(422, operationalRequest(array_merge($base, ['order_by' => 'rut']), $nonce)->get_status(), 'Orden arbitrario aceptado.');
    operationalSame(422, operationalRequest(array_merge($base, ['lifecycle_state' => 'unknown']), $nonce)->get_status(), 'Lifecycle desconocido aceptado.');
    operationalSame(422, operationalRequest(array_merge($base, ['status' => 'unknown']), $nonce)->get_status(), 'Status desconocido aceptado.');
    foreach (['ADMIN_LIST', ' admin_list', 'admin_list '] as $invalidContext) {
        operationalSame(422, operationalRequest(['context' => $invalidContext], $nonce)->get_status(), 'Contexto no canonico aceptado.');
    }
    operationalSame(422, operationalRequest(['lifecycle_state' => 'active'], $nonce)->get_status(), 'Lifecycle sin contexto aceptado.');

    $pageOne = operationalRequest(array_merge($base, ['page' => '1', 'per_page' => '2']), $nonce)->get_data();
    $pageTwo = operationalRequest(array_merge($base, ['page' => '2', 'per_page' => '2']), $nonce)->get_data();
    $pageThree = operationalRequest(array_merge($base, ['page' => '3', 'per_page' => '2']), $nonce)->get_data();
    operationalSame(2, count($pageOne['data']), 'Primera pagina incorrecta.');
    operationalSame(2, count($pageTwo['data']), 'Pagina intermedia incorrecta.');
    operationalSame(2, count($pageThree['data']), 'Ultima pagina incorrecta.');
    $pagedIds = array_map(static fn (array $item): int => $item['id'], array_merge($pageOne['data'], $pageTwo['data'], $pageThree['data']));
    operationalSame(6, count(array_unique($pagedIds)), 'Paginacion duplico filas con nombre repetido.');
    operationalSame([], operationalRequest(array_merge($base, ['page' => '9', 'per_page' => '2']), $nonce)->get_data()['data'] ?? null, 'Pagina fuera de rango no esta vacia.');
    operationalSame(200, operationalRequest(array_merge($base, ['per_page' => '1']), $nonce)->get_status(), 'Minimo per_page fallo.');
    operationalSame(200, operationalRequest(array_merge($base, ['per_page' => '100']), $nonce)->get_status(), 'Maximo per_page fallo.');
    operationalSame(422, operationalRequest(array_merge($base, ['per_page' => '101']), $nonce)->get_status(), 'per_page invalido aceptado.');

    $crossToken = 'cross-' . $token;
    $crossIds = [];
    foreach (['pending', 'inactive', 'active', 'rejected'] as $status) {
        foreach (['draft', 'complete'] as $onboarding) {
            foreach ([null, '2026-01-02 03:04:05'] as $approvedAt) {
                $id = $service->create([
                    'business_name' => $crossToken,
                    'legal_name' => '',
                    'owner_name' => 'Cross',
                    'rut' => '',
                    'email' => 'cross-' . count($crossIds) . '-' . substr($token, -8) . '@example.test',
                    'phone' => '',
                    'mobile' => '',
                    'address' => '',
                    'commune' => '',
                    'city' => '',
                    'region' => '',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                operationalSame(true, $wpdb->update($table, [
                    'status' => $status,
                    'onboarding_status' => $onboarding,
                    'approved_at' => $approvedAt,
                ], ['id' => $id]) !== false, 'No se preparo matriz cruzada.');
                $state = $contract->classify($status, $onboarding, $approvedAt);
                $crossIds[$state][] = $id;
            }
        }
    }
    foreach (array_keys($states) as $state) {
        $filtered = operationalRequest([
            'context' => 'admin_list',
            'search' => $crossToken,
            'lifecycle_state' => $state,
            'per_page' => '100',
        ], $nonce)->get_data();
        $actual = array_column($filtered['data'] ?? [], 'id');
        sort($actual);
        $expected = $crossIds[$state] ?? [];
        sort($expected);
        operationalSame($expected, $actual, 'SQL y contrato divergen para ' . $state . '.');
    }

    $selector = operationalRequest(['search' => $token, 'order_by' => 'business_name', 'direction' => 'ASC'], $nonce)->get_data();
    operationalSame(['id', 'name', 'status', 'onboarding_status', 'approved_at', 'location'], array_keys($selector['data'][0] ?? []), 'DTO selector cambio.');

    $missingNonce = new WP_REST_Request('GET', '/veciahorra/v1/stores');
    $missingNonce->set_query_params(['context' => 'admin_list']);
    operationalSame(403, rest_do_request($missingNonce)->get_status(), 'Nonce ausente fue aceptado.');
    wp_set_current_user(0);
    operationalSame(401, operationalRequest(['context' => 'admin_list'], $nonce)->get_status(), 'Anonimo fue aceptado.');
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);
}

echo "PASS store-admin-operational-list-test\n";
