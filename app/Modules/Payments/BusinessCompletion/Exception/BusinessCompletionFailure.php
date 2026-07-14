<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\BusinessCompletion\Exception;

final class BusinessCompletionFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $outcome)
    {
        parent::__construct($reason);
    }
}
