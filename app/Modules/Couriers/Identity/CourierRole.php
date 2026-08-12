<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Identity;

final class CourierRole
{
    public const ROLE = 'veciahorra_courier';
    public const CAPABILITY = 'veciahorra_manage_deliveries';
    public const META_KEY = '_veciahorra_courier_id';

    public static function register(): void
    {
        $role = add_role(self::ROLE, 'Repartidor VeciAhorra', ['read'=>true, self::CAPABILITY=>true]);
        if ($role instanceof \WP_Role) $role->add_cap(self::CAPABILITY);
    }
}
