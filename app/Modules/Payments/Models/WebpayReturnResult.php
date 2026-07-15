<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

final class WebpayReturnResult
{
    public function __construct(
        public readonly string $result,
        public readonly ?int $paymentSessionId,
        public readonly string $tokenReference,
        public readonly ?array $financial = null,
        public readonly ?string $previousResult = null,
        public readonly ?string $publicCheckoutId = null
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'result' => $this->result,
            'payment_session_id' => $this->paymentSessionId,
            'token_reference' => $this->tokenReference,
            'business_state_updated' => false,
        ];

        if ($this->financial !== null) {
            $data['financial'] = $this->financial;
        }

        if ($this->previousResult !== null) {
            $data['previous_result'] = $this->previousResult;
        }

        return $data;
    }
}
