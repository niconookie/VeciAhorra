<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryGenerationIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryIndeterminateReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityEntry;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (
    callable $operation,
    ?string $reason,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (DurableRetryActivationContractException $exception) {
        $assert(
            $reason === null || $exception->reasonCode() === $reason,
            $message . ' reason'
        );

        return;
    } catch (TypeError) {
        $assert($reason === null, $message . ' type');

        return;
    }

    $assert(false, $message . ' rejected');
};

$first = DurableRetryAuthorityIdentity::reconciliation(17);
$equivalent = DurableRetryAuthorityIdentity::reconciliation(17);
$different = DurableRetryAuthorityIdentity::reconciliation(18);
$assert($first->stage() === DurableRetryStage::RECONCILIATION, 'canonical stage');
$assert($first->subjectId() === 17, 'subject retained');
$assert($first->equals($equivalent), 'equivalent identities equal');
$assert(! $first->equals($different), 'different subject differs');
$assert($first->diagnosticKey() === 'reconciliation:17', 'stable diagnostic key');

foreach ([0, -1, PHP_INT_MIN] as $invalid) {
    $rejects(
        static fn () => DurableRetryAuthorityIdentity::reconciliation($invalid),
        DurableRetryActivationContractException::INVALID_AUTHORITY_IDENTITY,
        'non-positive subject'
    );
}
foreach (['1', '1e3', ' 1 ', '1x', 1.0, true, null, [], new stdClass()] as $invalid) {
    $rejects(
        static fn () => DurableRetryAuthorityIdentity::reconciliation($invalid),
        null,
        'non-integer subject'
    );
}

$initial = DurableRetryGenerationIdentity::initial($first);
$third = DurableRetryGenerationIdentity::fromAuthority($first, 3);
$assert($initial->authority() === $first, 'generation retains authority');
$assert($initial->stage() === DurableRetryStage::RECONCILIATION, 'generation stage');
$assert($initial->subjectId() === 17, 'generation subject');
$assert($initial->generation() === 1 && $initial->isInitial(), 'initial generation');
$assert($third->generation() === 3 && ! $third->isInitial(), 'later generation');
$assert(
    $initial->equals(DurableRetryGenerationIdentity::initial($equivalent)),
    'generation equality'
);
$assert(! $initial->equals($third), 'generation difference');
$assert(
    $third->diagnosticKey() === 'reconciliation:17:generation:3',
    'generation diagnostic key'
);
foreach ([0, -1] as $generation) {
    $rejects(
        static fn () => DurableRetryGenerationIdentity::fromAuthority(
            $first,
            $generation
        ),
        DurableRetryActivationContractException::INVALID_GENERATION_IDENTITY,
        'invalid generation'
    );
}

$empty = DurableRetryAuthorityIdentityCollection::fromIdentities();
$collection = DurableRetryAuthorityIdentityCollection::fromIdentities(
    $first,
    $different
);
$assert($empty->isEmpty() && count($empty) === 0, 'empty collection valid');
$assert(! $collection->isEmpty() && count($collection) === 2, 'collection count');
$assert($collection->contains($equivalent), 'collection semantic contains');
$assert(
    array_map(
        static fn (DurableRetryAuthorityIdentity $identity): int =>
            $identity->subjectId(),
        iterator_to_array($collection)
    ) === [17, 18],
    'collection order'
);
$rejects(
    static fn () => DurableRetryAuthorityIdentityCollection::fromIdentities(
        $first,
        $equivalent
    ),
    DurableRetryActivationContractException::INVALID_IDENTITY_COLLECTION,
    'duplicates rejected'
);
$maximum = [];
for ($id = 1; $id <= 500; ++$id) {
    $maximum[] = DurableRetryAuthorityIdentity::reconciliation($id);
}
$assert(
    count(DurableRetryAuthorityIdentityCollection::fromIdentities(...$maximum))
        === 500,
    'maximum collection accepted'
);
$maximum[] = DurableRetryAuthorityIdentity::reconciliation(501);
$rejects(
    static fn () => DurableRetryAuthorityIdentityCollection::fromIdentities(
        ...$maximum
    ),
    DurableRetryActivationContractException::INVALID_IDENTITY_COLLECTION,
    'collection over limit'
);

$reasons = [
    DurableRetryIndeterminateReason::QUERY_FAILED,
    DurableRetryIndeterminateReason::INCOMPATIBLE_DURABLE_STATE,
    DurableRetryIndeterminateReason::PERSISTED_DUPLICATE,
    DurableRetryIndeterminateReason::CORRUPT_IDENTITY,
    DurableRetryIndeterminateReason::INCOMPLETE_RESULT,
    DurableRetryIndeterminateReason::UNRESOLVED_RACE,
    DurableRetryIndeterminateReason::CONSISTENCY_ERROR,
];
$assert(DurableRetryIndeterminateReason::all() === $reasons, 'seven reasons exact');
foreach ($reasons as $reason) {
    DurableRetryIndeterminateReason::assert($reason);
    $assert(DurableRetryIndeterminateReason::message($reason) !== '', 'reason message');
}
foreach (['', ' ', 'unknown', 'QUERY_FAILED', 'query_failed '] as $invalidReason) {
    $rejects(
        static fn () => DurableRetryIndeterminateReason::assert($invalidReason),
        DurableRetryActivationContractException::INVALID_AUTHORITY_RESULT,
        'unknown reason'
    );
}

