<?php

declare(strict_types=1);

namespace VeciAhorra\Exceptions;

use RuntimeException;

/**
 * Indica que el registro solicitado no existe.
 */
final class RecordNotFoundException extends RuntimeException
{
}
