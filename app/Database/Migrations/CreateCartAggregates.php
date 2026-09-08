<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;
use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
final class CreateCartAggregates
{
    public function up():void
    {
        global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;$charset=$wpdb->get_charset_collate();
        $sql="CREATE TABLE IF NOT EXISTS {$p}carts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(48) NOT NULL,
            owner_key CHAR(64) NOT NULL, active_owner CHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL, session_id VARCHAR(128) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active', version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            fulfillment_method VARCHAR(20) NOT NULL DEFAULT 'pickup',service_zone_id BIGINT UNSIGNED NULL,
            materialization_key VARCHAR(128) NULL, materialization_fingerprint CHAR(64) NULL,
            materialization_result LONGTEXT NULL, created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,consumed_at DATETIME NULL,
            UNIQUE KEY carts_public (public_id), UNIQUE KEY carts_active_owner (active_owner), KEY carts_owner (owner_key)
        ) ENGINE=InnoDB {$charset}";
        if($wpdb->query($sql)===false)throw new PersistenceException('cart_schema_failed');
        foreach(['cart_items'=>'cart_id','checkouts'=>'source_cart_id'] as $table=>$column){
            if(!$wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE '{$column}'",ARRAY_A)
                &&$wpdb->query("ALTER TABLE {$p}{$table} ADD {$column} BIGINT UNSIGNED NULL DEFAULT NULL, ADD ".($table==='checkouts'?'UNIQUE ':'')."KEY {$table}_aggregate ({$column})")===false)throw new PersistenceException('cart_schema_failed');
        }
        foreach(['carts','cart_items','checkouts'] as $table){$row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$p.$table),ARRAY_A);if(($row['Engine']??null)!=='InnoDB')throw new PersistenceException('cart_requires_innodb');}
    }
}
