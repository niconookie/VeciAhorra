<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Checkout\Repository\DeliveryFlagRepository;

final class DeliveryFlagService
{
    public function __construct(private ?DeliveryFlagRepository $repository = null) {}

    /** @return array{entity:string,id:int,expected:int,enabled:int} */
    public function validate(array $input): array
    {
        if (array_keys($input) !== ['entity', 'id', 'expected', 'enabled']) {
            throw new \InvalidArgumentException('La solicitud de despacho no es canonica.');
        }
        foreach ($input as $value) {
            if (! is_string($value)) {
                throw new \InvalidArgumentException('La solicitud de despacho debe contener escalares.');
            }
        }
        $entity = $input['entity'];
        if (! in_array($entity, ['store', 'product', 'inventory'], true)
            || preg_match('/^[1-9][0-9]*$/D', $input['id']) !== 1
            || ! in_array($input['expected'], ['0', '1'], true)
            || ! in_array($input['enabled'], ['0', '1'], true)) {
            throw new \InvalidArgumentException('La solicitud de despacho no es valida.');
        }
        $id = filter_var($input['id'], FILTER_VALIDATE_INT);
        if (! is_int($id) || $id <= 0) {
            throw new \InvalidArgumentException('El ID de despacho no es valido.');
        }
        return ['entity' => $entity, 'id' => $id, 'expected' => (int) $input['expected'], 'enabled' => (int) $input['enabled']];
    }

    public function update(array $input): array
    {
        $command = $this->validate($input);
        $repository = $this->repository ?? new DeliveryFlagRepository();
        $current = $repository->find($command['entity'], $command['id']);
        if ($current === null) {
            throw new RecordNotFoundException('La entidad de despacho no existe.');
        }
        if ((int) $current['delivery_enabled'] !== $command['expected']) {
            throw new ConflictException('El estado de despacho cambio. Recarga la pagina.', 'concurrent_modification');
        }
        if ($command['expected'] !== $command['enabled']
            && $repository->compareAndSet($command['entity'], $command['id'], $command['expected'], $command['enabled']) !== 1) {
            throw new ConflictException('El estado de despacho cambio. Recarga la pagina.', 'concurrent_modification');
        }
        return [...$command, 'changed' => $command['expected'] !== $command['enabled']];
    }

    public function listing(string $entity): array
    {
        if (! in_array($entity, ['store', 'product', 'inventory'], true)) {
            throw new \InvalidArgumentException('La entidad de despacho no es valida.');
        }
        return ($this->repository ?? new DeliveryFlagRepository())->listing($entity);
    }
}
