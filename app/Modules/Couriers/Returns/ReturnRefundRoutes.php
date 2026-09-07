<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Returns;
final class ReturnRefundRoutes
{
    public function __construct(private ReturnRefundService $service = new ReturnRefundService()) {}
    public function register(): void
    {
        register_rest_route('veciahorra/v1','/admin/deliveries/(?P<id>\d+)/cancel-and-refund',[
            'methods'=>'POST','permission_callback'=>static fn():bool=>current_user_can('manage_options'),
            'callback'=>[$this,'cancel'],
        ]);
    }
    public function cancel(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $body=$request->get_json_params();
            if (!is_array($body) || !is_int($body['expected_version']??null) || !is_int($body['retry_of']??0)
                || !is_string($body['idempotency_key']??null) || !is_string($body['note']??null)
                || ($body['confirmed']??null)!==true) throw new \DomainException('refund_request_invalid');
            $data=$this->service->cancelAndRefund((int)$request['id'],$body['expected_version'],$body['idempotency_key'],$body['note'],true,$body['retry_of']??0);
            return new \WP_REST_Response(['success'=>true,'data'=>$data],200);
        } catch (\DomainException|\InvalidArgumentException|\VeciAhorra\Exceptions\ConflictException) {
            return new \WP_REST_Response(['success'=>false,'message'=>'No se pudo autorizar esta devolución. Actualiza la incidencia y revisa sus datos.'],409);
        } catch (\Throwable) {
            return new \WP_REST_Response(['success'=>false,'message'=>'Resultado pendiente de revisión. Conserva esta solicitud y actualiza su estado; no inicies otra devolución.'],503);
        }
    }
}
