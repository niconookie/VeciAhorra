<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Controllers;

use VeciAhorra\Modules\ProductCatalogs\Services\CategoryService;

final class CategoryController extends CatalogController
{
    public function __construct(CategoryService $service)
    {
        parent::__construct($service);
    }
}
