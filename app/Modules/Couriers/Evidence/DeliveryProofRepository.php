<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Evidence;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class DeliveryProofRepository extends Repository
{
    public function issue(int $id,int $version,string $now): void
    {
        $context=bin2hex(random_bytes(32));$otp=new DeliveryOtp();
        $data=['delivery_id'=>$id,'generation_context'=>$context,'code_hash'=>$otp->digest($id,$otp->code($id,$context)),
            'picked_up_version'=>$version,'created_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',strtotime($now.' UTC')+7200),'attempts'=>0];
        if($this->db()->insert($this->table('delivery_otps'),$data)!==1)throw new PersistenceException('otp_issue_failed');
    }
    public function otp(int $id,bool $lock=false): ?array
    {
        return $this->db()->get_row($this->db()->prepare("SELECT * FROM {$this->table('delivery_otps')} WHERE delivery_id=%d".($lock?' FOR UPDATE':''),$id),ARRAY_A);
    }
    public function evidence(int $id): ?array
    {
        return $this->db()->get_row($this->db()->prepare("SELECT * FROM {$this->table('delivery_evidence')} WHERE delivery_id=%d",$id),ARRAY_A);
    }
    public function failAttempt(int $id,int $attempts): void
    {
        if($this->db()->query($this->db()->prepare("UPDATE {$this->table('delivery_otps')} SET attempts=attempts+1 WHERE delivery_id=%d AND attempts=%d AND attempts<5 AND consumed_at IS NULL",$id,$attempts))!==1)throw new PersistenceException('otp_attempt_failed');
    }
    public function pending(array $data): void
    {
        if($this->db()->insert($this->table('delivery_evidence'),$data)!==1)throw new PersistenceException('evidence_write_failed');
    }
    public function complete(int $id,string $now): void
    {
        if($this->db()->query($this->db()->prepare("UPDATE {$this->table('delivery_otps')} SET consumed_at=%s WHERE delivery_id=%d AND consumed_at IS NULL AND expires_at>%s AND attempts<5",$now,$id,$now))!==1)throw new PersistenceException('otp_consume_failed');
        if($this->db()->query($this->db()->prepare("UPDATE {$this->table('delivery_evidence')} SET status='completed',completed_at=%s WHERE delivery_id=%d AND status='pending'",$now,$id))!==1)throw new PersistenceException('evidence_complete_failed');
    }
    public function owned(int $id,int $userId): ?array
    {
        if($userId<=0)return null;
        return $this->db()->get_row($this->db()->prepare(
            "SELECT d.* FROM {$this->table('deliveries')} d JOIN {$this->table('orders')} o ON o.id=d.order_id
             JOIN {$this->table('checkout_orders')} co ON co.order_id=o.id JOIN {$this->table('checkouts')} c ON c.id=co.checkout_id
             WHERE d.id=%d AND o.customer_id=%d AND d.customer_id=%d AND c.user_id=%d AND c.owner_type='user' AND c.fulfillment_method='delivery'",$id,$userId,$userId,$userId),ARRAY_A);
    }
    public function deliveryForOrder(int $orderId): ?int
    {
        $id=$this->db()->get_var($this->db()->prepare("SELECT id FROM {$this->table('deliveries')} WHERE order_id=%d",$orderId));
        return $id===null?null:(int)$id;
    }
    public function completed(): array
    {
        return $this->db()->get_results("SELECT delivery_id,status,completed_at FROM {$this->table('delivery_evidence')} WHERE status='completed' ORDER BY id DESC",ARRAY_A);
    }
}
