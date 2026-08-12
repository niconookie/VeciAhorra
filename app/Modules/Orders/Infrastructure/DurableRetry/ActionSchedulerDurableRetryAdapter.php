<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Infrastructure\DurableRetry;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult;

final class ActionSchedulerDurableRetryAdapter implements DurableRetryExternalSchedulerInterface
{
    public function schedule(
        string $hook,
        array $arguments,
        string $group,
        string $scheduledFor
    ): DurableRetryExternalScheduleResult {
        try {
            $arguments = DurableRetryExternalScheduleCatalog::normalizeIdentity(
                $hook,
                $arguments,
                $group
            );
            $timestamp = DurableRetryExternalScheduleCatalog::timestamp($scheduledFor);
        } catch (InvalidArgumentException) {
            return $this->result(DurableRetryExternalScheduleResult::INVALID_REQUEST);
        }

        if (! function_exists('as_schedule_single_action')
            || ! function_exists('as_get_scheduled_actions')
        ) {
            return $this->result(DurableRetryExternalScheduleResult::UNAVAILABLE);
        }

        try {
            $actionId = \as_schedule_single_action(
                $timestamp,
                $hook,
                $arguments,
                $group,
                true
            );
        } catch (Throwable) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }

        if (is_int($actionId) && $actionId > 0) {
            return $this->result(
                DurableRetryExternalScheduleResult::SCHEDULED,
                $actionId
            );
        }
        if ($actionId !== 0) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }

        $pending = $this->findPending($hook, $arguments, $group);

        return $pending->code() === DurableRetryExternalScheduleResult::FOUND
            ? $this->result(
                DurableRetryExternalScheduleResult::ALREADY_SCHEDULED,
                $pending->scheduledActionId()
            )
            : $this->result(
                $pending->code() === DurableRetryExternalScheduleResult::UNAVAILABLE
                    ? DurableRetryExternalScheduleResult::UNAVAILABLE
                    : DurableRetryExternalScheduleResult::EXTERNAL_ERROR
            );
    }

    public function findPending(
        string $hook,
        array $arguments,
        string $group
    ): DurableRetryExternalScheduleResult {
        try {
            $arguments = DurableRetryExternalScheduleCatalog::normalizeIdentity(
                $hook,
                $arguments,
                $group
            );
        } catch (InvalidArgumentException) {
            return $this->result(DurableRetryExternalScheduleResult::INVALID_REQUEST);
        }

        if (! function_exists('as_get_scheduled_actions')) {
            return $this->result(DurableRetryExternalScheduleResult::UNAVAILABLE);
        }

        try {
            $actionIds = \as_get_scheduled_actions([
                'hook' => $hook,
                'args' => $arguments,
                'group' => $group,
                'status' => 'pending',
                'per_page' => 2,
                'orderby' => 'date',
                'order' => 'ASC',
            ], 'ids');
        } catch (Throwable) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }

        if (! is_array($actionIds)) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }
        if ($actionIds === []) {
            return $this->result(DurableRetryExternalScheduleResult::NOT_FOUND);
        }
        if (count($actionIds) !== 1) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }
        $actionId = $this->providerActionId(array_values($actionIds)[0]);
        if ($actionId === null) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }

        return $this->result(
            DurableRetryExternalScheduleResult::FOUND,
            $actionId
        );
    }

    public function cancel(
        int $scheduledActionId,
        string $hook,
        array $arguments,
        string $group
    ): DurableRetryExternalScheduleResult {
        if ($scheduledActionId < 1) {
            return $this->result(DurableRetryExternalScheduleResult::INVALID_REQUEST);
        }
        try {
            $arguments = DurableRetryExternalScheduleCatalog::normalizeIdentity(
                $hook,
                $arguments,
                $group
            );
        } catch (InvalidArgumentException) {
            return $this->result(DurableRetryExternalScheduleResult::INVALID_REQUEST);
        }

        if (! function_exists('as_get_scheduled_actions')
            || ! function_exists('as_unschedule_action')
        ) {
            return $this->result(DurableRetryExternalScheduleResult::UNAVAILABLE);
        }

        $pending = $this->findPending($hook, $arguments, $group);
        if ($pending->code() === DurableRetryExternalScheduleResult::NOT_FOUND) {
            return $this->result(DurableRetryExternalScheduleResult::ALREADY_ABSENT);
        }
        if ($pending->code() !== DurableRetryExternalScheduleResult::FOUND) {
            return $pending;
        }
        if ($pending->scheduledActionId() !== $scheduledActionId) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }

        try {
            $cancelledId = \as_unschedule_action($hook, $arguments, $group);
        } catch (Throwable) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }

        $after = $this->findPending($hook, $arguments, $group);
        if ($after->code() !== DurableRetryExternalScheduleResult::NOT_FOUND) {
            return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
        }
        if ($cancelledId === $scheduledActionId) {
            return $this->result(
                DurableRetryExternalScheduleResult::CANCELLED,
                $scheduledActionId
            );
        }
        if ($cancelledId === null || $cancelledId === 0) {
            return $this->result(DurableRetryExternalScheduleResult::ALREADY_ABSENT);
        }

        return $this->result(DurableRetryExternalScheduleResult::EXTERNAL_ERROR);
    }

    private function result(
        string $code,
        ?int $scheduledActionId = null
    ): DurableRetryExternalScheduleResult {
        return new DurableRetryExternalScheduleResult($code, $scheduledActionId);
    }

    private function providerActionId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (
                strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0
            )
        ) {
            return null;
        }

        return (int) $value;
    }
}
