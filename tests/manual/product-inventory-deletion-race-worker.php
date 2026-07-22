<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Services\ProductService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

[$script, $mode, $productId, $storeId, $updatedAt, $readyFile] = $argv;
$repository = new ProductRepository();

try {
    $repository->transaction(function () use (
        $repository,
        $mode,
        $productId,
        $storeId,
        $updatedAt,
        $readyFile
    ): void {
        if ($repository->findByIdForUpdate((int) $productId) === null) {
            throw new RuntimeException('Product fixture no existe.');
        }

        if ($mode === 'create') {
            (new InventoryService())->create([
                'product_id' => (int) $productId,
                'minimarket_id' => (int) $storeId,
                'price' => 1000,
                'stock' => 5,
                'status' => 'active',
            ]);
        } elseif ($mode === 'delete') {
            (new ProductService())->delete(
                (int) $productId,
                $updatedAt
            );
        } else {
            throw new InvalidArgumentException('Modo de carrera invalido.');
        }

        if (file_put_contents($readyFile, 'ready') === false) {
            throw new RuntimeException('No fue posible senalizar el worker.');
        }

        usleep(1500000);
    });
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage());
    exit(1);
}
