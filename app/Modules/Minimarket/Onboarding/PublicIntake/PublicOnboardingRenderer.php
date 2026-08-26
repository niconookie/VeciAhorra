<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Core\LaunchGate;

final class PublicOnboardingRenderer
{
    public function __construct(
        private IdempotencyKeyIssuer $keys,
        private OnboardingLegalLinkProvider $legalLinks,
        private PublicOnboardingPageState $state,
        private PublicOnboardingAssets $assets,
        private OnboardingLegalAuthorityValidator $legalAuthority,
        private ?LaunchGate $launchGate = null
    ) { $this->launchGate ??= new LaunchGate(); }

    public function render(): string
    {
        if (! $this->legalAuthority->isAuthorizedRegistrationPage(get_queried_object())) return '';
        $this->assets->enqueue();
        if (! $this->launchGate->registrationEnabled()) {
            return '<section class="va-onboarding"><div class="va-onboarding__card" role="status"><h1>Registro de minimarket</h1><p>' . esc_html(LaunchGate::REGISTRATION_MESSAGE) . '</p><a href="' . esc_url(wp_login_url()) . '">Iniciar sesi&oacute;n</a></div></section>';
        }
        $response = $this->state->response();
        try {
            $legal = $this->legalLinks->links();
        } catch (\Throwable) {
            status_header(503);
            return '<section class="va-onboarding"><div class="va-onboarding__card" role="alert"><h1>Registro de minimarket</h1><p>No pudimos habilitar el formulario en este momento. Intenta nuevamente más tarde.</p></div></section>';
        }
        $request = $this->state->request();
        $key = $request !== null && $response?->reuseIdempotencyKey ? $request->idempotencyKey : $this->keys->issue();
        ob_start();
        require __DIR__ . '/Views/form.php';
        return (string) ob_get_clean();
    }
}
