<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Evidence;

use DomainException;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Couriers\Identity\CourierContext;
use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;

final class DeliveryProofService
{
    public function __construct(private DeliveryProofRepository $proof=new DeliveryProofRepository(),private CourierDeliveryRepository $deliveries=new CourierDeliveryRepository(),private PrivateDeliveryStorage $storage=new PrivateDeliveryStorage()){}
    public function confirm(int $id,int $version,string $code,array $upload,bool $visible,bool $consent): array
    {
        global $wpdb;
        if ((int)$wpdb->get_var('SELECT @@in_transaction') !== 0) throw new DomainException('delivery_requires_own_transaction');
        $courier=(new CourierContext())->resolve()??throw new DomainException('courier_forbidden');
        $courierId=(int)$courier['id'];$userId=get_current_user_id();
        if($version<0)throw new DomainException('expected_version_required');
        $snapshot=$this->deliveries->findOwned($id,$courierId)??throw new DomainException('delivery_unavailable');
        if($snapshot['status']==='delivered'){
            return (new CheckoutRepository())->transaction(function()use($id,$version,$courierId,$userId):array{
                $row=$this->locked($id,$courierId);
                return $this->replay($row,$version,$courierId,$userId);
            });
        }
        if($visible&&!$consent)throw new DomainException('recipient_consent_required');
        $file=$this->storage->prepare($upload);$moved=false;
        try{
            $result=(new CheckoutRepository())->transaction(function()use($id,$version,$code,$visible,$consent,$file,$courierId,$userId,&$moved):array{
                $row=$this->locked($id,$courierId);
                if($row['status']==='delivered')return ['replay'=>true,...$this->replay($row,$version,$courierId,$userId)];
                if($row['status']!=='picked_up'||(int)$row['transition_version']!==$version)throw new DomainException('delivery_transition_conflict');
                $otp=$this->proof->otp($id,true);$now=current_time('mysql',true);
                if(!$otp||$otp['consumed_at']!==null||$otp['expires_at']<=$now||(int)$otp['attempts']>=5||(int)$otp['picked_up_version']!==$version)throw new DomainException('otp_unavailable');
                if(!(new DeliveryOtp())->matches($otp,$code)){
                    $this->proof->failAttempt($id,(int)$otp['attempts']);
                    // Commit the failed-attempt counter, without any delivery/evidence writes.
                    return ['error'=>'otp_invalid'];
                }
                $this->proof->pending(['delivery_id'=>$id,'courier_id'=>$courierId,'actor_user_id'=>$userId,'expected_version'=>$version,
                    'storage_key'=>$file['final'],'sha256'=>$file['sha256'],'status'=>'pending','recipient_visible'=>$visible?1:0,
                    'consented_at'=>$visible&&$consent?$now:null,'created_at'=>$now]);
                $this->storage->move($file);$moved=true;
                $this->deliveries->change($row,'delivered',$courierId,$now);
                $this->deliveries->markOrderDelivered((int)$row['order_id'],$now);
                $this->proof->complete($id,$now);
                $this->deliveries->audit($row,'delivered',$courierId,'courier',$courierId,'delivered',null,$now);
                return ['id'=>$id,'status'=>'delivered','transition_version'=>$version+1];
            });
        }catch(\Throwable $e){$this->storage->compensate($file,$moved);throw $e;}
        if(isset($result['error'])||isset($result['replay']))$this->storage->compensate($file);
        if(isset($result['error']))throw new DomainException($result['error']);
        unset($result['replay']);
        return $result;
    }
    private function locked(int $id,int $courierId): array
    {
        $this->deliveries->lockCouriers([$courierId]);$row=$this->deliveries->lock($id);
        if((int)$row['courier_id']!==$courierId)throw new DomainException('delivery_unavailable');
        $this->deliveries->assertCourierTerritory($id,$courierId);
        return $row;
    }
    private function replay(array $row,int $version,int $courierId,int $userId): array
    {
        $id=(int)$row['id'];$e=$this->proof->evidence($id);$otp=$this->proof->otp($id,true);$event=$this->deliveries->event($id,(int)$row['transition_version']);
        if($row['status']!=='delivered'||!$e||$e['status']!=='completed'||(int)$e['courier_id']!==$courierId||(int)$e['actor_user_id']!==$userId
            ||(int)$e['expected_version']!==$version||(int)$row['transition_version']!==$version+1||!$otp||$otp['consumed_at']===null
            ||!$event||$event['new_status']!=='delivered'||$event['actor_type']!=='courier'||(int)$event['actor_id']!==$courierId
            ||!$this->deliveries->orderIsDelivered((int)$row['order_id'])||!is_file($this->storage->path($e['storage_key'])))throw new DomainException('delivery_replay_conflict');
        return ['id'=>$id,'status'=>'delivered','transition_version'=>(int)$row['transition_version']];
    }
    private function customerSession(): bool
    {
        return is_user_logged_in()&&!current_user_can('veciahorra_manage_deliveries')&&!current_user_can('veciahorra_manage_store')&&!current_user_can('manage_options');
    }
    public function customer(int $id): ?array
    {
        if(!$this->customerSession())return null;
        $row=$this->proof->owned($id,get_current_user_id());
        if(!$row)return null;
        $result=['delivery_id'=>$id,'otp'=>null,'otp_expires_at'=>null,'evidence_url'=>null];$otp=$this->proof->otp($id);
        if($row['status']==='picked_up'&&$otp&&(int)$otp['picked_up_version']===(int)$row['transition_version']&&$otp['consumed_at']===null&&$otp['expires_at']>current_time('mysql',true)&&(int)$otp['attempts']<5){
            $code=(new DeliveryOtp())->code($id,$otp['generation_context']);
            if((new DeliveryOtp())->matches($otp,$code)){$result['otp']=$code;$result['otp_expires_at']=str_replace(' ','T',$otp['expires_at']).'Z';}
        }
        $e=$this->proof->evidence($id);
        if($row['status']==='delivered'&&$e&&$e['status']==='completed')$result['evidence_url']=self::url($id);
        return $result;
    }
    public static function url(int $id): string
    {
        return add_query_arg(['action'=>'veciahorra_delivery_evidence','delivery_id'=>$id],admin_url('admin-post.php'));
    }
    /** Same not-found response for anonymous, other identities and absent evidence. */
    public function download(int $id): array
    {
        if(!is_user_logged_in()||(!current_user_can('manage_options')&&(!$this->customerSession()||!$this->proof->owned($id,get_current_user_id()))))throw new DomainException('evidence_unavailable');
        $e=$this->proof->evidence($id);
        if(!$e||$e['status']!=='completed')throw new DomainException('evidence_unavailable');
        $path=$this->storage->path($e['storage_key']);
        if(!is_file($path)||!hash_equals($e['sha256'],(string)hash_file('sha256',$path)))throw new DomainException('evidence_unavailable');
        return ['path'=>$path,'headers'=>['Content-Type'=>'image/jpeg','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'inline; filename="delivery-proof.jpg"']];
    }
}
