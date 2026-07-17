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

    public function purchases(int $userId): array
    {
        return $this->execute(fn (): array => $this->service->listPurchases($userId));
    }

    public function purchase(int $userId, string $publicId): array
    {
        return $this->execute(fn (): array => $this->service->getPurchase($userId, $publicId));
    }

    private function execute(callable $callback): array
    {
        try {
            return ['success' => true, 'data' => $callback()];
        } catch (RecordNotFoundException $exception) {
            return $this->error('customer_order_not_found', 'La compra no está disponible.');
        } catch (InvalidArgumentException $exception) {
            return $this->error('invalid_query', $exception->getMessage());
        } catch (Throwable) {
            return $this->error('customer_panel_unavailable', 'Ocurrio un error interno.');
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
