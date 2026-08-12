"""Read-only browser validation for courier/provider training UX and role navigation."""
import json
import tempfile

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
CREDS = {
    "customer": ("va_demo_carolina", "VA-Cliente-2026!", "/mis-compras/"),
    "store": ("va_demo_minimarket_vecinos", "VA-Vecinos-2026!", "/panel-minimarket/"),
    "courier": ("va_demo_diego", "VA-Diego-2026!", "/panel-repartidor/"),
    "provider": ("va_demo_jose", "VA-Jose-2026!", "/panel-prestador/"),
}


def check(condition, message):
    if not condition:
        raise AssertionError(message)


options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--ignore-certificate-errors")
options.add_argument("--allow-insecure-localhost")
options.add_argument("--window-size=1440,1200")
options.add_argument("--disable-gpu")
options.add_argument("--disable-dev-shm-usage")
options.add_argument("--no-sandbox")
profile = tempfile.TemporaryDirectory(prefix="va-secondary-role-chrome-")
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
results = {"navigation": {}, "js_errors": {}, "known_global": []}


def login(role):
    username, password, path = CREDS[role]
    driver.delete_all_cookies()
    driver.get(BASE + "/wp-login.php")
    driver.find_element(By.ID, "user_login").send_keys(username)
    driver.find_element(By.ID, "user_pass").send_keys(password)
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: path in d.current_url)
    return path


def nav(role, expected_path):
    links = driver.find_elements(By.CSS_SELECTOR, "nav a, .menu a")
    labels = [link.text.strip() for link in links if link.text.strip()]
    panels = [link for link in links if link.text.strip().casefold() == "mi panel"]
    logouts = [link for link in links if link.text.strip().casefold() == "cerrar sesión"]
    purchases = any(label.casefold() == "mis compras" for label in labels)
    check(len(panels) >= 1 and expected_path in panels[0].get_attribute("href"), role + " sin destino Mi panel correcto")
    check(len(logouts) >= 1 and panels[0] != logouts[0], role + " sin logout separado")
    results["navigation"][role] = {"mis_compras": purchases, "mi_panel": "PASS", "logout": "PASS"}


def console(role):
    severe = [item for item in driver.get_log("browser") if item.get("level") == "SEVERE"]
    known = [item for item in severe if "Bikrimart-Delivery.json" in item.get("message", "")]
    unexpected = [item for item in severe if item not in known]
    results["known_global"].extend(known)
    results["js_errors"][role] = len(unexpected)
    check(not unexpected, role + " tiene errores JS inesperados")


try:
    for role in ("customer", "store"):
        path = login(role)
        wait.until(lambda d: "Cerrar sesión" in d.find_element(By.TAG_NAME, "body").text)
        nav(role, path)
        console(role)

    path = login("courier")
    wait.until(lambda d: "Diego Morales" in d.find_element(By.TAG_NAME, "body").text)
    body = driver.find_element(By.TAG_NAME, "body").text
    check(body.count("Panel de repartidor") == 1, "Título courier no es único")
    metrics = driver.find_elements(By.CSS_SELECTOR, "[data-va-courier-summary] article")
    metric_values = [item.find_element(By.TAG_NAME, "strong").text for item in metrics]
    check(len(metrics) == 3 and metric_values == ["1", "2", "1"], "Resumen courier incorrecto")
    available_cards = driver.find_elements(By.CSS_SELECTOR, "[data-va-courier-available] article")
    owned_cards = driver.find_elements(By.CSS_SELECTOR, "[data-va-courier-owned] article")
    check(len(available_cards) == 1 and len(owned_cards) == 2, "Cards courier demo incompletas")
    check("Aceptar entrega" in available_cards[0].text, "CD-01 sin acción Aceptar entrega")
    check(any("Asignada" in card.text and "Marcar como retirada" in card.text for card in owned_cards), "CD-02 inválida")
    check(any("En curso" in card.text and "Marcar como entregada" in card.text for card in owned_cards), "CD-03 inválida")
    nav("courier", path)
    check(not results["navigation"]["courier"]["mis_compras"], "Courier ve Mis compras")
    console("courier")
    results["courier"] = {"render": "PASS", "available": 1, "assigned": 1, "in_progress": 1}

    path = login("provider")
    wait.until(lambda d: "Gasfitería Martínez" in d.find_element(By.TAG_NAME, "body").text)
    body = driver.find_element(By.TAG_NAME, "body").text
    for value in ("José Martínez", "Publicado", "Plan Destacado", "San Miguel", "Gasfitería Martínez"):
        check(value in body, "Provider no muestra " + value)
    check("Estado: published" not in body and "Plan: featured" not in body, "Provider expone enum interno")
    check(not driver.find_element(By.CSS_SELECTOR, "[data-va-provider-current-profile]").get_attribute("hidden"), "Ficha provider oculta")
    nav("provider", path)
    check(not results["navigation"]["provider"]["mis_compras"], "Provider ve Mis compras")
    console("provider")
    results["provider"] = {"render": "PASS", "status": "Publicado", "plan": "Plan Destacado", "binding": "PASS"}

    check(results["navigation"]["customer"]["mis_compras"], "Customer no ve Mis compras")
    check(not results["navigation"]["store"]["mis_compras"], "Store ve Mis compras")
    performance = driver.get_log("performance")
    failures = []
    for entry in performance:
        message = json.loads(entry["message"])["message"]
        if message["method"] == "Network.responseReceived" and message["params"]["response"]["status"] >= 500:
            failures.append(message["params"]["response"]["url"])
    results["http_5xx"] = len(failures)
    check(not failures, "Se detectaron HTTP 5xx")
    results["role_navigation"] = "PASS"
    print(json.dumps(results, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
