<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Orchestration;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer;

final class WebpayReturnRecovery
{
    public const HOOK = 'veciahorra_webpay_return_recovery';
    public const GROUP = 'veciahorra-webpay-return';

    public function __construct(
        private readonly PaymentOriginContextRepository $origins = new PaymentOriginContextRepository(),
        private readonly WebpayReconciliationMaterializer $materializer = new WebpayReconciliationMaterializer()
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'recover']);
        add_action('action_scheduler_init', [$this, 'schedule']);
    }

    public function schedule(): void
    {
        if (! function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (! function_exists('as_has_scheduled_action')
            || as_has_scheduled_action(self::HOOK, [], self::GROUP) === false) {
            as_schedule_recurring_action(time() + 90, 300, self::HOOK, [], self::GROUP, true);
        }
    }

    public function recover(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
        $rows = $wpdb->get_results(
            "SELECT wr.token_hash, o.id AS origin_id"
            . " FROM {$prefix}webpay_returns wr"
            . " JOIN {$prefix}payment_origin_contexts o"
            . ' ON o.token_hash=wr.token_hash'
            . " LEFT JOIN {$prefix}payment_reconciliations r"
            . ' ON r.webpay_return_id=wr.id'
            . " WHERE wr.processing_status='completed'"
            . ' AND wr.financial_fingerprint IS NOT NULL AND r.id IS NULL'
            . ' ORDER BY wr.id ASC LIMIT 100',
            ARRAY_A
        );
        foreach ($rows as $row) {
            $origin = $this->origins->find((int) $row['origin_id']);
            if ($origin !== null) {
                $this->materializer->resume((string) $row['token_hash'], $origin);
            }
        }
    }
}
