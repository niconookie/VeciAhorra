<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Search;

/**
 * Decides when a public search may exclude WooCommerce products.
 */
final class PublicSearchIsolationPolicy
{
    /** @param array<string, bool> $context */
    public function allowsTraditionalSearch(array $context): bool
    {
        foreach (
            [
                'is_admin',
                'is_ajax',
                'is_rest',
                'is_cron',
                'is_cli',
                'is_action_scheduler',
                'is_secondary',
                'is_not_search',
                'is_commercial_archive',
            ] as $negativeCondition
        ) {
            if ($context[$negativeCondition] ?? false) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $context */
    public function allowsLiveSearch(array $context): bool
    {
        return ($context['route'] ?? '') === '/wp/v2/search'
            && ($context['type'] ?? '') === 'post'
            && ($context['ct_live_search'] ?? null) === 'true';
    }

    public function excludesProduct(mixed $postTypes): mixed
    {
        if (! is_array($postTypes)) {
            return $postTypes;
        }

        $productCount = 0;
        $otherCount = 0;

        foreach ($postTypes as $postType) {
            if (! is_string($postType) || $postType === '') {
                return $postTypes;
            }

            if ($postType === 'product') {
                $productCount++;
            } else {
                $otherCount++;
            }
        }

        if ($productCount === 0 || $otherCount === 0) {
            return $postTypes;
        }

        return array_values(array_filter(
            $postTypes,
            static fn (mixed $postType): bool => $postType !== 'product'
        ));
    }

    public function isProductOnly(mixed $postTypes): bool
    {
        if ($postTypes === 'product') {
            return true;
        }

        return is_array($postTypes)
            && $postTypes !== []
            && count(array_filter(
                $postTypes,
                static fn (mixed $postType): bool => $postType === 'product'
            )) === count($postTypes);
    }

    /**
     * @param list<int> $additionalIds
     * @return list<int>
     */
    public function mergesExcludedPostIds(
        mixed $existingIds,
        array $additionalIds
    ): array {
        $values = is_array($existingIds)
            ? $existingIds
            : [$existingIds];
        $merged = [];

        foreach (array_merge($values, $additionalIds) as $value) {
            $id = is_int($value) && $value > 0
                ? $value
                : (is_string($value) && ctype_digit($value)
                    ? (int) $value
                    : 0);
            if ($id > 0 && ! isset($merged[$id])) {
                $merged[$id] = $id;
            }
        }

        return array_values($merged);
    }
}
