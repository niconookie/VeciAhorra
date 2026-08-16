"""Deterministic Phase 14 browser certification for the purchase detail heading."""

import base64, hashlib, json, os, re, subprocess, tempfile
from pathlib import Path
from urllib.parse import urlparse
from selenium import webdriver
from selenium.common.exceptions import TimeoutException
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("VA_CUSTOMER_PURCHASES_TEST_URL", "https://localhost/Minimarket/mis-compras/")
PHP = os.environ.get("VA_PHP", r"C:\xampp\php\php.exe")
WP_LOAD = str(ROOT.parents[2] / "wp-load.php").replace("\\", "/")
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")
SITE_PATH = urlparse(BASE).path.split("/mis-compras/", 1)[0]
LIST_PATH = SITE_PATH + "/wp-json/veciahorra/v1/customer-panel/purchases"
PUBLIC_ID = "chk_" + "T" * 43
DETAIL_PATH = LIST_PATH + "/" + PUBLIC_ID
ROOT_SELECTOR = "[data-va-customer-panel-detail-heading]"

def check(value, message):
    if not value: raise AssertionError(message)

def wait_for_stable_scroll(driver, timeout_seconds):
    driver.set_script_timeout(timeout_seconds + 1)
    result = driver.execute_async_script(r"""
const timeoutMs=arguments[0]*1000,done=arguments[arguments.length-1],started=performance.now(),samples=[];
let previous=null,stable=0,confirming=false,finished=false;
const snapshot=()=>{const root=document.documentElement,body=document.body,y=window.scrollY,scrollHeight=Math.max(root?root.scrollHeight:0,body?body.scrollHeight:0),innerHeight=window.innerHeight,maxScroll=Math.max(0,scrollHeight-innerHeight),active=document.activeElement;return {y,scrollHeight,innerHeight,maxScroll,active:active?{tag:active.tagName.toLowerCase(),className:String(active.className||'')}:null};};
const finish=(ok,reason)=>{if(finished)return;finished=true;done({ok,reason,timeout:arguments[0],sampleCount:samples.length,samples:samples.slice(-12),...snapshot()});};
const frame=()=>{if(finished)return;if(performance.now()-started>=timeoutMs){finish(false,'timeout');return;}if(!document.querySelector('.va-customer-panel__purchase-link,.va-customer-panel__list-title')){requestAnimationFrame(frame);return;}const current=snapshot();samples.push({t:Math.round(performance.now()-started),y:current.y});if(previous!==null&&Math.abs(current.y-previous)<=0.5)stable+=1;else stable=0;previous=current.y;if(stable>=2&&!confirming){confirming=true;setTimeout(()=>requestAnimationFrame(()=>{const confirmation=snapshot();samples.push({t:Math.round(performance.now()-started),y:confirmation.y});if(Math.abs(confirmation.y-previous)<=0.5)finish(true,'stable');else{previous=confirmation.y;stable=0;confirming=false;requestAnimationFrame(frame);}}),50);return;}requestAnimationFrame(frame);};
requestAnimationFrame(frame);
""", timeout_seconds)
    check(result.get("ok") is True, "Scroll no estable: " + json.dumps(result, ensure_ascii=False))
    return result

def php(source):
    run = subprocess.run([PHP], input=source, text=True, capture_output=True, timeout=45, check=False)
    check(run.returncode == 0, "PHP efimero fallo: " + run.stderr[-500:])
    return run.stdout.strip()

def create_user():
    source = """<?php
require %s;if(strtolower((string)DB_HOST)!=='localhost'||parse_url(home_url('/'),PHP_URL_HOST)!=='localhost'){exit(2);}
$t=bin2hex(random_bytes(16));$p=bin2hex(random_bytes(32));$l='va_phase13_'.substr($t,0,22);$e=$l.'@example.test';
$id=wp_insert_user(['user_login'=>$l,'user_pass'=>$p,'user_email'=>$e,'display_name'=>'Cliente Fase 13','role'=>'customer']);
if(is_wp_error($id)){exit(4);}echo wp_json_encode(['id'=>(int)$id,'login'=>$l,'password'=>$p,'email'=>$e]);?>""" % json.dumps(WP_LOAD)
    user = json.loads(php(source)); check(user.get("id", 0) > 0, "Usuario temporal incompleto."); return user

