<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfigurationValue;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationConfigurationSourceException;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\WordPressOptionDurableRetryActivationConfigurationValueReader;

$GLOBALS['a21_option_calls'] = [];
$GLOBALS['a21_option_result'] = null;
$GLOBALS['a21_option_absent'] = false;
$GLOBALS['a21_option_failure'] = null;

function get_option(string $name, mixed $default = false): mixed
{
    $GLOBALS['a21_option_calls'][] = [$name, $default];
    if ($GLOBALS['a21_option_failure'] instanceof Throwable) {
        throw $GLOBALS['a21_option_failure'];
    }
    if ($GLOBALS['a21_option_absent']) {
        return $default;
    }

    return $GLOBALS['a21_option_result'];
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$reset = static function (mixed $result, bool $absent = false): void {
    $GLOBALS['a21_option_calls'] = [];
    $GLOBALS['a21_option_result'] = $result;
    $GLOBALS['a21_option_absent'] = $absent;
    $GLOBALS['a21_option_failure'] = null;
};

$assert(
    WordPressOptionDurableRetryActivationConfigurationValueReader::OPTION_NAME ===
        'veciahorra_durable_retry_activation_reconciliation_percentage',
    'Option name must be exact.'
);
$reader = new WordPressOptionDurableRetryActivationConfigurationValueReader();
$assert($GLOBALS['a21_option_calls'] === [], 'Constructor must not read the option.');

$reset(null, true);
$value = $reader->read();
$assert(! $value->isPresent(), 'Exact sentinel must represent absence.');
$assert(count($GLOBALS['a21_option_calls']) === 1, 'Reader must call get_option once.');
$assert(
    $GLOBALS['a21_option_calls'][0][0] ===
        WordPressOptionDurableRetryActivationConfigurationValueReader::OPTION_NAME,
    'Reader must use the exact option key.'
);
$assert(
    is_object($GLOBALS['a21_option_calls'][0][1]),
    'Reader must pass an object sentinel.'
);

foreach ([null, 0, '0', false, 1.0, [], new stdClass()] as $raw) {
    $reset($raw);
    $value = $reader->read();
    $assert($value->isPresent(), 'Every non-sentinel result must be present.');
    $assert($value->value() === $raw, 'Reader must preserve exact type and value.');
    $assert(count($GLOBALS['a21_option_calls']) === 1, 'Reader must perform one call.');
}

$reset(0);
$source = new DurableRetryProductionActivationConfigurationSource($reader);
$assert($source->snapshot()->percentage() === 0, 'Present integer zero must be valid.');
$assert(count($GLOBALS['a21_option_calls']) === 1, 'Snapshot must perform one option read.');
$GLOBALS['a21_option_result'] = '100';
$assert($source->snapshot()->percentage() === 100, 'Next snapshot must observe a change.');
$assert(count($GLOBALS['a21_option_calls']) === 2, 'Two snapshots must perform two reads.');

$failure = new RuntimeException('filter failure');
$reset(null);
$GLOBALS['a21_option_failure'] = $failure;
try {
    $source->snapshot();
} catch (DurableRetryActivationConfigurationSourceException $exception) {
    $assert(
        $exception->reasonCode() ===
            DurableRetryActivationConfigurationSourceException::SOURCE_UNAVAILABLE,
        'get_option failure must become SOURCE_UNAVAILABLE.'
    );
    $assert($exception->getPrevious() === $failure, 'get_option cause must be preserved.');
}

$root = dirname(__DIR__, 2);
$autoload = var_export($root . '/vendor/autoload.php', true);
$code = <<<'PHP'
require_once %s;
$reader = new \VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\WordPressOptionDurableRetryActivationConfigurationValueReader();
$source = new \VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource($reader);
try {
    $source->snapshot();
} catch (\VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationConfigurationSourceException $exception) {
    echo $exception->reasonCode(), '|', $exception->getMessage(), '|',
        $exception->getPrevious()?->getMessage();
}
PHP;
$script = tempnam(sys_get_temp_dir(), 'veciahorra-a21-');
if ($script === false) {
    throw new RuntimeException('Unable to create isolated PHP script.');
}
file_put_contents($script, "<?php\n" . sprintf($code, $autoload));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
unlink($script);
$assert($exitCode === 0, 'Isolated PHP process must complete.');
$assert(
    implode("\n", $output) ===
        'activation_configuration_source_unavailable|' .
        'Durable retry activation configuration source is unavailable.|' .
        'WordPress option API is unavailable.',
    'Missing WordPress API must preserve the normative cause.'
);

echo "OK durable retry activation configuration WordPress ({$assertions} assertions)\n";
