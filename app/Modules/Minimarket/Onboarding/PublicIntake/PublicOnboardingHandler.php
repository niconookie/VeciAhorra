<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Modules\Minimarket\Onboarding\Application\StartStoreOnboarding;
use VeciAhorra\Modules\Minimarket\Onboarding\Application\StartStoreOnboardingCommand;

final class PublicOnboardingHandler
{
    public function __construct(
        private StartStoreOnboarding $start,
        private RateLimitIdentityFactory $identities,
        private PublicOnboardingRateLimiter $rateLimiter,
        private PublicOnboardingErrorTranslator $errors
    ) {}

    public function handle(PublicOnboardingRequest $request, PublicClientAddress $client): PublicOnboardingResponse
    {
        try {
            try {
                $identity = $this->identities->fromRaw($request->accountEmail, $request->ownerRut);
            } catch (\Throwable $validation) {
                $decision = $this->rateLimiter->consume($client, null, '');
                if (! $decision->allowed) return new PublicOnboardingResponse(429, 'rate_limited', retryAfter: $decision->retryAfter, reuseIdempotencyKey: true);
                return $this->errors->translate($validation);
            }
            $decision = $this->rateLimiter->consume($client, $identity, $request->idempotencyKey);
            if (! $decision->allowed) return new PublicOnboardingResponse(429, 'rate_limited', retryAfter: $decision->retryAfter, reuseIdempotencyKey: true);
            $result = $this->start->execute(new StartStoreOnboardingCommand(
                $request->accountEmail,
                $request->ownerRut,
                $request->idempotencyKey,
                $request->termsAccepted
            ));
            return new PublicOnboardingResponse(200, 'accepted', publicId: $result->publicId);
        } catch (\Throwable $exception) {
            return $this->errors->translate($exception);
        }
    }
}
