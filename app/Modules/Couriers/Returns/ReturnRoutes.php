<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Returns;
use DomainException;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Couriers\Identity\CourierContext;
use VeciAhorra\Modules\Couriers\Identity\CourierRole;
use VeciAhorra\Modules\Minimarket\Identity\StoreContext;
final class ReturnRoutes
{
    public function register():void
    {
        foreach([['/courier/deliveries/(?P<id>\d+)/return','POST','open','courier'],['/courier/returns','GET','owned','courierRead'],['/minimarket/returns','GET','pending','store'],['/minimarket/returns/(?P<id>\d+)/receive','POST','receive','store']] as [$path,$method,$callback,$permission])register_rest_route('veciahorra/v1',$path,['methods'=>$method,'callback'=>[$this,$callback],'permission_callback'=>[$this,$permission]]);
    }
    public function courier():bool{return(new CourierContext())->resolve()!==null;}
    public function courierRead():bool{return is_user_logged_in()&&current_user_can(CourierRole::CAPABILITY)&&(int)get_user_meta(get_current_user_id(),CourierRole::META_KEY,true)>0;}
    public function store():bool{return!is_wp_error((new StoreContext())->current());}
    public function open(\WP_REST_Request $r):\WP_REST_Response{return$this->response(function()use($r){if(!$this->courier())throw new DomainException('courier_forbidden');if($r['confirmed']!==true)throw new DomainException('return_confirmation_required');return(new ReturnService())->open((int)$r['id'],$this->version($r),$this->text($r['reason']),$this->text($r['observation']));});}
    public function receive(\WP_REST_Request $r):\WP_REST_Response{return$this->response(fn()=>(new ReturnService())->receive((int)$r['id'],$this->version($r),$this->text($r['condition']),$this->text($r['observation'])));}
    private function version(\WP_REST_Request $r):int{$v=$r['expected_version'];if($v===null||is_bool($v)||filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new DomainException('expected_version_required');return(int)$v;}
    private function text(mixed $v):string{if(!is_string($v))throw new DomainException('invalid_return_input');return$v;}
    public function owned():\WP_REST_Response{return$this->response(function(){if(!$this->courierRead())throw new DomainException('courier_forbidden');global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;return$wpdb->get_results($wpdb->prepare("SELECT d.id,d.order_id,d.status,s.business_name,s.address,s.commune,s.phone FROM {$p}deliveries d JOIN {$p}delivery_returns r ON r.delivery_id=d.id JOIN {$p}stores s ON s.id=r.store_id WHERE d.courier_id=%d AND d.status IN ('return_pending','returned_to_store') ORDER BY d.id DESC",(int)get_user_meta(get_current_user_id(),CourierRole::META_KEY,true)),ARRAY_A);});}
    public function pending():\WP_REST_Response{return$this->response(function(){$store=(new StoreContext())->current();if(is_wp_error($store))throw new DomainException('store_forbidden');global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;return$wpdb->get_results($wpdb->prepare("SELECT d.id,d.order_id,d.transition_version,r.opened_at FROM {$p}deliveries d JOIN {$p}delivery_returns r ON r.delivery_id=d.id JOIN {$p}orders o ON o.id=d.order_id AND o.minimarket_id=d.minimarket_id WHERE d.status='return_pending' AND d.minimarket_id=%d AND r.store_id=d.minimarket_id ORDER BY d.id",$store['id']),ARRAY_A);});}
    private function response(callable $cb):\WP_REST_Response{try{return new \WP_REST_Response(['success'=>true,'data'=>$cb()],200);}catch(DomainException $e){return new \WP_REST_Response(['success'=>false,'error'=>['code'=>$e->getMessage(),'message'=>'No fue posible registrar el retorno. Actualiza el panel.']],409);}catch(\Throwable){return new \WP_REST_Response(['success'=>false,'error'=>['code'=>'return_internal_error','message'=>'No fue posible registrar el retorno.']],500);}}
}
