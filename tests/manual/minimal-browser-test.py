"""Native fetch against loopback fixtures, loading the actual frontend files."""
from pathlib import Path
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlsplit,parse_qs
import subprocess,threading,json,time,urllib.request,websocket,tempfile,shutil,sys,os,hashlib,re,http.client,socket

def runtime_authority():
    identifier=os.environ.get('MINIMAL_RUNTIME_IDENTIFIER')
    if not identifier or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',identifier):
        raise RuntimeError('PREBOOTSTRAP_IDENTIFIER_REQUIRED')
    ini=Path(os.environ['PHPRC']).resolve()
    task=ini.parent
    if task.name!=identifier:
        raise RuntimeError('PREBOOTSTRAP_IDENTIFIER_AUTHORITY')
    wp=Path(os.environ['MINIMAL_WORDPRESS_ROOT']).resolve()
    if ini.name!='php.ini' or wp!=task/'wordpress' or (wp/'minimal-disposable.marker').read_text().strip()!=identifier:
        raise RuntimeError('PREBOOTSTRAP_RUNTIME_AUTHORITY')
    return identifier,task

identifier,task=runtime_authority()

def prebootstrap_probes():
    """Only edit the already provisioned, disposable runtime; restore every fixture in finally."""
    identifier,task=runtime_authority()
    wp=Path(os.environ['MINIMAL_WORDPRESS_ROOT']).resolve()
    assert wp==task/'wordpress'
    ini=task/'php.ini';config=wp/'wp-config.php';bootstrap=wp/'wp-load.php'
    plugin=wp/'wp-content/plugins/veciahorra/veciahorra.php'
    saved={f:f.read_bytes() for f in [ini,config,bootstrap,plugin]}
    marker=task/(identifier+'-bootstrap-entered')
    script=wp/'wp-content/plugins/veciahorra/tests/manual/minimal-regression-test.php'
    command=[r'C:\xampp\php\php.exe','-c',str(ini),str(script),'prebootstrap']
    cases=0
    def run(expected=None,env=None):
        nonlocal cases
        child=os.environ.copy();child['MINIMAL_RUNTIME_IDENTIFIER']=identifier
        for key,value in (env or {}).items():
            if value is None:child.pop(key,None)
            else:child[key]=value
        child['MINIMAL_PHP_INI_SHA256']=hashlib.sha256(ini.read_bytes()).hexdigest()
        child.setdefault('MINIMAL_WP_CONFIG_SHA256',hashlib.sha256(config.read_bytes()).hexdigest())
        result=subprocess.run(command,env=child,capture_output=True,encoding='utf-8',errors='replace')
        output=result.stdout+result.stderr
        if expected:
            assert result.returncode!=0 and expected in output and not marker.exists(),output
            print('PASS '+expected+' BOOTSTRAP_ENTERED=no',flush=True)
        else:
            assert result.returncode==0 and 'PASS prebootstrap WORDPRESS_LOADED=yes' in output,output
            print(output.strip(),flush=True)
        cases+=1
    try:
        # Two fresh children inherit the same ini and actual auto_prepend_file.
        run();run()
        bootstrap.write_text("<?php file_put_contents("+repr(marker.as_posix())+", 'entered'); exit;",encoding='utf-8')
        original=saved[config].decode('utf-8')
        config.write_text(original.replace("'"+identifier+"'","'incorrect-database'"),encoding='utf-8',newline='\n')
        run('PREBOOTSTRAP_DB_NAME',{'MINIMAL_WP_CONFIG_SHA256':hashlib.sha256(config.read_bytes()).hexdigest()})
        config.write_text(original.replace('127.0.0.1:3328','192.0.2.1:3328'),encoding='utf-8',newline='\n')
        run('PREBOOTSTRAP_DB_HOST',{'MINIMAL_WP_CONFIG_SHA256':hashlib.sha256(config.read_bytes()).hexdigest()})
        config.write_bytes(saved[config]);run('PREBOOTSTRAP_CONFIG_HASH',{'MINIMAL_WP_CONFIG_SHA256':'0'*64})
        plugin.write_bytes(saved[plugin]+b'\n')
        run('PREBOOTSTRAP_PLUGIN_AUTHORITY')
        plugin.write_bytes(saved[plugin])
        run('PREBOOTSTRAP_IDENTIFIER_REQUIRED',{'MINIMAL_RUNTIME_IDENTIFIER':None})
        run('PREBOOTSTRAP_IDENTIFIER_AUTHORITY',{'MINIMAL_RUNTIME_IDENTIFIER':'incorrect-identifier'})
        text=saved[ini].decode('utf-8')
        disabled=next(line.split('=',1)[1].strip() for line in reversed(text.splitlines()) if line.startswith('disable_functions='))
        for name in ['gethostbyname','gethostbynamel','dns_get_record','checkdnsrr','getmxrr','curl_init','fsockopen','pfsockopen','stream_socket_client']:
            assert name in disabled.split(',')
            ini.write_text(text+'\ndisable_functions='+','.join(x for x in disabled.split(',') if x!=name)+'\n',encoding='utf-8',newline='\n')
            run('PREBOOTSTRAP_NETWORK_FUNCTION_ENABLED')
        print('PASS prebootstrap_probes cases='+str(cases)+' external_attempts=0',flush=True)
    finally:
        for file,body in saved.items():file.write_bytes(body)
        if marker.exists():marker.unlink()


