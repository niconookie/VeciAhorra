<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ServiceProviders\Domain;

final class ServicePlanCatalog
{
    public const PLANS = [
        'local' => ['label' => 'Plan Local', 'price' => 1000],
        'featured' => ['label' => 'Plan Destacado', 'price' => 2000],
        'communal' => ['label' => 'Plan Comunal', 'price' => 3000],
    ];

    public static function canonical(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, self::PLANS)
            ? $value
            : null;
    }

    public static function label(string $plan): string
    {
        return self::PLANS[$plan]['label'] ?? 'Plan no reconocido';
    }

    public static function featured(string $plan): bool
    {
        return in_array($plan, ['featured', 'communal'], true);
    }
}
