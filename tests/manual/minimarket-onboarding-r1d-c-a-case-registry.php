<?php
declare(strict_types=1);
require_once __DIR__.'/minimarket-onboarding-r1d-c-a-manifest-channel.php';

final class R1dcaCaseRegistry
{
    /** @var array<string, string> */
    private array $expected;
    /** @var array<string, true> */
    private array $executed = [];

    /** @param array<string, string> $expected */
    public function __construct(private readonly string $label, array $expected, private readonly ?string $idsLabel = null)
    {
        if ($expected === [] || count($expected) !== count(array_unique(array_keys($expected)))) {
            throw new RuntimeException('r1dca_registry_expected_invalid');
        }
        foreach ($expected as $id => $description) {
            if ($id === '' || $description === '') {
                throw new RuntimeException('r1dca_registry_expected_invalid');
            }
        }
        $this->expected = $expected;
    }

    public function run(string $id, callable $setup, callable $operation, callable $assertions, callable $cleanup): void
    {
        if (!isset($this->expected[$id]) || isset($this->executed[$id])) {
            throw new RuntimeException('r1dca_registry_case_invalid');
        }
        $context = null;
        $operationResult = null;
        $operationFailure = null;
        $cleanupFailure = null;
        try {
            $context = $setup();
            try {
                $operationResult = $operation($context);
            } catch (Throwable $exception) {
                $operationFailure = $exception;
            }
            $assertions($context, $operationResult, $operationFailure);
        } finally {
            try {
                $cleanup($context ?? $operationResult);
            } catch (Throwable $exception) {
                $cleanupFailure = $exception;
            }
        }
        if ($cleanupFailure !== null) {
            throw new RuntimeException('r1dca_registry_cleanup_failed');
        }
        $this->executed[$id] = true;
    }

    public function seal(): int
    {
        if (array_keys($this->executed) !== array_keys($this->expected)) {
            throw new RuntimeException('r1dca_registry_incomplete');
        }
        $count = count($this->executed);
        echo $this->idsLabel ?? $this->label.'_CASE_IDS', '=', implode(',', array_keys($this->executed)), PHP_EOL;
        echo $this->label, '=', $count, '/PASS', PHP_EOL;
        R1dcaManifestChannel::collect(array_keys($this->executed));
        return $count;
    }
}
