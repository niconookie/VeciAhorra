"""Deterministic authenticated/visitor browser certification for Sectorization V1."""
import json, os, tempfile
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC

ROOT=os.environ.get("VA_TEST_ROOT", "https://localhost/Minimarket").rstrip("/"); CATALOG=ROOT+"/catalogo-veciahorra/"; DRIVER=os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")
def browser(width=1440,height=1000):
 p=tempfile.TemporaryDirectory(prefix="va-sector-");o=webdriver.ChromeOptions()
 for a in ["--headless=new","--ignore-certificate-errors","--allow-insecure-localhost","--disable-gpu","--no-sandbox",f"--window-size={width},{height}","--user-data-dir="+p.name]:o.add_argument(a)
 o.set_capability("acceptInsecureCerts",True);o.set_capability("goog:loggingPrefs",{"browser":"ALL","performance":"ALL"})
 return webdriver.Chrome(service=Service(DRIVER),options=o),p
def selector_ready(d,w):
 s=w.until(lambda x:x.find_element(By.CSS_SELECTOR,"[data-va-sector-select]"));w.until(lambda x:len(Select(s).options)==3);return s
def click_visible(d,w,element):
 d.execute_script("arguments[0].scrollIntoView({block:'center',inline:'nearest'})",element);w.until(lambda x:element.is_displayed() and element.is_enabled() and x.execute_script("const r=arguments[0].getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight",element));element.click()
def catalog_ready(d,w,expected):w.until(lambda x:expected in x.find_element(By.TAG_NAME,"body").text and (not x.find_elements(By.CSS_SELECTOR,"[data-va-catalog-loading]") or not x.find_element(By.CSS_SELECTOR,"[data-va-catalog-loading]").is_displayed()))
def open_product(d,w,name):
 card=next(c for c in d.find_elements(By.CSS_SELECTOR,".va-catalog-card") if name in c.text);link=card.find_element(By.CSS_SELECTOR,"a.va-catalog-card__action");d.execute_script("arguments[0].scrollIntoView({block:'center',inline:'nearest'})",link);w.until(lambda x:x.execute_script("const r=arguments[0].getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight",link));link.click();w.until(lambda x:name in x.find_element(By.CSS_SELECTOR,"[data-va-product-name]").text);w.until(lambda x:not x.find_element(By.CSS_SELECTOR,"[data-va-product-loading]").is_displayed())
def change(d,w,value,present,absent):
 s=selector_ready(d,w);Select(s).select_by_value(value)
 w.until(EC.staleness_of(s));w.until(lambda x:x.execute_script("return document.readyState")=="complete")
 s=selector_ready(d,w);w.until(lambda x:Select(s).first_selected_option.get_attribute("value")==value);catalog_ready(d,w,present)
 body=d.find_element(By.TAG_NAME,"body").text
 if absent:assert absent not in body,(present,absent)
 return Select(s).first_selected_option.text
