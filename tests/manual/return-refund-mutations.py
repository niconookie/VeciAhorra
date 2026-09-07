"""Only run against the disposable work copy. Native InnoDB tests, always restore bytes."""
from pathlib import Path
import os, subprocess

if os.environ.get('VA_REFUND_MUTATIONS')!='1': raise RuntimeError('explicit_mutation_opt_in_required')
root=Path(__file__).resolve().parents[2]
path=root/'app/Modules/Couriers/Returns/ReturnRefundService.php'
original=path.read_bytes()
decision=b'            $now=$this->now();\n            $refund='
terminal=b"                if ($terminal!==[]) return ['response'=>"
mutations=[
 ('partial_fees',decision,b"            $decision['platform_fee_refund']=700;$decision['total_refund']+=700;\n"+decision),
 ('omit_final_fees',decision,b"            $decision['total_refund']-=$decision['platform_fee_refund']+$decision['delivery_fee_refund'];$decision['platform_fee_refund']=0;$decision['delivery_fee_refund']=0;\n"+decision),
 ('double_fees',terminal,b"                if ($terminal!==[]) {global $wpdb;$wpdb->query(\"UPDATE {$p}checkout_refunds SET platform_fee_refund=platform_fee_refund+700,total_refund=total_refund+700 WHERE checkout_id={$checkoutId}\");}\n"+terminal),
 ('whole_checkout_for_order',b"CheckoutFeeCalculator::clp((string)$order['total']),$total,$totals[3]",b"$sum,$total,$totals[3]"),
 ('alter_other_order',b"                $ledger=['checkout_id'",b"                $wpdb->query(\"UPDATE {$p}orders o JOIN {$p}checkout_orders co ON co.order_id=o.id SET o.status='cancelled' WHERE co.checkout_id={$refund['checkout_id']}\");\n                $ledger=['checkout_id'"),
 ('exceed_total',decision,b"            $decision['total_refund']=$total+1;\n"+decision),
 ('omit_version',b"if ($version === null || $version < 0) throw new DomainException('expected_version_required');",b"$version ??= 4;"),
 ('unauthorized',b"if (!current_user_can('manage_options') || get_current_user_id() <= 0) throw new DomainException('refund_admin_required');",b'/* mutant: no authority */'),
 ('duplicate_replay',terminal,b"                if ($terminal!==[]) $this->gateway->refund('localtoken'.str_repeat('A',32),(int)$refund['total_refund']);\n"+terminal),
 ('retry_uncertain',terminal,b"                if ($refund['status']==='refund_uncertain') $this->gateway->refund('localtoken'.str_repeat('A',32),(int)$refund['total_refund']);\n"+terminal),
 ('early_success',b"        try { $result=$prepared['gateway']->refund",b"        $wpdb->query(\"UPDATE {$p}orders SET status='cancelled' WHERE id={$refund['order_id']}\");\n        try { $result=$prepared['gateway']->refund"),
 ('refunded_payable',b"UPDATE {$p}orders SET status='cancelled',updated_at",b"UPDATE {$p}orders SET status='delivered',updated_at"),
 ('inventory_write',b"                $ledger=['checkout_id'",b"                $wpdb->query(\"UPDATE {$p}inventory SET stock=stock+1\");\n                $ledger=['checkout_id'"),
 ('omit_tracking',b"(new CourierDeliveryRepository())->audit($delivery,'return_closed',(int)$delivery['courier_id'],'admin',(int)$refund['actor_id'],'cancel_and_refund',null,$now);",b'/* mutant: tracking omitted */'),
]
env=dict(os.environ,VA_REFUND_TEST='1')
php='C:/xampp/php/php.exe'
try:
 for name,old,new in mutations:
    if original.count(old)!=1: raise RuntimeError('anchor '+name)
    path.write_bytes(original.replace(old,new))
    lint=subprocess.run([php,'-l',str(path)],capture_output=True,text=True)
    if lint.returncode: raise RuntimeError('invalid syntax mutant '+name)
    run=subprocess.run([php,str(root/'tests/manual/return-refund-mysql-test.php')],capture_output=True,text=True,env=env,timeout=60)
    if run.returncode==0 or 'FAIL ' not in run.stdout+run.stderr: raise RuntimeError('survived or invalid mutant '+name+' '+run.stdout+run.stderr)
    print('DETECTED '+name,flush=True)
finally:path.write_bytes(original)
print('PASS return-refund mutations=14/14')
