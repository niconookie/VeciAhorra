<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\OrderSchema;

final class AddStoreFulfillmentState
{
    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $builder = TableBuilder::make($wpdb->prefix . \VeciAhorra\Core\Config::TABLE_PREFIX . 'orders');
        (new OrderSchema())->define($builder);
        dbDelta($builder->build($wpdb->get_charset_collate()));
    }
}
