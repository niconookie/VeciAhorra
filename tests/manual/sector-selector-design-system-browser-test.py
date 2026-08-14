"""Phase 6 browser certification for the public sector selector."""

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
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import Select, WebDriverWait

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("VA_SECTOR_TEST_URL", "https://localhost/Minimarket/catalogo-veciahorra/")
PHP = os.environ.get("VA_PHP", r"C:\xampp\php\php.exe")
WP_LOAD = str(ROOT.parents[2] / "wp-load.php").replace("\\", "/")
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")


def check(value, message):
    if not value:
        raise AssertionError(message)


def local_url(value):
    parsed = urlparse(value)
    return parsed.scheme in {"http", "https"} and parsed.hostname in {"localhost", "127.0.0.1", "::1"}


def php(source):
    run = subprocess.run([PHP], input=source, text=True, capture_output=True, timeout=30, check=False)
    check(run.returncode == 0, "PHP efímero falló: " + run.stderr[-500:])
    return run.stdout.strip()


def authority_snapshot():
    source = """<?php
require %s;
if (strtolower((string) DB_HOST) !== 'localhost' || parse_url(home_url('/'), PHP_URL_HOST) !== 'localhost') { exit(2); }
$save = session_save_path();
if ($save === '') { $save = sys_get_temp_dir(); }
$real = realpath($save);
if (!is_string($real) || $real === '' || !is_dir($real)) { exit(3); }
global $wpdb;
$prefix = $wpdb->prefix . VeciAhorra\\Core\\Config::TABLE_PREFIX;
$zones = $wpdb->get_results("SELECT * FROM {$prefix}service_zones ORDER BY id", ARRAY_A);
$links = $wpdb->get_results("SELECT * FROM {$prefix}store_service_zones ORDER BY zone_id,store_id", ARRAY_A);
$meta = $wpdb->get_results($wpdb->prepare("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%%s ORDER BY user_id,umeta_id", '_veciahorra_service_zone_id'), ARRAY_A);
echo wp_json_encode(['session_name'=>session_name(),'save_path'=>$real,'fingerprint'=>hash('sha256',wp_json_encode([$zones,$links,$meta]))]);
?>""" % json.dumps(WP_LOAD)
    data = json.loads(php(source))
    check(re.fullmatch(r"[A-Za-z0-9_-]{1,128}", data.get("session_name", "")) is not None, "Nombre de sesión inseguro.")
    save_path = Path(data.get("save_path", ""))
    check(save_path.is_absolute() and save_path.is_dir(), "session.save_path no local.")
    check(re.fullmatch(r"[a-f0-9]{64}", data.get("fingerprint", "")) is not None, "Fingerprint sectorial inválido.")
    return data


def destroy_exact_session(name, session_id):
    check(re.fullmatch(r"[A-Za-z0-9_-]{1,128}", name) is not None, "Nombre de sesión no permitido.")
    check(re.fullmatch(r"[A-Za-z0-9,-]{8,256}", session_id) is not None, "ID de sesión no permitido.")
    source = """<?php
require %s;
if (strtolower((string) DB_HOST) !== 'localhost' || parse_url(home_url('/'), PHP_URL_HOST) !== 'localhost') { exit(2); }
$expectedName=%s;$expectedId=%s;$save=session_save_path();if($save===''){$save=sys_get_temp_dir();}$real=realpath($save);
if(!is_string($real)||$real===''||!is_dir($real)||session_status()!==PHP_SESSION_NONE){exit(3);}
session_name($expectedName);session_id($expectedId);session_start();
unset($_SESSION['veciahorra_service_zone_id']);$_SESSION=[];$destroyed=session_destroy();
session_id($expectedId);session_start();$absent=!array_key_exists('veciahorra_service_zone_id',$_SESSION);$_SESSION=[];$destroyedAgain=session_destroy();
echo wp_json_encode(['destroyed'=>$destroyed,'absent'=>$absent,'destroyed_again'=>$destroyedAgain,'save_path'=>$real]);
exit(($destroyed&&$absent&&$destroyedAgain)?0:4);
?>""" % (json.dumps(WP_LOAD), json.dumps(name), json.dumps(session_id))
    result = json.loads(php(source))
    check(result.get("destroyed") is True and result.get("absent") is True and result.get("destroyed_again") is True, "Cleanup de sesión incompleto.")
    return result


def browser(profile, width=1440, height=1000):
    options = webdriver.ChromeOptions()
    for flag in ("--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"):
        options.add_argument(flag)
    options.add_argument(f"--window-size={width},{height}")
    options.add_argument("--user-data-dir=" + profile.name)
    options.set_capability("acceptInsecureCerts", True)
    options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
    return webdriver.Chrome(service=Service(DRIVER), options=options)


