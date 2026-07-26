<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Exceptions;

use RuntimeException;

final class OrderAdminReadException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(match ($errorCode) {
            'not_found' => 'La Order solicitada no existe.',
            'count_failed' => 'No fue posible contar las Orders.',
            'list_failed' => 'No fue posible consultar las Orders.',
            'optional_read_failed' => 'Una autoridad operacional no pudo leerse.',
            default => 'No fue posible construir la lectura operacional.',
        });
    }
}
