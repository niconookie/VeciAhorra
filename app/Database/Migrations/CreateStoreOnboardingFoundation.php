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
        $this->backfillValidatedOwners();
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
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('store_owner_backfill_transaction_failed');
        }
        try {
            $candidates = $this->validatedBackfillCandidates(true);
            if ($candidates === []) {
                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('store_owner_backfill_commit_failed');
                }
                return 0;
            }
            $stores = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
            foreach ($candidates as $userId => $storeId) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$stores} SET owner_user_id=%d WHERE id=%d AND (owner_user_id IS NULL OR owner_user_id=%d)",
                    $userId,
                    $storeId,
                    $userId
                ));
                if ($updated === false) {
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
        global $wpdb;
        $stores = $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT um.user_id,um.meta_value,CASE WHEN u.ID IS NULL THEN 0 ELSE 1 END user_exists,"
            . "s.id store_id,s.owner_user_id FROM {$wpdb->usermeta} um"
            . " LEFT JOIN {$wpdb->users} u ON u.ID=um.user_id"
            . " LEFT JOIN {$stores} s ON s.id=CAST(um.meta_value AS UNSIGNED)"
            . ' WHERE um.meta_key=%s ORDER BY um.user_id,um.umeta_id'
            . ($lockRows ? ' FOR UPDATE' : ''),
            MinimarketRole::STORE_META_KEY
        ), ARRAY_A);
        if (! is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('store_owner_backfill_read_failed');
        }

        $byUser = [];
        $byStore = [];
        $candidates = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $storeId = (int) $row['meta_value'];
            if ((int) $row['user_exists'] !== 1) throw new RuntimeException('store_owner_backfill_user_missing');
            if ($storeId <= 0 || $row['store_id'] === null) throw new RuntimeException('store_owner_backfill_store_missing');
            $byUser[$userId][$storeId] = true;
            $byStore[$storeId][$userId] = true;
            $owner = $row['owner_user_id'] === null ? null : (int) $row['owner_user_id'];
            if ($owner !== null && $owner !== $userId) throw new RuntimeException('store_owner_backfill_owner_conflict');
            $candidates[$userId] = $storeId;
        }
        foreach ($byUser as $storesForUser) {
            if (count($storesForUser) !== 1) throw new RuntimeException('store_owner_backfill_user_ambiguous');
        }
        foreach ($byStore as $usersForStore) {
            if (count($usersForStore) !== 1) throw new RuntimeException('store_owner_backfill_store_ambiguous');
        }
        return $candidates;
    }
}
