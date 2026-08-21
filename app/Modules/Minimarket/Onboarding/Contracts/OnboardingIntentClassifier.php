<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Contracts;

interface OnboardingIntentClassifier
{
    public const NEW = 'new';
    public const COMPATIBLE_REPLAY = 'compatible_replay';
    public const CONFLICT = 'conflict';

    public function classify(
        string $idempotencyHash,
        string $accountEmail,
        string $ownerRutNormalized,
        string $termsVersion
    ): string;
}
