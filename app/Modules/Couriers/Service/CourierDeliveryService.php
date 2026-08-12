<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Service;

use DomainException;
use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;

final class CourierDeliveryService
{
    public function __construct(private CourierDeliveryRepository $repository = new CourierDeliveryRepository()) {}
    public function available(): array { return array_map([$this,'publicData'],$this->repository->available()); }
    public function owned(int $courierId): array { return array_map([$this,'publicData'],$this->repository->owned($courierId)); }
    public function detail(int $id,int $courierId): ?array { $row=$this->repository->findOwned($id,$courierId); return $row===null?null:$this->publicData($row); }
    public function accept(int $id,int $courierId): array
    {
        $candidate=$this->repository->find($id);
        if($candidate===null) throw new \OutOfBoundsException('delivery_not_found');
        if($this->repository->findAvailable($id)===null){
            if((int)($candidate['courier_id']??0)===$courierId&&($candidate['status']??null)==='assigned')return $this->publicData($candidate);
            throw new DomainException(($candidate['courier_id']??null)!==null?'delivery_assignment_conflict':'delivery_not_available');
        }
        if($this->repository->accept($id,$courierId,$now=current_time('mysql',true))===1){$this->repository->track($id,'assigned',$now);return $this->detail($id,$courierId);}
        $current=$this->repository->find($id);
        if((int)($current['courier_id']??0)===$courierId && ($current['status']??null)==='assigned') return $this->publicData($current);
        throw new DomainException(($current['courier_id']??null)!==null?'delivery_assignment_conflict':'delivery_not_available');
    }
    public function transition(int $id,int $courierId,string $target): array
    {
        $from=['picked_up'=>'assigned','delivered'=>'picked_up'][$target]??throw new DomainException('invalid_transition');
        $current=$this->repository->findOwned($id,$courierId);
        if($current===null) throw new \OutOfBoundsException('delivery_not_found');
        if(($current['status']??null)===$target)return $this->publicData($current);
        if(($current['status']??null)!==$from)throw new DomainException('invalid_transition');
        global $wpdb; $wpdb->query('START TRANSACTION');
        try{
            $now=current_time('mysql',true);
            if($this->repository->transition($id,$courierId,$from,$target,$now)!==1)throw new DomainException('transition_conflict');
            if($target==='delivered')$this->repository->markOrderDelivered((int)$current['order_id'],$now);
            $this->repository->track($id,$target,$now);
            $wpdb->query('COMMIT');
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
        return $this->detail($id,$courierId)??throw new \RuntimeException('Delivery no recuperable.');
    }
    public function publicData(array $r): array
    {
        return ['id'=>(int)$r['id'],'order_id'=>(int)$r['order_id'],'status'=>(string)$r['status'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at'],
            'minimarket'=>['name'=>$r['minimarket'],'address'=>$r['pickup_address'],'commune'=>$r['pickup_commune'],'phone'=>$r['pickup_phone']],
            'delivery'=>['recipient_name'=>$r['delivery_recipient_name'],'contact_phone'=>$r['delivery_contact_phone'],'address_line1'=>$r['delivery_address_line1'],'commune'=>$r['delivery_commune'],'reference'=>$r['delivery_reference'],'notes'=>$r['delivery_notes']]];
    }
}
