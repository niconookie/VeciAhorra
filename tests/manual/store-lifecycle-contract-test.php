<?php

declare(strict_types=1);

use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract as Lifecycle;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\Stores\Services\StoreService;
use VeciAhorra\Modules\Stores\Requests\StoreRequest;
use VeciAhorra\Core\Config;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function lifecycleSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function lifecycleInvalid(
    Lifecycle $contract,
    array $combination,
    string $reason,
    ?string $field = null
): void
{
    [$status, $onboarding, $approvedAt] = $combination;
    lifecycleSame(Lifecycle::STATE_INVALID, $contract->classify($status, $onboarding, $approvedAt), 'Clasificacion invalida incorrecta.');
    try {
        $contract->validate($status, $onboarding, $approvedAt);
    } catch (StoreLifecycleException $exception) {
        lifecycleSame($reason, $exception->reason(), 'Motivo de error incorrecto.');
        if ($field !== null) {
            lifecycleSame($field, $exception->field(), 'Campo de error incorrecto.');
        }
        return;
    }
    throw new RuntimeException('La combinacion invalida fue aceptada.');
}

$contract = new Lifecycle();
$approvedAt = '2026-07-21 12:00:00';
$canonical = [
    ['pending', 'draft', null, Lifecycle::STATE_DRAFT, [Lifecycle::ACTION_SAVE, Lifecycle::ACTION_SUBMIT_FOR_REVIEW, Lifecycle::ACTION_DELETE_IF_UNREFERENCED]],
    ['pending', 'complete', null, Lifecycle::STATE_IN_REVIEW, [Lifecycle::ACTION_SAVE, Lifecycle::ACTION_APPROVE, Lifecycle::ACTION_REJECT, Lifecycle::ACTION_RETURN_TO_DRAFT]],
    ['rejected', 'complete', null, Lifecycle::STATE_REJECTED, [Lifecycle::ACTION_SAVE, Lifecycle::ACTION_RETURN_TO_DRAFT]],
    ['inactive', 'complete', $approvedAt, Lifecycle::STATE_APPROVED_INACTIVE, [Lifecycle::ACTION_SAVE, Lifecycle::ACTION_ACTIVATE]],
    ['active', 'complete', $approvedAt, Lifecycle::STATE_ACTIVE, [Lifecycle::ACTION_SAVE, Lifecycle::ACTION_DEACTIVATE]],
];

foreach ($canonical as [$status, $onboarding, $approval, $state, $actions]) {
    lifecycleSame($state, $contract->validate($status, $onboarding, $approval), 'Estado canonico incorrecto.');
    lifecycleSame($actions, $contract->allowedActions($status, $onboarding, $approval), 'Acciones derivadas incorrectas.');
}

lifecycleInvalid($contract, ['active', 'complete', null], 'invalid_combination');
lifecycleInvalid($contract, ['active', 'draft', $approvedAt], 'invalid_combination');
lifecycleInvalid($contract, ['inactive', 'complete', null], 'invalid_combination');
lifecycleInvalid($contract, ['rejected', 'complete', $approvedAt], 'invalid_combination');
lifecycleInvalid($contract, ['rejected', 'draft', null], 'invalid_combination');
lifecycleInvalid($contract, ['pending', 'draft', $approvedAt], 'invalid_combination');
lifecycleInvalid($contract, ['pending', 'complete', $approvedAt], 'invalid_combination');
lifecycleInvalid($contract, ['unknown', 'draft', null], 'unknown_status', 'status');
lifecycleInvalid($contract, ['pending', 'unknown', null], 'unknown_onboarding_status', 'onboarding_status');
lifecycleInvalid($contract, ['active', 'complete', 'not-a-date'], 'invalid_combination');
lifecycleInvalid($contract, ['active', 'complete', false], 'invalid_combination');

try {
    $contract->assertActionAllowed(Lifecycle::ACTION_ACTIVATE, 'rejected', 'complete', null);
    throw new RuntimeException('Se permitio activar una Store rechazada.');
} catch (StoreLifecycleException $exception) {
    lifecycleSame('action_not_allowed', $exception->reason(), 'Motivo de accion prohibida incorrecto.');
    lifecycleSame(Lifecycle::STATE_REJECTED, $exception->state(), 'Estado estructurado incorrecto.');
    lifecycleSame(Lifecycle::ACTION_ACTIVATE, $exception->action(), 'Accion estructurada incorrecta.');
}

