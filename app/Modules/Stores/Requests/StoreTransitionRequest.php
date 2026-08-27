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

    public function validated(): array
    {
        if (array_keys($this->payload) !== ['action', 'reason', 'expected_updated_at']) {
            throw new InvalidArgumentException('El payload debe contener action, reason y expected_updated_at en ese orden.');
        }
        $action = $this->payload['action'];
        if (! is_string($action) || ! in_array($action, [
            StoreLifecycleContract::ACTION_SUBMIT_FOR_REVIEW,
            StoreLifecycleContract::ACTION_RETURN_TO_DRAFT,
            StoreLifecycleContract::ACTION_APPROVE,
            StoreLifecycleContract::ACTION_REJECT,
            StoreLifecycleContract::ACTION_OBSERVE,
            StoreLifecycleContract::ACTION_ACTIVATE,
            StoreLifecycleContract::ACTION_DEACTIVATE,
        ], true)) {
            throw new InvalidArgumentException('La accion de ciclo de vida no es valida.');
        }

        $expected = $this->payload['expected_updated_at'] ?? null;
        $date = is_string($expected) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expected) : false;
        if ($date === false || $date->format('Y-m-d H:i:s') !== $expected) {
            throw new InvalidArgumentException('expected_updated_at es obligatorio y no es valido.');
        }
        $reason = $this->payload['reason'] ?? null;
        if (! ($reason === null || is_string($reason))) throw new InvalidArgumentException('El motivo debe ser texto o null.');
        $reason = $reason === null ? null : trim(sanitize_textarea_field($reason));
        if ($reason !== null && strlen($reason) > 1000) throw new InvalidArgumentException('El motivo no puede superar 1000 caracteres.');
        if (in_array($action, [StoreLifecycleContract::ACTION_OBSERVE, StoreLifecycleContract::ACTION_REJECT], true) && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('El motivo es obligatorio.');
        }
        return ['action' => $action, 'reason' => $reason === '' ? null : $reason, 'expected_updated_at' => $expected];
    }
}
