<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Support\PaymentConfirmationFingerprint;

final class PaymentConfirmationAudit
{
    public const EVENT_STARTED = 'confirmation_started';
    public const EVENT_SUCCEEDED = 'confirmation_succeeded';
    public const EVENT_IDEMPOTENT = 'confirmation_idempotent';
    public const EVENT_CONFLICT = 'confirmation_conflict';
    public const EVENT_ROLLED_BACK = 'confirmation_rolled_back';
    public const EVENT_AMBIGUOUS = 'confirmation_ambiguous';
    public const EVENT_RECOVERED = 'confirmation_recovered';
    public const EVENT_RESERVATION_EXPIRED = 'reservation_expired';
    public const EVENT_STATE_MISMATCH = 'state_mismatch';
    public const EVENT_FINANCIAL_MISMATCH = 'financial_mismatch';

    private const EVENTS = [
        self::EVENT_STARTED,
        self::EVENT_SUCCEEDED,
        self::EVENT_IDEMPOTENT,
        self::EVENT_CONFLICT,
        self::EVENT_ROLLED_BACK,
        self::EVENT_AMBIGUOUS,
        self::EVENT_RECOVERED,
        self::EVENT_RESERVATION_EXPIRED,
        self::EVENT_STATE_MISMATCH,
        self::EVENT_FINANCIAL_MISMATCH,
    ];
    private const SEVERITIES = ['info', 'warning', 'high', 'critical'];
    private const CONTEXT_FIELDS = [
        'origin', 'duration_ms', 'error_code', 'lock_order', 'recovery_state',
    ];

    public function __construct(
        public readonly string $correlationId,
        public readonly string $eventType,
        public readonly int $paymentSessionId,
        public readonly ?int $paymentId,
        public readonly int $checkoutId,
        public readonly ?string $confirmationFingerprint,
        public readonly ?int $confirmationFingerprintVersion,
        public readonly ?string $provider,
        public readonly ?string $amount,
        public readonly ?string $currency,
        public readonly ?string $previousState,
        public readonly ?string $resultingState,
        public readonly string $resultCode,
        public readonly string $severity,
        public readonly int $attemptNumber,
        public readonly ?string $safeFinancialReference,
        public readonly array $orderIds,
        public readonly array $context,
        public readonly string $createdAt
    ) {
        $this->validate();
    }

