"""Browser checks for the shared VeciAhorra theme header."""

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
PAGES = ["/", "/catalogo-veciahorra/", "/servicios/"]


def check(condition, message):
    if not condition:
        raise AssertionError(message)


profile = tempfile.TemporaryDirectory(prefix="va-global-header-")
options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--ignore-certificate-errors")
options.add_argument("--allow-insecure-localhost")
options.add_argument("--disable-gpu")
options.add_argument("--no-sandbox")
options.add_argument("--window-size=1440,1000")
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
result = {"contexts": {}, "js_errors": [], "preexisting_browser_errors": []}


def open_page(path):
    driver.get(BASE + path)
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    header = driver.find_element(By.CSS_SELECTOR, "[data-va-global-header]")
    links = [link.text.strip() for link in header.find_elements(By.CSS_SELECTOR, ".va-global-header__main a, .va-global-header__nav a") if link.text.strip()]
    html = driver.page_source
    check("Warning:" not in html and "Notice:" not in html, "Warning/notice PHP visible en " + path)
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Overflow horizontal en " + path)
    return header, links


def labels(links):
    return [link.casefold() for link in links]


def login(username, password, destination):
    driver.get(BASE + "/wp-login.php")
    wait.until(lambda current: current.find_element(By.ID, "loginform").is_displayed())
    driver.find_element(By.ID, "user_login").send_keys(username)
    driver.find_element(By.ID, "user_pass").send_keys(password)
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda current: destination in current.current_url)


try:
    driver.delete_all_cookies()
    guest_navigation = None
    for path in PAGES:
        header, links = open_page(path)
        check("iniciar sesi\u00f3n" in " ".join(labels(links)) and "registrarse" in labels(links), "Accesos de visitante ausentes en " + path)
        comparable = tuple(labels(links))
        guest_navigation = guest_navigation or comparable
        check(comparable == guest_navigation, "Cabecera visitante inconsistente en " + path)
        check(len(driver.find_elements(By.CSS_SELECTOR, "[data-va-global-header]")) == 1, "Cabecera global duplicada")
        logo = header.find_element(By.CSS_SELECTOR, '.va-global-header__logo')
        natural = driver.execute_script("return [arguments[0].naturalWidth, arguments[0].naturalHeight]", logo)
        rendered = driver.execute_script("return [arguments[0].clientWidth, arguments[0].clientHeight]", logo)
        check(all(natural) and all(rendered), "Logo sin dimensiones")
        check(abs((natural[0] / natural[1]) - (rendered[0] / rendered[1])) < 0.08, "Logo deformado")

    driver.set_window_size(390, 844)
    open_page("/")
    check(driver.find_element(By.CSS_SELECTOR, ".va-global-header__menu-toggle").is_displayed(), "Trigger móvil ausente")
    result["contexts"]["guest"] = "PASS"

    driver.set_window_size(1440, 1000)
    for role, credentials in CREDS.items():
        driver.delete_all_cookies()
        login(*credentials)
        expected = None
        for path in ["/", "/servicios/", credentials[2]]:
            _, links = open_page(path)
            current_labels = labels(links)
            check("iniciar sesión" not in current_labels and "registrarse" not in current_labels, "Acceso público visible para " + role)
            joined_labels = " ".join(current_labels)
            check("veciservicios" in current_labels and "mi cuenta" in joined_labels, "Navegación de rol incompleta para " + role + ": " + repr(links))
            comparable = tuple(current_labels)
            expected = expected or comparable
            check(comparable == expected, "Cabecera inconsistente para " + role + " en " + path)
        result["contexts"][role] = "PASS"

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE"]
    known_cors = [entry for entry in severe if "Bikrimart-Delivery.json" in entry.get("message", "")]
    result["preexisting_browser_errors"] = known_cors
    result["js_errors"] = [
        entry for entry in severe
        if "javascript" in entry.get("source", "").lower() and entry not in known_cors
    ]
    check(not result["js_errors"], "Errores JavaScript: " + json.dumps(result["js_errors"], ensure_ascii=False))
    result["desktop"] = "PASS"
    result["mobile"] = "PASS"
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
