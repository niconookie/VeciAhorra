<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationPolicyException;

final class DurableRetryActivationCohort
{
    public const ALGORITHM_VERSION = 'sha256-24bit-mod100-v1';
    public const BUCKET_COUNT = 100;

    public static function bucket(
        DurableRetryAuthorityIdentity $identity
    ): int {
        if ($identity->stage() !== DurableRetryStage::RECONCILIATION) {
            throw DurableRetryActivationPolicyException::forCode(
                DurableRetryActivationPolicyException::UNSUPPORTED_STAGE
            );
        }

        $input = 'veciahorra|durable-retry|initial-transfer|cohort|v1|'
            . 'stage=reconciliation|subject_id=' . $identity->subjectId();
        $digest = hash('sha256', $input, true);
        $value = (ord($digest[0]) * 65536)
            + (ord($digest[1]) * 256)
            + ord($digest[2]);

        return $value % self::BUCKET_COUNT;
    }

    private function __construct()
    {
    }
}
