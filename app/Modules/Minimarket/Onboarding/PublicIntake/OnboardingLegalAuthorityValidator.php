<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class OnboardingLegalAuthorityValidator
{
    /** @return array{terms_url:string,privacy_url:string} */
    public function validate(OnboardingLegalConfiguration $config): array
    {
        if ($config->jointVersion !== OnboardingLegalConfiguration::JOINT_VERSION
            || $config->termsDocumentCode !== 'V-ES-P-01' || $config->termsVersion !== '01' || $config->termsEffectiveDate !== '2026-07-22'
            || $config->privacyDocumentCode !== 'V-ES-P-02' || $config->privacyVersion !== '01' || $config->privacyEffectiveDate !== '2026-07-30'
            || $config->termsPageId <= 0 || $config->privacyPageId <= 0 || $config->termsPageId === $config->privacyPageId
            || preg_match('/\A[a-f0-9]{64}\z/', $config->termsContentHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $config->privacyContentHash) !== 1
            || $config->registrationPageId <= 0 || preg_match('/\A[a-f0-9]{64}\z/', $config->registrationContentHash) !== 1
            || (int) get_option('wp_page_for_privacy_policy', 0) !== $config->privacyPageId) {
            throw new PublicIntakeException('terms_version_unavailable');
        }
        $terms = get_post($config->termsPageId);
        $privacy = get_post($config->privacyPageId);
        $registration = get_post($config->registrationPageId);
        $this->assertPage($terms, 'terminos-y-condiciones', $config->termsContentHash);
        $this->assertPage($privacy, 'politica-de-privacidad', $config->privacyContentHash);
        $this->assertPage($registration, 'registro-minimarket', $config->registrationContentHash);
        $this->assertUniqueSlug('terminos-y-condiciones', $config->termsPageId);
        $this->assertUniqueSlug('politica-de-privacidad', $config->privacyPageId);
        $this->assertUniqueSlug('registro-minimarket', $config->registrationPageId);
        $termsUrl = get_permalink($config->termsPageId);
        $privacyUrl = get_permalink($config->privacyPageId);
        if (! is_string($termsUrl) || $termsUrl === '' || ! is_string($privacyUrl) || $privacyUrl === '') {
            throw new PublicIntakeException('terms_version_unavailable');
        }
        $registrationUrl = get_permalink($config->registrationPageId);
        if (! is_string($registrationUrl) || $registrationUrl === ''
            || untrailingslashit($registrationUrl) !== untrailingslashit(home_url('/registro-minimarket/'))) {
            throw new PublicIntakeException('terms_version_unavailable');
        }
        return ['terms_url' => $termsUrl, 'privacy_url' => $privacyUrl];
    }

    public function isAuthorizedRegistrationPage(mixed $page): bool
    {
        try {
            $config = OnboardingLegalConfiguration::fromWordPress();
            $this->validate($config);
            return $page instanceof \WP_Post && $page->ID === $config->registrationPageId
                && $page->post_type === 'page' && $page->post_status === 'publish'
                && $page->post_name === 'registro-minimarket'
                && trim($page->post_content) === '[veciahorra_minimarket_onboarding]'
                && hash_equals($config->registrationContentHash, self::contentHash($page->post_content));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function contentHash(string $html): string
    {
        return hash('sha256', trim(str_replace(["\r\n", "\r"], "\n", $html)));
    }

    private function assertPage(mixed $post, string $slug, string $hash): void
    {
        if (! $post instanceof \WP_Post || $post->post_type !== 'page' || $post->post_status !== 'publish'
            || $post->post_name !== $slug || trim($post->post_content) === ''
            || ! hash_equals($hash, self::contentHash($post->post_content))) {
            throw new PublicIntakeException('terms_version_unavailable');
        }
    }

    private function assertUniqueSlug(string $slug, int $expectedId): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_name=%s", $slug));
        if (count($ids) !== 1 || (int) $ids[0] !== $expectedId) throw new PublicIntakeException('terms_version_unavailable');
    }
}
