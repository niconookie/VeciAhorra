<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Operational;

use InvalidArgumentException;

final class OperationalStateCatalog
{
    public const COMMERCIAL = ['reserved', 'payment_pending', 'confirmed', 'expired', 'cancelled', 'fulfilled', 'unknown', 'inconsistent'];
    public const FINANCIAL = ['not_started', 'pending', 'approved', 'rejected', 'failed', 'manual_review', 'unknown', 'inconsistent'];
    public const RESERVATION = ['active', 'consumed', 'expired', 'released', 'mixed', 'missing', 'unknown', 'inconsistent'];
    public const PROCESSING = ['not_required', 'pending', 'processing', 'retry_wait', 'completed', 'failed', 'manual_review', 'unknown', 'inconsistent'];
    public const FULFILLMENT = ['not_started', 'pending', 'in_progress', 'completed', 'failed', 'manual_review', 'unknown', 'inconsistent'];
    public const DELIVERY = ['not_applicable', 'not_started', 'pending', 'assigned', 'picked_up', 'delivered', 'cancelled', 'unknown', 'inconsistent'];
    public const PAYMENT_SESSION = ['absent', 'pending', 'create_processing', 'create_retryable', 'create_ambiguous', 'create_failed', 'ready', 'confirmed', 'expired', 'cancelled', 'unknown'];
    public const PRIMARY = ['inconsistent', 'manual_review', 'failed', 'completed', 'in_fulfillment', 'fulfillment_pending', 'post_payment_processing', 'confirmed', 'payment_rejected', 'payment_in_progress', 'cancelled', 'expired', 'reserved', 'unknown'];
    public const CONSISTENCY = ['consistent', 'warning', 'degraded', 'inconsistent', 'unknown'];
    public const SEVERITY = ['info', 'warning', 'error', 'critical'];

    public static function assert(string $catalog, string $value): string
    {
        $values = constant(self::class . '::' . strtoupper($catalog));
        if (! in_array($value, $values, true)) {
            throw new InvalidArgumentException('El valor no pertenece al catalogo operacional cerrado.');
        }

        return $value;
    }
}
