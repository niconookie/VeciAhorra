"""Deterministic Chrome certification for the Phase 4 public cart."""

import base64
import hashlib
import json
import os
import re
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.common.exceptions import WebDriverException
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("VA_CART_TEST_URL", "https://localhost/Minimarket/carrito-veciahorra/")
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def skip(message):
    print(json.dumps({"status": "SKIP", "reason": message}, ensure_ascii=False))
    raise SystemExit(2)


if not Path(DRIVER).is_file():
    skip("ChromeDriver no disponible.")

profile = tempfile.TemporaryDirectory(prefix="va-cart-phase4-profile-")
captures = tempfile.TemporaryDirectory(prefix="va-cart-phase4-captures-")
options = webdriver.ChromeOptions()
for flag in ("--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"):
    options.add_argument(flag)
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})

try:
    driver = webdriver.Chrome(service=Service(DRIVER), options=options)
except WebDriverException as exception:
    profile.cleanup()
    captures.cleanup()
    skip("ChromeDriver no pudo iniciarse: " + str(exception))

wait = WebDriverWait(driver, 25)
performance = []
browser_log = []
result = {
    "status": "PASS", "compile": "PENDING", "execution": "PENDING",
    "states": {}, "viewports": {}, "assets": {}, "identity": "PENDING",
    "payloads": "PENDING", "keyboard": "PENDING", "focus": "PENDING",
    "network": "PENDING", "console": "PENDING", "privacy": "PENDING",
}

INTERCEPT = r"""
(() => {
  const nativeFetch = window.fetch.bind(window);
  const response = (payload, status = 200) => Promise.resolve(new Response(
    JSON.stringify(payload), {status, headers: {'Content-Type': 'application/json'}}
  ));
  const one = {id: 701, inventory_id: 99001, product_id: 501, minimarket_id: 801,
    product_name: 'Arroz efÃ­mero', product_image_url: null, minimarket_name: 'Minimarket efÃ­mero',
    unit_price_snapshot: '1111.00', quantity: 2, subtotal: '7777.00', sector_compatible: true};
  const two = {id: 702, inventory_id: 99002, product_id: 502, minimarket_id: 802,
    product_name: 'Aceite efÃ­mero', product_image_url: null, minimarket_name: 'Mercado efÃ­mero',
    unit_price_snapshot: '2222.00', quantity: 3, subtotal: '8888.00', sector_compatible: false};
  window.__vaCartCalls = [];
  window.__vaCartItems = [one, two];
  window.__vaCartGetCount = 0;
  window.__vaCartResolve = null;
  window.fetch = function(input, init) {
    const raw = typeof input === 'string' ? input : input.url;
    const url = new URL(raw, window.location.href);
    const method = String((init && init.method) || 'GET').toUpperCase();
    const cartRoot = '/wp-json/veciahorra/v1/cart';
    const itemMatch = url.pathname.match(/\/wp-json\/veciahorra\/v1\/cart\/items\/(\d+)$/);
    if (url.pathname.endsWith(cartRoot) || itemMatch) {
      let body = null;
      if (init && init.body) { try { body = JSON.parse(init.body); } catch (error) { body = 'INVALID'; } }
      const headers = {};
      new Headers((init && init.headers) || {}).forEach((value, key) => { headers[key] = value; });
      window.__vaCartCalls.push({method, path: url.pathname, body, headers, itemId: itemMatch ? itemMatch[1] : null});
      const state = new URL(window.location.href).searchParams.get('__va_cart_state') || 'multiple';
      if (method === 'GET' && url.pathname.endsWith(cartRoot)) {
        window.__vaCartGetCount += 1;
        if (state === 'loading' && window.__vaCartGetCount === 1) {
          return new Promise(resolve => { window.__vaCartResolve = () => response({success:true,data:[one],total:'9999.00'}).then(resolve); });
        }
        if (state === 'get-error') return response({success:false,error:{code:'cart_unavailable',message:'Error GET controlado.'}}, 503);
        if (state === 'empty' || window.__vaCartItems.length === 0) return response({success:true,data:[],total:'0.00'});
        if (state === 'one') return response({success:true,data:[one],total:'9999.00'});
        return response({success:true,data:window.__vaCartItems,total:'12345.00'});
      }
      if (method === 'PATCH' && itemMatch) {
        if (state === 'patch-error') return response({success:false,error:{code:'validation_error',message:'Error PATCH controlado.'}}, 422);
        window.__vaCartItems = window.__vaCartItems.map(item => item.id === Number(itemMatch[1]) ? Object.assign({}, item, {quantity: body.quantity, subtotal:'4321.00'}) : item);
        return response({success:true,data:{updated:true}});
      }
      if (method === 'DELETE' && itemMatch) {
        if (state === 'delete-error') return response({success:false,error:{code:'cart_item_not_found',message:'Error DELETE controlado.'}}, 404);
        window.__vaCartItems = window.__vaCartItems.filter(item => item.id !== Number(itemMatch[1]));
        return response({success:true,data:{deleted:true}});
      }
      if (method === 'DELETE' && url.pathname.endsWith(cartRoot)) {
        window.__vaCartItems = [];
        return response({success:true,data:{cleared:true}});
      }
    }
    return nativeFetch(input, init);
  };
})();
"""

