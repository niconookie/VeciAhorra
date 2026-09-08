"""Focused destructive mutations in an explicitly disposable checkout only."""
from pathlib import Path
import os, subprocess, sys, json
root=Path(__file__).resolve().parents[2]
if root.name!='cart-proximity-work': raise SystemExit('disposable_clone_required')
aggregate=root/'app/Modules/Cart/Service/CartAggregate.php'
transactions=root/'app/Modules/Checkout/Repository/CheckoutRepository.php'
schema=root/'app/Database/Migrations/CreateCartAggregates.php'
originals={p:p.read_text(encoding='utf-8') for p in [aggregate,transactions,schema]}
mutants=[]
def add(name,p,old,new,count=1):
    assert old in originals[p],name
    mutants.append((name,p,lambda s,old=old,new=new,count=count:s.replace(old,new,count)))
add('omit_row_lock',aggregate,' FOR UPDATE','',100)
add('omit_version_comparison',aggregate,"|| (int)$cart['version']!==$version",'') if "|| (int)$cart['version']!==$version" in originals[aggregate] else add('omit_version_comparison',aggregate,"||(int)$cart['version']!==$version",'')
add('accept_stale',aggregate,"(int)$cart['version']!==$version","(int)$cart['version']<$version")
add('no_increment',aggregate,'if($before!==$this->semantic($stored))$this->bump($cart);','if(false)$this->bump($cart);')
add('double_increment',aggregate,'SET version=version+1,updated_at','SET version=version+2,updated_at')
for state in ['consumed','materializing']:
    def transform(s,state=state):
        s=s.replace("$cart['status']!=='active'||", "!in_array($cart['status'],['active','"+state+"'],true)||")
        return s.replace("AND status='active'\",current_time", "AND status IN ('active','"+state+"')\",current_time")
    mutants.append(('mutate_'+state,aggregate,transform))
add('reactivate_consumed',aggregate,"        if($rows===[]){\n", "        if($rows===[]){\n            $wpdb->query($wpdb->prepare(\"UPDATE {$p}carts SET status='active',active_owner=%s WHERE owner_key=%s AND status='consumed'\",$key,$key));\n")
add('two_active',schema,'UNIQUE KEY carts_active_owner','KEY carts_active_owner')
def no_checkout_lock(s):
    start=s.index('    public function materialize(')
    s=s[:start]+s[start:].replace('$cart=$this->lookup($owner);',"$cart=$this->one('SELECT * FROM '.$this->p().'carts WHERE public_id=%s AND owner_key=%s',$owner['cart_id'],self::ownerKey($owner));",1)
    return s
mutants.append(('checkout_without_lock',aggregate,no_checkout_lock))
def payload_method_authority(s):
    start=s.index('    public function materialize(')
    guard="if(($owner['fulfillment_method']??null)!==$cart['fulfillment_method'])throw new DomainException('cart_modality_conflict');"
    assert guard in s[start:]
    return s[:start]+s[start:].replace(guard,'',1)
mutants.append(('payload_method_authority',aggregate,payload_method_authority))
# The invalid payload must be rejected before invoking the materialization operation.
add('checkout_without_zone',aggregate,'            $this->assertZone($cart);\n','',1)
def lines_after_failure(s):
    start=s.index('    public function materialize(');end=s.index('    public function setMethod(',start)
    section=s[start:end].replace('        return (new CheckoutRepository())->transaction(', '        try{return (new CheckoutRepository())->transaction(',1)
    section=section.replace('        });\n    }\n',"        });}catch(\\Throwable $e){global $wpdb;$wpdb->delete($this->p().'cart_items',['user_id'=>$owner['user_id']??0]);throw $e;}\n    }\n")
    return s[:start]+section+s[end:]
mutants.append(('checkout_failure_empty_lines',aggregate,lines_after_failure))
add('partial_orders_reservations',transactions,'$nested ? "ROLLBACK TO SAVEPOINT {$savepoint}" : \'ROLLBACK\'','$nested ? "ROLLBACK TO SAVEPOINT {$savepoint}" : \'COMMIT\'')
add('foreign_owner_line',aggregate,"throw new DomainException('cart_line_owner_conflict');",';')
add('duplicate_lazy_adoption',aggregate,' AND cart_id IS NULL FOR UPDATE',' FOR UPDATE')
env=os.environ.copy();env['VA_CART_TEST']='1'
env.pop('VA_PICKUP_TEST',None)
if len(sys.argv)==1:env.pop('VA_CART_MODALITY_ONLY',None)
if len(sys.argv)>1:
    selected=sys.argv[1:]
    assert all(name in [m[0] for m in mutants] for name in selected),'unknown_mutation'
    mutants=[m for m in mutants if m[0] in selected]
results=[]
try:
    for name,path,transform in mutants:
        mutated=transform(originals[path]);assert mutated!=originals[path],name
        path.write_text(mutated,encoding='utf-8')
        lint=subprocess.run(['C:/xampp/php/php.exe','-l',str(path)],capture_output=True,text=True)
        if lint.returncode: raise RuntimeError(name+': invalid mutant syntax '+lint.stdout)
        result=subprocess.run(['C:/xampp/php/php.exe',str(root/'tests/manual/cart-aggregate-mysql-test.php')],cwd=root,env=env,capture_output=True,text=True,timeout=45)
        detected=result.returncode!=0 and 'FIRST_FAILURE=' in result.stdout and 'DISPOSABLE_DATABASE_REMOVED=yes' in result.stdout
        results.append({'mutation':name,'detected':detected,'failure':next((x for x in result.stdout.splitlines() if x.startswith('FIRST_FAILURE=')),None)})
        print(json.dumps(results[-1]),flush=True)
        path.write_text(originals[path],encoding='utf-8')
        if not detected: raise RuntimeError('MUTATION_SURVIVED='+name)
finally:
    for path,source in originals.items():path.write_text(source,encoding='utf-8')
print('CART_MUTATIONS_DETECTED='+str(len(results))+'/'+str(len(mutants)))
