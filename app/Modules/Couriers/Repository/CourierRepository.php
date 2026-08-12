<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Couriers\Repository;

use VeciAhorra\Database\Repository;

/**
 * Consulta de repartidores para integraciones de Delivery.
 */
final class CourierRepository extends Repository
{
    private const TABLE = 'couriers';

    public function find(int $id): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE id = %%d
                     LIMIT 1',
                    $this->table(self::TABLE)
                ),
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    public function isApproved(array $courier): bool
    {
        return array_key_exists('status', $courier)
            && (string) $courier['status'] === 'approved';
    }

    public function all(): array
    {
        return $this->db()->get_results(sprintf('SELECT * FROM %s ORDER BY id DESC', $this->table(self::TABLE)), ARRAY_A);
    }

    public function save(array $data, ?int $id = null): int
    {
        if ($id === null) {
            if ($this->db()->insert($this->table(self::TABLE), $data) !== 1) throw new \RuntimeException('No fue posible crear Courier.');
            return (int) $this->db()->insert_id;
        }
        if ($this->db()->update($this->table(self::TABLE), $data, ['id'=>$id]) === false) throw new \RuntimeException('No fue posible editar Courier.');
        return $id;
    }

    public function transition(int $id, string $status, string $now): void
    {
        $current = $this->find($id) ?? throw new \RuntimeException('Courier inexistente.');
        $from = (string) $current['status'];
        if ($from === $status) return;
        if (! (($status === 'approved' && in_array($from, ['pending','inactive'], true)) || ($status === 'inactive' && in_array($from, ['pending','approved'], true)))) {
            throw new \DomainException('Transicion Courier invalida.');
        }
        $data = ['status'=>$status, 'updated_at'=>$now];
        if ($status === 'approved') $data['approved_at'] = $now;
        if ($this->db()->update($this->table(self::TABLE), $data, ['id'=>$id]) === false) throw new \RuntimeException('No fue posible cambiar Courier.');
    }
}
