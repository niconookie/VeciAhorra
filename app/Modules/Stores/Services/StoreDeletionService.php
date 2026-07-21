<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Services;

use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Contracts\StoreDeletionRepositoryInterface;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\Stores\Models\Store;
use VeciAhorra\Modules\Stores\Repositories\StoreDeletionRepository;

final class StoreDeletionService
{
    private StoreReferenceInspector $references;

    public function __construct(
        private ?StoreDeletionRepositoryInterface $repository = null,
        private ?StoreLifecycleContract $lifecycle = null
    ) {
        $this->repository ??= new StoreDeletionRepository();
        $this->lifecycle ??= new StoreLifecycleContract();
        $this->references = new StoreReferenceInspector($this->repository);
    }

    public function deleteIfUnreferenced(int $id): void
    {
        $ownsTransaction = false;
        $action = StoreLifecycleContract::ACTION_DELETE_IF_UNREFERENCED;

        try {
            $ownsTransaction = $this->repository->beginSerializable();
            $store = $this->repository->findForUpdate($id);
            if (! $store instanceof Store) {
                throw new StoreLifecycleException('store_not_found', 'El minimarket no existe.', 'id', 'invalid', $action);
            }

            $expected = [
                'status' => (string) $store->status,
                'onboarding_status' => (string) $store->onboarding_status,
                'approved_at' => $store->approved_at,
            ];
            $state = $this->lifecycle->validate(
                $expected['status'],
                $expected['onboarding_status'],
                $expected['approved_at']
            );
            $this->lifecycle->assertActionAllowed(
                $action,
                $expected['status'],
                $expected['onboarding_status'],
                $expected['approved_at']
            );

            $references = $this->references->inspect($id, true);
            if (! $references->isDeletable()) {
                throw new StoreLifecycleException(
                    'store_referenced',
                    'El minimarket posee referencias durables.',
                    null,
                    $state,
                    $action,
                    0,
                    null,
                    $references->domains(),
                    $references->counts()
                );
            }

            $deleted = $this->repository->compareAndDeleteLifecycle($id, $expected);
            if ($deleted > 1) {
                throw new PersistenceException('La eliminacion Store afecto mas de un registro.');
            }
            if ($deleted === 0) {
                $current = $this->repository->find($id);
                if (! $current instanceof Store) {
                    throw new StoreLifecycleException('store_not_found', 'El minimarket no existe.', 'id', 'invalid', $action);
                }
                $currentReferences = $this->references->inspect($id, true);
                if (! $currentReferences->isDeletable()) {
                    throw new StoreLifecycleException(
                        'store_referenced',
                        'El minimarket posee referencias durables.',
                        null,
                        $this->lifecycle->classify((string) $current->status, (string) $current->onboarding_status, $current->approved_at),
                        $action,
                        0,
                        null,
                        $currentReferences->domains(),
                        $currentReferences->counts()
                    );
                }
                throw new StoreLifecycleException('concurrent_modification', 'El minimarket o sus referencias cambiaron concurrentemente.', null, $this->lifecycle->classify((string) $current->status, (string) $current->onboarding_status, $current->approved_at), $action);
            }
            if ($this->repository->find($id) !== null) {
                throw new StoreLifecycleException('persistence_failure', 'No fue posible comprobar la eliminacion Store.', 'id', $state, $action);
            }

            if ($ownsTransaction) {
                $this->repository->commit();
            }
        } catch (StoreLifecycleException $exception) {
            $this->rollBackOwned($ownsTransaction, $action);
            throw $exception;
        } catch (PersistenceException $exception) {
            $this->rollBackOwned($ownsTransaction, $action);
            throw new StoreLifecycleException('persistence_failure', 'No fue posible ejecutar la eliminacion Store.', null, 'invalid', $action, 0, $exception);
        } catch (Throwable $exception) {
            $this->rollBackOwned($ownsTransaction, $action);
            throw new StoreLifecycleException('persistence_failure', 'Fallo inesperado al eliminar la Store.', null, 'invalid', $action, 0, $exception);
        }
    }

    private function rollBackOwned(bool $ownsTransaction, string $action): void
    {
        if (! $ownsTransaction) {
            return;
        }
        try {
            $this->repository->rollBack();
        } catch (PersistenceException $exception) {
            throw new StoreLifecycleException(
                'persistence_failure',
                'No fue posible revertir la eliminacion Store.',
                null,
                'invalid',
                $action,
                0,
                $exception
            );
        }
    }
}
