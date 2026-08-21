<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Support;

use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingInputException;

final class OnboardingEmailNormalizer
{
    public function normalize(string $email): string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || strlen($normalized) > 190 || preg_match('/[^\x20-\x7E]/', $normalized) === 1) {
            throw new OnboardingInputException('invalid_email');
        }
        $sanitized = sanitize_email($normalized);
        if ($sanitized !== $normalized || is_email($sanitized) === false) {
            throw new OnboardingInputException('invalid_email');
        }
        return $sanitized;
    }
}
