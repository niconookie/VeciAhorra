"""Deterministic browser evidence for the Service Providers training surface."""
from pathlib import Path
import atexit
import json
import subprocess
import time

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "artifacts" / "service-providers-visual"
OUT.mkdir(parents=True, exist_ok=True)
BASE = "https://localhost/Minimarket"
DRIVER = r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"


def browser(width, height):
    options = Options()
    options.add_argument("--headless=new")
    options.add_argument("--ignore-certificate-errors")
    options.add_argument("--allow-insecure-localhost")
    options.add_argument(f"--window-size={width},{height}")
    if width <= 500:
        options.add_experimental_option("mobileEmulation", {
            "deviceMetrics": {"width": width, "height": height, "pixelRatio": 1},
        })
    options.set_capability("goog:loggingPrefs", {"browser": "ALL"})
    return webdriver.Chrome(service=Service(DRIVER), options=options)


def overflow(driver):
    return driver.execute_script(
        "return {scroll:document.documentElement.scrollWidth,client:document.documentElement.clientWidth}"
    )


def section_shot(driver, selector, filename):
    target = driver.find_element(By.CSS_SELECTOR, selector)
    driver.execute_script("arguments[0].scrollIntoView({block:'start'});", target)
    time.sleep(0.5)
    driver.save_screenshot(str(OUT / filename))


fixture = json.loads(subprocess.check_output(
    ["php", str(ROOT / "tests/manual/service-providers-visual-fixture.php"), "setup"],
    cwd=ROOT,
    text=True,
).strip())
def clean_fixture():
    subprocess.call([
        "php", str(ROOT / "tests/manual/service-providers-visual-fixture.php"), "cleanup",
        str(fixture["provider_id"]), str(fixture["user_id"]),
    ], cwd=ROOT)


atexit.register(clean_fixture)
results = {"fixture": "temporary-and-cleaned"}
desktop = browser(1440, 1000)
try:
    desktop.get(BASE + "/prestadores/")
    WebDriverWait(desktop, 15).until(EC.presence_of_element_located((By.CSS_SELECTOR, ".va-sp-hero")))
    section_shot(desktop, ".va-sp-header", "landing-desktop.png")
    section_shot(desktop, "#planes", "plans-desktop.png")
    section_shot(desktop, "#registro", "wizard-desktop.png")
    results["landing_desktop"] = overflow(desktop)
    results["landing_console"] = desktop.get_log("browser")

    desktop.get(BASE + "/servicios/")
    time.sleep(3)
    WebDriverWait(desktop, 15).until(EC.presence_of_element_located((By.CSS_SELECTOR, ".va-sp-service-card")))
    desktop.save_screenshot(str(OUT / "services-desktop.png"))
    cards = desktop.find_elements(By.CSS_SELECTOR, ".va-sp-service-card")
    jose = next((card for card in cards if "José" in card.text or "Jose" in card.text), cards[0])
    desktop.execute_script("arguments[0].click();", jose.find_element(By.CSS_SELECTOR, "button"))
    WebDriverWait(desktop, 15).until(EC.presence_of_element_located((By.CSS_SELECTOR, ".va-sp-public-profile")))
    section_shot(desktop, ".va-sp-public-profile", "profile-jose-desktop.png")
    results["services_desktop"] = overflow(desktop)
    results["services_console"] = desktop.get_log("browser")
finally:
    desktop.quit()

mobile = browser(390, 844)
try:
    mobile.get(BASE + "/prestadores/")
    WebDriverWait(mobile, 15).until(EC.presence_of_element_located((By.CSS_SELECTOR, ".va-sp-hero")))
    section_shot(mobile, ".va-sp-header", "landing-mobile.png")
    section_shot(mobile, "#registro", "wizard-mobile.png")
    results["landing_mobile"] = overflow(mobile)
    results["mobile_console"] = mobile.get_log("browser")
finally:
    mobile.quit()

tablet = browser(768, 1024)
try:
    tablet.get(BASE + "/prestadores/")
    WebDriverWait(tablet, 15).until(EC.presence_of_element_located((By.CSS_SELECTOR, ".va-sp-wizard")))
    results["landing_tablet"] = overflow(tablet)
    results["tablet_console"] = tablet.get_log("browser")
finally:
    tablet.quit()

(OUT / "browser-results.json").write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
print(json.dumps(results, ensure_ascii=False, indent=2))
