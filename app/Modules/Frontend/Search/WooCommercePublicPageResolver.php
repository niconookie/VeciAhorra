<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Search;

use Closure;
use WP_Post;

/**
 * Resolves the WordPress pages officially assigned to WooCommerce.
 */
final class WooCommercePublicPageResolver
{
    private const PAGE_TYPES = ['shop', 'cart', 'checkout', 'myaccount'];

    /** @var list<int>|null */
    private ?array $resolved = null;

    /** @var Closure(string): mixed */
    private Closure $pageIdProvider;

    /** @var Closure(int): mixed */
    private Closure $postProvider;

    public function __construct(
        ?callable $pageIdProvider = null,
        ?callable $postProvider = null
    ) {
        $this->pageIdProvider = Closure::fromCallable(
            $pageIdProvider ?? $this->officialPageId(...)
        );
        $this->postProvider = Closure::fromCallable(
            $postProvider ?? static fn (int $id): mixed => get_post($id)
        );
    }

    /** @return list<int> */
    public function pageIds(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $ids = [];
        foreach (self::PAGE_TYPES as $pageType) {
            $id = $this->positiveId(($this->pageIdProvider)($pageType));
            if ($id < 1 || isset($ids[$id])) {
                continue;
            }

            $post = ($this->postProvider)($id);
            if (! $post instanceof WP_Post || $post->post_type !== 'page') {
                continue;
            }

            $ids[$id] = $id;
        }

        return $this->resolved = array_values($ids);
    }

    private function officialPageId(string $pageType): mixed
    {
        if (function_exists('wc_get_page_id')) {
            return wc_get_page_id($pageType);
        }

        return get_option('woocommerce_' . $pageType . '_page_id', 0);
    }

    private function positiveId(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        return is_string($value) && ctype_digit($value)
            ? (int) $value
            : 0;
    }
}
