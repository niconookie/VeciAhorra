<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;
use VeciAhorra\Database\Schemas\PaymentOrderSchema;
use VeciAhorra\Database\Schemas\PaymentSchema;

final class CreatePaymentsTables
{
    public function __construct(
        private ?string $paymentsTableName = null,
        private ?string $paymentOrdersTableName = null
    ) {
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->create(new PaymentSchema(), $this->paymentsTableName);
        $this->create(
            new PaymentOrderSchema(),
            $this->paymentOrdersTableName
        );
    }

    private function create(
        TableInterface $schema,
        ?string $tableName
    ): void {
        global $wpdb;

        $physicalName = $tableName
            ?? $wpdb->prefix . Config::TABLE_PREFIX . $schema->name();
        $builder = TableBuilder::make($physicalName);
        $schema->define($builder);

        dbDelta($builder->build($wpdb->get_charset_collate()));
    }
}
