<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Tables;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;
final class ServiceZonesTable implements TableInterface { public function name():string{return 'service_zones';} public function define(TableBuilder $t):void{$t->id()->string('commune',120)->string('name',150)->string('status',20)->default('active')->datetime('created_at')->datetime('updated_at')->index('status','service_zones_status_index')->unique(['commune','name'],'service_zones_commune_name_unique');}}
