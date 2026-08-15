"""Deterministic Phase 11 browser certification for purchase product lines."""

import base64
import hashlib
import json
import os
import re
import subprocess
import tempfile
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
PUBLIC_ID = "chk_" + "Q" * 43
DETAIL_PATH = LIST_PATH + "/" + PUBLIC_ID
IMAGE_PATH = SITE_PATH + "/wp-content/plugins/veciahorra/artifacts/catalog-32.2-mobile-375.png"
INTERCEPT_GET_ONLY = True


def check(value, message):
    if not value:
        raise AssertionError(message)


def php(source):
    run = subprocess.run([PHP], input=source, text=True, capture_output=True, timeout=45, check=False)
    check(run.returncode == 0, "PHP efÃ­mero fallÃ³: " + run.stderr[-500:])
    return run.stdout.strip()


def create_user():
    source = """<?php
require %s;if(strtolower((string)DB_HOST)!=='localhost'||parse_url(home_url('/'),PHP_URL_HOST)!=='localhost'){exit(2);}
$t=bin2hex(random_bytes(16));$p=bin2hex(random_bytes(32));$l='va_phase11_'.substr($t,0,22);$e=$l.'@example.test';
$id=wp_insert_user(['user_login'=>$l,'user_pass'=>$p,'user_email'=>$e,'display_name'=>'Cliente Fase 9','role'=>'customer']);
if(is_wp_error($id)){exit(4);}echo wp_json_encode(['id'=>(int)$id,'login'=>$l,'password'=>$p,'email'=>$e]);?>""" % json.dumps(WP_LOAD)
    user = json.loads(php(source)); check(user.get("id", 0) > 0, "Usuario incompleto."); return user


def delete_user(user):
    source = """<?php
require %s;require_once ABSPATH.'wp-admin/includes/user.php';$id=%d;$login=%s;$email=%s;
WP_Session_Tokens::get_instance($id)->destroy_all();wp_delete_user($id);clean_user_cache($id);global $wpdb;
$r=['id'=>get_userdata($id)===false,'login'=>get_user_by('login',$login)===false,'email'=>get_user_by('email',$email)===false,'usermeta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%%d",$id)),'tokens'=>count(WP_Session_Tokens::get_instance($id)->get_all())];
echo wp_json_encode($r);exit(($r['id']&&$r['login']&&$r['email']&&$r['usermeta']===0&&$r['tokens']===0)?0:5);?>""" % (json.dumps(WP_LOAD), user["id"], json.dumps(user["login"]), json.dumps(user["email"]))
    result = json.loads(php(source)); check(result == {"id": True, "login": True, "email": True, "usermeta": 0, "tokens": 0}, "Residuo de usuario.")


def fingerprint():
    source = """<?php
require %s;global $wpdb;$p=$wpdb->prefix.VeciAhorra\\Core\\Config::TABLE_PREFIX;$all=[];
foreach(['orders','checkouts','payments','deliveries','business_completions','delivery_completions','fulfillment_completions','service_zones','store_service_zones']as$t){$all[$t]=$wpdb->get_results("SELECT * FROM `{$p}{$t}` ORDER BY 1",ARRAY_A);}
$all['sector_meta']=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%%s ORDER BY user_id,umeta_id",'_veciahorra_service_zone_id'),ARRAY_A);echo hash('sha256',wp_json_encode($all));?>""" % json.dumps(WP_LOAD)
    value = php(source); check(re.fullmatch(r"[a-f0-9]{64}", value), "Fingerprint invÃ¡lido."); return value


def make_browser(profile, width=1440, height=1000):
    options = webdriver.ChromeOptions()
    for flag in ("--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"):
        options.add_argument(flag)
    options.add_argument(f"--window-size={width},{height}"); options.add_argument("--user-data-dir=" + profile.name)
    options.set_capability("acceptInsecureCerts", True); options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
    return webdriver.Chrome(service=Service(DRIVER), options=options)


