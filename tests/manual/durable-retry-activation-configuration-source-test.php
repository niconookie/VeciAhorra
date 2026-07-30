<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationCohort;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfigurationValue;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationConfigurationSourceException;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class MutableActivationConfigurationValueReader implements DurableRetryActivationConfigurationValueReaderInterface
{
    public int $calls = 0;

    public function __construct(
        public DurableRetryActivationConfigurationValue $result
    ) {
    }

    public function read(): DurableRetryActivationConfigurationValue
    {
        ++$this->calls;

        return $this->result;
    }
}

final class ThrowingActivationConfigurationValueReader implements DurableRetryActivationConfigurationValueReaderInterface
{
    public int $calls = 0;

    public function __construct(public readonly Throwable $failure)
    {
    }

    public function read(): DurableRetryActivationConfigurationValue
    {
        ++$this->calls;
        throw $this->failure;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$assertConfiguration = static function (
    object $configuration,
    int $percentage
) use ($assert): void {
    $assert($configuration->stage() === 'reconciliation', 'Stage must be reconciliation.');
    $assert($configuration->percentage() === $percentage, 'Percentage must be normalized exactly.');
    $assert(
        $configuration->algorithmVersion() === DurableRetryActivationCohort::ALGORITHM_VERSION,
        'Algorithm version must be the A2 constant.'
    );
};
$expectSourceException = static function (
    callable $operation,
    string $code,
    string $message
) use ($assert): DurableRetryActivationConfigurationSourceException {
    try {
        $operation();
    } catch (DurableRetryActivationConfigurationSourceException $exception) {
        $assert($exception->reasonCode() === $code, 'Unexpected source exception code.');
        $assert($exception->getMessage() === $message, 'Unexpected source exception message.');

        return $exception;
    }

    throw new RuntimeException('Expected source exception.');
};

$absent = DurableRetryActivationConfigurationValue::absent();
$assert(! $absent->isPresent(), 'Absent result must report absence.');
try {
    $absent->value();
} catch (LogicException $exception) {
    $assert(
        $exception->getMessage() ===
            'Absent durable retry activation configuration value has no payload.',
        'Absent payload access must use the normative message.'
    );
}

$validValues = [
    [0, 0],
    [1, 1],
    [50, 50],
    [99, 99],
    [100, 100],
    ['0', 0],
    ['1', 1],
    ['9', 9],
    ['10', 10],
    ['50', 50],
    ['99', 99],
    ['100', 100],
];
foreach ($validValues as [$raw, $expected]) {
    $reader = new MutableActivationConfigurationValueReader(
        DurableRetryActivationConfigurationValue::present($raw)
    );
    $configuration = (new DurableRetryProductionActivationConfigurationSource(
        $reader
    ))->snapshot();
    $assertConfiguration($configuration, $expected);
    $assert($reader->calls === 1, 'A valid snapshot must read exactly once.');
}

$reader = new MutableActivationConfigurationValueReader($absent);
$source = new DurableRetryProductionActivationConfigurationSource($reader);
$first = $source->snapshot();
$assertConfiguration($first, 0);
$assert($reader->calls === 1, 'Absence must read exactly once.');
$reader->result = DurableRetryActivationConfigurationValue::present('100');
$second = $source->snapshot();
$assertConfiguration($second, 100);
$assert($reader->calls === 2, 'A later snapshot must perform one fresh read.');
$assert($first !== $second, 'Each call must return a new snapshot.');

$invalidValues = [
    -1,
    101,
    1.0,
    true,
    false,
    null,
    [],
    new stdClass(),
    '',
    ' ',
    ' 50 ',
    '50 ',
    '+1',
    '-1',
    '-0',
    '00',
    '01',
    '50.0',
    '5e1',
    '0x32',
    '50suffix',
    'prefix50',
    "\t50",
    "50\t",
    "50\n",
    "100\n",
    "50\r",
    '５０',
    '٥٠',
];
$resource = fopen('php://memory', 'r');
$invalidValues[] = $resource;
foreach ($invalidValues as $raw) {
    $reader = new MutableActivationConfigurationValueReader(
        DurableRetryActivationConfigurationValue::present($raw)
    );
    $expectSourceException(
        static fn () => (new DurableRetryProductionActivationConfigurationSource(
            $reader
        ))->snapshot(),
        DurableRetryActivationConfigurationSourceException::INVALID_VALUE,
        'Invalid durable retry activation configuration value.'
    );
    $assert($reader->calls === 1, 'An invalid snapshot must read exactly once.');
}
fclose($resource);

$failure = new RuntimeException('reader failure');
$throwingReader = new ThrowingActivationConfigurationValueReader($failure);
$exception = $expectSourceException(
    static fn () => (new DurableRetryProductionActivationConfigurationSource(
        $throwingReader
    ))->snapshot(),
    DurableRetryActivationConfigurationSourceException::SOURCE_UNAVAILABLE,
    'Durable retry activation configuration source is unavailable.'
);
$assert($exception->getPrevious() === $failure, 'Source failure cause must be preserved.');
$assert($throwingReader->calls === 1, 'A failing reader must be called exactly once.');

foreach ([
    DurableRetryActivationConfigurationSourceException::INVALID_VALUE =>
        'Invalid durable retry activation configuration value.',
    DurableRetryActivationConfigurationSourceException::SOURCE_UNAVAILABLE =>
        'Durable retry activation configuration source is unavailable.',
] as $code => $message) {
    $catalogException =
        DurableRetryActivationConfigurationSourceException::forCode($code);
    $assert($catalogException->reasonCode() === $code, 'Catalog code must be stable.');
    $assert($catalogException->getMessage() === $message, 'Catalog message must be stable.');
}
try {
    DurableRetryActivationConfigurationSourceException::forCode('unknown');
} catch (InvalidArgumentException $exception) {
    $assert(
        $exception->getMessage() ===
            'Invalid durable retry activation configuration source exception code.',
        'Unknown catalog code must use the normative message.'
    );
}

$identity = DurableRetryAuthorityIdentity::reconciliation(17);
$reader = new MutableActivationConfigurationValueReader(
    DurableRetryActivationConfigurationValue::present(0)
);
$policy = new DurableRetryDeterministicActivationPolicy(
    new DurableRetryProductionActivationConfigurationSource($reader)
);
$assert(! $policy->allowsInitialTransfer($identity), 'Zero must deny in A2.');
$reader->result = DurableRetryActivationConfigurationValue::present(100);
$assert($policy->allowsInitialTransfer($identity), 'One hundred must allow in A2.');
$assert($reader->calls === 2, 'A2 must read one source snapshot per decision.');

echo "OK durable retry activation configuration source ({$assertions} assertions)\n";
