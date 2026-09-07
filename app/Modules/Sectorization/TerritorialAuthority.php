<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Sectorization;

use VeciAhorra\Database\Repository;

/** Current eligibility checks never rewrite historical snapshots. */
final class TerritorialAuthority extends Repository
{
    public function activeZone(int $id, bool $lock = false): array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            "SELECT * FROM {$this->table('service_zones')} WHERE id=%d AND status='active'" . ($lock ? ' FOR UPDATE' : ''), $id
        ), ARRAY_A);
        if (!is_array($row)) throw new \DomainException('active_service_zone_required');
        return $row;
    }

    public function storeInZone(int $zoneId, int $storeId, bool $lock = false): void
    {
        $this->activeZone($zoneId, $lock);
        $row = $this->db()->get_row($this->db()->prepare(
            "SELECT sz.id FROM {$this->table('store_service_zones')} sz"
            . " JOIN {$this->table('stores')} s ON s.id=sz.store_id AND s.status='active'"
            . ' WHERE sz.zone_id=%d AND sz.store_id=%d' . ($lock ? ' FOR UPDATE' : ''), $zoneId, $storeId
        ), ARRAY_A);
        if (!is_array($row)) throw new \DomainException('store_service_zone_mismatch');
    }
}
