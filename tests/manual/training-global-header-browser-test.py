"""Browser checks for the shared VeciAhorra theme header."""

import json
import re
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
CREDS = {
    "customer": ("va_demo_carolina", "VA-Cliente-2026!", "/mis-compras/"),
    "store": ("va_demo_minimarket_vecinos", "VA-Vecinos-2026!", "/panel-minimarket/"),
    "courier": ("va_demo_diego", "VA-Diego-2026!", "/panel-repartidor/"),
    "provider": ("va_demo_jose", "VA-Jose-2026!", "/panel-prestador/"),
}
zonal_handoff = Path(r"C:\xampp\tmp\veciahorra-zonal-training-credentials.txt")
zonal_text = zonal_handoff.read_text(encoding="utf-8")
zonal_user = re.search(r"^USERNAME=(.+)$", zonal_text, re.M)
zonal_password = re.search(r"^PASSWORD=(.+)$", zonal_text, re.M)
check_zonal_credentials = zonal_user is not None and zonal_password is not None
if check_zonal_credentials:
    CREDS["zonal"] = (zonal_user.group(1).strip(), zonal_password.group(1).strip(), "/wp-admin/admin.php?page=veciahorra-zonal-stores")
zonal_text = ""
PAGES = ["/", "/catalogo-veciahorra/", "/servicios/"]
SHOTS = Path(__file__).resolve().parents[2] / "artifacts" / "header-account-p0"
SHOTS.mkdir(parents=True, exist_ok=True)


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
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
result = {"contexts": {}, "js_errors": [], "mixed_content": [], "http_5xx": [], "preexisting_browser_errors": []}


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
    check(check_zonal_credentials, "Credenciales temporales zonales ausentes")
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
        driver.set_window_size(1440, 1000)
        driver.delete_all_cookies()
        login(*credentials)
        expected = None
        role_paths = ["/", "/servicios/"] if role == "zonal" else ["/", "/servicios/", credentials[2]]
        for path in role_paths:
            _, links = open_page(path)
            current_labels = labels(links)
            check("iniciar sesión" not in current_labels and "registrarse" not in current_labels, "Acceso público visible para " + role)
            joined_labels = " ".join(current_labels)
            check("veciservicios" in current_labels and "mi cuenta" in joined_labels, "Navegación de rol incompleta para " + role + ": " + repr(links))
            comparable = tuple(current_labels)
            expected = expected or comparable
            check(comparable == expected, "Cabecera inconsistente para " + role + " en " + path)
        if role != "customer":
            driver.set_window_size(504, 900)
            header, _ = open_page("/")
            toggle = header.find_element(By.CSS_SELECTOR, ".va-global-header__account-toggle")
            toggle.click()
            role_menu = header.find_element(By.ID, "va-global-account-menu")
            check([link.text for link in role_menu.find_elements(By.TAG_NAME, "a")] == ["Mi panel", "Cerrar sesión"], "Menú ajeno visible para " + role)
            driver.set_window_size(1440, 1000)
        if role == "customer":
            for width in (1920, 1440):
                driver.set_window_size(width, 1000)
                header, _ = open_page("/")
                check(header.find_element(By.CSS_SELECTOR, ".va-global-header__logout").is_displayed(), "Logout desktop no visible a " + str(width))
                check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Overflow a " + str(width))
            driver.save_screenshot(str(SHOTS / "desktop-authenticated.png"))
            for width in (1024, 846, 768, 504):
                driver.set_window_size(width, 900)
                header, _ = open_page("/")
                toggle = header.find_element(By.CSS_SELECTOR, ".va-global-header__account-toggle")
                check(toggle.is_displayed() and toggle.size["width"] >= 44 and toggle.size["height"] >= 44, "Target de cuenta insuficiente a " + str(width))
                check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Overflow a " + str(width))
            toggle.click()
            menu = header.find_element(By.ID, "va-global-account-menu")
            check(toggle.get_attribute("aria-expanded") == "true" and menu.is_displayed(), "Popover no abrió")
            check([link.text for link in menu.find_elements(By.TAG_NAME, "a")] == ["Mi cuenta", "Mis compras", "Cerrar sesión"], "Menú cliente incorrecto")
            driver.save_screenshot(str(SHOTS / "mobile-account-open.png"))
            driver.find_element(By.TAG_NAME, "body").send_keys(Keys.ESCAPE)
            check(toggle.get_attribute("aria-expanded") == "false" and driver.switch_to.active_element == toggle, "Escape/foco incorrecto")
            toggle.send_keys(Keys.SPACE)
            check(toggle.get_attribute("aria-expanded") == "true", "Apertura por teclado incorrecta")
            driver.find_element(By.TAG_NAME, "h1").click()
            check(toggle.get_attribute("aria-expanded") == "false" and not menu.is_displayed(), "Click exterior no cerró")
            toggle.send_keys(Keys.ENTER)
            menu.find_elements(By.TAG_NAME, "a")[-1].click()
            wait.until(lambda current: current.current_url.startswith(BASE + "/") and not current.find_elements(By.CSS_SELECTOR, ".va-global-header__account-toggle"))
            driver.save_screenshot(str(SHOTS / "after-logout.png"))
            check(not driver.find_elements(By.CSS_SELECTOR, ".va-global-header__account-toggle"), "Sesión persistió tras logout")
        result["contexts"][role] = "PASS"

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE"]
    known_cors = [entry for entry in severe if "Bikrimart-Delivery.json" in entry.get("message", "")]
    result["preexisting_browser_errors"] = known_cors
    result["js_errors"] = [
        entry for entry in severe
        if "javascript" in entry.get("source", "").lower() and entry not in known_cors
    ]
    result["mixed_content"] = [entry for entry in severe if "mixed content" in entry.get("message", "").lower()]
    for entry in driver.get_log("performance"):
        message = json.loads(entry["message"])["message"]
        if message.get("method") != "Network.responseReceived":
            continue
        response = message.get("params", {}).get("response", {})
        if int(response.get("status", 0)) >= 500:
            result["http_5xx"].append({"status": int(response["status"]), "url": response.get("url", "")})
    check(not result["js_errors"], "Errores JavaScript: " + json.dumps(result["js_errors"], ensure_ascii=False))
    check(not result["mixed_content"], "Mixed content: " + json.dumps(result["mixed_content"], ensure_ascii=False))
    check(not result["http_5xx"], "HTTP 5xx: " + json.dumps(result["http_5xx"], ensure_ascii=False))
    result["desktop"] = "PASS"
    result["mobile"] = "PASS"
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
