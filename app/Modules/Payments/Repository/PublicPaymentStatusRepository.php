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
            . ' wr.financial_status AS validated_financial_status,'
            . ' wr.financial_validated_at,'
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
            . ' f.updated_at AS fulfillment_updated_at,'
            . ' (SELECT COUNT(*) FROM ' . $p('payments') . ' p'
            . ' INNER JOIN ' . $p('payment_origin_contexts') . ' ao'
            . ' ON ao.payment_attempt_id=ps.public_id'
            . ' INNER JOIN ' . $p('webpay_returns') . ' awr'
            . ' ON awr.token_hash=ao.token_hash'
            . ' WHERE p.id=ps.payment_id AND p.payment_session_id=ps.id'
            . ' AND p.checkout_id=ps.checkout_id AND p.status=\'paid\''
            . ' AND p.customer_id=c.user_id AND c.owner_type=\'user\''
            . ' AND c.session_id IS NULL AND c.user_id IS NOT NULL'
            . ' AND p.currency=\'CLP\' AND ps.currency=p.currency'
            . ' AND c.currency=p.currency AND p.amount=ps.amount'
            . ' AND p.amount=c.total_amount'
            . ' AND awr.processing_status=\'completed\''
            . ' AND awr.result_status=\'approved\''
            . ' AND awr.financial_status=\'approved\''
            . ' AND awr.financial_validated_at IS NOT NULL'
            . ' AND awr.amount_clp=CAST(p.amount AS UNSIGNED)'
            . ' AND (SELECT COUNT(*) FROM ' . $p('checkout_orders') . ' co'
            . ' WHERE co.checkout_id=c.id)>0'
            . ' AND (SELECT COUNT(*) FROM ' . $p('checkout_orders') . ' co'
            . ' WHERE co.checkout_id=c.id)='
            . ' (SELECT COUNT(*) FROM ' . $p('payment_orders') . ' po'
            . ' INNER JOIN ' . $p('checkout_orders') . ' co'
            . ' ON co.order_id=po.order_id AND co.checkout_id=c.id'
            . ' WHERE po.payment_id=p.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $p('payment_orders') . ' po'
            . ' INNER JOIN ' . $p('checkout_orders') . ' co'
            . ' ON co.order_id=po.order_id AND co.checkout_id=c.id'
            . ' INNER JOIN ' . $p('orders') . ' ord ON ord.id=po.order_id'
            . ' WHERE po.payment_id=p.id AND (ord.customer_id<>p.customer_id'
            . ' OR ord.status NOT IN (\'paid\',\'delivered\')))'
            . ' AND p.amount=(SELECT COALESCE(SUM(ord.total),0)'
            . ' FROM ' . $p('payment_orders') . ' po'
            . ' INNER JOIN ' . $p('checkout_orders') . ' co'
            . ' ON co.order_id=po.order_id AND co.checkout_id=c.id'
            . ' INNER JOIN ' . $p('orders') . ' ord ON ord.id=po.order_id'
            . ' WHERE po.payment_id=p.id)) AS approved_payment_authority_count'
            . " FROM {$p('payment_sessions')} ps"
            . " INNER JOIN {$p('checkouts')} c ON c.id=ps.checkout_id"
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
