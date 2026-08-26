<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

final class LaunchGate
{
    public const REGISTRATION_FLAG = 'VECIAHORRA_PUBLIC_REGISTRATION_ENABLED';
    public const COMMERCE_FLAG = 'VECIAHORRA_PUBLIC_COMMERCE_ENABLED';
    public const REGISTRATION_MESSAGE = 'Estamos preparando VeciAhorra. El registro estará disponible desde el 1 de septiembre.';
    public const COMMERCE_MESSAGE = 'Estamos preparando VeciAhorra. Las compras estarán disponibles desde el 1 de septiembre.';

    public function registrationEnabled(): bool
    {
        return $this->enabled(self::REGISTRATION_FLAG);
    }

    public function commerceEnabled(): bool
    {
        return $this->enabled(self::COMMERCE_FLAG);
    }

    public static function evaluate(bool $exists, mixed $value, string $environment): bool
    {
        if ($exists) {
            return is_bool($value) && $value === true;
        }

        return in_array(strtolower(trim($environment)), [
            'local', 'development', 'staging',
        ], true);
    }

    private function enabled(string $constant): bool
    {
        return self::evaluate(
            defined($constant),
            defined($constant) ? constant($constant) : null,
            function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production'
        );
    }
}
