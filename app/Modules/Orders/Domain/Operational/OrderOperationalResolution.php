<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Operational;

final readonly class OrderOperationalResolution
{
    public function __construct(
        public string $primaryState,
        public array $dimensions,
        public string $consistencyState,
        public array $findings,
        public array $timeline,
        public string $operationalVersion,
        public string $observedAt,
        public array $evidenceSummary
    ) {
        OperationalStateCatalog::assert('primary', $primaryState);
        OperationalStateCatalog::assert('consistency', $consistencyState);
    }

    public function toArray(): array
    {
        $findings = array_map(
            static fn (ConsistencyFinding $finding): array => $finding->toArray(),
            $this->findings
        );

        return [
            'policy' => 'orders-operational-v1',
            'primary_state' => $this->primaryState,
            'dimensions' => $this->dimensions,
            'consistency' => [
                'classification' => $this->consistencyState,
                'findings' => $findings,
                'blockers' => array_values(array_filter($findings, static fn (array $finding): bool => $finding['blocker'])),
                'warnings' => array_values(array_filter($findings, static fn (array $finding): bool => in_array($finding['severity'], ['info', 'warning'], true))),
            ],
            'evidence_summary' => $this->evidenceSummary,
            'timeline' => $this->timeline,
            'concurrency' => [
                'operational_version' => $this->operationalVersion,
                'fingerprint_algorithm' => 'sha256-canonical-json-v1',
                'observed_at' => $this->observedAt,
            ],
            'allowed_actions' => ['view'],
            'mutable_actions' => [],
        ];
    }
}
