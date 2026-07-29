<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationSourceInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationCohort;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfiguration;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationPolicyException;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class MutableDurableRetryActivationConfigurationSource implements DurableRetryActivationConfigurationSourceInterface
{
    public int $calls = 0;

    public function __construct(
        private DurableRetryActivationConfiguration $configuration
    ) {
    }

    public function replace(DurableRetryActivationConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function snapshot(): DurableRetryActivationConfiguration
    {
        ++$this->calls;

        return $this->configuration;
    }
}

final class InvalidDurableRetryActivationConfigurationSource implements DurableRetryActivationConfigurationSourceInterface
{
    public function snapshot(): DurableRetryActivationConfiguration
    {
        return 'invalid';
    }
}

final class FailingDurableRetryActivationConfigurationSource implements DurableRetryActivationConfigurationSourceInterface
{
    public function snapshot(): DurableRetryActivationConfiguration
    {
        throw new RuntimeException('source failure');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;

    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectPolicyException = static function (
    callable $operation,
    string $code,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (DurableRetryActivationPolicyException $exception) {
        $assert($exception->reasonCode() === $code, 'Unexpected activation policy exception code.');
        $assert($exception->getMessage() === $message, 'Unexpected activation policy exception message.');

        return;
    }

    throw new RuntimeException('Expected a durable retry activation policy exception.');
};

$disabled = DurableRetryActivationConfiguration::disabled();
$assert($disabled->stage() === 'reconciliation', 'Disabled configuration must target reconciliation.');
$assert($disabled->percentage() === 0, 'Disabled configuration must use zero percent.');
$assert(
    $disabled->algorithmVersion() === DurableRetryActivationCohort::ALGORITHM_VERSION,
    'Disabled configuration must use the normative algorithm version.'
);
$assert($disabled->isDisabled(), 'Disabled configuration must report disabled.');
$assert(!$disabled->isFullyEnabled(), 'Disabled configuration must not report fully enabled.');

for ($percentage = 0; $percentage <= 100; ++$percentage) {
    $configuration = DurableRetryActivationConfiguration::reconciliation(
        $percentage,
        DurableRetryActivationCohort::ALGORITHM_VERSION
    );
    $assert($configuration->percentage() === $percentage, 'Configuration must retain every valid percentage.');
}

foreach ([-1, 101] as $invalidPercentage) {
    $expectPolicyException(
        static fn (): DurableRetryActivationConfiguration => DurableRetryActivationConfiguration::reconciliation(
            $invalidPercentage,
            DurableRetryActivationCohort::ALGORITHM_VERSION
        ),
        DurableRetryActivationPolicyException::INVALID_PERCENTAGE,
        'Invalid durable retry activation percentage.'
    );
}

foreach ([1.0, '1', '1e2', ' 1 ', true, null, [], new stdClass()] as $invalidPercentage) {
    try {
        DurableRetryActivationConfiguration::reconciliation(
            $invalidPercentage,
            DurableRetryActivationCohort::ALGORITHM_VERSION
        );
    } catch (TypeError) {
        $assert(true, 'Invalid percentage types must fail closed with TypeError.');

        continue;
    }

    throw new RuntimeException('An invalid percentage type was accepted.');
}

foreach (['', 'unknown', 'SHA256-24BIT-MOD100-V1', ' sha256-24bit-mod100-v1'] as $invalidVersion) {
    $expectPolicyException(
        static fn (): DurableRetryActivationConfiguration => DurableRetryActivationConfiguration::reconciliation(
            1,
            $invalidVersion
        ),
        DurableRetryActivationPolicyException::UNSUPPORTED_ALGORITHM_VERSION,
        'Unsupported durable retry activation algorithm version.'
    );
}

$identity = DurableRetryAuthorityIdentity::reconciliation(17);
$source = new MutableDurableRetryActivationConfigurationSource($disabled);
$policy = new DurableRetryDeterministicActivationPolicy($source);
$assert(!$policy->allowsInitialTransfer($identity), 'Zero percent must deny initial transfer.');
$assert($source->calls === 1, 'The policy must read exactly one snapshot per decision.');

$source->replace(DurableRetryActivationConfiguration::reconciliation(
    100,
    DurableRetryActivationCohort::ALGORITHM_VERSION
));
$assert($policy->allowsInitialTransfer($identity), 'One hundred percent must allow initial transfer.');
$assert($source->calls === 2, 'A later decision must read one fresh snapshot.');

try {
    (new DurableRetryDeterministicActivationPolicy(
        new InvalidDurableRetryActivationConfigurationSource()
    ))->allowsInitialTransfer($identity);
} catch (TypeError) {
    $assert(true, 'A source return-type violation must propagate as TypeError.');
}

try {
    (new DurableRetryDeterministicActivationPolicy(
        new FailingDurableRetryActivationConfigurationSource()
    ))->allowsInitialTransfer($identity);
} catch (RuntimeException $exception) {
    $assert($exception->getMessage() === 'source failure', 'Source errors must propagate unchanged.');
}

$bucket = DurableRetryActivationCohort::bucket($identity);
$source->replace(DurableRetryActivationConfiguration::reconciliation(
    $bucket,
    DurableRetryActivationCohort::ALGORITHM_VERSION
));
$assert(!$policy->allowsInitialTransfer($identity), 'A bucket equal to the percentage must be denied.');

$source->replace(DurableRetryActivationConfiguration::reconciliation(
    $bucket + 1,
    DurableRetryActivationCohort::ALGORITHM_VERSION
));
$assert($policy->allowsInitialTransfer($identity), 'A bucket below the percentage must be allowed.');
$assert($policy->allowsInitialTransfer(DurableRetryAuthorityIdentity::reconciliation(17)), 'Equal identities must produce equal decisions.');

$source->replace(DurableRetryActivationConfiguration::reconciliation(
    $bucket + 1,
    DurableRetryActivationCohort::ALGORITHM_VERSION
));
$assert($policy->allowsInitialTransfer($identity), 'Equivalent snapshots must produce equal decisions.');

$previous = false;
for ($percentage = 0; $percentage <= 100; ++$percentage) {
    $source->replace(DurableRetryActivationConfiguration::reconciliation(
        $percentage,
        DurableRetryActivationCohort::ALGORITHM_VERSION
    ));
    $current = $policy->allowsInitialTransfer($identity);
    $assert(!$previous || $current, 'Activation decisions must be monotonic as percentage increases.');
    $previous = $current;
}

$catalog = [
    [
        DurableRetryActivationPolicyException::forCode(DurableRetryActivationPolicyException::INVALID_PERCENTAGE),
        DurableRetryActivationPolicyException::INVALID_PERCENTAGE,
        'Invalid durable retry activation percentage.',
    ],
    [
        DurableRetryActivationPolicyException::forCode(DurableRetryActivationPolicyException::UNSUPPORTED_STAGE),
        DurableRetryActivationPolicyException::UNSUPPORTED_STAGE,
        'Unsupported durable retry activation stage.',
    ],
    [
        DurableRetryActivationPolicyException::forCode(
            DurableRetryActivationPolicyException::UNSUPPORTED_ALGORITHM_VERSION
        ),
        DurableRetryActivationPolicyException::UNSUPPORTED_ALGORITHM_VERSION,
        'Unsupported durable retry activation algorithm version.',
    ],
    [
        DurableRetryActivationPolicyException::forCode(
            DurableRetryActivationPolicyException::INVALID_CONFIGURATION_SNAPSHOT
        ),
        DurableRetryActivationPolicyException::INVALID_CONFIGURATION_SNAPSHOT,
        'Invalid durable retry activation configuration snapshot.',
    ],
];

foreach ($catalog as [$exception, $code, $message]) {
    $assert($exception->reasonCode() === $code, 'The exception catalog must expose stable codes.');
    $assert($exception->getMessage() === $message, 'The exception catalog must expose stable messages.');
}

try {
    DurableRetryActivationPolicyException::forCode('unknown');
} catch (InvalidArgumentException $exception) {
    $assert(
        $exception->getMessage() === 'Invalid durable retry activation policy exception code.',
        'Unknown exception codes must fail with the normative message.'
    );
}

echo sprintf("OK durable retry activation flag policy (%d assertions)\n", $assertions);
