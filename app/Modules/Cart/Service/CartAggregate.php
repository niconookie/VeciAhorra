<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Cart\Service;
use DomainException;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\TerritorialAuthority;

/** All line access requires this transaction-scoped aggregate context. */
final class CartAggregate
{
    private static ?array $context=null;
    public static function ownerKey(array $owner):string
    {
        if(is_int($owner['user_id']??null)&&$owner['user_id']>0)return hash('sha256','user:'.$owner['user_id']);
        if(is_string($owner['session_id']??null)&&preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$owner['session_id']))return hash('sha256','session:'.$owner['session_id']);
        throw new DomainException('cart_owner_required');
    }
    public static function context(array $owner):array
    {
        global $wpdb;
        if(self::$context===null||self::$context['owner_key']!==self::ownerKey($owner)||(int)$wpdb->get_var('SELECT @@in_transaction')!==1)throw new DomainException('cart_lock_required');
        return self::$context;
    }
    public static function meta(array $cart):array
    {
        return ['cart_id'=>$cart['public_id'],'version'=>(int)$cart['version'],'status'=>$cart['status'],'fulfillment_method'=>$cart['fulfillment_method'],'service_zone_id'=>$cart['service_zone_id']===null?null:(int)$cart['service_zone_id']];
    }
    public function read(array $owner,callable $reader):mixed
    {
        if(self::$context!==null){self::context($owner);return $reader(self::$context);}
        return (new CheckoutRepository())->transaction(function()use($owner,$reader){$cart=$this->active($owner);return $this->within($cart,fn()=>$reader($cart));});
    }
    public function mutate(array $owner,callable $operation,?callable $snapshot=null):array
    {
        return (new CheckoutRepository())->transaction(function()use($owner,$operation,$snapshot):array{
            $cart=$this->requested($owner,'expected_version');
            return $this->within($cart,function()use($owner,$operation,$cart,$snapshot):array{
                $before=$this->semantic($cart);
                $result=$operation($cart);
                $stored=$this->one('SELECT * FROM '.$this->p().'carts WHERE id=%d',$cart['id']);
                if($before!==$this->semantic($stored))$this->bump($cart);
                self::$context=$this->one('SELECT * FROM '.$this->p().'carts WHERE id=%d',$cart['id']);
                return ['result'=>$result,'cart'=>$snapshot!==null?$snapshot():self::meta(self::$context)];
            });
        });
    }
    public function inspect(array $owner,callable $operation):array
    {
        return (new CheckoutRepository())->transaction(function()use($owner,$operation){
            $cart=$this->requested($owner,'expected_cart_version');
            if(($owner['fulfillment_method']??null)!==$cart['fulfillment_method'])throw new DomainException('cart_modality_conflict');
            return $this->within($cart,fn()=>$operation($cart));
        });
    }
    /** Holds the same row lock for a proposal replay or a nested versioned mutation. */
    public function withCartLock(array $owner,callable $operation):mixed
    {
        return (new CheckoutRepository())->transaction(fn()=>$operation($this->lookup($owner)));
    }
    public function materialize(array $owner,callable $operation):array
    {
        return (new CheckoutRepository())->transaction(function()use($owner,$operation):array{
            global $wpdb;$p=$this->p();
            $cart=$this->lookup($owner);
            $version=$this->version($owner,'expected_cart_version');
            $key=$owner['idempotency_key']??null;
            if(!is_string($key)||strlen($key)<8||strlen($key)>128)throw new DomainException('cart_idempotency_required');
            $fingerprint=hash('sha256',json_encode([$cart['public_id'],$version,$owner['fulfillment_method']??null,$owner['delivery']??null],JSON_THROW_ON_ERROR));
            if($cart['status']==='consumed'&&hash_equals((string)$cart['materialization_key'],$key)&&hash_equals((string)$cart['materialization_fingerprint'],$fingerprint))return json_decode($cart['materialization_result'],true,512,JSON_THROW_ON_ERROR);
            $this->assertActive($cart,$version);
            $this->assertZone($cart);
            $this->assertLines($cart);
            if(($owner['fulfillment_method']??null)!==$cart['fulfillment_method'])throw new DomainException('cart_modality_conflict');
            $this->write($wpdb->update($p.'carts',['status'=>'materializing'],['id'=>$cart['id'],'status'=>'active','version'=>$version]));
            $cart['status']='materializing';
            return $this->within($cart,function()use($operation,$cart,$key,$fingerprint,$wpdb,$p):array{
                $result=$operation($cart);
                if(($result['valid']??false)!==true||empty($result['checkout']['checkout_id']))throw new DomainException('cart_materialization_invalid');
                $checkout=$this->one("SELECT id FROM {$p}checkouts WHERE public_id=%s AND source_cart_id=%d",$result['checkout']['checkout_id'],$cart['id']);
                if($this->rows("SELECT id FROM {$p}cart_items WHERE cart_id=%d",$cart['id'])!==[])throw new DomainException('cart_lines_not_consumed');
                $now=current_time('mysql',true);
                $this->write($wpdb->update($p.'carts',['status'=>'consumed','active_owner'=>null,'version'=>(int)$cart['version']+1,'consumed_at'=>$now,'updated_at'=>$now,'materialization_key'=>$key,'materialization_fingerprint'=>$fingerprint,'materialization_result'=>json_encode($result,JSON_THROW_ON_ERROR)],['id'=>$cart['id'],'status'=>'materializing','version'=>$cart['version']]));
                return $result;
            });
        });
    }
    public function setMethod(array $owner,string $method):array
    {
        if(!in_array($method,['pickup','delivery'],true))throw new DomainException('cart_method_invalid');
        return $this->mutate($owner,function($cart)use($method){global $wpdb;if($wpdb->update($this->p().'carts',['fulfillment_method'=>$method],['id'=>$cart['id']])===false)throw new DomainException('cart_write_failed');},fn()=>(new CartService(new \VeciAhorra\Modules\Cart\Repository\CartRepository()))->getPublicCart($owner));
    }
    public function changeZone(array $owner,int $zone,callable $persist):array
    {
        // Lock first even when the old selection is inactive; only empty carts can move.
        return (new CheckoutRepository())->transaction(function()use($owner,$zone,$persist):array{
            global $wpdb;$cart=$this->lookup($owner);$this->assertActive($cart,$this->version($owner,'expected_version'));
            (new TerritorialAuthority())->activeZone($zone,true);
            foreach($this->rows('SELECT minimarket_id FROM '.$this->p().'cart_items WHERE cart_id=%d',$cart['id']) as $line)(new TerritorialAuthority())->storeInZone($zone,(int)$line['minimarket_id'],true);
            if((int)$cart['service_zone_id']!==$zone){
                $this->write($wpdb->update($this->p().'carts',['service_zone_id'=>$zone],['id'=>$cart['id']]));$this->bump($cart);
            }
            $result=$persist();
            return ['sector'=>$result,'cart'=>self::meta($this->one('SELECT * FROM '.$this->p().'carts WHERE id=%d',$cart['id']))];
        });
    }
    public function consumeLines(array $owner):void
    {
        global $wpdb;$cart=self::context($owner);
        if($cart['status']!=='materializing')throw new DomainException('cart_materializing_required');
        if($wpdb->delete($this->p().'cart_items',['cart_id'=>$cart['id']])===false)throw new DomainException('cart_consume_failed');
    }
    private function active(array $owner):array
    {
        global $wpdb;$p=$this->p();$key=self::ownerKey($owner);
        $rows=$this->rows("SELECT * FROM {$p}carts WHERE active_owner=%s FOR UPDATE",$key);
        $created=$rows===[];
        if($rows===[]){
            $now=current_time('mysql',true);$zone=(new CurrentSector())->id();
            $sql=$wpdb->prepare("INSERT INTO {$p}carts (public_id,owner_key,active_owner,user_id,session_id,status,version,fulfillment_method,service_zone_id,created_at,updated_at) VALUES (%s,%s,%s,".(isset($owner['user_id'])?'%d,NULL':'NULL,%s').",'active',1,'pickup',".($zone>0?'%d':'NULL').",%s,%s) ON DUPLICATE KEY UPDATE id=id",...[... [bin2hex(random_bytes(24)),$key,$key,$owner['user_id']??$owner['session_id']],...($zone>0?[$zone]:[]),$now,$now]);
            if($wpdb->query($sql)===false)throw new DomainException('cart_create_failed');
            $rows=$this->rows("SELECT * FROM {$p}carts WHERE active_owner=%s FOR UPDATE",$key);
        }
        if(count($rows)!==1||$rows[0]['status']!=='active')throw new DomainException('cart_not_active');
        $cart=$rows[0];$field=isset($owner['user_id'])?'user_id':'session_id';$value=$owner[$field];
        $legacy=$this->rows("SELECT * FROM {$p}cart_items WHERE {$field}=".($field==='user_id'?'%d':'%s')." AND cart_id IS NULL FOR UPDATE",$value);
        foreach($legacy as $line){
            if(($field==='user_id'&&$line['session_id']!==null)||($field==='session_id'&&$line['user_id']!==null))throw new DomainException('cart_legacy_owner_ambiguous');
            (new TerritorialAuthority())->storeInZone((int)$cart['service_zone_id'],(int)$line['minimarket_id'],true);
            $inventory=$this->one("SELECT product_id,minimarket_id FROM {$p}inventory WHERE id=%d",$line['inventory_id']);
            if($inventory['product_id']!==$line['product_id']||$inventory['minimarket_id']!==$line['minimarket_id']||(int)$line['quantity']<=0)throw new DomainException('cart_legacy_inconsistent');
            $this->write($wpdb->update($p.'cart_items',['cart_id'=>$cart['id']],['id'=>$line['id'],'cart_id'=>null]));
        }
        $this->assertLines($cart);
        if(!$created&&$legacy!==[]){$this->bump($cart);$cart=$this->one("SELECT * FROM {$p}carts WHERE id=%d",$cart['id']);}
        return $cart;
    }
    private function assertLines(array $cart):void
    {
        foreach($this->rows('SELECT * FROM '.$this->p().'cart_items WHERE cart_id=%d',$cart['id']) as $line){
            if(self::ownerKey(['user_id'=>$line['user_id']===null?null:(int)$line['user_id'],'session_id'=>$line['session_id']])!==$cart['owner_key']||($line['user_id']!==null&&$line['session_id']!==null))throw new DomainException('cart_line_owner_conflict');
            if((int)$line['quantity']<1||preg_match('/^[0-9]+\.[0-9]{2}$/D',(string)$line['unit_price_snapshot'])!==1||(float)$line['unit_price_snapshot']<=0)throw new DomainException('cart_line_invalid');
        }
    }
    private function lookup(array $owner):array
    {
        if(!is_string($owner['cart_id']??null)||preg_match('/^[a-f0-9]{48}$/D',$owner['cart_id'])!==1)throw new DomainException('cart_identity_required');
        return $this->one('SELECT * FROM '.$this->p().'carts WHERE public_id=%s AND owner_key=%s FOR UPDATE',$owner['cart_id'],self::ownerKey($owner));
    }
    private function requested(array $owner,string $field):array{$cart=$this->lookup($owner);$this->assertActive($cart,$this->version($owner,$field));$this->assertZone($cart);$this->assertLines($cart);return $cart;}
    private function version(array $owner,string $field):int{if(!is_int($owner[$field]??null)||$owner[$field]<1)throw new DomainException('expected_cart_version_required');return $owner[$field];}
    private function assertActive(array $cart,int $version):void{if($cart['status']!=='active'||(int)$cart['version']!==$version)throw new DomainException('cart_version_conflict');}
    private function assertZone(array $cart):void{if((int)$cart['service_zone_id']!==(new CurrentSector())->id())throw new DomainException('cart_zone_conflict');}
    private function bump(array $cart):void{global $wpdb;$this->write($wpdb->query($wpdb->prepare('UPDATE '.$this->p()."carts SET version=version+1,updated_at=%s WHERE id=%d AND version=%d AND status='active'",current_time('mysql',true),$cart['id'],$cart['version'])));}
    private function semantic(array $cart):string{return hash('sha256',json_encode([$cart['fulfillment_method'],$cart['service_zone_id'],$this->rows('SELECT inventory_id,product_id,minimarket_id,quantity,unit_price_snapshot FROM '.$this->p().'cart_items WHERE cart_id=%d ORDER BY inventory_id',$cart['id'])],JSON_THROW_ON_ERROR));}
    private function within(array $cart,callable $callback):mixed{$previous=self::$context;self::$context=$cart;try{return $callback();}finally{self::$context=$previous;}}
    private function p():string{global $wpdb;return $wpdb->prefix.Config::TABLE_PREFIX;}
    private function rows(string $sql,mixed ...$args):array{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A);if($wpdb->last_error!=='')throw new DomainException('cart_read_failed');return $rows;}
    private function one(string $sql,mixed ...$args):array{$rows=$this->rows($sql,...$args);if(count($rows)!==1)throw new DomainException('cart_not_found');return $rows[0];}
    private function write(int|bool $n):void{if($n!==1)throw new DomainException('cart_write_conflict');}
}
