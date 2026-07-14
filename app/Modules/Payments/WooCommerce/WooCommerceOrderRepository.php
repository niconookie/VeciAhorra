<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Utilities\OrderUtil;
use VeciAhorra\Modules\Payments\WooCommerce\Contracts\WooCommerceOrderRepositoryInterface;

final class WooCommerceOrderRepository implements
    WooCommerceOrderRepositoryInterface
{
    public function find(int $orderId): ?object
    {
        if (! function_exists('wc_get_order')) {
            return null;
        }

        if (
            class_exists(OrderUtil::class)
            && class_exists(OrderCache::class)
            && OrderUtil::orders_cache_usage_is_enabled()
            && function_exists('wc_get_container')
        ) {
            wc_get_container()->get(OrderCache::class)->remove($orderId);
        }

        $order = wc_get_order($orderId);

        return is_object($order) ? $order : null;
    }
}
