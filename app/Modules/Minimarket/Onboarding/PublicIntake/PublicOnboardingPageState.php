<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class PublicOnboardingPageState
{
    private ?PublicOnboardingResponse $response = null;
    private ?PublicOnboardingRequest $request = null;

    public function set(PublicOnboardingResponse $response, ?PublicOnboardingRequest $request = null): void
    {
        $this->response = $response;
        $this->request = $request;
    }

    public function response(): ?PublicOnboardingResponse { return $this->response; }
    public function request(): ?PublicOnboardingRequest { return $this->request; }
}
