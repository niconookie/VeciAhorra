<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use VeciAhorra\Database\Builder\TableBuilder;

use VeciAhorra\Core\Config;



/**
 * Instala y actualiza la base de datos del plugin.
 */
final class Installer
{
    /**
     * Ejecuta la instalación.
     */

public static function install(): void
{
    global $wpdb;

    if (! MigrationManager::needsMigration()) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    foreach (Schema::tables() as $definition) {

        /*
         * Nombre físico de la tabla
         */
        $tableName =
    $wpdb->prefix .
    Config::TABLE_PREFIX .
    $definition->name();

        /*
         * Construcción de la tabla
         */
        $builder = TableBuilder::make($tableName);

        $definition->define($builder);

        /*
         * SQL generado
         */
        $charset = $wpdb->get_charset_collate();

$sql = $builder->build($charset);

dbDelta($sql);
    }

    MigrationManager::migrate();

    MigrationManager::updateVersion();
}

}
