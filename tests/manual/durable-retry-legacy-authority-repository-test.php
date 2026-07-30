<?php

declare(strict_types=1);

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'custom_';
        public string $last_error = '';
        public array $rows = [];
        public int $prepareCalls = 0;
        public int $readCalls = 0;
        public string $preparedSql = '';
        public array $preparedValues = [];

        public function prepare(string $sql, mixed ...$values): string
        {
            ++$this->prepareCalls;
            $this->preparedSql = $sql;
            $this->preparedValues = $values;

            return $sql;
        }

        public function get_results(string $sql, mixed $format): ?array
        {
            ++$this->readCalls;

            return $this->rows;
        }
    }
}

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryIndeterminateReason;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$row = static function (int $id, int $subjectId, int $generation = 1): array {
    return [
        'id' => (string) $id,
        'public_id' => hash('sha256', "public-{$id}"),
        'stage' => 'reconciliation',
        'subject_id' => (string) $subjectId,
        'completion_id' => (string) $subjectId,
        'generation' => (string) $generation,
        'attempt_number' => (string) ($generation - 1),
        'scheduled_for' => '2030-01-01 00:00:00',
        'scheduled_action_id' => null,
        'dispatch_token_hash' => hash('sha256', "token-{$id}"),
        'status' => 'dispatching',
        'active_slot' => '1',
        'version' => '1',
        'reason_code' => 'retryable_failure',
        'dispatched_at' => null,
        'claimed_at' => null,
        'consumed_at' => null,
        'terminal_at' => null,
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => '2030-01-01 00:00:00',
    ];
};

$database = new wpdb();
$repository = new DurableRetryLegacyAuthorityRepository($database);
$assert($database->readCalls === 0, 'Constructor must not read.');

$identity = DurableRetryAuthorityIdentity::reconciliation(41);
$result = $repository->classify($identity);
$assert($result->isLegacyAuthorized(), 'No rows must classify legacy.');
$assert($database->readCalls === 1, 'Individual classification must read once.');
$assert(str_contains($database->preparedSql, 'custom_va_durable_retry_schedules'), 'Physical table must use both prefixes.');
$assert(! str_contains($database->preparedSql, 'veciahorra_'), 'Incorrect table prefix must not be used.');
$assert($database->preparedValues === ['reconciliation', 41], 'Query parameters must be prepared.');

$database->rows = [$row(2, 41, 2)];
$result = $repository->classify($identity);
$assert($result->isLegacyAuthorized(), 'Later generation without generation one remains legacy.');

$database->rows = [$row(1, 41)];
$result = $repository->classify($identity);
$assert($result->isDurable(), 'Valid generation one must classify durable.');

$duplicate = $row(1, 41);
$database->rows = [$duplicate, $duplicate];
$assert($repository->classify($identity)->isDurable(), 'Equivalent duplicate evidence must classify deterministically.');

$database->rows = [$row(1, 41), $row(3, 41)];
$result = $repository->classify($identity);
$assert($result->isIndeterminate(), 'Contradictory generation one rows must be indeterminate.');
$assert($result->reason() === DurableRetryIndeterminateReason::PERSISTED_DUPLICATE, 'Duplicate reason must be exact.');

$corrupt = $row(1, 41);
$corrupt['completion_id'] = '99';
$database->rows = [$corrupt];
$assert($repository->classify($identity)->isIndeterminate(), 'Corrupt row must be indeterminate.');

$database->rows = [];
$database->last_error = 'read failed';
$result = $repository->classify($identity);
$assert($result->isIndeterminate(), 'Database error must be indeterminate.');
$assert($result->reason() === DurableRetryIndeterminateReason::QUERY_FAILED, 'Query failure reason must be exact.');
$database->last_error = '';

$second = DurableRetryAuthorityIdentity::reconciliation(42);
$database->rows = [$row(4, 42)];
$before = $database->readCalls;
$batch = $repository->classifyBatch(
    DurableRetryAuthorityIdentityCollection::fromIdentities($identity, $second)
);
$assert($database->readCalls === $before + 1, 'Non-empty batch must use one read.');
$assert($batch->forIdentity($identity)->isLegacyAuthorized(), 'Missing batch identity must be legacy.');
$assert($batch->forIdentity($second)->isDurable(), 'Batch generation one must be durable.');

$before = $database->readCalls;
$empty = $repository->classifyBatch(
    DurableRetryAuthorityIdentityCollection::fromIdentities()
);
$assert($empty->isEmpty(), 'Empty batch must return empty.');
$assert($database->readCalls === $before, 'Empty batch must perform zero reads.');

$database->rows = [];
$assert($repository->classify($identity)->isLegacyAuthorized(), 'Later invocation must observe changed data.');

echo "OK durable retry legacy authority repository ({$assertions} assertions)\n";
