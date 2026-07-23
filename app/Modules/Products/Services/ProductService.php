<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Services;

use InvalidArgumentException;
use VeciAhorra\Database\Collection;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Products\Domain\ProductLifecycleContract;
use VeciAhorra\Modules\Products\Domain\ProductReferenceInspection;
use VeciAhorra\Modules\Products\Exceptions\ProductConcurrencyException;
use VeciAhorra\Modules\Products\Exceptions\ProductDeletionException;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Repositories\ProductReferenceInspector;

/**
 * Servicio del catálogo maestro de Productos.
 */
final class ProductService
{
    private const SLUG_MAX_LENGTH = 200;

    private const SLUG_MAX_ATTEMPTS = 10000;

    private ProductRepository $repository;

    private CatalogValidator $catalogValidator;

    private ProductLifecycleContract $lifecycle;

    private ProductReferenceInspector $referenceInspector;

    public function __construct(
        ?CatalogValidator $catalogValidator = null,
        ?ProductReferenceInspector $referenceInspector = null
    ) {
        $this->repository = new ProductRepository();
        $this->catalogValidator = $catalogValidator
            ?? new CatalogValidator();
        $this->lifecycle = new ProductLifecycleContract();
        $this->referenceInspector = $referenceInspector
            ?? new ProductReferenceInspector();
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

        $this->catalogValidator->validate($payload);

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
    public function update(
        int $id,
        array $data,
        string $expectedUpdatedAt
    ): string
    {
        $product = $this->requireProduct($id);
        $this->assertCurrentVersion($product, $expectedUpdatedAt);
        $payload = $this->buildUpdatePayload($data);

        $this->catalogValidator->validate($payload);

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

        $updatedAt = $this->nextUpdatedAt($expectedUpdatedAt);
        $affected = $this->repository->updateCommercial(
            $id,
            $payload,
            $expectedUpdatedAt,
            $updatedAt
        );

        $this->assertCasSucceeded($id, $affected);

        return $updatedAt;
    }

    /**
     * Obtiene un producto por ID.
     */
    public function find(int $id): ?Product
    {
        return $this->repository->findById($id);
    }

    public function inspectReferences(
        int $id
    ): ProductReferenceInspection {
        $this->requireProduct($id);

        return $this->referenceInspector->inspect($id);
    }

    public function delete(
        int $id,
        string $expectedUpdatedAt
    ): ProductReferenceInspection {
        return $this->repository->transaction(function () use (
            $id,
            $expectedUpdatedAt
        ): ProductReferenceInspection {
            $product = $this->repository->findByIdForUpdate($id);

            if ($product === null) {
                throw new RecordNotFoundException(
                    'El producto solicitado no existe.'
                );
            }

            $this->assertCurrentVersion($product, $expectedUpdatedAt);
            $inspection = $this->referenceInspector->inspect($id, true);
            $this->assertDeletable($inspection);
            $affected = $this->repository->deleteSafely(
                $id,
                $expectedUpdatedAt
            );
            $this->assertCasSucceeded($id, $affected);

            return $inspection;
        });
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
        string $direction = 'DESC',
        ?int $categoryId = null,
        ?int $brandId = null
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
            $direction,
            $categoryId,
            $brandId
        );
    }

    /**
     * Cuenta productos.
     */
    public function count(
        ?string $term = null,
        ?string $status = null,
        ?int $categoryId = null,
        ?int $brandId = null
    ): int {
        if ($status !== null) {
            $this->assertAllowedStatus($status);
        }

        return $this->repository->count(
            $term,
            $status,
            $categoryId,
            $brandId
        );
    }

    /**
     * Actualiza el estado de un producto.
     */
    public function updateStatus(
        int $id,
        string $status,
        string $expectedUpdatedAt
    ): string {
        $this->assertAllowedStatus($status);

        $product = $this->requireProduct($id);
        $this->assertCurrentVersion($product, $expectedUpdatedAt);
        $this->lifecycle->assertTransition(
            $product->status,
            $status
        );

        if ($product->status === $status) {
            return $expectedUpdatedAt;
        }

        $updatedAt = $this->nextUpdatedAt($expectedUpdatedAt);
        $affected = $this->repository->updateStatus(
            $id,
            $status,
            $expectedUpdatedAt,
            $updatedAt
        );

        $this->assertCasSucceeded($id, $affected);

        return $updatedAt;
    }

    /**
     * Actualiza masivamente el estado de productos.
     */
    public function bulkUpdateStatus(
        array $ids,
        string $status
    ): int {
        $this->assertBulkIds($ids);
        $this->assertBulkStatus($status);
        $products = [];

        foreach ($ids as $id) {
            $product = $this->requireProduct($id);
            $this->lifecycle->assertTransition(
                $product->status,
                $status
            );
            $products[] = $product;
        }

        $updates = [];

        foreach ($products as $product) {
            if ($product->status === $status) {
                continue;
            }

            $updates[] = [
                'id' => (int) $product->id,
                'status' => $status,
                'expected_updated_at' => $product->updated_at,
                'updated_at' => $this->nextUpdatedAt(
                    $product->updated_at
                ),
            ];
        }

        return $this->repository->updateStatusesAtomically($updates);
    }

