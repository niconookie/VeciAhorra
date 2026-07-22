<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Domain;

use VeciAhorra\Modules\Products\Exceptions\ProductLifecycleException;
use VeciAhorra\Modules\Products\Models\Product;

final class ProductLifecycleContract
{
    private const TRANSITIONS = [
        Product::STATUS_DRAFT => [Product::STATUS_ACTIVE, Product::STATUS_INACTIVE],
        Product::STATUS_ACTIVE => [Product::STATUS_INACTIVE],
        Product::STATUS_INACTIVE => [Product::STATUS_ACTIVE],
    ];

    public function assertState(string $state): void
    {
        if (! array_key_exists($state, self::TRANSITIONS)) {
            throw new ProductLifecycleException(
                'invalid_product_state',
                'El estado actual del producto no pertenece al contrato.'
            );
        }
    }

    public function assertTransition(string $from, string $to): void
    {
        $this->assertState($from);
        $this->assertState($to);

        if ($from === $to) {
            return;
        }

        if (! in_array($to, self::TRANSITIONS[$from], true)) {
            throw new ProductLifecycleException(
                'product_transition_not_allowed',
                'La transición de estado solicitada no está permitida.'
            );
        }
    }

    /** @return list<string> */
    public function targets(string $state): array
    {
        $this->assertState($state);

        return self::TRANSITIONS[$state];
    }
}
