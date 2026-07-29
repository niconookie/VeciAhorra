<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Completion\Contracts;

interface FulfillmentCompletionReadAuthorityInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByBusinessCompletion(int $businessCompletionId): ?array;
}
