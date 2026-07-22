<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Requests;

/**
 * Resuelve la pantalla administrativa Store y su retorno al listado.
 */
final class StoreAdminPageRequest
{
    public const SCREEN_LIST = 'list';
    public const SCREEN_DETAIL = 'detail';
    public const SCREEN_INVALID_DETAIL = 'invalid_detail';
    public const SCREEN_UNKNOWN_ACTION = 'unknown_action';

    private const LIFECYCLE_STATES = [
        'draft',
        'in_review',
        'rejected',
        'approved_inactive',
        'active',
        'invalid',
    ];

    private const STATUSES = [
        'pending',
        'active',
        'inactive',
        'rejected',
    ];

    /** Valores técnicos de assets/admin/js/modules/stores/app.js. */
    private const SORTS = [
        'name_asc',
        'newest',
        'oldest',
        'updated',
    ];

    private const MAXIMUM_SEARCH_LENGTH = 100;
    private const MAXIMUM_PAGE = 1000000;

    private string $screen;
    private ?int $storeId = null;

    /** @var array<string, string|int> */
    private array $returnQuery = [];

    /** @param list<string> $duplicateRouteKeys */
    public function __construct(private array $query, private array $duplicateRouteKeys = [])
    {
        $this->resolve();
    }

    public static function fromGlobals(): self
    {
        $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
        return new self(
            $_GET,
            is_string($rawQuery) ? self::duplicateRouteKeys($rawQuery) : []
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

    public function storeId(): ?int
    {
        return $this->storeId;
    }

    /** @return array<string, string|int> */
    public function returnQuery(): array
    {
        return $this->returnQuery;
    }

    public function returnUrl(): string
    {
        return add_query_arg(
            ['page' => 'veciahorra-stores'] + $this->returnQuery,
            admin_url('admin.php')
        );
    }

    private function resolve(): void
    {
        if (in_array('action', $this->duplicateRouteKeys, true)) {
            $this->screen = self::SCREEN_UNKNOWN_ACTION;
            return;
        }

        if (! array_key_exists('action', $this->query)) {
            $this->screen = self::SCREEN_LIST;
            return;
        }

        $action = $this->query['action'];
        if (! is_string($action) || $action !== 'view') {
            $this->screen = self::SCREEN_UNKNOWN_ACTION;
            return;
        }

        $this->returnQuery = $this->validatedReturnQuery();
        if (in_array('id', $this->duplicateRouteKeys, true)) {
            $this->screen = self::SCREEN_INVALID_DETAIL;
            return;
        }
        $id = $this->query['id'] ?? null;
        if (! is_string($id) || ! $this->isCanonicalInteger($id, PHP_INT_MAX)) {
            $this->screen = self::SCREEN_INVALID_DETAIL;
            return;
        }

        $this->storeId = (int) $id;
        $this->screen = self::SCREEN_DETAIL;
    }

    /** @return array<string, string|int> */
    private function validatedReturnQuery(): array
    {
        $result = [];
        $search = $this->searchText('return_search');
        if ($search !== null) {
            $length = function_exists('mb_strlen') ? mb_strlen($search) : strlen($search);
            if ($length <= self::MAXIMUM_SEARCH_LENGTH) {
                $result['search'] = $search;
            }
        }

        foreach ([
            'return_lifecycle_state' => ['lifecycle_state', self::LIFECYCLE_STATES],
            'return_status' => ['status', self::STATUSES],
            'return_sort' => ['sort', self::SORTS],
        ] as $source => [$target, $allowed]) {
            $value = $this->literal($source);
            if ($value !== null && in_array($value, $allowed, true)) {
                $result[$target] = $value;
            }
        }

        $page = $this->query['return_paged'] ?? null;
        if (is_string($page) && $this->isCanonicalInteger($page, self::MAXIMUM_PAGE)) {
            $result['paged'] = (int) $page;
        }

        return $result;
    }

    private function searchText(string $key): ?string
    {
        if (! array_key_exists($key, $this->query) || ! is_string($this->query[$key])) {
            return null;
        }

        return trim(sanitize_text_field(wp_unslash($this->query[$key])));
    }

    private function literal(string $key): ?string
    {
        if (! array_key_exists($key, $this->query) || ! is_string($this->query[$key])) {
            return null;
        }

        return wp_unslash($this->query[$key]);
    }

    private function isCanonicalInteger(string $value, int $maximum): bool
    {
        if (preg_match('/^[1-9]\d*$/D', $value) !== 1) {
            return false;
        }

        $limit = (string) $maximum;
        return strlen($value) < strlen($limit)
            || (strlen($value) === strlen($limit) && strcmp($value, $limit) <= 0);
    }

    /** @return list<string> */
    private static function duplicateRouteKeys(string $rawQuery): array
    {
        $counts = ['action' => 0, 'id' => 0];
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
