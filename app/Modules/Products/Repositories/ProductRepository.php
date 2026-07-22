<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Repositories;

use BadMethodCallException;
use InvalidArgumentException;
use Throwable;
use VeciAhorra\Database\BaseRepository;
use VeciAhorra\Database\Collection;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Products\Domain\ProductLifecycleContract;
use VeciAhorra\Modules\Products\Exceptions\ProductConcurrencyException;
use VeciAhorra\Modules\Products\Models\Product;

/**
 * Repositorio de Productos.
 */
final class ProductRepository extends BaseRepository
{
    private const BULK_UPDATE_FIELDS = [
        'category_id',
        'brand_id',
        'unit_id',
    ];

    /**
     * Nombre lógico de la tabla.
     */
    protected string $table = 'products';

    /**
     * Modelo asociado.
     */
    protected function model(): string
    {
        return Product::class;
    }

    /**
     * Impide el update generico: Products exige contratos CAS separados.
     */
    public function update(int $id, array $data): int
    {
        throw new BadMethodCallException(
            'Use updateCommercial() o updateStatus() para Products.'
        );
    }

    /**
     * Impide el delete generico: Products exige inspeccion y CAS.
     */
    public function delete(int $id): int
    {
        throw new BadMethodCallException(
            'Use deleteSafely() para Products.'
        );
    }

    /**
     * Ejecuta una unidad atomica y respeta transacciones externas.
     */
    public function transaction(callable $callback): mixed
    {
        $nested = (int) $this->db()->get_var(
            'SELECT @@in_transaction'
        ) === 1;
        $savepoint = 'va_products_' . substr(hash(
            'sha256',
            (string) microtime(true) . random_int(1, PHP_INT_MAX)
        ), 0, 12);

        if ($nested) {
            if ($this->db()->query("SAVEPOINT {$savepoint}") === false) {
                throw new PersistenceException(
                    'No fue posible crear el savepoint de Products.'
                );
            }
        } elseif ($this->db()->query('START TRANSACTION') === false) {
            throw new PersistenceException(
                'No fue posible iniciar la transaccion de Products.'
            );
        }

        try {
            $result = $callback();
            $statement = $nested
                ? "RELEASE SAVEPOINT {$savepoint}"
                : 'COMMIT';

            if ($this->db()->query($statement) === false) {
                throw new PersistenceException(
                    'No fue posible confirmar la transaccion de Products.'
                );
            }

            return $result;
        } catch (Throwable $exception) {
            $this->db()->query(
                $nested
                    ? "ROLLBACK TO SAVEPOINT {$savepoint}"
                    : 'ROLLBACK'
            );
            throw $exception;
        }
    }

    /**
     * Obtiene una version durable posterior sin adelantar el reloj.
     */
    public function nextUpdatedAt(string $expectedUpdatedAt): string
    {
        $deadline = microtime(true) + 3.0;

        do {
            $now = current_time('mysql');

            if ($now !== '' && strcmp($now, $expectedUpdatedAt) > 0) {
                return $now;
            }

            usleep(25000);
        } while (microtime(true) < $deadline);

        throw new PersistenceException(
            'No fue posible obtener una version durable posterior.'
        );
    }

    /**
     * Busca un producto por ID.
     */
    public function findById(int $id): ?Product
    {
        return $this->find($id);
    }

