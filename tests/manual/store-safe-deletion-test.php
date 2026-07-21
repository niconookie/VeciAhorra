<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Model;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Contracts\StoreDeletionRepositoryInterface;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Domain\StoreReferenceResult;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\Stores\Repositories\StoreDeletionRepository;
use VeciAhorra\Modules\Stores\Services\StoreDeletionService;
use VeciAhorra\Modules\Stores\Services\StoreService;
use VeciAhorra\Modules\Stores\Services\StoreTransitionService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function deletionSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(
            "\nEsperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function deletionStore(StoreService $stores, string $token): int
{
    $now = current_time('mysql');
    return $stores->create([
        'business_name' => 'Delete ' . $token,
        'legal_name' => 'Delete Legal',
        'owner_name' => 'Delete Owner',
        'rut' => '44.444.444-4',
        'email' => $token . '@example.test',
        'phone' => '+56210000444',
        'mobile' => null,
        'address' => null,
        'commune' => null,
        'city' => null,
        'region' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function deletionReference(string $domain, int $storeId, string $token): int
{
    global $wpdb;
    $table = $wpdb->prefix . Config::TABLE_PREFIX . $domain;
    $now = current_time('mysql');
    $old = gmdate('Y-m-d H:i:s', time() - 86400 * 30);
    $future = gmdate('Y-m-d H:i:s', time() + 3600);
    $baseId = random_int(700000000, 799999999);
    $data = match ($domain) {
        'inventory' => [
            'product_id' => $baseId, 'minimarket_id' => $storeId,
            'price' => '1000.00', 'stock' => 0, 'status' => 'inactive',
            'created_at' => $old, 'updated_at' => $old,
        ],
        'cart_items' => [
            'session_id' => 'old-' . $token, 'user_id' => null,
            'inventory_id' => $baseId, 'product_id' => $baseId,
            'minimarket_id' => $storeId, 'quantity' => 1,
            'unit_price_snapshot' => '1000.00', 'created_at' => $old, 'updated_at' => $old,
        ],
        'reservations' => [
            'order_id' => null, 'inventory_id' => $baseId, 'product_id' => $baseId,
            'minimarket_id' => $storeId, 'quantity' => 1, 'status' => 'expired',
            'reserved_at' => $old, 'expires_at' => $old, 'released_at' => $old,
            'created_at' => $old, 'updated_at' => $old,
        ],
        'orders' => [
            'customer_id' => $baseId, 'minimarket_id' => $storeId,
            'total' => '1000.00', 'status' => 'cancelled',
            'reservation_expires_at' => $future, 'created_at' => $old, 'updated_at' => $now,
        ],
        'deliveries' => [
            'order_id' => $baseId, 'customer_id' => $baseId,
            'minimarket_id' => $storeId, 'courier_id' => null,
            'status' => 'delivered', 'created_at' => $old, 'updated_at' => $now,
        ],
    };
    deletionSame(1, $wpdb->insert($table, $data), 'No se creo referencia ' . $domain . '.');
    return (int) $wpdb->insert_id;
}

final class MutatingDeletionRepository implements StoreDeletionRepositoryInterface
{
    public int $deletes = 0;

    public function __construct(private StoreDeletionRepository $inner, private Closure $mutation)
    {
    }

    public function beginSerializable(): bool { return false; }
    public function commit(): void {}
    public function rollBack(): void {}
    public function find(int $id): ?Model { return $this->inner->find($id); }
    public function findForUpdate(int $id): ?Model { return $this->inner->findForUpdate($id); }
    public function referenceCounts(int $id, bool $lock = false): array { return $this->inner->referenceCounts($id, false); }

    public function compareAndDeleteLifecycle(int $id, array $expected): int
    {
        $this->deletes++;
        ($this->mutation)($id);
        return $this->inner->compareAndDeleteLifecycle($id, $expected);
    }
}

final class FailingDeletionRepository implements StoreDeletionRepositoryInterface
{
    public function __construct(private StoreDeletionRepository $inner) {}
    public function beginSerializable(): bool { return false; }
    public function commit(): void {}
    public function rollBack(): void {}
    public function find(int $id): ?Model { return $this->inner->find($id); }
    public function findForUpdate(int $id): ?Model { return $this->inner->findForUpdate($id); }
    public function referenceCounts(int $id, bool $lock = false): array { return $this->inner->referenceCounts($id, false); }
    public function compareAndDeleteLifecycle(int $id, array $expected): int { throw new PersistenceException('Fallo controlado.'); }
}

final class StageFailingDeletionRepository implements StoreDeletionRepositoryInterface
{
    public int $rollbacks = 0;

    public function __construct(private StoreDeletionRepository $inner, private string $stage) {}
    public function beginSerializable(): bool
    {
        if (in_array($this->stage, ['isolation', 'start'], true)) {
            throw new PersistenceException('Fallo de ' . $this->stage . '.');
        }
        return true;
    }
    public function commit(): void
    {
        if ($this->stage === 'commit') {
            throw new PersistenceException('Fallo de commit.');
        }
    }
    public function rollBack(): void
    {
        $this->rollbacks++;
        if ($this->stage === 'rollback') {
            throw new PersistenceException('Fallo de rollback.');
        }
    }
    public function find(int $id): ?Model { return $this->inner->find($id); }
    public function findForUpdate(int $id): ?Model
    {
        if (in_array($this->stage, ['lock', 'rollback'], true)) {
            throw new PersistenceException('Fallo de lock.');
        }
        return $this->inner->find($id);
    }
    public function referenceCounts(int $id, bool $lock = false): array
    {
        if ($this->stage === 'inspection') {
            throw new PersistenceException('Fallo de inspeccion.');
        }
        return $this->inner->referenceCounts($id, false);
    }
    public function compareAndDeleteLifecycle(int $id, array $expected): int
    {
        if ($this->stage === 'delete') {
            throw new PersistenceException('Fallo de DELETE.');
        }
        if ($this->stage === 'impossible') {
            return 2;
        }
        return $this->inner->compareAndDeleteLifecycle($id, $expected);
    }
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$domains = ['inventory', 'cart_items', 'reservations', 'orders', 'deliveries'];
$stores = new StoreService();
$deletion = new StoreDeletionService();
$storeIds = [];
$referenceIds = array_fill_keys($domains, []);

try {
    try {
        new StoreReferenceResult(['inventory' => -1]);
        throw new RuntimeException('El resumen acepto una cantidad negativa.');
    } catch (InvalidArgumentException) {
    }
    $countsBefore = [];
    foreach ($domains as $domain) {
        $countsBefore[$domain] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}{$domain}");
    }

    $returnedDraftId = deletionStore($stores, 'returned-draft-' . uniqid());
    $storeIds[] = $returnedDraftId;
    deletionSame(1, $wpdb->update($prefix . 'stores', [
        'status' => 'rejected', 'onboarding_status' => 'complete', 'approved_at' => null,
    ], ['id' => $returnedDraftId]), 'No se preparo Store rechazada con historial.');
    $returnedReferenceId = deletionReference('inventory', $returnedDraftId, uniqid());
    $referenceIds['inventory'][] = $returnedReferenceId;
    (new StoreTransitionService())->returnToDraft($returnedDraftId);
    try {
        $deletion->deleteIfUnreferenced($returnedDraftId);
        throw new RuntimeException('Volver a draft habilito borrado con historial.');
    } catch (StoreLifecycleException $exception) {
        deletionSame('store_referenced', $exception->reason(), 'El historial tras returnToDraft no bloqueo borrado.');
    }
    foreach ($domains as $domain) {
        $countsBefore[$domain] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}{$domain}");
    }
    $successId = deletionStore($stores, 'success-' . uniqid());
    $storeIds[] = $successId;
    $stores->delete($successId);
    deletionSame(null, $stores->find($successId), 'StoreService no elimino el draft sin referencias.');
    foreach ($domains as $domain) {
        deletionSame($countsBefore[$domain], (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}{$domain}"), 'La eliminacion modifico ' . $domain . '.');
    }

    $states = [
        'in_review' => ['pending', 'complete', null],
        'rejected' => ['rejected', 'complete', null],
        'approved_inactive' => ['inactive', 'complete', current_time('mysql')],
        'active' => ['active', 'complete', current_time('mysql')],
        'invalid' => ['active', 'draft', null],
    ];
    foreach ($states as $name => [$status, $onboarding, $approval]) {
        $id = deletionStore($stores, $name . '-' . uniqid());
        $storeIds[] = $id;
        deletionSame(1, $wpdb->update($prefix . 'stores', [
            'status' => $status, 'onboarding_status' => $onboarding, 'approved_at' => $approval,
        ], ['id' => $id]), 'No se preparo estado ' . $name . '.');
        try {
            $deletion->deleteIfUnreferenced($id);
            throw new RuntimeException('Se elimino Store en estado ' . $name . '.');
        } catch (StoreLifecycleException $exception) {
            deletionSame($name === 'invalid' ? 'invalid_combination' : 'action_not_allowed', $exception->reason(), 'Error contractual incorrecto para ' . $name . '.');
        }
        deletionSame(true, $stores->find($id) !== null, 'La Store prohibida fue eliminada.');
    }

    foreach ($domains as $domain) {
        $id = deletionStore($stores, $domain . '-' . uniqid());
        $storeIds[] = $id;
        $referenceId = deletionReference($domain, $id, uniqid());
        $referenceIds[$domain][] = $referenceId;
        try {
            $deletion->deleteIfUnreferenced($id);
            throw new RuntimeException('Se elimino Store referenciada por ' . $domain . '.');
        } catch (StoreLifecycleException $exception) {
            deletionSame('store_referenced', $exception->reason(), 'Referencia no produjo store_referenced.');
            deletionSame([$domain], $exception->domains(), 'Dominio bloqueante incorrecto.');
            deletionSame([$domain => 1], $exception->counts(), 'Cantidad bloqueante incorrecta.');
        }
        deletionSame(true, $stores->find($id) !== null, 'Store referenciada fue eliminada.');
        deletionSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}{$domain} WHERE id = %d", $referenceId)), 'La referencia fue limpiada.');
    }

    $multipleId = deletionStore($stores, 'multiple-' . uniqid());
    $storeIds[] = $multipleId;
    foreach (['inventory', 'inventory', 'orders'] as $domain) {
        $referenceIds[$domain][] = deletionReference($domain, $multipleId, uniqid());
    }
    try {
        $deletion->deleteIfUnreferenced($multipleId);
        throw new RuntimeException('Se elimino Store con referencias multiples.');
    } catch (StoreLifecycleException $exception) {
        deletionSame(['inventory', 'orders'], $exception->domains(), 'Dominios multiples inestables.');
        deletionSame(['inventory' => 2, 'orders' => 1], $exception->counts(), 'Cantidades multiples incorrectas.');
    }

    $concurrentId = deletionStore($stores, 'concurrent-' . uniqid());
    $storeIds[] = $concurrentId;
    $mutating = new MutatingDeletionRepository(new StoreDeletionRepository(), static function (int $id) use ($wpdb, $prefix): void {
        deletionSame(1, $wpdb->update($prefix . 'stores', ['onboarding_status' => 'complete'], ['id' => $id]), 'No se simulo cambio concurrente.');
    });
    try {
        (new StoreDeletionService($mutating))->deleteIfUnreferenced($concurrentId);
        throw new RuntimeException('El snapshot obsoleto elimino la Store.');
    } catch (StoreLifecycleException $exception) {
        deletionSame('concurrent_modification', $exception->reason(), 'Cambio concurrente produjo otro error.');
    }
    deletionSame(1, $mutating->deletes, 'El DELETE CAS fue reintentado.');
    deletionSame('complete', $stores->find($concurrentId)?->onboarding_status, 'Se sobrescribio el cambio concurrente.');

    $concurrentDeleteId = deletionStore($stores, 'concurrent-delete-' . uniqid());
    $storeIds[] = $concurrentDeleteId;
    $deleting = new MutatingDeletionRepository(new StoreDeletionRepository(), static function (int $id) use ($wpdb, $prefix): void {
        deletionSame(1, $wpdb->delete($prefix . 'stores', ['id' => $id]), 'No se simulo eliminacion concurrente.');
    });
    try {
        (new StoreDeletionService($deleting))->deleteIfUnreferenced($concurrentDeleteId);
        throw new RuntimeException('Eliminacion concurrente se considero exitosa.');
    } catch (StoreLifecycleException $exception) {
        deletionSame('store_not_found', $exception->reason(), 'Eliminacion concurrente produjo otro error.');
    }
    deletionSame(1, $deleting->deletes, 'La eliminacion concurrente fue reintentada.');

    $concurrentReferenceId = deletionStore($stores, 'concurrent-reference-' . uniqid());
    $storeIds[] = $concurrentReferenceId;
    $newReferenceId = 0;
    $referencing = new MutatingDeletionRepository(new StoreDeletionRepository(), static function (int $id) use (&$newReferenceId, &$referenceIds): void {
        $newReferenceId = deletionReference('cart_items', $id, uniqid());
        $referenceIds['cart_items'][] = $newReferenceId;
    });
    try {
        (new StoreDeletionService($referencing))->deleteIfUnreferenced($concurrentReferenceId);
        throw new RuntimeException('Nueva referencia concurrente no bloqueo DELETE.');
    } catch (StoreLifecycleException $exception) {
        deletionSame('store_referenced', $exception->reason(), 'Nueva referencia concurrente produjo otro error.');
        deletionSame(['cart_items'], $exception->domains(), 'Nueva referencia no se informo estructuradamente.');
    }
    deletionSame(true, $stores->find($concurrentReferenceId) !== null, 'Nueva referencia no preservo Store.');
    deletionSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}cart_items WHERE id = %d", $newReferenceId)), 'Nueva referencia no fue conservada.');

    $failureId = deletionStore($stores, 'failure-' . uniqid());
    $storeIds[] = $failureId;
    try {
        (new StoreDeletionService(new FailingDeletionRepository(new StoreDeletionRepository())))->deleteIfUnreferenced($failureId);
        throw new RuntimeException('Fallo SQL no produjo excepcion.');
    } catch (StoreLifecycleException $exception) {
        deletionSame('persistence_failure', $exception->reason(), 'Fallo SQL se confundio con concurrencia.');
        deletionSame(true, $exception->getPrevious() instanceof PersistenceException, 'No se conservo causa tecnica.');
    }
    deletionSame(true, $stores->find($failureId) !== null, 'Fallo tecnico elimino Store.');

    foreach (['isolation', 'start', 'lock', 'inspection', 'delete', 'impossible', 'rollback'] as $stage) {
        $technicalId = deletionStore($stores, 'technical-' . $stage . '-' . uniqid());
        $storeIds[] = $technicalId;
        $technicalRepository = new StageFailingDeletionRepository(new StoreDeletionRepository(), $stage);
        try {
            (new StoreDeletionService($technicalRepository))->deleteIfUnreferenced($technicalId);
            throw new RuntimeException('Fallo tecnico aceptado en ' . $stage . '.');
        } catch (StoreLifecycleException $exception) {
            deletionSame('persistence_failure', $exception->reason(), 'Fallo ' . $stage . ' se clasifico incorrectamente.');
            deletionSame(true, $exception->getPrevious() instanceof PersistenceException, 'Fallo ' . $stage . ' perdio causa previa.');
        }
        deletionSame(in_array($stage, ['isolation', 'start'], true) ? 0 : 1, $technicalRepository->rollbacks, 'Rollback incorrecto en ' . $stage . '.');
        deletionSame(true, $stores->find($technicalId) !== null, 'Fallo ' . $stage . ' elimino Store.');
    }

    $bypassId = deletionStore($stores, 'bypass-' . uniqid());
    $storeIds[] = $bypassId;
    foreach ([new \VeciAhorra\Modules\Stores\Repositories\StoreRepository(), new StoreDeletionRepository()] as $unsafeRepository) {
        try {
            $unsafeRepository->delete($bypassId);
            throw new RuntimeException('Un repositorio conservo delete(id) inseguro.');
        } catch (PersistenceException) {
        }
    }
    deletionSame(true, $stores->find($bypassId) !== null, 'El bypass de repositorio elimino Store.');

    deletionSame(false, $wpdb->query('START TRANSACTION') === false, 'No se inicio transaccion externa.');
    $externalId = deletionStore($stores, 'external-success-' . uniqid());
    $storeIds[] = $externalId;
    $deletion->deleteIfUnreferenced($externalId);
    deletionSame(1, (int) $wpdb->get_var('SELECT @@session.in_transaction'), 'La politica hizo commit de transaccion ajena.');
    deletionSame(0, $wpdb->query('ROLLBACK'), 'No se revirtio transaccion externa.');
    deletionSame(null, $stores->find($externalId), 'Rollback externo dejo fixture.');

    deletionSame(false, $wpdb->query('START TRANSACTION') === false, 'No se inicio transaccion externa fallida.');
    $externalFailureId = deletionStore($stores, 'external-failure-' . uniqid());
    $storeIds[] = $externalFailureId;
    $externalReferenceId = deletionReference('inventory', $externalFailureId, uniqid());
    $referenceIds['inventory'][] = $externalReferenceId;
    try {
        $deletion->deleteIfUnreferenced($externalFailureId);
        throw new RuntimeException('Referencia en transaccion externa fue ignorada.');
    } catch (StoreLifecycleException $exception) {
        deletionSame('store_referenced', $exception->reason(), 'Fallo externo se clasifico incorrectamente.');
    }
    deletionSame(1, (int) $wpdb->get_var('SELECT @@session.in_transaction'), 'La politica hizo rollback de transaccion ajena.');
    deletionSame(0, $wpdb->query('ROLLBACK'), 'No se limpio transaccion externa fallida.');

    try {
        $deletion->deleteIfUnreferenced(PHP_INT_MAX);
        throw new RuntimeException('Store inexistente no produjo error.');
    } catch (StoreLifecycleException $exception) {
        deletionSame('store_not_found', $exception->reason(), 'Store inexistente se confundio con concurrencia.');
    }
} finally {
    foreach (array_reverse($domains) as $domain) {
        foreach ($referenceIds[$domain] as $id) {
            $wpdb->delete($prefix . $domain, ['id' => $id]);
        }
    }
    foreach ($storeIds as $id) {
        $wpdb->delete($prefix . 'stores', ['id' => $id]);
    }
}

foreach ($domains as $domain) {
    foreach ($referenceIds[$domain] as $id) {
        deletionSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}{$domain} WHERE id = %d", $id)), 'Quedo fixture ' . $domain . '.');
    }
}
foreach ($storeIds as $id) {
    deletionSame(null, $stores->find($id), 'Quedo fixture Store.');
}
deletionSame(0, (int) $wpdb->get_var('SELECT @@session.in_transaction'), 'Quedo una transaccion abierta.');

echo "PASS: integridad referencial y eliminacion segura Store.\n";
