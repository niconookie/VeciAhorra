"""Phase 7 browser certification for the authenticated customer purchases list."""

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
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.common.exceptions import TimeoutException
from selenium.webdriver.support.ui import WebDriverWait

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("VA_CUSTOMER_PURCHASES_TEST_URL", "https://localhost/Minimarket/mis-compras/")
PHP = os.environ.get("VA_PHP", r"C:\xampp\php\php.exe")
WP_LOAD = str(ROOT.parents[2] / "wp-load.php").replace("\\", "/")
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")
INTERCEPT_LIST_ONLY = True


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
require %s;
if(strtolower((string)DB_HOST)!=='localhost'||parse_url(home_url('/'),PHP_URL_HOST)!=='localhost'){exit(2);}
if(get_role('customer')===null){exit(3);}
$t=bin2hex(random_bytes(16));$p=bin2hex(random_bytes(32));$l='va_phase7_'.substr($t,0,22);$e=$l.'@example.test';$d='Cliente Fase 7 '.substr($t,0,8);
$id=wp_insert_user(['user_login'=>$l,'user_pass'=>$p,'user_email'=>$e,'display_name'=>$d,'role'=>'customer']);
if(is_wp_error($id)){fwrite(STDERR,$id->get_error_code());exit(4);}
echo wp_json_encode(['id'=>(int)$id,'login'=>$l,'password'=>$p,'email'=>$e,'display'=>$d]);
?>""" % json.dumps(WP_LOAD)
    data = json.loads(php(source))
    check(data.get("id", 0) > 0 and data.get("password"), "Usuario temporal incompleto.")
    return data


def delete_user(user):
    source = """<?php
require %s;require_once ABSPATH.'wp-admin/includes/user.php';
$id=%d;$login=%s;$email=%s;
if($id>0){WP_Session_Tokens::get_instance($id)->destroy_all();wp_delete_user($id);clean_user_cache($id);}
global $wpdb;$meta=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%%d",$id));
$r=['id'=>get_userdata($id)===false,'login'=>get_user_by('login',$login)===false,'email'=>get_user_by('email',$email)===false,'usermeta'=>$meta,'tokens'=>count(WP_Session_Tokens::get_instance($id)->get_all())];
echo wp_json_encode($r);exit(($r['id']&&$r['login']&&$r['email']&&$meta===0&&$r['tokens']===0)?0:5);
?>""" % (json.dumps(WP_LOAD), user["id"], json.dumps(user["login"]), json.dumps(user["email"]))
    residue = json.loads(php(source))
    check(residue == {"id": True, "login": True, "email": True, "usermeta": 0, "tokens": 0}, "Residuo de usuario: " + repr(residue))


def authority_fingerprint():
    source = """<?php
