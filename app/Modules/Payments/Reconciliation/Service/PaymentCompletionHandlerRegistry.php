<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Service;

use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionHandlerInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentCompletionHandler;

final class PaymentCompletionHandlerRegistry implements
    PaymentCompletionHandlerInterface
{
    /** @var list<PaymentCompletionHandlerInterface> */
    private readonly array $handlers;

    /** @param list<PaymentCompletionHandlerInterface>|null $handlers */
    public function __construct(?array $handlers = null)
    {
        $handlers ??= [new WooCommercePaymentCompletionHandler()];

        foreach ($handlers as $handler) {
            if (! $handler instanceof PaymentCompletionHandlerInterface) {
                throw new InvalidArgumentException(
                    'El registro contiene un handler no valido.'
                );
            }
        }

        $this->handlers = array_values($handlers);
    }

    public function supports(DurablePaymentOrigin $origin): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($origin)) {
                return true;
            }
        }

        return false;
    }

    public function complete(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult,
        TechnicalReconciliationResult $technicalResult
    ): PaymentCompletionOutcomeInterface {
        $selected = null;

        foreach ($this->handlers as $handler) {
            if (! $handler->supports($origin)) {
                continue;
            }

            if ($selected !== null) {
                throw new RuntimeException(
                    'Mas de un handler acepta el origen durable.'
                );
            }

            $selected = $handler;
        }

        if ($selected === null) {
            throw new RuntimeException(
                'No existe un handler para el origen durable.'
            );
        }

        return $selected->complete(
            $reconciliation,
            $origin,
            $financialResult,
            $technicalResult
        );
    }
}
