<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\ServiceProviders\Routes;
use VeciAhorra\Core\LaunchGate;use VeciAhorra\Modules\ServiceProviders\Domain\ServiceCatalog;use VeciAhorra\Modules\ServiceProviders\Identity\ServiceProviderRole;use VeciAhorra\Modules\ServiceProviders\Service\ServiceProviderService;use WP_Error;use WP_REST_Request;use WP_REST_Response;
final class ServiceProviderRoutes
{
 public function __construct(private ServiceProviderService $service=new ServiceProviderService()){}
 public function register():void
 {
  foreach([['/service-provider/categories','GET','categories','publicPermission'],['/service-provider/enroll','POST','enroll','authenticatedPermission'],['/service-provider/me','GET','me','privatePermission'],['/service-provider/profile','POST','save','privatePermission'],['/service-provider/submit','POST','submit','privatePermission'],['/services','GET','catalog','authenticatedPermission'],['/services/(?P<id>\d+)','GET','detail','authenticatedPermission']]as[$p,$m,$c,$permission])register_rest_route('veciahorra/v1',$p,['methods'=>$m,'callback'=>[$this,$c],'permission_callback'=>[$this,$permission]]);
 }
 public function publicPermission():bool{return true;}public function authenticatedPermission():bool|WP_Error{return is_user_logged_in()?true:new WP_Error('authentication_required','Inicio de sesión requerido.',['status'=>401]);}public function privatePermission():bool|WP_Error{return is_user_logged_in()&&current_user_can(ServiceProviderRole::CAPABILITY)?true:new WP_Error('provider_forbidden','Prestador no autorizado.',['status'=>403]);}
 private function id():int{return(int)get_user_meta(get_current_user_id(),ServiceProviderRole::META_KEY,true);}
 public function categories():WP_REST_Response{return$this->ok(ServiceCatalog::publicData());}
 public function enroll():WP_REST_Response{if(!(new LaunchGate())->registrationEnabled()){$r=$this->error('registration_disabled',503,LaunchGate::REGISTRATION_MESSAGE);$r->header('Cache-Control','private, no-store, max-age=0');$r->header('Pragma','no-cache');return$r;}$u=new \WP_User(get_current_user_id());$u->add_role(ServiceProviderRole::ROLE);$u->add_cap(ServiceProviderRole::CAPABILITY);return$this->ok(['enrolled'=>true]);}
 public function me():WP_REST_Response{$r=$this->service->private($this->service->repository()->find($this->id()));return$r?$this->ok($r):$this->error('not_found',404);}
 public function save(WP_REST_Request $r):WP_REST_Response{return$this->mutate(fn()=>$this->service->save(get_current_user_id(),(array)$r->get_json_params()));}
 public function submit():WP_REST_Response{return$this->mutate(fn()=>$this->service->submit($this->id()));}
 public function catalog(WP_REST_Request $r):WP_REST_Response{return$this->ok(array_map([$this->service,'public'],$this->service->repository()->published($r->get_query_params())));}
 public function detail(WP_REST_Request $r):WP_REST_Response{$row=$this->service->repository()->find((int)$r['id']);return$row&&$row['status']==='published'?$this->ok($this->service->public($row)):$this->error('not_found',404);}
 private function mutate(callable $c):WP_REST_Response{try{return$this->ok($c());}catch(\InvalidArgumentException $e){return$this->error('validation_error',422,$e->getMessage());}catch(\DomainException $e){return$this->error($e->getMessage(),409);}catch(\Throwable){return$this->error('not_found',404);}}
 private function ok(mixed$d):WP_REST_Response{return new WP_REST_Response(['success'=>true,'data'=>$d],200);}private function error(string$c,int$s,string$m='Recurso no disponible.'):WP_REST_Response{return new WP_REST_Response(['success'=>false,'error'=>['code'=>$c,'message'=>$m]],$s);}
}
