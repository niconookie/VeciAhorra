<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Infrastructure\DurableRetry;

use RuntimeException;
use stdClass;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfigurationValue;

final class WordPressOptionDurableRetryActivationConfigurationValueReader implements DurableRetryActivationConfigurationValueReaderInterface
{
    public const OPTION_NAME =
        'veciahorra_durable_retry_activation_reconciliation_percentage';

    public function __construct()
    {
    }

    public function read(): DurableRetryActivationConfigurationValue
    {
        if (! function_exists('get_option')) {
            throw new RuntimeException(
                'WordPress option API is unavailable.'
            );
        }

        $absentSentinel = new stdClass();
        $raw = get_option(self::OPTION_NAME, $absentSentinel);

        if ($raw === $absentSentinel) {
            return DurableRetryActivationConfigurationValue::absent();
        }

        return DurableRetryActivationConfigurationValue::present($raw);
    }
}
