<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Orchestration;

final class DurableCompletionScheduler
{
    public const GROUP = 'veciahorra-completion';
    public const RECONCILIATION = 'veciahorra_process_payment_reconciliation';
    public const BUSINESS = 'veciahorra_process_business_completion';
    public const DELIVERY = 'veciahorra_process_delivery_completion';
    public const FULFILLMENT = 'veciahorra_process_fulfillment_completion';
    public const RECOVERY = 'veciahorra_recover_completion_pipeline';

    public function reconciliation(int $id): void { $this->schedule(self::RECONCILIATION, $id); }
    public function business(int $id): void { $this->schedule(self::BUSINESS, $id); }
    public function delivery(int $id): void { $this->schedule(self::DELIVERY, $id); }
    public function fulfillment(int $id): void { $this->schedule(self::FULFILLMENT, $id); }

    public function retry(string $hook, int $id, int $attempt): void
    {
        if ($attempt >= 5) { return; }
        $this->schedule($hook, $id, min(3600, 30 * (2 ** max(0, $attempt))));
    }

    private function schedule(string $hook, int $id, int $delay = 0): void
    {
        if ($id <= 0 || ! function_exists('as_schedule_single_action')) { return; }
        $args = ['authority_id' => $id];
        if (function_exists('as_has_scheduled_action')
            && as_has_scheduled_action($hook, $args, self::GROUP) !== false) {
            return;
        }
        as_schedule_single_action(time() + $delay, $hook, $args, self::GROUP, true);
    }
}
