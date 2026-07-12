<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Modules\Checkout\Service\CheckoutService;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;

final class CheckoutController
{
    public function __construct(private CheckoutService $service)
    {
    }

    public function validate(array $payload): array
    {
        return $this->execute(
            fn (): array => $this->service->validate($payload)
        );
    }

    public function initialize(array $payload): array
    {
        return $this->execute(
            fn (): array => $this->service->initialize($payload)
        );
    }

    public function show(string $publicId, array $owner): array
    {
        return $this->execute(
            fn (): array => $this->service->get($publicId, $owner)
        );
    }

    private function execute(callable $callback): array
    {
        try {
            return ['success' => true, 'data' => $callback()];
        } catch (RecordNotFoundException $exception) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => $exception->getMessage(),
                ],
            ];
        } catch (ConflictException $exception) {
            return [
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                ],
            ];
        } catch (InvalidArgumentException $exception) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ],
            ];
        } catch (Throwable) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Ocurrio un error interno.',
                ],
            ];
        }
    }
}
