<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Routes;

use VeciAhorra\Modules\ProductCatalogs\Controllers\UnitController;

final class UnitRoutes extends CatalogRoutes
{
    public function __construct(UnitController $controller)
    {
        parent::__construct($controller);
    }

    protected function resource(): string
    {
        return '/units';
    }
}
