<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryLegacyAuthorityBatchResult implements Countable, IteratorAggregate
{
    private function __construct(
        private readonly DurableRetryAuthorityIdentityCollection $requested,
        private readonly array $entries
    ) {
    }

    public static function fromEntries(
        DurableRetryAuthorityIdentityCollection $requested,
        DurableRetryLegacyAuthorityEntry ...$entries
    ): self {
        $byKey = [];
        foreach ($entries as $entry) {
            $identity = $entry->identity();
            $key = $identity->diagnosticKey();
            if (! $requested->contains($identity) || isset($byKey[$key])) {
                throw DurableRetryActivationContractException::forCode(
                    DurableRetryActivationContractException::INVALID_AUTHORITY_BATCH
                );
            }
            $byKey[$key] = $entry;
        }

        $ordered = [];
        foreach ($requested as $identity) {
            $key = $identity->diagnosticKey();
            $ordered[] = $byKey[$key] ?? new DurableRetryLegacyAuthorityEntry(
                $identity,
                DurableRetryLegacyAuthorityResult::indeterminate(
                    DurableRetryIndeterminateReason::INCOMPLETE_RESULT
                )
            );
        }

        return new self($requested, $ordered);
    }

    public static function indeterminateAll(
        DurableRetryAuthorityIdentityCollection $requested,
        string $reason
    ): self {
        DurableRetryIndeterminateReason::assert($reason);
        $entries = [];
        foreach ($requested as $identity) {
            $entries[] = new DurableRetryLegacyAuthorityEntry(
                $identity,
                DurableRetryLegacyAuthorityResult::indeterminate($reason)
            );
        }

        return new self($requested, $entries);
    }

    public function requested(): DurableRetryAuthorityIdentityCollection
    {
        return $this->requested;
    }

    public function forIdentity(
        DurableRetryAuthorityIdentity $identity
    ): DurableRetryLegacyAuthorityResult {
        if (! $this->requested->contains($identity)) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_AUTHORITY_BATCH
            );
        }

        foreach ($this->entries as $entry) {
            if ($entry->identity()->equals($identity)) {
                return $entry->result();
            }
        }

        throw DurableRetryActivationContractException::forCode(
            DurableRetryActivationContractException::CONTRACT_VIOLATION
        );
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }
}