foreach ([Lifecycle::ACTION_REJECT, Lifecycle::ACTION_RETURN_TO_DRAFT] as $forbidden) {
    try {
        $contract->assertActionAllowed($forbidden, 'inactive', 'complete', $approvedAt);
        throw new RuntimeException('Una Store aprobada acepto ' . $forbidden . '.');
    } catch (StoreLifecycleException $exception) {
        lifecycleSame('action_not_allowed', $exception->reason(), 'Accion critica produjo un error incorrecto.');
    }
}

$before = ['status' => 'pending', 'onboarding_status' => 'draft', 'approved_at' => null];
$contract->allowedActions(...array_values($before));
lifecycleSame(['status' => 'pending', 'onboarding_status' => 'draft', 'approved_at' => null], $before, 'El contrato modifico los datos recibidos.');

$_POST = [
    '_wpnonce' => wp_create_nonce('veciahorra_store'),
    'business_name' => 'Request lifecycle',
    'legal_name' => 'Request lifecycle legal',
    'rut' => '66.666.666-6',
    'owner_name' => 'Request Owner',
    'email' => 'request-lifecycle@example.test',
    'phone' => '+56210000222',
    'mobile' => '',
    'address' => 'Direccion 123',
    'commune' => 'Santiago',
    'city' => 'Santiago',
    'region' => 'Metropolitana',
    'status' => 'active',
    'onboarding_status' => 'complete',
    'approved_at' => $approvedAt,
];
$_REQUEST = $_POST;
$requestData = (new StoreRequest())->validatedForUpdate();
foreach (['status', 'onboarding_status', 'approved_at'] as $authority) {
    lifecycleSame(false, array_key_exists($authority, $requestData), 'StoreRequest acepto la autoridad ' . $authority . '.');
}

global $wpdb;
if ($wpdb->query('START TRANSACTION') === false) {
    throw new RuntimeException('No fue posible iniciar la transaccion CRUD.');
}

try {
    $now = current_time('mysql');
    $service = new StoreService();
    $id = $service->create([
        'business_name' => 'Lifecycle ' . uniqid(),
        'legal_name' => 'Lifecycle Legal',
        'owner_name' => 'Lifecycle Owner',
        'rut' => '77.777.777-7',
        'email' => uniqid('lifecycle-', true) . '@example.test',
        'phone' => '+56210000111',
        'mobile' => null,
        'address' => null,
        'commune' => null,
        'city' => null,
        'region' => null,
        'status' => 'active',
        'onboarding_status' => 'complete',
        'approved_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $created = $service->find($id);
    lifecycleSame('pending', $created?->status, 'Create no fijo pending.');
    lifecycleSame('draft', $created?->onboarding_status, 'Create no fijo draft.');
    lifecycleSame(null, $created?->approved_at, 'Create no fijo aprobacion nula.');

    $service->update($id, [
        'business_name' => 'Lifecycle editada',
        'status' => 'active',
        'onboarding_status' => 'complete',
        'approved_at' => $now,
        'updated_at' => $now,
    ]);
    $updated = $service->find($id);
    lifecycleSame('Lifecycle editada', $updated?->business_name, 'La edicion de datos fallo.');
    lifecycleSame('pending', $updated?->status, 'Update cambio status.');
    lifecycleSame('draft', $updated?->onboarding_status, 'Update cambio onboarding.');
    lifecycleSame(null, $updated?->approved_at, 'Update cambio approved_at.');

    $table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
    lifecycleSame(1, $wpdb->update($table, [
        'status' => 'inactive',
        'onboarding_status' => 'complete',
        'approved_at' => $now,
    ], ['id' => $id]), 'No fue posible preparar la Store aprobada.');
    lifecycleSame(1, $service->bulkUpdateStatus([$id], 'active'), 'El escritor masivo rechazo una combinacion valida.');

    lifecycleSame(1, $wpdb->update($table, [
        'status' => 'pending',
        'onboarding_status' => 'draft',
        'approved_at' => null,
    ], ['id' => $id]), 'No fue posible preparar el borrador.');
    try {
        $service->bulkUpdateStatus([$id], 'active');
        throw new RuntimeException('El escritor masivo acepto una combinacion invalida.');
    } catch (StoreLifecycleException $exception) {
        lifecycleSame('invalid_combination', $exception->reason(), 'El bypass masivo produjo un error incorrecto.');
    }
    lifecycleSame('pending', $service->find($id)?->status, 'El bulk invalido alcanzo persistencia.');
} finally {
    lifecycleSame(0, $wpdb->query('ROLLBACK'), 'El rollback CRUD fallo.');
}
lifecycleSame(null, $service->find($id), 'El rollback no retiro la escritura de prueba.');

echo "PASS: contrato Store, combinaciones, acciones e invariantes CRUD.\n";
