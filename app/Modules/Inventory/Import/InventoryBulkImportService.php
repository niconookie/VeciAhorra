<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Import;

use VeciAhorra\Modules\Products\Domain\ProductLifecycleContract;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;

final class InventoryBulkImportService
{
    public function __construct(private InventoryBulkImportRepository $repository = new InventoryBulkImportRepository(), private StoreLifecycleContract $stores = new StoreLifecycleContract(), private ProductLifecycleContract $products = new ProductLifecycleContract()) {}
    public function stores(): array { return $this->repository->stores(); }
    public function preview(int $storeId, array $rows, array $parseErrors): array
    {
        $store = $this->repository->store($storeId);
        if ($store === null) throw new \InvalidArgumentException('El minimarket seleccionado no existe.');
        $storeState = $this->stores->validate((string) $store['status'], (string) $store['onboarding_status'], $store['approved_at']);
        $accepted = []; $errors = $parseErrors;
        foreach ($rows as $row) {
            $product = $this->repository->productBySku($row['sku']);
            if ($product === null) { $errors[] = ['line' => $row['line'], 'sku' => $row['sku'], 'message' => 'El SKU no existe en el catálogo maestro.']; continue; }
            try { $this->products->assertState((string) $product['status']); } catch (\Throwable) { $errors[] = ['line' => $row['line'], 'sku' => $row['sku'], 'message' => 'El producto tiene un lifecycle inválido.']; continue; }
            if ($row['status'] === 'active' && ($storeState !== StoreLifecycleContract::STATE_ACTIVE || $product['status'] !== 'active')) { $errors[] = ['line' => $row['line'], 'sku' => $row['sku'], 'message' => 'No se puede activar: Store y Product deben estar activos y elegibles.']; continue; }
            $inventory = $this->repository->inventory($storeId, (int) $product['id']);
            $change = $inventory === null ? 'create' : ($this->same($inventory, $row) ? 'unchanged' : 'update');
            $accepted[] = $row + ['product_id' => (int) $product['id'], 'change' => $change, 'snapshot' => $this->snapshot($store, $product, $inventory)];
        }
        return ['store_id' => $storeId, 'store_name' => (string) $store['business_name'], 'rows' => $accepted, 'errors' => $errors, 'created' => count(array_filter($accepted, fn ($r) => $r['change'] === 'create')), 'updated' => count(array_filter($accepted, fn ($r) => $r['change'] === 'update')), 'unchanged' => count(array_filter($accepted, fn ($r) => $r['change'] === 'unchanged'))];
    }
    public function import(array $preview): array
    {
        if (! empty($preview['errors'])) {
            throw new \InvalidArgumentException('El archivo contiene errores. Corrige todas las filas rechazadas y vuelve a cargarlo. No se aplicó ningún cambio.');
        }
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0];
        $this->repository->begin();
        try {
            $store = $this->repository->store((int) $preview['store_id'], true);
            if ($store === null) throw new \RuntimeException('El minimarket ya no existe.');
            $rows = $preview['rows'];
            usort($rows, static fn (array $left, array $right): int => (int) $left['product_id'] <=> (int) $right['product_id']);
            foreach ($rows as $row) {
                $product = $this->repository->productBySku((string) $row['sku'], true);
                if ($product === null || (int) $product['id'] !== (int) $row['product_id']) throw new \RuntimeException('El catálogo cambió después de la vista previa.');
                $inventory = $this->repository->inventory((int) $preview['store_id'], (int) $product['id'], true);
                if (! hash_equals((string) $row['snapshot'], $this->snapshot($store, $product, $inventory))) throw new \RuntimeException('Los datos cambiaron después de la vista previa. Vuelva a validar el CSV.');
                $storeState = $this->stores->validate((string) $store['status'], (string) $store['onboarding_status'], $store['approved_at']);
                $this->products->assertState((string) $product['status']);
                if ($row['status'] === 'active' && ($storeState !== StoreLifecycleContract::STATE_ACTIVE || $product['status'] !== 'active')) throw new \RuntimeException('Una fila dejó de ser elegible para publicación.');
                if ($inventory === null) { $this->repository->create((int) $preview['store_id'], (int) $product['id'], (int) $row['price'], (int) $row['stock'], (string) $row['status'], current_time('mysql')); $result['created']++; }
                elseif ($this->same($inventory, $row)) $result['unchanged']++;
                else { $this->repository->update((int) $inventory['id'], (int) $row['price'], (int) $row['stock'], (string) $row['status'], current_time('mysql')); $result['updated']++; }
            }
            $this->repository->commit(); return $result;
        } catch (\Throwable $exception) { $this->repository->rollback(); throw new \RuntimeException('La importación fue cancelada sin aplicar cambios. ' . $exception->getMessage(), 0, $exception); }
    }
    private function same(array $inventory, array $row): bool { return (int) round((float) $inventory['price']) === (int) $row['price'] && (int) $inventory['stock'] === (int) $row['stock'] && (string) $inventory['status'] === (string) $row['status']; }
    private function snapshot(array $store, array $product, ?array $inventory): string { return hash('sha256', wp_json_encode([$store['id'], $store['status'], $store['onboarding_status'], $store['approved_at'], $store['updated_at'], $product['id'], $product['sku'], $product['status'], $product['updated_at'], $inventory])); }
}
