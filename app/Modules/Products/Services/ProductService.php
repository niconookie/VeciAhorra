<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Services;

use InvalidArgumentException;
use VeciAhorra\Database\Collection;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;

/**
 * Servicio del catálogo maestro de Productos.
 */
final class ProductService
{
    private const SLUG_MAX_LENGTH = 200;

    private const SLUG_MAX_ATTEMPTS = 10000;

    private ProductRepository $repository;

    public function __construct()
    {
        $this->repository = new ProductRepository();
    }

    /**
     * Crea un producto.
     */
    public function create(array $data): int
    {
        $payload = $this->buildCreatePayload($data);

        if (trim($payload['name']) === '') {
            throw new InvalidArgumentException(
                'El nombre del producto es obligatorio.'
            );
        }

        $this->assertUniqueSku($payload['sku']);
        $this->assertUniqueWooProductId(
            $payload['woo_product_id']
        );

        $payload['slug'] = $this->generateUniqueSlug(
            $payload['name']
        );
        $payload['status'] = Product::STATUS_DRAFT;
        $now = current_time('mysql');
        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;

        return $this->repository->create($payload);
    }

    /**
     * Actualiza un producto.
     */
    public function update(int $id, array $data): void
    {
        $product = $this->requireProduct($id);
        $payload = $this->buildUpdatePayload($data);

        if (array_key_exists('sku', $payload)) {
            $this->assertUniqueSku(
                $payload['sku'],
                $id
            );
        }

        if (array_key_exists('woo_product_id', $payload)) {
            $this->assertUniqueWooProductId(
                $payload['woo_product_id'],
                $id
            );
        }

        if (
            array_key_exists('name', $payload)
            && $payload['name'] !== $product->name
        ) {
            $payload['slug'] = $this->generateUniqueSlug(
                $payload['name'],
                $id
            );
        }

        $payload['updated_at'] = current_time('mysql');

        $this->repository->update(
            $id,
            $payload
        );
    }

    /**
     * Obtiene un producto por ID.
     */
    public function find(int $id): ?Product
    {
        return $this->repository->findById($id);
    }

    /**
     * Busca productos.
     */
    public function search(?string $term): Collection
    {
        return $this->repository->search($term);
    }

    /**
     * Obtiene una página de resultados.
     */
    public function paginate(
        int $page,
        int $perPage,
        ?string $term = null,
        ?string $status = null,
        string $orderBy = 'id',
        string $direction = 'DESC'
    ): Collection {
        if ($status !== null) {
            $this->assertAllowedStatus($status);
        }

        return $this->repository->paginate(
            $page,
            $perPage,
            $term,
            $status,
            $orderBy,
            $direction
        );
    }

    /**
     * Cuenta productos.
     */
    public function count(
        ?string $term = null,
        ?string $status = null
    ): int {
        if ($status !== null) {
            $this->assertAllowedStatus($status);
        }

        return $this->repository->count(
            $term,
            $status
        );
    }

    /**
     * Actualiza el estado de un producto.
     */
    public function updateStatus(
        int $id,
        string $status
    ): void {
        $this->assertAllowedStatus($status);

        $product = $this->requireProduct($id);

        if ($product->status === $status) {
            return;
        }

        $this->repository->updateStatus(
            $id,
            $status,
            current_time('mysql')
        );
    }

    /**
     * Desactiva un producto sin eliminarlo físicamente.
     */
    public function deactivate(int $id): void
    {
        $this->updateStatus(
            $id,
            Product::STATUS_INACTIVE
        );
    }

    /**
     * Obtiene un producto que debe existir.
     */
    private function requireProduct(int $id): Product
    {
        $product = $this->repository->findById($id);

        if ($product === null) {
            throw new RecordNotFoundException(
                'El producto solicitado no existe.'
            );
        }

        return $product;
    }

