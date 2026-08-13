import json
import time
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

BASE = "https://remark-berry-arms-maker.trycloudflare.com/Minimarket"
OUT = Path(__file__).with_name("training-webpay-e2e-result.json")
result = {"steps": [], "public_base": BASE}
RESUME_EXISTING_CART = False


def record(name, **data):
    result["steps"].append({"name": name, **data})
    OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")


def click_text(words):
    words = [word.lower() for word in words]
    for element in driver.find_elements(By.CSS_SELECTOR, "button,input[type=submit],a"):
        text = (element.text or element.get_attribute("value") or "").strip().lower()
        if element.is_displayed() and element.is_enabled() and any(word in text for word in words):
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", element)
            time.sleep(0.3)
            element.click()
            return text
    raise RuntimeError("No se encontró acción: " + ", ".join(words))


def visible_inputs():
    return [element for element in driver.find_elements(By.CSS_SELECTOR, "input,select") if element.is_displayed()]


def fill_matching(keywords, value, input_type=None):
    keywords = [word.lower() for word in keywords]
    for element in visible_inputs():
        identity = " ".join(filter(None, [
            element.get_attribute("id"), element.get_attribute("name"),
            element.get_attribute("placeholder"), element.get_attribute("autocomplete"),
            element.get_attribute("aria-label"),
        ])).lower()
        if input_type and element.get_attribute("type") != input_type:
            continue
        if any(word in identity for word in keywords):
            element.clear()
            element.send_keys(value)
            return identity
    raise RuntimeError("No se encontró input: " + ", ".join(keywords))


