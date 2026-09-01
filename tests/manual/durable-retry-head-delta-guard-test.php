<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/tests/manual/support/durable-retry-head-delta-guard.php';

$gitShow = static function (string $path) use ($root): string {
    $content = shell_exec(
        'git -C ' . escapeshellarg($root) . ' cat-file --filters --path='
            . escapeshellarg($path) . ' ' . escapeshellarg('HEAD:' . $path) . ' 2>NUL'
    );
    if (! is_string($content)) {
        throw new RuntimeException("Unable to read {$path} from HEAD.");
    }
    return $content;
};
$rejects = static function (callable $mutation): void {
    try {
        $mutation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Guard accepted a forbidden mutation.');
};

$configPath = DurableRetryHeadDeltaGuard::CONFIG_PATH;
$repositoryPath = DurableRetryHeadDeltaGuard::ORDER_REPOSITORY_PATH;
$configHead = $gitShow($configPath);
$repositoryHead = $gitShow($repositoryPath);
$configAllowed = str_replace("PLUGIN_VERSION = '0.3.7'", "PLUGIN_VERSION = '0.3.8'", $configHead);
$method = <<<'PHP'
    /** @param list<int> $ids */
    public function markCancelled(array $ids, string $updatedAt): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $params = ['cancelled', $updatedAt, ...$ids, 'reserved'];
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET status = %%s, updated_at = %%s'
                . ' WHERE id IN (%s) AND status = %%s',
                $this->table(self::ORDERS_TABLE),
                $placeholders
            ),
            ...$params
        ));
        if ($result === false || $result !== count($ids)) {
            throw new PersistenceException('No fue posible cancelar los pedidos.');
        }
        return $result;
    }

PHP;
$repositoryAllowed = str_replace(
    "    public function markDelivered(int \$id, string \$updatedAt): void\n",
    $method . "\n    public function markDelivered(int \$id, string \$updatedAt): void\n",
    $repositoryHead,
    $insertions
);
$businessAllowed = (string) file_get_contents($root . '/' . DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH);
$sessionAllowed = (string) file_get_contents($root . '/' . DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH);
$statusAllowed = (string) file_get_contents($root . '/' . DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH);
$returnAllowed = (string) file_get_contents($root . '/' . DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH);
if ($insertions !== 1) {
    throw new RuntimeException('OrderRepository fixture insertion point changed.');
}

DurableRetryHeadDeltaGuard::assertAllowedCandidate($configPath, $configHead, $configAllowed);
DurableRetryHeadDeltaGuard::assertAllowedCandidate($repositoryPath, $repositoryHead, $repositoryAllowed);
DurableRetryHeadDeltaGuard::assertAllowedCandidate(
    $configPath,
    $configHead,
    (string) file_get_contents($root . '/' . $configPath)
);
DurableRetryHeadDeltaGuard::assertAllowedCandidate(
    $repositoryPath,
    $repositoryHead,
    (string) file_get_contents($root . '/' . $repositoryPath)
);
foreach ([
    DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH,
    DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH,
    DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH,
    DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH,
] as $paymentPath) {
    DurableRetryHeadDeltaGuard::assertAllowedCandidate(
        $paymentPath,
        $gitShow($paymentPath),
        (string) file_get_contents($root . '/' . $paymentPath)
    );
}