INTERCEPT = r"""
(()=>{
 const nativeFetch=window.fetch.bind(window),listPath=%LIST%,detailPath=%DETAIL%,publicId=%PUBLIC%;
 window.__vaPhase11Calls=[];window.__vaPhase11Pending=[];
 const response=(payload,status=200)=>new Response(JSON.stringify(payload),{status,headers:{'Content-Type':'application/json; charset=UTF-8'}});
 const listItem=()=>({checkout_public_id:publicId,created_at:'2026-08-14T12:00:00Z',total:{amount:'999999999999.00',currency:'CLP'},product_quantity:1,order_count:0,minimarket_count:0,minimarkets:[],fulfillment_method:'pickup',visible_status:{code:'pending_payment',label:'Pendiente de pago',message:'Pendiente.'}});
 const payment=state=>{
  if(state==='null')return null;
  if(state==='pending-null')return {status:'pending',label:'Pago pendiente',amount:'999999999999.00',currency:'CLP',paid_at:null,method:null};
  if(state==='pending-method')return {status:'pending',label:'Pago pendiente',amount:'999999999999.00',currency:'CLP',paid_at:null,method:'Método público deliberadamente extenso'};
  if(state==='foreign')return {status:'received',label:'Pago recibido',amount:'1234567890.55',currency:'USD',paid_at:'2026-08-14T12:05:00Z',method:'Webpay Plus'};
  if(state==='invalid-type')return {status:'pending',label:'Pago pendiente',amount:123,currency:'CLP',paid_at:null,method:null};
  return {status:'received',label:'Pago recibido',amount:'999999999999.00',currency:'CLP',paid_at:'2026-08-14T12:05:00Z',method:'Webpay Plus'};
 };
 const envelope=state=>({success:true,data:{checkout_public_id:publicId,created_at:'2026-08-14T12:00:00Z',visible_status:{code:'pending_payment',label:'Pendiente de pago',message:'Detalle válido.'},requires_review:false,fulfillment:{method:'pickup',label:'Retiro'},summary:{subtotal:'999999999999.00',total:'999999999999.00',currency:'CLP',product_quantity:0,line_count:0,order_count:0,minimarket_count:0},orders:[],payment:payment(state),delivery:{method:'pickup',status:'not_applicable',label:'Retiro'},timeline:[]}});
 window.fetch=function(input,init){
  const raw=typeof input==='string'?input:input.url,url=new URL(raw,location.href),method=String((init&&init.method)||'GET').toUpperCase();
  if(url.origin!==location.origin||method!=='GET'||(url.pathname!==listPath&&url.pathname!==detailPath))return nativeFetch(input,init);
  const kind=url.pathname===listPath?'list':'detail';window.__vaPhase11Calls.push({method,path:url.pathname,kind});
  if(kind==='list')return Promise.resolve(response({success:true,data:[listItem()]}));
  const state=(document.cookie.match(/(?:^|; )va_phase11_state=([^;]+)/)||[])[1]||'received';
  if(state==='not-found')return Promise.resolve(response({success:false,error:{code:'customer_order_not_found'}},404));
  if(state==='http-error')return Promise.resolve(response({success:false,error:{code:'controlled'}},503));
  if(state==='network-error')return Promise.reject(new TypeError('controlled phase11 network failure'));
  if(state==='loading'||state==='stale')return new Promise(resolve=>window.__vaPhase11Pending.push(()=>resolve(response(envelope('received')))));
  return Promise.resolve(response(envelope(state)));
 };
})();
""".replace("%LIST%", json.dumps(LIST_PATH)).replace("%DETAIL%", json.dumps(DETAIL_PATH)).replace("%PUBLIC%", json.dumps(PUBLIC_ID))


def set_state(driver, value):
    driver.add_cookie({"name": "va_phase11_state", "value": value, "path": "/", "sameSite": "Lax"})


def open_list(driver, wait, value):
    set_state(driver, value); driver.get(BASE)
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
    return driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")


def enter(driver, wait, value):
    link = open_list(driver, wait, value); check(link.tag_name.lower() == "a", "Enlace no productivo."); link.click()
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__detail-title")) == 1)
    return link