def delete_user(user):
    source = """<?php
require %s;require_once ABSPATH.'wp-admin/includes/user.php';$id=%d;$login=%s;$email=%s;
WP_Session_Tokens::get_instance($id)->destroy_all();wp_delete_user($id);clean_user_cache($id);global $wpdb;
$r=['id'=>get_userdata($id)===false,'login'=>get_user_by('login',$login)===false,'email'=>get_user_by('email',$email)===false,'usermeta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%%d",$id)),'tokens'=>count(WP_Session_Tokens::get_instance($id)->get_all())];
echo wp_json_encode($r);exit(($r['id']&&$r['login']&&$r['email']&&$r['usermeta']===0&&$r['tokens']===0)?0:5);?>""" % (json.dumps(WP_LOAD), user["id"], json.dumps(user["login"]), json.dumps(user["email"]))
    check(json.loads(php(source)) == {"id":True,"login":True,"email":True,"usermeta":0,"tokens":0}, "Residuo de usuario.")

def fingerprint():
    source = r"""<?php
require %s;global $wpdb;$p=$wpdb->prefix.VeciAhorra\Core\Config::TABLE_PREFIX;$all=[];
foreach(['orders','checkouts','payments','deliveries','business_completions','delivery_completions','fulfillment_completions','service_zones','store_service_zones']as$t){$all[$t]=$wpdb->get_results("SELECT * FROM `{$p}{$t}` ORDER BY 1",ARRAY_A);}
$all['sector_meta']=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%%s ORDER BY user_id,umeta_id",'_veciahorra_service_zone_id'),ARRAY_A);echo hash('sha256',wp_json_encode($all));?>""" % json.dumps(WP_LOAD)
    value = php(source); check(re.fullmatch(r"[a-f0-9]{64}", value), "Fingerprint invalido."); return value

def browser(profile, width=1440, height=1000):
    options = webdriver.ChromeOptions()
    for flag in ("--headless=new","--ignore-certificate-errors","--allow-insecure-localhost","--disable-gpu","--no-sandbox"): options.add_argument(flag)
    options.add_argument(f"--window-size={width},{height}"); options.add_argument("--user-data-dir=" + profile.name)
    options.set_capability("acceptInsecureCerts", True); options.set_capability("goog:loggingPrefs", {"browser":"ALL","performance":"ALL"})
    return webdriver.Chrome(service=Service(DRIVER), options=options)