require %s;if(strtolower((string)DB_HOST)!=='localhost'||parse_url(home_url('/'),PHP_URL_HOST)!=='localhost'){exit(2);}global $wpdb;
$p=$wpdb->prefix.VeciAhorra\\Core\\Config::TABLE_PREFIX;$tables=['orders','checkouts','payments','deliveries','business_completions','delivery_completions','fulfillment_completions','service_zones','store_service_zones'];$all=[];
foreach($tables as$t){$table=$p.$t;$all[$t]=$wpdb->get_results("SELECT * FROM `{$table}` ORDER BY 1",ARRAY_A);}
$all['sector_meta']=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%%s ORDER BY user_id,umeta_id",'_veciahorra_service_zone_id'),ARRAY_A);
echo hash('sha256',wp_json_encode($all));
?>""" % json.dumps(WP_LOAD)
    value = php(source)
    check(re.fullmatch(r"[a-f0-9]{64}", value) is not None, "Fingerprint inválido.")
    return value


def browser(profile, width=1440, height=1000):
    options = webdriver.ChromeOptions()
    for flag in ("--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"):
        options.add_argument(flag)
    options.add_argument(f"--window-size={width},{height}")
    options.add_argument("--user-data-dir=" + profile.name)
    options.set_capability("acceptInsecureCerts", True)
    options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
    return webdriver.Chrome(service=Service(DRIVER), options=options)


PUBLIC_A = "chk_" + "A" * 43
PUBLIC_B = "chk_" + "B" * 43
LONG = "Minimarket Vecinal con un nombre comercial extraordinariamente extenso para certificar reflow y wrapping accesible"

INTERCEPT = r"""
(()=>{
 const nativeFetch=window.fetch.bind(window), exact='/wp-json/veciahorra/v1/customer-panel/purchases';
 window.__vaPhase7Calls=[];window.__vaPhase7Resolve=null;
 const reply=(payload,status=200)=>Promise.resolve(new Response(JSON.stringify(payload),{status,headers:{'Content-Type':'application/json; charset=UTF-8'}}));
 const purchase=(id,name,total='12990.00')=>({checkout_public_id:id,created_at:'2026-08-14T12:00:00Z',total:{amount:total,currency:'CLP'},product_quantity:3,order_count:2,minimarket_count:1,minimarkets:[name],fulfillment_method:'delivery',visible_status:{code:'preparing_delivery',label:'Preparando despacho',message:'Tus productos se están preparando para despacho.'}});
 window.fetch=function(input,init){
  const raw=typeof input==='string'?input:input.url,url=new URL(raw,location.href),method=String((init&&init.method)||'GET').toUpperCase();
  if(url.origin!==location.origin||!url.pathname.endsWith(exact)||method!=='GET')return nativeFetch(input,init);
  window.__vaPhase7Calls.push({method,path:url.pathname});
  const state=(document.cookie.match(/(?:^|; )va_phase7_state=([^;]+)/)||[])[1]||'one';
  if(state==='loading')return new Promise(resolve=>{window.__vaPhase7Resolve=()=>reply({success:true,data:[purchase('chk_'+('A'.repeat(43)),'Minimarket Uno')]}).then(resolve);});
  if(state==='empty')return reply({success:true,data:[]});
  if(state==='http-error')return reply({success:false,error:{code:'controlled',message:'Error controlado.'}},503);
  if(state==='network-error')return Promise.reject(new TypeError('controlled network failure'));
  if(state==='invalid')return reply({success:true,data:[{checkout_public_id:'invalid'}]});
  if(state==='long')return reply({success:true,data:[purchase('chk_'+('A'.repeat(43)),%LONG%)]});
  if(state==='many')return reply({success:true,data:[purchase('chk_'+('A'.repeat(43)),'Minimarket Uno'),purchase('chk_'+('B'.repeat(43)),'Minimarket Dos','8450.00')]});
  if(state==='stale')return new Promise(resolve=>{window.__vaPhase7Resolve=()=>reply({success:true,data:[purchase('chk_'+('A'.repeat(43)),'Minimarket Tardío')]}).then(resolve);});
  return reply({success:true,data:[purchase('chk_'+('A'.repeat(43)),'Minimarket Uno')]});
 };
})();
""".replace("%LONG%", json.dumps(LONG))


def set_state(driver, state):
    driver.add_cookie({"name": "va_phase7_state", "value": state, "path": "/", "sameSite": "Lax"})


def open_state(driver, wait, state):
    set_state(driver, state)
    driver.get(BASE)
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
    mount = wait.until(lambda d: d.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-mount]"))
    return mount


def list_root(driver):
    roots = driver.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-list-surface]")
    check(len(roots) == 1, "Raíz dinámica ausente o duplicada.")
    check(roots[0].get_attribute("class").split() == ["veciahorra-frontend", "va-design-system", "va-customer-panel__list-surface"], "Clase de superficie incorrecta.")
    return roots[0]


def performance_evidence(entries):
    urls, failures, methods = [], [], []
    for entry in entries:
        message = json.loads(entry["message"])["message"]
        method, params = message.get("method"), message.get("params", {})
        if method == "Network.requestWillBeSent":
            request = params.get("request", {})
            methods.append((request.get("method"), request.get("url", "")))
        if method == "Network.loadingFailed" and params.get("type") in {"Document", "Stylesheet", "Script"} and not params.get("canceled", False):
            failures.append(params.get("errorText"))
        if method == "Network.responseReceived":
            response = params.get("response", {})
            urls.append(response.get("url", ""))
            if params.get("type") in {"Document", "Stylesheet", "Script"} and int(response.get("status", 0)) >= 400:
                failures.append(f"{response.get('status')}:{response.get('url')}")
    return urls, failures, methods


def asset_evidence(driver, entries):
    required = {"design": "veciahorra-design-system.css", "legacy": "veciahorra-frontend.css", "panel_css": "customer-panel.css", "base_js": "veciahorra-frontend.js", "panel_js": "customer-panel.js"}
    found, request_id = {}, None
    for entry in entries:
        message = json.loads(entry["message"])["message"]
        if message.get("method") != "Network.responseReceived":
            continue
        params = message.get("params", {})
        response, url = params.get("response", {}), params.get("response", {}).get("url", "")
        for label, name in required.items():
            if url.split("?", 1)[0].endswith("/" + name) and int(response.get("status", 0)) == 200:
                found[label] = response.get("mimeType", "")
                if label == "panel_js":
                    request_id = params.get("requestId")
    check(set(found) == set(required), "Assets productivos incompletos: " + repr(found))
    check("javascript" in found["panel_js"].lower(), "MIME JavaScript inválido: " + found["panel_js"])
    body = driver.execute_cdp_cmd("Network.getResponseBody", {"requestId": request_id})
    remote = base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode()
    local = (ROOT / "assets/frontend/js/customer-panel.js").read_bytes()
    check(remote == local, "customer-panel.js remoto distinto del archivo productivo.")
    return hashlib.sha256(local).hexdigest()


check(local_url(BASE), "La URL del panel no es local.")
check(Path(PHP).is_file() and Path(DRIVER).is_file(), "Herramienta local ausente; SKIP no permitido.")
compile(Path(__file__).read_text(encoding="utf-8"), __file__, "exec")
before = authority_fingerprint()
visitor_profile = tempfile.TemporaryDirectory(prefix="va-phase7-visitor-")
auth_profile = tempfile.TemporaryDirectory(prefix="va-phase7-auth-")
visitor = driver = None
user = None
result = {"visitor": "PENDING", "authenticated": "PENDING", "cdp": "PENDING", "asset": "PENDING", "states": {}, "navigation": "PENDING", "accessibility": "PENDING", "network_console": "PENDING", "cleanup": "PENDING", "viewports": {}}

try:
    visitor = browser(visitor_profile)
    visitor.execute_cdp_cmd("Network.enable", {})
    check(visitor.get_cookies() == [], "Perfil visitante con cookies previas.")
    visitor.get(BASE)
    WebDriverWait(visitor, 25).until(lambda d: d.execute_script("return document.readyState") == "complete")
    check(len(visitor.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-mount]")) == 0, "Visitante recibió mount privado.")
    check(len(visitor.find_elements(By.CSS_SELECTOR, ".va-design-system")) == 0, "Visitante recibió raíz visual.")
    check("Iniciar sesi" in visitor.find_element(By.TAG_NAME, "body").text, "Bloqueo visitante ausente.")
    _, visitor_failures, visitor_methods = performance_evidence(visitor.get_log("performance"))
    check(not visitor_failures, "Fallo de documento/assets visitante.")
    check(not any("customer-panel/purchases" in url for _, url in visitor_methods), "Visitante consultó endpoint privado.")
    result["visitor"] = "PASS"
    visitor.quit(); visitor = None; visitor_profile.cleanup()

    user = create_user()
    driver = browser(auth_profile)
    wait = WebDriverWait(driver, 30)
    driver.execute_cdp_cmd("Network.enable", {})
    driver.execute_cdp_cmd("Runtime.enable", {})
    driver.execute_cdp_cmd("Page.enable", {})
    login = BASE.split("/mis-compras/", 1)[0] + "/wp-login.php"
    check(local_url(login), "Login no local.")
    for attempt in range(2):
        driver.get(login)
        driver.find_element(By.ID, "user_login").send_keys(user["login"])
        driver.find_element(By.ID, "user_pass").send_keys(user["password"])
        driver.find_element(By.ID, "wp-submit").click()
        try:
            WebDriverWait(driver, 15).until(lambda d: any(cookie["name"].startswith("wordpress_logged_in_") for cookie in d.get_cookies()))
            break
        except TimeoutException:
            if attempt == 1:
                raise AssertionError("WordPress no creó cookie autenticada tras dos logins locales reales.")
    source = (ROOT / "assets/frontend/js/customer-panel.js").read_text(encoding="utf-8")
    compiled = driver.execute_cdp_cmd("Runtime.compileScript", {"expression": source, "sourceURL": "assets/frontend/js/customer-panel.js", "persistScript": False})
    check("exceptionDetails" not in compiled, "CDP SyntaxError: " + repr(compiled.get("exceptionDetails")))
    intercept_compiled = driver.execute_cdp_cmd("Runtime.compileScript", {"expression": INTERCEPT, "sourceURL": "phase7-list-only-intercept.js", "persistScript": False})
    check("exceptionDetails" not in intercept_compiled, "Interceptor inválido.")
    driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {"source": INTERCEPT})
    result["cdp"] = "PASS"

    mount = open_state(driver, wait, "loading")
    loader = mount.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-status]")
    check(loader.is_displayed(), "Loading no visible.")
    check(loader.get_attribute("class").split() == ["veciahorra-frontend", "va-design-system", "va-customer-panel__status", "va-loader"], "Raíz loader incorrecta.")
    wait.until(lambda d: d.execute_script("return typeof window.__vaPhase7Resolve==='function'"))
    driver.execute_script("window.__vaPhase7Resolve()")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-list-surface]")) == 1)
    first_logs = driver.get_log("performance")
    urls, failures, methods = performance_evidence(first_logs)
    check(not failures, "Fallo de documento/assets: " + repr(failures))
    result["sha256"] = asset_evidence(driver, first_logs)
    result["asset"] = "PASS"
    check(driver.execute_script("return window.VeciAhorra.currentUser.loggedIn") is True, "Sesión WordPress no autenticada.")
    check(driver.execute_script("return window.VeciAhorra.currentUser.id") == user["id"], "Identidad autenticada incorrecta.")
    nonce = driver.execute_script("return window.VeciAhorra.nonce")
    check(isinstance(nonce, str) and len(nonce) >= 10, "Nonce real ausente.")
    check(driver.execute_script("return window.__vaPhase7Calls.length") == 1, "Número de GET de listado incorrecto.")
    check(not any(method != "GET" and "customer-panel" in url for method, url in methods), "Mutación detectada.")
    result["authenticated"] = "PASS"; result["states"]["loading"] = "PASS"

    for state, expected_count in (("one", 1), ("many", 2), ("long", 1)):
        mount = open_state(driver, wait, state)
        wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase")) == expected_count)
        surface = list_root(driver)
        check(len(surface.find_elements(By.CSS_SELECTOR, ":scope > .va-customer-panel__list-title")) == 1, "Título fuera de superficie.")
        check(all("va-card" in node.get_attribute("class").split() for node in surface.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase")), "Tarjeta sin va-card.")
        result["states"][state] = "PASS"

    mount = open_state(driver, wait, "empty")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-empty-state")) == 1)
    check("Aún no tienes compras" in list_root(driver).text, "Empty incorrecto.")
    result["states"]["empty"] = "PASS"

    for state in ("http-error", "network-error", "invalid"):
        mount = open_state(driver, wait, state)
        wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-alert--error")) == 1)
        check("No pudimos cargar" in list_root(driver).text, "Error no anunciado.")
        result["states"][state] = "PASS"

    mount = open_state(driver, wait, "http-error")
    set_state(driver, "one")
    driver.refresh()
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase")) == 1)
    list_root(driver)
    result["states"]["reload_retry"] = "PASS"

    mount = open_state(driver, wait, "stale")
    wait.until(lambda d: d.execute_script("return typeof window.__vaPhase7Resolve==='function'"))
    driver.execute_script("history.pushState(null,'',location.pathname+'?compra=chk_'+'C'.repeat(43));dispatchEvent(new PopStateEvent('popstate'));window.__vaPhase7Resolve();")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__detail-title")) == 1)
    check(len(driver.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-list-surface]")) == 0, "Respuesta obsoleta restauró listado.")
    result["states"]["stale_response"] = "PASS"

    mount = open_state(driver, wait, "many")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 2)
    link = driver.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")[0]
    href = link.get_attribute("href")
    parsed = urlparse(href)
    check(parsed.hostname in {"localhost", "127.0.0.1"} and parsed.query == "compra=" + PUBLIC_A, "Enlace canónico inválido.")
    driver.execute_script("window.scrollTo(0,100);arguments[0].scrollIntoView({block:'center'});", link)
    scroll_before = driver.execute_script("return window.scrollY")
    link.click()
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__detail-title")) == 1)
    check(len(driver.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-list-surface]")) == 0, "Raíz del listado presente en detalle.")
    check(driver.execute_script("return window.__vaPhase7Calls.length") == 1, "Se interceptó detalle u otra ruta.")
    driver.back()
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-customer-panel-list-surface]")) == 1)
    restored = driver.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")[0]
    wait.until(lambda d: d.execute_script("return document.activeElement===arguments[0]", restored))
    check(abs(driver.execute_script("return window.scrollY") - scroll_before) <= 2, "Scroll no restaurado.")
    result["navigation"] = "PASS"

    for width, height in ((1440, 1000), (375, 812), (320, 800)):
        driver.set_window_size(width, height)
        mount = open_state(driver, wait, "long")
        wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")) == 1)
        link = driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__purchase-link")
        metrics = driver.execute_script("const r=arguments[0].getBoundingClientRect();return {w:r.width,h:r.height};", link)
        check(metrics["w"] >= 44 and metrics["h"] >= 44, "Target menor a 44x44.")
        check(driver.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"), "Overflow horizontal.")
        result["viewports"][f"{width}x{height}"] = "PASS"

    heading = driver.find_element(By.CSS_SELECTOR, ".va-customer-panel__list-title")
    check(driver.execute_script("return document.activeElement===arguments[0]", heading), "Foco no enviado al título.")
    driver.find_element(By.TAG_NAME, "body").send_keys(Keys.TAB)
    check(driver.execute_script("return document.activeElement && document.activeElement!==document.body"), "Teclado no avanza foco.")
    announcer = driver.find_element(By.CSS_SELECTOR, "[data-va-customer-panel-announcer]")
    check(announcer.get_attribute("aria-live") == "polite" and announcer.get_attribute("aria-atomic") == "true", "Live region alterada.")
    driver.execute_cdp_cmd("Emulation.setEmulatedMedia", {"features": [{"name": "prefers-reduced-motion", "value": "reduce"}]})
    transition = driver.execute_script("return getComputedStyle(document.querySelector('.va-customer-panel__purchase')).transitionDuration")
    check(all(float(value.rstrip("s") or 0) == 0 for value in transition.split(", ")), "Reduced motion no respetado.")
    result["accessibility"] = "PASS"

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") in {"SEVERE", "ERROR"} and any(name in entry.get("message", "") for name in ("SyntaxError", "ReferenceError", "TypeError")) and "controlled network failure" not in entry.get("message", "")]
    check(not severe, "Errores JavaScript severos: " + repr(severe))
    result["network_console"] = "PASS"
finally:
    if visitor is not None:
        try: visitor.quit()
        except Exception: pass
    if driver is not None:
        try: driver.quit()
        except Exception: pass
    if user is not None:
        delete_user(user)
    visitor_profile.cleanup()
    auth_profile.cleanup()

after = authority_fingerprint()
check(before == after, "Autoridades persistentes cambiaron.")
check(not Path(visitor_profile.name).exists() and not Path(auth_profile.name).exists(), "Perfil Chrome residual.")
result["cleanup"] = "PASS"
print(json.dumps({"status": "PASS", **result}, ensure_ascii=False, sort_keys=True))
