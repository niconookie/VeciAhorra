<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryGenerationIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryReason;
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

$authority = DurableRetryAuthorityIdentity::reconciliation(31);
$scheduled = new DateTimeImmutable('2035-04-05 06:07:08', new DateTimeZone('UTC'));
$request = DurableRetryInitialTransferRequest::reconciliation(
    $authority,
    31,
    $scheduled
);
$assert($request->authority() === $authority, 'authority retained');
$assert($request->completionId() === 31, 'completion retained');
$assert($request->generation() === 1, 'generation fixed');
$assert($request->attemptNumber() === 0, 'attempt fixed');
$assert($request->reasonCode() === DurableRetryReason::RETRYABLE_FAILURE, 'reason fixed');
$assert($request->scheduledForUtc() === $scheduled, 'immutable time retained');
$assert($request->scheduledForDatabase() === '2035-04-05 06:07:08', 'database time');
$assert($request->generationIdentity()->isInitial(), 'initial identity');
$assert(
    $request->diagnosticKey()
        === 'reconciliation:31:generation:1:scheduled-for:2035-04-05 06:07:08',
    'request diagnostic key'
);
$assert(
    $request->equals(
        DurableRetryInitialTransferRequest::reconciliation(
            DurableRetryAuthorityIdentity::reconciliation(31),
            31,
            new DateTimeImmutable('2035-04-05 06:07:08 UTC')
        )
    ),
    'request deterministic equality'
);
$assert(
    ! $request->equals(
        DurableRetryInitialTransferRequest::reconciliation(
            $authority,
            31,
            new DateTimeImmutable('2035-04-05 06:07:09 UTC')
        )
    ),
    'scheduled time authoritative'
);
foreach ([0, -1, 30, 32] as $completionId) {
    $rejects(
        static fn () => DurableRetryInitialTransferRequest::reconciliation(
            $authority,
            $completionId,
            $scheduled
        ),
        DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_REQUEST,
        'invalid completion'
    );
}
foreach (['31', '3.1e1', ' 31 ', 31.0, true, null, [], new stdClass()] as $invalid) {
    $rejects(
        static fn () => DurableRetryInitialTransferRequest::reconciliation(
            $authority,
            $invalid,
            $scheduled
        ),
        null,
        'non-integer completion'
    );
}
$rejects(
    static fn () => DurableRetryInitialTransferRequest::reconciliation(
        $authority,
        31,
        new DateTimeImmutable('2035-04-05 06:07:08', new DateTimeZone('America/Santiago'))
    ),
    DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_REQUEST,
    'non-UTC time'
);
$rejects(
    static fn () => DurableRetryInitialTransferRequest::reconciliation(
        $authority,
        31,
        DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u T',
            '2035-04-05 06:07:08.000001 UTC'
        )
    ),
    DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_REQUEST,
    'microseconds rejected'
);

$expectedReasons = [
    DurableRetryInitialTransferReason::INITIAL_TRANSFER_CREATED,
    DurableRetryInitialTransferReason::EQUIVALENT_TRANSFER_EXISTS,
    DurableRetryInitialTransferReason::LEGACY_CLAIM_IN_FLIGHT,
    DurableRetryInitialTransferReason::FUNCTIONAL_RECORD_ABSENT,
    DurableRetryInitialTransferReason::FUNCTIONAL_STATE_INELIGIBLE,
    DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE,
    DurableRetryInitialTransferReason::DUPLICATE_DURABLE_IDENTITY,
    DurableRetryInitialTransferReason::PERSISTENCE_WRITE_FAILED,
    DurableRetryInitialTransferReason::PERSISTENCE_OUTCOME_UNCERTAIN,
];
$assert(DurableRetryInitialTransferReason::all() === $expectedReasons, 'nine reasons');
foreach ($expectedReasons as $reason) {
    $assert(DurableRetryInitialTransferReason::message($reason) !== '', 'safe message');
}
foreach (['', ' ', 'unknown', 'INITIAL_TRANSFER_CREATED', 'initial_transfer_created '] as $invalidReason) {
    $rejects(
        static fn () => DurableRetryInitialTransferReason::message($invalidReason),
        DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT,
        'unknown transfer reason'
    );
}
foreach (['', ' ', 'unknown', 'TRANSFERRED', 'transferred '] as $invalidState) {
    $rejects(
        static fn () => DurableRetryInitialTransferReason::allowedFor($invalidState),
        DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT,
        'unknown transfer state'
    );
}

