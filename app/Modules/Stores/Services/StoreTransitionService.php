<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Services;

use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Contracts\StoreTransitionRepositoryInterface;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\Stores\Models\Store;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

final class StoreTransitionService
{
    public function __construct(
        private ?StoreTransitionRepositoryInterface $repository = null,
        private ?StoreLifecycleContract $lifecycle = null
    ) {
        $this->repository ??= new StoreRepository();
        $this->lifecycle ??= new StoreLifecycleContract();
    }

    public function submitForReview(int $id): Store
    {
        return $this->transition($id, StoreLifecycleContract::ACTION_SUBMIT_FOR_REVIEW);
    }

    public function returnToDraft(int $id): Store
    {
        return $this->transition($id, StoreLifecycleContract::ACTION_RETURN_TO_DRAFT);
    }

    public function approve(int $id): Store
    {
        return $this->transition($id, StoreLifecycleContract::ACTION_APPROVE);
    }

    public function reject(int $id): Store
    {
        return $this->transition($id, StoreLifecycleContract::ACTION_REJECT);
    }

    public function activate(int $id): Store
    {
        return $this->transition($id, StoreLifecycleContract::ACTION_ACTIVATE);
    }

    public function deactivate(int $id): Store
    {
        return $this->transition($id, StoreLifecycleContract::ACTION_DEACTIVATE);
    }

    private function transition(int $id, string $action): Store
    {
        try {
            $store = $this->repository->find($id);
            if (! $store instanceof Store) {
                throw new StoreLifecycleException('store_not_found', 'El minimarket no existe.', 'id', 'invalid', $action);
            }

            $expected = [
                'status' => (string) $store->status,
                'onboarding_status' => (string) $store->onboarding_status,
                'approved_at' => $store->approved_at,
            ];
            $target = $this->lifecycle->transitionAuthorities(
                $action,
                $expected['status'],
                $expected['onboarding_status'],
                $expected['approved_at'],
                $action === StoreLifecycleContract::ACTION_APPROVE ? current_time('mysql') : null
            );
            $affected = $this->repository->compareAndSetLifecycle(
                $id,
                $expected,
                $target,
                current_time('mysql')
            );
            if ($affected !== 1) {
                $current = $this->repository->find($id);
                if (! $current instanceof Store) {
                    throw new StoreLifecycleException('store_not_found', 'El minimarket no existe.', 'id', 'invalid', $action);
                }
                throw new StoreLifecycleException('concurrent_modification', 'El minimarket fue modificado concurrentemente.', null, $this->lifecycle->classify((string) $current->status, (string) $current->onboarding_status, $current->approved_at), $action);
            }

            $updated = $this->repository->find($id);
            if (! $updated instanceof Store) {
                throw new StoreLifecycleException('persistence_failure', 'No fue posible comprobar la transicion Store.', 'id', 'invalid', $action);
            }
            $this->lifecycle->validate((string) $updated->status, (string) $updated->onboarding_status, $updated->approved_at);

            return $updated;
        } catch (StoreLifecycleException $exception) {
            throw $exception;
        } catch (PersistenceException $exception) {
            throw new StoreLifecycleException('persistence_failure', 'No fue posible persistir la transicion Store.', null, 'invalid', $action, 0, $exception);
        } catch (Throwable $exception) {
            throw new StoreLifecycleException('persistence_failure', 'Fallo inesperado al ejecutar la transicion Store.', null, 'invalid', $action, 0, $exception);
        }
    }
}
