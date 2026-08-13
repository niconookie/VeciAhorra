"""Read-only browser preflight for VeciAhorra training surfaces."""
import json
import subprocess
import tempfile
import time

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://localhost/Minimarket"
PHP = r"C:\xampp\php\php.exe"
WP_LOAD = "C:/xampp/htdocs/Minimarket/wp-load.php"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
TEST_EMAIL = f"va_final_cleanup_{int(time.time())}@example.test"


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
profile = tempfile.TemporaryDirectory(prefix="va-final-role-chrome-")
options.add_argument("--user-data-dir=" + profile.name)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 25)
result = {}


def login(username, password, expected_path):
    driver.delete_all_cookies()
    driver.get(BASE + "/wp-login.php")
    driver.find_element(By.ID, "user_login").send_keys(username)
    driver.find_element(By.ID, "user_pass").send_keys(password)
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: expected_path in d.current_url)
    return driver.current_url


def cleanup_test_user():
    php = (
        f"define('WP_USE_THEMES',false);require '{WP_LOAD}';"
        f"$u=get_user_by('email','{TEST_EMAIL}');"
        "if($u instanceof WP_User){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($u->ID);}"
        f"echo get_user_by('email','{TEST_EMAIL}')?'FAIL':'PASS';"
    )
    return subprocess.check_output([PHP, "-r", php], text=True).strip()


def authenticated_cookies(username):
    php = (
        f"define('WP_USE_THEMES',false);require '{WP_LOAD}';"
        f"$u=get_user_by('login','{username}');"
        "$e=time()+3600;echo wp_json_encode(["
        "['name'=>SECURE_AUTH_COOKIE,'value'=>wp_generate_auth_cookie($u->ID,$e,'secure_auth'),'path'=>ADMIN_COOKIE_PATH],"
        "['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($u->ID,$e,'logged_in'),'path'=>COOKIEPATH]]);"
    )
    return json.loads(subprocess.check_output([PHP, "-r", php], text=True))