options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--ignore-certificate-errors")
options.add_argument("--window-size=1440,1200")
options.set_capability("acceptInsecureCerts", True)
driver = webdriver.Chrome(
    service=Service(r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"),
    options=options,
)
wait = WebDriverWait(driver, 30)

try:
    driver.get(BASE + "/wp-login.php")
    driver.find_element(By.ID, "user_login").send_keys("va_demo_carolina")
    driver.find_element(By.ID, "user_pass").send_keys("VA-Cliente-2026!")
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: "wp-login.php" not in d.current_url)
    record("client_login", url=driver.current_url)

    products = [
        ("Coca-Cola Original 1,5 L", "3310", 3),
        ("Tallarines Carozzi 400 g", "3331", 1),
        ("Salsa de tomates Carozzi", "3334", 1),
        ("Super 8", "3340", 1),
    ]
    for name, inventory_id, quantity in products:
        if RESUME_EXISTING_CART:
            record("cart_resume", expected_products=4)
            break
        driver.get(BASE + "/catalogo-veciahorra/")
        search = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "[data-va-catalog-search]")))
        wait.until(lambda d: not d.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())
        search.send_keys(name)
        search.find_element(By.XPATH, "./ancestor::form").find_element(By.CSS_SELECTOR, 'button[type="submit"]').click()
        wait.until(lambda d: any(c.find_element(By.CSS_SELECTOR, ".va-catalog-card__title").text == name for c in d.find_elements(By.CSS_SELECTOR, ".va-catalog-card")))
        card = next(c for c in driver.find_elements(By.CSS_SELECTOR, ".va-catalog-card") if c.find_element(By.CSS_SELECTOR, ".va-catalog-card__title").text == name)
        driver.get(card.find_element(By.CSS_SELECTOR, "a.va-catalog-card__action").get_attribute("href"))
        wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-offer-list] [role=radio]")) > 0)
        driver.find_element(By.CSS_SELECTOR, f'[data-inventory-id="{inventory_id}"]').click()
        add = driver.find_element(By.CSS_SELECTOR, "[data-va-add-to-cart]")
        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", add)
        time.sleep(0.5)
        add.click()
        wait.until(lambda d: d.find_element(By.CSS_SELECTOR, "[data-va-cart-success]").is_displayed())
        for _ in range(quantity - 1):
            time.sleep(0.5)
            add.click()
            wait.until(lambda d: not add.get_attribute("aria-busy") == "true")
        record("cart_add", product=name, inventory_id=int(inventory_id), quantity=quantity)

    driver.get(BASE + "/checkout/")
    form = wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "[data-va-checkout-form]")))
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, '[name="delivery_method"][value="delivery"]')) == 1)
    delivery = driver.find_element(By.CSS_SELECTOR, '[name="delivery_method"][value="delivery"]')
    driver.execute_script("arguments[0].scrollIntoView({block:'center'});", delivery)
    time.sleep(0.5)
    delivery.click()
    fields = {
        "first_name": "Carolina", "last_name": "Soto", "phone": "+56955550101",
        "email": "carolina.soto.demo@veciahorra.test", "address": "Av. Siempre Viva 742",
        "commune": "La Cisterna", "reference": "Casa azul, portón negro",
        "notes": "Ensayo integral de capacitación",
    }
    for name, value in fields.items():
        element = form.find_element(By.NAME, name)
        element.clear()
        element.send_keys(value)
    submit = form.find_element(By.CSS_SELECTOR, "[data-va-checkout-submit]")
    driver.execute_script("arguments[0].scrollIntoView({block:'center'});", submit)
    time.sleep(0.5)
    submit.click()
    wait.until(lambda d: "webpay3gint.transbank.cl" in d.current_url)
    record("webpay_form", host="webpay3gint.transbank.cl", title=driver.title)

    wait.until(lambda d: any(e.get_attribute("id") == "tarjetas" for e in visible_inputs()
                             + d.find_elements(By.CSS_SELECTOR, "button")))
    click_text(["tarjetas"])
    wait.until(lambda d: any(e.get_attribute("id") == "card-number" for e in visible_inputs()))
    fill_matching(["card", "tarjeta", "numero", "número", "pan"], "4051885600446623")
    click_text(["continuar"])
    wait.until(lambda d: len(visible_inputs()) >= 2)
    fill_matching(["exp", "fecha", "expiration"], "1230")
    fill_matching(["cvv", "cvc", "seguridad"], "123")
    click_text(["pagar", "continuar"])
    record("webpay_card_submitted", test_card="official integration card ending 6623")

    WebDriverWait(driver, 45).until(lambda d:
        "trycloudflare.com/Minimarket" in d.current_url
        or "bp_control.cgi" not in d.current_url
        or len(visible_inputs()) > 0
    )
    if "transbank" in driver.current_url:
        record("issuer_page", url=driver.current_url, controls=[{
            "tag": e.tag_name, "type": e.get_attribute("type"), "id": e.get_attribute("id"),
            "name": e.get_attribute("name"), "text": (e.text or e.get_attribute("value") or "")[:100]
        } for e in driver.find_elements(By.CSS_SELECTOR, "input,select,button") if e.is_displayed()])
        try:
            fill_matching(["rut", "usuario"], "11111111-1")
        except RuntimeError:
            pass
        try:
            fill_matching(["clave", "password"], "123")
        except RuntimeError:
            password_fields = [e for e in visible_inputs() if e.get_attribute("type") == "password"]
            if password_fields:
                password_fields[0].send_keys("123")
        click_text(["aceptar", "continuar", "autorizar", "ingresar"])
        record("issuer_authorization_submitted")

    wait.until(lambda d: "trycloudflare.com/Minimarket" in d.current_url)
    time.sleep(8)
    record("return_ui", url=driver.current_url, title=driver.title, body=driver.find_element(By.TAG_NAME, "body").text[:3000])
except Exception as error:
    record("failure", error=type(error).__name__ + ": " + str(error), url=driver.current_url,
           title=driver.title, visible_controls=[{
               "tag": e.tag_name, "type": e.get_attribute("type"), "id": e.get_attribute("id"),
               "name": e.get_attribute("name"), "placeholder": e.get_attribute("placeholder"),
               "text": (e.text or e.get_attribute("value") or "")[:120],
           } for e in driver.find_elements(By.CSS_SELECTOR, "input,select,button") if e.is_displayed()])
    raise
finally:
    driver.quit()
