<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\ServiceProviders\Identity;
final class ServiceProviderRole{public const ROLE='veciahorra_service_provider';public const CAPABILITY='veciahorra_manage_service_profile';public const META_KEY='_veciahorra_service_provider_id';public static function register():void{$r=add_role(self::ROLE,'Prestador VeciAhorra',['read'=>true,self::CAPABILITY=>true]);if($r instanceof \WP_Role)$r->add_cap(self::CAPABILITY);}}
