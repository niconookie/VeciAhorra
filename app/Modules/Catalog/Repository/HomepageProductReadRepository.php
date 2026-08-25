<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog\Repository;

use VeciAhorra\Core\Config;

final class HomepageProductReadRepository
{
    private const LIMIT = 6;

    /** @return list<array<string, mixed>> */
    public function latestForSector(int $sectorId): array
    {
        if ($sectorId <= 0) {
            return [];
        }

        global $wpdb;
        $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
        $sql = $wpdb->prepare(
            "SELECT p.id, p.name, p.slug, p.description, p.image_id,
                    p.category_id, category.name category_name,
                    p.brand_id, brand.name brand_name,
                    p.unit_id, unit.name unit_name,
                    MIN(i.price) min_price,
                    COUNT(DISTINCT i.minimarket_id) available_minimarkets
             FROM {$prefix}products p
             INNER JOIN {$prefix}inventory i
                ON i.product_id=p.id AND i.id>0 AND i.status='active'
                AND i.stock>0 AND i.price>0
             INNER JOIN {$prefix}stores s
                ON s.id=i.minimarket_id AND s.id>0 AND s.status='active'
                AND s.onboarding_status='complete' AND s.approved_at IS NOT NULL
             INNER JOIN {$prefix}store_service_zones sz
                ON sz.store_id=s.id AND sz.zone_id=%d
             INNER JOIN {$prefix}service_zones z
                ON z.id=sz.zone_id AND z.status='active'
             LEFT JOIN {$wpdb->terms} category ON category.term_id=p.category_id
             LEFT JOIN {$wpdb->terms} brand ON brand.term_id=p.brand_id
             LEFT JOIN {$wpdb->terms} unit ON unit.term_id=p.unit_id
             WHERE p.id>0 AND p.status='active'
             GROUP BY p.id, p.name, p.slug, p.description, p.image_id,
                      p.category_id, category.name, p.brand_id, brand.name,
                      p.unit_id, unit.name, p.created_at
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT %d",
            $sectorId,
            self::LIMIT
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $imageIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['image_id'] ?? 0),
            $rows
        ))));
        if ($imageIds !== []) {
            _prime_post_caches($imageIds, false, true);
            update_meta_cache('post', $imageIds);
        }

        return array_map([$this, 'summary'], $rows);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function summary(array $row): array
    {
        $imageId = (int) ($row['image_id'] ?? 0);
        $image = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'medium') : false;
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'short_description' => wp_trim_words(wp_strip_all_tags((string) ($row['description'] ?? '')), 30, '…'),
            'image' => is_string($image) ? $image : null,
            'category' => $this->catalogItem($row, 'category'),
            'brand' => $this->catalogItem($row, 'brand'),
            'unit' => $this->catalogItem($row, 'unit'),
            'min_price' => number_format((float) $row['min_price'], 2, '.', ''),
            'available_minimarkets' => (int) $row['available_minimarkets'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function catalogItem(array $row, string $key): ?array
    {
        $id = (int) ($row[$key . '_id'] ?? 0);
        $name = trim((string) ($row[$key . '_name'] ?? ''));
        return $id > 0 && $name !== '' ? ['id' => $id, 'name' => $name] : null;
    }
}
