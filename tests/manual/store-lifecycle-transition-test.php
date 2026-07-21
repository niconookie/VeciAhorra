<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Model;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Contracts\StoreTransitionRepositoryInterface;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract as Lifecycle;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;
use VeciAhorra\Modules\Stores\Services\StoreService;
use VeciAhorra\Modules\Stores\Services\StoreTransitionService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function transitionSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(
            "\nEsperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function transitionAuthorities(Model $store): array
{
    return [
        'status' => $store->status,
        'onboarding_status' => $store->onboarding_status,
        'approved_at' => $store->approved_at,
    ];
}

function transitionStore(StoreService $stores, string $suffix): int
{
    $now = current_time('mysql');
    return $stores->create([
        'business_name' => 'Transition ' . $suffix,
        'legal_name' => 'Transition Legal',
        'owner_name' => 'Transition Owner',
        'rut' => '55.555.555-5',
        'email' => $suffix . '@example.test',
        'phone' => '+56210000333',
        'mobile' => null,
        'address' => null,
        'commune' => null,
        'city' => null,
        'region' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function transitionForbidden(callable $operation, string $action): void
{
    try {
        $operation();
    } catch (StoreLifecycleException $exception) {
        transitionSame('action_not_allowed', $exception->reason(), 'Motivo de transicion prohibida incorrecto.');
        transitionSame($action, $exception->action(), 'Accion prohibida no quedo estructurada.');
        return;
    }
    throw new RuntimeException('La transicion prohibida fue aceptada: ' . $action);
}

final class ConcurrentStoreTransitionRepository implements StoreTransitionRepositoryInterface
{
    private bool $mutated = false;
    public int $writes = 0;
    public ?array $lastTarget = null;

    public function __construct(
        private StoreRepository $inner,
        private Closure $mutation
    ) {
    }

    public function find(int $id): ?Model
    {
        return $this->inner->find($id);
    }

    public function compareAndSetLifecycle(int $id, array $expected, array $target, string $updatedAt): int
    {
        $this->writes++;
        $this->lastTarget = $target;
        if (! $this->mutated) {
            $this->mutated = true;
            ($this->mutation)($id);
        }
        return $this->inner->compareAndSetLifecycle($id, $expected, $target, $updatedAt);
    }
}

final class FailingStoreTransitionRepository implements StoreTransitionRepositoryInterface
{
    public int $writes = 0;

    public function __construct(private StoreRepository $inner)
    {
    }

    public function find(int $id): ?Model
    {
        return $this->inner->find($id);
    }

    public function compareAndSetLifecycle(int $id, array $expected, array $target, string $updatedAt): int
    {
        $this->writes++;
        throw new PersistenceException('Fallo tecnico controlado de prueba.');
    }
}

global $wpdb;
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
$started = $wpdb->query('START TRANSACTION');
transitionSame(true, $started !== false, 'No se inicio la transaccion.');

$stores = new StoreService();
$transitions = new StoreTransitionService();
$contract = new Lifecycle();
$ids = [];

$publicOperations = array_values(array_filter(
    array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(StoreTransitionService::class))->getMethods(ReflectionMethod::IS_PUBLIC)
    ),
    static fn (string $method): bool => $method !== '__construct'
));
sort($publicOperations);
transitionSame(
    ['activate', 'approve', 'deactivate', 'reject', 'returnToDraft', 'submitForReview'],
    $publicOperations,
    'El servicio expone operaciones publicas fuera del contrato.'
);

try {
    $mainId = transitionStore($stores, 'main-' . uniqid());
    $ids[] = $mainId;
    $review = $transitions->submitForReview($mainId);
    transitionSame(Lifecycle::STATE_IN_REVIEW, $contract->validate(...array_values(transitionAuthorities($review))), 'draft -> in_review fallo.');

    $beforeApproval = time();
    $approved = $transitions->approve($mainId);
    $afterApproval = time();
    transitionSame(Lifecycle::STATE_APPROVED_INACTIVE, $contract->validate(...array_values(transitionAuthorities($approved))), 'in_review -> approved_inactive fallo.');
    transitionSame(true, is_string($approved->approved_at) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $approved->approved_at) === 1, 'approved_at no usa el formato durable.');
    $approvalDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        (string) $approved->approved_at,
        wp_timezone()
    );
    $approvalEpoch = $approvalDate === false ? false : $approvalDate->getTimestamp();
    transitionSame(true, $approvalEpoch !== false && $approvalEpoch >= $beforeApproval - 5 && $approvalEpoch <= $afterApproval + 5, 'approved_at quedo fuera del rango razonable.');
    $approval = $approved->approved_at;

    $active = $transitions->activate($mainId);
    transitionSame(Lifecycle::STATE_ACTIVE, $contract->validate(...array_values(transitionAuthorities($active))), 'approved_inactive -> active fallo.');
    transitionSame($approval, $active->approved_at, 'Activar cambio approved_at.');
    $inactive = $transitions->deactivate($mainId);
    transitionSame(Lifecycle::STATE_APPROVED_INACTIVE, $contract->validate(...array_values(transitionAuthorities($inactive))), 'active -> approved_inactive fallo.');
    transitionSame($approval, $inactive->approved_at, 'Inactivar cambio approved_at.');
    $reactivated = $transitions->activate($mainId);
    transitionSame(Lifecycle::STATE_ACTIVE, $contract->validate(...array_values(transitionAuthorities($reactivated))), 'La reactivacion fallo.');
    transitionSame($approval, $reactivated->approved_at, 'Reactivar cambio approved_at.');

    $atomicApprovalId = transitionStore($stores, 'atomic-approval-' . uniqid());
    $ids[] = $atomicApprovalId;
    $transitions->submitForReview($atomicApprovalId);
    $recordingRepository = new ConcurrentStoreTransitionRepository(
        new StoreRepository(),
        static function (int $id): void {
        }
    );
    $atomicApproval = (new StoreTransitionService($recordingRepository))->approve($atomicApprovalId);
    transitionSame(1, $recordingRepository->writes, 'Aprobar no uso una unica escritura CAS.');
    transitionSame('inactive', $recordingRepository->lastTarget['status'] ?? null, 'Aprobar no escribio status conjuntamente.');
    transitionSame('complete', $recordingRepository->lastTarget['onboarding_status'] ?? null, 'Aprobar no escribio onboarding conjuntamente.');
    transitionSame($atomicApproval->approved_at, $recordingRepository->lastTarget['approved_at'] ?? null, 'Aprobar no escribio approved_at conjuntamente.');

    $rejectedId = transitionStore($stores, 'rejected-' . uniqid());
    $ids[] = $rejectedId;
    $transitions->submitForReview($rejectedId);
    $rejected = $transitions->reject($rejectedId);
    transitionSame(Lifecycle::STATE_REJECTED, $contract->validate(...array_values(transitionAuthorities($rejected))), 'in_review -> rejected fallo.');
    transitionSame(null, $rejected->approved_at, 'Rechazar creo aprobacion.');
    $draftAgain = $transitions->returnToDraft($rejectedId);
    transitionSame(Lifecycle::STATE_DRAFT, $contract->validate(...array_values(transitionAuthorities($draftAgain))), 'rejected -> draft fallo.');
    transitionSame(null, $draftAgain->approved_at, 'Volver a borrador creo aprobacion.');

    $draftId = transitionStore($stores, 'draft-' . uniqid());
    $ids[] = $draftId;
    transitionForbidden(fn () => $transitions->approve($draftId), Lifecycle::ACTION_APPROVE);
    transitionForbidden(fn () => $transitions->activate($draftId), Lifecycle::ACTION_ACTIVATE);

    $reviewId = transitionStore($stores, 'review-' . uniqid());
    $ids[] = $reviewId;
    $transitions->submitForReview($reviewId);
    transitionForbidden(fn () => $transitions->activate($reviewId), Lifecycle::ACTION_ACTIVATE);

    $rejectedBlockedId = transitionStore($stores, 'rejected-blocked-' . uniqid());
    $ids[] = $rejectedBlockedId;
    $transitions->submitForReview($rejectedBlockedId);
    $transitions->reject($rejectedBlockedId);
    transitionForbidden(fn () => $transitions->approve($rejectedBlockedId), Lifecycle::ACTION_APPROVE);
    transitionForbidden(fn () => $transitions->activate($rejectedBlockedId), Lifecycle::ACTION_ACTIVATE);

    $approvedId = transitionStore($stores, 'approved-blocked-' . uniqid());
    $ids[] = $approvedId;
    $transitions->submitForReview($approvedId);
    $transitions->approve($approvedId);
    transitionForbidden(fn () => $transitions->reject($approvedId), Lifecycle::ACTION_REJECT);
    transitionForbidden(fn () => $transitions->returnToDraft($approvedId), Lifecycle::ACTION_RETURN_TO_DRAFT);

    transitionForbidden(fn () => $transitions->approve($mainId), Lifecycle::ACTION_APPROVE);
    transitionForbidden(fn () => $transitions->reject($mainId), Lifecycle::ACTION_REJECT);
    transitionSame(false, method_exists($transitions, 'delete'), 'El servicio expone eliminacion fisica.');

    $invalidId = transitionStore($stores, 'invalid-' . uniqid());
    $ids[] = $invalidId;
    transitionSame(1, $wpdb->update($table, ['status' => 'active'], ['id' => $invalidId]), 'No se preparo combinacion invalida.');
    $invalidBefore = transitionAuthorities($stores->find($invalidId));
    try {
        $transitions->deactivate($invalidId);
        throw new RuntimeException('Se transiciono una combinacion invalida.');
    } catch (StoreLifecycleException $exception) {
        transitionSame('invalid_combination', $exception->reason(), 'Combinacion invalida produjo otro error.');
    }
    transitionSame($invalidBefore, transitionAuthorities($stores->find($invalidId)), 'La transicion invalida dejo escritura parcial.');

    $concurrentId = transitionStore($stores, 'concurrent-' . uniqid());
    $ids[] = $concurrentId;
    $repository = new StoreRepository();
    $concurrentRepository = new ConcurrentStoreTransitionRepository(
        $repository,
        static function (int $id) use ($wpdb, $table): void {
            transitionSame(1, $wpdb->update($table, [
                'status' => 'rejected',
                'onboarding_status' => 'complete',
                'approved_at' => null,
            ], ['id' => $id]), 'No se aplico la modificacion concurrente.');
        }
    );
    try {
        (new StoreTransitionService($concurrentRepository))->submitForReview($concurrentId);
        throw new RuntimeException('El snapshot obsoleto no produjo conflicto.');
    } catch (StoreLifecycleException $exception) {
        transitionSame('concurrent_modification', $exception->reason(), 'El conflicto concurrente no fue distinguido.');
        transitionSame(Lifecycle::ACTION_SUBMIT_FOR_REVIEW, $exception->action(), 'El conflicto no incluyo la accion.');
    }
    transitionSame('rejected', $stores->find($concurrentId)?->status, 'El CAS sobrescribio el cambio concurrente.');
    transitionSame(1, $concurrentRepository->writes, 'El conflicto CAS fue reintentado.');

    $deletedConcurrentlyId = transitionStore($stores, 'deleted-concurrently-' . uniqid());
    $ids[] = $deletedConcurrentlyId;
    $deletingRepository = new ConcurrentStoreTransitionRepository(
        new StoreRepository(),
        static function (int $id) use ($wpdb, $table): void {
            transitionSame(1, $wpdb->delete($table, ['id' => $id]), 'No se simulo la eliminacion concurrente.');
        }
    );
    try {
        (new StoreTransitionService($deletingRepository))->submitForReview($deletedConcurrentlyId);
        throw new RuntimeException('La eliminacion concurrente no produjo error.');
    } catch (StoreLifecycleException $exception) {
        transitionSame('store_not_found', $exception->reason(), 'La eliminacion concurrente se interpreto como conflicto.');
    }
    transitionSame(1, $deletingRepository->writes, 'La eliminacion concurrente fue reintentada.');

    $persistenceId = transitionStore($stores, 'persistence-' . uniqid());
    $ids[] = $persistenceId;
    $persistenceBefore = transitionAuthorities($stores->find($persistenceId));
    $failingRepository = new FailingStoreTransitionRepository(new StoreRepository());
    try {
        (new StoreTransitionService($failingRepository))->submitForReview($persistenceId);
        throw new RuntimeException('El fallo de persistencia no produjo error.');
    } catch (StoreLifecycleException $exception) {
        transitionSame('persistence_failure', $exception->reason(), 'El fallo tecnico se confundio con concurrencia.');
        transitionSame(true, $exception->getPrevious() instanceof PersistenceException, 'No se conservo la excepcion tecnica previa.');
    }
    transitionSame(1, $failingRepository->writes, 'El fallo de persistencia fue reintentado.');
    transitionSame($persistenceBefore, transitionAuthorities($stores->find($persistenceId)), 'El fallo de persistencia dejo autoridades parciales.');

    try {
        $transitions->submitForReview(PHP_INT_MAX);
        throw new RuntimeException('Store inexistente no produjo error.');
    } catch (StoreLifecycleException $exception) {
        transitionSame('store_not_found', $exception->reason(), 'Store inexistente se interpreto como conflicto.');
    }
} finally {
    transitionSame(0, $wpdb->query('ROLLBACK'), 'El rollback de transiciones fallo.');
}

foreach ($ids as $id) {
    transitionSame(null, $stores->find($id), 'El rollback dejo una Store de prueba.');
}

echo "PASS: transiciones atomicas y concurrencia Store.\n";
