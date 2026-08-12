<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Sectorization;
use VeciAhorra\Core\Session;
final class CurrentSector { private const META='_veciahorra_service_zone_id';private const SESSION='veciahorra_service_zone_id';public function __construct(private ServiceZoneRepository $zones=new ServiceZoneRepository()){}public function id():int{$id=is_user_logged_in()?(int)get_user_meta(get_current_user_id(),self::META,true):(int)Session::get(self::SESSION,0);return $id>0&&$this->zones->findActive($id)!==null?$id:0;}public function set(int $id):array{$zone=$this->zones->findActive($id)??throw new \InvalidArgumentException('El sector no existe o está inactivo.');if(is_user_logged_in())update_user_meta(get_current_user_id(),self::META,$id);else Session::put(self::SESSION,$id);return $zone;}public function current():?array{$id=$this->id();return $id?$this->zones->findActive($id):null;}}
