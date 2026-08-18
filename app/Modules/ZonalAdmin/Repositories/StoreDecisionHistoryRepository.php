<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ZonalAdmin\Repositories;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

final class StoreDecisionHistoryRepository
{
    public function append(array $decision): int
    {
        $required = ['store_id','actor_user_id','actor_role','action','from_state','to_state','reason','authority_service_zone_id','created_at'];
        if (array_keys($decision) !== $required) {
            throw new \InvalidArgumentException('La decision no cumple el contrato cerrado.');
        }
        if (! in_array($decision['action'], ['approve','observe','reject'], true)) {
            throw new \InvalidArgumentException('La accion de decision no es valida.');
        }
        if (in_array($decision['action'], ['observe','reject'], true) && (! is_string($decision['reason']) || trim($decision['reason']) === '')) {
            throw new \InvalidArgumentException('El motivo es obligatorio.');
        }
        if ($decision['action'] === 'approve' && ! ($decision['reason'] === null || (is_string($decision['reason']) && trim($decision['reason']) !== ''))) {
            throw new \InvalidArgumentException('El motivo opcional no puede ser una cadena vacia.');
        }
        global $wpdb;
        $result = $wpdb->insert($this->table(), $decision);
        if ($result !== 1) {
            throw new PersistenceException('No fue posible registrar la decision Store.');
        }
        return (int) $wpdb->insert_id;
    }

    public function forStore(int $storeId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table()
            . ' WHERE store_id = %d ORDER BY created_at DESC, id DESC',
            $storeId
        ), ARRAY_A);
    }

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . Config::TABLE_PREFIX . 'store_decision_history';
    }
}
