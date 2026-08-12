<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Completion\Contracts;

interface DeliveryCompletionReadAuthorityInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByBusinessCompletion(int $businessCompletionId): ?array;
}
