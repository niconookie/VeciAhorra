<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Services;

use VeciAhorra\Modules\ProductCatalogs\Repositories\TaxonomyCatalogRepository;

abstract class CatalogService
{
    public function __construct(
        private TaxonomyCatalogRepository $repository
    ) {
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    final public function all(): array
    {
        return $this->repository->all();
    }
}
