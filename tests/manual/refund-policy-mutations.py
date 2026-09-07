"""Pure subprocess mutation tests. Always restore the sole production file."""
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[2]
source = root / 'app/Modules/Checkout/Service/CheckoutRefundPolicy.php'
original = source.read_bytes()
mutations = [
    ('product_limit', b'$alreadyProducts > $productSubtotal', b'false'),
    ('platform_limit', b'$alreadyPlatformFee > $platformFee', b'false'),
    ('delivery_limit', b'$alreadyDeliveryFee > $deliveryFee', b'false'),
    ('total_limit', b'$accumulatedTotal > $originalTotal', b'false'),
    ('early_fees', b'$completes ? $remainingPlatform : 0', b'$remainingPlatform'),
    ('full_instead_of_remainder', b'$completes ? $remainingPlatform : 0', b'$completes ? $platformFee : 0'),
    ('omit_delivery', b'$completes ? $remainingDelivery : 0', b'0'),
    ('omit_platform', b'$completes ? $remainingPlatform : 0', b'0'),
    ('inconsistent', b'$originalSum !== $originalTotal || $accumulatedSum !== $accumulatedTotal', b'false'),
    ('floating', b'$sum += $value;', b'$sum = (int) ((float) $sum + $value);'),
]
php = sys.argv[1] if len(sys.argv)>1 else 'C:/xampp/php/php.exe'
try:
    for name, old, new in mutations:
        if original.count(old)!=1: raise RuntimeError('Mutation anchor: '+name)
        source.write_bytes(original.replace(old,new))
        run=subprocess.run([php,str(root/'tests/manual/refund-policy-test.php')],capture_output=True,text=True)
        if run.returncode==0 or 'Fatal error' not in run.stdout+run.stderr:
            raise RuntimeError('Survived or invalid mutant: '+name+' '+run.stdout+run.stderr)
        print('DETECTED '+name)
finally:
    source.write_bytes(original)
print('PASS policy mutations=10/10')
