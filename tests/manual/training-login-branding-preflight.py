"""Browser validation for the standard WordPress login branded by VeciAhorra."""
import json
import subprocess
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
PHP = r"C:\xampp\php\php.exe"
WP_LOAD = "C:/xampp/htdocs/Minimarket/wp-load.php"
CREDS = {
    "customer": ("va_demo_carolina", "VA-Cliente-2026!", "/mis-compras/"),
    "store": ("va_demo_minimarket_vecinos", "VA-Vecinos-2026!", "/panel-minimarket/"),
    "courier": ("va_demo_diego", "VA-Diego-2026!", "/panel-repartidor/"),
    "provider": ("va_demo_jose", "VA-Jose-2026!", "/panel-prestador/"),
}


def check(condition, message):
    if not condition:
        raise AssertionError(message)


profile = tempfile.TemporaryDirectory(prefix="va-login-branding-chrome-")
options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--ignore-certificate-errors")
options.add_argument("--allow-insecure-localhost")
options.add_argument("--disable-gpu")
options.add_argument("--disable-dev-shm-usage")
options.add_argument("--no-sandbox")
options.add_argument("--window-size=1440,1000")
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
result = {"redirects": {}}


def open_login():
    driver.delete_all_cookies()
    driver.get(BASE + "/wp-login.php")
    wait.until(lambda d: d.find_element(By.ID, "loginform").is_displayed())


def login(role):
    username, password, destination = CREDS[role]
    open_login()
    driver.find_element(By.ID, "user_login").send_keys(username)
    driver.find_element(By.ID, "user_pass").send_keys(password)
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: destination in d.current_url)
    result["redirects"][role] = "PASS"


try:
    open_login()
    body = driver.find_element(By.TAG_NAME, "body").text
    logo = driver.find_element(By.CSS_SELECTOR, ".login h1 a")
    background = driver.execute_script("return getComputedStyle(arguments[0]).backgroundImage", logo)
    check("Logo_Veciahorra.png" in background and "https://" in background, "Logo oficial HTTPS ausente")
    check(logo.get_attribute("href").rstrip("/") == BASE, "Logo no enlaza home_url")
    check(logo.get_attribute("textContent").strip() == "Ir a VeciAhorra", "Texto accesible de logo incorrecto")
    for text in ("Bienvenido a VeciAhorra", "Ingresa para acceder a tu panel.", "Clientes, minimarkets, repartidores y prestadores acceden desde aquí.", "Volver a VeciAhorra"):
        check(text in body, "Texto de branding ausente: " + text)
    check(driver.find_element(By.ID, "user_login").is_displayed(), "Usuario ausente")
    check(driver.find_element(By.ID, "user_pass").is_displayed(), "Password ausente")
    check(driver.find_element(By.ID, "rememberme").is_displayed(), "Recuérdame ausente")
    check(driver.find_element(By.ID, "wp-submit").is_displayed(), "Submit ausente")
    forgot = driver.find_element(By.CSS_SELECTOR, "#nav a")
    check("action=lostpassword" in forgot.get_attribute("href"), "Recuperación de contraseña ausente")
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Desktop desborda")
    screenshot = str(Path(tempfile.gettempdir()) / "veciahorra-login-branding.png")
    driver.save_screenshot(screenshot)

    driver.set_window_size(390, 844)
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Mobile desborda")
    check(driver.find_element(By.ID, "loginform").is_displayed(), "Formulario mobile no visible")
    result["responsive"] = "PASS"

    open_login()
    driver.find_element(By.ID, "user_login").send_keys("usuario_invalido_entrenamiento")
    driver.find_element(By.ID, "user_pass").send_keys("credencial-invalida")
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: d.find_element(By.ID, "login_error").is_displayed())
    check("Bienvenido a VeciAhorra" in driver.find_element(By.TAG_NAME, "body").text, "Branding ausente tras error")
    result["safe_error"] = "PASS"

    driver.set_window_size(1440, 1000)
    for role in CREDS:
        login(role)

    admin_php = (
        f"define('WP_USE_THEMES',false);require '{WP_LOAD}';"
        "$u=get_user_by('login','NicolasAvila');"
        "$url=apply_filters('login_redirect','', '', $u);"
        "echo str_contains($url,'wp-admin/admin.php?page=veciahorra')?'PASS':'FAIL';"
    )
    result["redirects"]["admin"] = subprocess.check_output([PHP, "-r", admin_php], text=True).strip()
    check(result["redirects"]["admin"] == "PASS", "Redirect admin incorrecto")

    browser_logs = driver.get_log("browser")
    severe = [entry for entry in browser_logs if entry.get("level") == "SEVERE"]
    mixed = [entry for entry in severe if "Mixed Content" in entry.get("message", "")]
    unexpected = [entry for entry in severe if "ERR_NETWORK_ACCESS_DENIED" not in entry.get("message", "") and entry not in mixed]
    check(not mixed, "Login contiene mixed content")
    check(not unexpected, "Login tiene errores de consola")
    performance = driver.get_log("performance")
    failures = []
    for entry in performance:
        message = json.loads(entry["message"])["message"]
        if message["method"] == "Network.responseReceived" and message["params"]["response"]["status"] >= 500:
            failures.append(message["params"]["response"]["url"])
    check(not failures, "Login produjo HTTP 5xx")
    result.update({"render": "PASS", "js_errors": 0, "mixed_content_errors": 0, "http_5xx": 0, "screenshot": screenshot})
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
