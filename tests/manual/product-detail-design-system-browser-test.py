"""Deterministic Chrome certification for the Phase 3 public product detail."""

import base64
import hashlib
import json
import os
import sys
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.common.exceptions import WebDriverException
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("VA_TEST_ROOT", "https://localhost/Minimarket").rstrip("/") + "/catalogo-veciahorra/"
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")
PRODUCT_ID = 1000000852


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def skip(message):
    print(json.dumps({"status": "SKIP", "reason": message}, ensure_ascii=False))
    raise SystemExit(2)


if not Path(DRIVER).is_file():
    skip("ChromeDriver no disponible.")

profile = tempfile.TemporaryDirectory(prefix="va-product-detail-phase3-profile-")
captures = tempfile.TemporaryDirectory(prefix="va-product-detail-phase3-captures-")
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
    "status": "PASS",
    "states": {},
    "viewports": {},
    "assets": {},
    "keyboard": "PENDING",
    "focus": "PENDING",
    "network": "PENDING",
    "console": "PENDING",
    "payload": "PENDING",
    "privacy": "PENDING",
}

INTERCEPT = r"""
(() => {
  const nativeFetch = window.fetch.bind(window);
  const response = (payload, status = 200) => Promise.resolve(new Response(
    JSON.stringify(payload), {status, headers: {'Content-Type': 'application/json'}}
  ));
  const productId = 1000000852;
  const offers = [
    {inventory_id: 1000000101, price: '1250.00', stock: 8},
    {inventory_id: 1000000102, price: '1390.00', stock: 4}
  ];
  const product = currentOffers => ({
    id: productId,
    name: 'Producto de comparación efímero',
    short_description: 'Detalle público sin datos persistentes.',
    description: 'Detalle público sin datos persistentes.',
    image: null,
    category: {id: 7, name: 'Categoría ficticia'},
    brand: {id: 8, name: 'Marca ficticia'},
    unit: {id: 9, name: 'Unidad ficticia'},
    availability: 'in_stock',
    price: {min: '1250.00', max: '1390.00', offers: currentOffers.length},
    offers: currentOffers,
    related_products: [],
    meta: {related_products: 0}
  });
  window.__vaDetailFixtures = {productId, offers};
  window.__vaDetailGetCount = 0;
  window.__vaDetailPostCount = 0;
  window.__vaDetailCartPayload = null;
  window.__vaOffersMode = 'normal';
  window.fetch = function(input, init) {
    const raw = typeof input === 'string' ? input : input.url;
    const url = new URL(raw, window.location.href);
    const detailPath = '/wp-json/veciahorra/v1/catalog/products/' + productId;
    const cartPath = '/wp-json/veciahorra/v1/cart/items';
    const method = String((init && init.method) || 'GET').toUpperCase();
    if (url.pathname.endsWith(detailPath) && method === 'GET') {
      window.__vaDetailGetCount += 1;
      const state = new URL(window.location.href).searchParams.get('__va_detail_state') || 'results';
      if (state === 'loading' && window.__vaDetailGetCount === 1) {
        return new Promise(resolve => { window.__vaDetailResolve = () => response({success: true, data: product(offers)}).then(resolve); });
      }
      if (state === 'load-error') return response({success: false, error: {code: 'catalog_unavailable', message: 'Error controlado de ficha.'}}, 503);
      if (state === 'empty') return response({success: true, data: product([])});
      if (window.__vaOffersMode === 'stale') return response({success: true, data: product([offers[1]])});
      return response({success: true, data: product(offers)});
    }
    if (url.pathname.endsWith(cartPath) && method === 'POST') {
      window.__vaDetailPostCount += 1;
      try { window.__vaDetailCartPayload = JSON.parse(init.body); } catch (error) { window.__vaDetailCartPayload = 'INVALID'; }
      const state = new URL(window.location.href).searchParams.get('__va_detail_state') || 'results';
      if (state === 'cart-error') return response({success: false, error: {code: 'validation_error', message: 'Oferta obsoleta controlada.'}}, 422);
      return response({success: true, data: {created: true, id: 1}}, 201);
    }
    return nativeFetch(input, init);
  };
})();
"""

