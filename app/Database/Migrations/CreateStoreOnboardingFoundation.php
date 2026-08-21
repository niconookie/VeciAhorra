<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use RuntimeException;
use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Tables\StoreOnboardingApplicationsTable;
use VeciAhorra\Database\Tables\StoresTable;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;

final class CreateStoreOnboardingFoundation
{
    public function up(): void
    {
        $this->installStructure();
        $this->assertStructure();
        $this->backfillValidatedOwners();
    }

    public function assertStructure(): void
    {
        global $wpdb;
        $stores = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
        $applications = $wpdb->prefix . Config::TABLE_PREFIX . 'store_onboarding_applications';

        $this->assertTableExists($stores, 'stores_table');
        $this->assertTableExists($applications, 'onboarding_table');

        $storeColumns = $this->columnsFor($stores);
        if (! isset($storeColumns['owner_user_id'])) {
            throw new RuntimeException('r1a_schema_missing:stores.owner_user_id');
        }
        $owner = $storeColumns['owner_user_id'];
        if (($owner['Null'] ?? '') !== 'YES') {
            throw new RuntimeException('r1a_schema_invalid:stores.owner_user_id.nullable');
        }
        if (preg_match('/^bigint(?:\(\d+\))? unsigned$/i', (string) ($owner['Type'] ?? '')) !== 1) {
            throw new RuntimeException('r1a_schema_invalid:stores.owner_user_id.type');
        }
        $this->assertIndex($stores, 'stores_owner_user_unique', ['owner_user_id'], true);

        $requiredColumns = [
            'id', 'public_id', 'user_id', 'account_email', 'owner_rut_normalized', 'status',
            'idempotency_key_hash', 'terms_version', 'terms_accepted_at', 'store_id',
            'failure_code', 'attempt_count', 'last_attempt_at', 'created_at', 'updated_at', 'abandoned_at',
        ];
        $applicationColumns = $this->columnsFor($applications);
        foreach ($requiredColumns as $column) {
            if (! isset($applicationColumns[$column])) {
                throw new RuntimeException('r1a_schema_missing:onboarding.' . $column);
            }
        }
        foreach ([
            'PRIMARY' => [['id'], true],
            'onboarding_public_id_unique' => [['public_id'], true],
            'onboarding_user_unique' => [['user_id'], true],
            'onboarding_store_unique' => [['store_id'], true],
            'onboarding_idempotency_unique' => [['idempotency_key_hash'], true],
            'onboarding_status_updated' => [['status', 'updated_at'], false],
            'onboarding_account_email' => [['account_email'], false],
            'onboarding_owner_rut' => [['owner_rut_normalized'], false],
        ] as $name => [$columns, $unique]) {
            $this->assertIndex($applications, $name, $columns, $unique);
        }
    }

