<?php
declare(strict_types=1);
$api=file_get_contents(dirname(__DIR__,2).'/assets/admin/js/modules/inventory/api.js');
if(!str_contains($api,'function isInventoryAdminRow')||!str_contains($api,'&& isObservedPrice(row.price)')||!str_contains($api,'&& isObservedInteger(row.stock)'))throw new RuntimeException('La lista administrativa no admite valores observados inválidos.');
$basic=substr($api,strpos($api,'function isInventoryRow'),strpos($api,'function isInventoryAdminRow')-strpos($api,'function isInventoryRow'));
if(!str_contains($basic,'isNonNegativeNumber(row.price)')||!str_contains($basic,'isNonNegativeInteger(row.stock)'))throw new RuntimeException('Se relajó el contrato de escritura/lectura canónica.');
echo "inventory-admin-runtime-contract-test: PASS\n";
