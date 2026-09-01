<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\ServiceProviders\Repository;
use VeciAhorra\Database\Repository;use VeciAhorra\Exceptions\PersistenceException;
final class ServiceProviderRepository extends Repository
{
 private const TABLE='service_providers';
 public function find(int $id):?array{$r=$this->db()->get_row($this->db()->prepare('SELECT * FROM '.$this->table(self::TABLE).' WHERE id=%d LIMIT 1',$id),ARRAY_A);return $r===null?null:$r;}
 public function create(array $d):int{if($this->db()->insert($this->table(self::TABLE),$d)!==1)throw new PersistenceException('No fue posible crear el perfil.');return(int)$this->db()->insert_id;}
 public function update(int $id,array $d):void{if($this->db()->update($this->table(self::TABLE),$d,['id'=>$id])===false)throw new PersistenceException('No fue posible actualizar el perfil.');}
 public function all(array $f=[]):array{$where=['1=1'];$args=[];foreach(['status','plan','category_key'] as $key)if(($f[$key]??'')!==''){$where[]="$key=%s";$args[]=$f[$key];}$sql='SELECT * FROM '.$this->table(self::TABLE).' WHERE '.implode(' AND ',$where).' ORDER BY id DESC';return $args==[]?$this->db()->get_results($sql,ARRAY_A):$this->db()->get_results($this->db()->prepare($sql,...$args),ARRAY_A);}
 public function published(array $f=[]):array{$where=["status='published'"];$args=[];foreach(['category_key','subcategory_key','commune'] as $key)if(($f[$key]??'')!==''){$where[]="$key=%s";$args[]=$f[$key];}$sql='SELECT * FROM '.$this->table(self::TABLE).' WHERE '.implode(' AND ',$where)." ORDER BY CASE plan WHEN 'communal' THEN 0 WHEN 'featured' THEN 1 WHEN 'local' THEN 2 ELSE 3 END, published_at DESC, id DESC";return $args==[]?$this->db()->get_results($sql,ARRAY_A):$this->db()->get_results($this->db()->prepare($sql,...$args),ARRAY_A);}
}
