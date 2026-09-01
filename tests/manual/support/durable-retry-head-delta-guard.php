<?php

declare(strict_types=1);

final class DurableRetryHeadDeltaGuard
{
    public const CONFIG_PATH = 'app/Core/Config.php';
    public const ORDER_REPOSITORY_PATH = 'app/Modules/Orders/Repositories/OrderRepository.php';
    public const BUSINESS_COMPLETION_PATH = 'app/Modules/Payments/BusinessCompletion/Service/BusinessCompletionProcessor.php';
    public const PAYMENT_SESSION_PATH = 'app/Modules/Payments/Repository/PaymentSessionRepository.php';
    public const PUBLIC_STATUS_PATH = 'app/Modules/Payments/Service/PublicPaymentStatusService.php';
    public const WEBPAY_RETURN_PATH = 'app/Modules/Payments/Service/WebpayReturnService.php';

    private const CONFIG_OLD_VERSION = "    public const PLUGIN_VERSION = '0.3.7';";
    private const CONFIG_NEW_VERSION = "    public const PLUGIN_VERSION = '0.3.8';";

    private const MARK_CANCELLED_METHOD = <<<'PHP'
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

    /**
     * @param list<string> $changedPaths
     * @return list<string>
     */
    public static function unauthorizedPaths(string $root, array $changedPaths): array
    {
        $unauthorized = [];
        foreach (array_values(array_unique($changedPaths)) as $path) {
            $path = str_replace('\\', '/', $path);
            if (! in_array($path, [
                self::CONFIG_PATH,
                self::ORDER_REPOSITORY_PATH,
                self::BUSINESS_COMPLETION_PATH,
                self::PAYMENT_SESSION_PATH,
                self::PUBLIC_STATUS_PATH,
                self::WEBPAY_RETURN_PATH,
            ], true)) {
                $unauthorized[] = $path;
                continue;
            }

            $head = self::headContent($root, $path);
            $candidate = file_get_contents($root . '/' . $path);
            if (! is_string($candidate)) {
                $unauthorized[] = $path;
                continue;
            }

            try {
                self::assertAllowedCandidate($path, $head, $candidate);
            } catch (RuntimeException) {
                $unauthorized[] = $path;
            }
        }

        return $unauthorized;
    }

    public static function assertAllowedCandidate(string $path, string $head, string $candidate): void
    {
        $head = str_replace("\r\n", "\n", $head);
        $candidate = str_replace("\r\n", "\n", $candidate);
        if ($path === self::CONFIG_PATH) {
            self::assertConfig($head, $candidate);
            return;
        }
        if ($path === self::ORDER_REPOSITORY_PATH) {
            self::assertOrderRepository($head, $candidate);
            return;
        }
        if ($path === self::BUSINESS_COMPLETION_PATH) {
            self::assertExpected($head, $candidate, self::businessCompletion($head), 'BusinessCompletionProcessor');
            return;
        }
        if ($path === self::PAYMENT_SESSION_PATH) {
            self::assertExpected($head, $candidate, self::paymentSession($head), 'PaymentSessionRepository');
            return;
        }
        if ($path === self::PUBLIC_STATUS_PATH) {
            self::assertExpected($head, $candidate, self::publicStatus($head), 'PublicPaymentStatusService');
            return;
        }
        if ($path === self::WEBPAY_RETURN_PATH) {
            self::assertExpected($head, $candidate, self::webpayReturn($head), 'WebpayReturnService');
            return;
        }

        throw new RuntimeException('Path is outside the authorized delta.');
    }

    private static function assertConfig(string $head, string $candidate): void
    {
        if (substr_count($head, self::CONFIG_OLD_VERSION) !== 1
            || str_contains($head, self::CONFIG_NEW_VERSION)
        ) {
            throw new RuntimeException('HEAD Config version authority is unexpected.');
        }
        if (substr_count($candidate, self::CONFIG_NEW_VERSION) !== 1
            || str_contains($candidate, self::CONFIG_OLD_VERSION)
            || substr_count($candidate, "public const VERSION = self::PLUGIN_VERSION;") !== 1
            || substr_count($candidate, "public const SCHEMA_VERSION = '0.32.0';") !== 1
        ) {
            throw new RuntimeException('Config constants do not match the authorized authority.');
        }

        $normalized = str_replace(self::CONFIG_NEW_VERSION, self::CONFIG_OLD_VERSION, $candidate, $count);
        if ($count !== 1 || $normalized !== $head) {
            throw new RuntimeException('Config contains a delta other than the authorized plugin version.');
        }
    }

