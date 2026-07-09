<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

/**
 * Define la estructura base de los eventos de seguimiento de entregas.
 */
final class DeliveryTrackingSchema implements TableInterface
{
    public function name(): string
    {
        return 'delivery_tracking';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('delivery_id')
            ->decimal('latitude', 10, 7)
                ->nullable()
            ->decimal('longitude', 10, 7)
                ->nullable()
            ->string('event', 30)
            ->datetime('created_at')
            ->index('delivery_id', 'delivery_tracking_delivery_id_index')
            ->index('event', 'delivery_tracking_event_index')
            ->index('created_at', 'delivery_tracking_created_at_index');
    }
}