def loopback_url(url):
    host=os.environ.get('MINIMAL_RUNTIME_HTTP_HOST')
    port=os.environ.get('MINIMAL_RUNTIME_HTTP_PORT','')
    if host not in ['127.0.0.1','localhost','::1'] or not re.fullmatch(r'[1-9][0-9]{0,4}',port) or int(port)>65535:
        raise RuntimeError('AUDIT_HTTP_AUTHORITY_FORBIDDEN')
    try:
        parsed=urlsplit(url)
        authority=('['+host+']' if host=='::1' else host)+':'+port
        if parsed.scheme!='http' or parsed.netloc!=authority or parsed.fragment or re.search(r'[\x00-\x20\x7f\\]',url):
            raise ValueError()
    except ValueError:
        raise RuntimeError('AUDIT_HTTP_AUTHORITY_FORBIDDEN')
    return parsed,host,int(port)

def loopback_runtime():
    """Disposable local HTTP listener and file queue for PHP with networking disabled."""
    identifier,task=runtime_authority()
    host=os.environ['MINIMAL_RUNTIME_HTTP_HOST'];port=int(os.environ['MINIMAL_RUNTIME_HTTP_PORT'])
    origin='http://'+('['+host+']' if host=='::1' else host)+':'+str(port)
    loopback_url(origin+'/')
    address='::1' if host=='::1' else '127.0.0.1' # Never resolve localhost through DNS.
    class LocalHandler(BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def do_HEAD(self):self.do_GET()
        def do_GET(self):
            with (task/'loopback-requests.jsonl').open('a',encoding='utf-8') as log:
                log.write(json.dumps({'path':self.path,'host':self.headers.get('Host')})+'\n')
            query=parse_qs(urlsplit(self.path).query)
            self.send_response(302 if 'location' in query else 200)
            if 'location' in query:self.send_header('Location',query['location'][0])
            self.send_header('Content-Length','0');self.end_headers()
    class LocalServer(HTTPServer):
        address_family=socket.AF_INET6 if address=='::1' else socket.AF_INET
    server=LocalServer((address,port),LocalHandler)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    queue=task/'loopback-http';queue.mkdir(exist_ok=True)
    (task/'loopback-ready').write_text(origin)
    try:
        while not (task/'loopback-stop').exists():
            for request in queue.glob('*.request.json'):
                if not re.fullmatch(r'[0-9a-f]{32}\.request\.json',request.name):raise RuntimeError('INVALID_QUEUE_FILE')
                connection=None
                try:
                    data=json.loads(request.read_text());url,_,_=loopback_url(data['url'])
                    if data['method'] not in ['GET','HEAD']:raise RuntimeError('AUDIT_HTTP_METHOD_FORBIDDEN')
                    connection=http.client.HTTPConnection(address,port,timeout=5)
                    connection.request(data['method'],url.path+('?' + url.query if url.query else ''),headers={'Host':url.netloc})
                    response=connection.getresponse();headers={k.lower():v for k,v in response.getheaders()}
                    if 'location' in headers:loopback_url(headers['location']) # No automatic redirects.
                    result={'headers':headers,'body':response.read().decode('utf-8'),'response':{'code':response.status,'message':response.reason},'cookies':[],'filename':None}
                except Exception as error:result={'error':str(error)}
                finally:
                    if connection:connection.close()
                target=request.with_name(request.name.replace('.request.json','.response.json'))
                pending=target.with_suffix('.pending');pending.write_text(json.dumps(result),encoding='utf-8');pending.replace(target)
                request.unlink(missing_ok=True)
            time.sleep(.01)
    finally:
        server.shutdown();server.server_close();thread.join()
        (task/'loopback-ready').unlink(missing_ok=True)

if '--loopback-runtime' in sys.argv:
    loopback_runtime();sys.exit(0)

if '--prebootstrap-probes' in sys.argv:
    prebootstrap_probes();sys.exit(0)

root=Path(__file__).resolve().parents[2]
scenario='';counts={};poll=0;checkout='chk_'+'a'*43
summary=dict(item_count=1,valid_item_count=1,invalid_item_count=0,product_subtotal='1500.00',platform_fee='700.00',delivery_fee='0.00',total='2200.00',delivery_eligible=False)
item=dict(offer_group="local",id=1,product_id=101,inventory_id=201,minimarket_id=1,minimarket_name='Local',product_name='Local',quantity=1,unit_price_snapshot='1500.00',subtotal='1500.00',valid=True,errors=[])
shell=(root/'tests/manual/public-checkout-browser-test.html').read_text(encoding='utf-8').split('<script>')[0]
panel='<section data-va-payment-status-panel hidden tabindex="-1"><p data-va-payment-status-message></p><a data-va-payment-status-action hidden></a><button data-va-payment-status-refresh hidden>Actualizar</button></section>'
shell=shell.replace('</section></div>',panel+'</section></div>')
shell=shell.replace('<strong data-va-checkout-total>', '<span data-va-checkout-product-subtotal></span><span data-va-checkout-platform-fee></span><span data-va-checkout-delivery-fee></span><strong data-va-checkout-total>')
shell=shell.replace('<section data-va-delivery-fields hidden>', '<section data-va-delivery-fields hidden><input name="recipient_name">')
instrument='''window.metrics={started:0,completed:0,aborted:0,timers:new Set(),pending:new Set()};
const nativeFetch=window.fetch.bind(window),nativeSet=window.setTimeout.bind(window),nativeClear=window.clearTimeout.bind(window);
window.setTimeout=function(fn,ms,...args){let id=nativeSet(()=>{metrics.timers.delete(id);fn(...args)},ms);metrics.timers.add(id);return id};
window.clearTimeout=function(id){metrics.timers.delete(id);return nativeClear(id)};
window.fetch=function(...args){let id=++metrics.started;metrics.pending.add(id);return nativeFetch(...args).then(r=>{metrics.completed++;return r},e=>{if(e.name==='AbortError')metrics.aborted++;throw e}).finally(()=>metrics.pending.delete(id))};
window.VeciAhorra={restUrl:location.origin+'/api',locale:'es-CL',currency:'CLP',nonce:'local',currentUser:{id:1,loggedIn:true},cart:{sessionHeader:'X-Veciahorra-Cart-Session',sessionId:'local'},checkout:{minimumDeliveryAmount:8000},launch:{commerceEnabled:true}};
'''
class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_POST(self):self.rfile.read(int(self.headers.get('Content-Length','0')));self.do_GET()
    def do_GET(self):
        global poll
        path=urlsplit(self.path).path;status=200
        if path.startswith('/assets/'):
            body=(root/path.lstrip('/')).read_bytes();mime='application/javascript'
        elif path=='/':
            body=(shell+'<script>'+instrument+'</script><script src="/assets/frontend/js/veciahorra-frontend.js"></script><script src="/assets/frontend/js/veciahorra-checkout.js"></script>').encode();mime='text/html'
        elif path.startswith('/api/'):
            counts[path]=counts.get(path,0)+1;mime='application/json'
            if path=='/api/cart':value={'success':True,'data':[item],'summary':summary}
            elif path=='/api/checkout/validate':value={'success':True,'data':{'valid':True,'errors':[],'items':[item],'summary':summary}}
            elif path=='/api/checkout':
                value={'success':True,'data':{'valid':True,'order_created':True,'reservation_created':True,'orders':[{'id':1,'status':'reserved'}],'summary':summary,'checkout':{'checkout_id':checkout}}}
                if scenario=='closed_submit':status=503;value={'success':False,'error':{'code':'payment_temporarily_unavailable','message':'El pago se encuentra temporalmente no disponible.'}}
            elif path=='/api/payments/session':
                value={'success':True,'data':{'payment_session_id':'ps_'+'a'*43,'checkout_id':checkout,'status':'create_failed' if scenario=='create_failed' else 'create_ambiguous' if scenario in ['create_ambiguous','double_click'] else 'pending'}}
                if scenario=='closed_session':status=503;value={'success':False,'error':{'code':'payment_temporarily_unavailable','message':'El pago se encuentra temporalmente no disponible.'}}
            elif path.endswith('/payment-status'):
                poll+=1;pending=scenario=='pending' and poll==1;state='pending' if pending else 'failed' if scenario=='create_failed' else 'payment_approved' if scenario=='pending' else 'manual_review'
                value={'success':True,'data':{'payment_status':state,'terminal':not pending,'next_action':'wait' if pending else 'contact_support','poll_after_ms':1000 if pending else None,'message':'Esperando' if pending else 'No intentes pagar nuevamente.','redirect_url':None}}
            else:status=404;value={'error':{'code':'unexpected_route'}}
            if scenario=='http_error' and path.endswith('/payment-status') and poll==1:status=500;value={'success':False,'error':{'code':'local_http_error'}}
            body=json.dumps(value).encode()
        else:status=404;mime='text/plain';body=b''
        self.send_response(status);self.send_header('Content-Type',mime);self.send_header('Cache-Control','no-store');self.send_header('Content-Security-Policy',"default-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'none'; img-src 'self' data:");self.end_headers();self.wfile.write(body)
server=HTTPServer(('127.0.0.1',8328),Handler);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
profile=Path(tempfile.mkdtemp(prefix=identifier+'-chrome-',dir=task))
chrome=None;ws=None;seq=0;results=[]
def call(method,params=None):
    global seq
    seq+=1;ws.send(json.dumps({'id':seq,'method':method,'params':params or {}}))
    while True:
        x=json.loads(ws.recv())
        if x.get('id')==seq:
            if 'error'in x:raise RuntimeError(x['error'])
            return x.get('result',{})
def evaluate(expression):
    r=call('Runtime.evaluate',{'expression':expression,'returnByValue':True})
    if 'exceptionDetails'in r:raise RuntimeError(r['exceptionDetails'])
    return r.get('result',{}).get('value')
def wait_for(expression,timeout=12):
    end=time.monotonic()+timeout
    while time.monotonic()<end:
        if evaluate(expression):return
        time.sleep(.1)
    raise RuntimeError('Browser timeout: '+expression+' BODY='+str(evaluate('document.body.innerText')))
try:
    chrome=subprocess.Popen([r'C:\Program Files\Google\Chrome\Application\chrome.exe','--headless=new','--proxy-server=http://127.0.0.1:9','--proxy-bypass-list=127.0.0.1;localhost','--disable-quic','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-component-update','--disable-sync','--disable-extensions','--disable-default-apps','--metrics-recording-only','--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1','--remote-allow-origins=http://127.0.0.1:9328','--remote-debugging-address=127.0.0.1','--remote-debugging-port=9328','--user-data-dir='+str(profile),'about:blank'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env={**os.environ,'MINIMAL_RUNTIME_IDENTIFIER':identifier})
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for _ in range(80):
        try:targets=json.load(opener.open('http://127.0.0.1:9328/json'));break
        except Exception:time.sleep(.1)
    else:raise RuntimeError('Chrome startup failed')
    ws=websocket.create_connection(next(t['webSocketDebuggerUrl']for t in targets if t['type']=='page'),origin='http://127.0.0.1:9328',timeout=15,http_proxy_host=None)
    call('Page.enable');call('Runtime.enable')
    for scenario in ['create_failed','create_ambiguous','expired_ambiguous','pending','double_click','reload','closed_submit','closed_session','http_error']:
        counts={};poll=0;resume=scenario in ['expired_ambiguous','reload'];url='http://127.0.0.1:8328/?case='+scenario+('&checkout_id='+checkout if resume else '')
        call('Page.navigate',{'url':url});wait_for('!!document.querySelector("[data-va-checkout]")?.vaCheckout')
        if not resume:
            wait_for('document.querySelector("[data-va-checkout-content]").hidden===false')
            evaluate("(()=>{let f=document.querySelector('[data-va-checkout-form]');for(let [k,v] of Object.entries({first_name:'Local',last_name:'Test',phone:'+56900000000',email:'local@localhost.invalid'})){f.elements[k].value=v;f.elements[k].dispatchEvent(new Event('input',{bubbles:true}))}f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));"+("f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));" if scenario=='double_click' else '')+"})()")
        if scenario=='closed_submit':wait_for("document.querySelector('[data-va-checkout-validation-errors]').hidden===false")
        else:wait_for("document.querySelector('[data-va-payment-status-panel]').hidden===false && document.querySelector('[data-va-payment-status-message]').textContent!=='Esperando'")
        if scenario=='reload':
            call('Page.reload');wait_for("!!document.querySelector('[data-va-checkout]')?.vaCheckout && document.querySelector('[data-va-payment-status-panel]').hidden===false")
        wait_for('metrics.pending.size===0 && metrics.timers.size===0')
        before=dict(counts);time.sleep(3.5);assert before==counts,'Polling continued after terminal'
        metrics=evaluate('({started:metrics.started,completed:metrics.completed,aborted:metrics.aborted,timers:metrics.timers.size,pending:metrics.pending.size})')
        creates=counts.get('/api/payments/session',0);assert creates<2 and metrics['timers']==0 and metrics['pending']==0
        if scenario in ['create_ambiguous','expired_ambiguous','double_click','reload']:assert evaluate("document.querySelector('[data-va-payment-status-action]').hidden"),'Retry button exposed'
        if scenario in ['closed_submit','closed_session']:assert poll==0
        result={'case':scenario,'requests':sum(counts.values()),'polls':poll,'creates':creates,'metrics':metrics,'post_terminal_requests':0};results.append(result);print(json.dumps(result),flush=True)
    print('PASS minimal_browser cases='+str(len(results)))
finally:
    if ws:
        try:call('Browser.close')
        except Exception:pass
        ws.close()
    if chrome:
        try:chrome.wait(timeout=10)
        except subprocess.TimeoutExpired:chrome.kill();chrome.wait()
    server.shutdown();server.server_close();thread.join();shutil.rmtree(profile)
