<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;

final class PaymentReconciliationProcessingResult
{
    public const PROCESSED = 'processed';
    public const NOT_FOUND = 'not_found';
    public const INVALID_LEASE = 'invalid_lease';
    public const NOT_PROCESSABLE = 'not_processable';
    public const FINANCIAL_EVIDENCE_MISSING = 'financial_evidence_missing';
    public const ORIGIN_CONTEXT_MISSING = 'origin_context_missing';
    public const INCONSISTENT_EVIDENCE = 'inconsistent_evidence';
    public const HEARTBEAT_REJECTED = 'heartbeat_rejected';
    public const AUTHORITY_LOST = 'authority_lost';
    public const CAS_REJECTED = 'cas_rejected';
    public const RECOVERABLE_ERROR = 'recoverable_error';
    public const COMPLETION_REJECTED = 'completion_rejected';

    public function __construct(
        private readonly string $status,
        private readonly ?TechnicalReconciliationResult $technicalResult = null,
        private readonly bool $heartbeatPerformed = false,
        private readonly ?PaymentCompletionOutcomeInterface $completionOutcome = null
    ) {
        if (! in_array($status, [
            self::PROCESSED,
            self::NOT_FOUND,
            self::INVALID_LEASE,
            self::NOT_PROCESSABLE,
            self::FINANCIAL_EVIDENCE_MISSING,
            self::ORIGIN_CONTEXT_MISSING,
            self::INCONSISTENT_EVIDENCE,
            self::HEARTBEAT_REJECTED,
            self::AUTHORITY_LOST,
            self::CAS_REJECTED,
            self::RECOVERABLE_ERROR,
            self::COMPLETION_REJECTED,
        ], true)) {
            throw new InvalidArgumentException('Resultado de procesamiento no valido.');
        }

        $handled = in_array($status, [
            self::PROCESSED,
            self::COMPLETION_REJECTED,
        ], true);

        if (
            $handled !== ($technicalResult !== null)
            || (! $handled && $completionOutcome !== null)
            || ($status === self::PROCESSED
                && $completionOutcome !== null
                && ! $completionOutcome->successful())
            || ($status === self::COMPLETION_REJECTED
                && ($completionOutcome === null
                    || $completionOutcome->successful()))
        ) {
            throw new InvalidArgumentException(
                'El resultado procesado no es coherente.'
            );
        }
    }

    public function status(): string { return $this->status; }
    public function processed(): bool { return $this->status === self::PROCESSED; }
    public function technicalResult(): ?TechnicalReconciliationResult
    {
        return $this->technicalResult;
    }
    public function heartbeatPerformed(): bool { return $this->heartbeatPerformed; }
    public function completionOutcome(): ?PaymentCompletionOutcomeInterface
    {
        return $this->completionOutcome;
    }
}
