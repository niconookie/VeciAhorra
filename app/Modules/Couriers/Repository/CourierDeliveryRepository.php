<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class CourierDeliveryRepository extends Repository
{
    private function projection(): string
    {
        return "SELECT d.id,d.order_id,d.courier_id,d.status,d.created_at,d.updated_at,
            d.delivery_recipient_name,d.delivery_contact_phone,d.delivery_address_line1,d.delivery_commune,d.delivery_reference,d.delivery_notes,
            s.business_name minimarket,s.address pickup_address,s.commune pickup_commune,COALESCE(NULLIF(s.mobile,''),s.phone) pickup_phone
            FROM {$this->table('deliveries')} d
            INNER JOIN {$this->table('orders')} o ON o.id=d.order_id AND o.minimarket_id=d.minimarket_id
            INNER JOIN {$this->table('checkout_orders')} co ON co.order_id=o.id
            INNER JOIN {$this->table('checkouts')} c ON c.id=co.checkout_id
            INNER JOIN {$this->table('stores')} s ON s.id=d.minimarket_id";
    }
    private function complete(): string
    {
        return "o.status='paid' AND o.store_fulfillment_status='ready_for_pickup' AND c.fulfillment_method='delivery' AND s.business_name<>'' AND s.address IS NOT NULL AND s.address<>'' AND s.commune IS NOT NULL AND s.commune<>'' AND COALESCE(NULLIF(s.mobile,''),s.phone)<>'' AND d.delivery_recipient_name IS NOT NULL AND d.delivery_recipient_name<>'' AND d.delivery_contact_phone IS NOT NULL AND d.delivery_contact_phone<>'' AND d.delivery_address_line1 IS NOT NULL AND d.delivery_address_line1<>'' AND d.delivery_commune IS NOT NULL AND d.delivery_commune<>''";
    }
    public function available(): array { return $this->db()->get_results($this->projection()." WHERE d.status='pending' AND d.courier_id IS NULL AND ".$this->complete().' ORDER BY d.id ASC', ARRAY_A); }
    public function findAvailable(int $id): ?array { $r=$this->db()->get_row($this->db()->prepare($this->projection()." WHERE d.id=%d AND d.status='pending' AND d.courier_id IS NULL AND ".$this->complete().' LIMIT 1',$id),ARRAY_A);return $r===null?null:$r; }
    public function owned(int $courierId): array { return $this->db()->get_results($this->db()->prepare($this->projection().' WHERE d.courier_id=%d ORDER BY d.id DESC', $courierId), ARRAY_A); }
    public function findOwned(int $id, int $courierId): ?array { $r=$this->db()->get_row($this->db()->prepare($this->projection().' WHERE d.id=%d AND d.courier_id=%d LIMIT 1',$id,$courierId),ARRAY_A); return $r===null?null:$r; }
    public function find(int $id): ?array { $r=$this->db()->get_row($this->db()->prepare($this->projection().' WHERE d.id=%d LIMIT 1',$id),ARRAY_A); return $r===null?null:$r; }
    public function accept(int $id,int $courierId,string $now): int
    {
        $sql="UPDATE {$this->table('deliveries')} d INNER JOIN {$this->table('orders')} o ON o.id=d.order_id AND o.minimarket_id=d.minimarket_id SET d.courier_id=%d,d.status='assigned',d.updated_at=%s WHERE d.id=%d AND d.courier_id IS NULL AND d.status='pending' AND o.status='paid' AND o.store_fulfillment_status='ready_for_pickup'";
        $r=$this->db()->query($this->db()->prepare($sql,$courierId,$now,$id));
        if($r===false)throw new PersistenceException('No fue posible aceptar la entrega.');
        return (int)$r;
    }
    public function transition(int $id,int $courierId,string $from,string $to,string $now): int { $r=$this->db()->query($this->db()->prepare("UPDATE {$this->table('deliveries')} SET status=%s,updated_at=%s WHERE id=%d AND courier_id=%d AND status=%s",$to,$now,$id,$courierId,$from)); if($r===false)throw new PersistenceException('No fue posible cambiar la entrega.'); return (int)$r; }
    public function track(int $id,string $event,string $now): void { if($this->db()->insert($this->table('delivery_tracking'),['delivery_id'=>$id,'event'=>$event,'created_at'=>$now])===false) throw new PersistenceException('No fue posible registrar tracking.'); }
    public function markOrderDelivered(int $orderId,string $now): void { if($this->db()->query($this->db()->prepare("UPDATE {$this->table('orders')} SET status='delivered',updated_at=%s WHERE id=%d AND status='paid'",$now,$orderId))===false) throw new PersistenceException('No fue posible completar Order.'); }
}
