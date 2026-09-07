<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers\Returns;

use DomainException;
use Throwable;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Checkout\Service\CheckoutFeeCalculator;
use VeciAhorra\Modules\Checkout\Service\CheckoutRefundPolicy;
use VeciAhorra\Modules\Couriers\Repository\CourierDeliveryRepository;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\RefundGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\RefundResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;

/** One checkout lock serializes financial decisions across its Orders. No remote call in SQL transactions. */
final class ReturnRefundService
{
    public function __construct(private ?RefundGatewayInterface $gateway = null) {}

    public function cancelAndRefund(int $deliveryId, ?int $version, string $key, string $note, bool $confirmed, int $retryOf = 0): array
    {
        global $wpdb;
        if (!current_user_can('manage_options') || get_current_user_id() <= 0) throw new DomainException('refund_admin_required');
        if ($version === null || $version < 0) throw new DomainException('expected_version_required');
        if (!$confirmed) throw new DomainException('refund_confirmation_required');
        $note = trim($note);
        if (preg_match('/^.{1,500}$/usD', $note)!==1 || preg_match('/[\x00-\x1f\x7f]/', $note)) throw new DomainException('refund_note_required_max_500');
        if ($deliveryId <= 0 || $retryOf < 0 || preg_match('/^[A-Za-z0-9:_-]{16,128}$/D', $key)!==1) throw new DomainException('refund_request_invalid');
        if ((int)$wpdb->get_var('SELECT @@in_transaction') !== 0) throw new DomainException('refund_requires_own_transaction');
        $fingerprint = hash('sha256', json_encode([$deliveryId,$version,$note,$retryOf], JSON_THROW_ON_ERROR));
        $p=$this->prefix();
        $links=$this->rows("SELECT co.checkout_id FROM {$p}checkout_orders co JOIN {$p}deliveries d ON d.order_id=co.order_id WHERE d.id=%d",$deliveryId);
        if (count($links)!==1) throw new DomainException('refund_identity_conflict');
        $checkoutId=(int)$links[0]['checkout_id'];
        $prepared=(new CheckoutRepository())->transaction(function() use ($p,$checkoutId,$deliveryId,$version,$key,$note,$confirmed,$retryOf,$fingerprint): array {
            $checkout=$this->one("SELECT * FROM {$p}checkouts WHERE id=%d FOR UPDATE",$checkoutId);
            $existing=$this->rows("SELECT * FROM {$p}return_refund_attempts WHERE idempotency_key=%s",$key);
            if ($existing!==[]) {
                $intent=$existing[0];
                if (!hash_equals((string)$intent['fingerprint'],$fingerprint) || (int)$intent['delivery_id']!==$deliveryId) throw new DomainException('refund_idempotency_conflict');
                $refund=$this->one("SELECT * FROM {$p}return_refunds WHERE delivery_id=%d FOR UPDATE",$deliveryId);
                $terminal=$this->rows("SELECT * FROM {$p}return_refund_attempts WHERE delivery_id=%d AND attempt=%d AND event IN ('refunded','refund_failed','refund_uncertain')",$deliveryId,$intent['attempt']);
                if ($terminal!==[]) return ['response'=>$this->safe([...$intent,'status'=>$terminal[0]['event'],'confirmed_at'=>$terminal[0]['event']==='refunded'?$terminal[0]['created_at']:null])];
                if ($refund['status']==='refund_pending' && $refund['lease_expires_at']<=$this->now()) {
                    $status=$refund['remote_started_at']===null?'refund_failed':'refund_uncertain';
                    $this->terminal($refund,$status);
                    $refund['status']=$status;
                }
                return ['response'=>$this->safe($refund)];
            }
            $busy=$this->rows("SELECT delivery_id FROM {$p}return_refunds WHERE checkout_id=%d AND status IN ('refund_pending','refund_uncertain')",$checkoutId);
            if ($busy!==[]) throw new DomainException('refund_checkout_requires_review');
            $delivery=$this->one("SELECT * FROM {$p}deliveries WHERE id=%d FOR UPDATE",$deliveryId);
            $order=$this->one("SELECT * FROM {$p}orders WHERE id=%d FOR UPDATE",$delivery['order_id']);
            $receipt=$this->one("SELECT * FROM {$p}delivery_returns WHERE delivery_id=%d FOR UPDATE",$deliveryId);
            if ($delivery['status']!=='returned_to_store' || (int)$delivery['transition_version']!==$version || $order['status']!=='incident_review') throw new DomainException('refund_state_conflict');
            if (!$receipt['received_at'] || !(int)$receipt['received_by'] || (int)$receipt['order_id']!==(int)$order['id']
                || (int)$receipt['store_id']!==(int)$delivery['minimarket_id'] || (int)$receipt['courier_id']!==(int)$delivery['courier_id']
                || (int)$receipt['received_version']+1!==$version) throw new DomainException('refund_receipt_required');
            if ($checkout['fulfillment_method']!=='delivery' || $checkout['owner_type']!=='user'
                || (int)$checkout['user_id']!==(int)$order['customer_id'] || (int)$delivery['customer_id']!==(int)$order['customer_id']
                || (int)$order['minimarket_id']!==(int)$delivery['minimarket_id']) throw new DomainException('refund_identity_conflict');
            $old=$this->rows("SELECT * FROM {$p}return_refunds WHERE delivery_id=%d FOR UPDATE",$deliveryId);
            if (($old===[] && $retryOf!==0) || ($old!==[] && ($old[0]['status']!=='refund_failed' || (int)$old[0]['attempt']!==$retryOf))) throw new DomainException('refund_explicit_retry_required');
            $payments=$this->rows("SELECT * FROM {$p}payments WHERE checkout_id=%d AND status='paid'",$checkoutId);
            if (count($payments)!==1) throw new DomainException('refund_payment_ambiguous');
            $payment=$payments[0];
            $sessions=$this->rows("SELECT * FROM {$p}payment_sessions WHERE checkout_id=%d AND payment_id=%d AND id=%d",$checkoutId,$payment['id'],$payment['payment_session_id']);
            if (count($sessions)!==1) throw new DomainException('refund_session_ambiguous');
            $session=$sessions[0];
            $total=CheckoutFeeCalculator::clp((string)$checkout['total_amount']);
            if ($payment['currency']!=='CLP' || $checkout['currency']!=='CLP' || $session['currency']!=='CLP'
                || CheckoutFeeCalculator::clp((string)$payment['amount'])!==$total || CheckoutFeeCalculator::clp((string)$session['amount'])!==$total
                || (int)$payment['customer_id']!==(int)$checkout['user_id'] || $payment['provider']!=='webpay_plus'
                || $session['provider']!=='webpay_plus' || $session['status']!=='confirmed'
                || preg_match('/^[A-Za-z0-9]{16,191}$/D',(string)$session['provider_session_id'])!==1) throw new DomainException('refund_payment_conflict');
            $origins=$this->rows("SELECT * FROM {$p}payment_origin_contexts WHERE payment_attempt_id=%s",$session['public_id']);
            if (count($origins)!==1) throw new DomainException('refund_origin_ambiguous');
            $origin=$origins[0];
            if ($origin['origin']!=='veciahorra_checkout' || $origin['origin_resource_id']!==$checkout['public_id']
                || $origin['site_scope']!==WordPressSiteScope::current() || $origin['gateway_id']!=='webpay_plus'
                || $origin['currency']!=='CLP' || (int)$origin['amount_clp']!==$total
                || !hash_equals((string)$origin['token_hash'],hash('sha256',$session['provider_session_id']))) throw new DomainException('refund_origin_conflict');
            $gateway=$this->gateway;
            if ($gateway===null) {
                try {
                    $configuration=PaymentGatewayConfiguration::webpay();
                    if ($configuration->environment!==$origin['environment'] || !hash_equals($origin['merchant_identity_hash'],hash('sha256',$configuration->commerceCode))) throw new DomainException('refund_configuration_conflict');
                    $gateway=new WebpayPaymentGateway($configuration);
                } catch (Throwable) { throw new DomainException('refund_configuration_unavailable'); }
            }
            $orders=$this->rows("SELECT o.* FROM {$p}orders o JOIN {$p}checkout_orders co ON co.order_id=o.id WHERE co.checkout_id=%d ORDER BY o.id",$checkoutId);
            $paymentOrders=$this->rows("SELECT order_id FROM {$p}payment_orders WHERE payment_id=%d ORDER BY order_id",$payment['id']);
            if (array_map('intval',array_column($orders,'id'))!==array_map('intval',array_column($paymentOrders,'order_id'))) throw new DomainException('refund_payment_orders_conflict');
            $sum=0;
            foreach ($orders as $linked) {
                if ((int)$linked['customer_id']!==(int)$checkout['user_id']) throw new DomainException('refund_identity_conflict');
                $sum+=CheckoutFeeCalculator::clp((string)$linked['total']);
                if ($sum>$total) throw new DomainException('refund_frozen_products_conflict');
            }
            if ($checkout['product_subtotal']===null || $sum!==CheckoutFeeCalculator::clp((string)$checkout['product_subtotal'])) throw new DomainException('refund_frozen_products_conflict');
            // Legacy accounting records do not prove which Order or remote refund they represent.
            // Refuse that Checkout; never infer an allocation or repeat its products remotely.
            $ledger=$this->rows("SELECT * FROM {$p}checkout_refunds WHERE checkout_id=%d",$checkoutId);
            $totals=[0,0,0,0];
            foreach ($ledger as $entry) {
                $known=$this->rows("SELECT r.* FROM {$p}return_refunds r WHERE r.checkout_id=%d AND r.status='refunded' AND CONCAT('return:',r.delivery_id,':',r.attempt)=%s",$checkoutId,$entry['idempotency_key']);
                if (count($known)!==1 || $entry['status']!=='refunded') throw new DomainException('refund_legacy_accounting_review');
                foreach (['product_refund','platform_fee_refund','delivery_fee_refund','total_refund'] as $i=>$component) {
                    if (CheckoutFeeCalculator::clp((string)$entry[$component])!==(int)$known[0][$component]) throw new DomainException('refund_ledger_conflict');
                    $totals[$i]+=CheckoutFeeCalculator::clp((string)$entry[$component]);
                    if ($totals[$i]>$total) throw new DomainException('refund_accumulated_limit');
                }
            }
            $decision=(new CheckoutRefundPolicy())->calculate($sum,CheckoutFeeCalculator::clp((string)$checkout['platform_fee']),CheckoutFeeCalculator::clp((string)$checkout['delivery_fee']),$totals[0],$totals[1],$totals[2],CheckoutFeeCalculator::clp((string)$order['total']),$total,$totals[3]);
            $now=$this->now();
            $refund=[...$decision,'delivery_id'=>$deliveryId,'order_id'=>(int)$order['id'],'checkout_id'=>$checkoutId,'payment_id'=>(int)$payment['id'],
                'payment_session_id'=>(int)$session['id'],'status'=>'refund_pending','expected_version'=>$version,'attempt'=>$retryOf+1,'actor_id'=>get_current_user_id(),'note'=>$note,
                'owner'=>bin2hex(random_bytes(32)),'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+120),'remote_started_at'=>null,'confirmed_at'=>null,'updated_at'=>$now];
            global $wpdb;
            if ($old===[]) $this->write($wpdb->insert($p.'return_refunds',[...$refund,'created_at'=>$now]));
            else $this->write($wpdb->update($p.'return_refunds',$refund,['delivery_id'=>$deliveryId,'attempt'=>$retryOf,'status'=>'refund_failed']));
            $this->event($refund,'refund_pending',$key,$fingerprint);
            return compact('refund','gateway')+['token'=>$session['provider_session_id'],'paid_total'=>$total];
        });
        if (isset($prepared['response'])) return $prepared['response'];
        $refund=$prepared['refund'];
        // A committed remote-start marker prevents a crash from authorizing a second call.
        $claimed=(new CheckoutRepository())->transaction(function()use($p,$refund):bool {
            global $wpdb;
            $this->one("SELECT id FROM {$p}checkouts WHERE id=%d FOR UPDATE",$refund['checkout_id']);
            $result=$wpdb->query($wpdb->prepare("UPDATE {$p}return_refunds SET remote_started_at=%s WHERE delivery_id=%d AND attempt=%d AND owner=%s AND status='refund_pending' AND remote_started_at IS NULL AND lease_expires_at>=%s",$this->now(),$refund['delivery_id'],$refund['attempt'],$refund['owner'],$this->now()));
            $this->write($result);
            $this->event($refund,'remote_started');
            return true;
        });
        if (!$claimed) throw new DomainException('refund_claim_conflict');
        try { $result=$prepared['gateway']->refund($prepared['token'],(int)$refund['total_refund']); }
        catch (Throwable) { $result=new RefundResult('refund_uncertain'); }
        unset($prepared['token']);
        if ($result->reversed && (int)$refund['total_refund']!==$prepared['paid_total']) $result=new RefundResult('refund_uncertain');
        try { return $this->finish($refund,$result); }
        catch (Throwable) {
            // Includes commit acknowledgement loss. CAS cannot overwrite a committed success.
            try {
                return (new CheckoutRepository())->transaction(function()use($p,$refund):array {
                    $this->one("SELECT id FROM {$p}checkouts WHERE id=%d FOR UPDATE",$refund['checkout_id']);
                    $stored=$this->one("SELECT * FROM {$p}return_refunds WHERE delivery_id=%d FOR UPDATE",$refund['delivery_id']);
                    if ($stored['status']==='refund_pending' && (int)$stored['attempt']===(int)$refund['attempt']) {
                        $this->terminal($stored,'refund_uncertain');$stored['status']='refund_uncertain';
                    }
                    return $this->safe($stored);
                });
            } catch (Throwable) { throw new DomainException('refund_result_requires_review'); }
        }
    }

    private function finish(array $expected, RefundResult $result): array
    {
        return (new CheckoutRepository())->transaction(function()use($expected,$result):array {
            global $wpdb;$p=$this->prefix();
            $checkout=$this->one("SELECT * FROM {$p}checkouts WHERE id=%d FOR UPDATE",$expected['checkout_id']);
            $refund=$this->one("SELECT * FROM {$p}return_refunds WHERE delivery_id=%d FOR UPDATE",$expected['delivery_id']);
            if ($refund['status']!=='refund_pending' || (int)$refund['attempt']!==(int)$expected['attempt'] || !hash_equals($refund['owner'],$expected['owner'])) throw new DomainException('refund_finalize_conflict');
            if ($result->status==='refunded') {
                $delivery=$this->one("SELECT * FROM {$p}deliveries WHERE id=%d FOR UPDATE",$refund['delivery_id']);
                $now=$this->now();
                $this->write($wpdb->query($wpdb->prepare("UPDATE {$p}orders SET status='cancelled',updated_at=%s WHERE id=%d AND status='incident_review'",$now,$refund['order_id'])));
                $this->write($wpdb->query($wpdb->prepare("UPDATE {$p}deliveries SET status='return_closed',transition_version=transition_version+1,updated_at=%s WHERE id=%d AND status='returned_to_store' AND transition_version=%d",$now,$refund['delivery_id'],$refund['expected_version'])));
                $ledger=['checkout_id'=>$refund['checkout_id'],'idempotency_key'=>'return:'.$refund['delivery_id'].':'.$refund['attempt'],'status'=>'refunded','created_at'=>$now];
                foreach (['product_refund','platform_fee_refund','delivery_fee_refund','total_refund'] as $component) $ledger[$component]=$refund[$component].'.00';
                $this->write($wpdb->insert($p.'checkout_refunds',$ledger));
                $sum=$this->one("SELECT SUM(product_refund) products FROM {$p}checkout_refunds WHERE checkout_id=%d",$refund['checkout_id']);
                $aggregation=CheckoutFeeCalculator::clp((string)$sum['products'])===CheckoutFeeCalculator::clp((string)$checkout['product_subtotal'])?'refunded':'partially_refunded';
                // Financial aggregation is separate from the original payment lifecycle.
                $write=$wpdb->update($p.'checkouts',['refund_status'=>$aggregation],['id'=>$refund['checkout_id']]);
                if ($write===false) throw new \RuntimeException('refund_write_failed');
                (new CourierDeliveryRepository())->audit($delivery,'return_closed',(int)$delivery['courier_id'],'admin',(int)$refund['actor_id'],'cancel_and_refund',null,$now);
            }
            $this->terminal($refund,$result->status);
            return $this->safe([...$refund,'status'=>$result->status,'confirmed_at'=>$result->status==='refunded'?$this->now():null]);
        });
    }

    private function terminal(array $refund, string $status): void
    {
        global $wpdb;
        $this->write($wpdb->update($this->prefix().'return_refunds',['status'=>$status,'confirmed_at'=>$status==='refunded'?$this->now():null,'updated_at'=>$this->now()],['delivery_id'=>$refund['delivery_id'],'attempt'=>$refund['attempt'],'status'=>'refund_pending','owner'=>$refund['owner']]));
        $this->event($refund,$status);
    }

    private function event(array $refund,string $event,?string $key=null,?string $fingerprint=null):void
    {
        global $wpdb;
        $data=array_intersect_key($refund,array_flip(['delivery_id','attempt','actor_id','note','product_refund','platform_fee_refund','delivery_fee_refund','total_refund']));
        $this->write($wpdb->insert($this->prefix().'return_refund_attempts',[...$data,'event'=>$event,'idempotency_key'=>$key,'fingerprint'=>$fingerprint,'created_at'=>$this->now()]));
    }

    /** Explicit allowlist; no provider data, notes or execution authority. */
    public function safe(array $row): array
    {
        $out=['status'=>$row['status'],'confirmed_at'=>$row['confirmed_at']??null];
        foreach (['product_refund','platform_fee_refund','delivery_fee_refund','total_refund'] as $key) $out[$key]=(int)$row[$key];
        return $out;
    }
    private function prefix():string {global $wpdb;return $wpdb->prefix.Config::TABLE_PREFIX;}
    private function now():string {return current_time('mysql',true);}
    private function rows(string $sql,mixed ...$args):array {global $wpdb;$rows=$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A);if($wpdb->last_error!=='')throw new \RuntimeException('refund_read_failed');return $rows;}
    private function one(string $sql,mixed ...$args):array {$rows=$this->rows($sql,...$args);if(count($rows)!==1)throw new DomainException('refund_record_unavailable');return $rows[0];}
    private function write(int|bool $result):void {if($result!==1)throw new \RuntimeException('refund_write_failed');}
}
