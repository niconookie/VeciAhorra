<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryIndeterminateReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationConfigurationSourceException;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationPolicyException;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class A5Journal
{
    /** @var list<string> */
    public array $events = [];
}

final class A5AuthorityDouble implements DurableRetryLegacyExclusionInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly A5Journal $journal,
        private readonly DurableRetryLegacyAuthorityResult|Throwable $outcome
    ) {
    }

    public function classify(
        DurableRetryAuthorityIdentity $identity
    ): DurableRetryLegacyAuthorityResult {
        ++$this->calls;
        $this->journal->events[] = 'A3';
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function classifyBatch(
        DurableRetryAuthorityIdentityCollection $identities
    ): DurableRetryLegacyAuthorityBatchResult {
        throw new RuntimeException('A5 must not classify a batch.');
    }
}

final class A5PolicyDouble implements DurableRetryActivationPolicyInterface
{
    public int $calls = 0;
    public int $snapshots = 0;

    public function __construct(
        private readonly A5Journal $journal,
        private readonly bool|Throwable $outcome
    ) {
    }

    public function allowsInitialTransfer(
        DurableRetryAuthorityIdentity $identity
    ): bool {
        ++$this->calls;
        ++$this->snapshots;
        $this->journal->events[] = 'A2';
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class A5TransferDouble implements
    DurableRetryInitialTransferAuthorityInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly A5Journal $journal,
        private readonly DurableRetryInitialTransferResult|Throwable $outcome
    ) {
    }

    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult {
        ++$this->calls;
        $this->journal->events[] = 'A4';
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (
    &$assertions
): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$validRequest = static function (
    int $id = 701
): DurableRetryInitialTransferRequest {
    return DurableRetryInitialTransferRequest::reconciliation(
        DurableRetryAuthorityIdentity::reconciliation($id),
        $id,
        new DateTimeImmutable('2026-07-30 12:00:00 UTC')
    );
};
$invalidRequest = static function (): DurableRetryInitialTransferRequest {
    $authorityClass = new ReflectionClass(DurableRetryAuthorityIdentity::class);
    $authority = $authorityClass->newInstanceWithoutConstructor();
    $authorityClass->getProperty('stage')->setValue($authority, 'business_completion');
    $authorityClass->getProperty('subjectId')->setValue($authority, 701);

    $requestClass = new ReflectionClass(DurableRetryInitialTransferRequest::class);
    $request = $requestClass->newInstanceWithoutConstructor();
    $requestClass->getProperty('authority')->setValue($request, $authority);
    $requestClass->getProperty('completionId')->setValue($request, 701);
    $requestClass->getProperty('scheduledForUtc')->setValue(
        $request,
        new DateTimeImmutable('2026-07-30 12:00:00 UTC')
    );

    return $request;
};
$generation = static fn (
    DurableRetryInitialTransferRequest $request
) => $request->generationIdentity();

$legacy = DurableRetryLegacyAuthorityResult::legacy();
$cases = [
    ['A5-01', 'invalid identity', $invalidRequest, $legacy, false,
        DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::OPERATIONAL_FAILURE,
        DurableRetryInitialAuthorityProductionResult::DEPENDENCY_FAILURE,
        [], 0, 0, 0],
    ['A5-02', 'A3 durable', $validRequest,
        DurableRetryLegacyAuthorityResult::durable(), false,
        DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::DURABLE_EXISTING,
        DurableRetryInitialAuthorityProductionResult::DURABLE_AUTHORITY_ALREADY_EXISTS,
        ['A3'], 1, 0, 0],
    ['A5-03', 'A3 indeterminate', $validRequest,
        DurableRetryLegacyAuthorityResult::indeterminate(
            DurableRetryIndeterminateReason::INCOMPATIBLE_DURABLE_STATE
        ), false, DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::AUTHORITY_INDETERMINATE,
        DurableRetryIndeterminateReason::INCOMPATIBLE_DURABLE_STATE,
        ['A3'], 1, 0, 0],
    ['A5-04', 'A3 legacy and A2 legacy', $validRequest, $legacy, false,
        DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::LEGACY_ALLOWED,
        DurableRetryInitialAuthorityProductionResult::ACTIVATION_POLICY_REJECTED,
        ['A3', 'A2'], 1, 1, 0],
    ['A5-05', 'invalid configuration', $validRequest, $legacy,
        DurableRetryActivationPolicyException::forCode(
            DurableRetryActivationPolicyException::INVALID_PERCENTAGE
        ), DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::CONFIGURATION_INVALID,
        DurableRetryActivationPolicyException::INVALID_PERCENTAGE,
        ['A3', 'A2'], 1, 1, 0],
    ['A5-06', 'A4 created', $validRequest, $legacy, true,
        static fn ($request) => DurableRetryInitialTransferResult::transferred(
            $request->generationIdentity()
        ), DurableRetryInitialAuthorityProductionResult::DURABLE_CREATED,
        DurableRetryInitialTransferReason::INITIAL_TRANSFER_CREATED,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-07', 'compatible convergence and concurrent loser', $validRequest,
        $legacy, true,
        static fn ($request) => DurableRetryInitialTransferResult::alreadyTransferred(
            $request->generationIdentity()
        ), DurableRetryInitialAuthorityProductionResult::DURABLE_CONVERGED,
        DurableRetryInitialTransferReason::EQUIVALENT_TRANSFER_EXISTS,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-08', 'legacy claim in flight', $validRequest, $legacy, true,
        DurableRetryInitialTransferResult::legacyInFlight(),
        DurableRetryInitialAuthorityProductionResult::LEGACY_IN_FLIGHT,
        DurableRetryInitialTransferReason::LEGACY_CLAIM_IN_FLIGHT,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-09', 'functionally ineligible', $validRequest, $legacy, true,
        DurableRetryInitialTransferResult::functionallyIneligible(
            DurableRetryInitialTransferReason::FUNCTIONAL_STATE_INELIGIBLE
        ), DurableRetryInitialAuthorityProductionResult::FUNCTIONALLY_INELIGIBLE,
        DurableRetryInitialTransferReason::FUNCTIONAL_STATE_INELIGIBLE,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-10', 'incompatible durable evidence', $validRequest, $legacy, true,
        DurableRetryInitialTransferResult::durableInconsistency(
            DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE
        ), DurableRetryInitialAuthorityProductionResult::DURABLE_INCONSISTENCY,
        DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-11', 'commit uncertainty', $validRequest, $legacy, true,
        DurableRetryInitialTransferResult::outcomeUncertain(),
        DurableRetryInitialAuthorityProductionResult::OUTCOME_UNCERTAIN,
        DurableRetryInitialTransferReason::PERSISTENCE_OUTCOME_UNCERTAIN,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-12', 'A3 exception', $validRequest,
        new RuntimeException('safe A3 failure'), false,
        DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::OPERATIONAL_FAILURE,
        DurableRetryInitialAuthorityProductionResult::DEPENDENCY_FAILURE,
        ['A3'], 1, 0, 0],
    ['A5-13', 'A2 source exception', $validRequest, $legacy,
        DurableRetryActivationConfigurationSourceException::forCode(
            DurableRetryActivationConfigurationSourceException::SOURCE_UNAVAILABLE
        ), DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::OPERATIONAL_FAILURE,
        DurableRetryActivationConfigurationSourceException::SOURCE_UNAVAILABLE,
        ['A3', 'A2'], 1, 1, 0],
    ['A5-14', 'A4 exception', $validRequest, $legacy, true,
        new RuntimeException('safe A4 failure'),
        DurableRetryInitialAuthorityProductionResult::OPERATIONAL_FAILURE,
        DurableRetryInitialAuthorityProductionResult::DEPENDENCY_FAILURE,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-15', 'persistence error', $validRequest, $legacy, true,
        DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::PERSISTENCE_ERROR,
        DurableRetryInitialTransferReason::PERSISTENCE_WRITE_FAILED,
        ['A3', 'A2', 'A4'], 1, 1, 1],
    ['A5-16', 'reinvocation observes durable', $validRequest,
        DurableRetryLegacyAuthorityResult::durable(), true,
        DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::DURABLE_EXISTING,
        DurableRetryInitialAuthorityProductionResult::DURABLE_AUTHORITY_ALREADY_EXISTS,
        ['A3'], 1, 0, 0],
    ['A5-17', 'new invocation sees changed disabled snapshot', $validRequest,
        $legacy, false, DurableRetryInitialTransferResult::persistenceError(),
        DurableRetryInitialAuthorityProductionResult::LEGACY_ALLOWED,
        DurableRetryInitialAuthorityProductionResult::ACTIVATION_POLICY_REJECTED,
        ['A3', 'A2'], 1, 1, 0],
];

$assert(count($cases) === 17, 'Exactly seventeen normative cases exist.');
$ids = array_column($cases, 0);
$assert(count(array_unique($ids)) === 17, 'Normative case IDs are unique.');
$assert(
    $ids === array_map(
        static fn (int $number): string => sprintf('A5-%02d', $number),
        range(1, 17)
    ),
    'Normative case IDs are complete and ordered.'
);
$observedStates = [];
foreach ($cases as $case) {
    [
        $id, $description, $requestFactory, $authorityOutcome, $policyOutcome,
        $transferOutcome, $expectedState, $expectedReason, $expectedJournal,
        $expectedA3, $expectedA2, $expectedA4,
    ] = $case;
    $journal = new A5Journal();
    $request = $requestFactory();
    if ($transferOutcome instanceof Closure) {
        $transferOutcome = $transferOutcome($request);
    }
    $authority = new A5AuthorityDouble($journal, $authorityOutcome);
    $policy = new A5PolicyDouble($journal, $policyOutcome);
    $transfer = new A5TransferDouble($journal, $transferOutcome);
    $producer = new DurableRetryInitialAuthorityProducer(
        $authority,
        $policy,
        $transfer
    );

    $result = $producer->produceReconciliation($request);
    $observedStates[] = $result->state();
    $assert($result->state() === $expectedState, "{$id} state: {$description}");
    $assert($result->reason() === $expectedReason, "{$id} reason");
    $assert($journal->events === $expectedJournal, "{$id} exact order");
    $assert($authority->calls === $expectedA3, "{$id} A3 count");
    $assert($policy->calls === $expectedA2, "{$id} A2 count");
    $assert($policy->snapshots === $expectedA2, "{$id} snapshot count");
    $assert($transfer->calls === $expectedA4, "{$id} A4 count");
    $assert($transfer->calls <= 1, "{$id} at most one A4");
    $assert(
        $result->permitsLegacyProduction()
            === ($expectedState
                === DurableRetryInitialAuthorityProductionResult::LEGACY_ALLOWED),
        "{$id} legacy permission"
    );
    $assert(
        $result->durableAuthorityConfirmed() === in_array(
            $expectedState,
            [
                DurableRetryInitialAuthorityProductionResult::DURABLE_EXISTING,
                DurableRetryInitialAuthorityProductionResult::DURABLE_CREATED,
                DurableRetryInitialAuthorityProductionResult::DURABLE_CONVERGED,
            ],
            true
        ),
        "{$id} durable confirmation"
    );
    $assert(
        $result->requiresRecovery() === in_array(
            $expectedState,
            [
                DurableRetryInitialAuthorityProductionResult::LEGACY_IN_FLIGHT,
                DurableRetryInitialAuthorityProductionResult::AUTHORITY_INDETERMINATE,
                DurableRetryInitialAuthorityProductionResult::DURABLE_INCONSISTENCY,
                DurableRetryInitialAuthorityProductionResult::PERSISTENCE_ERROR,
                DurableRetryInitialAuthorityProductionResult::OUTCOME_UNCERTAIN,
                DurableRetryInitialAuthorityProductionResult::OPERATIONAL_FAILURE,
            ],
            true
        ),
        "{$id} recovery predicate"
    );
    $assert(
        ($expectedA4 === 1) === ($result->transferResult() !== null)
            || $expectedState
                === DurableRetryInitialAuthorityProductionResult::OPERATIONAL_FAILURE,
        "{$id} transfer evidence"
    );
}

$expectedStates = [
    DurableRetryInitialAuthorityProductionResult::LEGACY_ALLOWED,
    DurableRetryInitialAuthorityProductionResult::LEGACY_IN_FLIGHT,
    DurableRetryInitialAuthorityProductionResult::DURABLE_EXISTING,
    DurableRetryInitialAuthorityProductionResult::DURABLE_CREATED,
    DurableRetryInitialAuthorityProductionResult::DURABLE_CONVERGED,
    DurableRetryInitialAuthorityProductionResult::FUNCTIONALLY_INELIGIBLE,
    DurableRetryInitialAuthorityProductionResult::AUTHORITY_INDETERMINATE,
    DurableRetryInitialAuthorityProductionResult::DURABLE_INCONSISTENCY,
    DurableRetryInitialAuthorityProductionResult::CONFIGURATION_INVALID,
    DurableRetryInitialAuthorityProductionResult::PERSISTENCE_ERROR,
    DurableRetryInitialAuthorityProductionResult::OUTCOME_UNCERTAIN,
    DurableRetryInitialAuthorityProductionResult::OPERATIONAL_FAILURE,
];
sort($expectedStates);
$uniqueStates = array_values(array_unique($observedStates));
sort($uniqueStates);
$assert($uniqueStates === $expectedStates, 'All twelve result states are covered.');

echo "OK durable retry initial authority producer (17 cases, {$assertions} assertions)\n";
