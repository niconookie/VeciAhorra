import json
import sys
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait


ROOT = Path(__file__).resolve().parents[2]
BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
OUT = ROOT / "artifacts" / "prelaunch"
MODE = sys.argv[1]
FIXTURE = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
SESSION = json.loads(Path(sys.argv[3]).read_text(encoding="utf-8"))

if MODE not in ("closed", "open"):
    raise RuntimeError("Mode must be closed or open.")

OUT.mkdir(parents=True, exist_ok=True)
profile = tempfile.TemporaryDirectory(prefix="va-prelaunch-catalog-")
options = webdriver.ChromeOptions()
for argument in (
    "--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost",
    "--window-size=1440,1100", "--disable-gpu", "--no-sandbox",
    "--user-data-dir=" + profile.name,
):
    options.add_argument(argument)
options.set_capability("acceptInsecureCerts", True)
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 30)


def fetch(script, *args):
    return driver.execute_async_script(script, *args)


def cart():
    return fetch("""
        const done = arguments[arguments.length - 1];
        const config = window.VeciAhorra || {}, cart = config.cart || {}, headers = {};
        if (cart.sessionId && cart.sessionHeader) headers[cart.sessionHeader] = cart.sessionId;
        fetch('/Minimarket/wp-json/veciahorra/v1/cart', {credentials: 'same-origin', headers})
          .then(async response => done({status: response.status, body: await response.json()}));
    """)


def network_entries():
    entries = []
    requests = {}
    for raw in driver.get_log("performance"):
        message = json.loads(raw["message"])["message"]
        params = message.get("params", {})
        if message["method"] == "Network.requestWillBeSent":
            request = params.get("request", {})
            url = request.get("url", "")
            if "/Minimarket/" in url:
                requests[params.get("requestId")] = {
                    "method": request.get("method"),
                    "path": url.split("/Minimarket", 1)[1].split("?", 1)[0] or "/",
                }
        elif message["method"] == "Network.responseReceived":
            item = requests.get(params.get("requestId"))
            if item:
                item = dict(item)
                item["status"] = int(params.get("response", {}).get("status", 0))
                entries.append(item)
    return entries


