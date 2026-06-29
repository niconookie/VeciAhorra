<?php

declare(strict_types=1);

namespace VeciAhorra\Admin\Tables;

use Closure;
use VeciAhorra\Core\Builder;

/**
 * Representa una columna de una tabla.
 */
final class Column extends Builder
{
    private bool $sortable = false;

    private ?string $width = null;

    private ?Closure $callback = null;

    /**
    * Permite buscar por esta columna.
    */
    private bool $searchable = false;

    /**
    * Alineación.
    */
    private string $align = 'left';

    /**
    * Oculta la columna.
    */
    private bool $hidden = false;

    /**
    * Clase CSS.
    */
    private ?string $class = null;

    /**
    * Mostrar como badge.
    */
    private bool $badge = false;

    private function __construct(
        private readonly string $name,
        private readonly string $label
    ) {
    }

    /**
     * Constructor fluido.
     */
    public static function make(
        string $name,
        string $label
    ): self {
        return new self($name, $label);
    }

    /**
     * Columna ordenable.
     */
    public function sortable(
        bool $state = true
    ): self {

        $this->sortable = $state;

        return $this;
    }

    /**
     * Define el ancho.
     */
    public function width(
        string $width
    ): self {

        $this->width = $width;

        return $this;
    }

    /**
     * Callback personalizado.
     */
    public function displayUsing(
    Closure $callback
    ): self {

    $this->callback = $callback;

    return $this;
}

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sortableState(): bool
    {
        return $this->sortable;
    }

    public function widthValue(): ?string
    {
        return $this->width;
    }

    public function callback(): ?Closure
{
    return $this->callback;
}

/**
 * Permite búsqueda.
 */
public function searchable(
    bool $state = true
): self {

    $this->searchable = $state;

    return $this;
}

/**
 * Alineación.
 */
public function align(
    string $align
): self {

    $this->align = $align;

    return $this;
}

/**
 * Oculta la columna.
 */
public function hidden(
    bool $state = true
): self {

    $this->hidden = $state;

    return $this;
}

/**
 * Clase CSS.
 */
public function cssClass(
    string $class
): self {

    $this->class = $class;

    return $this;
}

/**
 * Mostrar como badge.
 */
public function badge(
    bool $state = true
): self {

    $this->badge = $state;

    return $this;
}

public function searchableState(): bool
{
    return $this->searchable;
}

public function alignValue(): string
{
    return $this->align;
}

public function hiddenState(): bool
{
    return $this->hidden;
}

public function classValue(): ?string
{
    return $this->class;
}

public function badgeState(): bool
{
    return $this->badge;
}

}