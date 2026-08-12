<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Services\DurableRetryBusinessCompletionProcessor;
use VeciAhorra\Modules\Payments\BusinessCompletion\Contracts\BusinessCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Payments\BusinessCompletion\Contracts\BusinessCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;

final class BusinessCompletionAttemptDouble implements BusinessCompletionAttemptProcessorInterface
{
    public array $calls = [];
    public array $queue = [];

    public function process(int $reconciliationId, string $workerId, int $leaseSeconds = 30): BusinessCompletionResult
    {
        $this->calls[] = func_get_args();
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }
}

final class BusinessCompletionReadDouble implements BusinessCompletionReadAuthorityInterface
{
    public array $calls = [];
    public array $queue = [];

    public function findByReconciliation(int $reconciliationId): ?array
    {
        $this->calls[] = $reconciliationId;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$context = static fn (
    string $stage = DurableRetryStage::BUSINESS_COMPLETION,
    ?int $completionId = null,
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
$result = static fn (
    string $status,
    string $reason,
    ?int $completionId = 90,
    ?int $paymentId = null
): BusinessCompletionResult => new BusinessCompletionResult(
    $status,
    $reason,
    80,
    $completionId,
    $paymentId
);
$row = static function (
    string $status,
    mixed $attempt = 1,
    ?string $reason = null,
    mixed $id = 90,
    mixed $reconciliationId = 80
): array {
    return [
        'id' => $id,
        'reconciliation_id' => $reconciliationId,
        'status' => $status,
        'attempt_count' => $attempt,
        'last_result_code' => $reason,
        'payment_id' => $status === 'completed' ? 100 : null,
        'fulfillment_method' => $status === 'completed' ? 'delivery' : null,
        'completed_at' => $status === 'completed' ? '2030-01-01 00:02:00' : null,
    ];
};
$run = static function (
    BusinessCompletionResult|Throwable $attemptResult,
    array|Throwable|null $authority,
    ?DurableRetryExecutionContext $executionContext = null
) use ($context): array {
    $attempts = new BusinessCompletionAttemptDouble();
    $attempts->queue[] = $attemptResult;
    $reads = new BusinessCompletionReadDouble();
    $reads->queue[] = $authority;
    $processor = new DurableRetryBusinessCompletionProcessor($attempts, $reads);
    $processing = $processor->process($executionContext ?? $context());
    return [$processing, $attempts, $reads, $processor];
};

[$success, $attempts, $reads, $processor] = $run(
    $result(BusinessCompletionResult::COMPLETED, 'completed', 90, 100),
    $row('completed', 1, 'completed')
);
$assert($processor->stage() === DurableRetryStage::BUSINESS_COMPLETION, 'closed business stage');
$assert($success->succeededProcessing(), 'completed authority succeeds');
$assert($success->confirmedAttemptNumber() === 1, 'completed authoritative counter');
$assert(count($attempts->calls) === 1 && count($reads->calls) === 1, 'one attempt and one reread');
$assert($attempts->calls[0][0] === 80 && $reads->calls[0] === 80, 'subject is reconciliation id');
$assert(preg_match('/^business_[a-f0-9]{32}$/D', $attempts->calls[0][1]) === 1, 'opaque worker id');
$assert($attempts->calls[0][2] === 30, 'closed lease duration');

foreach ([
    [$context(DurableRetryStage::RECONCILIATION), 'wrong stage'],
    [$context(DurableRetryStage::BUSINESS_COMPLETION, null, 5), 'attempt outside durable budget'],
] as [$invalidContext, $label]) {
    [$invalid, $attempts, $reads] = $run(
        $result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'),
        $row('retryable', 1, 'unexpected_failure'),
        $invalidContext
    );
    $assert($invalid->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, "{$label} uncertain");
    $assert($invalid->confirmedAttemptNumber() === null, "{$label} has no attempt");
    $assert($attempts->calls === [] && $reads->calls === [], "{$label} has zero calls");
}

foreach ([null, 90] as $completionId) {
    [$completed] = $run(
        $result(BusinessCompletionResult::ALREADY_COMPLETED, 'already_completed', 90, 100),
        $row('completed', 1, 'completed'),
        $context(DurableRetryStage::BUSINESS_COMPLETION, $completionId)
    );
    $assert($completed->succeededProcessing(), 'nullable or coherent completion identity succeeds');
}

[$identityConflict] = $run(
    $result(BusinessCompletionResult::COMPLETED, 'completed', 90, 100),
    $row('completed', 1, 'completed'),
    $context(DurableRetryStage::BUSINESS_COMPLETION, 91)
);
$assert($identityConflict->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'completion id conflict uncertain');
$assert($identityConflict->confirmedAttemptNumber() === 1, 'completion id conflict preserves counter');

foreach ([
    ['permanent_failure', 'unsupported_origin', BusinessCompletionResult::PERMANENT_FAILURE],
    ['permanent_failure', 'checkout_or_session_missing', BusinessCompletionResult::PERMANENT_FAILURE],
    ['permanent_failure', 'empty_order_set', BusinessCompletionResult::PERMANENT_FAILURE],
    ['manual_review', 'reconciliation_changed', BusinessCompletionResult::MANUAL_REVIEW],
    ['manual_review', 'payment_state_conflict', BusinessCompletionResult::MANUAL_REVIEW],
    ['manual_review', 'invalid_clp_amount', BusinessCompletionResult::MANUAL_REVIEW],
] as [$status, $reason, $attemptStatus]) {
    [$terminal] = $run($result($attemptStatus, $reason), $row($status, 1, $reason));
    $assert($terminal->classification() === DurableRetryProcessingFailure::TERMINAL_FAILURE, "{$reason} terminal");
    $assert($terminal->confirmedAttemptNumber() === 1, "{$reason} counter");
}

foreach ([1, 5] as $attemptNumber) {
    [$retryable] = $run(
        $result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'),
        $row('retryable', $attemptNumber, 'unexpected_failure'),
        $context(DurableRetryStage::BUSINESS_COMPLETION, null, $attemptNumber - 1)
    );
    $assert($retryable->classification() === DurableRetryProcessingFailure::RETRYABLE_FAILURE, "attempt {$attemptNumber} retryable");
    $assert($retryable->confirmedAttemptNumber() === $attemptNumber, "attempt {$attemptNumber} preserved");
}

foreach ([
    [$result(BusinessCompletionResult::RETRYABLE, 'claim_unavailable'), $row('processing', 1, null), 1, 'lease occupied'],
    [$result(BusinessCompletionResult::LEASE_LOST, 'lease_lost'), $row('processing', 1, null), 1, 'lease lost'],
    [$result(BusinessCompletionResult::PERMANENT_FAILURE, 'unknown'), $row('permanent_failure', 1, 'unknown'), 1, 'unknown permanent'],
    [$result(BusinessCompletionResult::MANUAL_REVIEW, 'unknown'), $row('manual_review', 1, 'unknown'), 1, 'unknown manual'],
    [$result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'), $row('pending', 1, null), 1, 'pending'],
    [$result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'), $row('alien', 1, 'alien'), 1, 'unknown status'],
    [$result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'), null, null, 'missing row'],
    [$result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'), $row('retryable', 0, 'unexpected_failure'), null, 'zero counter'],
    [$result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'), $row('retryable', -1, 'unexpected_failure'), null, 'negative counter'],
    [$result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'), $row('retryable', 6, 'unexpected_failure'), null, 'excess counter'],
    [$result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'), $row('retryable', 2, 'unexpected_failure'), 2, 'advanced counter'],
    [$result(BusinessCompletionResult::COMPLETED, 'completed'), $row('completed', 1, 'completed', 90, 81), 1, 'reconciliation conflict'],
] as [$attemptResult, $authority, $counter, $label]) {
    [$uncertain] = $run($attemptResult, $authority);
    $assert($uncertain->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, "{$label} uncertain");
    $assert($uncertain->confirmedAttemptNumber() === $counter, "{$label} safe counter");
}

[$previousCounter] = $run(
    $result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'),
    $row('retryable', 1, 'unexpected_failure'),
    $context(DurableRetryStage::BUSINESS_COMPLETION, null, 1)
);
$assert($previousCounter->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'previous counter uncertain');
$assert($previousCounter->confirmedAttemptNumber() === 1, 'previous counter preserved');

[$lostResponse] = $run(
    new RuntimeException('SQL C:\\secret token nonce'),
    $row('completed', 1, 'completed')
);
$assert($lostResponse->succeededProcessing(), 'lost response with completed evidence succeeds');

[$attemptException, $attempts, $reads] = $run(
    new RuntimeException('SQL C:\\secret token nonce'),
    $row('retryable', 1, 'unexpected_failure')
);
$assert($attemptException->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'attempt exception uncertain');
$assert($attemptException->confirmedAttemptNumber() === 1, 'attempt exception preserves read counter');
$assert(count($attempts->calls) === 1 && count($reads->calls) === 1, 'exception preserves call budget');

[$readException] = $run(
    $result(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'),
    new RuntimeException('SELECT * FROM private_table C:\\secret')
);
$assert($readException->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'read exception uncertain');
$assert($readException->confirmedAttemptNumber() === null, 'read exception has no fabricated counter');
$serialized = serialize([$attemptException, $readException]);
$assert(! str_contains($serialized, 'secret') && ! str_contains($serialized, 'SELECT'), 'exceptions sanitized');

echo "durable retry business completion processor: {$assertions} assertions\n";