INTERCEPT = r"""
(()=>{const nativeFetch=window.fetch.bind(window),listPath=%LIST%,detailPath=%DETAIL%,publicId=%PUBLIC%;
window.__vaPhase13Calls=[];window.__vaPhase13Pending=[];
const response=(payload,status=200)=>new Response(JSON.stringify(payload),{status,headers:{'Content-Type':'application/json; charset=UTF-8'}});
const events={checkout_created:{code:'checkout_created',label:'Compra creada',occurred_at:'2026-08-15T10:00:00Z'},payment_confirmed:{code:'payment_confirmed',label:'Pago confirmado',occurred_at:'2026-08-15T10:01:00Z'},payment_reconciled:{code:'payment_reconciled',label:'Pago conciliado',occurred_at:'2026-08-15T10:02:00Z'},orders_materialized:{code:'orders_materialized',label:'Pedidos preparados en el sistema',occurred_at:'2026-08-15T10:03:00Z'},delivery_created:{code:'delivery_created',label:'Despacho creado',occurred_at:'2026-08-15T10:04:00Z'}};
const listItem=()=>({checkout_public_id:publicId,created_at:'2026-08-15T10:00:00Z',total:{amount:'1000.00',currency:'CLP'},product_quantity:0,order_count:0,minimarket_count:0,minimarkets:[],fulfillment_method:'delivery',visible_status:{code:'preparing_delivery',label:'Preparando despacho',message:'Preparando.'}});
const timeline=state=>{if(state==='empty')return [];if(events[state])return [events[state]];if(state==='unknown')return [{code:'future_public_event',label:'Evento futuro',occurred_at:'2026-08-15T11:00:00Z'}];if(state==='invalid_date')return [{...events.checkout_created,occurred_at:'invalid-date'}];if(state==='invalid_type')return [{...events.checkout_created,occurred_at:null}];if(state==='message')return [{...events.checkout_created,message:'Mensaje publico opcional'}];if(state==='long')return [{...events.checkout_created,label:'Etiqueta '.repeat(30),message:'Mensaje '.repeat(40)}];if(state==='duplicates')return [events.checkout_created,events.delivery_created,{...events.delivery_created,occurred_at:'2026-08-15T10:05:00Z'}];if(state==='ordered')return [events.delivery_created,events.checkout_created,events.orders_materialized];return Object.values(events);};
const envelope=state=>({success:true,data:{checkout_public_id:publicId,created_at:'2026-08-15T10:00:00Z',visible_status:{code:'preparing_delivery',label:'Preparando despacho',message:'Detalle valido.'},requires_review:false,fulfillment:{method:'delivery',label:'Despacho'},summary:{subtotal:'1000.00',total:'1000.00',currency:'CLP',product_quantity:0,line_count:0,order_count:0,minimarket_count:0},orders:[],payment:null,delivery:{method:'delivery',status:'preparing_delivery',label:'Despacho'},timeline:timeline(state)}});
window.fetch=function(input,init){const raw=typeof input==='string'?input:input.url,url=new URL(raw,location.href),method=String((init&&init.method)||'GET').toUpperCase();if(url.origin!==location.origin||method!=='GET'||(url.pathname!==listPath&&url.pathname!==detailPath))return nativeFetch(input,init);const kind=url.pathname===listPath?'list':'detail';window.__vaPhase13Calls.push({method,path:url.pathname,kind});if(kind==='list')return Promise.resolve(response({success:true,data:[listItem()]}));const state=(document.cookie.match(/(?:^|; )va_phase13_state=([^;]+)/)||[])[1]||'all';if(state==='not_found')return Promise.resolve(response({success:false,error:{code:'customer_order_not_found'}},404));if(state==='http_error')return Promise.resolve(response({success:false,error:{code:'controlled'}},503));if(state==='network_error')return Promise.reject(new TypeError('controlled phase13 network failure'));if(state==='loading'||state==='stale')return new Promise(resolve=>window.__vaPhase13Pending.push(()=>resolve(response(envelope('all')))));return Promise.resolve(response(envelope(state)));};})();
""".replace("%LIST%",json.dumps(LIST_PATH)).replace("%DETAIL%",json.dumps(DETAIL_PATH)).replace("%PUBLIC%",json.dumps(PUBLIC_ID))

def state(driver, value): driver.add_cookie({"name":"va_phase13_state","value":value,"path":"/","sameSite":"Lax"})
def open_list(driver, wait, value):
    state(driver,value); driver.get(BASE); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR,".va-customer-panel__purchase-link"))==1); return driver.find_element(By.CSS_SELECTOR,".va-customer-panel__purchase-link")
def enter(driver, wait, value):
    link=open_list(driver,wait,value); check(urlparse(link.get_attribute("href")).query=="compra="+PUBLIC_ID,"GET detalle no canonico."); link.click()

