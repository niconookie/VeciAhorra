"""Real-browser validation for the public catalog filter layout."""

import json
import os
import tempfile

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import Select, WebDriverWait

BASE = os.environ.get("VA_TEST_ROOT", "https://localhost/Minimarket").rstrip("/") + "/catalogo-veciahorra/"
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")


def check(condition, message):
    if not condition:
        raise AssertionError(message)


profile = tempfile.TemporaryDirectory(prefix="va-catalog-filters-")
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
result = {}


def dimensions(elements):
    return [driver.execute_script(
        "const r=arguments[0].getBoundingClientRect(); return {top:r.top,height:r.height,width:r.width,bottom:r.bottom};",
        element,
    ) for element in elements]


try:
    driver.get(BASE)
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    form = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, "[data-va-catalog-filters]"))
    category = form.find_element(By.CSS_SELECTOR, "[data-va-catalog-category]")
    wait.until(lambda current: category.is_enabled())

    fields = form.find_elements(By.CSS_SELECTOR, ":scope > .va-field")
    check(len(fields) == 3, "No se encontraron los tres campos principales.")
    labels = dimensions([field.find_element(By.TAG_NAME, "label") for field in fields])
    controls = dimensions([
        fields[0].find_element(By.TAG_NAME, "input"),
        fields[1].find_element(By.TAG_NAME, "select"),
        fields[2].find_element(By.TAG_NAME, "select"),
    ])
    check(max(item["top"] for item in labels) - min(item["top"] for item in labels) <= 1, "Etiquetas desalineadas: " + repr(labels))
    check(max(item["top"] for item in controls) - min(item["top"] for item in controls) <= 1, "Controles desalineados: " + repr(controls))
    check(max(item["height"] for item in controls) - min(item["height"] for item in controls) <= 1, "Alturas de controles distintas: " + repr(controls))
    actions = dimensions([form.find_element(By.CSS_SELECTOR, ".va-catalog__filter-actions")])[0]
    check(actions["top"] > max(item["bottom"] for item in controls), "Las acciones no permanecen debajo de los filtros.")
    result["desktop"] = "PASS"

    order = Select(form.find_element(By.CSS_SELECTOR, "[data-va-catalog-order]"))
    order.select_by_value("price")
    if len(Select(category).options) > 1:
        Select(category).select_by_index(1)
    apply_button = form.find_element(By.CSS_SELECTOR, 'button[type="submit"]')
    driver.execute_script("arguments[0].scrollIntoView({block:'center',inline:'nearest'})", apply_button)
    wait.until(lambda current: apply_button.is_displayed() and apply_button.is_enabled()
               and current.execute_script("const r=arguments[0].getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight", apply_button))
    apply_button.click()
    wait.until(lambda current: not current.find_element(By.CSS_SELECTOR, "[data-va-catalog-loading]").is_displayed())
    form.find_element(By.CSS_SELECTOR, "[data-va-catalog-reset]").click()
    wait.until(lambda current: Select(current.find_element(By.CSS_SELECTOR, "[data-va-catalog-order]")).first_selected_option.get_attribute("value") == "name")
    check(Select(category).first_selected_option.get_attribute("value") == "", "Restablecer no limpió Categoría.")
    result["functionality"] = "PASS"

    driver.set_window_size(390, 844)
    driver.get(BASE)
    wait.until(lambda current: current.execute_script("return document.readyState") == "complete")
    mobile_form = driver.find_element(By.CSS_SELECTOR, "[data-va-catalog-filters]")
    mobile_fields = mobile_form.find_elements(By.CSS_SELECTOR, ":scope > .va-field")
    mobile_boxes = dimensions(mobile_fields)
    check(all(mobile_boxes[index]["top"] < mobile_boxes[index + 1]["top"] for index in range(2)), "Los filtros no se apilan en móvil.")
    check(driver.execute_script("return document.documentElement.scrollWidth <= document.documentElement.clientWidth"), "Overflow horizontal móvil.")
    result["mobile"] = "PASS"

    html = driver.page_source
    check("Warning:" not in html and "Notice:" not in html, "Warning/notice PHP visible.")
    severe_js = [entry for entry in driver.get_log("browser") if entry.get("level") == "SEVERE" and entry.get("source") == "javascript"]
    check(not severe_js, "Errores JavaScript: " + json.dumps(severe_js, ensure_ascii=False))
    result["js_errors"] = 0
    result["php_warnings"] = 0
    print(json.dumps(result, ensure_ascii=False))
finally:
    driver.quit()
    profile.cleanup()
