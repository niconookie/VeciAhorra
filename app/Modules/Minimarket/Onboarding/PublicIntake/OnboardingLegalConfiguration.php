<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final readonly class OnboardingLegalConfiguration
{
    public const OPTION = 'veciahorra_minimarket_onboarding_legal';
    public const JOINT_VERSION = 'R1C-LEGAL-2026-07-30-V1';

    public function __construct(
        public string $jointVersion,
        public string $termsDocumentCode,
        public string $termsVersion,
        public string $termsEffectiveDate,
        public int $termsPageId,
        public string $termsContentHash,
        public string $privacyDocumentCode,
        public string $privacyVersion,
        public string $privacyEffectiveDate,
        public int $privacyPageId,
        public string $privacyContentHash
    ) {}

    public static function fromWordPress(): self
    {
        $value = get_option(self::OPTION, null);
        if (! is_array($value)) throw new PublicIntakeException('terms_version_unavailable');
        $keys = ['joint_version','terms_document_code','terms_version','terms_effective_date','terms_page_id','terms_content_hash','privacy_document_code','privacy_version','privacy_effective_date','privacy_page_id','privacy_content_hash'];
        if (array_diff($keys, array_keys($value)) !== [] || array_diff(array_keys($value), $keys) !== []) {
            throw new PublicIntakeException('terms_version_unavailable');
        }
        foreach ($keys as $key) if (! is_scalar($value[$key])) throw new PublicIntakeException('terms_version_unavailable');
        return new self(
            (string) $value['joint_version'], (string) $value['terms_document_code'], (string) $value['terms_version'],
            (string) $value['terms_effective_date'], (int) $value['terms_page_id'], (string) $value['terms_content_hash'],
            (string) $value['privacy_document_code'], (string) $value['privacy_version'], (string) $value['privacy_effective_date'],
            (int) $value['privacy_page_id'], (string) $value['privacy_content_hash']
        );
    }
}
