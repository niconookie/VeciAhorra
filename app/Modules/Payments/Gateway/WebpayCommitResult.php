<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

final class WebpayCommitResult
{
    public function __construct(
        public readonly string $status,
        public readonly int $responseCode,
        public readonly int $amount,
        public readonly string $buyOrder,
        public readonly string $sessionId,
        public readonly ?string $authorizationCode,
        public readonly ?string $paymentTypeCode,
        public readonly ?int $installmentsNumber,
        public readonly ?string $accountingDate,
        public readonly ?string $transactionDate,
        public readonly ?string $cardLastFour,
        public readonly int|float|null $balance
    ) {
    }

    public function isApproved(): bool
    {
        return $this->status === 'AUTHORIZED' && $this->responseCode === 0;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'response_code' => $this->responseCode,
            'amount' => $this->amount,
            'buy_order' => $this->buyOrder,
            'session_id' => $this->sessionId,
            'authorization_code' => $this->authorizationCode,
            'payment_type_code' => $this->paymentTypeCode,
            'installments_number' => $this->installmentsNumber,
            'accounting_date' => $this->accountingDate,
            'transaction_date' => $this->transactionDate,
            'card_last_four' => $this->cardLastFour,
            'balance' => $this->balance,
        ];
    }
}
