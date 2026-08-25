<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Products\Import;
use VeciAhorra\Core\Config;

final class ProductBulkImportRepository
{
    public function __construct(private ?\wpdb $database=null){global $wpdb;$this->database??=$wpdb;}
    public function product(string $sku,bool $lock=false):?array{$r=$this->db()->get_row($this->db()->prepare('SELECT * FROM '.$this->table('products').' WHERE sku=%s LIMIT 1'.($lock?' FOR UPDATE':''),$sku),ARRAY_A);return is_array($r)?$r:null;}
    public function term(string $taxonomy,string $name,?int $parent=null):?array{$sql="SELECT t.term_id,t.name,tt.parent FROM {$this->db()->terms} t JOIN {$this->db()->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s AND LOWER(t.name)=LOWER(%s)";$args=[$taxonomy,$name];if($parent!==null){$sql.=' AND tt.parent=%d';$args[]=$parent;}$rows=$this->db()->get_results($this->db()->prepare($sql,...$args),ARRAY_A);return count($rows)===1?$rows[0]:null;}
    public function slugExists(string $slug):bool{return(int)$this->db()->get_var($this->db()->prepare('SELECT COUNT(*) FROM '.$this->table('products').' WHERE slug=%s',$slug))>0;}
    public function begin():void{if($this->db()->query('START TRANSACTION')===false)throw new \RuntimeException('No fue posible iniciar la importación.');}
    public function commit():void{if($this->db()->query('COMMIT')===false)throw new \RuntimeException('No fue posible confirmar la importación.');}
    public function rollback():void{$this->db()->query('ROLLBACK');}
    public function create(array $d,string $now):int{if($this->db()->insert($this->table('products'),$d+['woo_product_id'=>null,'created_at'=>$now,'updated_at'=>$now])===false)throw new \RuntimeException('No fue posible crear el producto.');return(int)$this->db()->insert_id;}
    public function update(int $id,array $d,string $expected,string $now):void{$sets=[];$args=[];foreach($d as $k=>$v){$sets[]="{$k}=".($v===null?'NULL':'%s');if($v!==null)$args[]=$v;}$sets[]='updated_at=%s';$args[]=$now;$args[]=$id;$args[]=$expected;$n=$this->db()->query($this->db()->prepare('UPDATE '.$this->table('products').' SET '.implode(',',$sets).' WHERE id=%d AND updated_at=%s',...$args));if($n!==1)throw new \RuntimeException('El producto cambió después de la vista previa.');}
    public function nextUpdatedAt(string $expected):string{$deadline=microtime(true)+3;do{$now=current_time('mysql');if(strcmp($now,$expected)>0)return$now;usleep(25000);}while(microtime(true)<$deadline);throw new \RuntimeException('No fue posible obtener una versión durable posterior.');}
    public function inventoryCount():int{return(int)$this->db()->get_var('SELECT COUNT(*) FROM '.$this->table('inventory'));}
    private function db():\wpdb{if(!$this->database instanceof \wpdb)throw new \RuntimeException('Base de datos no disponible.');return$this->database;}
    private function table(string $n):string{return$this->db()->prefix.Config::TABLE_PREFIX.$n;}
}
