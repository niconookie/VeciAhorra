<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Services\DurableRetryReconciliationProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationAttemptProcessorInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationLeaseAuthorityInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationReadAuthorityInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLease;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

final class DurableReconciliationClaimDouble implements PaymentReconciliationLeaseAuthorityInterface
{
    public int $ownerCalls = 0;
    public array $calls = [];
    public array $queue = [];
    public function newOwner(): string
    {
        ++$this->ownerCalls;
        return 'worker_' . str_repeat('a', 32);
    }
    public function acquireLease(int $reconciliationId, string $owner, mixed $durationSeconds = 600): LeaseAcquireResult
    {
        $this->calls[] = func_get_args();
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }
}

final class DurableReconciliationAttemptDouble implements PaymentReconciliationAttemptProcessorInterface
{
    public array $calls = [];
    public array $queue = [];
    public function process(ReconciliationLease $lease): PaymentReconciliationProcessingResult
    {
        $this->calls[] = $lease;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }
}

final class DurableReconciliationReadDouble implements PaymentReconciliationReadAuthorityInterface
{
    public array $calls = [];
    public array $queue = [];
    public function find(int $id): ?PaymentReconciliation
    {
        $this->calls[] = $id;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }
}

final class DurableReconciliationRejectedOutcome implements PaymentCompletionOutcomeInterface
{
    public function __construct(private readonly string $target) {}
    public function successful(): bool { return false; }
    public function resultCode(): string { return 'rejected'; }
    public function targetReconciliationStatus(): string { return $this->target; }
    public function lastErrorCode(): ?string { return 'functional_rejection'; }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$authority = static function (
    string $status,
    int $attempt = 1,
    ?string $reason = null
): PaymentReconciliation {
    $reflection = new ReflectionClass(PaymentReconciliation::class);
    $value = $reflection->newInstanceWithoutConstructor();
    foreach ([
        'id' => 80,
        'status' => $status,
        'attemptCount' => $attempt,
        'lastErrorCode' => $reason,
    ] as $name => $propertyValue) {
        $reflection->getProperty($name)->setValue($value, $propertyValue);
    }
    return $value;
};
$context = static fn (
    string $stage = DurableRetryStage::RECONCILIATION,
    ?int $completionId = 80,
    int $previous = 0
): DurableRetryExecutionContext => new DurableRetryExecutionContext(
    70,
    $stage,
    80,
    $completionId,
    1,
    $previous,
    $previous + 1,
    '2030-01-01 00:01:00'
);
$lease = static fn (int $attempt = 1, int $id = 80): ReconciliationLease =>
    new ReconciliationLease(
        $id,
        'worker_' . str_repeat('a', 32),
        1,
        '2030-01-01 00:11:00',
        $attempt
    );
$processing = static function (string $status): PaymentReconciliationProcessingResult {
    return $status === PaymentReconciliationProcessingResult::PROCESSED
        ? new PaymentReconciliationProcessingResult(
            $status,
            new TechnicalReconciliationResult(
                80,
                'technical_completed',
                str_repeat('b', 64),
                str_repeat('c', 64)
            )
        )
        : new PaymentReconciliationProcessingResult($status);
};
$completionRejected = static function (string $target): PaymentReconciliationProcessingResult {
    return new PaymentReconciliationProcessingResult(
        PaymentReconciliationProcessingResult::COMPLETION_REJECTED,
        new TechnicalReconciliationResult(
            80,
            'technical_rejected',
            str_repeat('b', 64),
            str_repeat('c', 64)
        ),
        false,
        new DurableReconciliationRejectedOutcome($target)
    );
};
$run = static function (
    LeaseAcquireResult|Throwable $claimResult,
    PaymentReconciliationProcessingResult|Throwable|null $attemptResult,
    PaymentReconciliation|Throwable|null $row,
    ?DurableRetryExecutionContext $executionContext = null
) use ($context): array {
    $claims = new DurableReconciliationClaimDouble();
    $claims->queue[] = $claimResult;
    $attempts = new DurableReconciliationAttemptDouble();
    if ($attemptResult !== null) {
        $attempts->queue[] = $attemptResult;
    }
    $reads = new DurableReconciliationReadDouble();
    $reads->queue[] = $row;
    $processor = new DurableRetryReconciliationProcessor($claims, $attempts, $reads);
    $result = $processor->process($executionContext ?? $context());
    return [$result, $claims, $attempts, $reads, $processor];
};
$acquired = static fn (int $attempt = 1, int $id = 80): LeaseAcquireResult =>
    new LeaseAcquireResult(LeaseAcquireResult::ACQUIRED, $lease($attempt, $id));

[$result, $claims, $attempts, $reads, $processor] = $run(
    $acquired(),
    $processing(PaymentReconciliationProcessingResult::PROCESSED),
    $authority(PaymentReconciliation::STATUS_COMPLETED)
);
$assert($processor->stage() === DurableRetryStage::RECONCILIATION, 'closed reconciliation stage');
$assert($result->succeededProcessing(), 'completed authority succeeds');
$assert($result->confirmedAttemptNumber() === 1, 'success counter is authoritative');
$assert(count($claims->calls) === 1 && $claims->ownerCalls === 1, 'one claim and one owner');
$assert(count($attempts->calls) === 1, 'one functional attempt');
$assert(count($reads->calls) === 1, 'one authoritative reread');
$assert($claims->calls[0][0] === 80, 'subject id is reconciliation id');
$assert($attempts->calls[0]->reconciliationId() === 80, 'lease preserves reconciliation identity');

foreach ([
    [$context(DurableRetryStage::BUSINESS_COMPLETION), 'other stage'],
    [$context(DurableRetryStage::RECONCILIATION, null), 'missing completion'],
    [$context(DurableRetryStage::RECONCILIATION, 81), 'incoherent identity'],
] as [$invalidContext, $label]) {
    [$invalid, $claims, $attempts, $reads] = $run(
        new LeaseAcquireResult(LeaseAcquireResult::NOT_FOUND),
        null,
        null,
        $invalidContext
    );
    $assert($invalid->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, "{$label} rejected closed");
    $assert($invalid->confirmedAttemptNumber() === null, "{$label} has no fabricated counter");
    $assert($claims->calls === [] && $attempts->calls === [] && $reads->calls === [], "{$label} has zero authority calls");
}

foreach ([
    [PaymentReconciliationProcessingResult::ORIGIN_CONTEXT_MISSING, 'origin missing'],
    [PaymentReconciliationProcessingResult::FINANCIAL_EVIDENCE_MISSING, 'financial missing'],
    [PaymentReconciliationProcessingResult::INCONSISTENT_EVIDENCE, 'inconsistent evidence'],
    [PaymentReconciliationProcessingResult::RECOVERABLE_ERROR, 'recoverable error'],
] as [$status, $label]) {
    [$retryable] = $run(
        $acquired(),
        $processing($status),
        $authority(PaymentReconciliation::STATUS_RETRYABLE, 1, 'technical_internal_error')
    );
    $assert($retryable->classification() === DurableRetryProcessingFailure::RETRYABLE_FAILURE, "{$label} confirmed retryable");
    $assert($retryable->failure()?->failureCode() === DurableRetryProcessingFailure::CONFIRMED_RETRYABLE_FAILURE, "{$label} closed failure code");
    $assert($retryable->confirmedAttemptNumber() === 1, "{$label} authoritative counter");
}

foreach ([
    PaymentReconciliationProcessingResult::HEARTBEAT_REJECTED,
    PaymentReconciliationProcessingResult::AUTHORITY_LOST,
    PaymentReconciliationProcessingResult::INVALID_LEASE,
    PaymentReconciliationProcessingResult::CAS_REJECTED,
    PaymentReconciliationProcessingResult::NOT_PROCESSABLE,
] as $status) {
    [$uncertain] = $run(
        $acquired(),
        $processing($status),
        $authority(PaymentReconciliation::STATUS_PROCESSING)
    );
    $assert($uncertain->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, "{$status} uncertain");
    $assert($uncertain->confirmedAttemptNumber() === 1, "{$status} retains confirmed counter");
}

[$terminal] = $run(
    $acquired(),
    $completionRejected(PaymentReconciliation::STATUS_PERMANENT_FAILURE),
    $authority(PaymentReconciliation::STATUS_PERMANENT_FAILURE, 1, 'functional_rejection')
);
$assert($terminal->classification() === DurableRetryProcessingFailure::TERMINAL_FAILURE, 'permanent confirmed terminal');
$assert($terminal->failure()?->failureCode() === DurableRetryProcessingFailure::CONFIRMED_TERMINAL_FAILURE, 'terminal failure code');

[$manualTerminal] = $run(
    $acquired(),
    $completionRejected(PaymentReconciliation::STATUS_MANUAL_REVIEW),
    $authority(PaymentReconciliation::STATUS_MANUAL_REVIEW, 1, 'functional_rejection')
);
$assert($manualTerminal->classification() === DurableRetryProcessingFailure::TERMINAL_FAILURE, 'manual functional rejection terminal');
[$manualUnknown] = $run(
    $acquired(),
    $processing(PaymentReconciliationProcessingResult::NOT_PROCESSABLE),
    $authority(PaymentReconciliation::STATUS_MANUAL_REVIEW, 1, 'unknown_reason')
);
$assert($manualUnknown->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'unknown manual reason uncertain');
[$exhausted] = $run(
    new LeaseAcquireResult(LeaseAcquireResult::ATTEMPTS_EXHAUSTED),
    null,
    $authority(PaymentReconciliation::STATUS_MANUAL_REVIEW, 5, 'attempts_exhausted'),
    $context(previous: 4)
);
$assert($exhausted->classification() === DurableRetryProcessingFailure::RETRYABLE_FAILURE, 'functional exhaustion delegated to durable policy');
$assert($exhausted->confirmedAttemptNumber() === 5, 'fifth attempt retained');

foreach ([
    LeaseAcquireResult::BUSY,
    LeaseAcquireResult::NOT_CLAIMABLE,
] as $claimStatus) {
    [$uncertain, $claims, $attempts, $reads] = $run(
        new LeaseAcquireResult($claimStatus),
        null,
        $authority(PaymentReconciliation::STATUS_PROCESSING)
    );
    $assert($uncertain->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, "{$claimStatus} not success");
    $assert($uncertain->confirmedAttemptNumber() === 1, "{$claimStatus} rereads counter");
    $assert($attempts->calls === [] && count($reads->calls) === 1, "{$claimStatus} no processing and one read");
}
[$notFound, $claims, $attempts, $reads] = $run(
    new LeaseAcquireResult(LeaseAcquireResult::NOT_FOUND),
    null,
    null
);
$assert($notFound->confirmedAttemptNumber() === null, 'not found has no counter');
$assert($notFound->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'not found uncertain');
$assert($attempts->calls === [] && count($reads->calls) === 1, 'not found never processes');
[$alreadyCompleted, $claims, $attempts, $reads] = $run(
    new LeaseAcquireResult(LeaseAcquireResult::NOT_CLAIMABLE),
    null,
    $authority(PaymentReconciliation::STATUS_COMPLETED)
);
$assert($alreadyCompleted->succeededProcessing(), 'already completed is idempotent success');
$assert($alreadyCompleted->confirmedAttemptNumber() === 1, 'already completed uses persisted counter');
$assert($attempts->calls === [] && count($reads->calls) === 1, 'already completed never processes again');

[$residualRecovery] = $run(
    $acquired(),
    $processing(PaymentReconciliationProcessingResult::RECOVERABLE_ERROR),
    $authority(PaymentReconciliation::STATUS_PROCESSING, 1, 'technical_internal_error')
);
$assert($residualRecovery->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'recoverable residual processing is uncertain');
[$manualTechnical] = $run(
    $acquired(),
    $processing(PaymentReconciliationProcessingResult::RECOVERABLE_ERROR),
    $authority(PaymentReconciliation::STATUS_MANUAL_REVIEW, 1, 'technical_internal_error')
);
$assert($manualTechnical->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'manual technical result is uncertain');

[$contradiction, $claims, $attempts, $reads] = $run(
    $acquired(2),
    null,
    $authority(PaymentReconciliation::STATUS_PROCESSING, 2)
);
$assert($contradiction->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'lease contradiction uncertain');
$assert($contradiction->confirmedAttemptNumber() === 2, 'lease contradiction remains visible');
$assert($attempts->calls === [] && $reads->calls === [], 'lease contradiction stops before effects');

[$thrownBefore] = $run(
    new RuntimeException('secret SQL'),
    null,
    null
);
$assert($thrownBefore->confirmedAttemptNumber() === null, 'claim exception does not fabricate counter');
[$thrownAfter] = $run(
    $acquired(),
    new RuntimeException('secret token'),
    $authority(PaymentReconciliation::STATUS_PROCESSING)
);
$assert($thrownAfter->confirmedAttemptNumber() === 1, 'attempt exception recovers confirmed counter');
$assert($thrownAfter->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'attempt exception uncertain');
[$completedAfterThrow] = $run(
    $acquired(),
    new RuntimeException('hidden'),
    $authority(PaymentReconciliation::STATUS_COMPLETED)
);
$assert($completedAfterThrow->succeededProcessing(), 'completed authority wins after exception');
[$readFailure] = $run(
    $acquired(),
    $processing(PaymentReconciliationProcessingResult::PROCESSED),
    new RuntimeException('hidden db')
);
$assert($readFailure->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'read failure uncertain');
$assert($readFailure->confirmedAttemptNumber() === 1, 'read failure retains lease counter');

[$mismatch] = $run(
    $acquired(),
    $processing(PaymentReconciliationProcessingResult::PROCESSED),
    $authority(PaymentReconciliation::STATUS_COMPLETED, 2)
);
$assert($mismatch->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'authority mismatch uncertain');
$assert($mismatch->confirmedAttemptNumber() === 2, 'authority mismatch not hidden');

echo "durable retry reconciliation processor: {$assertions} assertions\n";
