<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Returns;

use DomainException;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Couriers\Identity\CourierContext;
use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;
use VeciAhorra\Modules\Minimarket\Identity\StoreContext;

final class ReturnService
{
    public const REASONS=['recipient_absent','recipient_rejected','otp_unavailable','photo_unavailable'];
    public const CONDITIONS=['intact','damaged','incomplete'];
    public function __construct(private CourierDeliveryRepository $deliveries=new CourierDeliveryRepository()){}
    public function open(int $id,?int $version,string $reason,string $note):array
    {
        $courier=(new CourierContext())->resolve()??throw new DomainException('courier_forbidden');
        return $this->operate($id,$version,$reason,$note,'open',(int)$courier['id']);
    }
    public function receive(int $id,?int $version,string $condition,string $note):array
    {
        $store=(new StoreContext())->current();
        if(is_wp_error($store))throw new DomainException('store_forbidden');
        return $this->operate($id,$version,$condition,$note,'receive',(int)$store['id']);
    }
    private function operate(int $id,?int $version,string $code,string $note,string $action,int $authority):array
    {
        global $wpdb;$p=$wpdb->prefix.Config::TABLE_PREFIX;
        if($version===null||$version<0)throw new DomainException('expected_version_required');
        if(!in_array($code,$action==='open'?self::REASONS:self::CONDITIONS,true))throw new DomainException('invalid_return_code');
        $note=trim($note);
        if(preg_match('/^.{1,500}$/usD',$note)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$note))throw new DomainException('return_note_required_max_500');
        if((int)$wpdb->get_var('SELECT @@in_transaction')!==0)throw new DomainException('return_requires_own_transaction');
        $snapshot=$this->deliveries->find($id)??throw new DomainException('return_unavailable');
        $user=get_current_user_id();
        return (new CheckoutRepository())->transaction(function()use($wpdb,$p,$id,$version,$code,$note,$action,$authority,$snapshot,$user):array{
            $this->deliveries->lockCouriers([(int)$snapshot['courier_id']]);
            if($action==='open'&&(new CourierContext())->resolve()===null)throw new DomainException('courier_forbidden');
            $row=$this->deliveries->lock($id);
            if(!$row['courier_id']||$row['courier_id']!==$snapshot['courier_id'])throw new DomainException('return_assignment_conflict');
            if($action==='open'&&(int)$row['courier_id']!==$authority)throw new DomainException('return_courier_forbidden');
            if($action==='receive'&&(int)$row['minimarket_id']!==$authority)throw new DomainException('return_store_forbidden');
            // Lock and revalidate the original store authority against concurrent ownership changes.
            $store=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}stores WHERE id=%d FOR UPDATE",$row['minimarket_id']),ARRAY_A);
            if($action==='receive'){
                $current=(new StoreContext())->current();
                if(is_wp_error($current)||(int)$current['id']!==$authority)throw new DomainException('return_store_forbidden');
            }
            $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}orders WHERE id=%d FOR UPDATE",$row['order_id']),ARRAY_A);
            $checkouts=$wpdb->get_results($wpdb->prepare("SELECT c.* FROM {$p}checkouts c JOIN {$p}checkout_orders co ON co.checkout_id=c.id WHERE co.order_id=%d",$row['order_id']),ARRAY_A);
            if(!$order||!$store||(int)$order['minimarket_id']!==(int)$row['minimarket_id']||(int)$order['customer_id']!==(int)$row['customer_id']
                ||count($checkouts)!==1||$checkouts[0]['fulfillment_method']!=='delivery'||(int)$checkouts[0]['user_id']!==(int)$row['customer_id'])throw new DomainException('return_identity_conflict');
            $record=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}delivery_returns WHERE delivery_id=%d FOR UPDATE",$id),ARRAY_A);
            $target=$action==='open'?'return_pending':'returned_to_store';$from=$action==='open'?'picked_up':'return_pending';
            $orderTarget=$action==='open'?'return_pending':'incident_review';$orderFrom=$action==='open'?'paid':'return_pending';
            $prefix=$action==='open'?'opened':'received';
            if($row['status']===$target&&$record&&(int)$row['transition_version']===$version+1&&$order['status']===$orderTarget
                &&(int)$record[$prefix.'_version']===$version&&(int)$record[$prefix.'_by']===$user&&$record[$prefix.'_code']===$code&&$record[$prefix.'_note']===$note){
                $event=$this->deliveries->event($id,$version+1);
                if(!$event||$event['new_status']!==$target||$event['reason_code']!==$code||$event['reason']!==$note||(int)$event['actor_id']!==$user)throw new DomainException('return_replay_conflict');
                return ['id'=>$id,'status'=>$target,'transition_version'=>$version+1];
            }
            if($row['status']!==$from||(int)$row['transition_version']!==$version||$order['status']!==$orderFrom)throw new DomainException('return_transition_conflict');
            $now=current_time('mysql',true);
            if($action==='open'){
                if($record)throw new DomainException('return_already_exists');
                // Delete verification authority irreversibly; never count an incident as an OTP attempt.
                $otp=$wpdb->get_row($wpdb->prepare("SELECT delivery_id,invalidated_at FROM {$p}delivery_otps WHERE delivery_id=%d FOR UPDATE",$id),ARRAY_A);
                if($wpdb->last_error!=='')throw new \RuntimeException('return_otp_read_failed');
                if($otp&&$otp['invalidated_at']===null){
                $this->write($wpdb->query($wpdb->prepare("UPDATE {$p}delivery_otps SET code_hash='',generation_context='',consumed_at=COALESCE(consumed_at,%s),invalidated_at=%s WHERE delivery_id=%d AND invalidated_at IS NULL",$now,$now,$id)),1);
                }
                $this->write($wpdb->insert($p.'delivery_returns',['delivery_id'=>$id,'order_id'=>$row['order_id'],'store_id'=>$row['minimarket_id'],'courier_id'=>$row['courier_id'],'opened_version'=>$version,'opened_by'=>$user,'opened_code'=>$code,'opened_note'=>$note,'opened_at'=>$now]),1);
            }else{
                if(!$record||(int)$record['courier_id']!==(int)$row['courier_id']||(int)$record['store_id']!==$authority)throw new DomainException('return_record_conflict');
                $this->write($wpdb->update($p.'delivery_returns',['received_version'=>$version,'received_by'=>$user,'received_code'=>$code,'received_note'=>$note,'received_at'=>$now],['delivery_id'=>$id,'received_at'=>null]),1);
            }
            // Custody remains attached to the original Courier; only the completed receipt ends it.
            $this->write($wpdb->query($wpdb->prepare("UPDATE {$p}deliveries SET status=%s,transition_version=transition_version+1,updated_at=%s WHERE id=%d AND status=%s AND transition_version=%d AND courier_id=%d",$target,$now,$id,$from,$version,$row['courier_id'])),1);
            $this->write($wpdb->query($wpdb->prepare("UPDATE {$p}orders SET status=%s,updated_at=%s WHERE id=%d AND status=%s",$orderTarget,$now,$row['order_id'],$orderFrom)),1);
            $this->deliveries->audit($row,$target,(int)$row['courier_id'],$action==='open'?'courier':'store',$user,$code,$note,$now);
            return ['id'=>$id,'status'=>$target,'transition_version'=>$version+1];
        });
    }
    private function write(int|bool $result,int $expected):void{if($result!==$expected)throw new \RuntimeException('return_write_conflict');}
}