    /**
     * Actualiza masivamente la categoria de productos.
     */
    public function bulkUpdateCategory(
        array $ids,
        ?int $categoryId
    ): int {
        $this->assertBulkIds($ids);
        $this->assertNullablePositiveId(
            $categoryId,
            'categoria'
        );
        $this->catalogValidator->validate([
            'category_id' => $categoryId,
        ]);
        return $this->bulkUpdateCommercial(
            $ids,
            'category_id',
            $categoryId
        );
    }

    /**
     * Actualiza masivamente la marca de productos.
     */
    public function bulkUpdateBrand(
        array $ids,
        ?int $brandId
    ): int {
        $this->assertBulkIds($ids);
        $this->assertNullablePositiveId(
            $brandId,
            'marca'
        );
        $this->catalogValidator->validate([
            'brand_id' => $brandId,
        ]);
        return $this->bulkUpdateCommercial(
            $ids,
            'brand_id',
            $brandId
        );
    }

    /**
     * Actualiza masivamente la unidad de productos.
     */
    public function bulkUpdateUnit(
        array $ids,
        ?int $unitId
    ): int {
        $this->assertBulkIds($ids);
        $this->assertNullablePositiveId(
            $unitId,
            'unidad'
        );
        $this->catalogValidator->validate([
            'unit_id' => $unitId,
        ]);
        return $this->bulkUpdateCommercial(
            $ids,
            'unit_id',
            $unitId
        );
    }

    /**
     * Desactiva un producto sin eliminarlo físicamente.
     */
    public function deactivate(int $id): void
    {
        $product = $this->requireProduct($id);
        $this->updateStatus(
            $id,
            Product::STATUS_INACTIVE,
            $product->updated_at
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
     * Comprueba que una operacion masiva reciba productos.
     */
    private function assertBulkIds(array $ids): void
    {
        if ($ids !== []) {
            return;
        }

        throw new InvalidArgumentException(
            'La operacion masiva requiere al menos un producto.'
        );
    }

    /**
     * Comprueba el estado permitido para operaciones masivas.
     */
    private function assertBulkStatus(string $status): void
    {
        if (in_array($status, ['active', 'inactive'], true)) {
            return;
        }

        throw new InvalidArgumentException(
            'El estado masivo debe ser active o inactive.'
        );
    }

    /**
     * Comprueba un identificador relacional nullable.
     */
    private function assertNullablePositiveId(
        ?int $id,
        string $label
    ): void {
        if ($id === null || $id > 0) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'El identificador de %s debe ser positivo o null.',
                $label
            )
        );
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

    private function assertCurrentVersion(
        Product $product,
        string $expectedUpdatedAt
    ): void {
        if ($product->updated_at !== $expectedUpdatedAt) {
            throw new ProductConcurrencyException(
                'El producto fue modificado por otra operacion.'
            );
        }
    }

    private function assertCasSucceeded(int $id, int $affected): void
    {
        if ($affected === 1) {
            return;
        }

        if ($this->repository->findById($id) === null) {
            throw new RecordNotFoundException(
                'El producto solicitado no existe.'
            );
        }

        throw new ProductConcurrencyException(
            'El producto fue modificado por otra operacion.'
        );
    }

    private function nextUpdatedAt(string $expectedUpdatedAt): string
    {
        return $this->repository->nextUpdatedAt($expectedUpdatedAt);
    }

    private function bulkUpdateCommercial(
        array $ids,
        string $field,
        ?int $value
    ): int {
        $updates = [];

        foreach ($ids as $id) {
            $product = $this->requireProduct($id);
            $updates[] = [
                'id' => (int) $product->id,
                'data' => [$field => $value],
                'expected_updated_at' => $product->updated_at,
                'updated_at' => $this->nextUpdatedAt(
                    $product->updated_at
                ),
            ];
        }

        return $this->repository->updateCommercialsAtomically($updates);
    }

    private function assertDeletable(
        ProductReferenceInspection $inspection
    ): void {
        $classification = $inspection->classification();

        if ($classification === ProductReferenceInspection::DELETABLE) {
            return;
        }

        $code = (string) $inspection->reasonCode();
        $message = match ($classification) {
            ProductReferenceInspection::INCONSISTENT =>
                'El producto presenta referencias inconsistentes.',
            ProductReferenceInspection::RETIRE_REQUIRED =>
                'El producto debe retirarse y sus referencias gestionarse por separado.',
            default =>
                'El producto posee referencias historicas u operativas protegidas.',
        };

        throw new ProductDeletionException(
            $code,
            $message,
            $inspection
        );
    }
}
