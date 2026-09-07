<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class CourierDeliveryRepository extends Repository
{
    private function joins(): string
    {
        return " FROM {$this->table('deliveries')} d"
            . " JOIN {$this->table('orders')} o ON o.id=d.order_id AND o.minimarket_id=d.minimarket_id"
            . " JOIN {$this->table('checkout_orders')} co ON co.order_id=o.id"
            . " JOIN {$this->table('checkouts')} c ON c.id=co.checkout_id"
            . " JOIN {$this->table('stores')} s ON s.id=d.minimarket_id";
    }
    private function projection(): string
    {
        return "SELECT d.id,d.order_id,d.courier_id,d.service_zone_id,d.status,d.created_at,d.updated_at,
            d.delivery_recipient_name,d.delivery_contact_phone,d.delivery_address_line1,d.delivery_commune,d.delivery_reference,d.delivery_notes,
            s.business_name minimarket,s.address pickup_address,s.commune pickup_commune,COALESCE(NULLIF(s.mobile,''),s.phone) pickup_phone"
            . $this->joins();
    }
    private function complete(): string
    {
        return "o.status='paid' AND o.store_fulfillment_status='ready_for_pickup' AND c.fulfillment_method='delivery' AND s.business_name<>'' AND s.address IS NOT NULL AND s.address<>'' AND s.commune IS NOT NULL AND s.commune<>'' AND COALESCE(NULLIF(s.mobile,''),s.phone)<>'' AND d.delivery_recipient_name IS NOT NULL AND d.delivery_recipient_name<>'' AND d.delivery_contact_phone IS NOT NULL AND d.delivery_contact_phone<>'' AND d.delivery_address_line1 IS NOT NULL AND d.delivery_address_line1<>'' AND d.delivery_commune IS NOT NULL AND d.delivery_commune<>''";
    }
    private function territory(int $courierId): string
    {
        return $this->db()->prepare(
            "d.service_zone_id IS NOT NULL AND d.service_zone_id=o.service_zone_id AND o.service_zone_id=c.service_zone_id"
            . " AND EXISTS (SELECT 1 FROM {$this->table('couriers')} cr"
            . " JOIN {$this->table('service_zones')} z ON z.id=cr.service_zone_id AND z.status='active'"
            . " WHERE cr.id=%d AND cr.status='approved' AND cr.service_zone_id=d.service_zone_id)"
            . " AND s.status='active' AND EXISTS (SELECT 1 FROM {$this->table('store_service_zones')} sz"
            . ' WHERE sz.store_id=d.minimarket_id AND sz.zone_id=d.service_zone_id)', $courierId
        );
    }
    public function available(int $courierId): array
    {
        return $this->db()->get_results($this->projection() . " WHERE d.status='pending' AND d.courier_id IS NULL AND "
            . $this->complete() . ' AND ' . $this->territory($courierId) . ' ORDER BY d.id ASC', ARRAY_A);
    }
    public function findAvailable(int $id, int $courierId): ?array
    {
        return $this->db()->get_row($this->db()->prepare($this->projection() . " WHERE d.id=%d AND d.status='pending' AND d.courier_id IS NULL AND "
            . $this->complete() . ' AND ' . $this->territory($courierId), $id), ARRAY_A);
    }
    public function owned(int $courierId): array
    {
        return $this->db()->get_results($this->db()->prepare($this->projection() . ' WHERE d.courier_id=%d AND '
            . $this->territory($courierId) . ' ORDER BY d.id DESC', $courierId), ARRAY_A);
    }
    public function findOwned(int $id, int $courierId): ?array
    {
        return $this->db()->get_row($this->db()->prepare($this->projection() . ' WHERE d.id=%d AND d.courier_id=%d AND '
            . $this->territory($courierId), $id, $courierId), ARRAY_A);
    }
    public function find(int $id): ?array
    {
        return $this->db()->get_row($this->db()->prepare($this->projection() . ' WHERE d.id=%d', $id), ARRAY_A);
    }
    public function assertCourierTerritory(int $id, int $courierId): void
    {
        if ($this->db()->get_row($this->db()->prepare($this->projection() . ' WHERE d.id=%d AND '
            . $this->territory($courierId), $id), ARRAY_A) === null) throw new \DomainException('delivery_zone_not_available');
    }
    public function accept(int $id, int $courierId, string $now): int
    {
        // Eligibility and territory must remain inside this atomic write.
        $sql = 'UPDATE ' . substr($this->joins(), 6)
            . " SET d.courier_id=%d,d.status='assigned',d.updated_at=%s"
            . " WHERE d.id=%d AND d.courier_id IS NULL AND d.status='pending' AND "
            . $this->complete() . ' AND ' . $this->territory($courierId);
        $result = $this->db()->query($this->db()->prepare($sql, $courierId, $now, $id));
        if ($result === false) throw new PersistenceException('No fue posible aceptar la entrega.');
        return (int)$result;
    }
    public function transition(int $id, int $courierId, string $from, string $to, string $now): int
    {
        $sql = 'UPDATE ' . substr($this->joins(), 6)
            . ' SET d.status=%s,d.updated_at=%s WHERE d.id=%d AND d.courier_id=%d AND d.status=%s AND '
            . $this->territory($courierId);
        $result = $this->db()->query($this->db()->prepare($sql, $to, $now, $id, $courierId, $from));
        if ($result === false) throw new PersistenceException('No fue posible cambiar la entrega.');
        return (int)$result;
    }
    public function track(int $id, string $event, string $now): void
    {
        if ($this->db()->insert($this->table('delivery_tracking'), ['delivery_id'=>$id,'event'=>$event,'created_at'=>$now]) === false)
            throw new PersistenceException('No fue posible registrar tracking.');
    }
    public function markOrderDelivered(int $orderId, string $now): void
    {
        if ($this->db()->query($this->db()->prepare("UPDATE {$this->table('orders')} SET status='delivered',updated_at=%s WHERE id=%d AND status='paid'", $now, $orderId)) === false)
            throw new PersistenceException('No fue posible completar Order.');
    }
}
