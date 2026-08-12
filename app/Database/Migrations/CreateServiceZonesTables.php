<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;
use VeciAhorra\Core\Config;use VeciAhorra\Database\Builder\TableBuilder;use VeciAhorra\Database\Contracts\TableInterface;use VeciAhorra\Database\Tables\ServiceZonesTable;use VeciAhorra\Database\Tables\StoreServiceZonesTable;
final class CreateServiceZonesTables { public function up():void{require_once ABSPATH.'wp-admin/includes/upgrade.php';$this->create(new ServiceZonesTable());$this->create(new StoreServiceZonesTable());} private function create(TableInterface $schema):void{global $wpdb;$b=TableBuilder::make($wpdb->prefix.Config::TABLE_PREFIX.$schema->name());$schema->define($b);dbDelta($b->build($wpdb->get_charset_collate()));}}
