<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Controllers;

use VeciAhorra\Modules\ProductCatalogs\Services\BrandService;

final class BrandController extends CatalogController
{
    public function __construct(BrandService $service)
    {
        parent::__construct($service);
    }
}
