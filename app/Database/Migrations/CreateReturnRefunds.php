<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;
use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
final class CreateReturnRefunds
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix . Config::TABLE_PREFIX;
        $charset = $wpdb->get_charset_collate();
        $statements = [
            "CREATE TABLE IF NOT EXISTS {$p}return_refunds (
                delivery_id BIGINT UNSIGNED PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
                checkout_id BIGINT UNSIGNED NOT NULL, payment_id BIGINT UNSIGNED NOT NULL,
                payment_session_id BIGINT UNSIGNED NOT NULL, status VARCHAR(30) NOT NULL,
                expected_version BIGINT UNSIGNED NOT NULL, attempt BIGINT UNSIGNED NOT NULL,
                actor_id BIGINT UNSIGNED NOT NULL, note VARCHAR(500) NOT NULL,
                product_refund BIGINT UNSIGNED NOT NULL, platform_fee_refund BIGINT UNSIGNED NOT NULL,
                delivery_fee_refund BIGINT UNSIGNED NOT NULL, total_refund BIGINT UNSIGNED NOT NULL,
                owner VARCHAR(64) NOT NULL, lease_expires_at DATETIME NOT NULL,
                remote_started_at DATETIME NULL, confirmed_at DATETIME NULL,
                inventory_resolution VARCHAR(30) NOT NULL DEFAULT 'pending_manual',
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY refund_order (order_id), KEY refund_checkout (checkout_id,status)
            ) ENGINE=InnoDB {$charset}",
            "CREATE TABLE IF NOT EXISTS {$p}return_refund_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, delivery_id BIGINT UNSIGNED NOT NULL,
                attempt BIGINT UNSIGNED NOT NULL, event VARCHAR(30) NOT NULL,
                idempotency_key VARCHAR(128) NULL, fingerprint CHAR(64) NULL,
                actor_id BIGINT UNSIGNED NOT NULL, note VARCHAR(500) NOT NULL,
                product_refund BIGINT UNSIGNED NOT NULL, platform_fee_refund BIGINT UNSIGNED NOT NULL,
                delivery_fee_refund BIGINT UNSIGNED NOT NULL, total_refund BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY refund_event (delivery_id,attempt,event), UNIQUE KEY refund_key (idempotency_key)
            ) ENGINE=InnoDB {$charset}",
        ];
        foreach ($statements as $sql) if ($wpdb->query($sql) === false) throw new PersistenceException('refund_schema_failed');
        if (!$wpdb->get_row("SHOW COLUMNS FROM {$p}checkouts LIKE 'refund_status'", ARRAY_A)
            && $wpdb->query("ALTER TABLE {$p}checkouts ADD refund_status VARCHAR(30) NOT NULL DEFAULT 'none'")===false) throw new PersistenceException('refund_schema_failed');
        foreach (['return_refunds','return_refund_attempts'] as $table) {
            $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$p.$table),ARRAY_A);
            if (($status['Engine']??'')!=='InnoDB') throw new PersistenceException('refund_requires_innodb');
        }
    }
}