result={}
d,p=browser()
try:
 w=WebDriverWait(d,30);d.get(ROOT+"/wp-login.php");d.find_element(By.ID,"user_login").send_keys("va_demo_carolina");d.find_element(By.ID,"user_pass").send_keys("VA-Cliente-2026!");d.find_element(By.ID,"wp-submit").click()
 w.until(lambda x:"wp-login.php" not in x.current_url and any(c["name"].startswith("wordpress_logged_in_") for c in x.get_cookies()));d.get(CATALOG);s=selector_ready(d,w);result["initial_zone"]=Select(s).first_selected_option.text;result["selector"]="PASS"
 if Select(s).first_selected_option.get_attribute("value")!="1":change(d,w,"1","Papas fritas Marco Polo","")
 else:catalog_ready(d,w,"Papas fritas Marco Polo")
 result["republica"]="PASS"
 label=change(d,w,"2","Yogurt Soprole","Papas fritas Marco Polo");assert "Centro Sur" in label;result["centro_sur"]="PASS"
 d.refresh();s=selector_ready(d,w);assert Select(s).first_selected_option.get_attribute("value")=="2";catalog_ready(d,w,"Yogurt Soprole");result["auth_persistence"]="PASS"
 d.set_window_size(390,844);d.refresh();s=selector_ready(d,w);assert d.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth");change(d,w,"1","Papas fritas Marco Polo","");result["mobile"]="PASS"
 d.set_window_size(1440,1000);open_product(d,w,"Coca-Cola Original 1,5 L");w.until(lambda x:"Minimarket Los Vecinos" in x.find_element(By.CSS_SELECTOR,"[data-va-offer-list]").text);change(d,w,"2","Coca-Cola Original 1,5 L","");offers=w.until(lambda x:x.find_element(By.CSS_SELECTOR,"[data-va-offer-list]")).text;assert "Minimarket Los Vecinos" not in offers and "Minimarket Plaza Sur" in offers;result["product_detail"]="PASS"
 change(d,w,"1","Coca-Cola Original 1,5 L","");d.get(CATALOG);catalog_ready(d,w,"Papas fritas Marco Polo");open_product(d,w,"Papas fritas Marco Polo");exclusive_product_url=d.current_url;offer=w.until(lambda x:x.find_element(By.CSS_SELECTOR,".va-offer-card[data-inventory-id]"));click_visible(d,w,offer);add=d.find_element(By.CSS_SELECTOR,"[data-va-add-to-cart]");w.until(lambda x:add.is_enabled());click_visible(d,w,add);view=w.until(lambda x:x.find_element(By.CSS_SELECTOR,"[data-va-view-cart]:not([hidden])"));click_visible(d,w,view);w.until(lambda x:x.find_element(By.CSS_SELECTOR,"[data-cart-item-id]"));result["cart_preserved"]="PASS"
 change(d,w,"2","Papas fritas Marco Polo","");w.until(lambda x:"no está disponible en tu sector actual" in x.find_element(By.CSS_SELECTOR,"[data-cart-item-id]").text);result["cart_identified"]="PASS"
 cart_url=d.current_url;d.get(exclusive_product_url);selector_ready(d,w);w.until(lambda x:not x.find_element(By.CSS_SELECTOR,"[data-va-product-loading]").is_displayed());assert not d.find_elements(By.CSS_SELECTOR,".va-offer-card[data-inventory-id]") and not d.find_element(By.CSS_SELECTOR,"[data-va-add-to-cart]").is_enabled();result["incompatible_addition"]="PASS";d.get(cart_url);w.until(lambda x:x.find_element(By.CSS_SELECTOR,"[data-cart-item-id]"));checkout=d.find_element(By.CSS_SELECTOR,"[data-va-cart-checkout]");click_visible(d,w,checkout);w.until(lambda x:x.find_element(By.CSS_SELECTOR,"[data-va-checkout-content]").is_displayed());submit=d.find_element(By.CSS_SELECTOR,"[data-va-checkout-submit]");d.find_element(By.NAME,"first_name").send_keys("Carolina");d.find_element(By.NAME,"last_name").send_keys("Soto");phone=d.find_element(By.NAME,"phone");email=d.find_element(By.NAME,"email");phone.send_keys("+56955550000");email.send_keys("carolina.soto.demo@veciahorra.test");click_visible(d,w,submit);errors=w.until(lambda x:x.find_element(By.CSS_SELECTOR,"[data-va-checkout-validation-error-list]"));w.until(lambda x:errors.text.strip()!="");assert "sector" in errors.text.lower();result["checkout_blocked"]="PASS"
 change(d,w,"1","Papas fritas Marco Polo","");d.get(cart_url);selector_ready(d,w);w.until(lambda x:x.find_elements(By.CSS_SELECTOR,"[data-cart-item-id]") and not x.find_elements(By.CSS_SELECTOR,".va-cart-sector-warning"));result["cart_revalidated"]="PASS"
 result["js_errors"]=len([e for e in d.get_log("browser") if e.get("level")=="SEVERE" and e.get("source")=="javascript"])
finally:d.quit();p.cleanup()
d,p=browser(390,844)
try:
 w=WebDriverWait(d,30);d.get(CATALOG);s=selector_ready(d,w);target="2" if Select(s).first_selected_option.get_attribute("value")!="2" else "1";change(d,w,target,"Yogurt Soprole" if target=="2" else "Papas fritas Marco Polo","Papas fritas Marco Polo" if target=="2" else "");d.refresh();s=selector_ready(d,w);assert Select(s).first_selected_option.get_attribute("value")==target;result["visitor"]="PASS"
finally:d.quit();p.cleanup()
print(json.dumps(result,ensure_ascii=False))
