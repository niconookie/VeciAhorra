<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Controllers;

use VeciAhorra\Modules\ProductCatalogs\Services\UnitService;

final class UnitController extends CatalogController
{
    public function __construct(UnitService $service)
    {
        parent::__construct($service);
    }
}
