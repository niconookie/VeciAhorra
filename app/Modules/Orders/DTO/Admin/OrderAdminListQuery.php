<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\DTO\Admin;

use InvalidArgumentException;

final readonly class OrderAdminListQuery
{
    private const ORDERS = ['newest', 'oldest', 'updated', 'total_desc', 'total_asc'];
    private const ORDER_STATUSES = ['reserved', 'paid', 'delivered'];
    private const FULFILLMENT_MODES = ['pickup', 'delivery'];

    public function __construct(
        public ?string $search = null,
        public ?int $storeId = null,
        public ?string $orderStatus = null,
        public ?string $fulfillmentMode = null,
        public ?string $createdFrom = null,
        public ?string $createdTo = null,
        public int $page = 1,
        public int $perPage = 20,
        public string $order = 'newest'
    ) {
        if ($page < 1 || ! in_array($perPage, [20, 50, 100], true)) {
            throw new InvalidArgumentException('La paginacion administrativa no es valida.');
        }
        if ($storeId !== null && $storeId < 1) {
            throw new InvalidArgumentException('store_id debe ser un entero positivo.');
        }
        if ($orderStatus !== null && ! in_array($orderStatus, self::ORDER_STATUSES, true)) {
            throw new InvalidArgumentException('order_status no pertenece al contrato.');
        }
        if ($fulfillmentMode !== null && ! in_array($fulfillmentMode, self::FULFILLMENT_MODES, true)) {
            throw new InvalidArgumentException('fulfillment_mode no pertenece al contrato.');
        }
        if (! in_array($order, self::ORDERS, true)) {
            throw new InvalidArgumentException('El orden administrativo no es valido.');
        }
        if ($search !== null) {
            $trimmed = trim($search);
            if ($trimmed === '' || $trimmed !== $search || mb_strlen($search) > 100) {
                throw new InvalidArgumentException('La busqueda administrativa no es valida.');
            }
            if (
                preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/Di', $search) === 1
                && preg_match('/^[1-9][0-9]*$/D', $search) !== 1
            ) {
                throw new InvalidArgumentException('El identificador de busqueda no es canonico.');
            }
            if (str_starts_with(strtolower($search), 'checkout:')
                && preg_match('/^checkout:[1-9][0-9]*$/Di', $search) !== 1
            ) {
                throw new InvalidArgumentException('El identificador de Checkout no es canonico.');
            }
        }
        foreach ([$createdFrom, $createdTo] as $timestamp) {
            if ($timestamp !== null && ! self::validTimestamp($timestamp)) {
                throw new InvalidArgumentException('El rango de creacion no es valido.');
            }
        }
        if ($createdFrom !== null && $createdTo !== null && $createdFrom > $createdTo) {
            throw new InvalidArgumentException('El rango de creacion esta invertido.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    private static function validTimestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }
}
