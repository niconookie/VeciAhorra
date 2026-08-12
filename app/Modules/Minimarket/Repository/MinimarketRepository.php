<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;

final class MinimarketRepository extends Repository
{
    public function findStore(int $id): ?array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare(sprintf('SELECT * FROM %s WHERE id=%%d', $this->table('stores')), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function summary(int $storeId): array
    {
        $db = $this->db();
        return [
            'active_offers' => (int) $db->get_var($db->prepare(sprintf("SELECT COUNT(*) FROM %s WHERE minimarket_id=%%d AND status='active'", $this->table('inventory')), $storeId)),
            'recent_orders' => $this->orders($storeId, 5),
        ];
    }

    public function inventories(int $storeId): array
    {
        $db = $this->db();
        $sql = sprintf("SELECT i.id inventory_id,i.product_id,i.price,i.stock,i.status,i.updated_at,
            p.name,p.sku,p.image_id,b.name brand,u.name unit
            FROM %s i JOIN %s p ON p.id=i.product_id
            LEFT JOIN %s b ON b.term_id=p.brand_id LEFT JOIN %s u ON u.term_id=p.unit_id
            WHERE i.minimarket_id=%%d ORDER BY p.name,i.id", $this->table('inventory'), $this->table('products'), $db->terms, $db->terms);
        return (array) $db->get_results($db->prepare($sql, $storeId), ARRAY_A);
    }

    public function availableProducts(int $storeId, string $search): array
    {
        $db = $this->db();
        $like = '%' . $db->esc_like($search) . '%';
        $sql = sprintf("SELECT p.id product_id,p.name,p.sku,p.image_id,b.name brand,u.name unit
            FROM %s p LEFT JOIN %s b ON b.term_id=p.brand_id LEFT JOIN %s u ON u.term_id=p.unit_id
            WHERE p.status='active' AND (p.name LIKE %%s OR p.sku LIKE %%s)
            AND NOT EXISTS(SELECT 1 FROM %s i WHERE i.product_id=p.id AND i.minimarket_id=%%d)
            ORDER BY p.name LIMIT 50", $this->table('products'), $db->terms, $db->terms, $this->table('inventory'));
        return (array) $db->get_results($db->prepare($sql, $like, $like, $storeId), ARRAY_A);
    }

    public function createInventory(int $storeId, array $data): array
    {
        $db = $this->db();
        $product = $db->get_row($db->prepare(sprintf("SELECT id FROM %s WHERE id=%%d AND status='active'", $this->table('products')), $data['product_id']), ARRAY_A);
        if (! is_array($product)) {
            throw new RecordNotFoundException('El producto maestro activo no existe.');
        }
        $exists = (int) $db->get_var($db->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE product_id=%%d AND minimarket_id=%%d', $this->table('inventory')), $data['product_id'], $storeId));
        if ($exists > 0) {
            throw new ConflictException('El producto ya pertenece a las ofertas del Store.', 'inventory_duplicate');
        }
        $now = current_time('mysql');
        $ok = $db->insert($this->table('inventory'), [
            'product_id' => $data['product_id'], 'minimarket_id' => $storeId,
            'price' => number_format((float) $data['price'], 2, '.', ''),
            'stock' => $data['stock'], 'status' => $data['status'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($ok !== 1) {
            throw new PersistenceException('No fue posible incorporar el producto.');
        }
        return $this->ownedInventory((int) $db->insert_id, $storeId);
    }

    public function updateInventory(int $inventoryId, int $storeId, array $data): array
    {
        $this->ownedInventory($inventoryId, $storeId);
        $update = $data;
        if (isset($update['price'])) $update['price'] = number_format((float) $update['price'], 2, '.', '');
        $update['updated_at'] = current_time('mysql');
        if ($this->db()->update($this->table('inventory'), $update, ['id' => $inventoryId, 'minimarket_id' => $storeId]) === false) {
            throw new PersistenceException('No fue posible actualizar la oferta.');
        }
        return $this->ownedInventory($inventoryId, $storeId);
    }

    public function ownedInventory(int $inventoryId, int $storeId): array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare(sprintf('SELECT * FROM %s WHERE id=%%d AND minimarket_id=%%d', $this->table('inventory')), $inventoryId, $storeId), ARRAY_A);
        if (! is_array($row)) throw new RecordNotFoundException('La oferta no existe para este Store.');
        return $row;
    }

    public function orders(int $storeId, int $limit = 50): array
    {
        $db = $this->db();
        $sql = sprintf("SELECT o.id order_id,o.created_at,o.total,o.status,o.store_fulfillment_status,u.display_name customer,
            c.public_id checkout_public_id,c.fulfillment_method,MAX(d.status) delivery_status
            FROM %s o LEFT JOIN %s co ON co.order_id=o.id LEFT JOIN %s c ON c.id=co.checkout_id
            LEFT JOIN %s u ON u.ID=o.customer_id LEFT JOIN %s d ON d.order_id=o.id
            WHERE o.minimarket_id=%%d GROUP BY o.id,c.id,u.ID ORDER BY o.created_at DESC,o.id DESC LIMIT %%d",
            $this->table('orders'),$this->table('checkout_orders'),$this->table('checkouts'),$db->users,$this->table('deliveries'));
        return (array) $db->get_results($db->prepare($sql, $storeId, $limit), ARRAY_A);
    }

    public function order(int $orderId, int $storeId): array
    {
        $db = $this->db();
        $sql = sprintf("SELECT o.id order_id,o.created_at,o.total,o.status,o.store_fulfillment_status,
            o.store_confirmed_at,o.store_preparation_started_at,o.store_ready_for_pickup_at,u.display_name customer,
            c.public_id checkout_public_id,c.fulfillment_method,MAX(d.status) delivery_status
            FROM %s o LEFT JOIN %s co ON co.order_id=o.id LEFT JOIN %s c ON c.id=co.checkout_id
            LEFT JOIN %s u ON u.ID=o.customer_id LEFT JOIN %s d ON d.order_id=o.id
            WHERE o.id=%%d AND o.minimarket_id=%%d GROUP BY o.id,c.id,u.ID",
            $this->table('orders'),$this->table('checkout_orders'),$this->table('checkouts'),$db->users,$this->table('deliveries'));
        $order = $db->get_row($db->prepare($sql, $orderId, $storeId), ARRAY_A);
        if (! is_array($order)) throw new RecordNotFoundException('El pedido no existe para este Store.');
        $itemsSql = sprintf('SELECT oi.product_id,oi.quantity,oi.unit_price,oi.subtotal,p.name FROM %s oi JOIN %s p ON p.id=oi.product_id WHERE oi.order_id=%%d ORDER BY oi.id', $this->table('order_items'), $this->table('products'));
        $order['items'] = (array) $db->get_results($db->prepare($itemsSql, $orderId), ARRAY_A);
        return $order;
    }

    public function transitionPreparation(int $orderId, int $storeId, string $from, string $to, string $timestampColumn, string $now): int
    {
        if (! in_array($timestampColumn, ['store_confirmed_at', 'store_preparation_started_at', 'store_ready_for_pickup_at'], true)) {
            throw new \InvalidArgumentException('Timestamp de preparación inválido.');
        }
        $sql = sprintf(
            'UPDATE %s SET store_fulfillment_status=%%s,%s=%%s,updated_at=%%s WHERE id=%%d AND minimarket_id=%%d AND status=%%s AND store_fulfillment_status=%%s',
            $this->table('orders'),
            $timestampColumn
        );
        $result = $this->db()->query($this->db()->prepare($sql, $to, $now, $now, $orderId, $storeId, 'paid', $from));
        if ($result === false) throw new PersistenceException('No fue posible actualizar la preparación del pedido.');
        return (int) $result;
    }
}