driver.execute_cdp_cmd("Network.enable", {})
driver.execute_cdp_cmd("Runtime.enable", {})
driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {"source": INTERCEPT})


def url(state):
    separator = "&" if "?" in BASE else "?"
    return f"{BASE}{separator}__va_cart_state={state}"


def drain_logs():
    performance.extend(driver.get_log("performance"))
    browser_log.extend(driver.get_log("browser"))


def load(state):
    driver.get(url(state))
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    roots = driver.find_elements(By.CSS_SELECTOR, "[data-va-cart]")
    if not roots:
        skip("No existe una pÃ¡gina pÃºblica publicada con el shortcode de carrito.")
    root = roots[0]
    check(root.get_attribute("class").split() == ["veciahorra-frontend", "va-design-system", "va-public-cart"], "RaÃ­z opt-in incorrecta.")
    labelledby = root.get_attribute("aria-labelledby")
    check(bool(labelledby) and root.find_element(By.ID, labelledby).tag_name == "h1", "aria-labelledby no referencia el h1 real.")
    return root


def visible(root, selector):
    elements = root.find_elements(By.CSS_SELECTOR, selector)
    return bool(elements and elements[0].is_displayed())


def click(element):
    driver.execute_script("arguments[0].scrollIntoView({block:'center',inline:'nearest'})", element)
    wait.until(lambda current: element.is_displayed() and element.is_enabled())
    element.click()


def assert_no_overflow(label):
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1"), "Overflow horizontal: " + label)


def calls():
    return driver.execute_script("return window.__vaCartCalls")


def asset_evidence():
    required = {"design-css": "veciahorra-design-system.css", "legacy-css": "veciahorra-frontend.css", "base-js": "veciahorra-frontend.js", "cart-js": "veciahorra-cart.js"}
    observed, failures, cart_request = {}, [], None
    document_ok = False
    for entry in performance:
        message = json.loads(entry["message"])["message"]
        method, params = message.get("method"), message.get("params", {})
        if method == "Network.loadingFailed" and params.get("type") in {"Document", "Stylesheet", "Script"} and not params.get("canceled", False):
            failures.append(params.get("errorText", "loadingFailed"))
        if method != "Network.responseReceived":
            continue
        response, resource_type = params.get("response", {}), params.get("type")
        response_url, status = response.get("url", ""), int(response.get("status", 0))
        if resource_type == "Document" and response_url.startswith(BASE.split("?", 1)[0]) and 200 <= status < 400:
            document_ok = True
        if resource_type in {"Document", "Stylesheet", "Script"} and status >= 400:
            failures.append(f"{resource_type} {status} {response_url}")
        for label, filename in required.items():
            if response_url.split("?", 1)[0].endswith("/" + filename) and status == 200:
                observed[label] = {"status": status, "mime": response.get("mimeType", "")}
                if label == "cart-js":
                    cart_request = params.get("requestId")
    check(document_ok and set(observed) == set(required), "Documento/assets productivos incompletos: " + repr(observed))
    check(not failures, "Fallos de documento/assets: " + repr(failures))
    check(observed["cart-js"]["mime"] in {"text/javascript", "application/javascript", "application/x-javascript"}, "MIME JavaScript invÃ¡lido.")
    body = driver.execute_cdp_cmd("Network.getResponseBody", {"requestId": cart_request})
    remote = base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode("utf-8")
    local = (ROOT / "assets/frontend/js/veciahorra-cart.js").read_bytes()
    check(remote == local, "El JS descargado no coincide byte a byte.")
    result["assets"] = {label: "PASS" for label in required}
    result["assets"].update({"document": "PASS", "cart_sha256": hashlib.sha256(local).hexdigest(), "http": 200, "mime": observed["cart-js"]["mime"]})


