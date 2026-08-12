<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Infrastructure\DurableRetry;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;

final class DurableRetryActionHookRegistrar
{
    private const PRIORITY = 10;
    private const ACCEPTED_ARGUMENTS = 2;

    private bool $registered = false;

    public function __construct(
        private readonly DurableRetryActionCallback $callback
    ) {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        foreach (DurableRetryExternalScheduleCatalog::hooks() as $hook) {
            $callback = $this->callback;
            add_action(
                $hook,
                static function (
                    mixed $scheduleId,
                    mixed $generation
                ) use ($callback, $hook): void {
                    $callback->execute($hook, $scheduleId, $generation);
                },
                self::PRIORITY,
                self::ACCEPTED_ARGUMENTS
            );
        }
    }
}
