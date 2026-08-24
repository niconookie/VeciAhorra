"""Visual release harness for the VeciAhorra global header."""

import json
import os
import tempfile

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import Select
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
OUTPUT = os.path.abspath("artifacts/header-release-review")
CREDS = {
    "customer": ("va_demo_carolina", "VA-Cliente-2026!", "/mis-compras/"),
    "store": ("va_demo_minimarket_vecinos", "VA-Vecinos-2026!", "/panel-minimarket/"),
}


def check(condition, message):
    if not condition:
        raise AssertionError(message)


os.makedirs(OUTPUT, exist_ok=True)
profile = tempfile.TemporaryDirectory(prefix="va-release-header-")
options = webdriver.ChromeOptions()
for argument in ["--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"]:
    options.add_argument(argument)
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
result = {"screenshots": [], "checks": {}, "breakpoints": {}, "js_errors": []}


def open_page(path, size):
    driver.set_window_size(*size)
    chrome_width = driver.execute_script("return window.outerWidth - window.innerWidth")
    chrome_height = driver.execute_script("return window.outerHeight - window.innerHeight")
    driver.set_window_size(size[0] + chrome_width, size[1] + chrome_height)
    driver.get(BASE + path)
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    header = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-va-global-header]"))
    check(len(driver.find_elements(By.CSS_SELECTOR, "[data-va-global-header]")) == 1, "Cabecera VeciAhorra duplicada")
    check(not driver.find_element(By.ID, "header").is_displayed(), "Cabecera del tema visible")
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Overflow horizontal")
    check("Warning:" not in driver.page_source and "Fatal error" not in driver.page_source, "Error PHP visible")
    return header


def check_breakpoint(width):
    header = open_page("/", (width, 900))
    check(driver.execute_script("return window.innerWidth") == width, "Viewport inexacto para " + str(width))
    compact = width <= 1100
    toggle = header.find_element(By.CSS_SELECTOR, ".va-global-header__menu-toggle")
    navigation = header.find_element(By.ID, "va-global-navigation")
    check(toggle.is_displayed() == compact, "Modo de cabecera incorrecto a " + str(width))
    check(navigation.is_displayed() != compact, "Navegación incorrecta a " + str(width))
    search = header.find_element(By.ID, "va-global-search")
    check(search.size["width"] >= 160, "Campo de búsqueda inutilizable a " + str(width))
    check(search.get_attribute("placeholder") == "¿Qué producto necesitas?", "Placeholder incorrecto a " + str(width))
    for selector in [".va-global-header__logo", ".va-global-header__sector", "[data-va-header-search-scope]", ".va-global-header__account", ".va-global-header__cart"]:
        check(header.find_element(By.CSS_SELECTOR, selector).is_displayed(), "Control ausente a " + str(width) + ": " + selector)
    if not compact:
        nav_inner = header.find_element(By.CSS_SELECTOR, ".va-global-header__nav-inner")
        check(driver.execute_script("return arguments[0].scrollWidth <= arguments[0].clientWidth", nav_inner), "Navegación comprimida a " + str(width))
    result["breakpoints"][str(width)] = {
        "mode": "compact" if compact else "desktop",
        "search_input_width": search.size["width"],
        "placeholder": search.get_attribute("placeholder"),
    }
    return header


def shot(name):
    path = os.path.join(OUTPUT, name + ".png")
    driver.save_screenshot(path)
    result["screenshots"].append(path)


def login(key):
    username, password, destination = CREDS[key]
    driver.delete_all_cookies()
    driver.get(BASE + "/wp-login.php")
    wait.until(lambda current: current.find_element(By.ID, "loginform").is_displayed())
    driver.find_element(By.ID, "user_login").send_keys(username)
    driver.find_element(By.ID, "user_pass").send_keys(password)
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda current: destination in current.current_url)


