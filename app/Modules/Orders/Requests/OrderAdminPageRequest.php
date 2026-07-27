<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Requests;

final class OrderAdminPageRequest
{
    public const SCREEN_LIST = 'list';
    public const SCREEN_DETAIL = 'detail';
    public const SCREEN_INVALID_DETAIL = 'invalid_detail';
    public const SCREEN_UNKNOWN_ACTION = 'unknown_action';

    private const RETURN_KEYS = [
        'return_search',
        'return_store_id',
        'return_order_status',
        'return_fulfillment_mode',
        'return_date_from',
        'return_date_to',
        'return_sort',
        'return_paged',
        'return_per_page',
    ];

    private const ORDER_STATUSES = ['reserved', 'paid', 'delivered'];
    private const FULFILLMENT_MODES = ['pickup', 'delivery'];
    private const SORTS = ['newest', 'oldest', 'updated', 'total_desc', 'total_asc'];
    private const PER_PAGE = ['20', '50', '100'];

    private string $screen;
    private ?int $orderId = null;

    /** @var array<string, string|int> */
    private array $returnQuery = [];

    /** @param list<string> $duplicateKeys */
    public function __construct(private array $query, private array $duplicateKeys = [])
    {
        $this->resolve();
    }

    public static function fromGlobals(): self
    {
        $rawQuery = $_SERVER['QUERY_STRING'] ?? '';

        return new self(
            $_GET,
            is_string($rawQuery) ? self::duplicateKeys($rawQuery) : []
        );
    }

    public function screen(): string
    {
        return $this->screen;
    }

    public function isList(): bool
    {
        return $this->screen === self::SCREEN_LIST;
    }

    public function isValidDetail(): bool
    {
        return $this->screen === self::SCREEN_DETAIL;
    }

    public function orderId(): ?int
    {
        return $this->orderId;
    }

    /** @return array<string, string|int> */
    public function returnQuery(): array
    {
        return $this->returnQuery;
    }

    public function returnUrl(): string
    {
        return add_query_arg(
            ['page' => 'veciahorra-orders'] + $this->returnQuery,
            admin_url('admin.php')
        );
    }

    private function resolve(): void
    {
        if (
            in_array('page', $this->duplicateKeys, true)
            || (
                array_key_exists('page', $this->query)
                && $this->query['page'] !== 'veciahorra-orders'
            )
            || in_array('action', $this->duplicateKeys, true)
        ) {
            $this->screen = self::SCREEN_UNKNOWN_ACTION;
            return;
        }

        if (! array_key_exists('action', $this->query)) {
            $this->screen = self::SCREEN_LIST;
            return;
        }

        if (! is_string($this->query['action']) || $this->query['action'] !== 'view') {
            $this->screen = self::SCREEN_UNKNOWN_ACTION;
            return;
        }

        $this->returnQuery = $this->validatedReturnQuery();
        if (in_array('order_id', $this->duplicateKeys, true)) {
            $this->screen = self::SCREEN_INVALID_DETAIL;
            return;
        }

        $orderId = $this->query['order_id'] ?? null;
        if (! is_string($orderId) || ! $this->isCanonicalInteger($orderId)) {
            $this->screen = self::SCREEN_INVALID_DETAIL;
            return;
        }

        $this->orderId = (int) $orderId;
        $this->screen = self::SCREEN_DETAIL;
    }

    /** @return array<string, string|int> */
    private function validatedReturnQuery(): array
    {
        $result = [];
        $search = $this->literal('return_search');
        if ($search !== null && $this->validSearch($search)) {
            $result['search'] = $search;
        }

        $storeId = $this->literal('return_store_id');
        if ($storeId !== null && $this->isCanonicalInteger($storeId)) {
            $result['store_id'] = (int) $storeId;
        }

        foreach ([
            'return_order_status' => ['order_status', self::ORDER_STATUSES],
            'return_fulfillment_mode' => ['fulfillment_mode', self::FULFILLMENT_MODES],
            'return_sort' => ['sort', self::SORTS],
            'return_per_page' => ['per_page', self::PER_PAGE],
        ] as $source => [$target, $allowed]) {
            $value = $this->literal($source);
            if ($value !== null && in_array($value, $allowed, true)) {
                $result[$target] = $target === 'per_page' ? (int) $value : $value;
            }
        }

        foreach (['return_date_from' => 'date_from', 'return_date_to' => 'date_to'] as $source => $target) {
            $value = $this->literal($source);
            if ($value !== null && $this->validDate($value)) {
                $result[$target] = $value;
            }
        }
        if (
            isset($result['date_from'], $result['date_to'])
            && $result['date_from'] > $result['date_to']
        ) {
            unset($result['date_from'], $result['date_to']);
        }

        $page = $this->literal('return_paged');
        if ($page !== null && $this->isCanonicalInteger($page)) {
            $result['paged'] = (int) $page;
        }

        return $result;
    }

    private function literal(string $key): ?string
    {
        if (
            in_array($key, $this->duplicateKeys, true)
            || ! array_key_exists($key, $this->query)
            || ! is_string($this->query[$key])
        ) {
            return null;
        }

        return wp_unslash($this->query[$key]);
    }

    private function validSearch(string $value): bool
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($value === '' || trim($value) !== $value || $length > 100) {
            return false;
        }
        if (
            preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/Di', $value) === 1
            && ! $this->isCanonicalInteger($value)
        ) {
            return false;
        }

        return ! str_starts_with(strtolower($value), 'checkout:')
            || preg_match('/^checkout:[1-9][0-9]*$/Di', $value) === 1;
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isCanonicalInteger(string $value): bool
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;
        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
    }

    /** @return list<string> */
    private static function duplicateKeys(string $rawQuery): array
    {
        $counts = array_fill_keys(
            ['page', 'action', 'order_id', ...self::RETURN_KEYS],
            0
        );
        foreach (explode('&', $rawQuery) as $pair) {
            $rawKey = explode('=', $pair, 2)[0];
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
    }
}
