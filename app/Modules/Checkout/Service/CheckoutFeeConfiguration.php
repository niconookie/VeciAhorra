<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use InvalidArgumentException;

final class CheckoutFeeConfiguration
{
    public const OPTION = 'veciahorra_checkout_fee_configuration';
    public const POLICY_VERSION = 'checkout-fees-v1';
    public const DEFAULT_PLATFORM_FEE_CLP = 700;
    public const DEFAULT_DELIVERY_FEE_CLP = 1000;
    public const DEFAULT_DELIVERY_MINIMUM_SUBTOTAL_CLP = 8000;
    public const MAX_CLP = 10000000;

    /** @return array{platform_fee_clp:int,delivery_fee_clp:int,delivery_minimum_subtotal_clp:int,policy_version:string} */
    public function current(): array
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, null) : null;
        $stored = is_array($stored) ? $stored : [];

        return [
            'platform_fee_clp' => $this->storedValue($stored, 'platform_fee_clp', self::DEFAULT_PLATFORM_FEE_CLP),
            'delivery_fee_clp' => $this->storedValue($stored, 'delivery_fee_clp', self::DEFAULT_DELIVERY_FEE_CLP),
            'delivery_minimum_subtotal_clp' => $this->storedValue($stored, 'delivery_minimum_subtotal_clp', self::DEFAULT_DELIVERY_MINIMUM_SUBTOTAL_CLP),
            'policy_version' => self::POLICY_VERSION,
        ];
    }

    /** @return array{platform_fee_clp:int,delivery_fee_clp:int,delivery_minimum_subtotal_clp:int,policy_version:string} */
    public function validate(array $input): array
    {
        $allowed = ['platform_fee_clp', 'delivery_fee_clp', 'delivery_minimum_subtotal_clp'];
        $provided = array_keys($input);
        sort($provided, SORT_STRING);
        $expected = $allowed;
        sort($expected, SORT_STRING);
        if ($provided !== $expected) {
            throw new InvalidArgumentException('La configuracion de cargos no es canonica.');
        }

        $validated = [];
        foreach ($allowed as $field) {
            $validated[$field] = $this->canonicalClp($input[$field] ?? null, $field);
        }
        $validated['policy_version'] = self::POLICY_VERSION;

        return $validated;
    }

    public function save(array $input): array
    {
        $validated = $this->validate($input);
        $stored = $validated;
        unset($stored['policy_version']);
        if (! function_exists('update_option') || ! update_option(self::OPTION, $stored, false)) {
            $current = function_exists('get_option') ? get_option(self::OPTION, null) : null;
            if ($current !== $stored) {
                throw new \RuntimeException('No fue posible guardar la configuracion de cargos.');
            }
        }
        return $validated;
    }

    private function storedValue(array $stored, string $field, int $default): int
    {
        try {
            return $this->canonicalClp($stored[$field] ?? null, $field);
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    private function canonicalClp(mixed $value, string $field): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            $value = $parsed === false ? null : $parsed;
        }
        if (! is_int($value) || $value < 0 || $value > self::MAX_CLP) {
            throw new InvalidArgumentException("{$field} debe ser un entero CLP entre 0 y " . self::MAX_CLP . '.');
        }
        return $value;
    }
}
