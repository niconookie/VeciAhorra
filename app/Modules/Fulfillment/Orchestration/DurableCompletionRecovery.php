<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Orchestration;

use VeciAhorra\Core\Config;

final class DurableCompletionRecovery
{
    public function __construct(private readonly DurableCompletionScheduler $scheduler = new DurableCompletionScheduler()) {}

    public function recover(): void
    {
        global $wpdb;
        $p = $wpdb->prefix . Config::TABLE_PREFIX;
        $eligible = "((%1\$s IN ('pending','retryable') AND %2\$s.attempt_count < 5) OR (%1\$s = 'processing' AND (%2\$s.lease_expires_at IS NULL OR %2\$s.lease_expires_at <= UTC_TIMESTAMP())))";
        foreach ($wpdb->get_col(sprintf("SELECT id FROM {$p}payment_reconciliations r WHERE " . $eligible . ' ORDER BY id LIMIT 100', 'r.reconciliation_status', 'r')) as $id) {
            $this->scheduler->reconciliation((int) $id);
        }
        foreach ($wpdb->get_col("SELECT r.id FROM {$p}payment_reconciliations r LEFT JOIN {$p}business_completions b ON b.reconciliation_id=r.id WHERE r.reconciliation_status='completed' AND (b.id IS NULL OR " . sprintf($eligible, 'b.status', 'b') . ') ORDER BY r.id LIMIT 100') as $id) {
            $this->scheduler->business((int) $id);
        }
        foreach ($wpdb->get_col("SELECT b.id FROM {$p}business_completions b LEFT JOIN {$p}delivery_completions d ON d.business_completion_id=b.id WHERE b.status='completed' AND (d.id IS NULL OR " . sprintf($eligible, 'd.completion_status', 'd') . ') ORDER BY b.id LIMIT 100') as $id) {
            $this->scheduler->delivery((int) $id);
        }
        foreach ($wpdb->get_col("SELECT b.id FROM {$p}business_completions b JOIN {$p}delivery_completions d ON d.business_completion_id=b.id LEFT JOIN {$p}fulfillment_completions f ON f.business_completion_id=b.id WHERE b.status='completed' AND d.completion_status IN ('completed','not_required') AND (f.id IS NULL OR " . sprintf($eligible, 'f.completion_status', 'f') . ') ORDER BY b.id LIMIT 100') as $id) {
            $this->scheduler->fulfillment((int) $id);
        }
    }
}
