"""Production browser transport with an in-memory REST double; no site or provider requests."""
import json
import os
import subprocess
from pathlib import Path
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from selenium import webdriver
from selenium.webdriver.chrome.service import Service

root = Path(os.environ.get('VA_PROOF_PLUGIN_ROOT', Path(__file__).resolve().parents[2]))

class Page(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.end_headers()
        self.wfile.write(b'<!doctype html><html><body></body></html>')
    def log_message(self, *args):
        pass

server = ThreadingHTTPServer(('127.0.0.1', 0), Page)
threading.Thread(target=server.serve_forever, daemon=True).start()
drivers = sorted(Path('C:/Users/UserMSI/.cache/selenium/chromedriver/win64').glob('*/chromedriver.exe'))
driver_path = os.environ.get('VA_CHROMEDRIVER') or str(drivers[-1])
try:
    with tempfile.TemporaryDirectory(prefix='va-cart-browser-') as profile:
        options = webdriver.ChromeOptions()
        for flag in ['--headless=new', '--disable-background-networking', '--disable-component-update', '--no-first-run', '--no-default-browser-check', '--proxy-server=socks5://127.0.0.1:9', '--user-data-dir=' + profile]:
            options.add_argument(flag)
        browser = webdriver.Chrome(service=Service(driver_path), options=options)
        try:
            browser.get('http://127.0.0.1:' + str(server.server_port))
            for path in ['global-header.js', 'veciahorra-cart.js', 'veciahorra-checkout.js', 'veciahorra-frontend.js']:
                browser.execute_script('new Function(arguments[0]);', (root / 'assets/frontend/js' / path).read_text(encoding='utf-8'))
            browser.execute_script("window.VeciAhorra={restUrl:location.origin+'/wp-json/veciahorra/v1',nonce:'local-nonce',cart:{sessionId:''}};")
            browser.execute_script((root / 'assets/frontend/js/veciahorra-frontend.js').read_text(encoding='utf-8'))
            result = browser.execute_async_script("""
                const done=arguments[arguments.length-1];
                (async()=>{
                    let count=0,version=4,conflict=false,calls=[];
                    const check=(ok,label)=>{count++;if(!ok)throw Error(label);};
                    window.fetch=async(url,options)=>{
                        const body=options.body?JSON.parse(options.body):null;
                        calls.push({url,method:options.method,body});
                        let status=200,payload;
                        if(options.method==='GET')payload={success:true,cart_id:'a'.repeat(48),version,status:'active',fulfillment_method:'pickup',data:[]};
                        else if(conflict){status=409;payload={success:false,error:{code:'cart_conflict'}};version++;}
                        else payload={success:true,data:{cart:{cart_id:'a'.repeat(48),version:++version,status:'active',fulfillment_method:'pickup',items:[]}}};
                        check(options.headers.get('X-WP-Nonce')==='local-nonce','REST_NONCE');
                        return new Response(JSON.stringify(payload),{status});
                    };
                    await VeciAhorra.api.post('/cart/items',{offer_token:'opaque',quantity:1});
                    check(calls.length===2&&calls[0].method==='GET','INITIAL_SNAPSHOT');
                    check(calls[1].body.expected_version===4&&calls[1].body.cart_id==='a'.repeat(48),'MUTATION_VERSION');
                    check(VeciAhorra.cartSnapshot.version===5,'CAPTURE_MUTATION');
                    await VeciAhorra.api.post('/checkout',{fulfillment_method:'pickup'});
                    check(calls[2].body.expected_cart_version===5&&!('expected_version' in calls[2].body),'CHECKOUT_VERSION');
                    conflict=true;const before=calls.length;let rejected=false;
                    try{await VeciAhorra.api.patch('/cart/items/1',{quantity:2});}catch(e){rejected=e.status===409&&e.message.includes('carrito');}
                    check(rejected,'CONFLICT_REPORTED');
                    check(calls.length===before+2&&calls.at(-1).method==='GET','REFRESH_WITHOUT_RETRY');
                    check(VeciAhorra.cartSnapshot.version===version,'REFRESH_CAPTURE');
                    return {tests:1,assertions:count,failed:0,external_calls:0};
                })().then(done,e=>done({failed:1,error:e.message}));
            """)
            print(json.dumps(result))
            if result['failed']:
                raise SystemExit(1)
            if os.environ.get('VA_PICKUP_BROWSER') == '1':
                browser.execute_script('document.body.innerHTML=arguments[0];', (root / 'app/Modules/Cart/Proximity/Views/search.php').read_text(encoding='utf-8'))
                browser.execute_script("window.VeciAhorra={restUrl:location.origin+'/wp-json/veciahorra/v1',nonce:'local-nonce',cart:{sessionId:''},currentUser:{loggedIn:true},launch:{commerceEnabled:true},pickup:{googleBrowserKey:''}};")
                browser.execute_script((root / 'assets/frontend/js/veciahorra-frontend.js').read_text(encoding='utf-8'))
                browser.execute_script((root / 'assets/frontend/js/veciahorra-pickup.js').read_text(encoding='utf-8'))
                support = Path(__file__).resolve().parent / 'support'
                result = browser.execute_async_script((support / 'pickup-browser-cases.js').read_text(encoding='utf-8'))
                print(json.dumps(result))
                if result['failed']:
                    raise SystemExit(1)
                for page in ['cart', 'checkout']:
                    browser.get('http://127.0.0.1:' + str(server.server_port))
                    markup = subprocess.run(['C:/xampp/php/php.exe', str(support / 'pickup-render-view.php'), page], check=True, capture_output=True, encoding='utf-8').stdout
                    browser.execute_script('document.body.innerHTML=arguments[0];', markup)
                    browser.execute_script((support / 'pickup-page-fixture.js').read_text(encoding='utf-8'))
                    for script in ['veciahorra-frontend.js', 'veciahorra-' + page + '.js', 'veciahorra-pickup.js']:
                        browser.execute_script((root / 'assets/frontend/js' / script).read_text(encoding='utf-8'))
                    result = browser.execute_async_script((support / 'pickup-page-cases.js').read_text(encoding='utf-8'), page)
                    print(json.dumps(result))
                    if result['failed']:
                        raise SystemExit(1)
        finally:
            browser.quit()
finally:
    server.shutdown()
    server.server_close()
