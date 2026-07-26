<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Operational;

final readonly class ConsistencyFinding
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $affectedDimension,
        public bool $blocker,
        public string $title,
        public string $description,
        public array $evidence = [],
        public bool $historicalTolerance = false
    ) {
        OperationalStateCatalog::assert('severity', $severity);
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'affected_dimension' => $this->affectedDimension,
            'blocker' => $this->blocker,
            'title' => $this->title,
            'description' => $this->description,
            'evidence' => $this->evidence,
            'historical_tolerance' => $this->historicalTolerance,
        ];
    }
}
