<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Services;

use VeciAhorra\Modules\ProductCatalogs\Repositories\UnitRepository;

final class UnitService extends CatalogService
{
    public function __construct(UnitRepository $repository)
    {
        parent::__construct($repository);
    }
}
