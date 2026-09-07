<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Routes;

use VeciAhorra\Modules\Couriers\Identity\CourierContext;
use VeciAhorra\Modules\Couriers\Service\CourierDeliveryService;
use WP_Error; use WP_REST_Request; use WP_REST_Response;

final class CourierRoutes
{
    public function __construct(private CourierContext $context=new CourierContext(),private CourierDeliveryService $service=new CourierDeliveryService()){}
    public function register():void
    {
        foreach([['/courier/me','GET','me'],['/courier/deliveries/available','GET','available'],['/courier/deliveries','GET','owned'],['/courier/deliveries/(?P<id>\d+)','GET','detail'],['/courier/deliveries/(?P<id>\d+)/accept','POST','accept'],['/courier/deliveries/(?P<id>\d+)/picked-up','POST','pickedUp'],['/courier/deliveries/(?P<id>\d+)/delivered','POST','delivered'],['/courier/deliveries/(?P<id>\d+)/abandon','POST','abandon']] as [$path,$method,$callback]) register_rest_route('veciahorra/v1',$path,['methods'=>$method,'callback'=>[$this,$callback],'permission_callback'=>[$this,'permission']]);
    }
    public function permission():bool|WP_Error{return $this->context->resolve()!==null?true:new WP_Error('courier_forbidden','Courier no autorizado.',['status'=>403]);}
    private function courier():array{return $this->context->resolve()??throw new \RuntimeException('Courier no autorizado.');}
    public function me():WP_REST_Response{$c=$this->courier();return $this->ok(['id'=>(int)$c['id'],'display_name'=>$c['display_name'],'phone'=>$c['phone'],'email'=>$c['email'],'status'=>$c['status'],'service_zone_id'=>(int)$c['service_zone_id'],'service_zone_name'=>$c['service_zone_name']]);}
    public function available():WP_REST_Response{return $this->ok($this->service->available((int)$this->courier()['id']));}
    public function owned():WP_REST_Response{return $this->ok($this->service->owned((int)$this->courier()['id']));}
    public function detail(WP_REST_Request $r):WP_REST_Response{$d=$this->service->detail((int)$r['id'],(int)$this->courier()['id']);return $d===null?$this->error('delivery_not_found',404):$this->ok($d);}
    public function accept(WP_REST_Request $r):WP_REST_Response{return $this->mutate(fn()=>$this->service->accept((int)$r['id'],(int)$this->courier()['id'],$this->version($r)));}
    public function pickedUp(WP_REST_Request $r):WP_REST_Response{return $this->mutate(fn()=>$this->service->transition((int)$r['id'],(int)$this->courier()['id'],'picked_up',$this->version($r)));}
    public function delivered(WP_REST_Request $r): WP_REST_Response
    {
        return $this->mutate(function() use($r):array {
            $upload=$r->get_file_params()['photo']??[];
            if($upload!==[]&&(!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])))throw new \DomainException('photo_invalid');
            foreach(['recipient_visible','recipient_consent'] as $field)if(!in_array($r[$field]??'0',['0','1',0,1,false,true],true))throw new \DomainException('invalid_consent');
            return (new \VeciAhorra\Modules\Couriers\Evidence\DeliveryProofService())->confirm(
                (int)$r['id'],$this->version($r),(string)($r['otp']??''),$upload,
                in_array($r['recipient_visible']??'0',['1',1,true],true),in_array($r['recipient_consent']??'0',['1',1,true],true)
            );
        });
    }
    private function version(WP_REST_Request $r): int
    {
        $value = $r['expected_version'] ?? null;
        if (filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]) === false || $value === null) throw new \DomainException('expected_version_required');
        return (int)$value;
    }
    public function abandon(WP_REST_Request $r): WP_REST_Response
    {
        return $this->mutate(fn()=>$this->service->abandon((int)$r['id'],(int)$this->courier()['id'],(string)($r['reason']??''),$this->version($r)));
    }
    private function mutate(callable $cb):WP_REST_Response{try{return $this->ok($cb());}catch(\OutOfBoundsException){return $this->error('delivery_not_found',404);}catch(\DomainException $e){return $this->error($e->getMessage(),409);}catch(\Throwable){return $this->error('internal_error',500);}}
    private function ok(mixed $data):WP_REST_Response{return new WP_REST_Response(['success'=>true,'data'=>$data],200);}
    private function error(string $code,int $status):WP_REST_Response{return new WP_REST_Response(['success'=>false,'error'=>['code'=>$code,'message'=>'No fue posible completar la operacion.']],$status);}
}
