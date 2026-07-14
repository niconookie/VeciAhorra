<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Completion\Exception;

final class DeliveryCompletionFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $outcome)
    {
        parent::__construct($reason);
    }
}