try:
    for breakpoint_width in [768, 846, 900, 1024, 1366, 1920]:
        check_breakpoint(breakpoint_width)
    compact_inner = open_page("/catalogo-veciahorra/", (846, 900))
    check(compact_inner.find_element(By.CSS_SELECTOR, ".va-global-header__breadcrumb").is_displayed(), "Breadcrumb ausente en compacto a 846")

    driver.delete_all_cookies()
    header = open_page("/", (1750, 900))
    expected = ["Categorías", "Ofertas", "VeciMarket", "VeciServicios", "Quiénes somos", "Vende en VeciAhorra", "Reparte con nosotros"]
    actual = [link.text.strip().splitlines()[-1] for link in header.find_elements(By.CSS_SELECTOR, ".va-global-header__nav a")]
    check(actual == expected, "Orden de navegación inesperado: " + repr(actual))
    logo = header.find_element(By.CSS_SELECTOR, ".va-global-header__logo")
    check(logo.is_displayed() and logo.size["width"] >= 220 and logo.size["height"] >= 50, "Logo horizontal oficial no legible en escritorio")
    check(logo.get_attribute("src").endswith("/assets/frontend/images/veciahorra-logo-horizontal.png"), "La cabecera no usa la variante horizontal oficial")
    shot("01-desktop-wide-guest")
    hero_image = driver.find_element(By.CSS_SELECTOR, ".va-home-hero .elementor-widget-image img")
    hero_src = hero_image.get_attribute("src")
    check(hero_src.endswith("/assets/frontend/images/veciahorra-logo-oficial.png"), "El hero no usa el PNG oficial autorizado: " + hero_src)
    shot("11-homepage-official-hero")
    search = header.find_element(By.ID, "va-global-search")
    check(search.get_attribute("placeholder") == "¿Qué producto necesitas?", "Placeholder productivo incorrecto")
    search.send_keys("pan integral")
    search.click()
    shot("07-search-focused")
    scope = Select(header.find_element(By.ID, "va-global-search-scope"))
    scope.select_by_value("services")
    check(not search.is_enabled(), "Servicios no debe simular búsqueda textual")
    check(search.get_attribute("value") == "", "Servicios no debe enviar ni mantener visible un término textual")
    check("servicios disponibles" in header.find_element(By.ID, "va-global-search-context").get_attribute("textContent").lower(), "Servicios no comunica su alcance territorial")
    check("/servicios/" in header.find_element(By.CSS_SELECTOR, "[data-va-header-search]").get_attribute("action"), "Servicios no apunta al listado canónico")
    check(header.find_element(By.CSS_SELECTOR, "[data-va-header-search] button").text == "Ver servicios", "CTA de Servicios no describe el comportamiento real")
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Servicios produce overflow horizontal")
    shot("10-search-services")
    scope.select_by_value("products")
    check(search.is_enabled(), "Productos perdió la búsqueda GET progresiva")
    check(search.get_attribute("value") == "pan integral", "El término de Productos no se conservó al cambiar de ámbito")
    sector = header.find_element(By.CSS_SELECTOR, ".va-global-header__sector")
    navigation_top = header.find_element(By.ID, "va-global-navigation").rect["y"]
    sector.click()
    wait.until(lambda current: not header.find_element(By.ID, "va-global-sector-panel").get_attribute("hidden"))
    panel = header.find_element(By.ID, "va-global-sector-panel")
    check(panel.size["width"] <= 440 and header.find_element(By.ID, "va-global-navigation").rect["y"] == navigation_top, "Popover desplaza la navegación")
    shot("08-sector-open")
    driver.execute_script("var b=document.querySelector('[data-va-header-cart-count]');b.hidden=false;b.textContent='3';")
    shot("09-cart-badge-visual-fixture")

    login("customer")
    open_page("/mis-compras/", (1440, 900))
    visible_titles = [node for node in driver.find_elements(By.XPATH, "//*[normalize-space(text())='Mis compras']") if node.is_displayed() and node.tag_name.lower() in ["h1", "h2"]]
    check(len(visible_titles) == 1, "Título Mis compras duplicado")
    shot("02-desktop-customer")

    login("store")
    open_page("/panel-minimarket/", (1440, 900))
    shot("03-desktop-commercial")

    driver.delete_all_cookies()
    open_page("/", (846, 1024))
    shot("04-tablet-guest")
    header = open_page("/", (390, 844))
    mobile_logo = header.find_element(By.CSS_SELECTOR, ".va-global-header__logo")
    check(mobile_logo.size["width"] >= 130 and mobile_logo.size["height"] >= 30, "Logo horizontal móvil ilegible")
    shot("05-mobile-menu-closed")
    toggle = header.find_element(By.CSS_SELECTOR, ".va-global-header__menu-toggle")
    check(toggle.size["width"] >= 44 and toggle.size["height"] >= 44, "Target movil inferior a 44px")
    toggle.click()
    wait.until(lambda current: header.find_element(By.ID, "va-global-navigation").is_displayed())
    shot("06-mobile-menu-open")

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE"]
    result["js_errors"] = [entry for entry in severe if "javascript" in entry.get("source", "").lower()]
    check(not result["js_errors"], "Errores JavaScript: " + json.dumps(result["js_errors"], ensure_ascii=False))
    result["mixed_content_errors"] = [entry for entry in severe if "mixed content" in entry.get("message", "").lower()]
    responses = [json.loads(entry["message"])["message"]["params"]["response"] for entry in driver.get_log("performance") if json.loads(entry["message"])["message"]["method"] == "Network.responseReceived"]
    result["http_5xx"] = [response["url"] for response in responses if int(response["status"]) >= 500]
    check(not result["mixed_content_errors"] and not result["http_5xx"], "Mixed content o HTTP 5xx detectado")
    result["checks"] = {"desktop": "PASS", "tablet": "PASS", "mobile": "PASS", "duplicate": "PASS", "overflow": "PASS", "touch_target": "PASS", "navigation_order": "PASS", "microzone_popover": "PASS", "single_purchases_title": "PASS", "search_scopes": "PASS", "compact_breadcrumb_846": "PASS"}
    with open(os.path.join(OUTPUT, "result.json"), "w", encoding="utf-8") as stream:
        json.dump(result, stream, ensure_ascii=False, indent=2)
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
