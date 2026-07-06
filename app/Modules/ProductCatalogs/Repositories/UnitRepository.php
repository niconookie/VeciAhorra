<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Repositories;

final class UnitRepository extends TaxonomyCatalogRepository
{
    protected function taxonomy(): string
    {
        return 'pa_unidad';
    }
}
