<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Tables;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class ZonalAdminServiceZonesTable implements TableInterface
{
    public function name(): string { return 'zonal_admin_service_zones'; }

    public function define(TableBuilder $table): void
    {
        $table->id()
            ->bigIntegerUnsigned('user_id')
            ->bigIntegerUnsigned('service_zone_id')
            ->datetime('created_at')
            ->bigIntegerUnsigned('created_by')
            ->unique(['user_id', 'service_zone_id'], 'zonal_admin_service_zones_unique')
            ->index('user_id', 'zonal_admin_service_zones_user_index')
            ->index('service_zone_id', 'zonal_admin_service_zones_zone_index');
    }
}