def assert_heading(driver, expected=1):
    roots=driver.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR);check(len(roots)==expected,f"Cardinalidad encabezado {len(roots)} != {expected}")
    if not expected:return None
    root=roots[0];check(root.tag_name.lower()=="div","Raiz no div.")
    check(root.get_attribute("class").split()==["veciahorra-frontend","va-design-system","va-customer-panel__detail-heading-row"],"Clase literal incorrecta.")
    headings=root.find_elements(By.CSS_SELECTOR,":scope > h2.va-customer-panel__visual-heading.va-customer-panel__detail-title");links=root.find_elements(By.CSS_SELECTOR,":scope > a.va-customer-panel__back-link")
    check(len(headings)==1 and headings[0].text=="Detalle de compra" and headings[0].get_attribute("tabindex")=="-1","Contrato titulo.")
    check(len(headings[0].find_elements(By.CSS_SELECTOR,":scope > svg.va-customer-panel__icon[aria-hidden='true'][focusable='false']"))==1,"Icono ausente.")
    check(len(links)==1 and links[0].text=="Volver a mis compras","Contrato enlace.")
    check(len(root.find_elements(By.CSS_SELECTOR,":scope > *"))==2 and not root.find_elements(By.CSS_SELECTOR,"button,form"),"Descendientes no cerrados.")
    for selector in ("[data-va-customer-panel-detail-overview]","[data-va-customer-panel-detail-item]","[data-va-customer-panel-detail-order-header]","[data-va-customer-panel-detail-payment]","[data-va-customer-panel-detail-delivery]"):
        for node in driver.find_elements(By.CSS_SELECTOR,selector):check(not node.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR) and not root.find_elements(By.CSS_SELECTOR,selector),"Raices anidadas.")
    for node in driver.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-timeline]"):check(not node.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR) and not root.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-timeline]"),"Timeline anidado.")
    return root

def asset_hash(driver, entries):
    required={"veciahorra-design-system.css","veciahorra-frontend.css","customer-panel.css","veciahorra-frontend.js","customer-panel.js"};found={};request_id=None
    for entry in entries:
        message=json.loads(entry["message"])["message"]
        if message.get("method")!="Network.responseReceived":continue
        params=message.get("params",{});response=params.get("response",{});url=response.get("url","").split("?",1)[0]
        for name in required:
            if url.endswith("/"+name) and int(response.get("status",0))==200:found[name]=response.get("mimeType","");request_id=params.get("requestId") if name=="customer-panel.js" else request_id
    check(set(found)==required and "javascript" in found["customer-panel.js"].lower(),"HTTP/MIME assets.")
    body=driver.execute_cdp_cmd("Network.getResponseBody",{"requestId":request_id});remote=base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode();local=(ROOT/"assets/frontend/js/customer-panel.js").read_bytes();check(remote==local,"JS remoto distinto.");return hashlib.sha256(local).hexdigest()

