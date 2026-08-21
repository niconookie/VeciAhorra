<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingIntentClassifier;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;

final class RepositoryOnboardingIntentClassifier implements OnboardingIntentClassifier
{
    public function __construct(private StoreOnboardingApplicationRepository $repository) {}

    public function classify(string $idempotencyHash, string $accountEmail, string $ownerRutNormalized, string $termsVersion): string
    {
        $classification = $this->repository->classify($idempotencyHash, $accountEmail, $ownerRutNormalized, $termsVersion);
        if (! in_array($classification, [self::NEW, self::COMPATIBLE_REPLAY, self::CONFLICT], true)) {
            throw new PublicIntakeException('rate_limit_unavailable');
        }
        return $classification;
    }
}
