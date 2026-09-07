"""Run only against an explicitly disposable checkout, restoring source byte-for-byte."""
import hashlib
import os
from pathlib import Path
import subprocess
import sys

if os.environ.get('VA_TERRITORY_MUTATIONS') != '1':
    raise SystemExit('VA_TERRITORY_MUTATIONS=1 required')
root = Path(__file__).resolve().parents[2]
source = root / 'app/Modules/Couriers/Repository/CourierDeliveryRepository.php'
original = source.read_bytes()
text = original.decode('utf-8')
php = os.environ.get('VA_TERRITORY_PHP', 'php')
test = root / 'tests/manual/courier-territory-mysql-test.php'
mutants = []
old = ". $this->complete() . ' AND ' . $this->territory($courierId) . ' ORDER BY d.id ASC'"
assert text.count(old) == 1
mutants.append(('available_filter', text.replace(old, ". $this->complete() . ' ORDER BY d.id ASC'"), 'FAIL A_cannot_see_B'))
start = text.index('    public function accept(')
end = text.index('    public function transition(', start)
block = text[start:end]
old = ". $this->complete() . ' AND ' . $this->territory($courierId);"
assert block.count(old) == 1
mutants.append(('acceptance_cas', text[:start] + block.replace(old, '. $this->complete();') + text[end:], 'FAIL CAS_cross_zone_rejected'))
try:
    for name, mutated, expected in mutants:
        source.write_text(mutated, encoding='utf-8', newline='\n')
        result = subprocess.run([php, '-d', 'allow_url_fopen=0', str(test)], cwd=root, capture_output=True, text=True)
        output = result.stdout + result.stderr
        if result.returncode == 0 or expected not in output or 'DISPOSABLE_DATABASE_REMOVED=yes' not in output:
            raise RuntimeError(f'Mutation {name} not detected correctly: {output}')
        print(f'MUTATION={name} DETECTED=yes CLEANUP=yes')
finally:
    source.write_bytes(original)
    assert hashlib.sha256(source.read_bytes()).digest() == hashlib.sha256(original).digest()
print('MUTATIONS_DETECTED=2/2 SOURCE_RESTORED=yes')
