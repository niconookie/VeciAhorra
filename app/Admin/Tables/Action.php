<?php

declare(strict_types=1);

namespace VeciAhorra\Admin\Tables;

use Closure;

/**
 * Acción de una tabla.
 */
final class Action
{
    private ?string $label = null;

    private ?string $icon = null;

    private ?string $color = null;

    private ?Closure $url = null;

    private bool $confirm = false;

    private ?string $confirmMessage = null;

    private function __construct(
        private readonly string $name
    ) {
    }

    /**
     * Constructor fluido.
     */
    public static function make(
        string $name
    ): self {

        return new self($name);
    }

    /**
     * Acción Editar.
     */
    public static function edit(): self
    {
        return self::make('edit')
            ->label('Editar')
            ->icon('edit');
    }

    /**
     * Acción Eliminar.
     */
    public static function delete(): self
    {
        return self::make('delete')
            ->label('Eliminar')
            ->icon('trash')
            ->confirm(
                '¿Deseas eliminar este registro?'
            );
    }

    /**
     * Acción Ver.
     */
    public static function view(): self
    {
        return self::make('view')
            ->label('Ver')
            ->icon('visibility');
    }

    /**
     * Etiqueta.
     */
    public function label(
        string $label
    ): self {

        $this->label = $label;

        return $this;
    }

    /**
     * Icono.
     */
    public function icon(
        string $icon
    ): self {

        $this->icon = $icon;

        return $this;
    }

    /**
     * Color.
     */
    public function color(
        string $color
    ): self {

        $this->color = $color;

        return $this;
    }

    /**
     * URL.
     */
    public function url(
        Closure $callback
    ): self {

        $this->url = $callback;

        return $this;
    }

    /**
     * Confirmación.
     */
    public function confirm(
        string $message
    ): self {

        $this->confirm = true;

        $this->confirmMessage = $message;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Getters
    |--------------------------------------------------------------------------
    */

    public function name(): string
    {
        return $this->name;
    }

    public function labelValue(): ?string
    {
        return $this->label;
    }

    public function iconValue(): ?string
    {
        return $this->icon;
    }

    public function colorValue(): ?string
    {
        return $this->color;
    }

    public function urlCallback(): ?Closure
    {
        return $this->url;
    }

    public function confirmState(): bool
    {
        return $this->confirm;
    }

    public function confirmMessage(): ?string
    {
        return $this->confirmMessage;
    }
}