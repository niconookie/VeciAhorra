"""Deterministic Phase 8 browser certification for the purchase overview."""

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
PUBLIC_ID = "chk_" + "P" * 43
DETAIL_PATH = LIST_PATH + "/" + PUBLIC_ID
INTERCEPT_GET_ONLY = True


def check(value, message):
    if not value:
        raise AssertionError(message)


def local_url(value):
    parsed = urlparse(value)
    return parsed.scheme in {"http", "https"} and parsed.hostname in {"localhost", "127.0.0.1", "::1"}


def php(source):
    run = subprocess.run([PHP], input=source, text=True, capture_output=True, timeout=45, check=False)
    check(run.returncode == 0, "PHP efímero falló: " + run.stderr[-500:])
    return run.stdout.strip()


def create_user():
    source = """<?php
require %s;if(strtolower((string)DB_HOST)!=='localhost'||parse_url(home_url('/'),PHP_URL_HOST)!=='localhost'){exit(2);}
if(get_role('customer')===null){exit(3);}$t=bin2hex(random_bytes(16));$p=bin2hex(random_bytes(32));
$l='va_phase8_'.substr($t,0,22);$e=$l.'@example.test';$id=wp_insert_user(['user_login'=>$l,'user_pass'=>$p,'user_email'=>$e,'display_name'=>'Cliente Fase 8','role'=>'customer']);
if(is_wp_error($id)){exit(4);}echo wp_json_encode(['id'=>(int)$id,'login'=>$l,'password'=>$p,'email'=>$e]);?>""" % json.dumps(WP_LOAD)
    value = json.loads(php(source))
    check(value.get("id", 0) > 0, "Usuario temporal incompleto.")
    return value


def delete_user(user):
    source = """<?php
require %s;require_once ABSPATH.'wp-admin/includes/user.php';$id=%d;$login=%s;$email=%s;
if($id>0){WP_Session_Tokens::get_instance($id)->destroy_all();wp_delete_user($id);clean_user_cache($id);}global $wpdb;
$r=['id'=>get_userdata($id)===false,'login'=>get_user_by('login',$login)===false,'email'=>get_user_by('email',$email)===false,'usermeta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%%d",$id)),'tokens'=>count(WP_Session_Tokens::get_instance($id)->get_all())];
echo wp_json_encode($r);exit(($r['id']&&$r['login']&&$r['email']&&$r['usermeta']===0&&$r['tokens']===0)?0:5);?>""" % (json.dumps(WP_LOAD), user["id"], json.dumps(user["login"]), json.dumps(user["email"]))
    residue = json.loads(php(source))
    check(residue == {"id": True, "login": True, "email": True, "usermeta": 0, "tokens": 0}, "Residuo: " + repr(residue))


def fingerprint():
    source = """<?php
require %s;global $wpdb;$p=$wpdb->prefix.VeciAhorra\\Core\\Config::TABLE_PREFIX;
$tables=['orders','checkouts','payments','deliveries','business_completions','delivery_completions','fulfillment_completions','service_zones','store_service_zones'];$all=[];
foreach($tables as$t){$all[$t]=$wpdb->get_results("SELECT * FROM `{$p}{$t}` ORDER BY 1",ARRAY_A);}
$all['sector_meta']=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%%s ORDER BY user_id,umeta_id",'_veciahorra_service_zone_id'),ARRAY_A);
echo hash('sha256',wp_json_encode($all));?>""" % json.dumps(WP_LOAD)
    value = php(source)
    check(re.fullmatch(r"[a-f0-9]{64}", value), "Fingerprint inválido.")
    return value


def make_browser(profile, width=1440, height=1000):
    options = webdriver.ChromeOptions()
    for flag in ("--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"):
        options.add_argument(flag)
    options.add_argument(f"--window-size={width},{height}")
    options.add_argument("--user-data-dir=" + profile.name)
    options.set_capability("acceptInsecureCerts", True)
    options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
    return webdriver.Chrome(service=Service(DRIVER), options=options)