    public function toPersistence(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'event_type' => $this->eventType,
            'event_key' => $this->eventKey(),
            'payment_session_id' => $this->paymentSessionId,
            'payment_id' => $this->paymentId,
            'checkout_id' => $this->checkoutId,
            'confirmation_fingerprint' => $this->confirmationFingerprint,
            'confirmation_fingerprint_version' =>
                $this->confirmationFingerprintVersion,
            'provider' => $this->provider,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'previous_state' => $this->previousState,
            'resulting_state' => $this->resultingState,
            'result_code' => $this->resultCode,
            'severity' => $this->severity,
            'attempt_number' => $this->attemptNumber,
            'safe_financial_reference' => $this->safeFinancialReference,
            'order_ids_json' => wp_json_encode($this->orderIds),
            'context_json' => $this->context === []
                ? null
                : wp_json_encode($this->context, JSON_UNESCAPED_SLASHES),
            'created_at' => $this->createdAt,
        ];
    }

    public static function validEvent(string $eventType): bool
    {
        return in_array($eventType, self::EVENTS, true);
    }

    public function eventKey(): ?string
    {
        if (! in_array($this->eventType, [
            self::EVENT_SUCCEEDED,
            self::EVENT_CONFLICT,
            self::EVENT_RESERVATION_EXPIRED,
            self::EVENT_STATE_MISMATCH,
            self::EVENT_FINANCIAL_MISMATCH,
        ], true)) {
            return null;
        }

        return hash('sha256', implode('|', [
            'payment-confirmation-audit-v1',
            $this->paymentSessionId,
            $this->eventType,
            $this->confirmationFingerprint ?? 'none',
            $this->resultCode,
        ]));
    }

    private function validate(): void
    {
        foreach ([$this->paymentSessionId, $this->checkoutId] as $id) {
            if ($id <= 0) {
                throw new InvalidArgumentException(
                    'Los IDs de auditoria deben ser positivos.'
                );
            }
        }

        if ($this->paymentId !== null && $this->paymentId <= 0) {
            throw new InvalidArgumentException('payment_id no es valido.');
        }

        if (
            preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $this->correlationId) !== 1
            || ! self::validEvent($this->eventType)
            || ! in_array($this->severity, self::SEVERITIES, true)
            || preg_match('/^[a-z0-9_]{2,50}$/D', $this->resultCode) !== 1
            || $this->attemptNumber <= 0
            || preg_match(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',
                $this->createdAt
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'La estructura de auditoria no es valida.'
            );
        }

        if (
            ($this->amount !== null
                && preg_match('/^[1-9]\d*\.00$/D', $this->amount) !== 1)
            || ($this->currency !== null
                && preg_match('/^[A-Z]{3}$/D', $this->currency) !== 1)
            || ($this->provider !== null
                && preg_match('/^[a-z0-9_]{2,50}$/D', $this->provider) !== 1)
            || ($this->safeFinancialReference !== null
                && preg_match(
                    '/^sha256:[a-f0-9]{12,56}$/D',
                    $this->safeFinancialReference
                ) !== 1)
        ) {
            throw new InvalidArgumentException(
                'Los datos financieros de auditoria no son validos.'
            );
        }

        foreach ([$this->previousState, $this->resultingState] as $state) {
            if (
                $state !== null
                && preg_match('/^[a-z][a-z0-9_]{1,29}$/D', $state) !== 1
            ) {
                throw new InvalidArgumentException(
                    'El estado de auditoria no es valido.'
                );
            }
        }

        if (
            ($this->confirmationFingerprint === null)
                !== ($this->confirmationFingerprintVersion === null)
            || ($this->confirmationFingerprint !== null
                && $this->invalidFingerprint())
        ) {
            throw new InvalidArgumentException(
                'El fingerprint de auditoria no es valido.'
            );
        }

        $orderIds = $this->orderIds;

        foreach ($orderIds as $orderId) {
            if (! is_int($orderId) || $orderId <= 0) {
                throw new InvalidArgumentException(
                    'Las Orders de auditoria no son validas.'
                );
            }
        }

        $sorted = array_values(array_unique($orderIds));
        sort($sorted, SORT_NUMERIC);

        if ($orderIds === [] || $orderIds !== $sorted) {
            throw new InvalidArgumentException(
                'Las Orders de auditoria deben ser unicas y ordenadas.'
            );
        }

        if (array_diff(array_keys($this->context), self::CONTEXT_FIELDS) !== []) {
            throw new InvalidArgumentException(
                'El contexto de auditoria contiene campos no permitidos.'
            );
        }


        if (
            isset($this->context['origin'])
            && ! in_array($this->context['origin'], [
                'webpay_return', 'manual_recovery', 'internal_retry', 'test',
            ], true)
        ) {
            throw new InvalidArgumentException(
                'El origen de auditoria no es valido.'
            );
        }

        if (
            isset($this->context['duration_ms'])
            && (! is_int($this->context['duration_ms'])
                || $this->context['duration_ms'] < 0)
        ) {
            throw new InvalidArgumentException(
                'La duracion de auditoria no es valida.'
            );
        }

        foreach (['error_code', 'lock_order', 'recovery_state'] as $field) {
            if (
                isset($this->context[$field])
                && (! is_string($this->context[$field])
                    || preg_match(
                        '/^[a-z0-9_:.-]{2,80}$/D',
                        $this->context[$field]
                    ) !== 1)
            ) {
                throw new InvalidArgumentException(
                    "El contexto {$field} no es valido."
                );
            }
        }

        $serialized = wp_json_encode($this->toPersistence());

        if (
            $serialized === false
            || preg_match('/token_ws|api[_-]?key|card_number/i', $serialized) === 1
        ) {
            throw new InvalidArgumentException(
                'La auditoria contiene datos sensibles.'
            );
        }
    }

    private function invalidFingerprint(): bool
    {
        try {
            PaymentConfirmationFingerprint::assertHash(
                (string) $this->confirmationFingerprint
            );
        } catch (InvalidArgumentException) {
            return true;
        }

        return (int) $this->confirmationFingerprintVersion <= 0;
    }
}
