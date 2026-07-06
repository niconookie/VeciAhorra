<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Controllers;

use Throwable;
use VeciAhorra\Modules\ProductCatalogs\Services\CatalogService;

abstract class CatalogController
{
    public function __construct(
        private CatalogService $service
    ) {
    }

    /**
     * Devuelve una respuesta neutral para el adaptador REST.
     *
     * @return array<string, mixed>
     */
    final public function index(): array
    {
        try {
            return [
                'success' => true,
                'data' => $this->service->all(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'catalog_unavailable',
                    'message' => 'No fue posible cargar el catálogo.',
                ],
            ];
        }
    }
}
