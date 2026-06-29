<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

/**
 * Modelo base del Framework.
 */
abstract class Model
{
    /**
     * Atributos del modelo.
     */
    protected array $attributes = [];

    /**
     * Constructor.
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Asigna atributos.
     */
    public function fill(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Obtiene un atributo.
     */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Modifica un atributo.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Comprueba si existe.
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Convierte el modelo a array.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Obtiene un atributo.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Asigna un atributo.
     */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }
}