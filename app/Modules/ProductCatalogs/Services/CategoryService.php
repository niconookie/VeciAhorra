<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Services;

use VeciAhorra\Modules\ProductCatalogs\Repositories\CategoryRepository;

final class CategoryService extends CatalogService
{
    public function __construct(CategoryRepository $repository)
    {
        parent::__construct($repository);
    }
}
