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
        $owned = $this->integerColumn($wpdb->prepare(
            "SELECT id FROM {$table} WHERE owner_user_id=%d ORDER BY id",
            $userId
        ), 'store_owner_authority_read_failed');
        if (count($owned) > 1) throw new RuntimeException('store_owner_authority_ambiguous');
        if ($owned !== []) {
            $storeId = $owned[0];
            $projection = $this->projectedStoreIds($userId);
            if ($projection !== [] && $projection !== [$storeId]) {
                throw new RuntimeException('store_owner_projection_conflict');
            }
            return $storeId;
        }

        $projection = $this->projectedStoreIds($userId);
        if (count($projection) > 1) throw new RuntimeException('store_owner_historical_user_ambiguous');
        if ($projection === []) return null;
        $storeId = $projection[0];
        $historicalStore = $wpdb->get_row($wpdb->prepare(
            "SELECT id,owner_user_id FROM {$table} WHERE id=%d",
            $storeId
        ), ARRAY_A);
        if (! is_array($historicalStore)) {
            throw new RuntimeException('store_owner_historical_store_missing');
        }
        if ($historicalStore['owner_user_id'] !== null && (int) $historicalStore['owner_user_id'] !== $userId) {
            throw new RuntimeException('store_owner_projection_conflict');
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
        $this->setOwnerStoreForUser($userId, $storeId);
    }

    public function unassignOwner(int $userId): void
    {
        $this->setOwnerStoreForUser($userId, null);
    }

    public function setOwnerStoreForUser(int $userId, ?int $storeId): void
    {
        if ($userId <= 0 || ($storeId !== null && $storeId <= 0) || get_userdata($userId) === false) {
            throw new RuntimeException('store_owner_invalid_assignment');
        }
        global $wpdb;
        $table = $this->storesTable();
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('store_owner_assignment_transaction_failed');
        }
        try {
            $candidateOwned = $this->integerColumn($wpdb->prepare(
                "SELECT id FROM {$table} WHERE owner_user_id=%d ORDER BY id",
                $userId
            ), 'store_owner_authority_read_failed');
            $storeIds = $candidateOwned;
            if ($storeId !== null) $storeIds[] = $storeId;
            $storeIds = array_values(array_unique($storeIds));
            sort($storeIds, SORT_NUMERIC);

            // Global lock order: Store IDs ascending, then user IDs ascending.
            $lockedStores = $this->lockStores($storeIds);
            $owned = $this->integerColumn($wpdb->prepare(
                "SELECT id FROM {$table} WHERE owner_user_id=%d ORDER BY id FOR UPDATE",
                $userId
            ), 'store_owner_authority_lock_failed');
            if ($owned !== $candidateOwned) throw new RuntimeException('store_owner_concurrent_modification');
            if (count($owned) > 1) throw new RuntimeException('store_owner_authority_ambiguous');
            if ($storeId !== null && ! isset($lockedStores[$storeId])) {
                throw new RuntimeException('store_owner_store_missing');
            }

            $otherProjectedUsers = [];
            if ($storeId !== null) {
                $otherProjectedUsers = $this->integerColumn($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta}"
                    . " WHERE meta_key=%s AND CAST(meta_value AS UNSIGNED)=%d AND user_id<>%d ORDER BY user_id",
                    MinimarketRole::STORE_META_KEY,
                    $storeId,
                    $userId
                ), 'store_owner_projection_read_failed');
            }
            $userIds = array_values(array_unique(array_merge([$userId], $otherProjectedUsers)));
            sort($userIds, SORT_NUMERIC);
            $this->lockUsers($userIds);
            $this->lockProjectionUsers($userIds);

            $projection = $this->projectedStoreIds($userId);
            if (count($projection) > 1) throw new RuntimeException('store_owner_historical_user_ambiguous');
            if ($owned !== [] && $projection !== [] && $projection !== $owned) {
                throw new RuntimeException('store_owner_projection_conflict');
            }

            if ($storeId !== null) {
                $store = $lockedStores[$storeId];
                if ($store['owner_user_id'] !== null && (int) $store['owner_user_id'] !== $userId) {
                    throw new RuntimeException('store_owner_store_already_owned');
                }
                $revalidatedOtherUsers = $this->integerColumn($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta}"
                    . " WHERE meta_key=%s AND CAST(meta_value AS UNSIGNED)=%d AND user_id<>%d ORDER BY user_id",
                    MinimarketRole::STORE_META_KEY,
                    $storeId,
                    $userId
                ), 'store_owner_projection_read_failed');
                if ($revalidatedOtherUsers !== $otherProjectedUsers) {
                    throw new RuntimeException('store_owner_concurrent_modification');
                }
                if ($revalidatedOtherUsers !== []) {
                    throw new RuntimeException('store_owner_historical_store_ambiguous');
                }
            }

            if ($owned !== [] && ($storeId === null || $owned[0] !== $storeId)) {
                if ($wpdb->update($table, ['owner_user_id' => null], ['id' => $owned[0], 'owner_user_id' => $userId]) !== 1) {
                    throw new RuntimeException('store_owner_unassignment_failed');
                }
            }
            if ($storeId !== null && ($owned === [] || $owned[0] !== $storeId)) {
                if ($wpdb->update($table, ['owner_user_id' => $userId], ['id' => $storeId, 'owner_user_id' => null]) !== 1) {
                    throw new RuntimeException('store_owner_assignment_failed');
                }
            }
            if ($wpdb->delete($wpdb->usermeta, [
                'user_id' => $userId,
                'meta_key' => MinimarketRole::STORE_META_KEY,
            ]) === false) {
                throw new RuntimeException('store_owner_projection_write_failed');
            }
            if ($storeId !== null && $wpdb->insert($wpdb->usermeta, [
                'user_id' => $userId,
                'meta_key' => MinimarketRole::STORE_META_KEY,
                'meta_value' => $storeId,
            ]) !== 1) {
                throw new RuntimeException('store_owner_projection_write_failed');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('store_owner_assignment_commit_failed');
            }
            clean_user_cache($userId);
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            clean_user_cache($userId);
            throw $exception;
        }
    }

    public function reconcileCompatibilityProjection(int $storeId, int $userId): void
    {
        if ($storeId <= 0 || $userId <= 0 || get_userdata($userId) === false) {
            throw new RuntimeException('store_owner_invalid_assignment');
        }
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('store_owner_assignment_transaction_failed');
        }
        try {
            $candidateOwned = $this->integerColumn($wpdb->prepare(
                "SELECT id FROM {$this->storesTable()} WHERE owner_user_id=%d ORDER BY id",
                $userId
            ), 'store_owner_authority_read_failed');
            sort($candidateOwned, SORT_NUMERIC);
            $this->lockStores($candidateOwned);
            $owned = $this->integerColumn($wpdb->prepare(
                "SELECT id FROM {$this->storesTable()} WHERE owner_user_id=%d ORDER BY id FOR UPDATE",
                $userId
            ), 'store_owner_authority_lock_failed');
            if ($owned !== $candidateOwned) throw new RuntimeException('store_owner_concurrent_modification');
            if (count($owned) > 1) throw new RuntimeException('store_owner_authority_ambiguous');
            if ($owned !== [] && $owned[0] !== $storeId) throw new RuntimeException('store_owner_projection_conflict');
            $this->lockUsers([$userId]);
            $this->lockProjectionUsers([$userId]);
            $projection = $this->projectedStoreIds($userId);
            if (count($projection) > 1) throw new RuntimeException('store_owner_historical_user_ambiguous');
            if ($owned === [] && $projection !== [] && $projection !== [$storeId]) {
                throw new RuntimeException('store_owner_projection_conflict');
            }
            if ($wpdb->delete($wpdb->usermeta, [
                'user_id'=>$userId,
                'meta_key'=>MinimarketRole::STORE_META_KEY,
            ]) === false) throw new RuntimeException('store_owner_projection_write_failed');
            if ($owned !== [] && $wpdb->insert($wpdb->usermeta, [
                'user_id'=>$userId,
                'meta_key'=>MinimarketRole::STORE_META_KEY,
                'meta_value'=>$owned[0],
            ]) !== 1) throw new RuntimeException('store_owner_projection_write_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('store_owner_assignment_commit_failed');
            clean_user_cache($userId);
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            clean_user_cache($userId);
            throw $exception;
        }
    }

    /** @param list<int> $storeIds @return array<int,array<string,mixed>> */
    private function lockStores(array $storeIds): array
    {
        if ($storeIds === []) return [];
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($storeIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,owner_user_id FROM {$this->storesTable()} WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE",
            ...$storeIds
        ), ARRAY_A);
        if (! is_array($rows)) throw new RuntimeException('store_owner_lock_failed');
        $locked = [];
        foreach ($rows as $row) $locked[(int) $row['id']] = $row;
        return $locked;
    }

    /** @param list<int> $userIds */
    private function lockProjectionUsers(array $userIds): void
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT umeta_id,user_id FROM {$wpdb->usermeta}"
            . " WHERE user_id IN ({$placeholders}) AND meta_key=%s ORDER BY user_id,umeta_id FOR UPDATE",
            ...array_merge($userIds, [MinimarketRole::STORE_META_KEY])
        ), ARRAY_A);
        if (! is_array($rows)) throw new RuntimeException('store_owner_projection_lock_failed');
    }

    /** @param list<int> $userIds */
    private function lockUsers(array $userIds): void
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        $locked = $this->integerColumn($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID IN ({$placeholders}) ORDER BY ID FOR UPDATE",
            ...$userIds
        ), 'store_owner_user_lock_failed');
        if ($locked !== $userIds) throw new RuntimeException('store_owner_user_missing');
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
        if (! is_array($values) || $wpdb->last_error !== '') {
            throw new RuntimeException('store_owner_projection_read_failed');
        }
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

    /** @return list<int> */
    private function integerColumn(string $query, string $failure): array
    {
        global $wpdb;
        $values = $wpdb->get_col($query);
        if (! is_array($values) || $wpdb->last_error !== '') throw new RuntimeException($failure);
        return array_map('intval', $values);
    }
}
