<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final readonly class PublicOnboardingResponse
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        public int $httpStatus,
        public string $kind,
        public ?string $publicId = null,
        public array $fieldErrors = [],
        public ?int $retryAfter = null,
        public bool $reuseIdempotencyKey = false
    ) {}
}
