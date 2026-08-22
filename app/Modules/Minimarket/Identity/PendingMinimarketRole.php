<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Identity;
use RuntimeException;
final class PendingMinimarketRole
{
    public const ROLE='veciahorra_minimarket_pending';
    public const CAPABILITY='veciahorra_continue_store_onboarding';
    private const EXPECTED=['read'=>true,self::CAPABILITY=>true];
    public function register():void
    {
        $role=get_role(self::ROLE);
        if($role===null)$role=add_role(self::ROLE,'Minimarket pendiente',self::EXPECTED);
        if(!$role instanceof \WP_Role)throw new RuntimeException('pending_role_unavailable');
        $this->assertCompatible($role);
    }
    public function assertCompatible(?\WP_Role $role=null):void
    {
        $role??=get_role(self::ROLE);
        if(!$role instanceof \WP_Role)throw new RuntimeException('pending_role_unavailable');
        $actual=array_filter($role->capabilities,static fn(mixed $v):bool=>$v===true);
        ksort($actual);$expected=self::EXPECTED;ksort($expected);
        if($actual!==$expected)throw new RuntimeException('pending_role_drift');
    }
}
