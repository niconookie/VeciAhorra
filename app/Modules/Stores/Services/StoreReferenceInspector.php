<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Services;

use VeciAhorra\Modules\Stores\Contracts\StoreDeletionRepositoryInterface;
use VeciAhorra\Modules\Stores\Domain\StoreReferenceResult;
use VeciAhorra\Modules\Stores\Repositories\StoreDeletionRepository;

final class StoreReferenceInspector
{
    public function __construct(private ?StoreDeletionRepositoryInterface $repository = null)
    {
        $this->repository ??= new StoreDeletionRepository();
    }

    public function inspect(int $storeId, bool $lock = false): StoreReferenceResult
    {
        return new StoreReferenceResult(
            $this->repository->referenceCounts($storeId, $lock)
        );
    }
}