$legacy = DurableRetryLegacyAuthorityResult::legacy();
$durable = DurableRetryLegacyAuthorityResult::durable();
$assert($legacy->state() === 'legacy' && $legacy->reason() === null, 'legacy state');
$assert($legacy->isLegacyAuthorized() && ! $legacy->blocksLegacy(), 'legacy permits');
$assert($durable->state() === 'durable' && $durable->reason() === null, 'durable state');
$assert($durable->isDurable() && $durable->blocksLegacy(), 'durable blocks');
$assert(! $durable->isIndeterminate(), 'durable not indeterminate');
$assert(
    $legacy->diagnosticMessage() === 'Legacy scheduling authority confirmed.',
    'legacy safe message'
);
$assert(
    $durable->diagnosticMessage()
        === 'Durable retry scheduling authority confirmed.',
    'durable safe message'
);
foreach ($reasons as $reason) {
    $result = DurableRetryLegacyAuthorityResult::indeterminate($reason);
    $assert($result->state() === 'indeterminate', 'indeterminate state');
    $assert($result->reason() === $reason, 'indeterminate reason retained');
    $assert($result->isIndeterminate(), 'indeterminate predicate');
    $assert(! $result->isLegacyAuthorized(), 'indeterminate never permits');
    $assert($result->blocksLegacy(), 'indeterminate blocks');
    $assert(
        $result->diagnosticMessage()
            === DurableRetryIndeterminateReason::message($reason),
        'indeterminate message'
    );
}

$complete = DurableRetryLegacyAuthorityBatchResult::fromEntries(
    $collection,
    new DurableRetryLegacyAuthorityEntry($different, $durable),
    new DurableRetryLegacyAuthorityEntry($first, $legacy)
);
$assert(count($complete) === 2 && ! $complete->isEmpty(), 'complete batch');
$assert($complete->requested() === $collection, 'requested retained');
$assert($complete->forIdentity($equivalent) === $legacy, 'semantic lookup');
$assert($complete->forIdentity($different) === $durable, 'second lookup');
$assert(
    array_map(
        static fn (DurableRetryLegacyAuthorityEntry $entry): int =>
            $entry->identity()->subjectId(),
        iterator_to_array($complete)
    ) === [17, 18],
    'batch requested order'
);
$partial = DurableRetryLegacyAuthorityBatchResult::fromEntries(
    $collection,
    new DurableRetryLegacyAuthorityEntry($first, $legacy)
);
$missing = $partial->forIdentity($different);
$assert($missing->isIndeterminate(), 'missing becomes indeterminate');
$assert(
    $missing->reason() === DurableRetryIndeterminateReason::INCOMPLETE_RESULT,
    'missing closed reason'
);
$allFailed = DurableRetryLegacyAuthorityBatchResult::indeterminateAll(
    $collection,
    DurableRetryIndeterminateReason::QUERY_FAILED
);
foreach ($allFailed as $entry) {
    $assert($entry->result()->blocksLegacy(), 'failed batch blocks');
    $assert(
        $entry->result()->reason() === DurableRetryIndeterminateReason::QUERY_FAILED,
        'failed batch reason'
    );
}
$assert(
    DurableRetryLegacyAuthorityBatchResult::fromEntries($empty)->isEmpty(),
    'empty batch'
);
$rejects(
    static fn () => DurableRetryLegacyAuthorityBatchResult::fromEntries(
        $collection,
        new DurableRetryLegacyAuthorityEntry($first, $legacy),
        new DurableRetryLegacyAuthorityEntry($equivalent, $durable)
    ),
    DurableRetryActivationContractException::INVALID_AUTHORITY_BATCH,
    'batch duplicate'
);
$outside = DurableRetryAuthorityIdentity::reconciliation(99);
$rejects(
    static fn () => DurableRetryLegacyAuthorityBatchResult::fromEntries(
        $collection,
        new DurableRetryLegacyAuthorityEntry($outside, $legacy)
    ),
    DurableRetryActivationContractException::INVALID_AUTHORITY_BATCH,
    'additional identity'
);
$rejects(
    static fn () => $complete->forIdentity($outside),
    DurableRetryActivationContractException::INVALID_AUTHORITY_BATCH,
    'foreign lookup'
);

foreach ([
    DurableRetryAuthorityIdentity::class,
    DurableRetryGenerationIdentity::class,
    DurableRetryAuthorityIdentityCollection::class,
    DurableRetryLegacyAuthorityEntry::class,
    DurableRetryLegacyAuthorityBatchResult::class,
] as $class) {
    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        $assert($property->isReadOnly(), $class . ' property readonly');
    }
}

echo "OK durable retry activation authority contracts ({$assertions} assertions)\n";
