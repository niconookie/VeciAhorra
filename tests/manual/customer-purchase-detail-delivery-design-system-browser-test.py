"""Deterministic Phase 12 browser certification for purchase delivery."""

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
PUBLIC_ID = "chk_" + "D" * 43
DETAIL_PATH = LIST_PATH + "/" + PUBLIC_ID
ROOT_SELECTOR = "[data-va-customer-panel-detail-delivery]"


def check(value, message):
    if not value:
        raise AssertionError(message)


def php(source):
    run = subprocess.run([PHP], input=source, text=True, capture_output=True, timeout=45, check=False)
    check(run.returncode == 0, "PHP efímero falló: " + run.stderr[-500:])
    return run.stdout.strip()


def create_user():
    source = """<?php
require %s;if(strtolower((string)DB_HOST)!=='localhost'||parse_url(home_url('/'),PHP_URL_HOST)!=='localhost'){exit(2);}
$t=bin2hex(random_bytes(16));$p=bin2hex(random_bytes(32));$l='va_phase12_'.substr($t,0,22);$e=$l.'@example.test';
$id=wp_insert_user(['user_login'=>$l,'user_pass'=>$p,'user_email'=>$e,'display_name'=>'Cliente Fase 12','role'=>'customer']);
if(is_wp_error($id)){exit(4);}echo wp_json_encode(['id'=>(int)$id,'login'=>$l,'password'=>$p,'email'=>$e]);?>""" % json.dumps(WP_LOAD)
    user = json.loads(php(source))
    check(user.get("id", 0) > 0, "Usuario temporal incompleto.")
    return user


def delete_user(user):
    source = """<?php
require %s;require_once ABSPATH.'wp-admin/includes/user.php';$id=%d;$login=%s;$email=%s;
WP_Session_Tokens::get_instance($id)->destroy_all();wp_delete_user($id);clean_user_cache($id);global $wpdb;
$r=['id'=>get_userdata($id)===false,'login'=>get_user_by('login',$login)===false,'email'=>get_user_by('email',$email)===false,'usermeta'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%%d",$id)),'tokens'=>count(WP_Session_Tokens::get_instance($id)->get_all())];
echo wp_json_encode($r);exit(($r['id']&&$r['login']&&$r['email']&&$r['usermeta']===0&&$r['tokens']===0)?0:5);?>""" % (json.dumps(WP_LOAD), user["id"], json.dumps(user["login"]), json.dumps(user["email"]))
    result = json.loads(php(source))
    check(result == {"id": True, "login": True, "email": True, "usermeta": 0, "tokens": 0}, "Residuo de usuario.")


