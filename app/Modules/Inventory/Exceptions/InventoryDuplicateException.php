<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Exceptions;

use RuntimeException;

/**
 * Colisión durable de la pareja Product + Store.
 */
final class InventoryDuplicateException extends RuntimeException
{
}
