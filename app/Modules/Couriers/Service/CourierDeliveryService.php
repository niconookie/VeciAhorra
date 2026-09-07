<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Service;

use DomainException;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;
use VeciAhorra\Modules\Couriers\Repository\CourierRepository;

final class CourierDeliveryService
{
    public function __construct(private CourierDeliveryRepository $repository = new CourierDeliveryRepository()) {}
    public function available(int $courierId): array { return array_map([$this,'publicData'],$this->repository->available($courierId)); }
    public function owned(int $courierId): array { return array_map([$this,'publicData'],$this->repository->owned($courierId)); }
    public function detail(int $id,int $courierId): ?array { $row=$this->repository->findOwned($id,$courierId); return $row===null?null:$this->publicData($row); }
    public function accept(int $id, int $courierId, ?int $version = null): array
    {
        return $this->operate($id,$courierId,'assigned','assigned','courier',$courierId,null,$version,'pending');
    }
    public function transition(int $id, int $courierId, string $target, ?int $version = null): array
    {
        $from = ['picked_up'=>'assigned','delivered'=>'picked_up'][$target] ?? throw new DomainException('invalid_transition');
        return $this->operate($id,$courierId,$target,$target,'courier',$courierId,null,$version,$from);
    }
    public function abandon(int $id, int $courierId, string $reason, int $version): array
    {
        return $this->operate($id,null,'pending','courier_abandoned','courier',$courierId,$this->reason($reason),$version,'assigned');
    }
    /** Administrative actors always come from the authenticated session. */
    public function adminAssign(int $id, int $courierId, ?int $version = null): array
    {
        return $this->operate($id,$courierId,'assigned','assigned','admin',$this->adminActor(),null,$version,'pending');
    }
    public function adminChange(int $id, ?int $courierId, string $reason, int $version): array
    {
        return $this->operate($id,$courierId,$courierId===null?'pending':'assigned',$courierId===null?'admin_unassigned':'admin_reassigned','admin',$this->adminActor(),$this->reason($reason),$version,'assigned');
    }
    public function adminTransition(int $id, string $target, int $version): array
    {
        $actor = $this->adminActor();
        $from = ['picked_up'=>'assigned','delivered'=>'picked_up'][$target] ?? throw new DomainException('invalid_transition');
        $row = $this->repository->find($id) ?? throw new \OutOfBoundsException('delivery_not_found');
        return $this->operate($id,(int)$row['courier_id'],$target,$target,'admin',$actor,null,$version,$from);
    }
    private function adminActor(): int
    {
        if (!current_user_can('manage_options') || get_current_user_id() <= 0) throw new DomainException('admin_forbidden');
        return get_current_user_id();
    }
    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || preg_match('/^.{1,500}$/usD',$reason) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason)) throw new DomainException('reason_required_max_500');
        return $reason;
    }
    private function operate(int $id, ?int $nextCourier, string $target, string $code, string $actorType, int $actorId, ?string $reason, ?int $version, string $from): array
    {
        $snapshot = $this->repository->find($id) ?? throw new \OutOfBoundsException('delivery_not_found');
        if ($actorId <= 0 || ($nextCourier !== null && $nextCourier <= 0) || ($version !== null && $version < 0)) throw new DomainException('invalid_delivery_command');
        return (new CheckoutRepository())->transaction(function () use ($id,$nextCourier,$target,$code,$actorType,$actorId,$reason,$version,$from,$snapshot): array {
            $this->repository->lockCouriers([$snapshot['courier_id'],$nextCourier,$actorType==='courier'?$actorId:0]);
            $current = $this->repository->lock($id);
            if ($current['courier_id'] !== $snapshot['courier_id']) throw new DomainException('delivery_assignment_conflict');
            $authority = $nextCourier ?? ($actorType==='courier'?$actorId:(int)$current['courier_id']);
            $event = $this->repository->event($id,(int)$current['transition_version']);
            if ($authority <= 0 && $event !== null) $authority = (int)$event['previous_courier_id'];
            $this->repository->assertCourierTerritory($id,$authority);
            $matches = $event !== null && $event['actor_type']===$actorType && (int)$event['actor_id']===$actorId
                && $event['reason_code']===$code && $event['reason']===$reason && $event['previous_status']===$from
                && $event['new_status']===$target && $current['status']===$target
                && $event['new_courier_id']===$current['courier_id']
                && ($current['courier_id']===null ? $nextCourier===null : (int)$current['courier_id']===$nextCourier)
                && ($version===null || (int)$current['transition_version']===$version+1);
            if ($matches) {
                if (in_array($target,['assigned','picked_up'],true) && !$this->repository->eligible($id,$authority)) throw new DomainException('delivery_not_available');
                if ($target==='delivered' && !$this->repository->orderIsDelivered((int)$current['order_id'])) throw new DomainException('incoherent_delivery_replay');
                return $this->result($id);
            }
            $expected = $version ?? (int)$snapshot['transition_version'];
            if ((int)$current['transition_version']!==$expected || $current['status']!==$from) throw new DomainException('delivery_transition_conflict');
            if ($from==='pending' && $current['courier_id']!==null) throw new DomainException('delivery_assignment_conflict');
            if ($from!=='pending' && $current['courier_id']===null) throw new DomainException('delivery_assignment_conflict');
            if ($actorType==='courier' && $from!=='pending' && (int)$current['courier_id']!==$actorId) throw new DomainException('delivery_assignment_conflict');
            if ($code==='admin_reassigned' && (int)$current['courier_id']===$nextCourier) throw new DomainException('different_courier_required');
            $now = current_time('mysql',true);
            $this->repository->change($current,$target,$nextCourier,$now,in_array($target,['assigned','picked_up'],true));
            if ($target==='delivered') $this->repository->markOrderDelivered((int)$current['order_id'],$now);
            $this->repository->audit($current,$target,$nextCourier,$actorType,$actorId,$code,$reason,$now);
            return $this->result($id);
        });
    }
    private function result(int $id): array
    {
        return $this->publicData($this->repository->find($id) ?? throw new \RuntimeException('delivery_read_failed'));
    }
    public function suspend(int $courierId, string $now): void
    {
        $actor = $this->adminActor();
        (new CheckoutRepository())->transaction(function () use ($courierId,$now,$actor): void {
            $this->repository->lockCouriers([$courierId]);
            $couriers = new CourierRepository();
            $courier = $couriers->find($courierId) ?? throw new DomainException('courier_not_found');
            $deliveries = $this->repository->assignedTo($courierId);
            foreach ($deliveries as $delivery) if ($delivery['status']==='picked_up') throw new DomainException('courier_has_picked_up_deliveries');
            if (!in_array($courier['status'],['approved','pending','inactive'],true)) throw new DomainException('invalid_courier_transition');
            foreach ($deliveries as $delivery) {
                // Release all assigned work, even in an inactive territory. Normal listing/acceptance still applies.
                $this->repository->change($delivery,'pending',null,$now,false,true);
                $this->repository->audit($delivery,'pending',null,'admin',$actor,'courier_suspended',null,$now);
            }
            if ($courier['status']!=='inactive') $couriers->setInactive($courierId,(string)$courier['status'],$now);
        });
    }
    public function publicData(array $r): array
    {
        return ['transition_version'=>(int)$r['transition_version'],'service_zone_id'=>(int)$r['service_zone_id'],'id'=>(int)$r['id'],'order_id'=>(int)$r['order_id'],'status'=>(string)$r['status'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at'],
            'minimarket'=>['name'=>$r['minimarket'],'address'=>$r['pickup_address'],'commune'=>$r['pickup_commune'],'phone'=>$r['pickup_phone']],
            'delivery'=>['recipient_name'=>$r['delivery_recipient_name'],'contact_phone'=>$r['delivery_contact_phone'],'address_line1'=>$r['delivery_address_line1'],'commune'=>$r['delivery_commune'],'reference'=>$r['delivery_reference'],'notes'=>$r['delivery_notes']]];
    }
}
