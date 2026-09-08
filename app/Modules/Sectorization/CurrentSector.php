<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Sectorization;
use VeciAhorra\Core\Session;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Cart\Service\CartAggregate;
use VeciAhorra\Modules\Frontend\Support\CartSession;
final class CurrentSector
{
    private const META='_veciahorra_service_zone_id';
    private const SESSION='veciahorra_service_zone_id';
    public function __construct(private ServiceZoneRepository $zones=new ServiceZoneRepository()){}
    public function owner():array{return is_user_logged_in()?['user_id'=>get_current_user_id()]:['session_id'=>(new CartSession())->identifier()];}
    public function id():int
    {
        global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;
        // After lazy adoption, the aggregate persists the selection in the same transaction as its version.
        $cart=$wpdb->get_row($wpdb->prepare("SELECT service_zone_id FROM {$p}carts WHERE owner_key=%s ORDER BY id DESC LIMIT 1",CartAggregate::ownerKey($this->owner())),ARRAY_A);
        if($wpdb->last_error!=='')throw new \DomainException('cart_sector_read_failed');
        $id=$cart!==null?(int)$cart['service_zone_id']:$this->legacyId();
        return $id>0&&$this->zones->findActive($id)!==null?$id:0;
    }
    public function legacyId():int
    {return self::normalizedId(is_user_logged_in()?get_user_meta(get_current_user_id(),self::META,true):Session::get(self::SESSION,0));}
    public function set(int $id,array $version=[]):array
    {
        $zone=$this->zones->findActive($id)??throw new \InvalidArgumentException('El sector no existe o esta inactivo.');
        $result=(new CartAggregate())->changeZone([...$version,...$this->owner()],$id,fn()=>$zone);
        return $result;
    }
    public function current():?array{$id=$this->id();return $id?$this->zones->findActive($id):null;}
    private static function normalizedId(mixed $value):int
    {if(is_int($value))return max(0,$value);return is_string($value)&&preg_match('/^[1-9]\d*$/D',$value)?max(0,(int)$value):0;}
}
