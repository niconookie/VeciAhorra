<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Operational;

final class InvariantCatalog
{
    private const DEFINITIONS = [
        'order_status_unknown' => ['error', 'commercial', true, 'Estado Order desconocido'],
        'paid_without_financial_evidence' => ['critical', 'financial', true, 'Pago sin evidencia financiera'],
        'approved_without_business_processing' => ['warning', 'processing', true, 'Aprobacion pendiente de procesamiento'],
        'business_completed_without_paid_order' => ['critical', 'processing', true, 'Procesamiento sin Order pagada'],
        'delivered_without_delivery_evidence' => ['critical', 'delivery', true, 'Order entregada sin evidencia'],
        'delivery_completed_order_not_delivered' => ['error', 'delivery', true, 'Entrega no proyectada en Order'],
        'pickup_has_delivery' => ['error', 'delivery', true, 'Pickup con Delivery'],
        'pickup_completion_invalid' => ['error', 'fulfillment', true, 'Completion de pickup invalida'],
        'delivery_integrity_mismatch' => ['critical', 'delivery', true, 'Integridad de Delivery invalida'],
        'fulfillment_completed_without_branch' => ['critical', 'fulfillment', true, 'Fulfillment sin rama valida'],
        'reservation_items_mismatch' => ['critical', 'reservations', true, 'Reservas no coinciden con lineas'],
        'active_reservation_after_terminal_release' => ['critical', 'reservations', true, 'Reserva activa despues de liberacion'],
        'reservations_active_after_payment' => ['critical', 'reservations', true, 'Reservas activas despues del pago'],
        'reservations_consumed_without_approval' => ['critical', 'reservations', true, 'Reservas consumidas sin aprobacion'],
        'reservation_terminal_mixed' => ['error', 'reservations', true, 'Reservas terminales mixtas'],
        'stock_double_terminal_evidence' => ['critical', 'reservations', true, 'Stock con evidencia terminal doble'],
        'order_item_subtotal_mismatch' => ['critical', 'commercial', true, 'Subtotal de linea inconsistente'],
        'order_total_mismatch' => ['critical', 'commercial', true, 'Total de Order inconsistente'],
        'checkout_total_mismatch' => ['critical', 'commercial', true, 'Total de Checkout inconsistente'],
        'order_store_mismatch' => ['critical', 'commercial', true, 'Store de Order inconsistente'],
        'checkout_order_relation_missing' => ['error', 'commercial', true, 'Relacion Checkout-Order ausente'],
        'checkout_order_owner_mismatch' => ['critical', 'commercial', true, 'Owner de Checkout inconsistente'],
        'operational_order_set_mismatch' => ['critical', 'commercial', true, 'Conjunto operacional de Orders inconsistente'],
        'payment_flow_mismatch' => ['critical', 'financial', true, 'Flujo financiero inconsistente'],
        'payment_amount_mismatch' => ['critical', 'financial', true, 'Monto financiero inconsistente'],
        'financial_terminal_regression' => ['critical', 'financial', true, 'Regresion financiera terminal'],
        'processing_lease_expired' => ['warning', 'processing', true, 'Lease de procesamiento vencido'],
        'processing_retry_scheduled' => ['info', 'processing', false, 'Reintento de procesamiento programado'],
        'current_catalog_reference_missing' => ['warning', 'commercial', false, 'Referencia de catalogo actual ausente'],
        'current_store_missing' => ['warning', 'commercial', true, 'Store actual ausente'],
        'read_failure' => ['error', 'read', true, 'Lectura operacional incompleta'],
    ];

    public static function codes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function create(string $code, array $evidence = [], ?string $severity = null, bool $historical = false): ConsistencyFinding
    {
        [$defaultSeverity, $dimension, $blocker, $title] = self::DEFINITIONS[$code];

        return new ConsistencyFinding(
            $code,
            $severity ?? $defaultSeverity,
            $dimension,
            $historical ? false : $blocker,
            $title,
            'La evidencia operacional no satisface el contrato ' . $code . '.',
            $evidence,
            $historical
        );
    }
}
