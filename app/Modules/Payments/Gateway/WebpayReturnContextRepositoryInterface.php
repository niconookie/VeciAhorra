<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

interface WebpayReturnContextRepositoryInterface
{
    public function store(
        string $tokenHash,
        WebpayReturnContext $context,
        int $ttl
    ): void;

    public function find(string $tokenHash): ?WebpayReturnContext;

    public function forget(string $tokenHash): void;
}
