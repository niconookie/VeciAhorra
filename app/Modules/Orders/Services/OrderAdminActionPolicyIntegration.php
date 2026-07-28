<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Orders\Domain\Policies\OrderAdminActionDecision;
use VeciAhorra\Modules\Orders\Domain\Policies\OrderAdminActionPolicy;

/**
 * Private, in-memory composition point for future backend consumers.
 *
 * Availability is not an execution guarantee. A future authority adapter must
 * reload and revalidate durable facts before delegating any work.
 */
final readonly class OrderAdminActionPolicyIntegration
{
    public function __construct(
        private OrderOperationalFactsAssembler $assembler = new OrderOperationalFactsAssembler(),
        private OrderOperationalStateResolver $resolver = new OrderOperationalStateResolver(),
        private OrderAdminActionPolicy $policy = new OrderAdminActionPolicy()
    ) {
    }

    public function evaluate(
        array $base,
        array $bundle,
        string $observedAt,
        DateTimeImmutable $now
    ): OrderAdminActionDecision {
        try {
            $facts = $this->assembler->assemble($base, $bundle, $observedAt);
            $resolution = $this->resolver->resolve($facts);
        } catch (InvalidArgumentException) {
            return new OrderAdminActionDecision(
                OrderAdminActionPolicy::RETRY_DURABLE_PROCESSING,
                false,
                'insufficient_facts',
                true,
                'medium',
                null
            );
        }

        return $this->policy->evaluate(
            OrderAdminActionPolicy::RETRY_DURABLE_PROCESSING,
            $facts,
            $resolution,
            $now
        );
    }
}
