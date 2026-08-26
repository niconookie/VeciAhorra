import atexit, json, subprocess, sys, tempfile, time
from pathlib import Path
import requests, urllib3
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

urllib3.disable_warnings()
ROOT = Path(__file__).resolve().parents[2]
BASE = 'https://localhost/Minimarket'
MODE = sys.argv[1]
assert MODE in ('absent', 'false', 'true', 'hostile')
MESSAGE_REG = 'Estamos preparando VeciAhorra. El registro estará disponible desde el 1 de septiembre.'
MESSAGE_COM = 'Estamos preparando VeciAhorra. Las compras estarán disponibles desde el 1 de septiembre.'
session = requests.Session()
session.verify = False

def request(method, path, **kwargs):
    response = session.request(method, BASE + path, timeout=30, allow_redirects=False, **kwargs)
    return response

def payload(response):
    try: return response.json()
    except Exception: return None

results = {}
closed = MODE != 'true'
fixture_login = 'va_prelaunch_' + __import__('secrets').token_hex(6)
fixture = json.loads(subprocess.check_output([r'C:\xampp\php\php.exe', str(ROOT / 'tests/manual/prelaunch-http-user-fixture.php'), 'setup', fixture_login], text=True))
atexit.register(lambda: subprocess.run([r'C:\xampp\php\php.exe', str(ROOT / 'tests/manual/prelaunch-http-user-fixture.php'), 'cleanup', fixture_login], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
authenticated = requests.Session(); authenticated.verify = False
for cookie in fixture['cookies']:
    authenticated.cookies.set(cookie['name'], cookie['value'], path=cookie['path'])
registration = request('GET', '/registro-cliente/')
native = request('GET', '/wp-login.php?action=register')
customer_post = request('POST', '/registro-cliente/', data={'veciahorra_customer_registration': '1'})
minimarket_post = request('POST', '/registro-minimarket/', data={})
cart = request('POST', '/wp-json/veciahorra/v1/cart/items?VECIAHORRA_PUBLIC_COMMERCE_ENABLED=true', json={}, headers={'X-VeciAhorra-Cart-Session': 'audit-' + 'a' * 32})
checkout = request('POST', '/wp-json/veciahorra/v1/checkout', json={}, headers={'X-VeciAhorra-Cart-Session': 'audit-' + 'b' * 32})
payment = request('POST', '/wp-json/veciahorra/v1/payments/session', json={'VECIAHORRA_PUBLIC_COMMERCE_ENABLED': True})
provider = authenticated.post(BASE + '/wp-json/veciahorra/v1/service-provider/enroll', headers={'X-WP-Nonce': fixture['nonce']}, verify=False, timeout=30)

if closed:
    assert registration.status_code == 200 and MESSAGE_REG in registration.text
    assert native.status_code == 503 and MESSAGE_REG in native.text
    assert customer_post.status_code == 503 and MESSAGE_REG in customer_post.text
    assert minimarket_post.status_code == 503 and MESSAGE_REG in minimarket_post.text
    for response in (cart, checkout, payment):
        assert response.status_code == 503 and payload(response)['error']['message'] == MESSAGE_COM
    assert provider.status_code == 503 and payload(provider)['error']['message'] == MESSAGE_REG
else:
    assert MESSAGE_REG not in registration.text
    assert native.status_code != 503
    assert customer_post.status_code != 503
    assert minimarket_post.status_code != 503
    assert cart.status_code == 422
    assert checkout.status_code == 422
    assert payment.status_code == 400
    assert provider.status_code == 200

home = request('GET', '/')
results['cache'] = {
    'cache_control': home.headers.get('Cache-Control', ''),
    'pragma': home.headers.get('Pragma', ''),
    'x_litespeed_cache': home.headers.get('X-LiteSpeed-Cache', ''),
}
if closed:
    assert 'private' in results['cache']['cache_control'] and 'no-store' in results['cache']['cache_control']
    assert 'no-cache' in results['cache']['pragma']
    for response in (cart, checkout, payment):
        assert 'no-cache' in response.headers.get('Cache-Control', '').lower() or 'no-store' in response.headers.get('Cache-Control', '').lower()

for key, response in {'registration': registration, 'native_registration': native, 'customer_post': customer_post, 'minimarket_post': minimarket_post, 'provider_enrollment': provider, 'cart': cart, 'checkout': checkout, 'payment_session': payment}.items():
    body = payload(response)
    results[key] = {'status': response.status_code, 'content_type': response.headers.get('Content-Type', '').split(';')[0], 'code': body.get('error', {}).get('code') if isinstance(body, dict) else None}

if MODE in ('false', 'true'):
    out = ROOT / 'artifacts/prelaunch'
    out.mkdir(parents=True, exist_ok=True)
    profile = tempfile.TemporaryDirectory(prefix='va-prelaunch-http-')
    options = webdriver.ChromeOptions()
    for arg in ['--headless=new', '--ignore-certificate-errors', '--allow-insecure-localhost', '--window-size=1440,1100', '--disable-gpu', '--no-sandbox', '--user-data-dir=' + profile.name]: options.add_argument(arg)
    options.set_capability('acceptInsecureCerts', True)
    driver = webdriver.Chrome(service=Service(r'C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe'), options=options)
    try:
        driver.get(BASE + ('/' if closed else '/catalogo-veciahorra/'))
        time.sleep(3)
        target = out / ('http-false-closed.png' if closed else '05-modo-abierto-compra-habilitada.png')
        driver.save_screenshot(str(target))
        results['capture'] = str(target)
        results['overflow'] = not driver.execute_script('return document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1')
    finally:
        driver.quit(); profile.cleanup()

subprocess.check_call([r'C:\xampp\php\php.exe', str(ROOT / 'tests/manual/prelaunch-http-user-fixture.php'), 'cleanup', fixture_login], stdout=subprocess.DEVNULL)
print(json.dumps({'mode': MODE, 'closed': closed, 'results': results, 'fixture_cleanup': True}, ensure_ascii=False))
