<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Routes;

use VeciAhorra\Modules\ProductCatalogs\Controllers\BrandController;

final class BrandRoutes extends CatalogRoutes
{
    public function __construct(BrandController $controller)
    {
        parent::__construct($controller);
    }

    protected function resource(): string
    {
        return '/brands';
    }
}