try:
    source = (ROOT / "assets/frontend/js/veciahorra-cart.js").read_text(encoding="utf-8")
    compiled = driver.execute_cdp_cmd("Runtime.compileScript", {"expression": source, "sourceURL": "assets/frontend/js/veciahorra-cart.js", "persistScript": False})
    check("exceptionDetails" not in compiled, "Chrome detectÃ³ SyntaxError: " + repr(compiled.get("exceptionDetails")))
    result["compile"] = "PASS"

    driver.set_window_size(1440, 1000)
    root = load("loading")
    check(visible(root, "[data-va-cart-loading]"), "Loading no visible.")
    check(driver.execute_script("return typeof window.__vaCartResolve === 'function'"), "Fixture loading no instalado.")
    driver.execute_script("window.__vaCartResolve()")
    wait.until(lambda current: visible(root, "[data-va-cart-content]"))
    result["states"]["loading"] = "PASS"
    drain_logs()
    asset_evidence()

    session_id = driver.execute_script("return window.VeciAhorra.cart.sessionId")
    header_name = driver.execute_script("return window.VeciAhorra.cart.sessionHeader")
    check(header_name == "X-Veciahorra-Cart-Session" and re.fullmatch(r"[a-f0-9]{64}", session_id or ""), "SesiÃ³n invitada productiva invÃ¡lida.")
    check(all(call["headers"].get("x-veciahorra-cart-session") == session_id for call in calls()), "Header invitado inicial incorrecto.")

    root = load("multiple")
    wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[data-cart-item-id]")) == 2)
    check(root.find_element(By.CSS_SELECTOR, "[data-va-cart-total]").text and "12.345" in root.find_element(By.CSS_SELECTOR, "[data-va-cart-total]").text, "Total autoritativo no renderizado.")
    check("7.777" in root.text and "8.888" in root.text and "1.111" in root.text and "2.222" in root.text, "Snapshots/subtotales autoritativos sustituidos.")
    check(visible(root, ".va-cart-sector-warning"), "Warning sectorial ausente.")
    result["states"]["multiple"] = "PASS"
    result["states"]["sector-warning"] = "PASS"

    increase = root.find_element(By.CSS_SELECTOR, "[data-cart-item-id='701'] [aria-label^='Aumentar']")
    click(increase)
    wait.until(lambda current: len(calls()) >= 3)
    patch_calls = [item for item in calls() if item["method"] == "PATCH"]
    check(len(patch_calls) == 1 and patch_calls[0]["itemId"] == "701" and patch_calls[0]["body"] == {"quantity": 3}, "PATCH no usa item id/payload exactos.")
    current_calls = calls()
    patch_index = next(index for index, item in enumerate(current_calls) if item["method"] == "PATCH")
    check(current_calls[patch_index + 1]["method"] == "GET", "PATCH no fue seguido por GET autoritativo.")
    check(current_calls[patch_index]["headers"].get("x-veciahorra-cart-session") == session_id and current_calls[patch_index + 1]["headers"].get("x-veciahorra-cart-session") == session_id, "SesiÃ³n no conservada en PATCH/GET.")

    remove = root.find_element(By.CSS_SELECTOR, "[data-cart-item-id='702'] [aria-label^='Eliminar']")
    click(remove)
    wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[data-cart-item-id]")) == 1)
    current_calls = calls()
    delete_index = next(index for index, item in enumerate(current_calls) if item["method"] == "DELETE" and item["itemId"] == "702")
    check(current_calls[delete_index]["body"] is None and current_calls[delete_index + 1]["method"] == "GET", "DELETE item no es exacto o no fue seguido por GET.")
    check(current_calls[delete_index]["headers"].get("x-veciahorra-cart-session") == session_id, "Header invitado DELETE item ausente.")

    click(root.find_element(By.CSS_SELECTOR, "[data-va-cart-clear]"))
    wait.until(lambda current: visible(root, "[data-va-cart-empty]"))
    clear_calls = [item for item in calls() if item["method"] == "DELETE" and item["itemId"] is None]
    check(len(clear_calls) == 1 and clear_calls[0]["body"] is None and clear_calls[0]["headers"].get("x-veciahorra-cart-session") == session_id, "Clear incorrecto.")
    result["states"]["clear"] = "PASS"
    result["payloads"] = "PASS"
    result["identity"] = "PASS"
    drain_logs()

    root = load("empty")
    wait.until(lambda current: visible(root, "[data-va-cart-empty]"))
    result["states"]["empty"] = "PASS"
    drain_logs()

    for state, method, message in (("get-error", "GET", "Error GET controlado"), ("patch-error", "PATCH", "Error PATCH controlado"), ("delete-error", "DELETE", "Error DELETE controlado")):
        root = load(state)
        if method == "GET":
            wait.until(lambda current: visible(root, "[data-va-cart-error]"))
            check(message in root.find_element(By.CSS_SELECTOR, "[data-va-cart-error]").text, "Error GET no visible.")
            driver.execute_script("history.replaceState(null,'',location.pathname+'?__va_cart_state=one')")
            click(root.find_element(By.CSS_SELECTOR, "[data-va-cart-retry]"))
            wait.until(lambda current: visible(root, "[data-va-cart-content]"))
            result["states"]["retry"] = "PASS"
        else:
            wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[data-cart-item-id]")) >= 1)
            selector = "[data-cart-item-id='701'] [aria-label^='Aumentar']" if method == "PATCH" else "[data-cart-item-id='701'] [aria-label^='Eliminar']"
            click(root.find_element(By.CSS_SELECTOR, selector))
            wait.until(lambda current: visible(root, "[data-va-cart-error]"))
            check(message in root.find_element(By.CSS_SELECTOR, "[data-va-cart-error]").text, method + " error no visible.")
        result["states"][state] = "PASS"
        drain_logs()

    root = load("one")
    wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[data-cart-item-id]")) == 1)
    checkout = root.find_elements(By.CSS_SELECTOR, "[data-va-cart-checkout]")
    unavailable = root.find_elements(By.CSS_SELECTOR, "[data-va-cart-checkout-unavailable]")
    check(bool(checkout) != bool(unavailable), "Estado checkout ambiguo.")
    if checkout:
        check(checkout[0].get_attribute("href").startswith("http"), "Checkout no navegable.")
        result["states"]["checkout-available"] = "PASS"
        result["states"]["checkout-unavailable"] = "PASS_STATIC_CONDITIONAL"
    else:
        check(unavailable[0].is_displayed(), "Aviso checkout no disponible invisible.")
        result["states"]["checkout-unavailable"] = "PASS"
        result["states"]["checkout-available"] = "PASS_STATIC_CONDITIONAL"
    catalog = root.find_element(By.CSS_SELECTOR, "[data-va-cart-continue-shopping]")
    check(catalog.get_attribute("href").startswith("http"), "Enlace de catÃ¡logo no navegable.")

    catalog.send_keys(Keys.TAB)
    active = driver.switch_to.active_element
    check(active.tag_name in {"a", "button"}, "NavegaciÃ³n por teclado perdiÃ³ controles.")
    result["keyboard"] = "PASS"
    focus_style = driver.execute_script("const s=getComputedStyle(arguments[0]);return [s.outlineStyle,s.outlineWidth,s.boxShadow]", active)
    check((focus_style[0] != "none" and focus_style[1] != "0px") or focus_style[2] != "none", "Foco visible ausente.")
    result["focus"] = "PASS"

    for width, height, label in ((1440, 1000, "desktop"), (375, 812, "mobile-375"), (320, 800, "mobile-320")):
        driver.set_window_size(width, height)
        root = load("multiple")
        wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[data-cart-item-id]")) == 2)
        assert_no_overflow(label)
        for element in root.find_elements(By.CSS_SELECTOR, ".va-button"):
            if element.is_displayed():
                check(element.rect["height"] >= 44 and element.rect["width"] >= 44, "Target menor a 44x44 en " + label)
        shot = Path(captures.name) / (label + ".png")
        check(driver.save_screenshot(str(shot)) and shot.stat().st_size > 0, "Captura invÃ¡lida: " + label)
        result["viewports"][label] = "PASS"
        drain_logs()

    visible_text = driver.find_element(By.TAG_NAME, "body").text.lower()
    for forbidden in ("prestador", "whatsapp", "telÃ©fono", "telefono", "contact_email", "correo del prestador"):
        check(forbidden not in visible_text, "Privacidad vulnerada: " + forbidden)
    result["privacy"] = "PASS"
    severe = [entry for entry in browser_log if entry.get("level") == "SEVERE" and entry.get("source") == "javascript"]
    check(not severe, "Errores JavaScript severos: " + json.dumps(severe, ensure_ascii=False))
    result["console"] = "PASS"
    result["network"] = "PASS"
    result["execution"] = "PASS"
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
finally:
    driver.quit()
    profile.cleanup()
    captures.cleanup()
