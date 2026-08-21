<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class PublicOnboardingController
{
    public function __construct(
        private PublicRequestGuard $guard,
        private PublicOnboardingRequestFactory $requests,
        private PublicOnboardingHandler $handler,
        private RemoteAddressResolver $addresses,
        private PublicOnboardingErrorTranslator $errors,
        private PublicOnboardingPageState $state,
        private OnboardingLegalAuthorityValidator $legalAuthority
    ) {}

    public function handle(): void
    {
        $correctPage = $this->isOnboardingPage();
        if (! $correctPage) return;
        $this->noStoreHeaders();
        do_action('litespeed_control_set_nocache', 'VeciAhorra minimarket onboarding');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
        $request = null;
        try {
            [$rawBody, $completeBody] = $this->readRawBody();
            $this->guard->assertAllowed(true, $_SERVER, $_POST, home_url('/'), $rawBody, $completeBody);
            $request = $this->requests->fromPost($_POST);
            $response = $this->handler->handle($request, $this->addresses->resolve($_SERVER));
        } catch (\Throwable $exception) {
            $response = $this->errors->translate($exception);
        }
        $this->state->set($response, $request);
        status_header($response->httpStatus);
        if ($response->retryAfter !== null) header('Retry-After: ' . max(1, $response->retryAfter));
    }

    public function noStoreHeaders(): void
    {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0', true);
        header('Pragma: no-cache', true);
        header('Expires: 0', true);
        header('X-Content-Type-Options: nosniff', false);
        header('Referrer-Policy: same-origin', true);
    }

    private function isOnboardingPage(): bool
    {
        $page = get_queried_object();
        return $this->legalAuthority->isAuthorizedRegistrationPage($page);
    }

    /** @return array{string,bool} */
    private function readRawBody(): array
    {
        $stream = fopen('php://input', 'rb');
        if (! is_resource($stream)) return ['', false];
        $body = '';
        while (strlen($body) <= PublicRequestGuard::MAX_BODY_BYTES && ! feof($stream)) {
            $chunk = fread($stream, PublicRequestGuard::MAX_BODY_BYTES + 1 - strlen($body));
            if (! is_string($chunk)) { fclose($stream); return [$body, false]; }
            $body .= $chunk;
        }
        $complete = feof($stream);
        fclose($stream);
        return [$body, $complete];
    }
}