check(urlparse(BASE).hostname in {"localhost","127.0.0.1","::1"},"Origen no local.");check(Path(PHP).is_file() and Path(DRIVER).is_file(),"Herramienta ausente; SKIP prohibido.")
compile(Path(__file__).read_text(encoding="utf-8"),__file__,"exec");before=fingerprint();vp=tempfile.TemporaryDirectory(prefix="va-phase13-visitor-");ap=tempfile.TemporaryDirectory(prefix="va-phase13-auth-");visitor=driver=None;user=None
result={"visitor":"PENDING","authentication":"PENDING","interception":"PENDING","states":{},"events":{},"navigation":"PENDING","direct":"PENDING","accessibility":"PENDING","asset":"PENDING","cleanup":"PENDING","viewports":{}}
try:
    visitor=browser(vp);visitor.get(BASE);WebDriverWait(visitor,25).until(lambda d:d.execute_script("return document.readyState")=="complete");check(not visitor.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR),"Visitante expuesto.");result["visitor"]="PASS";visitor.quit();visitor=None;vp.cleanup()
    user=create_user();driver=browser(ap);wait=WebDriverWait(driver,30);driver.execute_cdp_cmd("Network.enable",{});driver.execute_cdp_cmd("Runtime.enable",{})
    login=BASE.split("/mis-compras/",1)[0]+"/wp-login.php"
    for attempt in range(2):
        driver.get(login);driver.find_element(By.ID,"user_login").send_keys(user["login"]);driver.find_element(By.ID,"user_pass").send_keys(user["password"]);driver.find_element(By.ID,"wp-submit").click()
        try:WebDriverWait(driver,15).until(lambda d:any(c["name"].startswith("wordpress_logged_in_") for c in d.get_cookies()));break
        except TimeoutException:
            if attempt==1:raise AssertionError("Cookie real ausente.")
    driver.get(BASE);wait.until(lambda d:d.execute_script("return document.readyState")=="complete");check(driver.execute_script("return window.VeciAhorra.currentUser.loggedIn") is True and driver.execute_script("return window.VeciAhorra.currentUser.id")==user["id"],"Identidad real.");check(len(driver.execute_script("return window.VeciAhorra.nonce"))>=10,"Nonce ausente.")
    source=(ROOT/"assets/frontend/js/customer-panel.js").read_text(encoding="utf-8");check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript",{"expression":source,"sourceURL":"customer-panel.js","persistScript":False}),"JS invalido.");check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript",{"expression":INTERCEPT,"sourceURL":"phase13-intercept.js","persistScript":False}),"Interceptor invalido.");driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument",{"source":INTERCEPT});result["authentication"]="PASS"
    enter(driver,wait,"loading");wait.until(lambda d:d.execute_script("return window.__vaPhase13Pending.length===1"));check(not driver.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR),"Loading invadido.");driver.execute_script("window.__vaPhase13Pending.shift()()");wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR))==1);assert_heading(driver);result["states"]["loading"]="PASS"
    calls=driver.execute_script("return window.__vaPhase13Calls");check(calls[:2]==[{"method":"GET","path":LIST_PATH,"kind":"list"},{"method":"GET","path":DETAIL_PATH,"kind":"detail"}] and all(c["method"]=="GET" and c["path"] in {LIST_PATH,DETAIL_PATH} for c in calls),"Intercepcion no cerrada.");result["interception"]="PASS";result["sha256"]=asset_hash(driver,driver.get_log("performance"));result["asset"]="PASS"
    enter(driver,wait,"all");wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR))==1);root=assert_heading(driver);back=root.find_element(By.CSS_SELECTOR,":scope > a");check(urlparse(back.get_attribute("href")).query=="" and urlparse(back.get_attribute("href")).fragment=="","Retorno no canonico.");result["states"]["success"]="PASS"
    enter(driver,wait,"invalid_type");wait.until(lambda d:"No pudimos cargar" in d.find_element(By.CSS_SELECTOR,"[data-va-customer-panel-content]").text);assert_heading(driver,0);result["states"]["invalid_type"]="PASS"
    for value,fragment in (("not_found","La compra no est"),("http_error","No pudimos cargar"),("network_error","No pudimos cargar")):
        enter(driver,wait,value);wait.until(lambda d:fragment in d.find_element(By.CSS_SELECTOR,"[data-va-customer-panel-content]").text);assert_heading(driver,0);result["states"][value]="PASS"
    enter(driver,wait,"stale");wait.until(lambda d:d.execute_script("return window.__vaPhase13Pending.length===1"));driver.back();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,".va-customer-panel__purchase-link"))==1);state(driver,"all");driver.find_element(By.CSS_SELECTOR,".va-customer-panel__purchase-link").click();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR))==1);driver.execute_script("window.__vaPhase13Pending.shift()()");assert_heading(driver);result["states"]["stale"]="PASS"
    link=open_list(driver,wait,"all");driver.execute_script("window.scrollTo(0,100);arguments[0].scrollIntoView({block:'center'});",link);before_scroll=wait_for_stable_scroll(driver,5);scroll=before_scroll["y"];check(0<=scroll<=before_scroll["maxScroll"],"Scroll esperado fuera de rango: "+json.dumps(before_scroll,ensure_ascii=False));pre_click=wait_for_stable_scroll(driver,5);check(abs(pre_click["y"]-scroll)<=0.5,"Scroll cambio antes del clic: "+json.dumps({"expected":scroll,"confirmation":pre_click},ensure_ascii=False));link.click();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR))==1);driver.back();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,".va-customer-panel__purchase-link"))==1);restored=driver.find_element(By.CSS_SELECTOR,".va-customer-panel__purchase-link");wait.until(lambda d:d.execute_script("return document.activeElement===arguments[0]",restored));after_scroll=wait_for_stable_scroll(driver,5);difference=abs(after_scroll["y"]-scroll);check(difference<=2,"Scroll no restaurado: "+json.dumps({"expectedStableY":scroll,"restoredStableY":after_scroll["y"],"difference":difference,"maxScroll":after_scroll["maxScroll"],"scrollHeight":after_scroll["scrollHeight"],"innerHeight":after_scroll["innerHeight"],"sampleCount":after_scroll["sampleCount"],"samples":after_scroll["samples"],"active":after_scroll["active"],"timeout":after_scroll["timeout"]},ensure_ascii=False));result["scroll"]={"pre_click":before_scroll,"pre_click_confirmation":pre_click,"post_back":after_scroll,"difference":difference,"tolerance":2,"helper":"exercised"};result["navigation"]="PASS"
    link=open_list(driver,wait,"all");link.send_keys(Keys.ENTER);wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR))==1);root=assert_heading(driver);root.find_element(By.CSS_SELECTOR,"a.va-customer-panel__back-link").click();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,".va-customer-panel__purchase-link"))==1);result["return_click"]="PASS";result["enter"]="PASS"
    driver.get(BASE+"?compra="+PUBLIC_ID);wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR))==1);root=assert_heading(driver);root.find_element(By.CSS_SELECTOR,"a.va-customer-panel__back-link").click();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,".va-customer-panel__list-title"))==1);fallback=driver.find_element(By.CSS_SELECTOR,".va-customer-panel__list-title");wait.until(lambda d:d.execute_script("return document.activeElement===arguments[0]",fallback));wait_for_stable_scroll(driver,5);result["direct"]="PASS";result["fallback"]="PASS"
    for width,height in ((1440,1000),(375,812),(320,800)):
        driver.set_window_size(width,height);state(driver,"long");driver.get(BASE+"?compra="+PUBLIC_ID);wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,ROOT_SELECTOR))==1);root=assert_heading(driver);check(root.is_displayed() and driver.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"),"Overflow.");back=root.find_element(By.CSS_SELECTOR,"a.va-customer-panel__back-link");check(back.rect["width"]>=44 and back.rect["height"]>=44,"Control menor 44.");check(driver.execute_script("return document.activeElement.classList.contains('va-customer-panel__detail-title')"),"Foco inicial.");result["viewports"][f"{width}x{height}"]="PASS"
    driver.find_element(By.TAG_NAME,"body").send_keys(Keys.TAB);check(driver.find_element(By.CSS_SELECTOR,"[data-va-customer-panel-announcer]").get_attribute("aria-live")=="polite","Live region.");driver.execute_cdp_cmd("Emulation.setEmulatedMedia",{"features":[{"name":"prefers-reduced-motion","value":"reduce"}]});result["accessibility"]="PASS"
finally:
    if visitor is not None:
        try:visitor.quit()
        except Exception:pass
    if driver is not None:
        try:driver.delete_all_cookies();driver.quit()
        except Exception:pass
    if user is not None:delete_user(user)
    vp.cleanup();ap.cleanup()
after=fingerprint();check(before==after,"Fingerprints cambiaron.");check(not Path(vp.name).exists() and not Path(ap.name).exists(),"Perfil residual.");check(not list(ROOT.rglob("*.pyc")),"Bytecode residual.");result["cleanup"]="PASS";print(json.dumps({"status":"PASS",**result},ensure_ascii=False,sort_keys=True))
