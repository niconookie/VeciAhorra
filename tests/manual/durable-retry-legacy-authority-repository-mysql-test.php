<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$table = $wpdb->prefix . Config::TABLE_PREFIX . 'durable_retry_schedules';
$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
$assert($found === $table, "Authoritative table {$table} must exist.");
$assert(
    $table === $wpdb->prefix . 'va_durable_retry_schedules',
    'Physical table must combine the real WordPress and plugin prefixes.'
);

$repository = new DurableRetryLegacyAuthorityRepository($wpdb);
$empty = DurableRetryAuthorityIdentityCollection::fromIdentities();
$queriesBefore = $wpdb->num_queries;
$assert($repository->classifyBatch($empty)->isEmpty(), 'Empty batch must be empty.');
$assert(
    $wpdb->num_queries === $queriesBefore,
    'Empty batch must execute zero physical queries.'
);

$base = 8_100_000_000 + random_int(1, 99_000_000);
$absent = DurableRetryAuthorityIdentity::reconciliation($base);
$durable = DurableRetryAuthorityIdentity::reconciliation($base + 1);
$laterOnly = DurableRetryAuthorityIdentity::reconciliation($base + 2);
$incompatible = DurableRetryAuthorityIdentity::reconciliation($base + 3);
$secondDurable = DurableRetryAuthorityIdentity::reconciliation($base + 4);
$identities = DurableRetryAuthorityIdentityCollection::fromIdentities(
    $absent,
    $durable,
    $laterOnly,
    $incompatible,
    $secondDurable
);

$insert = static function (
    int $subjectId,
    int $generation,
    string $status = 'dispatching'
) use ($wpdb, $table): void {
    $now = '2026-01-01 00:00:00';
    $inserted = $wpdb->insert($table, [
        'public_id' => hash('sha256', "a3-mysql-{$subjectId}-{$generation}"),
        'stage' => 'reconciliation',
        'subject_id' => $subjectId,
        'completion_id' => $subjectId,
        'generation' => $generation,
        'attempt_number' => 0,
        'scheduled_for' => $now,
        'scheduled_action_id' => null,
        'dispatch_token_hash' => hash('sha256', "a3-token-{$subjectId}-{$generation}"),
        'status' => $status,
        'active_slot' => $status === 'dispatching' ? 1 : null,
        'version' => 1,
        'reason_code' => 'retryable_failure',
        'dispatched_at' => null,
        'claimed_at' => null,
        'consumed_at' => null,
        'terminal_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($inserted !== 1) {
        throw new RuntimeException('Unable to create isolated A3 MySQL fixture.');
    }
};

$wpdb->query('START TRANSACTION');
try {
    $insert($durable->subjectId(), 1);
    $insert($laterOnly->subjectId(), 2);
    $insert($incompatible->subjectId(), 1, 'incompatible');
    $insert($secondDurable->subjectId(), 1);

    $repositoryQueries = [];
    $captureQuery = static function (string $query) use (&$repositoryQueries): string {
        $repositoryQueries[] = $query;

        return $query;
    };
    add_filter('query', $captureQuery);
    $queriesBefore = $wpdb->num_queries;
    try {
        $batch = $repository->classifyBatch($identities);
    } finally {
        remove_filter('query', $captureQuery);
    }

    $assert(
        $wpdb->num_queries === $queriesBefore + 1,
        'Non-empty batch must execute exactly one physical query.'
    );
    $assert(
        count($repositoryQueries) === 1
            && preg_match('/^\s*SELECT\b/i', $repositoryQueries[0]) === 1,
        'Repository operation must contain exactly one SELECT.'
    );
    $assert(
        str_contains($repositoryQueries[0], $table),
        'Repository SELECT must target the real prefixed table.'
    );
    $assert(
        preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $repositoryQueries[0]) !== 1,
        'Repository must execute no INSERT, UPDATE or DELETE.'
    );
    $assert($batch->count() === 5, 'One batch must classify multiple identities.');
    $assert(
        $batch->forIdentity($absent)->isLegacyAuthorized(),
        'No persisted rows must classify legacy.'
    );
    $assert(
        $batch->forIdentity($durable)->isDurable(),
        'A valid generation 1 must classify durable.'
    );
    $assert(
        $batch->forIdentity($laterOnly)->isLegacyAuthorized(),
        'An isolated generation above 1 must classify legacy.'
    );
    $assert(
        $batch->forIdentity($incompatible)->isIndeterminate(),
        'An incompatible persisted row must classify indeterminate.'
    );
    $assert(
        $batch->forIdentity($secondDurable)->isDurable(),
        'A second identity in the same batch must classify independently.'
    );
    $assert($wpdb->last_error === '', 'Real A3 read must complete without database error.');
    $assert(Config::TABLE_PREFIX === 'va_', 'Certified plugin table prefix must remain exact.');
} finally {
    $wpdb->query('ROLLBACK');
}

echo "OK durable retry legacy authority repository mysql ({$assertions} assertions)\n";
