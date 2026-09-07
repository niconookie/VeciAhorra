"""Disposable-checkout mutation checks. Exact source bytes restored in finally."""
import os,subprocess
from pathlib import Path
if os.environ.get('VA_PROOF_MUTATIONS')!='1':raise SystemExit('explicit_mutation_opt_in_required')
root=Path(__file__).resolve().parents[2]
php=os.environ.get('VA_TEST_PHP','C:/xampp/php/php.exe')
service=root/'app/Modules/Couriers/Evidence/DeliveryProofService.php'
storage=root/'app/Modules/Couriers/Evidence/PrivateDeliveryStorage.php'
courier=root/'app/Modules/Couriers/Service/CourierDeliveryService.php'
mutations=[
 ('otp',service,[("if(!(new DeliveryOtp())->matches($otp,$code)){","if(false){")],'FAIL OTP_WRONG_REJECTED'),
 ('photo',courier,[("if ($target === 'delivered') throw new DomainException('delivery_photo_otp_required');","if (false) throw new DomainException('delivery_photo_otp_required');")],'FAIL legacy_courier_delivery_closed'),
 ('authorization',service,[("if(!is_user_logged_in()||(!current_user_can('manage_options')&&(!$this->customerSession()||!$this->proof->owned($id,get_current_user_id()))))","if(false)")],'FAIL READ_AUTHORIZATION_customer'),
 ('transaction',service,[
 ("$result=(new CheckoutRepository())->transaction(function()use","$result=(function()use"),
 ("return ['id'=>$id,'status'=>'delivered','transition_version'=>$version+1];\n            });","return ['id'=>$id,'status'=>'delivered','transition_version'=>$version+1];\n            })();")
 ],'FAIL ATOMIC_ROLLBACK_tracking'),
 ('privacy',storage,[("if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==403||str_contains(wp_remote_retrieve_body($response),$token))","if(false)")],'FAIL PRIVACY_FAIL_CLOSED'),
 ('compensation',storage,[("foreach($moved?['temp','final']:['temp'] as $kind)","foreach(['temp'] as $kind)")],'FAIL ATOMIC_ROLLBACK_tracking'),
]
for name,path,replacements,expected in mutations:
 original=path.read_bytes()
 try:
  source=original.decode('utf-8').replace('\r\n','\n')
  for old,new in replacements:
   if source.count(old)!=1:raise RuntimeError(name+': nonunique mutation')
   source=source.replace(old,new)
  path.write_text(source,encoding='utf8',newline='\n')
  p=subprocess.run([php,str(root/'tests/manual/delivery-proof-mysql-test.php')],env=dict(os.environ,VA_PROOF_TEST='1'),capture_output=True,text=True)
  output=p.stdout+p.stderr
  if p.returncode==0 or expected not in output or 'DISPOSABLE_DATABASE_REMOVED=yes TEMP_FILES_REMOVED=yes' not in output:raise RuntimeError(name+': unrelated or undetected\n'+output)
  print('MUTATION='+name+' DETECTED=yes')
 finally:path.write_bytes(original)
print('PROOF_MUTATIONS=PASS DETECTED=6/6 RESTORED=yes')
