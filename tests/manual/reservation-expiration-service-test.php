<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Inventory\Services\InventoryLockService;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationExpirationService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertExpiration(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertExpirationSame(mixed $expected, mixed $actual): void
{
    assertExpiration(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

global $wpdb;

$inventoryRepository = new InventoryRepository();
$reservationRepository = new ReservationRepository();
$lockService = new InventoryLockService();
$expirationService = new ReservationExpirationService();
$inventoryTable = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$reservationsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'reservations';
$preexistingExpired = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$reservationsTable}"
    . ' WHERE status = %s AND expires_at <= %s',
    'active',
    current_time('mysql')
));
$transaction = $wpdb->query('START TRANSACTION');
assertExpiration($transaction !== false, 'No se inicio la transaccion.');

try {
    $now = current_datetime();
    $nowSql = $now->format('Y-m-d H:i:s');
    $inventoryId = $inventoryRepository->create([
        'product_id' => random_int(32000000, 32999999),
        'minimarket_id' => random_int(33000000, 33999999),
        'price' => 1000.0,
        'stock' => 20,
        'status' => 'active',
        'created_at' => $nowSql,
        'updated_at' => $nowSql,
    ]);
    $stock = static fn (): int => (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT stock FROM {$inventoryTable} WHERE id = %d",
            $inventoryId
        )
    );
    $createReservation = static function (
        string $status,
        int $quantity,
        string $expiresAt
    ) use (
        $reservationRepository,
        $inventoryId,
        $nowSql
    ): int {
        return $reservationRepository->create([
            'order_id' => random_int(34000000, 34999999),
            'inventory_id' => $inventoryId,
            'product_id' => random_int(35000000, 35999999),
            'minimarket_id' => random_int(36000000, 36999999),
            'quantity' => $quantity,
            'status' => $status,
            'reserved_at' => $nowSql,
            'expires_at' => $expiresAt,
            'released_at' => null,
            'created_at' => $nowSql,
            'updated_at' => $nowSql,
        ]);
    };

    $currentId = $createReservation(
        'active',
        2,
        $now->modify('+10 minutes')->format('Y-m-d H:i:s')
    );
    $expiredId = $createReservation(
        'active',
        3,
        $now->modify('-10 minutes')->format('Y-m-d H:i:s')
    );
    $consumedId = $createReservation(
        'consumed',
        4,
        $now->modify('-20 minutes')->format('Y-m-d H:i:s')
    );
    $secondExpiredId = $createReservation(
        'active',
        2,
        $now->modify('-1 minute')->format('Y-m-d H:i:s')
    );

    foreach ([2, 3, 4, 2] as $quantity) {
        assertExpiration(
            $lockService->lockStock($inventoryId, $quantity),
            'No fue posible preparar el stock bloqueado.'
        );
    }
    assertExpirationSame(9, $stock());

    assertExpirationSame(
        $preexistingExpired + 2,
        $expirationService->processExpiredReservations()
    );
    assertExpirationSame(14, $stock());

    $current = $reservationRepository->find($currentId);
    $expired = $reservationRepository->find($expiredId);
    $consumed = $reservationRepository->find($consumedId);
    $secondExpired = $reservationRepository->find($secondExpiredId);

    assertExpirationSame('active', $current['status']);
    assertExpirationSame(null, $current['released_at']);
    assertExpirationSame('expired', $expired['status']);
    assertExpiration(
        is_string($expired['released_at'])
        && $expired['released_at'] !== '',
        'released_at no fue registrado.'
    );
    assertExpirationSame('consumed', $consumed['status']);
    assertExpirationSame(null, $consumed['released_at']);
    assertExpirationSame('expired', $secondExpired['status']);

    assertExpirationSame(
        0,
        $expirationService->processExpiredReservations()
    );
    assertExpirationSame(14, $stock());

    echo "PASS reservation-expiration-service-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
