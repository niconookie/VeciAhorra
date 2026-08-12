<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Tables;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;
final class StoreServiceZonesTable implements TableInterface { public function name():string{return 'store_service_zones';} public function define(TableBuilder $t):void{$t->id()->bigIntegerUnsigned('store_id')->bigIntegerUnsigned('zone_id')->bigIntegerUnsigned('assigned_by')->datetime('assigned_at')->unique(['zone_id','store_id'],'store_service_zones_unique')->index('store_id','store_service_zones_store_index')->index('zone_id','store_service_zones_zone_index');}}
