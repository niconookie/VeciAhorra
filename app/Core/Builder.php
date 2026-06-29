<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

/**
 * Builder base del Framework.
 */
abstract class Builder
{
    /**
     * Builder padre.
     */
    protected ?object $parent = null;

    /**
     * Define el builder padre.
     */
    public function parent(
        object $parent
    ): static {

        $this->parent = $parent;

        return $this;
    }

    /**
     * Finaliza el builder actual.
     */
    public function end(): object
    {
        return $this->parent ?? $this;
    }
}