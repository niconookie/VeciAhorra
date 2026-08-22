<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Tables;

final class StoreOnboardingEmailVerificationsTable
{
    public function name(): string { return 'store_onboarding_email_verifications'; }

    public function sql(string $physicalName, string $charsetCollate): string
    {
        return "CREATE TABLE {$physicalName} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
application_id BIGINT UNSIGNED NOT NULL,
purpose VARCHAR(32) NOT NULL,
generation INT UNSIGNED NOT NULL DEFAULT 1,
candidate_user_id BIGINT UNSIGNED NULL,
attached_user_id BIGINT UNSIGNED NULL,
email_binding_hash BINARY(32) NOT NULL,
token_hash BINARY(32) NOT NULL,
expires_at DATETIME NOT NULL,
consumed_at DATETIME NULL,
failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
resend_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
last_sent_at DATETIME NULL,
delivery_state VARCHAR(32) NOT NULL,
delivery_attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
last_error_code VARCHAR(64) NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY onboarding_email_verification_application_unique (application_id),
UNIQUE KEY onboarding_email_verification_token_unique (token_hash),
KEY onboarding_email_verification_expiry_index (expires_at, consumed_at),
KEY onboarding_email_verification_delivery_index (delivery_state, updated_at),
KEY onboarding_email_verification_candidate_user_index (candidate_user_id),
KEY onboarding_email_verification_attached_user_index (attached_user_id)
) ENGINE=InnoDB {$charsetCollate};";
    }
}
