<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Repositories;

use VeciAhorra\Database\BaseRepository;
use VeciAhorra\Database\Model;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Contracts\StoreDeletionRepositoryInterface;
use VeciAhorra\Modules\Stores\Models\Store;

final class StoreDeletionRepository extends BaseRepository implements StoreDeletionRepositoryInterface
{
    private const REFERENCES = [
        'inventory' => 'inventory',
        'cart_items' => 'cart_items',
        'reservations' => 'reservations',
        'orders' => 'orders',
        'deliveries' => 'deliveries',
    ];

    protected string $table = 'stores';

    public function delete(int $id): int
    {
        throw new PersistenceException('La eliminacion Store requiere compare-and-delete.');
    }

    protected function model(): string
    {
        return Store::class;
    }

    public function beginSerializable(): bool
    {
        $database = $this->db();
        $active = (int) $database->get_var('SELECT @@session.in_transaction');
        if ($database->last_error !== '') {
            throw new PersistenceException('No fue posible comprobar la transaccion Store.');
        }
        if ($active === 1) {
            return false;
        }
        if (
            $database->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE') === false
            || $database->query('START TRANSACTION') === false
        ) {
            throw new PersistenceException('No fue posible iniciar la transaccion Store.');
        }

        return true;
    }

    public function commit(): void
    {
        if ($this->db()->query('COMMIT') === false) {
            throw new PersistenceException('No fue posible confirmar la eliminacion Store.');
        }
    }

    public function rollBack(): void
    {
        if ($this->db()->query('ROLLBACK') === false) {
            throw new PersistenceException('No fue posible revertir la eliminacion Store.');
        }
    }

    public function findForUpdate(int $id): ?Model
    {
        $database = $this->db();
        $row = $database->get_row(
            $database->prepare(
                sprintf('SELECT * FROM %s WHERE id = %%d FOR UPDATE', $this->table($this->table)),
                $id
            ),
            ARRAY_A
        );
        if ($database->last_error !== '') {
            throw new PersistenceException('No fue posible bloquear la Store.');
        }

        return $row === null ? null : $this->hydrate($row);
    }

    public function referenceCounts(int $id, bool $lock = false): array
    {
        $database = $this->db();
        $counts = [];
        foreach (self::REFERENCES as $domain => $table) {
            $sql = sprintf(
                'SELECT id FROM %s WHERE minimarket_id = %%d%s',
                $this->table($table),
                $lock ? ' LOCK IN SHARE MODE' : ''
            );
            $ids = $database->get_col($database->prepare($sql, $id));
            if (! is_array($ids) || $database->last_error !== '') {
                throw new PersistenceException('No fue posible comprobar referencias Store.');
            }
            $counts[$domain] = count($ids);
        }

        return $counts;
    }

    public function compareAndDeleteLifecycle(int $id, array $expected): int
    {
        $approvalWhere = $expected['approved_at'] === null
            ? 'approved_at IS NULL'
            : 'approved_at = %s';
        $params = [$id, $expected['status'], $expected['onboarding_status']];
        if ($expected['approved_at'] !== null) {
            $params[] = $expected['approved_at'];
        }
        $database = $this->db();
        $referenceGuards = [];
        foreach (self::REFERENCES as $table) {
            $referenceGuards[] = sprintf(
                'NOT EXISTS (SELECT 1 FROM %s WHERE minimarket_id = %%d)',
                $this->table($table)
            );
            $params[] = $id;
        }
        $sql = sprintf(
            'DELETE FROM %s WHERE id = %%d AND status = %%s AND onboarding_status = %%s AND %s AND %s',
            $this->table($this->table),
            $approvalWhere,
            implode(' AND ', $referenceGuards)
        );
        $result = $database->query($database->prepare($sql, ...$params));
        if ($result === false || $result > 1) {
            throw new PersistenceException('No fue posible eliminar la Store de forma condicional.');
        }

        return (int) $result;
    }
}