INTERCEPT = r"""
(()=>{
 const nativeFetch=window.fetch.bind(window),listPath=%LIST%,detailPath=%DETAIL%,publicId=%PUBLIC%;
 window.__vaPhase8Calls=[];window.__vaPhase8Pending=[];
 const response=(payload,status=200)=>new Response(JSON.stringify(payload),{status,headers:{'Content-Type':'application/json; charset=UTF-8'}});
 const listItem=()=>({checkout_public_id:publicId,created_at:'2026-08-14T12:00:00Z',total:{amount:'9876543210.00',currency:'CLP'},product_quantity:3,order_count:2,minimarket_count:2,minimarkets:['Minimarket de prueba visual'],fulfillment_method:'delivery',visible_status:{code:'preparing_delivery',label:'Preparando despacho',message:'Tus productos se están preparando para despacho.'}});
 const item=(name,q=1)=>({name,name_historical:false,image:null,image_historical:false,quantity:q,unit_price:'1234567890.00',subtotal:'2469135780.00'});
 const detail=()=>({checkout_public_id:publicId,created_at:'2026-08-14T12:00:00Z',visible_status:{code:'preparing_delivery',label:'Preparando despacho con una etiqueta deliberadamente extensa',message:'Información visible extensa para comprobar wrapping sin alterar el contrato funcional.'},requires_review:false,fulfillment:{method:'delivery',label:'Despacho'},summary:{subtotal:'9876543210.00',total:'9876543210.00',currency:'CLP',product_quantity:3,line_count:3,order_count:2,minimarket_count:2},orders:[{minimarket:{name:'Minimarket Uno',historical:false},subtotal:'4938271605.00',items:[item('Producto uno con nombre extraordinariamente extenso',2),item('Producto dos')]},{minimarket:{name:'Minimarket Dos',historical:false},subtotal:'4938271605.00',items:[item('Producto tres')]}],payment:{status:'received',label:'Pago recibido',amount:'9876543210.00',currency:'CLP',paid_at:'2026-08-14T12:05:00Z',method:'Webpay Plus'},delivery:{method:'delivery',status:'preparing_delivery',label:'Despacho'},timeline:[{code:'checkout_created',label:'Compra creada',occurred_at:'2026-08-14T12:00:00Z'}]});
 window.fetch=function(input,init){
  const raw=typeof input==='string'?input:input.url,url=new URL(raw,location.href),method=String((init&&init.method)||'GET').toUpperCase();
  if(url.origin!==location.origin||method!=='GET'||(url.pathname!==listPath&&url.pathname!==detailPath))return nativeFetch(input,init);
  const kind=url.pathname===listPath?'list':'detail';window.__vaPhase8Calls.push({method,path:url.pathname,kind});
  if(kind==='list')return Promise.resolve(response({success:true,data:[listItem()]}));
  const state=(document.cookie.match(/(?:^|; )va_phase8_state=([^;]+)/)||[])[1]||'success';
  if(state==='http-error')return Promise.resolve(response({success:false,error:{code:'controlled',message:'Error controlado'}},503));
  if(state==='not-found')return Promise.resolve(response({success:false,error:{code:'customer_order_not_found',message:'La compra no está disponible.'}},404));
  if(state==='network-error')return Promise.reject(new TypeError('controlled phase8 network failure'));
  if(state==='invalid')return Promise.resolve(response({success:true,data:{checkout_public_id:'invalid'}}));
  if(state==='loading'||state==='stale')return new Promise(resolve=>window.__vaPhase8Pending.push(()=>resolve(response({success:true,data:detail()}))));
  return Promise.resolve(response({success:true,data:detail()}));
 };
})();
""".replace("%LIST%", json.dumps(LIST_PATH)).replace("%DETAIL%", json.dumps(DETAIL_PATH)).replace("%PUBLIC%", json.dumps(PUBLIC_ID))


def state(driver, value):
    driver.add_cookie({"name": "va_phase8_state", "value": value, "path": "/", "sameSite": "Lax"})


def open_list(driver, wait, value="success"):
    state(driver, value)
    driver.get(BASE)
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
    return driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")


def assert_overview(driver):
    roots = driver.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]")
    check(len(roots) == 1, "Raíz overview ausente o duplicada.")
    check(roots[0].get_attribute("class").split() == ["veciahorra-frontend", "va-design-system", "va-customer-panel__detail-overview", "va-customer-panel__detail-primary-card"], "Clase literal incorrecta.")
    check(len(roots[0].find_elements(By.CSS_SELECTOR, ":scope > .va-customer-panel__detail-header")) == 1, "Header fuera de raíz.")
    check(len(roots[0].find_elements(By.CSS_SELECTOR, ":scope > .va-customer-panel__detail-summary")) == 1, "Resumen fuera de raíz.")
    for selector in (".va-customer-panel__detail-orders-section", ".va-customer-panel__detail-services", ".va-customer-panel__timeline"):
        check(len(roots[0].find_elements(By.CSS_SELECTOR, selector)) == 0, "Raíz alcanzó hermano " + selector)
    return roots[0]


def logs(driver):
    return driver.get_log("performance")


