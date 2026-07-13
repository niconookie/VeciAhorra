<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use Throwable;
use VeciAhorra\Database\Database;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Exceptions\AmbiguousPaymentCommit;
use VeciAhorra\Modules\Payments\Exceptions\PaymentConfirmationFailure;

class PaymentConfirmationTransaction
{
    public function run(callable $callback): mixed
    {
        $db = (new Database())->getConnection();

        if ($db->query('START TRANSACTION') === false) {
            throw $this->databaseFailure((string) $db->last_error);
        }

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $error = (string) $db->last_error;
            $db->query('ROLLBACK');

            if ($exception instanceof PaymentConfirmationFailure) {
                throw $exception;
            }

            if ($exception instanceof PersistenceException) {
                throw $this->databaseFailure($error, $exception);
            }

            throw $exception;
        }

        if ($db->query('COMMIT') === false) {
            throw new AmbiguousPaymentCommit();
        }

        return $result;
    }

    public function resetConnection(): bool
    {
        $db = (new Database())->getConnection();
        $db->close();

        return $db->db_connect(false);
    }

    private function databaseFailure(
        string $databaseError,
        ?Throwable $previous = null
    ): PaymentConfirmationFailure {
        $error = strtolower($databaseError);

        if (str_contains($error, 'deadlock') || str_contains($error, '1213')) {
            return new PaymentConfirmationFailure('deadlock', true, 'warning');
        }

        if (
            str_contains($error, 'lock wait timeout')
            || str_contains($error, '1205')
        ) {
            return new PaymentConfirmationFailure(
                'lock_timeout',
                true,
                'warning'
            );
        }

        return new PaymentConfirmationFailure(
            $previous === null
                ? 'transient_database_error'
                : 'permanent_database_error',
            $previous === null,
            'high'
        );
    }
}
