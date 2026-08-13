"""Browser validation for minimarket order-detail navigation and isolation."""

import json
import tempfile

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"


def check(condition, message):
    if not condition:
        raise AssertionError(message)


profile = tempfile.TemporaryDirectory(prefix="va-mm-order-detail-")
options = webdriver.ChromeOptions()
for argument in [
    "--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost",
    "--disable-gpu", "--no-sandbox", "--window-size=1440,900",
    "--user-data-dir=" + profile.name,
]:
    options.add_argument(argument)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
result = {}


def login():
    driver.get(BASE + "/wp-login.php")
    driver.find_element(By.ID, "user_login").send_keys("va_demo_minimarket_vecinos")
    driver.find_element(By.ID, "user_pass").send_keys("VA-Vecinos-2026!")
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda current: "/panel-minimarket/" in current.current_url)


def open_orders():
    wait.until(lambda current: len(current.find_elements(By.CSS_SELECTOR, "[data-inventory] .va-minimarket__card")) > 0)
    driver.find_element(By.CSS_SELECTOR, '[data-tab="orders"]').click()
    wait.until(lambda current: len(current.find_elements(By.CSS_SELECTOR, "[data-orders] .va-minimarket__order")) > 0)


def click_order(order_id):
    card = next(
        card for card in driver.find_elements(By.CSS_SELECTOR, "[data-orders] .va-minimarket__order")
        if "Pedido #" + str(order_id) in card.text
    )
    link = card.find_element(By.LINK_TEXT, "Ver pedido")
    check("order_id=" + str(order_id) in link.get_attribute("href"), "Href incorrecto para " + str(order_id))
    link.click()
    detail = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, ".va-minimarket__order-detail"))
    wait.until(lambda current: "Pedido #" + str(order_id) in detail.text and "Productos" in detail.text)
    check("Fecha" in detail.text and "Total" in detail.text and "Estado" in detail.text, "Detalle incompleto")
    check("order_id=" + str(order_id) in driver.current_url, "URL no representa el pedido")
    check(driver.find_element(By.CSS_SELECTOR, "[data-orders]").get_attribute("hidden") is not None, "Listado visible detrás del detalle")
    top = driver.execute_script("return arguments[0].getBoundingClientRect().top", detail)
    check(0 <= top < driver.execute_script("return innerHeight"), "Detalle fuera del viewport")
    return detail.text


def api_status(order_id):
    return driver.execute_async_script(
        "const done=arguments[arguments.length-1];"
        "fetch(window.VeciAhorraMinimarket.restUrl+'orders/'+arguments[0],{credentials:'same-origin',headers:{'X-WP-Nonce':window.VeciAhorraMinimarket.nonce}})"
        ".then(r=>done(r.status)).catch(()=>done(0));",
        order_id,
    )


try:
    login()
    open_orders()
    cards = driver.find_elements(By.CSS_SELECTOR, "[data-orders] .va-minimarket__order")
    check(any("Pedido #1313" in card.text for card in cards), "Pedido #1313 ausente")
    check(any("Pedido #1311" in card.text for card in cards), "Pedido #1311 ausente")
    result["list"] = "PASS"

    detail_1313 = click_order(1313)
    check("Coca-Cola 350 cc" in detail_1313, "#1313 abrió productos incorrectos")
    driver.find_element(By.LINK_TEXT, "← Volver a Mis pedidos").click()
    wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-orders]").get_attribute("hidden") is None)
    detail_1311 = click_order(1311)
    check("Coca-Cola Original 1,5 L" in detail_1311, "#1311 abrió productos incorrectos")
    result["orders"] = {"1313": "PASS", "1311": "PASS", "routing": "PASS"}

    check(api_status(1312) == 404, "Pedido ajeno no fue ocultado con 404")
    check(api_status(999999999) == 404, "ID inexistente no fue manejado con 404")
    result["isolation"] = {"foreign": "PASS", "invalid": "PASS"}

    driver.get(BASE + "/panel-minimarket/?order_id=1313")
    wait.until(lambda current: "Pedido #1313" in current.find_element(By.CSS_SELECTOR, "[data-order-detail]").text)
    check("Coca-Cola 350 cc" in driver.find_element(By.CSS_SELECTOR, "[data-order-detail]").text, "Acceso directo incorrecto")
    result["direct_url"] = "PASS"

    driver.set_window_size(390, 844)
    driver.get(BASE + "/panel-minimarket/?order_id=1311")
    detail = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, ".va-minimarket__order-detail"))
    wait.until(lambda current: "Pedido #1311" in detail.text)
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Overflow móvil")
    check(driver.find_element(By.LINK_TEXT, "← Volver a Mis pedidos").is_displayed(), "Volver no visible en móvil")
    check("Warning:" not in driver.page_source and "Notice:" not in driver.page_source, "Warning/notice PHP visible")
    result["desktop"] = result["mobile"] = "PASS"

    driver.delete_all_cookies()
    driver.get(BASE + "/panel-minimarket/?order_id=1313")
    check(not driver.find_elements(By.CSS_SELECTOR, "[data-va-minimarket]"), "Visitante recibió el panel privado")
    check("iniciar sesión" in driver.find_element(By.TAG_NAME, "body").text.lower(), "Visitante no fue bloqueado")
    result["guest"] = "PASS"

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE" and entry.get("source") == "javascript" and "Bikrimart-Delivery.json" not in entry.get("message", "")]
    check(not severe, "Errores JS: " + json.dumps(severe, ensure_ascii=True))
    result["js_errors"] = 0
    result["php_warnings"] = 0
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
