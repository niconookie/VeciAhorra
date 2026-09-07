<?php
declare(strict_types=1);
require (getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__, 2)) . '/app/Exceptions/ConflictException.php';
require (getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__, 2)) . '/app/Modules/Checkout/Service/CheckoutRefundPolicy.php';
use VeciAhorra\Modules\Checkout\Service\CheckoutRefundPolicy;
use VeciAhorra\Exceptions\ConflictException;
$policy = new CheckoutRefundPolicy();
$count = 0;
$assert = static function (bool $ok, string $message) use (&$count): void {
    ++$count;
    if (!$ok) throw new RuntimeException($message);
};
$reject = static function (array $args, string $code) use ($policy, $assert): void {
    try { $policy->calculate(...$args); } catch (ConflictException $e) {
        $assert($e->errorCode() === $code, 'Wrong rejection: ' . $code); return;
    }
    $assert(false, 'Missing rejection: ' . $code);
};
$base = [8000, 700, 1000, 0, 0, 0, 3000, 9700, 0];
$assert($policy->calculate(...$base) === ['product_refund'=>3000,'platform_fee_refund'=>0,'delivery_fee_refund'=>0,'total_refund'=>3000], 'Partial');
$assert($policy->calculate(8000,700,1000,3000,0,0,5000,9700,3000)['total_refund'] === 6700, 'Last products');
$assert($policy->calculate(8000,700,1000,0,0,0,8000,9700,0)['total_refund'] === 9700, 'Single full');
$final = $policy->calculate(8000,700,1000,3000,200,300,5000,9700,3500);
$assert($final === ['product_refund'=>5000,'platform_fee_refund'=>500,'delivery_fee_refund'=>700,'total_refund'=>6200], 'Historical remainders');
$assert(3500 + $final['total_refund'] === 9700, 'Never 8500');
foreach ([0,1,2,3,4,5,7,8] as $index) { $a=$base; $a[$index]=-1; $reject($a,'refund_negative_amount'); }
$reject([8000,700,1000,8001,0,0,1,9700,8001], 'refund_product_limit');
$reject([8000,700,1000,0,701,0,1,9700,701], 'refund_platform_limit');
$reject([8000,700,1000,0,0,1001,1,9700,1001], 'refund_delivery_limit');
$reject([8000,700,1000,0,0,0,1,9700,9701], 'refund_total_limit');
$reject([8000,700,1000,0,0,0,1,9600,0], 'refund_inconsistent_components');
$reject([8000,700,1000,3000,0,0,1,9700,2999], 'refund_inconsistent_components');
foreach ([-1,0,5001] as $request) $reject([8000,700,1000,3000,0,0,$request,9700,3000], 'refund_exceeds_product_subtotal');
$reject([8000,700,1000,8000,700,1000,1,9700,9700], 'refund_exceeds_product_subtotal');
$reject([0,0,0,0,0,0,0,0,0], 'refund_exceeds_product_subtotal');
$assert($policy->calculate(1,0,0,0,0,0,1,1,0)['total_refund'] === 1, 'Zero fees');
$assert($policy->calculate(PHP_INT_MAX-2,1,1,0,0,0,PHP_INT_MAX-2,PHP_INT_MAX,0)['total_refund'] === PHP_INT_MAX, 'Integer maximum');
$reject([PHP_INT_MAX,1,0,0,0,0,1,PHP_INT_MAX,0], 'refund_integer_overflow');
// Exhaustive small legal histories include partially refunded fees and exact closure.
for ($products=1;$products<=8;++$products) for ($previous=0;$previous<$products;++$previous)
for ($platform=0;$platform<=3;++$platform) for ($delivery=0;$delivery<=3;++$delivery) {
    $r=$policy->calculate($products,3,3,$previous,$platform,$delivery,$products-$previous,$products+6,$previous+$platform+$delivery);
    $assert($r['total_refund']+$previous+$platform+$delivery === $products+6, 'Exact closure');
    $assert($r['platform_fee_refund']===(3-$platform) && $r['delivery_fee_refund']===(3-$delivery), 'Component bounds');
}
$tokens = token_get_all(file_get_contents((getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__,2)).'/app/Modules/Checkout/Service/CheckoutRefundPolicy.php'));
foreach ($tokens as $token) if (is_array($token)) $assert(!in_array($token[0],[T_DNUMBER,T_DOUBLE_CAST],true), 'No floating arithmetic');
echo "PASS refund-policy assertions={$count}\n";
