<?php
declare(strict_types=1);

use VeciAhorra\Core\Session;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\ServiceZoneRepository;

function sectorizationFixtureClearCurrent(): void
{
    wp_set_current_user(0);
    Session::forget('veciahorra_service_zone_id');
}

/** @param list<int> $storeIds */
function sectorizationFixtureSelect(array $storeIds, string $identity): int
{
    $name = 'Fixture ' . sanitize_key($identity);
    $zoneId = (new ServiceZoneRepository())->save([
        'commune' => 'Test', 'name' => $name, 'status' => 'active',
        'stores' => array_values(array_unique(array_map('intval', $storeIds))),
    ], 0);
    sectorizationFixtureClearCurrent();
    (new CurrentSector())->set($zoneId);
    if ((new CurrentSector())->id() !== $zoneId) throw new RuntimeException('No se pudo seleccionar la zona fixture.');
    return $zoneId;
}