result = {"mode": MODE, "product_id": FIXTURE["product"], "zone_id": FIXTURE["zone"]}
try:
    driver.get(BASE + "/")
    selected_sector = fetch("""
        const done = arguments[arguments.length - 1];
        fetch('/Minimarket/wp-json/veciahorra/v1/sector/current/' + arguments[0], {
          method: 'POST', credentials: 'same-origin'
        }).then(async response => done({status: response.status, body: await response.json()}));
    """, str(FIXTURE["zone"]))
    assert selected_sector["status"] == 200, selected_sector
    driver.get(BASE + "/catalogo-veciahorra/")
    wait.until(lambda current: not current.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())
    wait.until(lambda current: len(current.find_elements(By.CSS_SELECTOR, ".va-catalog-card")) == 1)
    cards = driver.find_elements(By.CSS_SELECTOR, ".va-catalog-card")
    assert "Producto comparaci" in cards[0].text
    assert driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1")

    if MODE == "closed":
        labels = [node.text for node in driver.find_elements(By.CSS_SELECTOR, ".va-catalog-card__add")]
        assert labels == ["Disponible desde el 1 de septiembre"], labels
        before = cart()
        blocked = fetch("""
            const done = arguments[arguments.length - 1];
            const config = window.VeciAhorra || {}, cart = config.cart || {};
            const headers = {'Content-Type': 'application/json'};
            if (cart.sessionId && cart.sessionHeader) headers[cart.sessionHeader] = cart.sessionId;
            fetch('/Minimarket/wp-json/veciahorra/v1/cart/items', {
              method: 'POST', credentials: 'same-origin',
              headers, body: JSON.stringify({})
            }).then(async response => done({
              status: response.status,
              cache_control: response.headers.get('cache-control') || '',
              pragma: response.headers.get('pragma') || '',
              body: await response.json()
            }));
        """)
        after = cart()
        assert blocked["status"] == 503
        assert blocked["body"]["error"]["code"] == "commerce_disabled"
        assert "no-store" in blocked["cache_control"].lower() or "no-cache" in blocked["cache_control"].lower()
        assert before["body"] == after["body"]
        target = OUT / "03-catalogo-productos-visibles-compra-bloqueada.png"
        driver.save_screenshot(str(target))
        result.update({"capture": str(target), "cards": len(cards), "blocked_post": blocked, "zero_writes": True})
    else:
        detail = cards[0].find_element(By.CSS_SELECTOR, "a.va-catalog-card__action").get_attribute("href")
        driver.get(detail)
        wait.until(lambda current: len(current.find_elements(By.CSS_SELECTOR, ".va-offer-card")) == 3)
        offers = driver.find_elements(By.CSS_SELECTOR, ".va-offer-card")
        assert ["Opción A" in offers[0].text, "Opción B" in offers[1].text, "Opción C" in offers[2].text] == [True, True, True]
        assert not any("Private" in offer.text or "@" in offer.text for offer in offers)
        offers[1].click()
        wait.until(lambda current: current.find_elements(By.CSS_SELECTOR, ".va-offer-card")[1].get_attribute("aria-checked") == "true")
        add = driver.find_element(By.CSS_SELECTOR, "[data-va-add-to-cart]")
        assert add.is_enabled() and "Agregar al carrito" in add.text
        added = driver.execute_async_script("""
            const done = arguments[arguments.length - 1];
            document.querySelector('[data-va-product-detail]').vaOfferSelector.addToCart().then(done);
        """)
        wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-va-cart-success]").is_displayed())
        populated = cart()
        assert populated["status"] != 503 and len(populated["body"].get("data", [])) == 1
        driver.set_window_size(1440, 1600)
        driver.execute_script("arguments[0].scrollIntoView({block: 'end'});", add)
        target = OUT / "05-modo-abierto-mismas-ofertas-compra-habilitada.png"
        driver.save_screenshot(str(target))
        cleared = fetch("""
            const done = arguments[arguments.length - 1];
            const config = window.VeciAhorra || {}, cart = config.cart || {}, headers = {};
            if (cart.sessionId && cart.sessionHeader) headers[cart.sessionHeader] = cart.sessionId;
            fetch('/Minimarket/wp-json/veciahorra/v1/cart', {method: 'DELETE', credentials: 'same-origin', headers})
              .then(async response => done({status: response.status, body: await response.json()}));
        """)
        assert cleared["status"] != 503 and cart()["body"].get("data", []) == []
        result.update({"capture": str(target), "offers": ["A", "B", "C"], "selected": "B", "add_status": populated["status"], "cart_cleaned": True})

    result["overflow"] = False
    result["network"] = network_entries()
    intentional = [entry for entry in result["network"] if entry["status"] >= 500]
    if MODE == "closed":
        assert intentional == [{"method": "POST", "path": "/wp-json/veciahorra/v1/cart/items", "status": 503}], intentional
    else:
        assert intentional == [], intentional
    severe = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE"
              and not (MODE == "closed" and "/cart/items" in entry.get("message", "") and "503" in entry.get("message", ""))]
    assert not severe, severe
    result["js_errors"] = 0
    result["nominal_5xx"] = 0
    print(json.dumps(result, ensure_ascii=False, indent=2))
finally:
    try:
        fetch("""
            const done = arguments[arguments.length - 1];
            const config = window.VeciAhorra || {}, cart = config.cart || {}, headers = {};
            if (cart.sessionId && cart.sessionHeader) headers[cart.sessionHeader] = cart.sessionId;
            fetch('/Minimarket/wp-json/veciahorra/v1/cart', {method: 'DELETE', credentials: 'same-origin', headers})
              .then(async response => done({status: response.status}));
        """)
    except Exception:
        pass
    driver.quit()
    profile.cleanup()
