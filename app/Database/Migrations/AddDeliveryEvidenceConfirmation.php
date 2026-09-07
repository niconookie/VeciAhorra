<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;
use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

final class AddDeliveryEvidenceConfirmation
{
    public function up(): void
    {
        global $wpdb;
        $table=$wpdb->prefix.Config::TABLE_PREFIX.'delivery_evidence';
        $column=$wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'courier_confirmed_at'",ARRAY_A);
        if(!$column){
            if($wpdb->query("ALTER TABLE {$table} ADD courier_confirmed_at DATETIME NULL DEFAULT NULL")===false)throw new PersistenceException('delivery_confirmation_schema_failed');
            $column=$wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'courier_confirmed_at'",ARRAY_A);
        }
        if(!$column||strtolower($column['Type'])!=='datetime'||$column['Null']!=='YES')throw new PersistenceException('delivery_confirmation_schema_incompatible');
    }
}
