<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryNextGenerationPersistenceResult
{
    public const CREATED = 'next_generation_created';
    public const CONCURRENT_CONVERGENCE = 'concurrent_convergence';
    public const NOT_FOUND = 'not_found';
    public const CAS_CONFLICT = 'cas_conflict';
    public const INELIGIBLE_STATE = 'ineligible_state';
    public const INVALID_DECISION = 'invalid_decision';
    public const ACTIVE_SLOT_CONFLICT = 'active_slot_conflict';
    public const DURABLE_INCONSISTENCY = 'durable_inconsistency';
    public const INSERT_FAILED = 'insert_failed';
    public const PERSISTENCE_ERROR = 'persistence_error';

    private const ALL = [
        self::CREATED,
        self::CONCURRENT_CONVERGENCE,
        self::NOT_FOUND,
        self::CAS_CONFLICT,
        self::INELIGIBLE_STATE,
        self::INVALID_DECISION,
        self::ACTIVE_SLOT_CONFLICT,
        self::DURABLE_INCONSISTENCY,
        self::INSERT_FAILED,
        self::PERSISTENCE_ERROR,
    ];

    public function __construct(
        private readonly string $code,
        private readonly ?DurableRetryScheduleSnapshot $superseded = null,
        private readonly ?DurableRetryScheduleSnapshot $successor = null
    ) {
        if (! in_array($code, self::ALL, true)) {
            throw new InvalidArgumentException('Invalid next generation persistence result.');
        }
        $hasEvidence = $superseded !== null && $successor !== null;
        if (in_array($code, [self::CREATED, self::CONCURRENT_CONVERGENCE], true)
            !== $hasEvidence
        ) {
            throw new InvalidArgumentException('Invalid next generation evidence.');
        }
        if ($hasEvidence
            && ($superseded->status() !== DurableRetryStatus::SUPERSEDED
                || $successor->status() !== DurableRetryStatus::DISPATCHING
                || $superseded->id() === $successor->id()
                || $successor->stage() !== $superseded->stage()
                || $successor->subjectId() !== $superseded->subjectId()
                || $successor->generation() !== $superseded->generation() + 1)
        ) {
            throw new InvalidArgumentException('Incompatible next generation evidence.');
        }
    }

    public function code(): string
    {
        return $this->code;
    }

    public function superseded(): ?DurableRetryScheduleSnapshot
    {
        return $this->superseded;
    }

    public function successor(): ?DurableRetryScheduleSnapshot
    {
        return $this->successor;
    }

    public function succeeded(): bool
    {
        return in_array($this->code, [
            self::CREATED,
            self::CONCURRENT_CONVERGENCE,
        ], true);
    }

    public function converged(): bool
    {
        return $this->code === self::CONCURRENT_CONVERGENCE;
    }

    public function insertedByThisCall(): bool
    {
        return $this->code === self::CREATED;
    }
}