    public function findByIdForUpdate(int $id): ?Product
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT * FROM %s WHERE id = %%d FOR UPDATE',
                    $this->table($this->table)
                ),
                $id
            ),
            ARRAY_A
        );

        if ($this->db()->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear el producto.'
            );
        }

        if ($row === null) {
            return null;
        }

        /** @var Product $product */
        $product = $this->hydrate($row);

        return $product;
    }

    public function deleteSafely(
        int $id,
        string $expectedUpdatedAt
    ): int {
        $result = $this->db()->delete(
            $this->table($this->table),
            [
                'id' => $id,
                'updated_at' => $expectedUpdatedAt,
            ]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible eliminar el producto.'
            );
        }

        return $result;
    }

    /**
     * Busca un producto por slug.
     */
    public function findBySlug(string $slug): ?Product
    {
        return $this->findOneBy(
            'slug',
            $slug,
            '%s'
        );
    }

    /**
     * Busca un producto por SKU.
     */
    public function findBySku(string $sku): ?Product
    {
        return $this->findOneBy(
            'sku',
            $sku,
            '%s'
        );
    }

    /**
     * Busca un producto por su referencia de WooCommerce.
     */
    public function findByWooProductId(
        int $wooProductId
    ): ?Product {
        return $this->findOneBy(
            'woo_product_id',
            $wooProductId,
            '%d'
        );
    }

    /**
     * Indica si existe un producto con el slug indicado.
     */
    public function existsBySlug(
        string $slug,
        ?int $excludeId = null
    ): bool {
        return $this->existsBy(
            'slug',
            $slug,
            '%s',
            $excludeId
        );
    }

    /**
     * Indica si existe un producto con el SKU indicado.
     */
    public function existsBySku(
        string $sku,
        ?int $excludeId = null
    ): bool {
        return $this->existsBy(
            'sku',
            $sku,
            '%s',
            $excludeId
        );
    }

    /**
     * Indica si existe un producto con la referencia indicada.
     */
    public function existsByWooProductId(
        int $wooProductId,
        ?int $excludeId = null
    ): bool {
        return $this->existsBy(
            'woo_product_id',
            $wooProductId,
            '%d',
            $excludeId
        );
    }

    /**
     * Busca productos.
     */
    public function search(?string $term): Collection
    {
        [$where, $params] = $this->buildFilters($term);

        $sql = sprintf(
            'SELECT *
             FROM %s
             %s
             ORDER BY id DESC',
            $this->table($this->table),
            $where
        );

        if (! empty($params)) {
            $sql = $this->db()->prepare(
                $sql,
                ...$params
            );
        }

        $rows = $this->db()->get_results(
            $sql,
            ARRAY_A
        );

        return $this->collectionFromRows($rows);
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
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->buildFilters(
            $term,
            $status
        );

        $allowed = [
            'id',
            'name',
            'slug',
            'sku',
            'status',
            'created_at',
            'updated_at',
        ];

        if (! in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }

        $direction = strtoupper($direction);

        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }

        $sql = sprintf(
            'SELECT *
             FROM %s
             %s
             ORDER BY %s %s
             LIMIT %%d OFFSET %%d',
            $this->table($this->table),
            $where,
            $orderBy,
            $direction
        );

        $params[] = $perPage;
        $params[] = $offset;

        $rows = $this->db()->get_results(
            $this->db()->prepare(
                $sql,
                ...$params
            ),
            ARRAY_A
        );

        return $this->collectionFromRows($rows);
    }

    /**
     * Cuenta los registros.
     */
    public function count(
        ?string $term = null,
        ?string $status = null
    ): int {
        [$where, $params] = $this->buildFilters(
            $term,
            $status
        );

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s %s',
            $this->table($this->table),
            $where
        );

        if (empty($params)) {
            return (int) $this->db()->get_var($sql);
        }

        return (int) $this->db()->get_var(
            $this->db()->prepare(
                $sql,
                ...$params
            )
        );
    }

    /**
     * Actualiza el estado de un producto.
     */
    public function updateStatus(
        int $id,
        string $status,
        string $expectedUpdatedAt,
        string $updatedAt
    ): int {
        $sql = sprintf(
            'UPDATE %s
             SET status = %%s, updated_at = %%s
             WHERE id = %%d AND updated_at = %%s',
            $this->table($this->table)
        );

        $result = $this->db()->query(
            $this->db()->prepare(
                $sql,
                $status,
                $updatedAt,
                $id,
                $expectedUpdatedAt
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el estado del producto.'
            );
        }

        return $result;
    }

    /**
     * Actualiza datos comerciales si la version durable coincide.
     */
    public function updateCommercial(
        int $id,
        array $data,
        string $expectedUpdatedAt,
        string $updatedAt
    ): int {
        $allowedFields = [
            'woo_product_id',
            'name',
            'slug',
            'sku',
            'description',
            'category_id',
            'brand_id',
            'unit_id',
            'image_id',
        ];
        $payload = array_intersect_key(
            $data,
            array_flip($allowedFields)
        );
        $payload['updated_at'] = $updatedAt;

        $result = $this->db()->update(
            $this->table($this->table),
            $payload,
            [
                'id' => $id,
                'updated_at' => $expectedUpdatedAt,
            ]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el producto.'
            );
        }

        return $result;
    }

    /**
     * Aplica un lote lifecycle como una unica unidad atomica.
     *
     * @param list<array{
     *     id: int,
     *     status: string,
     *     expected_updated_at: string,
     *     updated_at: string
     * }> $updates
     */
    public function updateStatusesAtomically(array $updates): int
    {
        return $this->transaction(function () use ($updates): int {
            foreach ($updates as $update) {
                $affected = $this->updateStatus(
                    $update['id'],
                    $update['status'],
                    $update['expected_updated_at'],
                    $update['updated_at']
                );

                if ($affected !== 1) {
                    throw new ProductConcurrencyException(
                        'El lote encontro una version concurrente.'
                    );
                }
            }

            return count($updates);
        });
    }

    /**
     * Aplica un lote comercial como una unica unidad atomica.
     *
     * @param list<array{
     *     id: int,
     *     data: array<string, mixed>,
     *     expected_updated_at: string,
     *     updated_at: string
     * }> $updates
     */
    public function updateCommercialsAtomically(array $updates): int
    {
        return $this->transaction(function () use ($updates): int {
            foreach ($updates as $update) {
                $affected = $this->updateCommercial(
                    $update['id'],
                    $update['data'],
                    $update['expected_updated_at'],
                    $update['updated_at']
                );

                if ($affected !== 1) {
                    throw new ProductConcurrencyException(
                        'El lote encontro una version concurrente.'
                    );
                }
            }

            return count($updates);
        });
    }

    /**
     * Actualiza masivamente el estado de productos.
     */
    public function bulkUpdateStatus(
        array $ids,
        string $status,
        string $updatedAt
    ): int {
        $lifecycle = new ProductLifecycleContract();
        $updates = [];

        foreach ($ids as $id) {
            $product = $this->findById((int) $id);

            if ($product === null) {
                continue;
            }

            $lifecycle->assertTransition($product->status, $status);

            if ($product->status === $status) {
                continue;
            }

            if (strcmp($updatedAt, $product->updated_at) <= 0) {
                throw new InvalidArgumentException(
                    'La version masiva debe ser posterior.'
                );
            }

            $updates[] = [
                'id' => (int) $product->id,
                'status' => $status,
                'expected_updated_at' => $product->updated_at,
                'updated_at' => $updatedAt,
            ];
        }

        return $this->updateStatusesAtomically($updates);
    }

    /**
     * Actualiza masivamente la categoria de productos.
     */
    public function bulkUpdateCategory(
        array $ids,
        ?int $categoryId,
        string $updatedAt
    ): int {
        return $this->bulkUpdateField(
            $ids,
            'category_id',
            $categoryId,
            $updatedAt
        );
    }

    /**
     * Actualiza masivamente la marca de productos.
     */
    public function bulkUpdateBrand(
        array $ids,
        ?int $brandId,
        string $updatedAt
    ): int {
        return $this->bulkUpdateField(
            $ids,
            'brand_id',
            $brandId,
            $updatedAt
        );
    }

    /**
     * Actualiza masivamente la unidad de productos.
     */
    public function bulkUpdateUnit(
        array $ids,
        ?int $unitId,
        string $updatedAt
    ): int {
        return $this->bulkUpdateField(
            $ids,
            'unit_id',
            $unitId,
            $updatedAt
        );
    }

    /**
     * Actualiza un campo permitido para varios productos.
     */
    private function bulkUpdateField(
        array $ids,
        string $field,
        string|int|null $value,
        string $updatedAt
    ): int {
        if (! in_array($field, self::BULK_UPDATE_FIELDS, true)) {
            throw new InvalidArgumentException(
                'El campo de actualizacion masiva no es valido.'
            );
        }

        if ($ids === []) {
            return 0;
        }

        $idPlaceholders = implode(
            ', ',
            array_fill(0, count($ids), '%d')
        );
        $params = [];

        if ($value === null) {
            $assignment = sprintf('%s = NULL', $field);
        } else {
            $valuePlaceholder = '%d';
            $assignment = sprintf(
                '%s = %s',
                $field,
                $valuePlaceholder
            );
            $params[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s
             SET %s, updated_at = %%s
             WHERE id IN (%s)',
            $this->table($this->table),
            $assignment,
            $idPlaceholders
        );

        $params[] = $updatedAt;
        $params = array_merge($params, $ids);

        $result = $this->db()->query(
            $this->db()->prepare(
                $sql,
                ...$params
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar masivamente los productos.'
            );
        }

        return $result;
    }

    /**
     * Busca un registro por una columna conocida.
     */
    private function findOneBy(
        string $column,
        string|int $value,
        string $placeholder
    ): ?Product {
        $sql = sprintf(
            'SELECT *
             FROM %s
             WHERE %s = %s
             LIMIT 1',
            $this->table($this->table),
            $column,
            $placeholder
        );

        $row = $this->db()->get_row(
            $this->db()->prepare(
                $sql,
                $value
            ),
            ARRAY_A
        );

        if ($row === null) {
            return null;
        }

        /** @var Product $product */
        $product = $this->hydrate($row);

        return $product;
    }

    /**
     * Comprueba la existencia por una columna conocida.
     */
    private function existsBy(
        string $column,
        string|int $value,
        string $placeholder,
        ?int $excludeId
    ): bool {
        $exclude = $excludeId === null
            ? ''
            : ' AND id <> %d';

        $sql = sprintf(
            'SELECT 1
             FROM %s
             WHERE %s = %s%s
             LIMIT 1',
            $this->table($this->table),
            $column,
            $placeholder,
            $exclude
        );

        $params = [$value];

        if ($excludeId !== null) {
            $params[] = $excludeId;
        }

        return $this->db()->get_var(
            $this->db()->prepare(
                $sql,
                ...$params
            )
        ) !== null;
    }

    /**
     * Construye los filtros compartidos del listado.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function buildFilters(
        ?string $term,
        ?string $status = null
    ): array {
        $conditions = [];
        $params = [];

        if (! empty($term)) {
            $conditions[] = '(
                name LIKE %s
                OR slug LIKE %s
                OR sku LIKE %s
            )';

            $term = '%' . $this->db()->esc_like($term) . '%';

            $params = [
                $term,
                $term,
                $term,
            ];
        }

        if (! empty($status)) {
            $conditions[] = 'status = %s';
            $params[] = $status;
        }

        $where = empty($conditions)
            ? ''
            : 'WHERE ' . implode("\nAND ", $conditions);

        return [$where, $params];
    }

    /**
     * Convierte filas en una colección de modelos.
     */
    private function collectionFromRows(array $rows): Collection
    {
        $collection = new Collection();

        foreach ($rows as $row) {
            $collection->add(
                $this->hydrate($row)
            );
        }

        return $collection;
    }
}
