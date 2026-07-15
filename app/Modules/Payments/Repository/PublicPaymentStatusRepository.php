<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use VeciAhorra\Database\Repository;

final class PublicPaymentStatusRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function findAttempts(int $checkoutId): array
    {
        if ($checkoutId <= 0) {
            throw new \InvalidArgumentException('checkout_id no es valido.');
        }
        $p = fn (string $table): string => $this->table($table);
        return $this->db()->get_results($this->db()->prepare(
            'SELECT ps.id, ps.public_id, ps.status AS session_status,'
            . ' ps.redirect_url, ps.expires_at AS session_expires_at,'
            . ' ps.updated_at AS session_updated_at, o.id AS origin_id,'
            . ' o.origin, o.environment, wr.id AS return_id,'
            . ' wr.processing_status AS return_processing_status,'
            . ' wr.result_status AS return_result_status,'
            . ' wr.updated_at AS return_updated_at,'
            . ' r.id AS reconciliation_id,'
            . ' r.reconciliation_status, r.reconciled_at,'
            . ' r.updated_at AS reconciliation_updated_at,'
            . ' b.id AS business_id, b.status AS business_status,'
            . ' b.completed_at AS business_completed_at,'
            . ' b.updated_at AS business_updated_at,'
            . ' d.id AS delivery_completion_id,'
            . ' d.completion_status AS delivery_status,'
            . ' d.completed_at AS delivery_completed_at,'
            . ' d.updated_at AS delivery_updated_at,'
            . ' f.id AS fulfillment_completion_id,'
            . ' f.completion_status AS fulfillment_status,'
            . ' f.completed_at AS fulfillment_completed_at,'
            . ' f.updated_at AS fulfillment_updated_at'
            . " FROM {$p('payment_sessions')} ps"
            . " LEFT JOIN {$p('payment_origin_contexts')} o"
            . ' ON o.payment_attempt_id=ps.public_id'
            . " LEFT JOIN {$p('webpay_returns')} wr ON wr.token_hash=o.token_hash"
            . " LEFT JOIN {$p('payment_reconciliations')} r"
            . ' ON r.webpay_return_id=wr.id AND r.origin_context_id=o.id'
            . " LEFT JOIN {$p('business_completions')} b"
            . ' ON b.reconciliation_id=r.id'
            . " LEFT JOIN {$p('delivery_completions')} d"
            . ' ON d.business_completion_id=b.id'
            . " LEFT JOIN {$p('fulfillment_completions')} f"
            . ' ON f.business_completion_id=b.id'
            . ' WHERE ps.checkout_id=%d ORDER BY ps.id DESC',
            $checkoutId
        ), ARRAY_A);
    }
}
