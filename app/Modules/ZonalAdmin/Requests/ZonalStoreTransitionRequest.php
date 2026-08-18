<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Requests;

final class ZonalStoreTransitionRequest
{
    public function __construct(private array $input) {}

    public function validated(): array
    {
        $allowed = ['action', 'reason', 'expected_updated_at'];
        if (array_diff(array_keys($this->input), $allowed) !== []) {
            throw new \InvalidArgumentException('El payload contiene campos inesperados.');
        }
        $action = $this->input['action'] ?? null;
        if (! is_string($action) || ! in_array($action, ['approve', 'observe', 'reject'], true)) {
            throw new \InvalidArgumentException('La accion no es valida.');
        }
        $expected = $this->input['expected_updated_at'] ?? null;
        if (! is_string($expected) || ! $this->timestamp($expected)) {
            throw new \InvalidArgumentException('expected_updated_at es obligatorio y no es valido.');
        }
        $reason = $this->input['reason'] ?? null;
        if (! ($reason === null || is_string($reason))) {
            throw new \InvalidArgumentException('El motivo debe ser texto o null.');
        }
        $reason = $reason === null ? null : trim(sanitize_textarea_field($reason));
        if ($reason !== null && strlen($reason) > 1000) {
            throw new \InvalidArgumentException('El motivo no puede superar 1000 caracteres.');
        }
        if (in_array($action, ['observe', 'reject'], true) && ($reason === null || $reason === '')) {
            throw new \InvalidArgumentException('El motivo es obligatorio.');
        }
        return ['action' => $action, 'reason' => $reason === '' ? null : $reason, 'expected_updated_at' => $expected];
    }

    private function timestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }
}
