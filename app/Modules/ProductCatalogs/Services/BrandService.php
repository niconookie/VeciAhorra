<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Services;

use VeciAhorra\Modules\ProductCatalogs\Repositories\BrandRepository;

final class BrandService extends CatalogService
{
    public function __construct(BrandRepository $repository)
    {
        parent::__construct($repository);
    }
}
