<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Contenedor de dependencias.
 */
final class Container
{
    /**
     * Servicios registrados.
     *
     * @var array<string, Closure>
     */
    private array $bindings = [];

    /**
     * Instancias compartidas.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Registra un servicio.
     */
    public function bind(
        string $abstract,
        Closure $factory
    ): void {

        $this->bindings[$abstract] = $factory;
    }

    /**
     * Registra un singleton.
     */
    public function singleton(
        string $abstract,
        Closure $factory
    ): void {

        $this->bindings[$abstract] = function () use (
            $abstract,
            $factory
        ) {

            if (! isset($this->instances[$abstract])) {

                $this->instances[$abstract] = $factory();
            }

            return $this->instances[$abstract];
        };
    }

    /**
     * Resuelve un servicio.
     */
    public function make(
        string $abstract
    ): object {

        if (isset($this->bindings[$abstract])) {

            return ($this->bindings[$abstract])();
        }

        return $this->build($abstract);
    }

    /**
     * Construcción automática mediante reflexión.
     */
    private function build(
        string $class
    ): object {

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw new \RuntimeException(
                "No se puede instanciar {$class}"
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {

            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            if ($type->isBuiltin()) {
                continue;
            }

            $dependencies[] = $this->make(
                $type->getName()
            );
        }

        return $reflection->newInstanceArgs(
            $dependencies
        );
    }
}