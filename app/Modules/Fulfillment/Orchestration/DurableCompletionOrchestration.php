<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Orchestration;

final class DurableCompletionOrchestration
{
    public function register(): void
    {
        $workers = new DurableCompletionWorkers();
        add_action(DurableCompletionScheduler::RECONCILIATION, [$workers, 'reconciliation'], 10, 1);
        add_action(DurableCompletionScheduler::BUSINESS, [$workers, 'business'], 10, 1);
        add_action(DurableCompletionScheduler::DELIVERY, [$workers, 'delivery'], 10, 1);
        add_action(DurableCompletionScheduler::FULFILLMENT, [$workers, 'fulfillment'], 10, 1);
        add_action(DurableCompletionScheduler::RECOVERY, [new DurableCompletionRecovery(), 'recover']);
        add_action('action_scheduler_init', [$this, 'scheduleRecovery']);
    }

    public function scheduleRecovery(): void
    {
        if (function_exists('as_has_scheduled_action')
            && as_has_scheduled_action(DurableCompletionScheduler::RECOVERY, [], DurableCompletionScheduler::GROUP) === false) {
            as_schedule_recurring_action(time() + 60, 300, DurableCompletionScheduler::RECOVERY, [], DurableCompletionScheduler::GROUP, true);
        }
    }
}
