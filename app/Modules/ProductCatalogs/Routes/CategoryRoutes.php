<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Routes;

use VeciAhorra\Modules\ProductCatalogs\Controllers\CategoryController;

final class CategoryRoutes extends CatalogRoutes
{
    public function __construct(CategoryController $controller)
    {
        parent::__construct($controller);
    }

    protected function resource(): string
    {
        return '/categories';
    }
}
