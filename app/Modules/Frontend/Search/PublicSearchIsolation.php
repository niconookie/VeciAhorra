<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Search;

use WP_Query;
use WP_REST_Request;

/**
 * Adapts WordPress search hooks to the public search isolation policy.
 */
final class PublicSearchIsolation
{
    public const MAIN_SEARCH_PRIORITY = 1000;
    public const LIVE_SEARCH_PRIORITY = 1000;

    private bool $registered = false;

    public function __construct(
        private PublicSearchIsolationPolicy $policy,
        private ?WooCommercePublicPageResolver $wooCommercePages = null
    ) {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        add_action(
            'pre_get_posts',
            [$this, 'filterMainSearch'],
            self::MAIN_SEARCH_PRIORITY
        );
        add_filter(
            'rest_post_search_query',
            [$this, 'filterLiveSearch'],
            self::LIVE_SEARCH_PRIORITY,
            2
        );
    }

    public function filterMainSearch(WP_Query $query): void
    {
        if (! $this->policy->allowsTraditionalSearch(
            $this->traditionalContext($query)
        )) {
            return;
        }

        $postTypes = $query->get('post_type');
        if ($this->policy->isProductOnly($postTypes)) {
            return;
        }

        if ($postTypes === '' || $postTypes === 'any' || $postTypes === null) {
            $postTypes = array_values(get_post_types([
                'public' => true,
                'exclude_from_search' => false,
            ], 'names'));
        }

        $filtered = $this->policy->excludesProduct($postTypes);
        if ($filtered !== $postTypes) {
            $query->set('post_type', $filtered);
        }

        $this->excludeWooCommercePagesFromQuery($query);
    }

    /**
     * Blocksy marks only its live requests with ct_live_search=true.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filterLiveSearch(array $args, WP_REST_Request $request): array
    {
        if (! $this->policy->allowsLiveSearch([
            'route' => $request->get_route(),
            'type' => $request->get_param('type'),
            'ct_live_search' => $request->get_param('ct_live_search'),
        ])) {
            return $args;
        }

        $postTypes = $args['post_type'] ?? null;
        if ($this->policy->isProductOnly($postTypes)) {
            return $args;
        }

        $filtered = $this->policy->excludesProduct($postTypes);

        if ($filtered !== $postTypes) {
            $args['post_type'] = $filtered;
        }

        $excludedIds = $this->wooCommercePageIds();
        if ($excludedIds !== []) {
            $args['post__not_in'] = $this->policy->mergesExcludedPostIds(
                $args['post__not_in'] ?? [],
                $excludedIds
            );
        }

        return $args;
    }

    private function excludeWooCommercePagesFromQuery(WP_Query $query): void
    {
        $excludedIds = $this->wooCommercePageIds();
        if ($excludedIds === []) {
            return;
        }

        $query->set('post__not_in', $this->policy->mergesExcludedPostIds(
            $query->get('post__not_in'),
            $excludedIds
        ));
    }

    /** @return list<int> */
    private function wooCommercePageIds(): array
    {
        return ($this->wooCommercePages ??= new WooCommercePublicPageResolver())
            ->pageIds();
    }

    /** @return array<string, bool> */
    private function traditionalContext(WP_Query $query): array
    {
        return [
            'is_admin' => is_admin(),
            'is_ajax' => wp_doing_ajax(),
            'is_rest' => defined('REST_REQUEST') && REST_REQUEST,
            'is_cron' => wp_doing_cron(),
            'is_cli' => defined('WP_CLI') && WP_CLI,
            'is_action_scheduler' => doing_action('action_scheduler_run_queue'),
            'is_secondary' => ! $query->is_main_query(),
            'is_not_search' => ! $query->is_search(),
            'is_commercial_archive' => $this->isCommercialArchive($query),
        ];
    }

    private function isCommercialArchive(WP_Query $query): bool
    {
        if ($query->is_post_type_archive('product')
            || $query->is_singular('product')
            || $query->is_tax(['product_cat', 'product_tag'])
        ) {
            return true;
        }

        $taxonomy = $query->get('taxonomy');

        return is_string($taxonomy) && str_starts_with($taxonomy, 'pa_');
    }
}
