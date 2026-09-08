<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

final class CreatePickupProposals
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix . Config::TABLE_PREFIX;
        foreach (['pickup_latitude' => 'DECIMAL(10,7)', 'pickup_longitude' => 'DECIMAL(11,7)'] as $column => $type) {
            if (!$wpdb->get_row("SHOW COLUMNS FROM {$p}stores LIKE '{$column}'", ARRAY_A)
                && $wpdb->query("ALTER TABLE {$p}stores ADD {$column} {$type} NULL DEFAULT NULL") === false) {
                throw new PersistenceException('pickup_coordinates_schema_failed');
            }
        }
        $sql = "CREATE TABLE IF NOT EXISTS {$p}pickup_proposals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(48) NOT NULL, cart_id BIGINT UNSIGNED NOT NULL,
            cart_version BIGINT UNSIGNED NOT NULL, owner_key CHAR(64) NOT NULL,
            service_zone_id BIGINT UNSIGNED NOT NULL, store_id BIGINT UNSIGNED NOT NULL,
            store_point_hash CHAR(64) NOT NULL, source_hash CHAR(64) NOT NULL,
            offers_json LONGTEXT NOT NULL, presentation_json LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'offered', expires_at DATETIME NOT NULL,
            accepted_key VARCHAR(128) NULL, accepted_fingerprint CHAR(64) NULL,
            accepted_result LONGTEXT NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY pickup_proposal_public (public_id), KEY pickup_cart (cart_id),
            KEY pickup_expiry (status, expires_at)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()}";
        if ($wpdb->query($sql) === false) throw new PersistenceException('pickup_proposals_schema_failed');
    }
}
