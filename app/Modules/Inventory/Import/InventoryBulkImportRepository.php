<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Import;

use VeciAhorra\Core\Config;

final class InventoryBulkImportRepository
{
    public function __construct(private ?\wpdb $database = null) { global $wpdb; $this->database ??= $wpdb; }
    public function stores(): array { return $this->db()->get_results('SELECT id, business_name, status, onboarding_status, approved_at, updated_at FROM ' . $this->table('stores') . ' ORDER BY business_name ASC, id ASC', ARRAY_A) ?: []; }
    public function store(int $id, bool $lock = false): ?array { $row = $this->db()->get_row($this->db()->prepare('SELECT id, business_name, status, onboarding_status, approved_at, updated_at FROM ' . $this->table('stores') . ' WHERE id = %d LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), $id), ARRAY_A); return is_array($row) ? $row : null; }
    public function productBySku(string $sku, bool $lock = false): ?array { $row = $this->db()->get_row($this->db()->prepare('SELECT id, sku, status, updated_at FROM ' . $this->table('products') . ' WHERE sku = %s LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), $sku), ARRAY_A); return is_array($row) ? $row : null; }
    public function inventory(int $storeId, int $productId, bool $lock = false): ?array { $row = $this->db()->get_row($this->db()->prepare('SELECT id, product_id, minimarket_id, price, stock, status, updated_at FROM ' . $this->table('inventory') . ' WHERE minimarket_id = %d AND product_id = %d LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), $storeId, $productId), ARRAY_A); return is_array($row) ? $row : null; }
    public function begin(): void { if ($this->db()->query('START TRANSACTION') === false) throw new \RuntimeException('No fue posible iniciar la importación.'); }
    public function commit(): void { if ($this->db()->query('COMMIT') === false) throw new \RuntimeException('No fue posible confirmar la importación.'); }
    public function rollback(): void { $this->db()->query('ROLLBACK'); }
    public function create(int $storeId, int $productId, int $price, int $stock, string $status, string $now): void { if ($this->db()->insert($this->table('inventory'), ['product_id' => $productId, 'minimarket_id' => $storeId, 'price' => $price, 'stock' => $stock, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]) === false) throw new \RuntimeException('No fue posible crear una fila de inventario.'); }
    public function update(int $id, int $price, int $stock, string $status, string $now): void { if ($this->db()->update($this->table('inventory'), ['price' => $price, 'stock' => $stock, 'status' => $status, 'updated_at' => $now], ['id' => $id]) === false) throw new \RuntimeException('No fue posible actualizar una fila de inventario.'); }
    private function db(): \wpdb { if (! $this->database instanceof \wpdb) throw new \RuntimeException('Base de datos no disponible.'); return $this->database; }
    private function table(string $name): string { return $this->db()->prefix . Config::TABLE_PREFIX . $name; }
}
