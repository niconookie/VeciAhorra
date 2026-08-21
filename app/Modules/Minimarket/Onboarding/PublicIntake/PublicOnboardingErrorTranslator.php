<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingConflictException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingInputException;
use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingPersistenceException;

final class PublicOnboardingErrorTranslator
{
    public function translate(\Throwable $exception): PublicOnboardingResponse
    {
        if ($exception instanceof OnboardingInputException) {
            return match ($exception->reason()) {
                'invalid_email' => new PublicOnboardingResponse(422, 'validation_error', fieldErrors: ['account_email' => 'Ingresa un correo electrónico válido.'], reuseIdempotencyKey: true),
                'invalid_rut' => new PublicOnboardingResponse(422, 'validation_error', fieldErrors: ['owner_rut' => 'Ingresa un RUT válido.'], reuseIdempotencyKey: true),
                'terms_not_accepted' => new PublicOnboardingResponse(422, 'validation_error', fieldErrors: ['terms_accepted' => 'Debes aceptar los Términos y la Política de Privacidad.'], reuseIdempotencyKey: true),
                'terms_version_unavailable' => new PublicOnboardingResponse(503, 'unavailable', reuseIdempotencyKey: true),
                default => new PublicOnboardingResponse(403, 'rejected'),
            };
        }
        if ($exception instanceof OnboardingConflictException) return new PublicOnboardingResponse(409, 'conflict');
        if ($exception instanceof OnboardingPersistenceException) {
            return new PublicOnboardingResponse(503, 'unavailable', reuseIdempotencyKey: $exception->reason() === 'outcome_uncertain');
        }
        if ($exception instanceof PublicIntakeException) {
            return match ($exception->reason()) {
                'invalid_email' => new PublicOnboardingResponse(422, 'validation_error', fieldErrors: ['account_email' => 'Ingresa un correo electrónico válido.'], reuseIdempotencyKey: true),
                'invalid_rut' => new PublicOnboardingResponse(422, 'validation_error', fieldErrors: ['owner_rut' => 'Ingresa un RUT válido.'], reuseIdempotencyKey: true),
                'rate_limit_unavailable', 'terms_version_unavailable' => new PublicOnboardingResponse(503, 'unavailable', reuseIdempotencyKey: true),
                default => new PublicOnboardingResponse(403, 'rejected'),
            };
        }
        return new PublicOnboardingResponse(503, 'unavailable', reuseIdempotencyKey: true);
    }
}
