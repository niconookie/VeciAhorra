<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Support;

use mysqli;
use wpdb;

final class DatabaseErrorClassifier
{
    private const MYSQL_DUPLICATE_KEY = 1062;

    public static function isDuplicateKey(wpdb $database): bool
    {
        return $database->dbh instanceof mysqli
            && mysqli_errno($database->dbh) === self::MYSQL_DUPLICATE_KEY;
    }

    private function __construct()
    {
    }
}
