<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationCohort;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$vectors = [
    [1, '4828b6ff68e98ce830cd7a8c6bde59b6f1d84a714c9622cca589ae8306d1f77c', 4729014, 14],
    [2, 'ae72ac374c43137f57c7c5785f913b22ab0de6a4ed04f0485c15c88fe0a69981', 11432620, 20],
    [17, '6df8a5df638e65213111ef61ad2ee4f3e1895ee935c0b6783ee4bf884ea2af0e', 7207077, 77],
    [31, 'caed22379ae6cefcb03a00a0044b952b41b0365c4614a578caf4ddb77aaba27e', 13298978, 78],
    [100, 'da75668e1f1e82fdbdfbf017be4283bbe00b6e5b307ca1fe2692490f94bffac3', 14316902, 2],
    [999999, '588b7ef378586e8785447e810134d300caf055bb92dc3f9120b61ae8c6f3a649', 5802878, 78],
];

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;

    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$evaluate = static function (array $vector) use ($assert): void {
    [$subjectId, $expectedDigest, $expectedValue, $expectedBucket] = $vector;
    $input = sprintf(
        'veciahorra|durable-retry|initial-transfer|cohort|v1|stage=reconciliation|subject_id=%d',
        $subjectId
    );
    $digest = hash('sha256', $input);
    $binary = hex2bin(substr($digest, 0, 6));
    $value = (ord($binary[0]) << 16) | (ord($binary[1]) << 8) | ord($binary[2]);

    $assert($digest === $expectedDigest, 'The normative SHA-256 digest changed.');
    $assert($value === $expectedValue, 'The normative unsigned 24-bit value changed.');
    $assert($value % 100 === $expectedBucket, 'The normative vector bucket changed.');
    $assert(
        DurableRetryActivationCohort::bucket(
            DurableRetryAuthorityIdentity::reconciliation($subjectId)
        ) === $expectedBucket,
        'The production cohort must match the independent normative vector.'
    );
};

foreach ($vectors as $vector) {
    $evaluate($vector);
}

foreach (array_reverse($vectors) as $vector) {
    $evaluate($vector);
}

$maximumInput = 'veciahorra|durable-retry|initial-transfer|cohort|v1|'
    . 'stage=reconciliation|subject_id=' . PHP_INT_MAX;
$maximumDigest = hash('sha256', $maximumInput, true);
$maximumValue = (ord($maximumDigest[0]) << 16)
    | (ord($maximumDigest[1]) << 8)
    | ord($maximumDigest[2]);
$assert(
    DurableRetryActivationCohort::bucket(
        DurableRetryAuthorityIdentity::reconciliation(PHP_INT_MAX)
    ) === $maximumValue % 100,
    'PHP_INT_MAX must retain canonical unsigned decimal representation.'
);

echo sprintf("OK durable retry activation flag policy vectors (%d assertions)\n", $assertions);
