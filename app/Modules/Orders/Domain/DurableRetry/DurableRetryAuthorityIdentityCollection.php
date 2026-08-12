<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryAuthorityIdentityCollection implements Countable, IteratorAggregate
{
    public const MAX_IDENTITIES = 500;

    private function __construct(private readonly array $identities)
    {
    }

    public static function fromIdentities(
        DurableRetryAuthorityIdentity ...$identities
    ): self {
        if (count($identities) > self::MAX_IDENTITIES) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_IDENTITY_COLLECTION
            );
        }

        $seen = [];
        foreach ($identities as $identity) {
            $key = $identity->diagnosticKey();
            if (isset($seen[$key])) {
                throw DurableRetryActivationContractException::forCode(
                    DurableRetryActivationContractException::INVALID_IDENTITY_COLLECTION
                );
            }
            $seen[$key] = true;
        }

        return new self(array_values($identities));
    }

    public function count(): int
    {
        return count($this->identities);
    }

    public function isEmpty(): bool
    {
        return $this->identities === [];
    }

    public function contains(DurableRetryAuthorityIdentity $identity): bool
    {
        foreach ($this->identities as $candidate) {
            if ($candidate->equals($identity)) {
                return true;
            }
        }

        return false;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->identities);
    }
}
