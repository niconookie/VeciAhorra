"""Read-only production-data browser review for the P0 public catalog."""

import json
import os
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import Select, WebDriverWait

ROOT = os.environ.get("VA_TEST_ROOT", "https://localhost/Minimarket").rstrip("/")
CATALOG = ROOT + "/catalogo-veciahorra/"
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")
OUTPUT = Path("artifacts/catalog-p0-review").resolve()


def check(condition, message):
    if not condition:
        raise AssertionError(message)


OUTPUT.mkdir(parents=True, exist_ok=True)
profile = tempfile.TemporaryDirectory(prefix="va-catalog-p0-")
options = webdriver.ChromeOptions()
for argument in ["--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"]:
    options.add_argument(argument)
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 30)
result = {"status": "PASS", "viewports": {}, "roles": {}, "search": {}, "console": {}}


def exact_viewport(width, height):
    driver.set_window_size(width, height)
    chrome_width = driver.execute_script("return window.outerWidth - window.innerWidth")
    chrome_height = driver.execute_script("return window.outerHeight - window.innerHeight")
    driver.set_window_size(width + chrome_width, height + chrome_height)
    check(driver.execute_script("return window.innerWidth") == width, "Viewport inexacto: " + str(width))


def open_catalog(width, height):
    exact_viewport(width, height)
    driver.get(CATALOG)
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    root = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-va-catalog]"))
    check(len(driver.find_elements(By.CSS_SELECTOR, "[data-va-global-header]")) == 1, "Cabecera global duplicada")
    check("Catálogo VeciAhorra" not in root.text, "Hero interno antiguo visible")
    check("Productos cerca de ti" in root.text, "Título P0 ausente")
    return root


def select_real_sector():
    selectors = driver.find_elements(By.CSS_SELECTOR, "[data-va-sector-select]")
    if not selectors:
        return
    select = Select(selectors[0])
    wait.until(lambda current: len(select.options) > 1)
    if select.first_selected_option.get_attribute("value") == "":
        value = select.options[1].get_attribute("value")
        driver.execute_async_script("let done=arguments[arguments.length-1],id=arguments[0];fetch('/Minimarket/wp-json/veciahorra/v1/sector/current/'+id,{method:'POST',credentials:'same-origin'}).then(r=>r.json()).then(done)", value)


def wait_catalog():
    wait.until(lambda current: not current.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())


def columns():
    return driver.execute_script("return getComputedStyle(document.querySelector('[data-va-catalog-grid]')).gridTemplateColumns.split(' ').filter(Boolean).length")


def no_overflow(label):
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1"), "Overflow horizontal: " + label)


def capture(label):
    path = OUTPUT / (label + ".png")
    check(driver.save_screenshot(str(path)) and path.stat().st_size > 0, "Captura inválida: " + label)
    return str(path)


def login_customer():
    driver.delete_all_cookies()
    driver.get(ROOT + "/wp-login.php")
    wait.until(lambda current: current.find_element(By.ID, "loginform").is_displayed())
    driver.find_element(By.ID, "user_login").send_keys("va_demo_carolina")
    driver.find_element(By.ID, "user_pass").send_keys("VA-Cliente-2026!")
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda current: "/mis-compras/" in current.current_url)


