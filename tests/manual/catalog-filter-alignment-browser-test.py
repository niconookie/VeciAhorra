"""Deterministic, read-only browser certification for the public catalog."""

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

BASE = os.environ.get("VA_TEST_ROOT", "https://localhost/Minimarket").rstrip("/") + "/catalogo-veciahorra/"
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def skip(message):
    print(json.dumps({"status": "SKIP", "reason": message}, ensure_ascii=False))
    raise SystemExit(2)


if not Path(DRIVER).is_file():
    skip("ChromeDriver no disponible.")

profile = tempfile.TemporaryDirectory(prefix="va-catalog-phase2-profile-")
captures = tempfile.TemporaryDirectory(prefix="va-catalog-phase2-captures-")
options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--ignore-certificate-errors")
options.add_argument("--allow-insecure-localhost")
options.add_argument("--disable-gpu")
options.add_argument("--no-sandbox")
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
result = {"status": "PASS", "states": {}, "viewports": {}, "screenshots": {}}

INTERCEPT = r"""
(() => {
  const nativeFetch = window.fetch.bind(window);
  const response = (payload, status = 200) => Promise.resolve(new Response(
    JSON.stringify(payload),
    {status, headers: {'Content-Type': 'application/json'}}
  ));
  const product = {
    id: 1000000852,
    name: 'Producto de prueba visual',
    slug: 'producto-prueba-visual',
    image: null,
    min_price: '1250.00',
    available_minimarkets: 2
  };
  let errorDelivered = false;
  window.fetch = function(input, init) {
    const raw = typeof input === 'string' ? input : input.url;
    const url = new URL(raw, window.location.href);
    const categoriesPath = '/wp-json/veciahorra/v1/catalog/categories';
    const productsPath = '/wp-json/veciahorra/v1/catalog/products';
    const isCategories = url.pathname.endsWith(categoriesPath);
    const isProducts = url.pathname.endsWith(productsPath);
    if (!isCategories && !isProducts) return nativeFetch(input, init);
    const state = new URL(window.location.href).searchParams.get('__va_catalog_state') || 'results';
    if (isCategories) {
      return response({success: true, data: [{id: 7, name: 'Categoría visual', products_count: 1}]});
    }
    if (isProducts) {
      if (state === 'loading') {
        return new Promise(resolve => {
          window.__vaCatalogResolve = () => response({success: true, data: [product]}).then(resolve);
        });
      }
      if (state === 'empty') return response({success: true, data: []});
      if (state === 'error' && !errorDelivered) {
        errorDelivered = true;
        return response({success: false, error: {code: 'catalog_unavailable', message: 'Error controlado de catálogo.'}}, 503);
      }
      return response({success: true, data: [product]});
    }
    return nativeFetch(input, init);
  };
})();
"""

driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {"source": INTERCEPT})


def state_url(state):
    return BASE + "?__va_catalog_state=" + state


def load(state):
    driver.get(state_url(state))
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    roots = driver.find_elements(By.CSS_SELECTOR, "[data-va-catalog]")
    if not roots:
        skip("No existe una página publicada con [data-va-catalog].")
    root = roots[0]
    check(root.get_attribute("class").split() == ["veciahorra-frontend", "va-design-system", "va-catalog"], "Raíz de catálogo incorrecta.")
    return root


def visible(selector):
    elements = driver.find_elements(By.CSS_SELECTOR, selector)
    return bool(elements and elements[0].is_displayed())


def assert_no_overflow(label):
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1"), "Overflow horizontal: " + label)


def capture(label):
    path = Path(captures.name) / (label + ".png")
    check(driver.save_screenshot(str(path)), "No fue posible capturar " + label)
    check(path.is_file() and path.stat().st_size > 0, "Captura vacía: " + label)
    result["screenshots"][label] = path.stat().st_size


def click_visible(element):
    driver.execute_script("arguments[0].scrollIntoView({block:'center',inline:'nearest'})", element)
    wait.until(lambda current: element.is_displayed() and element.is_enabled() and current.execute_script(
        "const r=arguments[0].getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight", element
    ))
    element.click()


def network_failures():
    failures = []
    for entry in driver.get_log("performance"):
        message = json.loads(entry["message"])["message"]
        method = message.get("method")
        params = message.get("params", {})
        if method == "Network.loadingFailed" and params.get("type") in {"Document", "Stylesheet", "Script"} and not params.get("canceled", False):
            failures.append(f"{params.get('type')} {params.get('errorText', 'Network.loadingFailed')}")
        if method == "Network.responseReceived" and params.get("type") in {"Document", "Stylesheet", "Script"}:
            response = params.get("response", {})
            if int(response.get("status", 0)) >= 400:
                failures.append(f"{params.get('type')} {response.get('status')} {response.get('url')}")
    return failures


