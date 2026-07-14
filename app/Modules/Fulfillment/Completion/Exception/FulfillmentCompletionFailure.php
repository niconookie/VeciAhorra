<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Completion\Exception;

final class FulfillmentCompletionFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $outcome)
    {
        parent::__construct($reason);
    }
}