driver.execute_cdp_cmd("Network.enable", {})
driver.execute_cdp_cmd("Runtime.enable", {})
driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {"source": INTERCEPT})


def url(state):
    return f"{BASE}?product_id={PRODUCT_ID}&__va_detail_state={state}"


def drain_logs():
    performance.extend(driver.get_log("performance"))
    browser_log.extend(driver.get_log("browser"))


def load(state):
    driver.get(url(state))
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    roots = driver.find_elements(By.CSS_SELECTOR, "[data-va-product-detail]")
    if not roots:
        skip("No existe una ficha pública publicada para el entrypoint real.")
    root = roots[0]
    check(root.get_attribute("class").split() == ["veciahorra-frontend", "va-design-system", "va-product-detail"], "Raíz opt-in incorrecta.")
    check(root.get_attribute("data-product-id") == str(PRODUCT_ID), "data-product-id no coincide con el solicitado.")
    labelledby = root.get_attribute("aria-labelledby")
    check(bool(labelledby) and root.find_element(By.ID, labelledby).tag_name == "h1", "aria-labelledby no referencia el h1.")
    return root


def visible(root, selector):
    elements = root.find_elements(By.CSS_SELECTOR, selector)
    return bool(elements and elements[0].is_displayed())


def click_visible(element):
    driver.execute_script("arguments[0].scrollIntoView({block:'center',inline:'nearest'})", element)
    wait.until(lambda current: element.is_displayed() and element.is_enabled() and current.execute_script(
        "const r=arguments[0].getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight", element
    ))
    element.click()


def assert_no_overflow(label):
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1"), "Overflow horizontal: " + label)


def asset_evidence():
    required = {
        "design-css": "veciahorra-design-system.css",
        "legacy-css": "veciahorra-frontend.css",
        "base-js": "veciahorra-frontend.js",
        "offers-js": "veciahorra-product-offers.js",
    }
    observed = {}
    offers_request = None
    failures = []
    document_ok = False
    for entry in performance:
        message = json.loads(entry["message"])["message"]
        method = message.get("method")
        params = message.get("params", {})
        if method == "Network.loadingFailed" and params.get("type") in {"Document", "Stylesheet", "Script"} and not params.get("canceled", False):
            failures.append(params.get("errorText", "loadingFailed"))
        if method != "Network.responseReceived":
            continue
        response = params.get("response", {})
        response_url = response.get("url", "")
        status = int(response.get("status", 0))
        resource_type = params.get("type")
        if resource_type == "Document" and response_url.startswith(BASE) and 200 <= status < 400:
            document_ok = True
        if resource_type in {"Document", "Stylesheet", "Script"} and status >= 400:
            failures.append(f"{resource_type} {status} {response_url}")
        for label, filename in required.items():
            if response_url.split("?", 1)[0].endswith("/" + filename) and status == 200:
                observed[label] = {"url": response_url, "status": status, "mime": response.get("mimeType", "")}
                if label == "offers-js":
                    offers_request = params.get("requestId")
    check(document_ok, "Documento productivo no confirmado.")
    check(set(observed) == set(required), "Assets productivos incompletos: " + repr(observed))
    check(not failures, "Fallos de documento/assets: " + repr(failures))
    check(observed["offers-js"]["mime"] in {"text/javascript", "application/javascript", "application/x-javascript"}, "MIME JavaScript inválido.")
    check(offers_request is not None, "RequestId del JS de ofertas ausente.")
    body = driver.execute_cdp_cmd("Network.getResponseBody", {"requestId": offers_request})
    remote = base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode("utf-8")
    local = (ROOT / "assets/frontend/js/veciahorra-product-offers.js").read_bytes()
    check(remote == local, "El JS descargado no coincide byte a byte.")
    result["assets"] = {label: "PASS" for label in required}
    result["assets"]["document"] = "PASS"
    result["assets"]["offers_sha256"] = hashlib.sha256(local).hexdigest()