def asset_evidence(driver, entries):
    required = {"veciahorra-design-system.css", "veciahorra-frontend.css", "customer-panel.css", "veciahorra-frontend.js", "customer-panel.js"}
    found, panel_request = {}, None
    for entry in entries:
        message = json.loads(entry["message"])["message"]
        if message.get("method") != "Network.responseReceived":
            continue
        params = message.get("params", {}); response = params.get("response", {}); url = response.get("url", "").split("?", 1)[0]
        for name in required:
            if url.endswith("/" + name) and int(response.get("status", 0)) == 200:
                found[name] = response.get("mimeType", "")
                if name == "customer-panel.js": panel_request = params.get("requestId")
    check(set(found) == required, "Assets incompletos: " + repr(found))
    check("javascript" in found["customer-panel.js"].lower(), "MIME JS inválido.")
    body = driver.execute_cdp_cmd("Network.getResponseBody", {"requestId": panel_request})
    remote = base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode()
    local = (ROOT / "assets/frontend/js/customer-panel.js").read_bytes()
    check(remote == local, "Asset remoto distinto.")
    return hashlib.sha256(local).hexdigest()


check(local_url(BASE), "Origen no local.")
check(Path(PHP).is_file() and Path(DRIVER).is_file(), "Herramienta local ausente; SKIP prohibido.")
compile(Path(__file__).read_text(encoding="utf-8"), __file__, "exec")
before = fingerprint()
visitor_profile = tempfile.TemporaryDirectory(prefix="va-phase8-visitor-")
auth_profile = tempfile.TemporaryDirectory(prefix="va-phase8-auth-")
visitor = driver = None
user = None
result = {"visitor": "PENDING", "authentication": "PENDING", "double_interception": "PENDING", "states": {}, "navigation": "PENDING", "direct": "PENDING", "accessibility": "PENDING", "asset": "PENDING", "cleanup": "PENDING", "viewports": {}}

