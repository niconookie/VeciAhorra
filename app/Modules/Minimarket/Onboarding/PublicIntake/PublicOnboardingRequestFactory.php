<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class PublicOnboardingRequestFactory
{
    public const ALLOWED_FIELDS = [
        'veciahorra_minimarket_onboarding', '_va_minimarket_onboarding_nonce',
        'account_email', 'owner_rut', 'terms_accepted', 'idempotency_key',
    ];

    /** @param array<string, mixed> $post */
    public function fromPost(array $post): PublicOnboardingRequest
    {
        foreach ($post as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_FIELDS, true) || (! is_string($value) && ! is_int($value))) {
                throw new PublicIntakeException('invalid_shape');
            }
        }
        foreach (['account_email', 'owner_rut', 'idempotency_key'] as $required) {
            if (! isset($post[$required]) || ! is_string($post[$required])) {
                throw new PublicIntakeException('invalid_shape');
            }
        }

        return new PublicOnboardingRequest(
            wp_unslash($post['account_email']),
            wp_unslash($post['owner_rut']),
            isset($post['terms_accepted']) && is_string($post['terms_accepted']) && wp_unslash($post['terms_accepted']) === '1',
            wp_unslash($post['idempotency_key'])
        );
    }
}
