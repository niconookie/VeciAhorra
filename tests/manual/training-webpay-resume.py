import json
import time
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE = "https://remark-berry-arms-maker.trycloudflare.com/Minimarket"
CHECKOUT = "chk_EeizVyCn7YjGeWkWbUD2R_-A3HWg6YpaChDEagzPZho"
OUT = Path(__file__).with_name("training-webpay-resume-result.json")
result = {"steps": [], "checkout_id": CHECKOUT}


def record(name, **data):
    result["steps"].append({"name": name, **data})
    OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")


def controls():
    return [element for element in driver.find_elements(By.CSS_SELECTOR, "input,select,button") if element.is_displayed()]


def fill(keywords, value):
    for element in controls():
        identity = " ".join(filter(None, [element.get_attribute(name) for name in
            ["id", "name", "placeholder", "autocomplete", "aria-label"]])).lower()
        if any(word in identity for word in keywords):
            element.clear()
            element.send_keys(value)
            return identity
    raise RuntimeError("Input no encontrado: " + ",".join(keywords))


def click(words):
    for element in controls():
        text = (element.text or element.get_attribute("value") or "").strip().lower()
        if element.is_enabled() and any(word in text for word in words):
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", element)
            time.sleep(0.4)
            element.click()
            return text
    raise RuntimeError("Acción no encontrada: " + ",".join(words))


options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--window-size=1440,1200")
driver = webdriver.Chrome(service=Service(
    r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
), options=options)
wait = WebDriverWait(driver, 35)

try:
    driver.get(BASE + "/wp-login.php")
    driver.find_element(By.ID, "user_login").send_keys("va_demo_carolina")
    driver.find_element(By.ID, "user_pass").send_keys("VA-Cliente-2026!")
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: "wp-login.php" not in d.current_url)
    driver.get(BASE + "/checkout/?checkout_id=" + CHECKOUT)
    action = wait.until(lambda d: next((a for a in d.find_elements(By.CSS_SELECTOR, "[data-va-payment-status-action]")
                                       if a.is_displayed() and a.get_attribute("data-va-start-payment") == "true"), None))
    action.click()
    wait.until(lambda d: "webpay3gint.transbank.cl" in d.current_url)
    record("session_resumed", host="webpay3gint.transbank.cl", title=driver.title)

    wait.until(lambda d: any(c.get_attribute("id") == "tarjetas" for c in controls()))
    click(["tarjetas"])
    wait.until(lambda d: len([c for c in controls() if c.tag_name == "input"]) >= 3)
    record("card_form_visible", controls=[{
        "id": c.get_attribute("id"), "name": c.get_attribute("name"),
        "type": c.get_attribute("type"), "placeholder": c.get_attribute("placeholder")
    } for c in controls()])
    fill(["card", "tarjeta", "numero", "número", "pan"], "4051885600446623")
    fill(["exp", "fecha"], "1230")
    fill(["cvv", "cvc", "seguridad"], "123")
    click(["pagar", "continuar"])
    record("test_card_submitted", card="official integration ending 6623")

    time.sleep(2)
    if "transbank" in driver.current_url:
        try:
            fill(["rut", "usuario"], "11111111-1")
        except RuntimeError:
            pass
        try:
            fill(["clave", "password"], "123")
        except RuntimeError:
            passwords = [c for c in controls() if c.get_attribute("type") == "password"]
            if passwords:
                passwords[0].send_keys("123")
        click(["aceptar", "continuar", "autorizar", "ingresar"])
        record("issuer_authorization_submitted")

    wait.until(lambda d: "trycloudflare.com/Minimarket" in d.current_url)
    time.sleep(10)
    record("return_ui", url=driver.current_url, title=driver.title,
           body=driver.find_element(By.TAG_NAME, "body").text[:3500])
except Exception as error:
    record("failure", error=type(error).__name__ + ": " + str(error), url=driver.current_url,
           title=driver.title, controls=[{
               "tag": c.tag_name, "id": c.get_attribute("id"), "name": c.get_attribute("name"),
               "type": c.get_attribute("type"), "placeholder": c.get_attribute("placeholder"),
               "text": (c.text or c.get_attribute("value") or "")[:120]
           } for c in controls()])
    raise
finally:
    driver.quit()