try:
    root = open_catalog(1440, 1000)
    select_real_sector()
    root = open_catalog(1440, 1000)
    wait_catalog()

    status = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-status]").text
    check("opci" in status.lower() and any(character.isdigit() for character in status), "Cantidad real ausente")
    order = Select(root.find_element(By.CSS_SELECTOR, "[data-va-catalog-order]"))
    check(order.first_selected_option.get_attribute("value") == "price", "Precio más conveniente no está seleccionado")
    prices = driver.execute_script("return Array.from(document.querySelectorAll('.va-catalog-card__price-value')).map(node => Number(node.textContent.replace(/[^0-9]/g, '')))")
    check(prices == sorted(prices), "Las tarjetas no están ordenadas por precio mínimo ascendente")
    result["price_order"] = {"default": "price", "ascending_minimums": prices}

    category = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-category]")
    check(category.is_enabled(), "Categoría permanece bloqueada con autoridad productiva disponible")
    check(driver.execute_script("return getComputedStyle(arguments[0]).pointerEvents", category) == "auto", "Categoría interceptada por pointer-events")
    category_options = Select(category).options
    check(len(category_options) > 2, "Categorías reales no disponibles")
    search_control = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-search]")
    search_control.click()
    search_control.send_keys(Keys.TAB)
    check(driver.switch_to.active_element == category, "Tab no alcanza el selector Categoría")
    first_category = category_options[1].get_attribute("value")
    Select(category).select_by_index(1)
    wait.until(lambda current: "category=" + first_category in current.current_url and not current.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())
    check(Select(category).first_selected_option.get_attribute("value") == first_category, "Selección con mouse no persistió")
    filtered_count = len(root.find_elements(By.CSS_SELECTOR, ".va-catalog-card"))
    check(filtered_count > 0, "Categoría real no devolvió resultados")
    driver.refresh()
    root = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-va-catalog]"))
    wait_catalog()
    category = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-category]")
    check(Select(category).first_selected_option.get_attribute("value") == first_category, "Selección GET no sobrevivió recarga")
    category.send_keys(Keys.HOME, Keys.ARROW_DOWN, Keys.ARROW_DOWN, Keys.ENTER)
    wait.until(lambda current: "category=" in current.current_url and "category=" + first_category not in current.current_url and not current.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())
    keyboard_category = Select(category).first_selected_option.get_attribute("value")
    check(keyboard_category not in ["", first_category], "Teclado no cambió la categoría")
    subcategory = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-subcategory]")
    child_values = [option.get_attribute("value") for option in Select(subcategory).options if option.get_attribute("value")]
    check(subcategory.is_enabled() == bool(child_values), "Subcategoría no refleja hijos reales")
    result["category_filter"] = {"mouse": first_category, "keyboard": keyboard_category, "tab": "PASS", "filtered_results": filtered_count, "get_persistence": "PASS", "subcategories": child_values}
    check(root.find_element(By.CSS_SELECTOR, "[data-va-catalog-filters]").get_attribute("method").lower() == "get", "Búsqueda GET perdida")
    search = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-search]")
    search.send_keys("zzzz-consulta-sin-resultados")
    root.find_element(By.CSS_SELECTOR, "[data-va-catalog-filters]").submit()
    wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-va-catalog-empty]").is_displayed())
    check("category=" + keyboard_category in driver.current_url and "search=zzzz-consulta-sin-resultados" in driver.current_url, "GET combinado categoría/búsqueda incorrecto")
    reset = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-reset]")
    reset.click()
    wait.until(lambda current: "category=" not in current.current_url and "search=" not in current.current_url and not current.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())
    check(Select(root.find_element(By.CSS_SELECTOR, "[data-va-catalog-category]")).first_selected_option.get_attribute("value") == "", "Limpiar no restauró Categoría")
    result["search"] = {"get": "PASS", "empty_with_category": "PASS", "reset": "PASS"}

    expected = {1920: 4, 1440: 4, 1024: 3, 768: 2, 504: 1}
    labels = {1920: "desktop-1920", 1440: "desktop-1440", 1024: "desktop-1024", 768: "tablet-768", 504: "mobile-504"}
    for width, expected_columns in expected.items():
        root = open_catalog(width, 1000 if width >= 1024 else 900)
        wait_catalog()
        no_overflow(labels[width])
        actual_columns = columns()
        check(actual_columns == expected_columns, f"Grid {actual_columns} columnas a {width}; esperado {expected_columns}")
        toggle = root.find_element(By.CSS_SELECTOR, "[data-va-catalog-filters-toggle]")
        check(toggle.is_displayed() == (width <= 1100), "Estado de filtros incorrecto a " + str(width))
        if toggle.is_displayed():
            check(toggle.size["height"] >= 44 and toggle.size["width"] >= 44, "Target de filtros menor a 44 px")
            toggle.click()
            check("va-catalog--filters-open" in root.get_attribute("class"), "Filtros no reabren a " + str(width))
            toggle.send_keys(Keys.ESCAPE)
            wait.until(lambda current: "va-catalog--filters-open" not in root.get_attribute("class"))
            check(toggle.get_attribute("aria-expanded") == "false", "Escape no cerró filtros a " + str(width))
        for button in root.find_elements(By.CSS_SELECTOR, ".va-catalog-card__add"):
            check(button.size["height"] >= 44 and button.size["width"] >= 44, "Target + menor a 44 px")
        result["viewports"][str(width)] = {"columns": actual_columns, "overflow": "PASS", "capture": capture(labels[width])}

    result["roles"]["visitor"] = "PASS"
    login_customer()
    root = open_catalog(1440, 1000)
    select_real_sector()
    root = open_catalog(1440, 1000)
    wait_catalog()
    no_overflow("customer")
    check(driver.find_element(By.CSS_SELECTOR, ".va-global-header__account").is_displayed(), "Cuenta cliente ausente")
    result["roles"]["customer"] = "PASS"

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE"]
    js_errors = [entry for entry in severe if "javascript" in entry.get("source", "").lower()]
    mixed = [entry for entry in severe if "mixed content" in entry.get("message", "").lower()]
    responses = [json.loads(entry["message"])["message"]["params"]["response"] for entry in driver.get_log("performance") if json.loads(entry["message"])["message"]["method"] == "Network.responseReceived"]
    failures = [response["url"] for response in responses if int(response["status"]) >= 500]
    check(not js_errors, "Errores JavaScript: " + json.dumps(js_errors, ensure_ascii=False))
    check(not mixed, "Mixed content: " + json.dumps(mixed, ensure_ascii=False))
    check(not failures, "HTTP 5xx: " + json.dumps(failures, ensure_ascii=False))
    result["console"] = {"javascript": 0, "mixed_content": 0, "http_5xx": 0}
    (OUTPUT / "result.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
