<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Policies;

use InvalidArgumentException;

final readonly class OrderAdminActionDecision
{
    public function __construct(
        public string $actionCode,
        public bool $available,
        public string $reasonCode,
        public bool $requiresConfirmation,
        public string $risk,
        public ?string $stage
    ) {
        if ($actionCode !== OrderAdminActionPolicy::RETRY_DURABLE_PROCESSING) {
            throw new InvalidArgumentException('Unsupported administrative action.');
        }
        if (! in_array($reasonCode, OrderAdminActionPolicy::REASON_CODES, true)) {
            throw new InvalidArgumentException('Unsupported administrative action reason.');
        }
        if (! in_array($risk, OrderAdminActionPolicy::RISKS, true)) {
            throw new InvalidArgumentException('Unsupported administrative action risk.');
        }
        if ($stage !== null && ! in_array($stage, OrderAdminActionPolicy::STAGES, true)) {
            throw new InvalidArgumentException('Unsupported durable stage.');
        }
        if ($available !== ($reasonCode === 'available')) {
            throw new InvalidArgumentException('Contradictory administrative action availability.');
        }
        if ($available && $stage === null) {
            throw new InvalidArgumentException('An available action requires a durable stage.');
        }
    }

    /** @return array{action_code:string,available:bool,reason_code:string,requires_confirmation:bool,risk:string,stage:?string} */
    public function toArray(): array
    {
        return [
            'action_code' => $this->actionCode,
            'available' => $this->available,
            'reason_code' => $this->reasonCode,
            'requires_confirmation' => $this->requiresConfirmation,
            'risk' => $this->risk,
            'stage' => $this->stage,
        ];
    }
}
