<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\CatalogUnavailableException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Catalog\Requests\CatalogListRequest;
use VeciAhorra\Modules\Catalog\Service\CatalogService;

final class CatalogController
{
    public function __construct(private CatalogService $service)
    {
    }

    public function index(array $input): array
    {
        try {
            $result = $this->service->list(
                (new CatalogListRequest($input))->validated()
            );

            return [
                'success' => true,
                'data' => $result['items'],
                'meta' => $result['meta'],
            ];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function show(int $id): array
    {
        try {
            return ['success' => true, 'data' => $this->service->find($id)];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function error(Throwable $exception): array
    {
        if ($exception instanceof RecordNotFoundException) {
            return $this->failure('catalog_product_not_found', $exception->getMessage());
        }

        if ($exception instanceof InvalidArgumentException) {
            return $this->failure('validation_error', $exception->getMessage());
        }

        if ($exception instanceof CatalogUnavailableException) {
            return $this->failure('catalog_unavailable', 'Catalog data is temporarily unavailable.');
        }

        return $this->failure('internal_error', 'An internal error occurred.');
    }

    private function failure(string $code, string $message): array
    {
        return ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
