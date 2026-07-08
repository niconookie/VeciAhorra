<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\Controller;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\CustomerPanel\Service\CustomerPanelService;

final class CustomerPanelController
{
    public function __construct(private CustomerPanelService $service)
    {
    }

    public function index(int $customerId): array
    {
        return $this->execute(
            fn (): array => $this->service->listOrders($customerId)
        );
    }

    public function show(int $customerId, int $orderId): array
    {
        return $this->execute(
            fn (): array => $this->service->getOrder($customerId, $orderId)
        );
    }

    private function execute(callable $callback): array
    {
        try {
            return ['success' => true, 'data' => $callback()];
        } catch (RecordNotFoundException $exception) {
            return $this->error('order_not_found', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage());
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
