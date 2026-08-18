<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Routes;

use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\ZonalAdmin\Authorization\StoreTerritoryAuthorizer;
use VeciAhorra\Modules\ZonalAdmin\Controllers\ZonalStoreController;
use VeciAhorra\Modules\ZonalAdmin\Requests\ZonalStoreTransitionRequest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ZonalStoreRoutes
{
    public function __construct(private ZonalStoreController $controller, private StoreTerritoryAuthorizer $territory) {}

    public function register(): void
    {
        register_rest_route('veciahorra/v1','/zonal/stores',['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'index'],'permission_callback'=>[$this,'permission']]);
        register_rest_route('veciahorra/v1','/zonal/stores/(?P<id>[1-9]\d*)',['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'show'],'permission_callback'=>[$this,'permission']]);
        register_rest_route('veciahorra/v1','/zonal/stores/(?P<id>[1-9]\d*)/transitions',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'transition'],'permission_callback'=>[$this,'decisionPermission']]);
    }

    public function permission(WP_REST_Request $request): bool|WP_Error
    {
        $userId = get_current_user_id();
        if ($userId <= 0) return new WP_Error('rest_not_logged_in','Autenticacion obligatoria.',['status'=>401]);
        if (! $this->territory->canList($userId)) return new WP_Error('rest_forbidden','No posee permisos.',['status'=>403]);
        $nonce=$request->get_header('X-WP-Nonce');
        if (!is_string($nonce)||$nonce===''||!wp_verify_nonce($nonce,'wp_rest')) return new WP_Error('rest_cookie_invalid_nonce','El nonce REST no es valido.',['status'=>403]);
        return true;
    }

    public function decisionPermission(WP_REST_Request $request): bool|WP_Error
    {
        $permission = $this->permission($request);
        if ($permission !== true) return $permission;
        if (! current_user_can('manage_options') && ! current_user_can(\VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole::CAPABILITY_DECIDE)) {
            return new WP_Error('rest_forbidden','No posee permisos de decision.',['status'=>403]);
        }
        return true;
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        try { return $this->response($this->controller->index(get_current_user_id(),$this->query($request)),200); }
        catch (\InvalidArgumentException $e) { return $this->error('validation_error',$e->getMessage(),422); }
        catch (\Throwable $e) { return $this->error('persistence_failure','No fue posible listar los minimarkets.',500); }
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        try { return $this->response($this->controller->show(get_current_user_id(),(int)$request['id']),200); }
        catch (StoreLifecycleException $e) { return $this->error('store_not_found','El minimarket no existe.',404); }
        catch (\Throwable $e) { return $this->error('persistence_failure','No fue posible cargar el minimarket.',500); }
    }

    public function transition(WP_REST_Request $request): WP_REST_Response
    {
        try {
            if (!$request->is_json_content_type()) throw new \InvalidArgumentException('El cuerpo debe usar application/json.');
            $body=$request->get_json_params();
            if (!is_array($body)) throw new \InvalidArgumentException('El cuerpo debe ser un objeto JSON.');
            $payload=(new ZonalStoreTransitionRequest($body))->validated();
            return $this->response($this->controller->transition(get_current_user_id(),(int)$request['id'],$payload),200);
        } catch (\InvalidArgumentException $e) { return $this->error('validation_error',$e->getMessage(),422); }
        catch (\DomainException $e) { return $this->error('store_not_found','El minimarket no existe.',404); }
        catch (StoreLifecycleException $e) {
            $status=$e->reason()==='store_not_found'?404:409;
            return $this->error($e->reason(),$status===404?'El minimarket no existe.':$e->getMessage(),$status);
        } catch (\Throwable $e) { return $this->error('persistence_failure','No fue posible decidir el minimarket.',500); }
    }

    private function query(WP_REST_Request $request): array
    {
        $input=$request->get_query_params(); $allowed=['page','per_page','search','state'];
        if(array_diff(array_keys($input),$allowed)!==[]) throw new \InvalidArgumentException('La consulta contiene campos inesperados.');
        foreach(['page'=>1,'per_page'=>20] as $key=>$default){$value=$input[$key]??$default;if(is_string($value)&&ctype_digit($value))$value=(int)$value;if(!is_int($value)||$value<1||($key==='per_page'&&$value>100))throw new \InvalidArgumentException("{$key} no es valido.");$input[$key]=$value;}
        $search=$input['search']??null;if($search!==null){if(!is_string($search)||strlen(trim($search))>100)throw new \InvalidArgumentException('search no es valido.');$search=trim(sanitize_text_field(wp_unslash($search)));$search=$search===''?null:$search;}
        $state=$input['state']??null;if($state!==null&&(!is_string($state)||!in_array($state,['in_review','observed','rejected','approved_inactive'],true)))throw new \InvalidArgumentException('state no es valido.');
        return ['page'=>$input['page'],'per_page'=>$input['per_page'],'search'=>$search,'state'=>$state];
    }

    private function error(string $code,string $message,int $status): WP_REST_Response { return $this->response(['success'=>false,'error'=>['code'=>$code,'message'=>$message,'data'=>['status'=>$status]]],$status); }
    private function response(array $data,int $status): WP_REST_Response {$response=new WP_REST_Response($data,$status);$response->header('Cache-Control','private, no-store');return $response;}
}