try:
    try:
        driver.set_window_size(1440, 1000)
        root = load("loading")
    except WebDriverException as exception:
        skip("VA_TEST_ROOT inaccesible: " + str(exception))

    check(visible("[data-va-catalog-loading]"), "Loading no visible.")
    check(not visible("[data-va-catalog-grid]") and not visible("[data-va-catalog-empty]") and not visible("[data-va-catalog-error]"), "Loading mezcló estados.")
    result["states"]["loading"] = "PASS"
    check(driver.execute_script("return typeof window.__vaCatalogResolve === 'function'"), "Seam de loading no instalado.")
    driver.execute_script("window.__vaCatalogResolve()")
    wait.until(lambda current: visible(".va-catalog-card"))
    card = driver.find_element(By.CSS_SELECTOR, ".va-catalog-card")
    check("va-card" in card.get_attribute("class").split(), "Tarjeta sin va-card.")
    link = card.find_element(By.CSS_SELECTOR, "a.va-button.va-button--primary.va-catalog-card__action")
    href = link.get_attribute("href")
    check(link.text == "Ver producto" and "product_id=1000000852" in href and href.startswith(BASE), "Enlace canónico de ficha inválido.")
    result["states"]["results"] = "PASS"

    load("empty")
    wait.until(lambda current: visible("[data-va-catalog-empty]"))
    check(not visible("[data-va-catalog-grid]") and not visible("[data-va-catalog-error]"), "Empty mezcló estados.")
    result["states"]["empty"] = "PASS"

    load("error")
    wait.until(lambda current: visible("[data-va-catalog-error]"))
    check("Error controlado de catálogo" in driver.find_element(By.CSS_SELECTOR, "[data-va-catalog-error]").text, "Mensaje de error incorrecto.")
    click_visible(driver.find_element(By.CSS_SELECTOR, "[data-va-catalog-retry]"))
    wait.until(lambda current: visible(".va-catalog-card"))
    result["states"]["error"] = "PASS"
    result["states"]["retry"] = "PASS"

    for width, height, label in [(1440, 1000, "desktop"), (375, 812, "mobile-375"), (320, 800, "mobile-320")]:
        driver.set_window_size(width, height)
        load("results")
        wait.until(lambda current: visible(".va-catalog-card"))
        assert_no_overflow(label)
        columns = driver.execute_script("return getComputedStyle(document.querySelector('[data-va-catalog-grid]')).gridTemplateColumns.split(' ').length")
        if width <= 375:
            check(columns == 1, "El grid no usa una columna en " + label)
        for selector in ["button[type='submit']", "[data-va-catalog-reset]", "[data-va-catalog-retry]", ".va-catalog-card__action"]:
            elements = driver.find_elements(By.CSS_SELECTOR, selector)
            if elements and elements[0].is_displayed():
                rect = elements[0].rect
                check(rect["height"] >= 44 and rect["width"] >= 44, "Target menor a 44x44: " + selector + " " + label)
        capture(label)
        result["viewports"][label] = "PASS"

    driver.set_window_size(1440, 1000)
    load("results")
    wait.until(lambda current: visible(".va-catalog-card"))
    driver.find_element(By.TAG_NAME, "body").click()
    focused = None
    for _ in range(30):
        driver.switch_to.active_element.send_keys(Keys.TAB)
        active = driver.switch_to.active_element
        if active.get_attribute("type") == "submit":
            focused = active
            break
    check(focused is not None, "El teclado no alcanzó Aplicar filtros.")
    focus_style = driver.execute_script("const s=getComputedStyle(arguments[0]);return [s.outlineStyle,s.outlineWidth,s.boxShadow]", focused)
    check((focus_style[0] != "none" and focus_style[1] != "0px") or focus_style[2] != "none", "Foco visible ausente.")
    result["keyboard"] = "PASS"

    severe_js = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE" and entry.get("source") == "javascript"]
    check(not severe_js, "Errores JavaScript: " + json.dumps(severe_js, ensure_ascii=False))
    failures = network_failures()
    check(not failures, "Errores de documento/assets: " + json.dumps(failures, ensure_ascii=False))
    result["console"] = "PASS"
    result["network"] = "PASS"
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
finally:
    driver.quit()
    profile.cleanup()
    captures.cleanup()