def selector_root(driver, wait):
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
    roots = driver.find_elements(By.CSS_SELECTOR, "[data-va-sector-selector]")
    check(len(roots) == 1, "Selector sectorial ausente o duplicado.")
    root = roots[0]
    check(root.get_attribute("class").split() == ["veciahorra-frontend", "va-design-system"], "Raíz opt-in incorrecta.")
    wrapper = root.find_element(By.CSS_SELECTOR, ":scope > .va-sector-selector.va-card.va-field-group")
    check(wrapper.find_element(By.CSS_SELECTOR, ":scope > label.va-field [data-va-sector-select]"), "Campo sectorial incorrecto.")
    check(root.get_attribute("aria-label") == "Sector de compra", "ARIA de región alterado.")
    return root, root.find_element(By.CSS_SELECTOR, "[data-va-sector-select]")


def performance_evidence(entries):
    urls = []
    failures = []
    for entry in entries:
        message = json.loads(entry["message"])["message"]
        method = message.get("method")
        params = message.get("params", {})
        if method == "Network.loadingFailed" and params.get("type") in {"Document", "Stylesheet", "Script"} and not params.get("canceled", False):
            failures.append(params.get("errorText"))
        if method == "Network.responseReceived":
            response = params.get("response", {})
            url = response.get("url", "")
            urls.append(url)
            if params.get("type") in {"Document", "Stylesheet", "Script"} and int(response.get("status", 0)) >= 400:
                failures.append(f"{response.get('status')}:{url}")
    return urls, failures


ERROR_INTERCEPT = r"""
(()=>{
 const nativeFetch=window.fetch.bind(window);
 window.__vaSectorNativeFetch=nativeFetch;window.__vaSectorIntercepted=[];
 window.fetch=function(input,init){
  const raw=typeof input==='string'?input:input.url,url=new URL(raw,location.href),method=String((init&&init.method)||'GET').toUpperCase();
  const exact=/\/wp-json\/veciahorra\/v1\/sector\/current\/[1-9]\d*$/.test(url.pathname);
  if(method==='POST'&&url.origin===location.origin&&exact){
   window.__vaSectorIntercepted.push({method,path:url.pathname});
   return Promise.resolve(new Response(JSON.stringify({success:false,error:{code:'sector_test_error',message:'Error sectorial controlado.'}}),{status:503,headers:{'Content-Type':'application/json'}}));
  }
  return nativeFetch(input,init);
 };
})();
"""


check(local_url(BASE), "La URL del catálogo no es local.")
check(Path(PHP).is_file() and Path(DRIVER).is_file(), "Herramienta local ausente; SKIP no permitido.")
compile(Path(__file__).read_text(encoding="utf-8"), __file__, "exec")
before = authority_snapshot()
profile = tempfile.TemporaryDirectory(prefix="va-phase6-sector-")
driver = None
session_cookie = None
cleanup = None
result = {"document_assets": "PENDING", "get_real": "PENDING", "post_real": "PENDING", "persistence_reload": "PENDING", "error_intercepted": "PENDING", "keyboard_focus": "PENDING", "console_network": "PENDING", "cleanup": "PENDING", "viewports": {}}

