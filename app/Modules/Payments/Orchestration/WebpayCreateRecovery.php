<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Orchestration;

use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;

final class WebpayCreateRecovery
{
    public const RECOVER_HOOK = 'veciahorra_webpay_create_recover';
    public const SWEEP_HOOK = 'veciahorra_webpay_create_recovery_sweep';
    public const GROUP = 'veciahorra-webpay-create';

    public function __construct(
        private readonly PaymentSessionRepository $sessions = new PaymentSessionRepository(),
        private readonly CheckoutRepository $transactions = new CheckoutRepository()
    ) {}

    public function register(): void
    {
        add_action(self::RECOVER_HOOK, [$this, 'recoverOne']);
        add_action(self::SWEEP_HOOK, [$this, 'sweep']);
        add_action('action_scheduler_init', [$this, 'scheduleSweep']);
    }

    public static function schedule(int $sessionId, int $delay = 125): void
    {
        if ($sessionId <= 0 || ! function_exists('as_schedule_single_action')) {
            return;
        }
        $args = ['payment_session_id' => $sessionId];
        if (function_exists('as_has_scheduled_action')
            && as_has_scheduled_action(self::RECOVER_HOOK, $args, self::GROUP) !== false) {
            return;
        }
        as_schedule_single_action(time() + $delay, self::RECOVER_HOOK, $args, self::GROUP, true);
    }

    public function recoverOne(int $paymentSessionId): void
    {
        $now = current_time('mysql');
        $this->transactions->transaction(function () use ($paymentSessionId, $now): void {
            $this->sessions->classifyExpiredCreateClaim($paymentSessionId, $now);
        });
    }

    public function sweep(): void
    {
        foreach ($this->sessions->findExpiredCreateClaims(current_time('mysql')) as $id) {
            self::schedule($id, 1);
        }
    }

    public function scheduleSweep(): void
    {
        if (! function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (! function_exists('as_has_scheduled_action')
            || as_has_scheduled_action(self::SWEEP_HOOK, [], self::GROUP) === false) {
            as_schedule_recurring_action(time() + 60, 300, self::SWEEP_HOOK, [], self::GROUP, true);
        }
    }
}
