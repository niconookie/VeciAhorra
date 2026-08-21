<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Support;

use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingInputException;

final class ChileanRutNormalizer
{
    public function normalizeAndValidate(string $rut): string
    {
        $compact = strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
        if (preg_match('/\A([0-9]{7,8})([0-9K])\z/', $compact, $matches) !== 1) {
            throw new OnboardingInputException('invalid_rut');
        }
        $body = $matches[1];
        if ($this->verificationDigit($body) !== $matches[2]) {
            throw new OnboardingInputException('invalid_rut');
        }
        return $body . '-' . $matches[2];
    }

    private function verificationDigit(string $body): string
    {
        $sum = 0;
        $factor = 2;
        for ($index = strlen($body) - 1; $index >= 0; $index--) {
            $sum += ((int) $body[$index]) * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        return match (11 - ($sum % 11)) {
            11 => '0',
            10 => 'K',
            default => (string) (11 - ($sum % 11)),
        };
    }
}