$identity = DurableRetryGenerationIdentity::initial($authority);
$results = [
    DurableRetryInitialTransferResult::transferred($identity),
    DurableRetryInitialTransferResult::alreadyTransferred($identity),
    DurableRetryInitialTransferResult::legacyInFlight(),
    DurableRetryInitialTransferResult::functionallyIneligible(
        DurableRetryInitialTransferReason::FUNCTIONAL_RECORD_ABSENT
    ),
    DurableRetryInitialTransferResult::durableInconsistency(
        DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE,
        $identity
    ),
    DurableRetryInitialTransferResult::persistenceError(),
    DurableRetryInitialTransferResult::outcomeUncertain($identity),
];
$states = [
    'transferred',
    'already_transferred',
    'legacy_in_flight',
    'functionally_ineligible',
    'durable_inconsistency',
    'persistence_error',
    'outcome_uncertain',
];
$assert(
    array_map(
        static fn (DurableRetryInitialTransferResult $result): string =>
            $result->state(),
        $results
    ) === $states,
    'seven transfer states exact'
);
foreach ($results as $result) {
    $assert($result->diagnosticMessage() !== '', 'result safe message');
}
$assert($results[0]->succeeded(), 'created succeeds');
$assert(! $results[0]->idempotent(), 'created not idempotent');
$assert($results[0]->permitsInitialExternalScheduling(), 'created schedules once');
$assert($results[0]->blocksLegacy(), 'created blocks legacy');
$assert($results[0]->generationIdentity() === $identity, 'created evidence');
$assert($results[1]->succeeded() && $results[1]->idempotent(), 'already idempotent');
$assert(! $results[1]->permitsInitialExternalScheduling(), 'already no scheduling');
$assert(! $results[2]->blocksLegacy(), 'legacy claim remains legacy');
$assert(! $results[2]->requiresRecovery(), 'legacy claim no recovery');
$assert($results[3]->blocksLegacy() && ! $results[3]->succeeded(), 'ineligible closed');
$assert($results[4]->requiresRecovery(), 'inconsistency recovery');
$assert($results[5]->requiresRecovery(), 'persistence recovery');
$assert($results[6]->requiresRecovery(), 'uncertain recovery');

$later = DurableRetryGenerationIdentity::fromAuthority($authority, 2);
$rejects(
    static fn () => DurableRetryInitialTransferResult::transferred($later),
    DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT,
    'non-initial transferred evidence'
);
$rejects(
    static fn () => DurableRetryInitialTransferResult::alreadyTransferred($later),
    DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT,
    'non-initial idempotent evidence'
);
$rejects(
    static fn () => DurableRetryInitialTransferResult::functionallyIneligible(
        DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE
    ),
    DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT,
    'reason state mismatch'
);

$exceptionMessages = [
    DurableRetryActivationContractException::INVALID_AUTHORITY_IDENTITY =>
        'Invalid durable retry authority identity.',
    DurableRetryActivationContractException::INVALID_GENERATION_IDENTITY =>
        'Invalid durable retry generation identity.',
    DurableRetryActivationContractException::INVALID_IDENTITY_COLLECTION =>
        'Invalid durable retry authority identity collection.',
    DurableRetryActivationContractException::INVALID_AUTHORITY_RESULT =>
        'Invalid durable retry legacy authority result.',
    DurableRetryActivationContractException::INVALID_AUTHORITY_BATCH =>
        'Invalid durable retry legacy authority batch result.',
    DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_REQUEST =>
        'Invalid durable retry initial transfer request.',
    DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT =>
        'Invalid durable retry initial transfer result.',
    DurableRetryActivationContractException::CONTRACT_VIOLATION =>
        'Durable retry activation contract violation.',
];
foreach ($exceptionMessages as $code => $message) {
    $exception = DurableRetryActivationContractException::forCode($code);
    $assert($exception->reasonCode() === $code, 'exception code');
    $assert($exception->getMessage() === $message, 'exception message');
}
try {
    DurableRetryActivationContractException::forCode('unknown');
    $assert(false, 'unknown exception code rejected');
} catch (InvalidArgumentException $exception) {
    $assert(
        $exception->getMessage()
            === 'Invalid durable retry activation exception code.',
        'unknown exception stable message'
    );
}

$publicMethods = array_map(
    static fn (ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(DurableRetryInitialTransferResult::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC)
);
foreach (['rollback', 'delete', 'forceTransfer', 'transferBack', 'overwrite'] as $forbidden) {
    $assert(! in_array($forbidden, $publicMethods, true), 'no ' . $forbidden);
}
$assert(
    ! (new ReflectionClass(DurableRetryInitialTransferResult::class))
        ->hasConstant('INVALID_INPUT'),
    'invalid input is not result'
);

echo "OK durable retry activation transfer contracts ({$assertions} assertions)\n";
