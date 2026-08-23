<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateDeliveriesTable;
use VeciAhorra\Database\Migrations\CreateDeliveryTrackingTable;
use VeciAhorra\Database\Migrations\CreateInventoryTable;
use VeciAhorra\Database\Migrations\CreateOrdersTables;
use VeciAhorra\Database\Migrations\CreateReservationsTable;
use VeciAhorra\Database\Migrations\CreateCartItemsTable;
use VeciAhorra\Database\Migrations\CreatePaymentsTables;
use VeciAhorra\Database\Migrations\CreateCheckoutsTable;
use VeciAhorra\Database\Migrations\CreateCheckoutOrdersTable;
use VeciAhorra\Database\Migrations\CreatePaymentSessionsTable;
use VeciAhorra\Database\Migrations\CreateWebpayReturnsTable;
use VeciAhorra\Database\Migrations\CreatePaymentConfirmationAuditsTable;
use VeciAhorra\Database\Migrations\CreatePaymentOriginContextsTable;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Database\Migrations\CreateBusinessCompletionsTable;
use VeciAhorra\Database\Migrations\CreateDeliveryCompletionsTable;
use VeciAhorra\Database\Migrations\EnsureUniqueDeliveryOrder;
use VeciAhorra\Database\Migrations\CreateFulfillmentCompletionsTable;
use VeciAhorra\Database\Migrations\EnsureUniqueFulfillmentCompletion;
use VeciAhorra\Database\Migrations\AddDurableWebpayCreateState;
use VeciAhorra\Database\Migrations\CreateDurableRetrySchedulesTable;
use VeciAhorra\Database\Migrations\AddStoreFulfillmentState;
use VeciAhorra\Database\Migrations\CreateServiceZonesTables;
use VeciAhorra\Database\Migrations\CreateZonalAdminFoundationTables;
use VeciAhorra\Database\Migrations\CreateStoreOnboardingFoundation;
use VeciAhorra\Database\Migrations\CreateStoreOnboardingEmailVerificationFoundation;
use VeciAhorra\Database\Migrations\CreateStoreOnboardingActivationSessionFoundation;
use VeciAhorra\Database\Migrations\CreateStoreOnboardingRateLimitFoundation;

/**
 * Gestiona la versión instalada de la base de datos.
 */
final class MigrationManager
{
    /**
     * Nombre de la opción en WordPress.
     */
    private const OPTION_NAME = 'veciahorra_db_version';

    /** @var null|\Closure(string, array<string, scalar|null>): void */
    private static ?\Closure $processObserver = null;

    /**
     * Instala observaciÃ³n efÃ­mera del proceso para harnesses de migraciÃ³n.
     */
    public static function observeProcess(?callable $observer): void
    {
        self::$processObserver = $observer === null
            ? null
            : \Closure::fromCallable($observer);
    }

    /** @param array<string, scalar|null> $context */
    private static function observe(string $event, array $context = []): void
    {
        if (self::$processObserver !== null) {
            (self::$processObserver)($event, $context);
        }
    }

    /**
     * Ejecuta las migraciones registradas en orden.
     */
    public static function migrate(): void
    {
        self::observe('migration_manager_enter');
        self::observe('migration_manager_run_started');

        foreach (self::migrations() as $migration) {
            $class = get_class($migration);
            self::observe('migration_dispatched', ['class' => $class]);

            try {
                $migration->up();
            } catch (\Throwable $exception) {
                self::observe('migration_failed', ['class' => $class]);
                throw $exception;
            }
        }
    }

    /**
     * Obtiene la versión instalada.
     */
    public static function installedVersion(): string
    {
        return (string) get_option(
            self::OPTION_NAME,
            '0.0.0'
        );
    }

    /**
     * Guarda la versión instalada.
     */
    public static function updateVersion(): void
    {
        update_option(
            self::OPTION_NAME,
            Config::SCHEMA_VERSION
        );
    }

    /**
     * Indica si la base de datos necesita actualización.
     */
    public static function needsMigration(): bool
    {
        return version_compare(
            self::installedVersion(),
            Config::SCHEMA_VERSION,
            '<'
        );
    }

    /**
     * @return list<
     *     CreateInventoryTable|CreateOrdersTables|CreateReservationsTable|
     *     CreateCartItemsTable|CreatePaymentsTables|CreateDeliveriesTable|
     *     CreateDeliveryTrackingTable|CreateCheckoutsTable|
     *     CreateCheckoutOrdersTable|CreatePaymentSessionsTable|
     *     CreateWebpayReturnsTable|CreatePaymentConfirmationAuditsTable|
     *     CreatePaymentOriginContextsTable|CreatePaymentReconciliationsTable
     * >
     */
    private static function migrations(): array
    {
        return [
            new CreateInventoryTable(),
            new CreateOrdersTables(),
            new CreateReservationsTable(),
            new CreateCartItemsTable(),
            new CreateCheckoutsTable(),
            new CreateCheckoutOrdersTable(),
            new CreatePaymentSessionsTable(),
            new AddDurableWebpayCreateState(),
            new CreatePaymentOriginContextsTable(),
            new CreateWebpayReturnsTable(),
            new CreatePaymentReconciliationsTable(),
            new CreateBusinessCompletionsTable(),
            new CreatePaymentsTables(),
            new CreatePaymentConfirmationAuditsTable(),
            new CreateDeliveriesTable(),
            new EnsureUniqueDeliveryOrder(),
            new CreateDeliveryCompletionsTable(),
            new CreateFulfillmentCompletionsTable(),
            new EnsureUniqueFulfillmentCompletion(),
            new CreateDeliveryTrackingTable(),
            new CreateDurableRetrySchedulesTable(),
            new AddStoreFulfillmentState(),
            new CreateServiceZonesTables(),
            new CreateZonalAdminFoundationTables(),
            new CreateStoreOnboardingFoundation(),
            new CreateStoreOnboardingEmailVerificationFoundation(),
            new CreateStoreOnboardingActivationSessionFoundation(),
            new CreateStoreOnboardingRateLimitFoundation(),
        ];
    }
}
