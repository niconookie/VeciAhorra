<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Repositories;

final class CategoryRepository extends TaxonomyCatalogRepository
{
    protected function taxonomy(): string
    {
        return 'product_cat';
    }
}
