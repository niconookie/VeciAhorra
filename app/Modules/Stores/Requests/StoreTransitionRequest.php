<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Requests;

use InvalidArgumentException;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;

final class StoreTransitionRequest
{
    public function __construct(private array $payload)
    {
    }

    public function validated(): string
    {
        if (array_keys($this->payload) !== ['action']) {
            throw new InvalidArgumentException('El payload debe contener unicamente action.');
        }
        $action = $this->payload['action'];
        if (! is_string($action) || ! in_array($action, [
            StoreLifecycleContract::ACTION_SUBMIT_FOR_REVIEW,
            StoreLifecycleContract::ACTION_RETURN_TO_DRAFT,
            StoreLifecycleContract::ACTION_APPROVE,
            StoreLifecycleContract::ACTION_REJECT,
            StoreLifecycleContract::ACTION_ACTIVATE,
            StoreLifecycleContract::ACTION_DEACTIVATE,
        ], true)) {
            throw new InvalidArgumentException('La accion de ciclo de vida no es valida.');
        }

        return $action;
    }
}
