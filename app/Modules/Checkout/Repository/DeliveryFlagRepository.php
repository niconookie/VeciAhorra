<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

class DeliveryFlagRepository extends Repository
{
    private const TABLES = [
        'store' => 'stores',
        'product' => 'products',
        'inventory' => 'inventory',
    ];

    public function find(string $entity, int $id): ?array
    {
        $table = $this->entityTable($entity);
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT id, delivery_enabled FROM %s WHERE id = %%d LIMIT 1', $table),
            $id
        ), ARRAY_A);
        if ($this->db()->last_error !== '') {
            throw new PersistenceException('No fue posible consultar el estado de despacho.');
        }
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listing(string $entity): array
    {
        $table = $this->entityTable($entity);
        $sql = match ($entity) {
            'store' => "SELECT id, business_name AS label, delivery_enabled FROM {$table} ORDER BY id DESC LIMIT 500",
            'product' => "SELECT id, name AS label, delivery_enabled FROM {$table} ORDER BY id DESC LIMIT 500",
            'inventory' => sprintf(
                'SELECT i.id, CONCAT(p.name, %s, s.business_name) AS label, i.delivery_enabled'
                . ' FROM %s i JOIN %s p ON p.id=i.product_id JOIN %s s ON s.id=i.minimarket_id'
                . ' ORDER BY i.id DESC LIMIT 500',
                "' — '", $table, $this->table('products'), $this->table('stores')
            ),
        };
        $rows = $this->db()->get_results($sql, ARRAY_A);
        if ($this->db()->last_error !== '') {
            throw new PersistenceException('No fue posible listar los estados de despacho.');
        }
        return is_array($rows) ? $rows : [];
    }

    public function compareAndSet(string $entity, int $id, int $expected, int $enabled): int
    {
        $database = $this->db();
        $result = $database->query($database->prepare(
            sprintf('UPDATE %s SET delivery_enabled = %%d WHERE id = %%d AND delivery_enabled = %%d', $this->entityTable($entity)),
            $enabled,
            $id,
            $expected
        ));
        if ($result === false || $result > 1) {
            throw new PersistenceException('No fue posible actualizar el estado de despacho.');
        }
        return (int) $result;
    }

    private function entityTable(string $entity): string
    {
        if (! isset(self::TABLES[$entity])) {
            throw new \InvalidArgumentException('La entidad de despacho no es valida.');
        }
        return $this->table(self::TABLES[$entity]);
    }
}
