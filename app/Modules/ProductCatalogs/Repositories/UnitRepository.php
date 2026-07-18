<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Repositories;

use VeciAhorra\Modules\ProductCatalogs\UnitTaxonomy;

final class UnitRepository extends TaxonomyCatalogRepository
{
    protected function taxonomy(): string
    {
        return UnitTaxonomy::NAME;
    }
}