    /**
     * Genera un slug único para el producto.
     */
    private function generateUniqueSlug(
        string $name,
        ?int $excludeId = null
    ): string {
        $baseSlug = sanitize_title($name);

        if ($baseSlug === '') {
            throw new InvalidArgumentException(
                'No fue posible generar un slug válido para el producto.'
            );
        }

        $baseSlug = function_exists('mb_substr')
            ? mb_substr($baseSlug, 0, self::SLUG_MAX_LENGTH)
            : substr($baseSlug, 0, self::SLUG_MAX_LENGTH);

        $baseSlug = rtrim($baseSlug, '-');
        $candidate = $baseSlug;

        if (! $this->repository->existsBySlug(
            $candidate,
            $excludeId
        )) {
            return $candidate;
        }

        for (
            $suffix = 2;
            $suffix <= self::SLUG_MAX_ATTEMPTS;
            $suffix++
        ) {
            $suffixText = '-' . $suffix;
            $suffixLength = function_exists('mb_strlen')
                ? mb_strlen($suffixText)
                : strlen($suffixText);
            $maximumBaseLength =
                self::SLUG_MAX_LENGTH - $suffixLength;

            $truncatedBase = function_exists('mb_substr')
                ? mb_substr($baseSlug, 0, $maximumBaseLength)
                : substr($baseSlug, 0, $maximumBaseLength);

            $candidate = rtrim($truncatedBase, '-')
                . $suffixText;

            if (! $this->repository->existsBySlug(
                $candidate,
                $excludeId
            )) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(
            'No fue posible generar un slug único para el producto.'
        );
    }

    /**
     * Comprueba la unicidad del SKU.
     */
    private function assertUniqueSku(
        ?string $sku,
        ?int $excludeId = null
    ): void {
        if ($sku === null) {
            return;
        }

        if ($this->repository->existsBySku($sku, $excludeId)) {
            throw new InvalidArgumentException(
                'Ya existe un producto con el SKU indicado.'
            );
        }
    }

    /**
     * Comprueba la unicidad de la referencia de WooCommerce.
     */
    private function assertUniqueWooProductId(
        ?int $wooProductId,
        ?int $excludeId = null
    ): void {
        if ($wooProductId === null) {
            return;
        }

        if ($this->repository->existsByWooProductId(
            $wooProductId,
            $excludeId
        )) {
            throw new InvalidArgumentException(
                'Ya existe un producto con la referencia de WooCommerce indicada.'
            );
        }
    }

    /**
     * Comprueba que el estado pertenezca al catálogo permitido.
     */
    private function assertAllowedStatus(string $status): void
    {
        if (! in_array(
            $status,
            Product::allowedStatuses(),
            true
        )) {
            throw new InvalidArgumentException(
                'El estado del producto no es válido.'
            );
        }
    }

    /**
     * Construye los datos persistibles de creación.
     */
    private function buildCreatePayload(array $data): array
    {
        $sku = isset($data['sku'])
            ? trim((string) $data['sku'])
            : null;

        if ($sku === '') {
            $sku = null;
        }

        $wooProductId = $data['woo_product_id'] ?? null;

        if (
            $wooProductId === null
            || (
                is_string($wooProductId)
                && trim($wooProductId) === ''
            )
        ) {
            $wooProductId = null;
        } else {
            $wooProductId = (int) $wooProductId;
        }

        return [
            'woo_product_id' => $wooProductId,
            'name' => $data['name'] ?? '',
            'sku' => $sku,
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'image_id' => $data['image_id'] ?? null,
        ];
    }

    /**
     * Construye los datos persistibles de actualización.
     */
    private function buildUpdatePayload(array $data): array
    {
        $allowedFields = [
            'woo_product_id',
            'name',
            'sku',
            'description',
            'category_id',
            'brand_id',
            'unit_id',
            'image_id',
        ];

        $payload = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('sku', $payload)) {
            $sku = $payload['sku'] === null
                ? ''
                : trim((string) $payload['sku']);

            $payload['sku'] = $sku === ''
                ? null
                : $sku;
        }

        if (array_key_exists('woo_product_id', $payload)) {
            $wooProductId = $payload['woo_product_id'];

            if (
                $wooProductId === null
                || (
                    is_string($wooProductId)
                    && trim($wooProductId) === ''
                )
            ) {
                $payload['woo_product_id'] = null;
            } else {
                $payload['woo_product_id'] = (int) $wooProductId;
            }
        }

        return $payload;
    }
}
