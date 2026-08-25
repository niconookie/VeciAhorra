import json,tempfile,time,subprocess
from pathlib import Path
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import Select,WebDriverWait
ROOT=Path(__file__).resolve().parents[2];BASE='https://localhost/Minimarket';DRIVER=r'C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe';OUT=ROOT/'artifacts/anonymous-offers';OUT.mkdir(parents=True,exist_ok=True)
fixture=json.loads(subprocess.check_output([r'C:\xampp\php\php.exe',str(ROOT/'tests/manual/anonymous-product-offer-browser-fixture.php'),'setup'],text=True));previous_sector=subprocess.check_output([r'C:\xampp\php\php.exe',str(ROOT/'tests/manual/anonymous-product-offer-sector-state.php'),'set',str(fixture['zone'])],text=True).strip();p=tempfile.TemporaryDirectory(prefix='va-anonymous-offers-');o=webdriver.ChromeOptions()
for a in ['--headless=new','--ignore-certificate-errors','--allow-insecure-localhost','--window-size=1440,1100','--disable-gpu','--no-sandbox','--user-data-dir='+p.name]:o.add_argument(a)
o.set_capability('acceptInsecureCerts',True);o.set_capability('goog:loggingPrefs',{'browser':'ALL','performance':'ALL'});d=webdriver.Chrome(service=Service(DRIVER),options=o);w=WebDriverWait(d,30);result={}
def async_fetch(script,*args):return d.execute_async_script(script,*args)
def private(text):return any(x.lower() in text.lower() for x in ['minimarket central','minimarket plaza sur','private','@','rut','dirección del minimarket'])
try:
 session=json.loads(subprocess.check_output([r'C:\xampp\php\php.exe',str(ROOT/'tests/manual/anonymous-product-offer-session-fixture.php')],text=True));d.get(BASE+'/wp-login.php')
 for cookie in session['cookies']:d.add_cookie({'name':cookie['name'],'value':cookie['value'],'path':cookie['path'],'secure':True})
 sector_result=async_fetch("let done=arguments[arguments.length-1];fetch('/Minimarket/wp-json/veciahorra/v1/sector/current/'+arguments[0],{method:'POST'}).then(r=>r.json()).then(done)",str(fixture['zone']));product={'id':fixture['product'],'name':'Producto comparación anónima'};d.get(fixture['page'])
 try:w.until(lambda x:len(x.find_elements(By.CSS_SELECTOR,'.va-offer-card'))>=3)
 except Exception:
  print('OFFER_DEBUG='+json.dumps({'sector':sector_result,'url':d.current_url,'body':d.find_element(By.TAG_NAME,'body').text[:2000],'console':d.get_log('browser')}));raise
 cards=d.find_elements(By.CSS_SELECTOR,'.va-offer-card');assert len(cards)>=3 and all(('Opción '+chr(65+i)) in c.text for i,c in enumerate(cards[:3]));assert 'Precio más conveniente' in cards[0].text;body=d.find_element(By.TAG_NAME,'body').text;assert not private(body);html=d.page_source.lower();assert 'inventory_id' not in html and 'minimarket_id' not in html and 'data-inventory' not in html
 assert all(c.get_attribute('aria-checked')=='false' for c in cards[:3]);d.find_element(By.TAG_NAME,'body').send_keys(Keys.ESCAPE);assert all(c.get_attribute('aria-checked')=='false' for c in d.find_elements(By.CSS_SELECTOR,'.va-offer-card')[:3]);cards[2].click();w.until(lambda x:x.find_elements(By.CSS_SELECTOR,'.va-offer-card')[2].get_attribute('aria-checked')=='true');d.find_element(By.CSS_SELECTOR,'[data-va-offer-mode="free"]').click();assert d.find_elements(By.CSS_SELECTOR,'.va-offer-card')[2].get_attribute('aria-checked')=='true'
 d.save_screenshot(str(OUT/'01-tres-ofertas-anonimas-desktop.png'))
 d.find_elements(By.CSS_SELECTOR,'.va-offer-card')[1].click();w.until(lambda x:x.find_elements(By.CSS_SELECTOR,'.va-offer-card')[1].get_attribute('aria-checked')=='true')
 cart_result=d.execute_async_script("let done=arguments[arguments.length-1];document.querySelector('[data-va-product-detail]').vaOfferSelector.addToCart().then(done)")
 if not d.find_element(By.CSS_SELECTOR,'[data-va-cart-success]').is_displayed():raise AssertionError('ADD_CART_FAILED '+json.dumps({'result':cart_result,'error':d.find_element(By.CSS_SELECTOR,'[data-va-cart-error]').text}))
 d.execute_script('arguments[0].click()',d.find_element(By.CSS_SELECTOR,'[data-va-view-cart]'));w.until(lambda x:not x.find_element(By.CSS_SELECTOR,'[data-va-cart-loading]').is_displayed());assert not private(d.find_element(By.TAG_NAME,'body').text);d.save_screenshot(str(OUT/'02-carrito-anonimo.png'))
 d.execute_script('arguments[0].click()',d.find_element(By.CSS_SELECTOR,'[data-va-cart-checkout]'));w.until(lambda x:x.find_elements(By.CSS_SELECTOR,'[data-va-checkout]'));time.sleep(2);assert not private(d.find_element(By.TAG_NAME,'body').text);d.save_screenshot(str(OUT/'03-checkout-anonimo.png'))
 d.set_window_size(390,900);d.back();d.back();w.until(lambda x:len(x.find_elements(By.CSS_SELECTOR,'.va-offer-card'))>=3);assert d.execute_script('return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1');d.save_screenshot(str(OUT/'04-tres-ofertas-anonimas-mobile.png'))
 # Owner-only post-payment projection is exercised by the existing customer panel.
 d.get(BASE+'/mis-compras/');time.sleep(3);paid=d.find_elements(By.CSS_SELECTOR,'a[href*="compra"],a[href*="checkout"]');post=None
 if paid:
  target=next((x for x in paid if x.is_displayed()),paid[0]);d.execute_script('arguments[0].click()',target);time.sleep(2);stores=[x for x in d.find_elements(By.CSS_SELECTOR,'.va-customer-purchase-order h4,h4') if x.is_displayed()]
  if stores:d.execute_script("arguments[0].scrollIntoView({block:'center'})",stores[0]);time.sleep(1)
  post=str(OUT/'05-postpago-propietario.png');d.save_screenshot(post)
 severe=[x for x in d.get_log('browser') if x.get('level')=='SEVERE' and 'ERR_NETWORK_ACCESS_DENIED' not in x.get('message','')];assert not severe,severe
 result={'status':'PASS','product':product['name'],'offers':len(cards),'choice':'B','desktop':str(OUT/'01-tres-ofertas-anonimas-desktop.png'),'mobile':str(OUT/'04-tres-ofertas-anonimas-mobile.png'),'cart':str(OUT/'02-carrito-anonimo.png'),'checkout':str(OUT/'03-checkout-anonimo.png'),'postpayment':post,'js_errors':0}
 print(json.dumps(result,ensure_ascii=False,indent=2))
finally:
 try:async_fetch("let done=arguments[arguments.length-1];fetch('/Minimarket/wp-json/veciahorra/v1/cart',{method:'DELETE'}).then(r=>r.json()).then(done)")
 except Exception:pass
 d.quit();p.cleanup();print('SECTOR_RESTORE='+subprocess.check_output([r'C:\xampp\php\php.exe',str(ROOT/'tests/manual/anonymous-product-offer-sector-state.php'),'restore',previous_sector],text=True).strip());print('BROWSER_FIXTURE_CLEANUP='+subprocess.check_output([r'C:\xampp\php\php.exe',str(ROOT/'tests/manual/anonymous-product-offer-browser-fixture.php'),'cleanup',fixture['run']],text=True).strip())