def fingerprint():
    source = r"""<?php
require %s;global $wpdb;$p=$wpdb->prefix.VeciAhorra\Core\Config::TABLE_PREFIX;$all=[];
foreach(['orders','checkouts','payments','deliveries','business_completions','delivery_completions','fulfillment_completions','service_zones','store_service_zones']as$t){$all[$t]=$wpdb->get_results("SELECT * FROM `{$p}{$t}` ORDER BY 1",ARRAY_A);}
$all['sector_meta']=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%%s ORDER BY user_id,umeta_id",'_veciahorra_service_zone_id'),ARRAY_A);echo hash('sha256',wp_json_encode($all));?>""" % json.dumps(WP_LOAD)
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
 window.__vaPhase12Calls=[];window.__vaPhase12Pending=[];
 const response=(payload,status=200)=>new Response(JSON.stringify(payload),{status,headers:{'Content-Type':'application/json; charset=UTF-8'}});
 const listItem=()=>({checkout_public_id:publicId,created_at:'2026-08-15T12:00:00Z',total:{amount:'1000.00',currency:'CLP'},product_quantity:1,order_count:0,minimarket_count:0,minimarkets:[],fulfillment_method:'delivery',visible_status:{code:'preparing_delivery',label:'Preparando despacho',message:'Preparando.'}});
 const combinations={
  pickup:['pickup','not_applicable','Retiro'],delivery_available:['delivery','not_available','Despacho'],null_method:[null,'not_available','Por confirmar'],
  preparing:['delivery','preparing_delivery','Despacho'],out:['delivery','out_for_delivery','Despacho'],delivered:['delivery','delivered','Despacho'],cancelled:['delivery','cancelled','Despacho'],
  review_pickup:['pickup','under_review','Retiro'],review_delivery:['delivery','under_review','Despacho'],review_null:[null,'under_review','Por confirmar'],long_label:['delivery','not_available','Despacho deliberadamente extenso para certificar wrapping sin desbordamiento horizontal']
 };
 const envelope=state=>{const c=combinations[state]||combinations.preparing;return {success:true,data:{checkout_public_id:publicId,created_at:'2026-08-15T12:00:00Z',visible_status:{code:'preparing_delivery',label:'Preparando despacho',message:'Detalle válido.'},requires_review:false,fulfillment:{method:c[0],label:c[2]},summary:{subtotal:'1000.00',total:'1000.00',currency:'CLP',product_quantity:0,line_count:0,order_count:0,minimarket_count:0},orders:[],payment:null,delivery:{method:c[0],status:state==='invalid_type'?null:c[1],label:c[2]},timeline:[]}}};
 window.fetch=function(input,init){
  const raw=typeof input==='string'?input:input.url,url=new URL(raw,location.href),method=String((init&&init.method)||'GET').toUpperCase();
  if(url.origin!==location.origin||method!=='GET'||(url.pathname!==listPath&&url.pathname!==detailPath))return nativeFetch(input,init);
  const kind=url.pathname===listPath?'list':'detail';window.__vaPhase12Calls.push({method,path:url.pathname,kind});
  if(kind==='list')return Promise.resolve(response({success:true,data:[listItem()]}));
  const state=(document.cookie.match(/(?:^|; )va_phase12_state=([^;]+)/)||[])[1]||'preparing';
  if(state==='not_found')return Promise.resolve(response({success:false,error:{code:'customer_order_not_found'}},404));
  if(state==='http_error')return Promise.resolve(response({success:false,error:{code:'controlled'}},503));
  if(state==='network_error')return Promise.reject(new TypeError('controlled phase12 network failure'));
  if(state==='loading'||state==='stale')return new Promise(resolve=>window.__vaPhase12Pending.push(()=>resolve(response(envelope('preparing')))));
  return Promise.resolve(response(envelope(state)));
 };
})();
""".replace("%LIST%", json.dumps(LIST_PATH)).replace("%DETAIL%", json.dumps(DETAIL_PATH)).replace("%PUBLIC%", json.dumps(PUBLIC_ID))


def set_state(driver, value):
    driver.add_cookie({"name": "va_phase12_state", "value": value, "path": "/", "sameSite": "Lax"})


def open_list(driver, wait, state):
    set_state(driver, state)
    driver.get(BASE)
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
    return driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")


def enter(driver, wait, state):
    link = open_list(driver, wait, state)
    check(link.tag_name.lower() == "a" and urlparse(link.get_attribute("href")).query == "compra=" + PUBLIC_ID, "GET de detalle no canónico.")
    link.click()
    return link


def assert_delivery(driver, expected=1):
    roots = driver.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR)
    check(len(roots) == expected, f"Cardinalidad Entrega {len(roots)} != {expected}")
    if expected == 0:
        return roots
    root = roots[0]
    check(root.tag_name.lower() == "section", "Raíz Entrega no es section.")
    check(root.get_attribute("class").split() == ["veciahorra-frontend", "va-design-system", "va-customer-panel__detail-section", "va-customer-panel__detail-delivery"], "Clase literal Entrega incorrecta.")
    check(len(root.find_elements(By.CSS_SELECTOR, ":scope > h3.va-customer-panel__visual-heading")) == 1, "Encabezado Entrega ausente.")
    check(len(root.find_elements(By.CSS_SELECTOR, ":scope > .va-customer-panel__delivery-status .va-customer-panel__status-badge")) == 1, "Badge Entrega ausente.")
    check(not root.find_elements(By.CSS_SELECTOR, "button,a,form"), "Acción añadida a Entrega.")
    services = root.find_element(By.XPATH, "..")
    check("va-customer-panel__detail-services" in services.get_attribute("class").split() and services.get_attribute("data-va-customer-panel-detail-delivery") is None, "Raíz aplicada a services.")
    payment = services.find_element(By.CSS_SELECTOR, ":scope > [data-va-customer-panel-detail-payment]")
    check(payment.get_attribute("data-va-customer-panel-detail-delivery") is None and not payment.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR), "Pago invadido.")
    for selector in ("[data-va-customer-panel-detail-overview]", "[data-va-customer-panel-detail-item]", "[data-va-customer-panel-detail-order-header]", ".va-customer-panel__timeline"):
        for node in driver.find_elements(By.CSS_SELECTOR, selector):
            check(not node.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR) and not root.find_elements(By.CSS_SELECTOR, selector), "Raíces opt-in anidadas.")
    return root


def asset_hash(driver, entries):
    required = {"veciahorra-design-system.css", "veciahorra-frontend.css", "customer-panel.css", "veciahorra-frontend.js", "customer-panel.js"}
    found = {}; request_id = None
    for entry in entries:
        message = json.loads(entry["message"])["message"]
        if message.get("method") != "Network.responseReceived":
            continue
        params = message.get("params", {}); response = params.get("response", {}); url = response.get("url", "").split("?", 1)[0]
        for name in required:
            if url.endswith("/" + name) and int(response.get("status", 0)) == 200:
                found[name] = response.get("mimeType", "")
                request_id = params.get("requestId") if name == "customer-panel.js" else request_id
    check(set(found) == required and "javascript" in found["customer-panel.js"].lower(), "HTTP/MIME de assets inválidos.")
    body = driver.execute_cdp_cmd("Network.getResponseBody", {"requestId": request_id})
    remote = base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode()
    local = (ROOT / "assets/frontend/js/customer-panel.js").read_bytes()
    check(remote == local, "JavaScript remoto no coincide con producto.")
    return hashlib.sha256(local).hexdigest()


check(urlparse(BASE).hostname in {"localhost", "127.0.0.1", "::1"}, "Origen no local.")
check(Path(PHP).is_file() and Path(DRIVER).is_file(), "Herramienta ausente; SKIP prohibido.")
compile(Path(__file__).read_text(encoding="utf-8"), __file__, "exec")
before = fingerprint()
visitor_profile = tempfile.TemporaryDirectory(prefix="va-phase12-visitor-")
auth_profile = tempfile.TemporaryDirectory(prefix="va-phase12-auth-")
visitor = driver = None; user = None
result = {"visitor":"PENDING","authentication":"PENDING","interception":"PENDING","combinations":{},"states":{},"navigation":"PENDING","direct":"PENDING","accessibility":"PENDING","asset":"PENDING","cleanup":"PENDING","viewports":{}}
try:
    visitor = make_browser(visitor_profile)
    visitor.get(BASE)
    WebDriverWait(visitor, 25).until(lambda d: d.execute_script("return document.readyState") == "complete")
    check(not visitor.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-mount]") and not visitor.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR), "Visitante expuesto.")
    result["visitor"] = "PASS"; visitor.quit(); visitor = None; visitor_profile.cleanup()

    user = create_user(); driver = make_browser(auth_profile); wait = WebDriverWait(driver, 30)
    driver.execute_cdp_cmd("Network.enable", {}); driver.execute_cdp_cmd("Runtime.enable", {})
    login = BASE.split("/mis-compras/", 1)[0] + "/wp-login.php"
    for attempt in range(2):
        driver.get(login); driver.find_element(By.ID, "user_login").send_keys(user["login"]); driver.find_element(By.ID, "user_pass").send_keys(user["password"]); driver.find_element(By.ID, "wp-submit").click()
        try:
            WebDriverWait(driver, 15).until(lambda d: any(c["name"].startswith("wordpress_logged_in_") for c in d.get_cookies())); break
        except TimeoutException:
            if attempt == 1: raise AssertionError("Cookie real ausente.")
    driver.get(BASE); wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
    check(driver.execute_script("return window.VeciAhorra.currentUser.loggedIn") is True and driver.execute_script("return window.VeciAhorra.currentUser.id") == user["id"], "Identidad real incorrecta.")
    check(len(driver.execute_script("return window.VeciAhorra.nonce")) >= 10, "Nonce real ausente.")
    source = (ROOT / "assets/frontend/js/customer-panel.js").read_text(encoding="utf-8")
    check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript", {"expression":source,"sourceURL":"customer-panel.js","persistScript":False}), "JavaScript productivo inválido.")
    check("exceptionDetails" not in driver.execute_cdp_cmd("Runtime.compileScript", {"expression":INTERCEPT,"sourceURL":"phase12-intercept.js","persistScript":False}), "Interceptor inválido.")
    driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {"source":INTERCEPT}); result["authentication"] = "PASS"

    enter(driver, wait, "loading")
    wait.until(lambda d: d.execute_script("return window.__vaPhase12Pending.length===1"))
    check(driver.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-content] .va-loader").is_displayed() and not driver.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR), "Loading invadido.")
    driver.execute_script("window.__vaPhase12Pending.shift()()")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR)) == 1); assert_delivery(driver); result["states"]["loading"] = "PASS"; result["states"]["success"] = "PASS"
    calls = driver.execute_script("return window.__vaPhase12Calls")
    check(calls[:2] == [{"method":"GET","path":LIST_PATH,"kind":"list"},{"method":"GET","path":DETAIL_PATH,"kind":"detail"}], "Intercepción no cerrada.")
    check(all(call["method"] == "GET" and call["path"] in {LIST_PATH, DETAIL_PATH} for call in calls), "Tercera ruta interceptada.")
    result["interception"] = "PASS"; result["sha256"] = asset_hash(driver, driver.get_log("performance")); result["asset"] = "PASS"

    expected = {"pickup":"Retiro","delivery_available":"Despacho","null_method":"Por confirmar","preparing":"Despacho","out":"Despacho","delivered":"Despacho","cancelled":"Despacho","review_pickup":"Retiro","review_delivery":"Despacho","review_null":"Por confirmar"}
    for state, label in expected.items():
        enter(driver, wait, state); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR)) == 1)
        root = assert_delivery(driver); check(label in root.text and "Entrega" in root.text, "Label productiva alterada: " + state); result["combinations"][state] = "PASS"

    for state, fragment in (("invalid_type","No pudimos cargar"),("not_found","La compra no est"),("http_error","No pudimos cargar"),("network_error","No pudimos cargar")):
        enter(driver, wait, state); wait.until(lambda d: fragment in d.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-content]").text); assert_delivery(driver, 0); result["states"][state] = "PASS"

    enter(driver, wait, "stale"); wait.until(lambda d: d.execute_script("return window.__vaPhase12Pending.length===1")); driver.back(); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
    set_state(driver, "preparing"); driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link").click(); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR)) == 1); driver.execute_script("window.__vaPhase12Pending.shift()()"); assert_delivery(driver); result["states"]["stale_response"] = "PASS"

    link = open_list(driver, wait, "preparing"); driver.execute_script("window.scrollTo(0,100);arguments[0].scrollIntoView({block:'center'});", link); scroll = driver.execute_script("return window.scrollY"); link.click(); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR)) == 1); driver.back(); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
    restored = driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link"); wait.until(lambda d: d.execute_script("return document.activeElement===arguments[0]", restored)); check(abs(driver.execute_script("return window.scrollY") - scroll) <= 2, "Scroll no restaurado."); result["navigation"] = "PASS"
    driver.get(BASE + "?compra=" + PUBLIC_ID); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR)) == 1); assert_delivery(driver); check(driver.execute_script("return window.__vaPhase12Calls[0].kind") == "detail", "Acceso directo incorrecto."); result["direct"] = "PASS"

    for width, height in ((1440,1000),(375,812),(320,800)):
        driver.set_window_size(width, height); set_state(driver, "long_label"); driver.get(BASE + "?compra=" + PUBLIC_ID); wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ROOT_SELECTOR)) == 1)
        root = assert_delivery(driver); payment = driver.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-detail-payment]"); badge = root.find_element(By.CSS_SELECTOR, ".va-customer-panel__status-badge"); heading = root.find_element(By.CSS_SELECTOR, "h3")
        check(root.is_displayed() and badge.is_displayed() and heading.is_displayed(), "Entrega no visible.")
        check(driver.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"), "Overflow horizontal.")
        columns = driver.execute_script("return getComputedStyle(arguments[0].parentElement).gridTemplateColumns.split(' ').length", root)
        check(columns == (2 if width == 1440 else 1), "Columnas Pago/Entrega incorrectas.")
        check(abs(root.rect["y"] - payment.rect["y"]) < 5 if width == 1440 else root.rect["y"] > payment.rect["y"], "Separación Pago/Entrega incorrecta.")
        check(badge.rect["width"] <= root.rect["width"] and badge.rect["height"] > 20, "Wrapping/badge inválido.")
        back = driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__back-link"); check(back.rect["width"] >= 44 and back.rect["height"] >= 44, "Control menor a 44x44.")
        check(driver.execute_script("return document.activeElement.classList.contains('va-customer-panel__detail-title')"), "Foco inicial incorrecto.")
        severe = [e for e in driver.get_log("browser") if e.get("level") in {"SEVERE","ERROR"} and "controlled phase12 network failure" not in e.get("message","")]
        check(not [e for e in severe if any(x in e.get("message","") for x in ("SyntaxError","ReferenceError"))], "Error de consola.")
        calls = driver.execute_script("return window.__vaPhase12Calls"); check(all(c["method"] == "GET" and c["path"] in {LIST_PATH,DETAIL_PATH} for c in calls), "Red no autorizada.")
        result["viewports"][f"{width}x{height}"] = "PASS"

    driver.find_element(By.TAG_NAME, "body").send_keys(Keys.TAB)
    check(driver.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-announcer]").get_attribute("aria-live") == "polite", "Live region alterada.")
    driver.execute_cdp_cmd("Emulation.setEmulatedMedia", {"features":[{"name":"prefers-reduced-motion","value":"reduce"}]}); result["accessibility"] = "PASS"
finally:
    if visitor is not None:
        try: visitor.quit()
        except Exception: pass
    if driver is not None:
        try: driver.delete_all_cookies(); driver.quit()
        except Exception: pass
    if user is not None: delete_user(user)
    visitor_profile.cleanup(); auth_profile.cleanup()

after = fingerprint(); check(before == after, "Fingerprints cambiaron.")
check(not Path(visitor_profile.name).exists() and not Path(auth_profile.name).exists(), "Perfil temporal residual.")
check(not list(ROOT.rglob("*.pyc")), "Bytecode .pyc residual.")
result["cleanup"] = "PASS"
print(json.dumps({"status":"PASS", **result}, ensure_ascii=False, sort_keys=True))