try:
    driver.get(BASE + "/")
    home = driver.page_source
    check(all(label in home for label in ["Registrarse", "Iniciar sesión", "Catálogo"]), "Home no expone accesos.")
    result["visitor"] = "PASS"

    driver.get(BASE + "/registro-cliente/")
    driver.find_element(By.NAME, "first_name").send_keys("Prueba")
    driver.find_element(By.NAME, "last_name").send_keys("Final")
    driver.find_element(By.NAME, "email").send_keys(TEST_EMAIL)
    driver.find_element(By.NAME, "password").send_keys("ClaveFinal-2026!")
    driver.find_element(By.NAME, "password_confirmation").send_keys("ClaveFinal-2026!")
    registration_form = driver.find_element(By.NAME, "veciahorra_customer_registration").find_element(By.XPATH, "./ancestor::form")
    registration_form.find_element(By.CSS_SELECTOR, "button[type=submit]").click()
    wait.until(lambda d: "/mis-compras/" in d.current_url)
    wait.until(lambda d: "Aún no tienes compras" in d.find_element(By.TAG_NAME, "body").text)
    customer_body = driver.find_element(By.TAG_NAME, "body").text
    check("Ir al catálogo" in customer_body and "Cerrar sesión" in customer_body, "Primer panel incompleto.")
    result["new_customer"] = "PASS"

    login("va_demo_carolina", "VA-Cliente-2026!", "/mis-compras/")
    wait.until(lambda d: "Mis compras" in d.find_element(By.TAG_NAME, "body").text)
    check("Cerrar sesión" in driver.find_element(By.TAG_NAME, "body").text, "Customer sin logout.")
    result["existing_customer"] = "PASS"

    login("va_demo_minimarket_vecinos", "VA-Vecinos-2026!", "/panel-minimarket/")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-inventory] .va-minimarket__card")) == 20)
    cards = driver.find_elements(By.CSS_SELECTOR, "[data-inventory] .va-minimarket__card")
    images = driver.find_elements(By.CSS_SELECTOR, "[data-inventory] .va-minimarket__image img")
    check(len(images) == 20, "Panel no renderizó 20 imágenes.")
    for image in images:
        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", image)
        wait.until(lambda d, current=image: d.execute_script("return arguments[0].complete && arguments[0].naturalWidth > 0", current))
    official = {
        "Coca-Cola Original 1,5 L": ("2190", "12"),
        "Tallarines Carozzi 400 g": ("1050", "17"),
        "Salsa de tomates Carozzi": ("750", "18"),
        "Super 8": ("500", "11"),
    }
    for name, values in official.items():
        card = next((item for item in cards if name in item.text), None)
        check(card is not None, f"Oferta ausente: {name}")
        check(card.find_element(By.NAME, "price").get_attribute("value") in (values[0], values[0] + ".00"), f"Precio incorrecto: {name}")
        check(card.find_element(By.NAME, "stock").get_attribute("value") == values[1], f"Stock incorrecto: {name}")
        check(card.find_element(By.NAME, "status").get_attribute("value") == "active", f"Estado incorrecto: {name}")
    check("Cerrar sesión" in driver.find_element(By.TAG_NAME, "body").text, "Store sin logout.")
    result["minimarket"] = {"status": "PASS", "rows": 20, "images": 20, "official": 4}

    login("va_demo_diego", "VA-Diego-2026!", "/panel-repartidor/")
    wait.until(lambda d: "Diego Morales" in d.find_element(By.TAG_NAME, "body").text)
    courier_body = driver.find_element(By.TAG_NAME, "body").text
    check("Entregas disponibles" in courier_body and "Mis entregas" in courier_body and "Cerrar sesión" in courier_body, "Courier incompleto.")
    result["courier"] = "PASS"

    login("va_demo_jose", "VA-Jose-2026!", "/panel-prestador/")
    wait.until(lambda d: "Mi perfil de servicios" in d.find_element(By.TAG_NAME, "body").text)
    provider_body = driver.find_element(By.TAG_NAME, "body").text
    check("Publicado" in provider_body and "Cerrar sesión" in provider_body, "Provider incompleto.")
    result["provider"] = "PASS"

    driver.delete_all_cookies()
    driver.get(BASE + "/")
    admin_cookies = authenticated_cookies("NicolasAvila")
    for admin_cookie in admin_cookies:
        driver.add_cookie({"name": admin_cookie["name"], "value": admin_cookie["value"], "path": admin_cookie["path"], "secure": True})
    driver.get(BASE + "/wp-admin/admin.php?page=veciahorra")
    check("/wp-admin/admin.php?page=veciahorra" in driver.current_url, "Sesión admin no llegó a VeciAhorra.")
    admin_pages = {
        "dashboard": "veciahorra",
        "stores": "veciahorra-stores",
        "products": "veciahorra-products",
        "inventory": "veciahorra-inventory",
        "orders": "veciahorra-orders",
    }
    checked = []
    for name, page in admin_pages.items():
        driver.get(BASE + "/wp-admin/admin.php?page=" + page)
        check("wp-admin" in driver.current_url and "error crítico" not in driver.page_source.lower(), f"Admin {name} falló.")
        checked.append(name)
    result["admin"] = {"status": "PASS", "pages": checked}

    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE"]
    result["browser_severe"] = severe
    if severe:
        print("BROWSER_SEVERE=" + json.dumps(severe, ensure_ascii=False))
    environment_only = ("fonts.googleapis.com", "stats.wp.com", "secure.gravatar.com")
    unexpected_severe = [entry for entry in severe if
                         "Bikrimart-Delivery.json" not in entry.get("message", "") and
                         not ("ERR_NETWORK_ACCESS_DENIED" in entry.get("message", "") and
                              any(host in entry.get("message", "") for host in environment_only))]
    check(not unexpected_severe, "Se detectaron errores SEVERE inesperados en consola.")
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
    cleanup = cleanup_test_user()
    print("TEST_USER_REMOVED=" + cleanup)
    if cleanup != "PASS":
        raise RuntimeError("Cleanup de usuario final falló.")
