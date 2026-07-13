<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Support\PaymentOriginKey;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;

final class DurablePaymentOrigin
{
    public const ORIGIN_WOOCOMMERCE = 'woocommerce';
    public const ORIGIN_VECIAHORRA = 'veciahorra_checkout';

    private readonly string $publicId;
    private readonly string $siteScope;
    private readonly string $origin;
    private readonly string $originResourceId;
    private readonly string $gatewayId;
    private readonly string $paymentAttemptId;
    private readonly int $amountClp;
    private readonly string $environment;
    private readonly string $merchantIdentityHash;
    private readonly string $buyOrder;
    private readonly string $financialSessionId;
    private readonly ?string $tokenHash;
    private readonly int $contextVersion;
    private readonly string $createdAt;
    private readonly string $updatedAt;
    private readonly string $expiresAt;

    public function __construct(
        string $publicId,
        string $siteScope,
        string $origin,
        string $originResourceId,
        string $gatewayId,
        string $paymentAttemptId,
        mixed $amountClp,
        string $environment,
        string $merchantIdentityHash,
        string $buyOrder,
        string $financialSessionId,
        ?string $tokenHash,
        int $contextVersion,
        string $createdAt,
        string $updatedAt,
        string $expiresAt
    ) {
        if (! in_array($origin, [
            self::ORIGIN_WOOCOMMERCE,
            self::ORIGIN_VECIAHORRA,
        ], true)) {
            throw new InvalidArgumentException('origin no es valido.');
        }

        if (
            ! in_array($gatewayId, ['veciahorra_webpay_plus', 'webpay_plus'], true)
            || ($origin === self::ORIGIN_WOOCOMMERCE
                && $gatewayId !== 'veciahorra_webpay_plus')
        ) {
            throw new InvalidArgumentException('gateway_id no es valido.');
        }

        if (! in_array($environment, ['integration', 'production'], true)) {
            throw new InvalidArgumentException('environment no es valido.');
        }

        if ($contextVersion <= 0) {
            throw new InvalidArgumentException('context_version no es valido.');
        }

        if (
            preg_match('/^poc_[a-f0-9]{32,56}$/D', $publicId) !== 1
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $paymentAttemptId) !== 1
            || ($origin === self::ORIGIN_WOOCOMMERCE
                && ! WordPressSiteScope::isValid($siteScope))
            || ($origin === self::ORIGIN_WOOCOMMERCE
                && (preg_match('/^[1-9]\d*$/D', $originResourceId) !== 1))
            || preg_match('/^VA[A-F0-9]{24}$/D', $buyOrder) !== 1
            || preg_match('/^VA-[A-F0-9]{58}$/D', $financialSessionId) !== 1
        ) {
            throw new InvalidArgumentException(
                'Las referencias durables no son validas.'
            );
        }

        $this->publicId = ReconciliationValidation::identifier(
            $publicId,
            'public_id'
        );
        $this->siteScope = ReconciliationValidation::identifier(
            $siteScope,
            'site_scope'
        );
        $this->origin = $origin;
        $this->originResourceId = ReconciliationValidation::identifier(
            $originResourceId,
            'origin_resource_id'
        );
        $this->gatewayId = $gatewayId;
        $this->paymentAttemptId = ReconciliationValidation::identifier(
            $paymentAttemptId,
            'payment_attempt_id'
        );
        $this->amountClp = ReconciliationValidation::clp($amountClp);
        $this->environment = $environment;
        $this->merchantIdentityHash = ReconciliationValidation::hash(
            $merchantIdentityHash,
            'merchant_identity_hash'
        );
        $this->buyOrder = $buyOrder;
        $this->financialSessionId = $financialSessionId;
        $this->tokenHash = $tokenHash === null
            ? null
            : ReconciliationValidation::hash($tokenHash, 'token_hash');
        $this->contextVersion = $contextVersion;
        $this->createdAt = ReconciliationValidation::mysqlDate(
            $createdAt,
            'created_at'
        );
        $this->updatedAt = ReconciliationValidation::mysqlDate(
            $updatedAt,
            'updated_at'
        );
        $this->expiresAt = ReconciliationValidation::mysqlDate(
            $expiresAt,
            'expires_at'
        );

        if ($this->expiresAt <= $this->createdAt) {
            throw new InvalidArgumentException('expires_at no es posterior.');
        }
    }

    public function originKey(): string
    {
        return PaymentOriginKey::make($this);
    }

    public function publicId(): string { return $this->publicId; }
    public function siteScope(): string { return $this->siteScope; }
    public function origin(): string { return $this->origin; }
    public function originResourceId(): string { return $this->originResourceId; }
    public function gatewayId(): string { return $this->gatewayId; }
    public function paymentAttemptId(): string { return $this->paymentAttemptId; }
    public function amountClp(): int { return $this->amountClp; }
    public function currency(): string { return 'CLP'; }
    public function environment(): string { return $this->environment; }
    public function merchantIdentityHash(): string { return $this->merchantIdentityHash; }
    public function buyOrder(): string { return $this->buyOrder; }
    public function financialSessionId(): string { return $this->financialSessionId; }
    public function tokenHash(): ?string { return $this->tokenHash; }
    public function contextVersion(): int { return $this->contextVersion; }
    public function createdAt(): string { return $this->createdAt; }
    public function updatedAt(): string { return $this->updatedAt; }
    public function expiresAt(): string { return $this->expiresAt; }
}