try:
    visitor = make_browser(visitor_profile)
    visitor.get(BASE)
    WebDriverWait(visitor, 25).until(lambda d: d.execute_script("return document.readyState") == "complete")
    check(not visitor.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-mount]"), "Visitante recibió mount privado.")
    check(not visitor.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]"), "Visitante recibió raíz.")
    result["visitor"] = "PASS"
    visitor.quit(); visitor = None; visitor_profile.cleanup()

    user = create_user()
    driver = make_browser(auth_profile)
    wait = WebDriverWait(driver, 30)
    driver.execute_cdp_cmd("Network.enable", {})
    driver.execute_cdp_cmd("Runtime.enable", {})
    login = BASE.split("/mis-compras/", 1)[0] + "/wp-login.php"
    for attempt in range(2):
        driver.get(login)
        driver.find_element(By.ID, "user_login").send_keys(user["login"])
        driver.find_element(By.ID, "user_pass").send_keys(user["password"])
        driver.find_element(By.ID, "wp-submit").click()
        try:
            WebDriverWait(driver, 15).until(lambda d: any(c["name"].startswith("wordpress_logged_in_") for c in d.get_cookies()))
            break
        except TimeoutException:
            if attempt == 1: raise AssertionError("Cookie WordPress ausente.")

    # Real page first: identity and nonce are proven before interception is activated.
    driver.get(BASE)
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
    check(driver.execute_script("return window.VeciAhorra.currentUser.loggedIn") is True, "Login real no acreditado.")
    check(driver.execute_script("return window.VeciAhorra.currentUser.id") == user["id"], "Identidad real incorrecta.")
    nonce = driver.execute_script("return window.VeciAhorra.nonce")
    check(isinstance(nonce, str) and len(nonce) >= 10, "Nonce real ausente.")
    source = (ROOT / "assets/frontend/js/customer-panel.js").read_text(encoding="utf-8")
    check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript", {"expression": source, "sourceURL": "customer-panel.js", "persistScript": False}), "CDP SyntaxError.")
    check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript", {"expression": INTERCEPT, "sourceURL": "phase8-intercept.js", "persistScript": False}), "Interceptor inválido.")
    driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {"source": INTERCEPT})
    result["authentication"] = "PASS"

    link = open_list(driver, wait, "loading")
    check(link.tag_name.lower() == "a", "Navegación no usa enlace.")
    check(urlparse(link.get_attribute("href")).query == "compra=" + PUBLIC_ID, "ID público/URL incorrectos.")
    initial_logs = logs(driver)
    result["sha256"] = asset_evidence(driver, initial_logs); result["asset"] = "PASS"
    link.click()
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__detail-title")) == 1)
    check(driver.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-content] .va-loader").is_displayed(), "Loading productivo no observado.")
    check(not driver.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]"), "Raíz presente en loading.")
    wait.until(lambda d: d.execute_script("return window.__vaPhase8Pending.length===1"))
    driver.execute_script("window.__vaPhase8Pending.shift()()")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]")) == 1)
    assert_overview(driver)
    result["states"]["loading"] = "PASS"; result["states"]["success"] = "PASS"

    calls = driver.execute_script("return window.__vaPhase8Calls")
    check(calls[0] == {"method": "GET", "path": LIST_PATH, "kind": "list"} and calls[1] == {"method": "GET", "path": DETAIL_PATH, "kind": "detail"}, "Doble interceptación no cerrada.")
    result["double_interception"] = "PASS"

    for name, expected in (("not-found", "La compra no está disponible"), ("http-error", "No pudimos cargar"), ("network-error", "No pudimos cargar"), ("invalid", "No pudimos cargar")):
        link = open_list(driver, wait, name); link.click()
        wait.until(lambda d: expected in d.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-content]").text)
        check(not driver.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]"), "Raíz presente en " + name)
        result["states"][name] = "PASS"

    link = open_list(driver, wait, "stale")
    link.click(); wait.until(lambda d: d.execute_script("return window.__vaPhase8Pending.length===1"))
    driver.back(); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
    state(driver, "success"); driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link").click()
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]")) == 1)
    driver.execute_script("window.__vaPhase8Pending.shift()()")
    check(len(driver.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]")) == 1, "Respuesta obsoleta reemplazó generación actual.")
    result["states"]["stale_response"] = "PASS"

    link = open_list(driver, wait, "success")
    driver.execute_script("window.scrollTo(0,100);arguments[0].scrollIntoView({block:'center'});", link)
    scroll_before = driver.execute_script("return window.scrollY")
    link.click(); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]")) == 1)
    driver.back(); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
    restored = driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")
    wait.until(lambda d: d.execute_script("return document.activeElement===arguments[0]", restored))
    check(abs(driver.execute_script("return window.scrollY") - scroll_before) <= 2, "Scroll no restaurado.")
    result["navigation"] = "PASS"

    driver.get(BASE + "?compra=" + PUBLIC_ID)
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]")) == 1)
    assert_overview(driver)
    check(driver.execute_script("return window.__vaPhase8Calls[0].kind") == "detail", "Acceso directo no solicitó detalle productivo.")
    result["direct"] = "PASS"

    for width, height in ((1440, 1000), (375, 812), (320, 800)):
        driver.set_window_size(width, height)
        driver.get(BASE + "?compra=" + PUBLIC_ID)
        wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-detail-overview]")) == 1)
        root = assert_overview(driver)
        check(driver.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"), "Overflow horizontal.")
        back = driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__back-link")
        rect = driver.execute_script("const r=arguments[0].getBoundingClientRect();return {w:r.width,h:r.height};", back)
        check(rect["w"] >= 44 and rect["h"] >= 44, "Control menor a 44x44.")
        check(root.is_displayed(), "Overview no visible.")
        result["viewports"][f"{width}x{height}"] = "PASS"

    heading = driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__detail-title")
    check(driver.execute_script("return document.activeElement===arguments[0]", heading), "Foco no enviado al título.")
    driver.find_element(By.TAG_NAME, "body").send_keys(Keys.TAB)
    check(driver.execute_script("return document.activeElement!==document.body"), "Teclado sin avance.")
    announcer = driver.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-announcer]")
    check(announcer.get_attribute("aria-live") == "polite" and announcer.get_attribute("aria-atomic") == "true", "Live region alterada.")
    driver.execute_cdp_cmd("Emulation.setEmulatedMedia", {"features": [{"name": "prefers-reduced-motion", "value": "reduce"}]})
    result["accessibility"] = "PASS"
    severe = [e for e in driver.get_log("browser") if e.get("level") in {"SEVERE", "ERROR"} and "controlled phase8 network failure" not in e.get("message", "")]
    check(not [e for e in severe if any(x in e.get("message", "") for x in ("SyntaxError", "ReferenceError"))], "Errores de consola: " + repr(severe))
finally:
    if visitor is not None:
        try: visitor.quit()
        except Exception: pass
    if driver is not None:
        try: driver.delete_all_cookies(); driver.quit()
        except Exception: pass
    if user is not None: delete_user(user)
    visitor_profile.cleanup(); auth_profile.cleanup()

after = fingerprint()
check(before == after, "Fingerprints persistentes cambiaron.")
check(not Path(visitor_profile.name).exists() and not Path(auth_profile.name).exists(), "Perfil temporal residual.")
result["cleanup"] = "PASS"
print(json.dumps({"status": "PASS", **result}, ensure_ascii=False, sort_keys=True))
