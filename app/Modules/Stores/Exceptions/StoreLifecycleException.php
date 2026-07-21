<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Exceptions;

use InvalidArgumentException;
use Throwable;

final class StoreLifecycleException extends InvalidArgumentException
{
    public function __construct(
        private string $reason,
        string $message,
        private ?string $field = null,
        private string $state = 'invalid',
        private ?string $action = null,
        int $code = 0,
        ?Throwable $previous = null,
        private array $domains = [],
        private array $counts = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function reason(): string { return $this->reason; }
    public function field(): ?string { return $this->field; }
    public function state(): string { return $this->state; }
    public function action(): ?string { return $this->action; }
    public function domains(): array { return $this->domains; }
    public function counts(): array { return $this->counts; }
}