$cases = [
    'arbitrary Config delta' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($configPath, $configHead, str_replace("NAME = 'VeciAhorra'", "NAME = 'Changed'", $configAllowed)),
    'schema version delta' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($configPath, $configHead, str_replace("SCHEMA_VERSION = '0.32.0'", "SCHEMA_VERSION = '0.33.0'", $configAllowed)),
    'VERSION ceases to alias' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($configPath, $configHead, str_replace('VERSION = self::PLUGIN_VERSION', "VERSION = '0.3.8'", $configAllowed)),
    'pre-existing repository method changed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($repositoryPath, $repositoryHead, str_replace('markDelivered', 'markDeliveredChanged', $repositoryAllowed)),
    'second unauthorized method' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($repositoryPath, $repositoryHead, str_replace($method, $method . "    public function unauthorized(): void {}\n\n", $repositoryAllowed)),
    'missing reserved restriction' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($repositoryPath, $repositoryHead, str_replace("...\$ids, 'reserved'", '...$ids', $repositoryAllowed)),
    'missing exact cardinality' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($repositoryPath, $repositoryHead, str_replace(' || $result !== count($ids)', '', $repositoryAllowed)),
    'missing PersistenceException' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate($repositoryPath, $repositoryHead, str_replace('throw new PersistenceException', 'throw new RuntimeException', $repositoryAllowed)),
    'other certified path' => static fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate('app/Database/Schema.php', 'a', 'b'),
    'fingerprint field changed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH, $gitShow(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH), str_replace("'amount' => \$components->amountClp()", "'amount' => 1", $businessAllowed)),
    'fingerprint version changed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH, $gitShow(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH), str_replace('PaymentConfirmationFingerprint::VERSION', '2', $businessAllowed)),
    'safe reference changed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH, $gitShow(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH), str_replace('safeFinancialReference()', 'fingerprint()', $businessAllowed)),
    'evidence transaction guard removed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH, $gitShow(DurableRetryHeadDeltaGuard::BUSINESS_COMPLETION_PATH), str_replace("if ((\$session['status'] ?? null) === PaymentSession::STATUS_READY)", 'if (true)', $businessAllowed)),
    'cancelReady ready guard removed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH, $gitShow(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH), str_replace(' AND status = %%s', '', $sessionAllowed)),
    'cancelReady confirmed guard removed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH, $gitShow(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH), str_replace(' AND confirmed_at IS NULL', '', $sessionAllowed)),
    'cancelReady redirect retained' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH, $gitShow(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH), str_replace(', redirect_url = NULL', '', $sessionAllowed)),
    'cancelReady exception removed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH, $gitShow(DurableRetryHeadDeltaGuard::PAYMENT_SESSION_PATH), str_replace('throw new PersistenceException', 'return false; //', $sessionAllowed)),
    'aborted projected rejected' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH, $gitShow(DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH), str_replace("'aborted') { return \$this->state('payment_cancelled')", "'aborted') { return \$this->state('payment_rejected')", $statusAllowed)),
    'cancelled made nonterminal' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH, $gitShow(DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH), str_replace("'payment_cancelled' => [true, null", "'payment_cancelled' => [false, 3000", $statusAllowed)),
    'unauthorized origin cancellation' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH, $gitShow(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH), str_replace('=== DurablePaymentOrigin::ORIGIN_VECIAHORRA', '!== DurablePaymentOrigin::ORIGIN_VECIAHORRA', $returnAllowed)),
    'cancellation exception suppressed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH, $gitShow(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH), str_replace('throw $exception;', 'return $result;', $returnAllowed)),
    'repeated cancellation removed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH, $gitShow(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH), str_replace("&& isset(\$row['payment_session_id'])", "&& false && isset(\$row['payment_session_id'])", $returnAllowed)),
    'cancellation moved after complete' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH, $gitShow(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH), str_replace("        \$this->returns->complete(\n", "        // cancellation moved after complete\n        \$this->returns->complete(\n", $returnAllowed)),
    'preexisting payment method changed' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH, $gitShow(DurableRetryHeadDeltaGuard::PUBLIC_STATUS_PATH), str_replace('private function updatedAt', 'private function changedUpdatedAt', $statusAllowed)),
    'second constructor property' => fn (): mixed => DurableRetryHeadDeltaGuard::assertAllowedCandidate(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH, $gitShow(DurableRetryHeadDeltaGuard::WEBPAY_RETURN_PATH), str_replace('private ?PaymentTerminalOutcomeInterface $terminalOutcomes = null', 'private ?PaymentTerminalOutcomeInterface $terminalOutcomes = null, private mixed $extra = null', $returnAllowed)),
];
foreach ($cases as $case) {
    $rejects($case);
}

echo 'durable retry HEAD delta guard: PASS positive=2 negative=' . count($cases) . PHP_EOL;
