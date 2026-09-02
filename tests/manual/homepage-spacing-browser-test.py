"""Visual regression check for homepage section spacing."""

import json
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import Select, WebDriverWait

BASE = "https://localhost/Minimarket/"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"


def check(condition, message):
    if not condition:
        raise AssertionError(message)


profile = tempfile.TemporaryDirectory(prefix="va-home-spacing-")
options = webdriver.ChromeOptions()
for argument in ["--headless=new", "--ignore-certificate-errors", "--allow-insecure-localhost", "--disable-gpu", "--no-sandbox"]:
    options.add_argument(argument)
options.add_argument("--user-data-dir=" + profile.name)
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 30)
results = {}


def measure(label, width, height):
    if width < 768:
        driver.execute_cdp_cmd("Emulation.setDeviceMetricsOverride", {"width": width, "height": height, "deviceScaleFactor": 1, "mobile": True})
    else:
        driver.execute_cdp_cmd("Emulation.clearDeviceMetricsOverride", {})
    driver.set_window_size(width, height)
    chrome_width = driver.execute_script("return window.outerWidth - window.innerWidth")
    chrome_height = driver.execute_script("return window.outerHeight - window.innerHeight")
    driver.set_window_size(width + chrome_width, height + chrome_height)
    driver.get(BASE)
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    wait.until(lambda current: current.find_elements(By.CSS_SELECTOR, ".va-home-hero"))
    wait.until(lambda current: current.find_elements(By.CSS_SELECTOR, "[data-va-home-products]"))
    wait.until(lambda current: "Cargando" not in current.find_element(By.CSS_SELECTOR, "[data-va-home-products-status]").text)
    metrics = driver.execute_script("""
        const hero = document.querySelector('.va-home-hero').getBoundingClientRect();
        const products = document.querySelector('[data-va-home-products]').getBoundingClientRect();
        const heading = document.querySelector('.va-home-products__heading').getBoundingClientRect();
        const how = document.querySelector('.va-home-how-it-works').getBoundingClientRect();
        const link = document.querySelector('.va-home-products__catalog-link').getBoundingClientRect();
        return {
            viewport: [innerWidth, innerHeight],
            heroHeight: hero.height,
            heroToSection: products.top - hero.bottom,
            heroToHeading: heading.top - hero.bottom,
            productsToHow: how.top - products.bottom,
            contentToHow: how.top - link.bottom,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            overlap: !(hero.bottom <= products.top && products.bottom <= how.top),
            status: document.querySelector('[data-va-home-products-status]').textContent.trim(),
            cards: document.querySelectorAll('.va-catalog-card--homepage').length
        };
    """)
    check(metrics["heroToSection"] >= -1, label + ": products overlap hero")
    check(39 <= metrics["heroToHeading"] <= 65, label + ": unnatural hero-to-heading spacing")
    check(abs(metrics["productsToHow"]) <= 1, label + ": products overlap how-it-works")
    check(39 <= metrics["contentToHow"] <= 81, label + ": how-it-works spacing changed")
    check(metrics["overflow"] <= 1, label + ": horizontal overflow")
    check(not metrics["overlap"], label + ": section overlap")
    screenshot = Path(tempfile.gettempdir()) / ("veciahorra-home-spacing-" + label + ".png")
    check(driver.save_screenshot(str(screenshot)), label + ": screenshot failed")
    metrics["screenshot"] = str(screenshot)
    if width < 768:
        driver.execute_script("document.querySelector('.va-home-products__heading').scrollIntoView({block: 'center'})")
        products_screenshot = Path(tempfile.gettempdir()) / ("veciahorra-home-spacing-" + label + "-products.png")
        check(driver.save_screenshot(str(products_screenshot)), label + ": products screenshot failed")
        metrics["productsScreenshot"] = str(products_screenshot)
    results[label] = metrics


try:
    measure("desktop-no-sector", 1440, 1000)
    check("Selecciona un sector para ver productos disponibles." in results["desktop-no-sector"]["status"], "missing no-sector state")

    driver.find_element(By.CSS_SELECTOR, ".va-global-header__sector").click()
    selector = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-va-header-sector-select]"))
    wait.until(lambda current: len(Select(selector).options) > 1)
    old_body = driver.find_element(By.TAG_NAME, "body")
    Select(selector).select_by_index(1)
    driver.execute_script("arguments[0].dispatchEvent(new Event('change', {bubbles: true}))", selector)
    wait.until(EC.staleness_of(old_body))

    measure("desktop-sector", 1440, 1000)
    measure("mobile-sector", 390, 844)
    check(results["desktop-sector"]["cards"] > 0, "selected sector has no products")
    check(results["mobile-sector"]["cards"] > 0, "selected sector lost products on mobile")
    print(json.dumps({"status": "PASS", "results": results}, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
