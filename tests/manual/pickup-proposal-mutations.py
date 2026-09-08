"""One destructive behavioral mutation at a time, only in the disposable draft."""
from pathlib import Path
import json
import os
import subprocess
import sys

root=Path(__file__).resolve().parents[2]
if root.name!='cart-proximity-work':raise SystemExit('disposable_clone_required')
service=root/'app/Modules/Cart/Proximity/PickupProposalService.php'
transactions=root/'app/Modules/Checkout/Repository/CheckoutRepository.php'
originals={path:path.read_bytes() for path in [service,transactions]}
mutants=[]
def add(name,path,old,new):
    assert old in originals[path].decode('utf-8'),name
    mutants.append((name,path,lambda source:source.replace(old,new,1)))
add('minimum_inclusive',service,"$source['subtotal'] < $minimum","$source['subtotal'] <= $minimum")
add('rank_by_price',service,"($a['distance'] <=> $b['distance'])","((float)$a['summary']['total'] <=> (float)$b['summary']['total'])")
def browser_distance(source):
    source=source.replace("['latitude', 'longitude', 'confirmed']","['latitude', 'longitude', 'confirmed', 'distance']",1)
    source=source.replace('use ($point, $owner)', 'use ($point, $owner, $input)',1)
    return source.replace("GeoPoint::distance($point, $candidate['point'])","(float)($input['distance'] ?? 0)",1)
mutants.append(('browser_distance_authority',service,browser_distance))
add('omit_zone',service,'(new TerritorialAuthority())->storeInZone($zone, $storeId, $lock);','')
add('missing_coordinates_allowed',service,"GeoPoint::validate($store['pickup_latitude'], $store['pickup_longitude'])","GeoPoint::validate($store['pickup_latitude'] ?? 0, $store['pickup_longitude'] ?? 0)")
add('omit_required_product',service,'foreach ($products as $productId => $quantity)', 'foreach (array_slice($products, 0, 1, true) as $productId => $quantity)')
add('ignore_quantity_stock',service,"(int)$inventory['stock'] < $quantity",'false')
add('allow_multiple_stores',service," WHERE minimarket_id=%d AND product_id=%d ORDER BY id' . $suffix, $storeId, $productId"," WHERE product_id=%d ORDER BY id' . $suffix, $productId")
add('modify_before_acceptance',service,'$best = $candidates[0];',"$best = $candidates[0]; $wpdb->delete($this->p() . 'cart_items', ['cart_id' => $cart['id']]);")
def omit_revalidation(source):
    source=source.replace("$candidate = $this->candidate((int)$proposal['store_id'], (int)$lockedCart['service_zone_id'], $source['products'], true);","$candidate = ['offers' => json_decode($proposal['offers_json'], true, 512, JSON_THROW_ON_ERROR)];",1)
    return source.replace("if ($candidate === null || !hash_equals", "if (false && ($candidate === null || !hash_equals",1).replace("$candidate['summary'] !== $display['summary']) throw", "$candidate['summary'] !== $display['summary'])) throw",1)
mutants.append(('omit_acceptance_revalidation',service,omit_revalidation))
add('ignore_expected_version',service,"$rows = $this->rows('SELECT * FROM '","$owner['expected_version'] = (int)$cart['version'];\n            $rows = $this->rows('SELECT * FROM '")
add('keep_delivery_method',service,"$this->write($wpdb->update($this->p() . 'carts', ['fulfillment_method' => 'pickup'], ['id' => $lockedCart['id']]));",'')
add('keep_delivery_fee',service,"'summary' => $best['summary'],","'summary' => [...$best['summary'], 'delivery_fee' => '1000.00'],")
add('allow_partial_replacement',transactions,'$nested ? "ROLLBACK TO SAVEPOINT {$savepoint}" : \'ROLLBACK\'','$nested ? "RELEASE SAVEPOINT {$savepoint}" : \'COMMIT\'')
add('reserve_during_search',service,'$best = $candidates[0];',"$best = $candidates[0]; (new \\VeciAhorra\\Modules\\Reservations\\Service\\ReservationService())->createForCheckout($best['offers']);")
add('persist_search_location',service,"json_encode($presentation, JSON_THROW_ON_ERROR)","json_encode([...$presentation, 'search_point' => $point], JSON_THROW_ON_ERROR)")
if len(sys.argv)>1:
    assert all(name in [m[0] for m in mutants] for name in sys.argv[1:]),'unknown_mutation'
    mutants=[m for m in mutants if m[0] in sys.argv[1:]]
env=os.environ.copy();env.update(VA_CART_TEST='1',VA_PICKUP_TEST='1')
results=[]
try:
    for name,path,transform in mutants:
        changed=transform(originals[path].decode('utf-8'));assert changed.encode('utf-8')!=originals[path],name
        path.write_bytes(changed.encode('utf-8'))
        lint=subprocess.run(['C:/xampp/php/php.exe','-l',str(path)],capture_output=True,text=True)
        if lint.returncode:raise RuntimeError(name+': invalid mutant syntax '+lint.stdout+lint.stderr)
        run=subprocess.run(['C:/xampp/php/php.exe',str(root/'tests/manual/cart-aggregate-mysql-test.php')],cwd=root,env=env,capture_output=True,text=True,timeout=60)
        failure=next((line for line in run.stdout.splitlines() if line.startswith('FIRST_FAILURE=')),None)
        detected=run.returncode!=0 and failure is not None and 'DISPOSABLE_DATABASE_REMOVED=yes' in run.stdout
        results.append({'mutation':name,'detected':detected,'failure':failure});print(json.dumps(results[-1]),flush=True)
        path.write_bytes(originals[path])
        if not detected:raise RuntimeError('MUTATION_SURVIVED='+name)
finally:
    for path,original in originals.items():path.write_bytes(original)
print('PROXIMITY_MUTATIONS_DETECTED='+str(len(results))+'/'+str(len(mutants)))
