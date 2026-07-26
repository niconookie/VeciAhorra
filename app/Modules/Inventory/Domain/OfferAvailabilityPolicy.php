<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Domain;

use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;

/**
 * Deriva la disponibilidad publica efectiva sin consultar ni persistir datos.
 */
final class OfferAvailabilityPolicy
{
    public const POLICY = 'effective-v1';

    private const INVENTORY_STATUSES = ['active', 'inactive'];

    private const PRODUCT_STATUSES = ['draft', 'active', 'inactive'];

    private const STORE_STATUSES = [
        'pending',
        'active',
        'inactive',
        'rejected',
    ];

    public function __construct(
        private ?StoreLifecycleContract $storeLifecycle = null
    ) {
        $this->storeLifecycle ??= new StoreLifecycleContract();
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function evaluate(array $snapshot): array
    {
        $inventoryExists = ($snapshot['inventory_exists'] ?? true) === true;
        $productId = $this->positiveInteger($snapshot['product_id'] ?? null);
        $storeId = $this->positiveInteger($snapshot['minimarket_id'] ?? null);
        $resolvedProductId = $this->positiveInteger(
            $snapshot['resolved_product_id'] ?? null
        );
        $resolvedStoreId = $this->positiveInteger(
            $snapshot['resolved_store_id'] ?? null
        );
        $productExists = ($snapshot['product_exists'] ?? false) === true;
        $storeExists = ($snapshot['store_exists'] ?? false) === true;
        $inventoryStatus = $this->contractText(
            $snapshot['inventory_status'] ?? null
        );
        $productStatus = $this->contractText(
            $snapshot['product_status'] ?? null
        );
        $storeStatus = $this->contractText(
            $snapshot['store_status'] ?? null
        );
        $onboardingStatus = $this->contractText(
            $snapshot['store_onboarding_status'] ?? null
        );
        $approvedAt = $snapshot['store_approved_at'] ?? null;
        $price = $this->finiteNumber($snapshot['price'] ?? null);
        $stock = $this->integer($snapshot['stock'] ?? null);
        $storeLifecycleState = $storeExists
            ? $this->storeLifecycle->classify(
                $storeStatus ?? '',
                $onboardingStatus ?? '',
                $approvedAt
            )
            : null;

        $causes = [];

        if (! $inventoryExists) {
            $causes[] = $this->cause('inventory_missing');
        }

        if ($productId === null) {
            $causes[] = $this->cause('product_reference_invalid');
        }

        if ($storeId === null) {
            $causes[] = $this->cause('store_reference_invalid');
        }

        if ($productId !== null && ! $productExists) {
            $causes[] = $this->cause('product_missing');
        }

        if ($storeId !== null && ! $storeExists) {
            $causes[] = $this->cause('store_missing');
        }

        if (
            (
                $productExists
                && $productId !== null
                && $productId !== $resolvedProductId
            )
            || (
                $storeExists
                && $storeId !== null
                && $storeId !== $resolvedStoreId
            )
        ) {
            $causes[] = $this->cause('reference_mismatch');
        }

        if (! in_array($inventoryStatus, self::INVENTORY_STATUSES, true)) {
            $causes[] = $this->cause('inventory_status_unknown');
        } elseif ($inventoryStatus === 'inactive') {
            $causes[] = $this->cause('inventory_inactive');
        }

        if (
            $productExists
            && ! in_array($productStatus, self::PRODUCT_STATUSES, true)
        ) {
            $causes[] = $this->cause('product_status_unknown');
        } elseif ($productExists && $productStatus !== 'active') {
            $causes[] = $this->cause('product_not_public');
        }

        if (
            $storeExists
            && ! in_array($storeStatus, self::STORE_STATUSES, true)
        ) {
            $causes[] = $this->cause('store_status_unknown');
        } elseif ($storeExists && $storeStatus !== 'active') {
            $causes[] = $this->cause('store_not_active');
        }

        if ($price === null || $price <= 0) {
            $causes[] = $this->cause('invalid_public_price');
        }

        if ($stock === null || $stock <= 0) {
            $causes[] = $this->cause('out_of_stock');
        }

        $warnings = [];

        if (
            $storeExists
            && $storeLifecycleState === StoreLifecycleContract::STATE_INVALID
        ) {
            $warnings[] = $this->cause('store_lifecycle_inconsistent');
        }

        $available = $causes === [];
        $primary = $available
            ? $this->cause('publicly_available')
            : $causes[0];

        return [
            'is_publicly_available' => $available,
            'primary_cause' => $primary,
            'blocking_causes' => $causes,
            'blocking_codes' => array_column($causes, 'code'),
            'warnings' => $warnings,
            'warning_codes' => array_column($warnings, 'code'),
            'dimensions' => [
                'references' => [
                    'product_reference_valid' => $productId !== null,
                    'store_reference_valid' => $storeId !== null,
                    'product_resolved' => $productExists,
                    'store_resolved' => $storeExists,
                    'matches' => ! in_array(
                        'reference_mismatch',
                        array_column($causes, 'code'),
                        true
                    ),
                ],
                'inventory' => [
                    'exists' => $inventoryExists,
                    'observed_status' => $inventoryStatus,
                    'status_known' => in_array(
                        $inventoryStatus,
                        self::INVENTORY_STATUSES,
                        true
                    ),
                    'active' => $inventoryStatus === 'active',
                ],
                'product' => [
                    'exists' => $productExists,
                    'observed_status' => $productStatus,
                    'status_known' => in_array(
                        $productStatus,
                        self::PRODUCT_STATUSES,
                        true
                    ),
                    'public' => $productExists && $productStatus === 'active',
                ],
                'store' => [
                    'exists' => $storeExists,
                    'observed_status' => $storeStatus,
                    'status_known' => in_array(
                        $storeStatus,
                        self::STORE_STATUSES,
                        true
                    ),
                    'active' => $storeExists && $storeStatus === 'active',
                    'lifecycle_state' => $storeLifecycleState,
                    'lifecycle_consistent' => $storeExists
                        && $storeLifecycleState
                            !== StoreLifecycleContract::STATE_INVALID,
                ],
                'price' => [
                    'observed_value' => $price,
                    'valid_for_publication' => $price !== null && $price > 0,
                ],
                'stock' => [
                    'observed_value' => $stock,
                    'available' => $stock !== null && $stock > 0,
                ],
            ],
            'evaluated_policy' => self::POLICY,
        ];
    }

    /** @return array{code: string, label: string, message: string, severity: string} */
    private function cause(string $code): array
    {
        return match ($code) {
            'inventory_missing' => $this->definition($code, 'Inventory inexistente', 'La oferta no existe.', 'error'),
            'product_reference_invalid' => $this->definition($code, 'Referencia Product invalida', 'El Product ID de la oferta no es valido.', 'error'),
            'store_reference_invalid' => $this->definition($code, 'Referencia Store invalida', 'El Store ID de la oferta no es valido.', 'error'),
            'product_missing' => $this->definition($code, 'Product inexistente', 'El Product asociado no existe.', 'error'),
            'store_missing' => $this->definition($code, 'Store inexistente', 'El Store asociado no existe.', 'error'),
            'reference_mismatch' => $this->definition($code, 'Referencia contradictoria', 'La entidad resuelta no coincide con la referencia.', 'error'),
            'inventory_status_unknown' => $this->definition($code, 'Estado Inventory desconocido', 'El estado de Inventory no pertenece al contrato.', 'error'),
            'inventory_inactive' => $this->definition($code, 'Inventory inactiva', 'La oferta esta inactiva.', 'neutral'),
            'product_status_unknown' => $this->definition($code, 'Estado Product desconocido', 'El estado de Product no pertenece al contrato.', 'error'),
            'product_not_public' => $this->definition($code, 'Product no publico', 'El Product no esta activo.', 'warning'),
            'store_status_unknown' => $this->definition($code, 'Estado Store desconocido', 'El estado de Store no pertenece al contrato.', 'error'),
            'store_not_active' => $this->definition($code, 'Store no activo', 'El Store no esta activo.', 'warning'),
            'invalid_public_price' => $this->definition($code, 'Precio no publicable', 'El precio debe ser mayor que cero.', 'warning'),
            'out_of_stock' => $this->definition($code, 'Sin stock disponible', 'El stock disponible debe ser mayor que cero.', 'warning'),
            'store_lifecycle_inconsistent' => $this->definition($code, 'Lifecycle Store inconsistente', 'El lifecycle compuesto de Store es inconsistente.', 'warning'),
            default => $this->definition('publicly_available', 'Publica', 'La oferta cumple la politica publica vigente.', 'success'),
        };
    }

    /** @return array{code: string, label: string, message: string, severity: string} */
    private function definition(
        string $code,
        string $label,
        string $message,
        string $severity
    ): array {
        return compact('code', 'label', 'message', 'severity');
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number === false || $number <= 0 ? null : $number;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number === false ? null : $number;
    }

    private function finiteNumber(mixed $value): ?float
    {
        if (is_string($value)) {
            if (
                preg_match(
                    '/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D',
                    $value
                ) !== 1
            ) {
                return null;
            }
        } elseif (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function contractText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