try:
    source = (ROOT / "assets/frontend/js/veciahorra-product-offers.js").read_text(encoding="utf-8")
    compiled = driver.execute_cdp_cmd("Runtime.compileScript", {"expression": source, "sourceURL": "assets/frontend/js/veciahorra-product-offers.js", "persistScript": False})
    check("exceptionDetails" not in compiled, "Chrome detectó SyntaxError: " + repr(compiled.get("exceptionDetails")))
    result["compile"] = "PASS"

    driver.set_window_size(1440, 1000)
    root = load("loading")
    check(visible(root, "[data-va-product-loading]"), "Loading no visible.")
    check(driver.execute_script("return typeof window.__vaDetailResolve === 'function'"), "Fixture loading no instalado.")
    driver.execute_script("window.__vaDetailResolve()")
    wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, ".va-offer-card[role='radio']")) == 2)
    result["states"]["loading"] = "PASS"
    result["states"]["results"] = "PASS"
    drain_logs()
    asset_evidence()

    cards = root.find_elements(By.CSS_SELECTOR, "button.va-card.va-offer-card[role='radio']")
    check(len(cards) == 2, "El frontend inventó u omitió ofertas.")
    check([card.get_attribute("data-inventory-id") for card in cards] == ["1000000101", "1000000102"], "data-inventory-id incorrecto.")
    check([card.find_element(By.CSS_SELECTOR, ".va-offer-card__store").text for card in cards] == ["Oferta 1", "Oferta 2"], "Neutral offer numbering mismatch.")
    check("Selecciona la oferta que más te convenga." in root.text, "Public offer instruction mismatch.")
    add = root.find_element(By.CSS_SELECTOR, "[data-va-add-to-cart]")
    check(not add.is_enabled(), "Agregar habilitado sin selección.")
    click_visible(cards[0])
    cards = root.find_elements(By.CSS_SELECTOR, "button.va-card.va-offer-card[role='radio']")
    check(cards[0].get_attribute("aria-checked") == "true" and cards[1].get_attribute("aria-checked") == "false", "Selección no exclusiva.")
    click_visible(add)
    wait.until(lambda current: visible(root, "[data-va-cart-success]"))
    payload = driver.execute_script("return window.__vaDetailCartPayload")
    check(payload == {"inventory_id": 1000000101, "quantity": 1}, "Payload de carrito incorrecto: " + repr(payload))
    check(driver.execute_script("return window.__vaDetailPostCount") == 1, "Cantidad de POST inesperada.")
    result["payload"] = "PASS"
    result["states"]["cart-success"] = "PASS"

    root = load("empty")
    wait.until(lambda current: visible(root, "[data-va-offers-empty]"))
    check(not root.find_elements(By.CSS_SELECTOR, "[role='radio']"), "Empty contiene ofertas inventadas.")
    result["states"]["empty"] = "PASS"
    drain_logs()

    root = load("load-error")
    wait.until(lambda current: visible(root, "[data-va-product-error]"))
    check("Error controlado de ficha" in root.find_element(By.CSS_SELECTOR, "[data-va-product-error]").text, "Error GET incorrecto.")
    result["states"]["error"] = "PASS"
    drain_logs()

    root = load("cart-error")
    wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[role='radio']")) == 2)
    click_visible(root.find_elements(By.CSS_SELECTOR, "[role='radio']")[1])
    click_visible(root.find_element(By.CSS_SELECTOR, "[data-va-add-to-cart]"))
    wait.until(lambda current: visible(root, "[data-va-cart-error]"))
    check("Oferta obsoleta controlada" in root.find_element(By.CSS_SELECTOR, "[data-va-cart-error]").text, "Error POST incorrecto.")
    result["states"]["cart-error"] = "PASS"
    drain_logs()

    root = load("results")
    wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[role='radio']")) == 2)
    click_visible(root.find_elements(By.CSS_SELECTOR, "[role='radio']")[0])
    driver.execute_script("window.__vaOffersMode='stale'")
    driver.execute_async_script("const done=arguments[arguments.length-1];document.querySelector('[data-va-product-detail]').vaOfferSelector.reload().then(done)")
    state = driver.execute_script("return document.querySelector('[data-va-product-detail]').vaOfferSelector.getState()")
    check(state["selectedInventoryId"] is None and state["error"]["code"] == "offer_unavailable", "Oferta obsoleta no limpió selección.")
    result["states"]["stale-offer"] = "PASS"
    drain_logs()

    root = load("results")
    wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[role='radio']")) == 2)
    cards = root.find_elements(By.CSS_SELECTOR, "[role='radio']")
    cards[0].send_keys(Keys.ARROW_DOWN)
    check(root.find_elements(By.CSS_SELECTOR, "[role='radio']")[1].get_attribute("aria-checked") == "true", "ArrowDown falló.")
    driver.switch_to.active_element.send_keys(Keys.HOME)
    check(root.find_elements(By.CSS_SELECTOR, "[role='radio']")[0].get_attribute("aria-checked") == "true", "Home falló.")
    driver.switch_to.active_element.send_keys(Keys.END)
    check(root.find_elements(By.CSS_SELECTOR, "[role='radio']")[1].get_attribute("aria-checked") == "true", "End falló.")
    driver.switch_to.active_element.send_keys(Keys.ARROW_UP)
    driver.switch_to.active_element.send_keys(Keys.ARROW_RIGHT)
    driver.switch_to.active_element.send_keys(Keys.ARROW_LEFT)
    driver.switch_to.active_element.send_keys(Keys.SPACE)
    driver.switch_to.active_element.send_keys(Keys.ENTER)
    check(len(root.find_elements(By.CSS_SELECTOR, "[aria-checked='true']")) == 1, "Teclado rompió selección exclusiva.")
    result["keyboard"] = "PASS"
    focus_style = driver.execute_script("const s=getComputedStyle(arguments[0]);return [s.outlineStyle,s.outlineWidth,s.boxShadow]", driver.switch_to.active_element)
    check((focus_style[0] != "none" and focus_style[1] != "0px") or focus_style[2] != "none", "Foco visible ausente.")
    result["focus"] = "PASS"

    for width, height, label in ((1440, 1000, "desktop"), (375, 812, "mobile-375"), (320, 800, "mobile-320")):
        driver.set_window_size(width, height)
        root = load("results")
        wait.until(lambda current: len(root.find_elements(By.CSS_SELECTOR, "[role='radio']")) == 2)
        assert_no_overflow(label)
        for element in root.find_elements(By.CSS_SELECTOR, "[role='radio'], .va-button"):
            if element.is_displayed():
                check(element.rect["height"] >= 44 and element.rect["width"] >= 44, "Target menor a 44x44 en " + label)
        shot = Path(captures.name) / (label + ".png")
        check(driver.save_screenshot(str(shot)) and shot.stat().st_size > 0, "Captura inválida: " + label)
        result["viewports"][label] = "PASS"
        drain_logs()

    visible_text = driver.find_element(By.TAG_NAME, "body").text.lower()
    for forbidden in ("prestador", "whatsapp", "teléfono", "telefono", "contact_email", "correo del prestador"):
        check(forbidden not in visible_text, "Privacidad vulnerada: " + forbidden)
    result["privacy"] = "PASS"

    severe = [entry for entry in browser_log if entry.get("level") == "SEVERE" and entry.get("source") == "javascript"]
    check(not severe, "Errores JavaScript severos: " + json.dumps(severe, ensure_ascii=False))
    result["console"] = "PASS"
    result["network"] = "PASS"
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
finally:
    driver.quit()
    profile.cleanup()
    captures.cleanup()
