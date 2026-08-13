"""Browser validation for the final Minimarket panel UX; performs no writes."""
import json
import tempfile
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"


def check(value, message):
    if not value:
        raise AssertionError(message)


options = webdriver.ChromeOptions()
profile = tempfile.TemporaryDirectory(prefix="va-minimarket-ux-")
options.add_argument("--headless=new")
options.add_argument("--ignore-certificate-errors")
options.add_argument("--allow-insecure-localhost")
options.add_argument("--window-size=1440,1200")
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
result = {}

try:
    driver.get(BASE + "/wp-login.php")
    driver.find_element(By.ID, "user_login").send_keys("va_demo_minimarket_vecinos")
    driver.find_element(By.ID, "user_pass").send_keys("VA-Vecinos-2026!")
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: "/panel-minimarket/" in d.current_url)
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-inventory] .va-minimarket__card")) == 20)

    body = driver.find_element(By.TAG_NAME, "body").text
    menu_labels = [link.text.strip() for link in driver.find_elements(By.CSS_SELECTOR, "header nav a") if link.is_displayed()]
    check("Panel listo." not in body, "Permanece Panel listo.")
    check("Minimarket Los Vecinos" in body and "Activo" in body and "20 ofertas activas" in body, "Resumen incorrecto.")
    check("Mis compras" not in menu_labels, "Mis compras visible para store.")
    check("Mi panel" in menu_labels and "Cerrar sesión" in menu_labels, "Accesos superiores incompletos.")
    tabs = driver.find_elements(By.CSS_SELECTOR, "[data-tab]")
    check([tab.text for tab in tabs] == ["Mis productos", "Agregar productos", "Mis pedidos"], "Tabs incorrectas.")
    check(tabs[0].get_attribute("aria-selected") == "true", "Tab productos no activo.")

    product_cards = driver.find_elements(By.CSS_SELECTOR, "[data-inventory] .va-minimarket__card")
    product_images = driver.find_elements(By.CSS_SELECTOR, "[data-inventory] .va-minimarket__image img")
    check(len(product_cards) == len(product_images) == 20, "Productos/imágenes distintos de 20.")
    check(all("Precio ($)" in card.text and "Stock" in card.text and "Estado" in card.text and "Guardar cambios" in card.text for card in product_cards), "Card de producto incompleta.")
    result["products"] = {"rows": 20, "images": 20, "render": "PASS"}

    tabs[1].click()
    wait.until(lambda d: not d.find_element(By.CSS_SELECTOR, '[data-panel="add"]').get_attribute("hidden"))
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-products] .va-minimarket__card")) > 0 or "No hay productos" in d.find_element(By.CSS_SELECTOR, "[data-products]").text)
    candidate_cards = driver.find_elements(By.CSS_SELECTOR, "[data-products] .va-minimarket__card")
    candidate_images = driver.find_elements(By.CSS_SELECTOR, "[data-products] .va-minimarket__image img")
    add_text = driver.find_element(By.CSS_SELECTOR, '[data-panel="add"]').text
    check("Buscar productos" in add_text and "Agregar productos a mi minimarket" in add_text, "Buscador sin contexto.")
    check(all("Precio ($)" in card.text and "Stock" in card.text and "Agregar producto" in card.text for card in candidate_cards), "Candidato incompleto.")
    result["add"] = {"candidates": len(candidate_cards), "images": len(candidate_images), "render": "PASS"}

    tabs[2].click()
    wait.until(lambda d: not d.find_element(By.CSS_SELECTOR, '[data-panel="orders"]').get_attribute("hidden"))
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-orders] .va-minimarket__order")) > 0 or "Aún no tienes pedidos" in d.find_element(By.CSS_SELECTOR, "[data-orders]").text)
    order_cards = driver.find_elements(By.CSS_SELECTOR, "[data-orders] .va-minimarket__order")
    order_text = driver.find_element(By.CSS_SELECTOR, '[data-panel="orders"]').text
    if order_cards:
        check(all(all(label in card.text for label in ["Fecha", "Total", "Estado", "Tipo de entrega", "Estado del despacho", "Ver pedido"]) for card in order_cards), "Pedido sin campos amigables.")
        check(" reserved " not in " " + order_text.lower() + " " and " delivery " not in " " + order_text.lower() + " ", "Pedido expone códigos internos.")
        order_cards[0].find_element(By.LINK_TEXT, "Ver pedido").click()
        wait.until(lambda d: "Productos" in d.find_element(By.CSS_SELECTOR, "[data-order-detail]").text)
    result["orders"] = {"rows": len(order_cards), "detail": "PASS" if order_cards else "NOT_APPLICABLE", "render": "PASS"}

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE" and "Bikrimart-Delivery.json" not in entry.get("message", "")]
    check(not severe, "Errores JS del panel: " + json.dumps(severe))
    result["js_errors"] = 0
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
