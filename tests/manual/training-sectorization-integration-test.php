<?php
declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Cart\Repository\CartRepository;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Checkout\Service\CheckoutValidationService;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Sectorization\CurrentSector;
use VeciAhorra\Modules\Sectorization\ServiceZoneRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function sectorAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$zones = new ServiceZoneRepository();
$byName = [];
foreach ($zones->active() as $zone) $byName[(string) $zone['name']] = (int) $zone['id'];
sectorAssert(count($byName) === 2 && isset($byName['República'], $byName['Centro Sur']), 'Sectores activos inesperados.');

$storeIds = [];
foreach ($wpdb->get_results("SELECT id,business_name FROM {$prefix}stores", ARRAY_A) as $store) {
    $storeIds[(string) $store['business_name']] = (int) $store['id'];
}
$republica = $zones->allowedStoreIds($byName['República']); sort($republica);
$centro = $zones->allowedStoreIds($byName['Centro Sur']); sort($centro);
$expectedRepublica = [$storeIds['Minimarket Los Vecinos'], $storeIds['Minimarket Central']]; sort($expectedRepublica);
$expectedCentro = [$storeIds['Minimarket Central'], $storeIds['Minimarket Plaza Sur']]; sort($expectedCentro);
sectorAssert($republica === $expectedRepublica && $centro === $expectedCentro, 'Asignaciones sectoriales inesperadas.');

$user = get_user_by('login', 'va_demo_carolina');
sectorAssert($user instanceof WP_User, 'Usuario demo ausente.');
sectorAssert($wpdb->query('START TRANSACTION') !== false, 'No fue posible iniciar la transacción.');
try {
    wp_set_current_user((int) $user->ID);
    update_user_meta((int) $user->ID, '_veciahorra_service_zone_id', $byName['Centro Sur']);
    sectorAssert((new CurrentSector())->id() === $byName['Centro Sur'], 'Persistencia autenticada inválida.');

    $cartTable = $prefix . 'cart_items';
    $wpdb->delete($cartTable, ['user_id' => (int) $user->ID]);
    $inventory = $wpdb->get_results($wpdb->prepare(
        "SELECT i.*,p.name FROM {$prefix}inventory i JOIN {$prefix}products p ON p.id=i.product_id WHERE (p.name=%s AND i.minimarket_id=%d) OR (p.name=%s AND i.minimarket_id=%d) ORDER BY p.name",
        'Papas fritas Marco Polo', $storeIds['Minimarket Los Vecinos'],
        'Yogurt Soprole', $storeIds['Minimarket Central']
    ), ARRAY_A);
    sectorAssert(count($inventory) === 2, 'Inventario sectorial de prueba incompleto.');

    $validate = new CheckoutValidationService(
        new CartService(new CartRepository()),
        new InventoryService(),
        new ProductService(),
        new StoreRepository()
    );
    $measure = static function (array $rows) use ($wpdb, $cartTable, $user, $validate): array {
        $wpdb->delete($cartTable, ['user_id' => (int) $user->ID]);
        foreach ($rows as $row) $wpdb->insert($cartTable, [
            'session_id' => null, 'user_id' => (int) $user->ID, 'inventory_id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'], 'minimarket_id' => (int) $row['minimarket_id'],
            'quantity' => 1, 'unit_price_snapshot' => $row['price'],
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ]);
        $count = 0;
        $filter = static function (string $query) use (&$count): string {
            if (str_contains($query, 'service_zones')) $count++;
            return $query;
        };
        add_filter('query', $filter);
        try { $result = $validate->validate(['session_id' => null, 'user_id' => (int) $user->ID]); }
        finally { remove_filter('query', $filter); }
        return [$result, $count];
    };

    [$oneItem, $oneQueries] = $measure([$inventory[0]]);
    [$twoItems, $twoQueries] = $measure($inventory);
    $codes = array_column($twoItems['errors'], 'code');
    sectorAssert(in_array('inventory_out_of_sector', $codes, true), 'Checkout no bloqueó el ítem fuera de sector.');
    sectorAssert($oneQueries === $twoQueries && $twoQueries <= 4, "N+1 sectorial detectado: {$oneQueries}/{$twoQueries}");
    sectorAssert($twoItems['summary']['item_count'] === 2, 'Checkout no evaluó ambos ítems.');
    $wpdb->query('ROLLBACK');
} catch (Throwable $exception) {
    $wpdb->query('ROLLBACK');
    throw $exception;
}

echo "TRAINING_SECTORIZATION_BACKEND=PASS\nSECTOR_QUERY_COUNT_1={$oneQueries}\nSECTOR_QUERY_COUNT_2={$twoQueries}\nN_PLUS_ONE=no\n";
