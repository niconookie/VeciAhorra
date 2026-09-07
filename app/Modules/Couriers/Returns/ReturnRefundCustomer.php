<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Returns;
use VeciAhorra\Core\Config;
final class ReturnRefundCustomer
{
    public function forCheckout(int $checkoutId):array
    {
        global $wpdb;
        $user=get_current_user_id();
        if ($user<=0) return [];
        $p=$wpdb->prefix.Config::TABLE_PREFIX;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT r.order_id,r.status,r.product_refund,r.platform_fee_refund,r.delivery_fee_refund,r.total_refund,r.confirmed_at
            FROM {$p}return_refunds r JOIN {$p}checkouts c ON c.id=r.checkout_id JOIN {$p}orders o ON o.id=r.order_id
            WHERE c.id=%d AND c.owner_type='user' AND c.user_id=%d AND o.customer_id=%d ORDER BY r.order_id",$checkoutId,$user,$user),ARRAY_A);
        if ($wpdb->last_error!=='') throw new \RuntimeException('refund_projection_unavailable');
        return array_map(static function(array $r):array {
            $safe=(new ReturnRefundService())->safe($r);
            return [...$safe,'order_id'=>(int)$r['order_id'],'message'=>match($r['status']){
                'refunded'=>'Devolución confirmada','refund_pending'=>'Devolución en proceso',
                default=>'Devolución con problema pendiente de revisión',
            }];
        },$rows);
    }
}
