<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Services;

use VeciAhorra\Exceptions\CatalogValidationException;
use VeciAhorra\Modules\ProductCatalogs\Repositories\BrandRepository;
use VeciAhorra\Modules\ProductCatalogs\Repositories\CategoryRepository;
use VeciAhorra\Modules\ProductCatalogs\Repositories\TaxonomyCatalogRepository;
use VeciAhorra\Modules\ProductCatalogs\Repositories\UnitRepository;

final class CatalogValidator
{
    public function __construct(
        private CategoryRepository $categories = new CategoryRepository(),
        private BrandRepository $brands = new BrandRepository(),
        private UnitRepository $units = new UnitRepository()
    ) {
    }

    /**
     * Valida solo las relaciones presentes en el payload.
     */
    public function validate(array $data): void
    {
        $this->validateTerm(
            $data,
            'category_id',
            $this->categories,
            'invalid_category_id',
            'La categoría indicada no existe.'
        );
        $this->validateTerm(
            $data,
            'brand_id',
            $this->brands,
            'invalid_brand_id',
            'La marca indicada no existe.'
        );
        $this->validateTerm(
            $data,
            'unit_id',
            $this->units,
            'invalid_unit_id',
            'La unidad indicada no existe.'
        );

        if (
            ! array_key_exists('image_id', $data)
            || $data['image_id'] === null
        ) {
            return;
        }

        $post = get_post((int) $data['image_id']);

        if ($post === null) {
            throw new CatalogValidationException(
                'invalid_image_id',
                'La imagen indicada no existe.'
            );
        }

        if ($post->post_type !== 'attachment') {
            throw new CatalogValidationException(
                'invalid_image_id',
                'El identificador de imagen no corresponde a un attachment.'
            );
        }
    }

    private function validateTerm(
        array $data,
        string $field,
        TaxonomyCatalogRepository $repository,
        string $errorCode,
        string $message
    ): void {
        if (
            ! array_key_exists($field, $data)
            || $data[$field] === null
        ) {
            return;
        }

        if (! $repository->exists((int) $data[$field])) {
            throw new CatalogValidationException(
                $errorCode,
                $message
            );
        }
    }
}
