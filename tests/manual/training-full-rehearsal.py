import json
import time
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

BASE = "https://localhost/Minimarket"
OUT = Path(__file__).with_name("training-full-rehearsal-result.json")
result = {"steps": [], "offers": [], "browser": "Chrome headless"}
RESUME_EXISTING_CART = True


def step(name, **data):
    result["steps"].append({"name": name, **data})
    OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")


options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--ignore-certificate-errors")
options.add_argument("--window-size=1440,1200")
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"performance": "ALL"})
driver = webdriver.Chrome(
    service=Service(r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"),
    options=options,
)
wait = WebDriverWait(driver, 25)

try:
    driver.get(BASE + "/wp-login.php")
    driver.find_element(By.ID, "user_login").send_keys("va_demo_carolina")
    driver.find_element(By.ID, "user_pass").send_keys("VA-Cliente-2026!")
    driver.find_element(By.ID, "wp-submit").click()
    wait.until(lambda d: "wp-login.php" not in d.current_url)
    step("client_login", url=driver.current_url, title=driver.title)

    products = [
        ("Coca-Cola Original 1,5 L", "3310"),
        ("Tallarines Carozzi 400 g", "3331"),
        ("Salsa de tomates Carozzi", "3334"),
        ("Super 8", "3340"),
    ]
    for index, (name, inventory_id) in enumerate(products):
        driver.get(BASE + "/catalogo-veciahorra/")
        search = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "[data-va-catalog-search]")))
        wait.until(lambda d: not d.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())
        search.clear()
        search.send_keys(name)
        search.find_element(By.XPATH, "./ancestor::form").find_element(By.CSS_SELECTOR, 'button[type="submit"]').click()
        wait.until(lambda d: any(
            c.find_element(By.CSS_SELECTOR, ".va-catalog-card__title").text == name
            for c in d.find_elements(By.CSS_SELECTOR, ".va-catalog-card")
        ))
        card = next(c for c in driver.find_elements(By.CSS_SELECTOR, ".va-catalog-card")
                    if c.find_element(By.CSS_SELECTOR, ".va-catalog-card__title").text == name)
        card_text = card.text
        link = card.find_element(By.CSS_SELECTOR, "a.va-catalog-card__action").get_attribute("href")
        step("catalog_search", product=name, card=card_text, url=link)
        driver.get(link)
        root = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "[data-va-product-detail]")))
        wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-offer-list] [role=radio]")) > 0)
        offers = [b.text for b in driver.find_elements(By.CSS_SELECTOR, "[data-va-offer-list] [role=radio]")]
        if index == 0:
            result["offers"] = offers
            OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
        if not RESUME_EXISTING_CART:
            selected = driver.find_element(By.CSS_SELECTOR, f'[data-inventory-id="{inventory_id}"]')
            selected.click()
            add = driver.find_element(By.CSS_SELECTOR, "[data-va-add-to-cart]")
            driver.execute_script("arguments[0].scrollIntoView({block:'center'});", add)
            time.sleep(0.4)
            add.click()
            wait.until(lambda d: d.find_element(By.CSS_SELECTOR, "[data-va-cart-success]").is_displayed())
            step("add_to_cart", product=name, inventory_id=int(inventory_id), offers=offers if index == 0 else None)

    driver.get(BASE + "/carrito-veciahorra/")
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, "[data-va-cart-items] tr")) == 4)
    def coca_row():
        return next(row for row in driver.find_elements(By.CSS_SELECTOR, "[data-va-cart-items] tr") if "Coca-Cola Original" in row.text)
    while int(coca_row().find_element(By.CSS_SELECTOR, ".va-cart-quantity__value").text) < 3:
        before = int(coca_row().find_element(By.CSS_SELECTOR, ".va-cart-quantity__value").text)
        coca_row().find_elements(By.CSS_SELECTOR, ".va-cart-quantity__button")[-1].click()
        wait.until(lambda d: int(coca_row().find_element(By.CSS_SELECTOR, ".va-cart-quantity__value").text) > before)
    cart_text = driver.find_element(By.CSS_SELECTOR, "[data-va-cart-content]").text
    step("cart", item_rows=4, text=cart_text)

    driver.get(BASE + "/checkout/")
    form = wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "[data-va-checkout-form]")))
    wait.until(lambda d: len(d.find_elements(By.CSS_SELECTOR, '[name="delivery_method"]')) > 0)
    fields = {
        "first_name": "Carolina", "last_name": "Soto", "phone": "+56955550101",
        "email": "carolina.soto.demo@veciahorra.test", "address": "Av. Siempre Viva 742",
        "commune": "La Cisterna", "reference": "Casa azul, portón negro",
        "notes": "Ensayo integral de capacitación",
    }
    delivery = driver.find_elements(By.CSS_SELECTOR, '[name="delivery_method"][value="delivery"]')
    if delivery:
        driver.execute_script("arguments[0].scrollIntoView({block:'center'});", delivery[0])
        time.sleep(0.4)
        delivery[0].click()
    for name, value in fields.items():
        element = form.find_element(By.NAME, name)
        element.clear()
        element.send_keys(value)
    step("checkout_form", delivery_available=bool(delivery), address=fields["address"], commune=fields["commune"])
    driver.execute_script("""
        window.__vaWebpayHandoff = null;
        HTMLFormElement.prototype.submit = function () {
            if (this.action && this.action.indexOf('transbank.cl') !== -1) {
                window.__vaWebpayHandoff = {
                    action: this.action,
                    token_present: !!this.querySelector('[name="token_ws"]')
                };
                return;
            }
            return HTMLFormElement.prototype.requestSubmit.call(this);
        };
    """)
    submit = form.find_element(By.CSS_SELECTOR, "[data-va-checkout-submit]")
    driver.execute_script("arguments[0].scrollIntoView({block:'center'});", submit)
    time.sleep(0.4)
    submit.click()
    observations = []
    for _ in range(30):
        time.sleep(1)
        statuses = driver.find_elements(By.CSS_SELECTOR, "[data-va-checkout-status]")
        errors = driver.find_elements(By.CSS_SELECTOR, "[data-va-checkout-validation-errors]")
        observations.append({
            "url": driver.current_url,
            "status": statuses[0].text if statuses else "",
            "errors": errors[0].text if errors and errors[0].is_displayed() else "",
        })
        handoff = driver.execute_script("return window.__vaWebpayHandoff;")
        if "webpay3gint.transbank.cl" in driver.current_url or handoff:
            break
    step("checkout_submit_observed", observations=observations)
    result_nodes = driver.find_elements(By.CSS_SELECTOR, "[data-va-checkout-result]")
    if result_nodes and result_nodes[0].is_displayed():
        step("checkout_created", text=result_nodes[0].text)
    requests = []
    for entry in driver.get_log("performance"):
        message = json.loads(entry["message"])["message"]
        if message["method"] != "Network.requestWillBeSent":
            continue
        request = message["params"]["request"]
        if "/wp-json/veciahorra/v1/checkout" not in request["url"]:
            continue
        requests.append({
            "url": request["url"],
            "method": request["method"],
            "postData": json.loads(request["postData"]) if request.get("postData") else None,
        })
    step("checkout_requests", requests=requests)
    step("webpay_handoff_prevented", handoff=driver.execute_script("return window.__vaWebpayHandoff;"))
    step("payment_handoff", url=driver.current_url, title=driver.title, body=driver.find_element(By.TAG_NAME, "body").text[:2000])
finally:
    OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    driver.quit()
