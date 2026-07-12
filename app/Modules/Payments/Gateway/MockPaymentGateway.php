<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class MockPaymentGateway implements PaymentGatewayInterface
{
    public const SCENARIO_SUCCESS = 'success';
    public const SCENARIO_REJECTED = 'rejected';
    public const SCENARIO_EXPIRED = 'expired';

    /** @var array<string, array{fingerprint: string, result: GatewaySessionResult}> */
    private array $sessions = [];

    public function __construct(
        private string $scenario = self::SCENARIO_SUCCESS,
        private ?DateTimeImmutable $now = null
    ) {
        if (! in_array($scenario, [
            self::SCENARIO_SUCCESS,
            self::SCENARIO_REJECTED,
            self::SCENARIO_EXPIRED,
        ], true)) {
            throw new InvalidArgumentException('El escenario Mock no es valido.');
        }
    }

    public function createSession(
        PaymentSessionContext $context
    ): GatewaySessionResult {
        $stored = $this->sessions[$context->idempotencyKey] ?? null;

        if ($stored !== null) {
            if (! hash_equals($stored['fingerprint'], $context->fingerprint())) {
                throw new InvalidArgumentException(
                    'La clave Mock fue usada con un contexto diferente.'
                );
            }

            return $stored['result'];
        }

        $reference = 'MOCK-' . strtoupper(substr(
            hash('sha256', $context->fingerprint()),
            0,
            24
        ));
        $now = $this->now ?? current_datetime();
        $maximumExpiration = new DateTimeImmutable(
            $context->expiresAt,
            wp_timezone()
        );
        $normalExpiration = $now->modify('+15 minutes');
        $expiresAt = $normalExpiration < $maximumExpiration
            ? $normalExpiration
            : $maximumExpiration;
        $status = GatewaySessionResult::STATUS_READY;
        $errorCode = null;

        if ($this->scenario === self::SCENARIO_REJECTED) {
            $status = GatewaySessionResult::STATUS_REJECTED;
            $errorCode = 'mock_rejected';
        } elseif ($this->scenario === self::SCENARIO_EXPIRED) {
            $status = GatewaySessionResult::STATUS_EXPIRED;
            $expiresAt = $now->modify('-1 second');
            $errorCode = 'mock_expired';
        }

        $result = new GatewaySessionResult(
            'mock',
            $reference,
            $status,
            $status === GatewaySessionResult::STATUS_READY
                ? home_url('/veciahorra/mock-payment/' . rawurlencode($reference))
                : null,
            $expiresAt->format('Y-m-d H:i:s'),
            $errorCode
        );
        $this->sessions[$context->idempotencyKey] = [
            'fingerprint' => $context->fingerprint(),
            'result' => $result,
        ];

        return $result;
    }

    public function recoverSession(
        string $providerSessionId
    ): GatewaySessionResult {
        foreach ($this->sessions as $session) {
            if ($session['result']->providerSessionId === $providerSessionId) {
                return $session['result'];
            }
        }

        throw new RuntimeException('La sesion Mock solicitada no existe.');
    }
}
