<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\BusinessCompletion\Contracts;

interface BusinessCompletionReadAuthorityInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByReconciliation(int $reconciliationId): ?array;
}
