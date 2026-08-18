<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Identity;

final class ZonalAdminRole
{
    public const ROLE = 'veciahorra_zonal_admin';
    public const CAPABILITY_READ = 'veciahorra_review_zonal_stores';
    public const CAPABILITY_DECIDE = 'veciahorra_decide_zonal_stores';

    public static function register(): void
    {
        add_role(self::ROLE, 'Administrador zonal VeciAhorra', [
            'read' => true,
            self::CAPABILITY_READ => true,
            self::CAPABILITY_DECIDE => true,
        ]);
        $role = get_role(self::ROLE);
        if ($role instanceof \WP_Role) {
            $expected = ['read', self::CAPABILITY_READ, self::CAPABILITY_DECIDE];
            foreach (array_keys($role->capabilities) as $capability) {
                if (! in_array($capability, $expected, true)) {
                    $role->remove_cap($capability);
                }
            }
            foreach ($expected as $capability) {
                if (! $role->has_cap($capability)) {
                    $role->add_cap($capability);
                }
            }
        }
        $administrator = get_role('administrator');
        if ($administrator instanceof \WP_Role) {
            if (! $administrator->has_cap(self::CAPABILITY_READ)) {
                $administrator->add_cap(self::CAPABILITY_READ);
            }
            if (! $administrator->has_cap(self::CAPABILITY_DECIDE)) {
                $administrator->add_cap(self::CAPABILITY_DECIDE);
            }
        }
    }
}
