<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Application;

final readonly class StartStoreOnboardingResult
{
    public function __construct(
        public string $publicId,
        public string $status,
        public string $createdAt,
        public string $updatedAt
    ) {}
}