    private function assertTableExists(string $table, string $identity): void
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table) {
            throw new RuntimeException('r1a_schema_missing:' . $identity);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function columnsFor(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (! is_array($rows)) {
            throw new RuntimeException('r1a_schema_inspection_failed:columns');
        }
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['Field']] = $row;
        }
        return $columns;
    }

    /** @param list<string> $expectedColumns */
    private function assertIndex(string $table, string $name, array $expectedColumns, bool $unique): void
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name=%s",
            $name
        ), ARRAY_A);
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('r1a_schema_missing:index.' . $name);
        }
        usort($rows, static fn(array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        $columns = array_map(static fn(array $row): string => (string) $row['Column_name'], $rows);
        $isUnique = array_reduce($rows, static fn(bool $carry, array $row): bool => $carry && (int) $row['Non_unique'] === 0, true);
        $indexesWholeColumns = array_reduce($rows, static function (bool $carry, array $row): bool {
            $subPart = $row['Sub_part'] ?? null;
            return $carry && ($subPart === null || strtolower((string) $subPart) === 'null');
        }, true);
        if ($columns !== $expectedColumns || $isUnique !== $unique || ! $indexesWholeColumns) {
            throw new RuntimeException('r1a_schema_invalid:index.' . $name);
        }
    }

    public function installStructure(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ([new StoresTable(), new StoreOnboardingApplicationsTable()] as $schema) {
            $builder = TableBuilder::make($wpdb->prefix . Config::TABLE_PREFIX . $schema->name());
            $schema->define($builder);
            dbDelta($builder->build($wpdb->get_charset_collate()));
        }

    }

    public function backfillValidatedOwners(): int
    {
        global $wpdb;
        $discoveredRows = $this->projectionRows();
        $candidates = $this->validateBackfillRows($discoveredRows);
        if ($candidates === []) {
            return 0;
        }
        $storeIds = array_values(array_unique(array_values($candidates)));
        $userIds = array_values(array_unique(array_keys($candidates)));
        sort($storeIds, SORT_NUMERIC);
        sort($userIds, SORT_NUMERIC);

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('store_owner_backfill_transaction_failed');
        }
        try {
            $stores = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
            $lockedStores = [];
            foreach ($storeIds as $storeId) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,owner_user_id FROM {$stores} WHERE id=%d FOR UPDATE",
                    $storeId
                ), ARRAY_A);
                if (! is_array($row)) throw new RuntimeException('store_owner_backfill_concurrent_conflict');
                $lockedStores[$storeId] = $row;
            }
            foreach ($userIds as $userId) {
                if ((int) $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",
                    $userId
                )) !== $userId) throw new RuntimeException('store_owner_backfill_concurrent_conflict');
            }
            foreach ($userIds as $userId) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT umeta_id,user_id,meta_value FROM {$wpdb->usermeta}"
                    . " WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id FOR UPDATE",
                    $userId,
                    MinimarketRole::STORE_META_KEY
                ), ARRAY_A);
                if (! is_array($rows) || $wpdb->last_error !== '') {
                    throw new RuntimeException('store_owner_backfill_lock_failed');
                }
            }

            // Lock the complete meta-key range last so a new user cannot add a
            // competing projection after the closed Store/User sets were built.
            $lockedProjectionRows = $wpdb->get_results($wpdb->prepare(
                "SELECT umeta_id,user_id,meta_value FROM {$wpdb->usermeta}"
                . " WHERE meta_key=%s ORDER BY user_id,umeta_id FOR UPDATE",
                MinimarketRole::STORE_META_KEY
            ), ARRAY_A);
            if (! is_array($lockedProjectionRows) || $wpdb->last_error !== '') {
                throw new RuntimeException('store_owner_backfill_lock_failed');
            }
            if ($this->projectionIdentity($lockedProjectionRows) !== $this->projectionIdentity($discoveredRows)) {
                throw new RuntimeException('store_owner_backfill_concurrent_conflict');
            }

            $revalidated = $this->validateBackfillRows($lockedProjectionRows, $lockedStores, $userIds);
            if ($revalidated !== $candidates) {
                throw new RuntimeException('store_owner_backfill_concurrent_conflict');
            }

            ksort($revalidated, SORT_NUMERIC);
            foreach ($revalidated as $userId => $storeId) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$stores} SET owner_user_id=%d WHERE id=%d AND (owner_user_id IS NULL OR owner_user_id=%d)",
                    $userId,
                    $storeId,
                    $userId
                ));
                if ($updated === false || ($updated !== 0 && $updated !== 1)) {
                    throw new RuntimeException('store_owner_backfill_write_failed');
                }
                $owner = $wpdb->get_var($wpdb->prepare(
                    "SELECT owner_user_id FROM {$stores} WHERE id=%d",
                    $storeId
                ));
                if ($owner === null || (int) $owner !== $userId) {
                    throw new RuntimeException('store_owner_backfill_concurrent_conflict');
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('store_owner_backfill_commit_failed');
            }
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
        return count($candidates);
    }

    /** @return array<int,int> */
    public function validatedBackfillCandidates(bool $lockRows = false): array
    {
        if ($lockRows) throw new RuntimeException('store_owner_backfill_join_lock_forbidden');
        return $this->validateBackfillRows($this->projectionRows());
    }

    /** @return list<array<string,mixed>> */
    private function projectionRows(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT umeta_id,user_id,meta_value FROM {$wpdb->usermeta}"
            . ' WHERE meta_key=%s ORDER BY user_id,umeta_id',
            MinimarketRole::STORE_META_KEY
        ), ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('store_owner_backfill_read_failed');
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,array<string,mixed>>|null $lockedStores
     * @param list<int>|null $lockedUsers
     * @return array<int,int>
     */
    private function validateBackfillRows(array $rows, ?array $lockedStores = null, ?array $lockedUsers = null): array
    {
        global $wpdb;
        $stores = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
        $byUser = [];
        $byStore = [];
        $candidates = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $rawStoreId = (string) $row['meta_value'];
            if ($userId <= 0 || preg_match('/^[1-9][0-9]*$/', $rawStoreId) !== 1) {
                throw new RuntimeException('store_owner_backfill_store_missing');
            }
            $storeId = (int) $rawStoreId;
            if ($lockedUsers !== null) {
                if (! in_array($userId, $lockedUsers, true)) throw new RuntimeException('store_owner_backfill_concurrent_conflict');
            } elseif ((int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE ID=%d",
                $userId
            )) !== $userId) {
                throw new RuntimeException('store_owner_backfill_user_missing');
            }
            $store = $lockedStores[$storeId] ?? null;
            if ($lockedStores === null) {
                $store = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,owner_user_id FROM {$stores} WHERE id=%d",
                    $storeId
                ), ARRAY_A);
            }
            if (! is_array($store)) throw new RuntimeException('store_owner_backfill_store_missing');
            $byUser[$userId][$storeId] = true;
            $byStore[$storeId][$userId] = true;
            $owner = $store['owner_user_id'] === null ? null : (int) $store['owner_user_id'];
            if ($owner !== null && $owner !== $userId) throw new RuntimeException('store_owner_backfill_owner_conflict');
            $candidates[$userId] = $storeId;
        }
        foreach ($byUser as $storesForUser) {
            if (count($storesForUser) !== 1) throw new RuntimeException('store_owner_backfill_user_ambiguous');
        }
        foreach ($byStore as $usersForStore) {
            if (count($usersForStore) !== 1) throw new RuntimeException('store_owner_backfill_store_ambiguous');
        }
        ksort($candidates, SORT_NUMERIC);
        return $candidates;
    }

    /** @param list<array<string,mixed>> $rows @return list<array{int,int,string}> */
    private function projectionIdentity(array $rows): array
    {
        $identity = array_map(static fn(array $row): array => [
            (int) $row['user_id'],
            (int) $row['umeta_id'],
            (string) $row['meta_value'],
        ], $rows);
        usort($identity, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $identity;
    }
}
