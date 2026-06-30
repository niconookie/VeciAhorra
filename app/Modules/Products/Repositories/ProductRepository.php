<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Repositories;

use VeciAhorra\Database\BaseRepository;
use VeciAhorra\Database\Collection;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Products\Models\Product;

/**
 * Repositorio de Productos.
 */
final class ProductRepository extends BaseRepository
{
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
     * Busca un producto por ID.
     */
    public function findById(int $id): ?Product
    {
        return $this->find($id);
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
        string $updatedAt
    ): int {
        $sql = sprintf(
            'UPDATE %s
             SET status = %%s, updated_at = %%s
             WHERE id = %%d',
            $this->table($this->table)
        );

        $result = $this->db()->query(
            $this->db()->prepare(
                $sql,
                $status,
                $updatedAt,
                $id
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
