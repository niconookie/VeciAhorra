<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Model;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Contracts\StoreTransitionRepositoryInterface;
use VeciAhorra\Modules\Stores\Controllers\StoreAdminReadController;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;
use VeciAhorra\Modules\Stores\Routes\StoreRoutes;
use VeciAhorra\Modules\Stores\Services\StoreService;
use VeciAhorra\Modules\Stores\Services\StoreTransitionService;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function restStoreSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(
            "\nEsperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function restStoreFixture(StoreService $stores, string $token): int
{
    $now = current_time('mysql');
    return $stores->create([
        'business_name' => 'REST ' . $token,
        'legal_name' => 'REST Legal',
        'owner_name' => 'REST Owner',
        'rut' => '33.333.333-3',
        'email' => $token . '@example.test',
        'phone' => '+56210000555',
        'mobile' => '+56910000555',
        'address' => 'REST 123',
        'commune' => 'Santiago',
        'city' => 'Santiago',
        'region' => 'Metropolitana',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function restStoreRequest(
    string $method,
    string $path,
    ?array $body = null,
    ?string $nonce = null
): WP_REST_Response {
    $request = new WP_REST_Request($method, $path);
    if ($nonce !== null) {
        $request->set_header('X-WP-Nonce', $nonce);
    }
    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function restStoreTransition(int $id, string $action, string $nonce): WP_REST_Response
{
    return restStoreRequest('POST', "/veciahorra/v1/stores/{$id}/transitions", ['action' => $action], $nonce);
}

function restStoreRawRequest(
    string $method,
    string $path,
    string $body,
    string $nonce,
    bool $json = true
): WP_REST_Response {
    $request = new WP_REST_Request($method, $path);
    $request->set_header('X-WP-Nonce', $nonce);
    if ($json) {
        $request->set_header('content-type', 'application/json');
    }
    $request->set_body($body);

    return rest_do_request($request);
}

final class RestMutatingTransitionRepository implements StoreTransitionRepositoryInterface
{
    public int $writes = 0;
    public function __construct(private StoreRepository $inner, private Closure $mutation) {}
    public function find(int $id): ?Model { return $this->inner->find($id); }
    public function compareAndSetLifecycle(int $id, array $expected, array $target, string $updatedAt): int
    {
        $this->writes++;
        ($this->mutation)($id);
        return $this->inner->compareAndSetLifecycle($id, $expected, $target, $updatedAt);
    }
}

final class RestFailingTransitionRepository implements StoreTransitionRepositoryInterface
{
    public function __construct(private StoreRepository $inner) {}
    public function find(int $id): ?Model { return $this->inner->find($id); }
    public function compareAndSetLifecycle(int $id, array $expected, array $target, string $updatedAt): int
    {
        throw new PersistenceException('SQL sensible que no debe exponerse.');
    }
}

global $wpdb;
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
restStoreSame(true, $admins !== [], 'La prueba requiere administrador.');
$subscriberId = wp_insert_user([
    'user_login' => 'store-rest-' . uniqid(),
    'user_pass' => wp_generate_password(24),
    'user_email' => uniqid('store-rest-', true) . '@example.test',
    'role' => 'subscriber',
]);
restStoreSame(true, is_int($subscriberId) && $subscriberId > 0, 'No se creo usuario sin permisos.');

$stores = new StoreService();
$contract = new StoreLifecycleContract();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
restStoreSame(false, $wpdb->query('START TRANSACTION') === false, 'No se inicio transaccion REST.');

try {
    wp_set_current_user((int) $admins[0]);
    $nonce = wp_create_nonce('wp_rest');
    $states = [
        'draft' => ['pending', 'draft', null],
        'in_review' => ['pending', 'complete', null],
        'rejected' => ['rejected', 'complete', null],
        'approved_inactive' => ['inactive', 'complete', current_time('mysql')],
        'active' => ['active', 'complete', current_time('mysql')],
    ];
    $stateIds = [];
    foreach ($states as $state => [$status, $onboarding, $approval]) {
        $id = restStoreFixture($stores, $state . '-' . uniqid());
        $stateIds[$state] = $id;
        restStoreSame(true, $wpdb->update($table, [
            'status' => $status, 'onboarding_status' => $onboarding, 'approved_at' => $approval,
        ], ['id' => $id]) !== false, 'No se preparo ' . $state . '.');
        $response = restStoreRequest('GET', "/veciahorra/v1/stores/{$id}", null, $nonce);
        $data = $response->get_data()['data'] ?? [];
        restStoreSame(200, $response->get_status(), 'Detalle fallo para ' . $state . '.');
        restStoreSame($state, $data['lifecycle_state'] ?? null, 'Estado derivado incorrecto.');
        restStoreSame($contract->allowedActions($status, $onboarding, $approval), $data['allowed_actions'] ?? null, 'Acciones derivadas incorrectas.');
        restStoreSame('private, no-store', $response->get_headers()['Cache-Control'] ?? null, 'Detalle permite cache.');
        foreach (['id', 'business_name', 'legal_name', 'owner_name', 'rut', 'email', 'phone', 'mobile', 'address', 'commune', 'city', 'region', 'status', 'onboarding_status', 'approved_at', 'created_at', 'updated_at'] as $field) {
            restStoreSame(true, array_key_exists($field, $data), 'DTO sin campo ' . $field . '.');
        }
    }

    $invalidCombinationId = restStoreFixture($stores, 'invalid-' . uniqid());
    restStoreSame(1, $wpdb->update($table, [
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
    ], ['id' => $invalidCombinationId]), 'No se preparo combinacion invalida.');
    $invalidCombination = restStoreRequest(
        'GET',
        "/veciahorra/v1/stores/{$invalidCombinationId}",
        null,
        $nonce
    );
    restStoreSame(422, $invalidCombination->get_status(), 'Combinacion invalida no devolvio 422.');
    restStoreSame('invalid_combination', $invalidCombination->get_data()['error']['code'] ?? null, 'Codigo de combinacion invalida incorrecto.');

    $missing = restStoreRequest('GET', '/veciahorra/v1/stores/999999999', null, $nonce);
    restStoreSame(404, $missing->get_status(), 'Store inexistente no devolvio 404.');
    restStoreSame('store_not_found', $missing->get_data()['error']['code'] ?? null, 'Codigo 404 incorrecto.');
    foreach (['-1', 'abc', '1.5', '0', '01'] as $invalidId) {
        restStoreSame(422, restStoreRequest('GET', '/veciahorra/v1/stores/' . $invalidId, null, $nonce)->get_status(), 'ID invalido aceptado.');
    }

    $flowId = restStoreFixture($stores, 'flow-' . uniqid());
    $response = restStoreTransition($flowId, 'submit_for_review', $nonce);
    restStoreSame('in_review', $response->get_data()['data']['lifecycle_state'] ?? null, 'submit_for_review fallo.');
    restStoreSame(409, restStoreTransition($flowId, 'submit_for_review', $nonce)->get_status(), 'Segunda transicion no idempotente fue aceptada.');
    $response = restStoreTransition($flowId, 'approve', $nonce);
    restStoreSame('approved_inactive', $response->get_data()['data']['lifecycle_state'] ?? null, 'approve fallo.');
    $approval = $stores->find($flowId)?->approved_at;
    $response = restStoreTransition($flowId, 'activate', $nonce);
    restStoreSame('active', $response->get_data()['data']['lifecycle_state'] ?? null, 'activate fallo.');
    restStoreSame($approval, $stores->find($flowId)?->approved_at, 'REST activar cambio aprobacion.');
    $response = restStoreTransition($flowId, 'deactivate', $nonce);
    restStoreSame('approved_inactive', $response->get_data()['data']['lifecycle_state'] ?? null, 'deactivate fallo.');

    $rejectId = restStoreFixture($stores, 'reject-' . uniqid());
    restStoreTransition($rejectId, 'submit_for_review', $nonce);
    restStoreSame('rejected', restStoreTransition($rejectId, 'reject', $nonce)->get_data()['data']['lifecycle_state'] ?? null, 'reject fallo.');
    restStoreSame('draft', restStoreTransition($rejectId, 'return_to_draft', $nonce)->get_data()['data']['lifecycle_state'] ?? null, 'return_to_draft fallo.');

    $prohibited = [
        [$stateIds['draft'], 'approve'],
        [$stateIds['in_review'], 'activate'],
        [$stateIds['approved_inactive'], 'reject'],
        [$stateIds['active'], 'return_to_draft'],
    ];
    foreach ($prohibited as [$id, $action]) {
        $blocked = restStoreTransition($id, $action, $nonce);
        restStoreSame(409, $blocked->get_status(), 'Transicion prohibida no devolvio 409.');
        restStoreSame('action_not_allowed', $blocked->get_data()['error']['data']['reason'] ?? null, 'Motivo prohibido incorrecto.');
    }

    foreach ([
        ['action' => ''],
        ['action' => null],
        ['action' => 1],
        ['action' => 'unknown'],
        ['action' => 'submit'],
        ['action' => 'reopen'],
        ['action' => 'SUBMIT_FOR_REVIEW'],
        ['action' => ' submit_for_review'],
        ['action' => 'submit_for_review '],
        ['action' => 'save'],
        ['action' => 'delete_if_unreferenced'],
        ['action' => 'submit_for_review', 'status' => 'active'],
        ['action' => 'submit_for_review', 'onboarding_status' => 'complete'],
        ['action' => 'submit_for_review', 'approved_at' => current_time('mysql')],
        ['action' => 'submit_for_review', 'reason' => 'texto descartado'],
        ['action' => 'submit_for_review', 'force' => true],
        ['action' => ['name' => 'submit_for_review']],
    ] as $invalidPayload) {
        $invalid = restStoreRequest('POST', "/veciahorra/v1/stores/{$stateIds['draft']}/transitions", $invalidPayload, $nonce);
        restStoreSame(422, $invalid->get_status(), 'Payload prohibido aceptado.');
        restStoreSame('validation_error', $invalid->get_data()['error']['code'] ?? null, 'Error de payload inestable.');
        restStoreSame('private, no-store', $invalid->get_headers()['Cache-Control'] ?? null, 'Error de payload permite cache.');
    }

    foreach (['', 'null', '[]', '"submit_for_review"', '1', '{"action":'] as $rawBody) {
        $invalid = restStoreRawRequest(
            'POST',
            "/veciahorra/v1/stores/{$stateIds['draft']}/transitions",
            $rawBody,
            $nonce
        );
        restStoreSame(400, $invalid->get_status(), 'Representacion JSON invalida fue aceptada.');
    }
    restStoreSame(
        400,
        restStoreRawRequest(
            'POST',
            "/veciahorra/v1/stores/{$stateIds['draft']}/transitions",
            '{"action":"submit_for_review"}',
            $nonce,
            false
        )->get_status(),
        'Content-Type no JSON fue aceptado.'
    );

    $deleteId = restStoreFixture($stores, 'delete-' . uniqid());
    $deleteResponse = restStoreRequest('DELETE', "/veciahorra/v1/stores/{$deleteId}", null, $nonce);
    restStoreSame(204, $deleteResponse->get_status(), 'DELETE seguro fallo.');
    restStoreSame(null, $deleteResponse->get_data(), 'DELETE 204 incluyo cuerpo.');
    restStoreSame('private, no-store', $deleteResponse->get_headers()['Cache-Control'] ?? null, 'DELETE permite cache.');
    restStoreSame(404, restStoreRequest('DELETE', "/veciahorra/v1/stores/{$deleteId}", null, $nonce)->get_status(), 'DELETE repetido no devolvio 404.');
    restStoreSame(409, restStoreRequest('DELETE', "/veciahorra/v1/stores/{$stateIds['active']}", null, $nonce)->get_status(), 'DELETE active no fue bloqueado.');
    $deleteWithBodyId = restStoreFixture($stores, 'delete-body-' . uniqid());
    $deleteWithBody = restStoreRawRequest(
        'DELETE',
        "/veciahorra/v1/stores/{$deleteWithBodyId}",
        '{"force":true}',
        $nonce
    );
    restStoreSame(400, $deleteWithBody->get_status(), 'DELETE acepto force.');
    restStoreSame(true, $stores->find($deleteWithBodyId) !== null, 'DELETE ejecuto dominio pese al cuerpo invalido.');

    foreach (['inventory', 'cart_items', 'reservations', 'orders', 'deliveries'] as $domain) {
        $id = restStoreFixture($stores, 'ref-' . $domain . '-' . uniqid());
        $base = random_int(800000000, 899999999);
        $now = current_time('mysql');
        $target = $wpdb->prefix . Config::TABLE_PREFIX . $domain;
        $row = match ($domain) {
            'inventory' => ['product_id' => $base, 'minimarket_id' => $id, 'price' => '1.00', 'stock' => 0, 'status' => 'inactive', 'created_at' => $now, 'updated_at' => $now],
            'cart_items' => ['session_id' => 'rest-' . $base, 'user_id' => null, 'inventory_id' => $base, 'product_id' => $base, 'minimarket_id' => $id, 'quantity' => 1, 'unit_price_snapshot' => '1.00', 'created_at' => $now, 'updated_at' => $now],
            'reservations' => ['order_id' => null, 'inventory_id' => $base, 'product_id' => $base, 'minimarket_id' => $id, 'quantity' => 1, 'status' => 'expired', 'reserved_at' => $now, 'expires_at' => $now, 'released_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            'orders' => ['customer_id' => $base, 'minimarket_id' => $id, 'total' => '1.00', 'status' => 'cancelled', 'reservation_expires_at' => null, 'created_at' => $now, 'updated_at' => $now],
            'deliveries' => ['order_id' => $base, 'customer_id' => $base, 'minimarket_id' => $id, 'courier_id' => null, 'status' => 'delivered', 'created_at' => $now, 'updated_at' => $now],
        };
        restStoreSame(1, $wpdb->insert($target, $row), 'No se creo referencia REST.');
        $blocked = restStoreRequest('DELETE', "/veciahorra/v1/stores/{$id}", null, $nonce);
        restStoreSame(409, $blocked->get_status(), 'Referencia ' . $domain . ' no bloqueo DELETE.');
        restStoreSame('store_referenced', $blocked->get_data()['error']['code'] ?? null, 'Codigo referencial incorrecto.');
        restStoreSame([$domain], $blocked->get_data()['error']['data']['domains'] ?? null, 'Dominio referencial no expuesto.');
        restStoreSame([$domain => 1], $blocked->get_data()['error']['data']['counts'] ?? null, 'Cantidad referencial incorrecta.');
        restStoreSame(false, str_contains((string) wp_json_encode($blocked->get_data()), (string) $base), 'DELETE expuso ID de relacion.');
    }

    wp_set_current_user(0);
    restStoreSame(401, restStoreRequest('GET', "/veciahorra/v1/stores/{$stateIds['draft']}", null, $nonce)->get_status(), 'Anonimo no fue rechazado.');
    wp_set_current_user((int) $subscriberId);
    restStoreSame(403, restStoreRequest('GET', "/veciahorra/v1/stores/{$stateIds['draft']}", null, wp_create_nonce('wp_rest'))->get_status(), 'Usuario sin capability no fue rechazado.');
    wp_set_current_user((int) $admins[0]);
    restStoreSame(403, restStoreRequest('GET', "/veciahorra/v1/stores/{$stateIds['draft']}")->get_status(), 'Nonce ausente fue aceptado.');
    restStoreSame(403, restStoreRequest('GET', "/veciahorra/v1/stores/{$stateIds['draft']}", null, 'invalid')->get_status(), 'Nonce invalido fue aceptado.');
    restStoreSame(200, restStoreRequest('GET', "/veciahorra/v1/stores/{$stateIds['draft']}", null, $nonce)->get_status(), 'Nonce valido fue rechazado.');

    $directId = restStoreFixture($stores, 'direct-' . uniqid());
    $makeRoutes = static fn (StoreTransitionRepositoryInterface $repository): StoreRoutes => new StoreRoutes(
        new StoreAdminReadController($stores, new StoreTransitionService($repository), $contract)
    );
    $request = new WP_REST_Request('POST', "/veciahorra/v1/stores/{$directId}/transitions");
    $request->set_url_params(['id' => (string) $directId]);
    $request->set_header('content-type', 'application/json');
    $request->set_body('{"action":"submit_for_review"}');

    $mutating = new RestMutatingTransitionRepository(new StoreRepository(), static function (int $id) use ($wpdb, $table): void {
        restStoreSame(1, $wpdb->update($table, ['onboarding_status' => 'complete'], ['id' => $id]), 'No se simulo concurrencia REST.');
    });
    $conflict = $makeRoutes($mutating)->transition($request);
    restStoreSame(409, $conflict->get_status(), 'Concurrencia REST no devolvio 409.');
    restStoreSame('concurrent_modification', $conflict->get_data()['error']['data']['reason'] ?? null, 'Motivo concurrente incorrecto.');
    restStoreSame(1, $mutating->writes, 'REST reintento el CAS.');
    restStoreSame('complete', $stores->find($directId)?->onboarding_status, 'REST sobrescribio cambio concurrente.');

    $deletedConcurrentId = restStoreFixture($stores, 'deleted-concurrent-' . uniqid());
    $deletedRequest = clone $request;
    $deletedRequest->set_url_params(['id' => (string) $deletedConcurrentId]);
    $deleting = new RestMutatingTransitionRepository(new StoreRepository(), static function (int $id) use ($wpdb, $table): void {
        restStoreSame(1, $wpdb->delete($table, ['id' => $id]), 'No se simulo eliminacion REST concurrente.');
    });
    restStoreSame(404, $makeRoutes($deleting)->transition($deletedRequest)->get_status(), 'Eliminacion concurrente REST no devolvio 404.');

    $failureId = restStoreFixture($stores, 'failure-' . uniqid());
    $failureRequest = clone $request;
    $failureRequest->set_url_params(['id' => (string) $failureId]);
    $failure = $makeRoutes(new RestFailingTransitionRepository(new StoreRepository()))->transition($failureRequest);
    restStoreSame(500, $failure->get_status(), 'Persistence failure no devolvio 500.');
    $encodedFailure = (string) wp_json_encode($failure->get_data());
    restStoreSame(false, str_contains($encodedFailure, 'SQL sensible'), 'REST expuso la excepcion previa.');
    restStoreSame(false, str_contains($encodedFailure, 'trace'), 'REST expuso stack trace.');

    $list = restStoreRequest('GET', '/veciahorra/v1/stores', null, $nonce);
    restStoreSame(200, $list->get_status(), 'Listado existente cambio contrato de acceso.');
    restStoreSame(['id', 'name', 'status', 'onboarding_status', 'approved_at', 'location'], array_keys($list->get_data()['data'][0] ?? []), 'DTO del listado fue ampliado.');

    $container = (new VeciAhorra\Core\Application())->container();
    restStoreSame(true, $container->make(StoreTransitionService::class) instanceof StoreTransitionService, 'Contenedor no resuelve StoreTransitionService.');
} finally {
    $wpdb->query('ROLLBACK');
    if (is_int($subscriberId) && $subscriberId > 0) {
        wp_delete_user($subscriberId);
    }
}

echo "PASS: REST administrativo de detalle y transiciones Store.\n";
