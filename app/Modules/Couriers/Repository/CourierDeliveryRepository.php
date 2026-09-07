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
        return "SELECT d.id,d.order_id,d.courier_id,d.service_zone_id,d.status,d.transition_version,d.created_at,d.updated_at,
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
            . " AND c.fulfillment_method='delivery' AND s.status='active' AND EXISTS (SELECT 1 FROM {$this->table('store_service_zones')} sz"
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
            . " SET d.courier_id=%d,d.status='assigned',d.updated_at=%s,d.transition_version=d.transition_version+1"
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
        if ($this->db()->query($this->db()->prepare("UPDATE {$this->table('orders')} SET status='delivered',updated_at=%s WHERE id=%d AND status='paid'", $now, $orderId)) !== 1)
            throw new PersistenceException('No fue posible completar Order.');
    }

    /** Every operation takes Courier locks first, then Delivery, including suspension. */
    public function lockCouriers(array $ids): void
    {
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if ($this->db()->get_row($this->db()->prepare("SELECT id FROM {$this->table('couriers')} WHERE id=%d FOR UPDATE", $id), ARRAY_A) === null) throw new \DomainException('courier_not_found');
        }
    }
    public function lock(int $id): array
    {
        return $this->db()->get_row($this->db()->prepare("SELECT * FROM {$this->table('deliveries')} WHERE id=%d FOR UPDATE", $id), ARRAY_A)
            ?? throw new \OutOfBoundsException('delivery_not_found');
    }
    public function assignedTo(int $courierId): array
    {
        $rows = $this->db()->get_results($this->db()->prepare("SELECT * FROM {$this->table('deliveries')} WHERE courier_id=%d AND status IN ('assigned','picked_up') ORDER BY id FOR UPDATE", $courierId), ARRAY_A);
        if ($this->db()->last_error !== '') throw new PersistenceException('delivery_read_failed');
        return $rows;
    }
    public function administrativeAssigned(): array
    {
        return $this->db()->get_results($this->projection()." WHERE d.status='assigned' ORDER BY d.id", ARRAY_A);
    }
    public function eligibleCouriers(int $id): array
    {
        $result = [];
        foreach ((new CourierRepository())->all() as $courier) {
            if ($this->eligible($id, (int)$courier['id'])) $result[] = $courier;
        }
        return $result;
    }
    public function eligible(int $id, int $courierId): bool
    {
        return $this->db()->get_var($this->db()->prepare('SELECT d.id'.$this->joins().' WHERE d.id=%d AND '.$this->complete().' AND '.$this->territory($courierId), $id)) !== null;
    }
    /** State, owner and revision predicates are part of the write, not a preceding check. */
    public function change(array $before, string $target, ?int $courierId, string $now, bool $eligibility = false, bool $suspension = false): void
    {
        $owner = $before['courier_id'] === null ? 'd.courier_id IS NULL' : $this->db()->prepare('d.courier_id=%d', $before['courier_id']);
        $next = $courierId === null ? 'NULL' : (string)$courierId;
        $guard = '';
        if (!$suspension) {
            $guard = ' AND '.$this->territory($courierId ?? (int)$before['courier_id']);
            if ($eligibility) $guard .= ' AND '.$this->complete();
        }
        $source = $suspension ? $this->table('deliveries').' d' : substr($this->joins(),6);
        $sql = 'UPDATE '.$source." SET d.status=%s,d.courier_id={$next},d.updated_at=%s,d.transition_version=d.transition_version+1"
            .' WHERE d.id=%d AND d.status=%s AND '.$owner.' AND d.transition_version=%d'.$guard;
        $result = $this->db()->query($this->db()->prepare($sql,$target,$now,$before['id'],$before['status'],$before['transition_version']));
        if ($result === false) throw new PersistenceException('delivery_write_failed');
        if ($result !== 1) throw new \DomainException('delivery_transition_conflict');
    }
    public function event(int $id, int $version): ?array
    {
        return $this->db()->get_row($this->db()->prepare("SELECT * FROM {$this->table('delivery_tracking')} WHERE delivery_id=%d AND transition_version=%d",$id,$version), ARRAY_A);
    }
    public function audit(array $before, string $target, ?int $courierId, string $actorType, int $actorId, string $code, ?string $reason, string $now): void
    {
        if ($this->db()->insert($this->table('delivery_tracking'), [
            'delivery_id'=>$before['id'], 'transition_version'=>(int)$before['transition_version']+1,
            'event'=>$code, 'previous_status'=>$before['status'], 'new_status'=>$target,
            'previous_courier_id'=>$before['courier_id'], 'new_courier_id'=>$courierId,
            'actor_type'=>$actorType, 'actor_id'=>$actorId, 'reason_code'=>$code, 'reason'=>$reason, 'created_at'=>$now,
        ]) !== 1) throw new PersistenceException('delivery_tracking_failed');
    }
    public function orderIsDelivered(int $id): bool
    {
        return $this->db()->get_var($this->db()->prepare("SELECT status FROM {$this->table('orders')} WHERE id=%d",$id)) === 'delivered';
    }
}
