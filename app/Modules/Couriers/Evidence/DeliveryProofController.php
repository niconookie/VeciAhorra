<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Evidence;

final class DeliveryProofController
{
    public function register(): void
    {
        add_action('admin_post_veciahorra_delivery_evidence',[$this,'download']);
        add_action('admin_post_nopriv_veciahorra_delivery_evidence',[$this,'download']);
    }
    public function download(): void
    {
        try{
            $id=filter_var($_GET['delivery_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false||$id===null)throw new \DomainException('evidence_unavailable');
            $file=(new DeliveryProofService())->download((int)$id);
        }catch(\Throwable){
            status_header(404);header('Cache-Control: private, no-store');echo 'Comprobante no disponible.';exit;
        }
        foreach($file['headers'] as $key=>$value)header($key.': '.$value);
        readfile($file['path']);exit;
    }
}
