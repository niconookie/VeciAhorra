<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Payments\Requests\PaymentRequest;
use VeciAhorra\Modules\Payments\Service\PaymentService;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;

final class PaymentController
{
    public function __construct(
        private PaymentService $service,
        private PaymentSessionService $sessionService
    ) {
    }

    public function index(): array
    {
        return $this->execute(fn (): array => $this->service->list());
    }

    public function show(int $id): array
    {
        return $this->execute(function () use ($id): array {
            $payment = $this->service->find($id);

            if ($payment === null) {
                throw new RecordNotFoundException(
                    'El pago solicitado no existe.'
                );
            }

            return $payment;
        });
    }

    public function store(array $payload): array
    {
        return $this->execute(fn (): array => $this->service->create(
            (new PaymentRequest($payload))->validated()
        ));
    }

    public function createSession(int $id): array
    {
        return $this->execute(
            fn (): array => $this->sessionService->create($id)
        );
    }

    private function execute(callable $callback): array
    {
        try {
            return ['success' => true, 'data' => $callback()];
        } catch (RecordNotFoundException $exception) {
            return $this->error('payment_not_found', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage());
        } catch (PersistenceException) {
            return $this->error(
                'persistence_error',
                'No fue posible completar la operacion.'
            );
        } catch (Throwable) {
            return $this->error('internal_error', 'Ocurrio un error interno.');
        }
    }

    private function error(string $code, string $message): array
    {
        return [
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