    private static function assertOrderRepository(string $head, string $candidate): void
    {
        if (str_contains($head, 'function markCancelled')) {
            throw new RuntimeException('HEAD already contains markCancelled.');
        }
        if (substr_count($candidate, 'function markCancelled') !== 1
            || substr_count($candidate, self::MARK_CANCELLED_METHOD) !== 1
        ) {
            throw new RuntimeException('markCancelled does not have the exact authorized signature and body.');
        }

        $normalized = str_replace(self::MARK_CANCELLED_METHOD . "\n", '', $candidate, $count);
        if ($count !== 1 || $normalized !== $head) {
            $limit = min(strlen($normalized), strlen($head));
            $offset = $limit;
            for ($index = 0; $index < $limit; ++$index) {
                if ($normalized[$index] !== $head[$index]) {
                    $offset = $index;
                    break;
                }
            }
            throw new RuntimeException(
                "OrderRepository contains a delta other than markCancelled at byte {$offset} "
                    . '(candidate=' . strlen($normalized) . ', HEAD=' . strlen($head) . ').'
            );
        }
    }

    private static function headContent(string $root, string $path): string
    {
        $command = 'git -C ' . escapeshellarg($root)
            . ' cat-file --filters --path=' . escapeshellarg($path)
            . ' ' . escapeshellarg('HEAD:' . $path) . ' 2>NUL';
        $content = shell_exec($command);
        if (! is_string($content)) {
            throw new RuntimeException("Unable to read {$path} from HEAD.");
        }

        return $content;
    }

    private static function assertExpected(string $head, string $candidate, string $expected, string $authority): void
    {
        if ($candidate !== $expected) {
            throw new RuntimeException("{$authority} contains an unauthorized delta.");
        }
    }

    private static function replaceOnce(string $source, string $old, string $new, string $authority): string
    {
        $result = str_replace($old, $new, $source, $count);
        if ($count !== 1) {
            throw new RuntimeException("{$authority} HEAD anchor is unexpected.");
        }
        return $result;
    }

    private static function businessCompletion(string $head): string
    {
        $expected = self::replaceOnce(
            $head,
            "use VeciAhorra\\Modules\\Payments\\Repository\\PaymentSessionRepository;\n",
            "use VeciAhorra\\Modules\\Payments\\Repository\\PaymentSessionRepository;\nuse VeciAhorra\\Modules\\Payments\\Support\\PaymentConfirmationFingerprint;\n",
            'BusinessCompletionProcessor import'
        );
        $anchor = "                \$this->sessions->linkPayment((int) \$session['id'], \$paymentId);\n";
        $block = <<<'PHP'
                if (($session['status'] ?? null) === PaymentSession::STATUS_READY) {
                    $components = $reconciliation->financialResult()->components();
                    $confirmationFingerprint = PaymentConfirmationFingerprint::make([
                        'provider' => 'webpay_plus',
                        'payment_session_id' => (int) $session['id'],
                        'payment_id' => $paymentId,
                        'checkout_id' => (int) $checkout['id'],
                        'order_ids' => $orderIds,
                        'amount' => $components->amountClp(),
                        'currency' => 'CLP',
                        'buy_order' => $components->buyOrder(),
                        'financial_session_id' => $components->financialSessionId(),
                        'safe_financial_reference' => $reconciliation->financialResult()->safeFinancialReference(),
                        'transaction_date' => (string) $components->transactionDate(),
                    ]);
                    $this->sessions->storeConfirmationEvidence(
                        (int) $session['id'],
                        $paymentId,
                        $confirmationFingerprint,
                        PaymentConfirmationFingerprint::VERSION,
                        $reconciliation->financialResult()->safeFinancialReference(),
                        $now
                    );
                }
PHP;
        return self::replaceOnce($expected, $anchor, $anchor . $block . "\n", 'BusinessCompletionProcessor evidence');
    }

    private static function paymentSession(string $head): string
    {
        $anchor = "    public function findByKey(int \$checkoutId, string \$key): ?array\n";
        $method = <<<'PHP'
    public function cancelReady(int $sessionId, string $updatedAt): bool
    {
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET status = %%s, redirect_url = NULL, updated_at = %%s'
                . ' WHERE id = %%d AND status = %%s AND confirmed_at IS NULL',
                $this->table(self::TABLE)
            ),
            PaymentSession::STATUS_CANCELLED,
            $updatedAt,
            $sessionId,
            PaymentSession::STATUS_READY
        ));

        if ($result === false) {
            throw new PersistenceException('No fue posible cancelar PaymentSession.');
        }

        return $result === 1;
    }

