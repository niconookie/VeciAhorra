"""Mutate only a disposable checkout; restore original bytes for every experiment."""
import os,subprocess,re
from pathlib import Path
if os.environ.get('VA_RETURN_MUTATIONS')!='1':raise SystemExit('explicit_opt_in_required')
r=Path(__file__).resolve().parents[2]
s=r/'app/Modules/Couriers/Returns/ReturnService.php'
c=r/'app/Modules/Couriers/Service/CourierDeliveryService.php'
p=r/'app/Modules/Couriers/Evidence/DeliveryProofService.php'
mutations=[
 ('assigned_courier',s,[("if($action==='open'&&(int)$row['courier_id']!==$authority)","if(false)")],'OPEN_AUTHORITY_courier'),
 ('picked_up_only',s,[("$from=$action==='open'?'picked_up':'return_pending'","$from=$action==='open'?$row['status']:'return_pending'")],'OPEN_STATE_REQUIRED'),
 ('otp_invalidation',s,[("                $this->write($wpdb->query($wpdb->prepare(\"UPDATE {$p}delivery_otps SET code_hash='',generation_context='',consumed_at=COALESCE(consumed_at,%s),invalidated_at=%s WHERE delivery_id=%d AND invalidated_at IS NULL\",$now,$now,$id)),1);",'')],'OTP_INVALIDATED'),
 ('completion_after_incident',p,[("        global $wpdb;","        global $wpdb; if($wpdb->get_var($wpdb->prepare(\"SELECT status FROM {$wpdb->prefix}va_deliveries WHERE id=%d\",$id))==='return_pending')return ['status'=>'delivered'];")],'DELIVERY_AFTER_INCIDENT'),
 ('early_custody_release',s,[("SET status=%s,transition_version=transition_version+1,updated_at=%s WHERE id=%d", "SET courier_id=NULL,status=%s,transition_version=transition_version+1,updated_at=%s WHERE id=%d")],'CUSTODY_OWNER'),
 ('foreign_store',s,[("(int)$store['id']);","1001);"),("if(is_wp_error($current)||(int)$current['id']!==$authority)","if(is_wp_error($current))")],'RECEIVE_AUTHORITY_store'),
 ('expected_version',s,[("if($version===null||$version<0)","$version??=$action==='open'?2:3; if($version<0)")],'invalid_open_input'),
 ('order_write',s,[("            $this->write($wpdb->query($wpdb->prepare(\"UPDATE {$p}orders SET status=%s,updated_at=%s WHERE id=%d AND status=%s\",$orderTarget,$now,$row['order_id'],$orderFrom)),1);",'')],'OPEN_DELIVERY_ORDER'),
 ('tracking',s,[("            $this->deliveries->audit($row,$target,(int)$row['courier_id'],$action==='open'?'courier':'store',$user,$code,$note,$now);",'')],'TRACKING_OPEN'),
 ('new_acceptance',c,[("if($nextCourier!==null&&$target==='assigned'&&$this->repository->hasReturnCustody($nextCourier))",'if(false)')],'NEW_ACCEPTANCE_CUSTODY'),
]
for name,path,replacements,label in mutations:
 original=path.read_bytes()
 try:
  source=original.decode('utf8').replace('\r\n','\n')
  for old,new in replacements:
   if source.count(old)!=1:raise RuntimeError(name+': nonunique mutation')
   source=source.replace(old,new)
  path.write_text(source,encoding='utf8',newline='\n')
  result=subprocess.run(['C:/xampp/php/php.exe',str(r/'tests/manual/return-flow-mysql-test.php')],env=dict(os.environ,VA_PROOF_TEST='1'),text=True,capture_output=True)
  output=result.stdout+result.stderr
  if result.returncode==0 or 'FAIL '+label not in output or 'DISPOSABLE_DATABASE_REMOVED=yes TEMP_FILES_REMOVED=yes' not in output:raise RuntimeError(name+': undetected or unrelated\n'+output)
  print('MUTATION='+name+' DETECTED=yes',flush=True)
 finally:path.write_bytes(original)
print('RETURN_MUTATIONS=PASS DETECTED=10/10 RESTORED=yes')