def assert_payment(driver, expected=1):
    roots=driver.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]")
    check(len(roots)==expected,f"Cardinalidad Pago {len(roots)} != {expected}")
    if expected==0:return roots
    root=roots[0]
    check(root.tag_name.lower()=="section","Root Pago no es section.")
    check(root.get_attribute("class").split()==["veciahorra-frontend","va-design-system","va-customer-panel__detail-section","va-customer-panel__detail-payment"],"Clase literal Pago incorrecta.")
    check(len(root.find_elements(By.CSS_SELECTOR,":scope > h3.va-customer-panel__visual-heading"))==1,"Heading Pago ausente.")
    check(len(root.find_elements(By.CSS_SELECTOR,"svg.va-customer-panel__icon[aria-hidden='true']"))==1,"Icono Pago no decorativo.")
    check(not root.find_elements(By.CSS_SELECTOR,"button,a,form"),"Acción de pago añadida.")
    services=root.find_element(By.XPATH,"..")
    check("va-customer-panel__detail-services" in services.get_attribute("class").split() and not services.get_attribute("data-va-customer-panel-detail-payment"),"Root aplicada a services.")
    delivery=services.find_element(By.CSS_SELECTOR,":scope > .va-customer-panel__detail-delivery")
    check(not delivery.get_attribute("data-va-customer-panel-detail-payment") and not delivery.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"),"Entrega invadida.")
    for selector in ("[data-va-customer-panel-detail-overview]","[data-va-customer-panel-detail-item]","[data-va-customer-panel-detail-order-header]",".va-customer-panel__timeline"):
        for node in driver.find_elements(By.CSS_SELECTOR,selector):
            check(not node.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]") and not root.find_elements(By.CSS_SELECTOR,selector),"Raíz cerrada anidada.")
    return roots

def asset_hash(driver, entries):
    required = {"veciahorra-design-system.css", "veciahorra-frontend.css", "customer-panel.css", "veciahorra-frontend.js", "customer-panel.js"}; found={}; request_id=None
    for entry in entries:
        message=json.loads(entry["message"])["message"]
        if message.get("method") != "Network.responseReceived": continue
        params=message.get("params",{}); response=params.get("response",{}); url=response.get("url","").split("?",1)[0]
        for name in required:
            if url.endswith("/"+name) and int(response.get("status",0))==200:
                found[name]=response.get("mimeType",""); request_id=params.get("requestId") if name=="customer-panel.js" else request_id
    check(set(found)==required and "javascript" in found["customer-panel.js"].lower(), "Assets/MIME invÃ¡lidos.")
    body=driver.execute_cdp_cmd("Network.getResponseBody",{"requestId":request_id}); remote=base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode(); local=(ROOT/"assets/frontend/js/customer-panel.js").read_bytes()
    check(remote==local,"Asset remoto distinto."); return hashlib.sha256(local).hexdigest()


check(urlparse(BASE).hostname in {"localhost","127.0.0.1","::1"}, "Origen no local.")
check(Path(PHP).is_file() and Path(DRIVER).is_file(), "Herramienta ausente; SKIP prohibido.")
compile(Path(__file__).read_text(encoding="utf-8"), __file__, "exec")
before=fingerprint(); visitor_profile=tempfile.TemporaryDirectory(prefix="va-phase11-visitor-"); auth_profile=tempfile.TemporaryDirectory(prefix="va-phase11-auth-")
visitor=driver=None; user=None
result={"visitor":"PENDING","authentication":"PENDING","double_interception":"PENDING","payments":{},"states":{},"navigation":"PENDING","direct":"PENDING","accessibility":"PENDING","asset":"PENDING","cleanup":"PENDING","viewports":{}}
try:
    visitor=make_browser(visitor_profile);visitor.get(BASE);WebDriverWait(visitor,25).until(lambda d:d.execute_script("return document.readyState")=="complete")
    check(not visitor.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-mount]") and not visitor.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"),"Visitante expuesto.");result["visitor"]="PASS";visitor.quit();visitor=None;visitor_profile.cleanup()
    user=create_user();driver=make_browser(auth_profile);wait=WebDriverWait(driver,30);driver.execute_cdp_cmd("Network.enable",{});driver.execute_cdp_cmd("Runtime.enable",{})
    login=BASE.split("/mis-compras/",1)[0]+"/wp-login.php"
    for attempt in range(2):
        driver.get(login);driver.find_element(By.ID,"user_login").send_keys(user["login"]);driver.find_element(By.ID,"user_pass").send_keys(user["password"]);driver.find_element(By.ID,"wp-submit").click()
        try: WebDriverWait(driver,15).until(lambda d:any(c["name"].startswith("wordpress_logged_in_") for c in d.get_cookies()));break
        except TimeoutException:
            if attempt==1: raise AssertionError("Cookie real ausente.")
    driver.get(BASE);wait.until(lambda d:d.execute_script("return document.readyState")=="complete")
    check(driver.execute_script("return window.VeciAhorra.currentUser.loggedIn") is True and driver.execute_script("return window.VeciAhorra.currentUser.id")==user["id"],"Identidad real incorrecta.")
    check(len(driver.execute_script("return window.VeciAhorra.nonce"))>=10,"Nonce real ausente.")
    source=(ROOT/"assets/frontend/js/customer-panel.js").read_text(encoding="utf-8");check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript",{"expression":source,"sourceURL":"customer-panel.js","persistScript":False}),"CDP SyntaxError.")
    check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript",{"expression":INTERCEPT,"sourceURL":"phase11-intercept.js","persistScript":False}),"Interceptor invÃ¡lido.")
    driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument",{"source":INTERCEPT});result["authentication"]="PASS"

    enter(driver,wait,"loading");check(driver.find_element(By.CSS_SELECTOR,"[data-va-customer-panel-content] .va-loader").is_displayed(),"Loading no productivo.");wait.until(lambda d:d.execute_script("return window.__vaPhase11Pending.length===1"));check(not driver.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"),"Root durante loading.");driver.execute_script("window.__vaPhase11Pending.shift()()")
    wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"))==1);assert_payment(driver);result["states"]["loading"]="PASS";result["states"]["success"]="PASS"
    calls=driver.execute_script("return window.__vaPhase11Calls");check(calls[:2]==[{"method":"GET","path":LIST_PATH,"kind":"list"},{"method":"GET","path":DETAIL_PATH,"kind":"detail"}],"Intercepción no cerrada.");result["double_interception"]="PASS"
    result["sha256"]=asset_hash(driver,driver.get_log("performance"));result["asset"]="PASS"

    for state_name in ("null","pending-null","pending-method","received","foreign"):
        enter(driver,wait,state_name);wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"))==1);roots=assert_payment(driver);text=roots[0].text;result["payments"][state_name]="PASS"
        if state_name=="null": check("Información de pago no disponible" in text and not roots[0].find_elements(By.CSS_SELECTOR,"dl"),"payment=null alterado.")
        if state_name=="pending-null": check("Pago pendiente" in text and "Fecha de pago" not in text and "Método" not in text,"Opcionales nulos alterados.")
        if state_name=="pending-method": check("Pago pendiente" in text and "Método público" in text and "Fecha de pago" not in text,"Método pending alterado.")
        if state_name=="received":
            check("Pago recibido" in text and "Webpay Plus" in text and "Fecha de pago" in text and "$" in text,"Pago received/CLP alterado.")
            expected=driver.execute_script("return new Intl.DateTimeFormat(String(window.VeciAhorra.locale||'es-CL'),{dateStyle:'medium',timeStyle:'short',timeZone:String(window.VeciAhorra.timeZone||'UTC')}).format(new Date('2026-08-14T12:05:00Z'))")
            check(expected in text,"Fecha/timezone alterado.")
        if state_name=="foreign": check("1234567890.55 USD" in text and "Pago recibido" in text,"Moneda no CLP alterada.")

    for state_name,fragment in (("invalid-type","No pudimos cargar"),("not-found","La compra no est"),("http-error","No pudimos cargar"),("network-error","No pudimos cargar")):
        enter(driver,wait,state_name);wait.until(lambda d:fragment in d.find_element(By.CSS_SELECTOR,"[data-va-customer-panel-content]").text);assert_payment(driver,0);result["states"][state_name]="PASS"

    enter(driver,wait,"stale");wait.until(lambda d:d.execute_script("return window.__vaPhase11Pending.length===1"));driver.back();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,".va-customer-panel__purchase-link"))==1);set_state(driver,"one");driver.find_element(By.CSS_SELECTOR,".va-customer-panel__purchase-link").click();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"))==1);driver.execute_script("window.__vaPhase11Pending.shift()()");assert_payment(driver);result["states"]["stale_response"]="PASS"

    link=open_list(driver,wait,"one");driver.execute_script("window.scrollTo(0,100);arguments[0].scrollIntoView({block:'center'});",link);scroll=driver.execute_script("return window.scrollY");link.click();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"))==1);driver.back();wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,".va-customer-panel__purchase-link"))==1);restored=driver.find_element(By.CSS_SELECTOR,".va-customer-panel__purchase-link");wait.until(lambda d:d.execute_script("return document.activeElement===arguments[0]",restored));check(abs(driver.execute_script("return window.scrollY")-scroll)<=2,"Scroll no restaurado.");result["navigation"]="PASS"
    driver.get(BASE+"?compra="+PUBLIC_ID);wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"))==1);assert_payment(driver);check(driver.execute_script("return window.__vaPhase11Calls[0].kind")=="detail","Acceso directo incorrecto.");result["direct"]="PASS"
    for width,height in ((1440,1000),(375,812),(320,800)):
        driver.set_window_size(width,height);driver.get(BASE+"?compra="+PUBLIC_ID);wait.until(lambda d:len(d.find_elements(By.CSS_SELECTOR,"[data-va-customer-panel-detail-payment]"))==1);assert_payment(driver);check(driver.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"),"Overflow.");back=driver.find_element(By.CSS_SELECTOR,".va-customer-panel__back-link");rect=driver.execute_script("const r=arguments[0].getBoundingClientRect();return {w:r.width,h:r.height};",back);check(rect["w"]>=44 and rect["h"]>=44,"44x44 perdido.");result["viewports"][f"{width}x{height}"]="PASS"
    heading=driver.find_element(By.CSS_SELECTOR,".va-customer-panel__detail-title");check(driver.execute_script("return document.activeElement===arguments[0]",heading),"Foco incorrecto.");driver.find_element(By.TAG_NAME,"body").send_keys(Keys.TAB);announcer=driver.find_element(By.CSS_SELECTOR,"[data-va-customer-panel-announcer]");check(announcer.get_attribute("aria-live")=="polite","Live region alterada.");driver.execute_cdp_cmd("Emulation.setEmulatedMedia",{"features":[{"name":"prefers-reduced-motion","value":"reduce"}]});result["accessibility"]="PASS"
    severe=[e for e in driver.get_log("browser") if e.get("level") in {"SEVERE","ERROR"} and "controlled phase11 network failure" not in e.get("message","")];check(not [e for e in severe if any(x in e.get("message","") for x in ("SyntaxError","ReferenceError"))],"Consola severa.")
finally:
    if visitor is not None:
        try: visitor.quit()
        except Exception: pass
    if driver is not None:
        try: driver.delete_all_cookies();driver.quit()
        except Exception: pass
    if user is not None: delete_user(user)
    visitor_profile.cleanup();auth_profile.cleanup()
after=fingerprint();check(before==after,"Fingerprints cambiaron.");check(not Path(visitor_profile.name).exists() and not Path(auth_profile.name).exists(),"Perfil residual.");result["cleanup"]="PASS"
print(json.dumps({"status":"PASS",**result},ensure_ascii=False,sort_keys=True))
