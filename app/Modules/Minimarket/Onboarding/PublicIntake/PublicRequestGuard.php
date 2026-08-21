<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class PublicRequestGuard
{
    public const MARKER = '1';
    public const NONCE_ACTION = 'veciahorra_minimarket_onboarding';
    public const NONCE_FIELD = '_va_minimarket_onboarding_nonce';
    public const MAX_BODY_BYTES = 8192;

    /** @param array<string, mixed> $server @param array<string, mixed> $post */
    public function assertAllowed(bool $correctPage, array $server, array $post, string $canonicalUrl): void
    {
        if (! $correctPage) throw new PublicIntakeException('wrong_page');
        if (($post['veciahorra_minimarket_onboarding'] ?? null) !== self::MARKER) throw new PublicIntakeException('invalid_marker');
        if (($server['REQUEST_METHOD'] ?? null) !== 'POST') throw new PublicIntakeException('invalid_method');

        $contentType = $server['CONTENT_TYPE'] ?? '';
        if (! is_string($contentType) || strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/x-www-form-urlencoded') {
            throw new PublicIntakeException('invalid_content_type');
        }
        $contentLength = $server['CONTENT_LENGTH'] ?? null;
        if ($contentLength !== null && (! is_string($contentLength) || ! ctype_digit($contentLength) || (int) $contentLength > self::MAX_BODY_BYTES)) {
            throw new PublicIntakeException('payload_too_large');
        }
        if (strlen(http_build_query($post, '', '&', PHP_QUERY_RFC3986)) > self::MAX_BODY_BYTES) {
            throw new PublicIntakeException('payload_too_large');
        }
        $nonce = $post[self::NONCE_FIELD] ?? null;
        if (! is_string($nonce) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), self::NONCE_ACTION)) {
            throw new PublicIntakeException('invalid_csrf');
        }

        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && trim($origin) !== '') {
            if (! $this->sameOrigin($origin, $canonicalUrl)) throw new PublicIntakeException('invalid_csrf');
            return;
        }
        $referer = $server['HTTP_REFERER'] ?? null;
        if (! is_string($referer) || trim($referer) === '' || ! $this->sameOrigin($referer, $canonicalUrl)) {
            throw new PublicIntakeException('invalid_csrf');
        }
    }

    private function sameOrigin(string $candidate, string $authority): bool
    {
        $left = wp_parse_url($candidate);
        $right = wp_parse_url($authority);
        if (! is_array($left) || ! is_array($right)) return false;
        foreach (['scheme', 'host'] as $part) {
            if (! isset($left[$part], $right[$part]) || strtolower((string) $left[$part]) !== strtolower((string) $right[$part])) return false;
        }
        return $this->port($left) === $this->port($right);
    }

    /** @param array<string, mixed> $url */
    private function port(array $url): int
    {
        if (isset($url['port'])) return (int) $url['port'];
        return strtolower((string) ($url['scheme'] ?? '')) === 'https' ? 443 : 80;
    }
}
