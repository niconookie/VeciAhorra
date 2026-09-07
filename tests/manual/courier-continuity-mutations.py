"""Run only in a disposable checkout; restore every mutated byte in finally."""
import os
from pathlib import Path
import subprocess

if os.environ.get('VA_CONTINUITY_MUTATIONS') != '1':
    raise SystemExit('explicit_disposable_mutations_required')
root = Path(__file__).resolve().parents[2]
repo = root / 'app/Modules/Couriers/Repository/CourierDeliveryRepository.php'
service = root / 'app/Modules/Couriers/Service/CourierDeliveryService.php'
php = os.environ.get('VA_TEST_PHP', 'php')
mutations = [
    ('cas', repo, [('AND d.transition_version=%d', 'AND %d>=0')], 'FAIL CAS_STALE_REVISION'),
    ('territorial_list', repo, [(". $this->complete() . ' AND ' . $this->territory($courierId) . ' ORDER BY d.id ASC'", ". $this->complete() . ' ORDER BY d.id ASC'")], 'FAIL TERRITORIAL_LIST'),
    ('territorial_write', repo, [("$guard = ' AND '.$this->territory($courierId ?? (int)$before['courier_id']);", "$guard = ''; // mutant")], 'FAIL TERRITORIAL_WRITE'),
    ('rollback', service, [
        ('return (new CheckoutRepository())->transaction(function () use', 'return (function () use'),
        ('return $this->result($id);\n        });', 'return $this->result($id);\n        })();'),
    ], 'FAIL ACCEPT_ROLLBACK'),
]
for name, path, replacements, expected in mutations:
    original = path.read_bytes()
    try:
        mutated = original.decode('utf-8').replace('\r\n','\n')
        for old, new in replacements:
            if mutated.count(old) != 1:
                raise RuntimeError(f'{name}: mutation target must be unique')
            mutated = mutated.replace(old, new)
        path.write_text(mutated, encoding='utf-8', newline='\n')
        env = dict(os.environ, VA_CONTINUITY_TEST='1')
        result = subprocess.run([php, str(root/'tests/manual/courier-continuity-mysql-test.php')], env=env, capture_output=True, text=True)
        output = result.stdout + result.stderr
        if result.returncode == 0 or expected not in output or 'DISPOSABLE_DATABASE_REMOVED=yes' not in output:
            raise RuntimeError(f'{name}: undetected or unrelated failure\n{output}')
        print(f'MUTATION={name} DETECTED=yes EXPECTED={expected}')
    finally:
        path.write_bytes(original)
print('CONTINUITY_MUTATIONS=PASS DETECTED=4/4 RESTORED=yes')
