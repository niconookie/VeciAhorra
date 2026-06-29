<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Colección de modelos.
 */
final class Collection implements IteratorAggregate, Countable
{
    /**
     * Elementos de la colección.
     *
     * @var array<int, mixed>
     */
    private array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Agrega un elemento.
     */
    public function add(mixed $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Devuelve todos los elementos.
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Primer elemento.
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * Último elemento.
     */
    public function last(): mixed
    {
        if (empty($this->items)) {
            return null;
        }

        return $this->items[array_key_last($this->items)];
    }

    /**
     * Cantidad de elementos.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Convierte la colección a array.
     */
    public function toArray(): array
    {
        return array_map(
            static function ($item) {

                if ($item instanceof Model) {
                    return $item->toArray();
                }

                return $item;
            },
            $this->items
        );
    }

    /**
     * Permite recorrer la colección.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}