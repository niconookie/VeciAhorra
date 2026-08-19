<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Ownership;

use RuntimeException;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;

final class StoreOwnershipRepository
{
    public function resolveStoreIdForOwnerUser(int $userId): ?int
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return null;
        }
        global $wpdb;
        $table = $this->storesTable();
        $owned = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE owner_user_id=%d ORDER BY id",
            $userId
        )));
        if (count($owned) > 1) throw new RuntimeException('store_owner_authority_ambiguous');
        if ($owned !== []) {
            $storeId = $owned[0];
            $projection = $this->projectedStoreIds($userId);
            if ($projection !== [] && $projection !== [$storeId]) {
                throw new RuntimeException('store_owner_projection_conflict');
            }
            if ($projection === []) $this->reconcileCompatibilityProjection($storeId, $userId);
            return $storeId;
        }

        $projection = $this->projectedStoreIds($userId);
        if (count($projection) > 1) throw new RuntimeException('store_owner_historical_user_ambiguous');
        if ($projection === []) return null;
        $storeId = $projection[0];
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id=%d", $storeId)) !== 1) {
            throw new RuntimeException('store_owner_historical_store_missing');
        }
        if ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s AND CAST(meta_value AS UNSIGNED)=%d",
            MinimarketRole::STORE_META_KEY,
            $storeId
        )) !== 1) throw new RuntimeException('store_owner_historical_store_ambiguous');
        return $storeId;
    }

    public function assignOwner(int $storeId, int $userId): void
    {
        if ($storeId <= 0 || $userId <= 0 || get_userdata($userId) === false) {
            throw new RuntimeException('store_owner_invalid_assignment');
        }
        global $wpdb;
        $table = $this->storesTable();
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('store_owner_assignment_transaction_failed');
        }
        try {
            $store = $wpdb->get_row($wpdb->prepare(
                "SELECT id,owner_user_id FROM {$table} WHERE id=%d FOR UPDATE",
                $storeId
            ), ARRAY_A);
            if (! is_array($store)) throw new RuntimeException('store_owner_store_missing');
            if ($store['owner_user_id'] !== null && (int) $store['owner_user_id'] !== $userId) {
                throw new RuntimeException('store_owner_store_already_owned');
            }
            $other = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE owner_user_id=%d AND id<>%d LIMIT 1 FOR UPDATE",
                $userId,
                $storeId
            ));
            if ($other > 0) throw new RuntimeException('store_owner_user_already_owns_store');
            $projection = $this->projectedStoreIds($userId, true);
            if ($projection !== [] && $projection !== [$storeId]) {
                throw new RuntimeException('store_owner_projection_conflict');
            }
            if ($wpdb->update($table, ['owner_user_id' => $userId], ['id' => $storeId]) === false) {
                throw new RuntimeException('store_owner_assignment_failed');
            }
            $this->reconcileCompatibilityProjection($storeId, $userId);
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('store_owner_assignment_commit_failed');
            }
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    public function reconcileCompatibilityProjection(int $storeId, int $userId): void
    {
        global $wpdb;
        $authority = $wpdb->get_var($wpdb->prepare(
            "SELECT owner_user_id FROM {$this->storesTable()} WHERE id=%d",
            $storeId
        ));
        if ($authority === null || (int) $authority !== $userId) {
            throw new RuntimeException('store_owner_projection_without_authority');
        }
        $projection = $this->projectedStoreIds($userId);
        if ($projection !== [] && $projection !== [$storeId]) {
            throw new RuntimeException('store_owner_projection_conflict');
        }
        if ($projection === [] && update_user_meta($userId, MinimarketRole::STORE_META_KEY, $storeId) === false) {
            throw new RuntimeException('store_owner_projection_write_failed');
        }
    }

    /** @return list<int> */
    private function projectedStoreIds(int $userId, bool $lockRows = false): array
    {
        global $wpdb;
        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id"
            . ($lockRows ? ' FOR UPDATE' : ''),
            $userId,
            MinimarketRole::STORE_META_KEY
        ));
        $values = array_values(array_unique(array_filter(array_map(
            'intval',
            $values
        ), static fn(int $id): bool => $id > 0)));
        sort($values);
        return $values;
    }

    private function storesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Config::TABLE_PREFIX . 'stores';
    }
}