PHP;
        return self::replaceOnce($head, $anchor, $method . "\n" . $anchor, 'PaymentSessionRepository cancelReady');
    }

    private static function publicStatus(string $head): string
    {
        $expected = self::replaceOnce(
            $head,
            "        if (\$this->hasApprovedPaymentAuthority(\$row)) {\n            return \$this->state('payment_approved');\n        }\n\n        \$fulfillment = \$row['fulfillment_status'] ?? null;\n",
            "        if (\$this->hasApprovedPaymentAuthority(\$row)) {\n            return \$this->state('payment_approved');\n        }\n\n        \$returnStatus = \$row['return_result_status'] ?? null;\n        if (\$returnStatus === 'aborted') { return \$this->state('payment_cancelled'); }\n        if (\$returnStatus === 'rejected') { return \$this->state('payment_rejected'); }\n\n        \$fulfillment = \$row['fulfillment_status'] ?? null;\n",
            'PublicPaymentStatusService precedence'
        );
        $expected = self::replaceOnce($expected, "        \$returnStatus = \$row['return_result_status'] ?? null;\n        \$returnProcessing", "        \$returnProcessing", 'PublicPaymentStatusService duplicate authority');
        return self::replaceOnce(
            $expected,
            "            'payment_rejected' => [true, null, 'El pago fue rechazado. Puedes iniciar un nuevo intento.', 'retry_payment'],\n",
            "            'payment_rejected' => [true, null, 'El pago fue rechazado. Puedes iniciar un nuevo intento.', 'retry_payment'],\n            'payment_cancelled' => [true, null, 'Pago cancelado. Puedes iniciar un nuevo intento.', 'retry_payment'],\n",
            'PublicPaymentStatusService cancelled state'
        );
    }

    private static function webpayReturn(string $head): string
    {
        $expected = self::replaceOnce($head, "use VeciAhorra\\Modules\\Payments\\Gateway\\PaymentGatewayException;\n", "use VeciAhorra\\Modules\\Payments\\Gateway\\PaymentGatewayException;\nuse VeciAhorra\\Modules\\Payments\\Contracts\\PaymentTerminalOutcomeInterface;\n", 'WebpayReturnService import');
        $expected = self::replaceOnce($expected, "        private ?PaymentOriginContextRepository \$durableOrigins = null\n", "        private ?PaymentOriginContextRepository \$durableOrigins = null,\n        private ?PaymentTerminalOutcomeInterface \$terminalOutcomes = null\n", 'WebpayReturnService constructor');
        $repeatedAnchor = "        return new WebpayReturnResult(\n";
        $repeatedBlock = "        if (\$durableOrigin?->origin() === DurablePaymentOrigin::ORIGIN_VECIAHORRA\n            && in_array(\$row['result_status'] ?? null, ['rejected', 'aborted'], true)\n            && isset(\$row['payment_session_id']) && (int) \$row['payment_session_id'] > 0) {\n            \$this->terminalOutcomes?->cancel(\n                (int) \$row['payment_session_id'],\n                \$durableOrigin->originResourceId()\n            );\n        }\n\n";
        $repeatedPosition = strpos($expected, $repeatedAnchor, strpos($expected, 'private function repeated('));
        if ($repeatedPosition === false) { throw new RuntimeException('WebpayReturnService repeated anchor is unexpected.'); }
        $expected = substr_replace($expected, $repeatedBlock, $repeatedPosition, 0);
        $finalizeAnchor = "        \$this->returns->complete(\n";
        $finalizeBlock = "        if (\$durableOrigin?->origin() === DurablePaymentOrigin::ORIGIN_VECIAHORRA\n            && in_array(\$result->result, ['rejected', 'aborted'], true)\n            && \$result->paymentSessionId !== null) {\n            try {\n                \$this->terminalOutcomes?->cancel(\n                    \$result->paymentSessionId,\n                    \$durableOrigin->originResourceId()\n                );\n            } catch (\\Throwable \$exception) {\n                \$this->returns->fail(\$tokenHash, current_time('mysql'));\n                throw \$exception;\n            }\n        }\n\n";
        $finalizePosition = strpos($expected, $finalizeAnchor, strpos($expected, 'private function finalize('));
        if ($finalizePosition === false) { throw new RuntimeException('WebpayReturnService finalize anchor is unexpected.'); }
        return substr_replace($expected, $finalizeBlock, $finalizePosition, 0);
    }
}
