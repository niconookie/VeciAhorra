<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;
use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
final class CreateDeliveryReturns
{
    public function up():void
    {
        global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;$charset=$wpdb->get_charset_collate();
        if($wpdb->query("CREATE TABLE IF NOT EXISTS {$p}delivery_returns (
            delivery_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,store_id BIGINT UNSIGNED NOT NULL,courier_id BIGINT UNSIGNED NOT NULL,
            opened_version BIGINT UNSIGNED NOT NULL,opened_by BIGINT UNSIGNED NOT NULL,opened_code VARCHAR(30) NOT NULL,opened_note VARCHAR(500) NOT NULL,opened_at DATETIME NOT NULL,
            received_version BIGINT UNSIGNED NULL,received_by BIGINT UNSIGNED NULL,received_code VARCHAR(30) NULL,received_note VARCHAR(500) NULL,received_at DATETIME NULL,
            UNIQUE KEY return_order (order_id),KEY return_store (store_id,received_at)
        ) ENGINE=InnoDB {$charset}")===false)throw new PersistenceException('return_schema_failed');
        if(!$wpdb->get_row("SHOW COLUMNS FROM {$p}delivery_otps LIKE 'invalidated_at'",ARRAY_A)&&$wpdb->query("ALTER TABLE {$p}delivery_otps ADD invalidated_at DATETIME NULL DEFAULT NULL")===false)throw new PersistenceException('return_schema_failed');
        $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$p.'delivery_returns'),ARRAY_A);
        if(($status['Engine']??'')!=='InnoDB')throw new PersistenceException('return_requires_innodb');
    }
}