try:
    driver = browser(profile)
    wait = WebDriverWait(driver, 30)
    check(driver.get_cookies() == [], "El perfil Chrome contiene cookies previas.")
    driver.execute_cdp_cmd("Network.enable", {})
    driver.execute_cdp_cmd("Runtime.enable", {})
    compiled = driver.execute_cdp_cmd("Runtime.compileScript", {"expression": ERROR_INTERCEPT, "sourceURL": "phase6-sector-error.js", "persistScript": False})
    check("exceptionDetails" not in compiled, "Interceptor CDP inválido.")

    driver.get(BASE)
    root, select_node = selector_root(driver, wait)
    wait.until(lambda d: len(Select(select_node).options) > 1)
    options = [(option.get_attribute("value"), option.text.strip()) for option in Select(select_node).options]
    active = [(value, label) for value, label in options if value]
    check(active and all(re.fullmatch(r"[1-9]\d*", value) and label for value, label in active), "Options sectoriales inválidas.")
    check(len({value for value, _ in active}) == len(active), "IDs sectoriales duplicados.")
    cookies = [cookie for cookie in driver.get_cookies() if cookie["name"] == before["session_name"]]
    check(len(cookies) == 1, "No se identificó exactamente una cookie de sesión PHP.")
    session_cookie = cookies[0]
    check(session_cookie.get("domain", "").lstrip(".") in {"localhost", "127.0.0.1"}, "Cookie de sesión no local.")
    check(not any(cookie["name"].startswith("wordpress_logged_in_") for cookie in driver.get_cookies()), "Perfil visitante autenticado.")
    check(re.fullmatch(r"[A-Za-z0-9,-]{8,256}", session_cookie["value"]) is not None, "ID de sesión inesperado.")
    first_logs = driver.get_log("performance")
    first_urls, first_failures = performance_evidence(first_logs)
    required_assets = ("veciahorra-design-system.css", "veciahorra-frontend.css", "veciahorra-frontend.js", "veciahorra-catalog.js")
    check(all(any(url.split("?", 1)[0].endswith("/" + asset) for url in first_urls) for asset in required_assets), "Assets productivos incompletos.")
    check(any("/wp-json/veciahorra/v1/sectors" in url for url in first_urls) and any("/wp-json/veciahorra/v1/sector/current" in url for url in first_urls), "GET sectoriales reales ausentes.")
    check(not first_failures, "Fallo de documento/assets: " + repr(first_failures))
    result["document_assets"] = "PASS"
    result["get_real"] = "PASS"

    driver.execute_script(ERROR_INTERCEPT)
    original_url = driver.current_url
    error_value = active[-1][0]
    Select(select_node).select_by_value(error_value)
    message = root.find_element(By.CSS_SELECTOR, "[data-va-sector-message]")
    wait.until(lambda d: "Error sectorial controlado." in message.text)
    check(select_node.is_enabled(), "Select no rehabilitado tras error.")
    check(driver.current_url == original_url, "El error provocó recarga.")
    check(driver.execute_script("return window.__vaSectorIntercepted.length") == 1, "Interceptación fuera de contrato.")
    current_after_error = driver.execute_async_script("const done=arguments[0];window.__vaSectorNativeFetch(window.VeciAhorra.restUrl+'sector/current',{credentials:'same-origin'}).then(r=>r.json()).then(done).catch(e=>done({error:String(e)}));")
    check(current_after_error.get("data") is None, "El error cambió el sector de sesión.")
    driver.execute_script("window.fetch=window.__vaSectorNativeFetch;delete window.__vaSectorNativeFetch;")
    result["error_intercepted"] = "PASS"

    real_value = active[0][0]
    old_root = root
    Select(select_node).select_by_value(real_value)
    wait.until(EC.staleness_of(old_root))
    root, select_node = selector_root(driver, wait)
    wait.until(lambda d: len(Select(select_node).options) > 1 and Select(select_node).first_selected_option.get_attribute("value") == real_value)
    reload_logs = driver.get_log("performance")
    reload_urls, reload_failures = performance_evidence(reload_logs)
    check(any("/wp-json/veciahorra/v1/sector/current/" + real_value in url for url in reload_urls), "POST sectorial real ausente.")
    check(any("/wp-json/veciahorra/v1/catalog/products" in url for url in reload_urls), "Catálogo no se consultó tras recarga.")
    check(not reload_failures, "Fallo de red tras recarga: " + repr(reload_failures))
    result["post_real"] = "PASS"
    result["persistence_reload"] = "PASS"

    for width, height in ((1440, 1000), (375, 812), (320, 800)):
        driver.set_window_size(width, height)
        root, select_node = selector_root(driver, wait)
        metrics = driver.execute_script("const r=arguments[0].getBoundingClientRect(),s=getComputedStyle(arguments[0]);return {w:r.width,h:r.height,outline:s.outlineStyle};", select_node)
        check(metrics["w"] >= 44 and metrics["h"] >= 44, "Select menor a 44x44.")
        check(driver.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"), "Overflow horizontal.")
        result["viewports"][f"{width}x{height}"] = "PASS"
    select_node.send_keys(Keys.TAB)
    check(driver.execute_script("return document.activeElement!==document.body"), "Tab no avanzó el foco.")
    driver.switch_to.active_element.send_keys(Keys.SHIFT, Keys.TAB)
    check(driver.execute_script("return document.activeElement===arguments[0]", select_node), "Select no recuperó foco por teclado.")
    focus_style = driver.execute_script("const s=getComputedStyle(arguments[0]);return {outline:s.outlineStyle,width:s.outlineWidth};", select_node)
    check(focus_style["outline"] != "none" and focus_style["width"] != "0px", "Foco visible ausente.")
    result["keyboard_focus"] = "PASS"

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") in {"SEVERE", "ERROR"} and any(name in entry.get("message", "") for name in ("SyntaxError", "ReferenceError", "TypeError"))]
    check(not severe, "Errores JavaScript severos: " + repr(severe))
    result["console_network"] = "PASS"
finally:
    if driver is not None:
        try:
            driver.quit()
        except Exception:
            pass
    if session_cookie is not None:
        cleanup = destroy_exact_session(session_cookie["name"], session_cookie["value"])
    profile.cleanup()

after = authority_snapshot()
check(cleanup is not None and cleanup.get("absent") is True, "No se certificó cleanup de la sesión exacta.")
check(before["save_path"] == after["save_path"], "session.save_path cambió.")
check(before["fingerprint"] == after["fingerprint"], "User meta o tablas sectoriales cambiaron.")
check(not Path(profile.name).exists(), "Perfil Chrome residual.")
result["cleanup"] = "PASS"
print(json.dumps({"status": "PASS", "session_id": "REDACTED:" + hashlib.sha256(session_cookie["value"].encode()).hexdigest()[:12], **result}, ensure_ascii=False, sort_keys=True))
