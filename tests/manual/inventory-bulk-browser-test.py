import json, subprocess, tempfile
from pathlib import Path
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import Select, WebDriverWait

BASE="https://localhost/Minimarket"; PHP=r"C:\xampp\php\php.exe"; DRIVER=r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe"
ROOT=Path(__file__).resolve().parents[2]; FIXTURE=ROOT/'tests/manual/inventory-bulk-browser-fixture.php'; OUT=ROOT/'artifacts/inventory-bulk-runtime'; OUT.mkdir(parents=True,exist_ok=True)
def check(value,message):
    if not value: raise AssertionError(message)
fixture=json.loads(subprocess.check_output([PHP,str(FIXTURE),'setup'],text=True))
profile=tempfile.TemporaryDirectory(prefix='va-bulk-ui-'); options=webdriver.ChromeOptions()
for arg in ['--headless=new','--ignore-certificate-errors','--allow-insecure-localhost','--window-size=1440,1200','--disable-gpu','--no-sandbox']: options.add_argument(arg)
options.add_argument('--user-data-dir='+profile.name); options.set_capability('acceptInsecureCerts',True); options.set_capability('goog:loggingPrefs',{'browser':'ALL'})
driver=webdriver.Chrome(service=Service(DRIVER),options=options); wait=WebDriverWait(driver,30); results={}
def shot(name):
    path=OUT/(name+'.png'); check(driver.save_screenshot(str(path)) and path.stat().st_size>0,'Captura inválida '+name); results[name]=str(path)
def close_vendor_notices():
    for xpath in ["//button[normalize-space()='Entendido']", "//a[normalize-space()='Descartar']", "//button[contains(@class,'notice-dismiss')]", "//button[@aria-label='Descartar']"]:
        for element in driver.find_elements(By.XPATH,xpath):
            try:
                if element.is_displayed(): element.click()
            except Exception:
                pass
    driver.execute_script("document.querySelectorAll('[role=dialog]').forEach(e=>e.remove());document.querySelectorAll('.notice').forEach(e=>{if(!e.classList.contains('inline')&&!e.classList.contains('notice-success'))e.remove()})")
def upload(path):
    Select(driver.find_element(By.ID,'va-store')).select_by_value(str(fixture['store_id'])); driver.find_element(By.ID,'va-csv').send_keys(str(path)); driver.find_element(By.CSS_SELECTOR,"form[action*='admin-post.php'] button.button-primary").click(); wait.until(lambda d:'va_token=' in d.current_url); wait.until(lambda d:'Vista previa:' in d.page_source); close_vendor_notices()
try:
    driver.get(BASE+'/wp-login.php')
    for cookie in fixture['cookies']: driver.add_cookie({'name':cookie['name'],'value':cookie['value'],'path':cookie['path'],'secure':True})
    page=BASE+'/wp-admin/admin.php?page=veciahorra-inventory-import'; driver.get(page); wait.until(lambda d:'Carga masiva de inventario' in d.find_element(By.TAG_NAME,'body').text); close_vendor_notices()
    work=Path(tempfile.mkdtemp(prefix='va-bulk-csv-'))
    mixed=work/'mixed.csv'; mixed.write_text('sku,precio,stock,estado\n'+fixture['sku_a']+',1990,10,active\nSKU-INEXISTENTE-RUNTIME,1000,1,active\n',encoding='utf-8'); upload(mixed)
    body=driver.find_element(By.TAG_NAME,'body').text; check('1 válidas' in body and '1 rechazadas' in body,'Conteos mixtos incorrectos'); check('No se aplicó ningún cambio.' in body,'Mensaje de bloqueo ausente'); check(len(driver.find_elements(By.XPATH,"//button[contains(.,'Confirmar importación')]"))==0,'CSV mixto permite confirmar'); check('Activo' in body and 'Crear' in body,'Traducciones ausentes'); shot('01-preview-errores-bloqueada')
    driver.get(page); wait.until(lambda d:d.find_element(By.ID,'va-store').is_displayed()); close_vendor_notices(); valid=work/'valid.csv'; valid.write_text('sku,precio,stock,estado\n'+fixture['sku_a']+',1990,10,active\n'+fixture['sku_b']+',2490,4,inactive\n',encoding='utf-8'); upload(valid)
    body=driver.find_element(By.TAG_NAME,'body').text; check('2 válidas' in body and '0 rechazadas' in body,'Preview válida incorrecta'); check('Activo' in body and 'Inactivo' in body and body.count('Crear')>=2,'Traducciones válidas ausentes'); buttons=driver.find_elements(By.XPATH,"//button[contains(.,'Confirmar importación')]"); check(len(buttons)==1,'Preview válida sin confirmación'); shot('02-preview-totalmente-valida')
    buttons[0].click(); wait.until(lambda d:'Importación completada correctamente.' in d.find_element(By.TAG_NAME,'body').text); close_vendor_notices(); body=driver.find_element(By.TAG_NAME,'body').text; check('Creados: 2' in body and 'Rechazados' not in body,'Resultado final incorrecto'); check(len(driver.find_elements(By.XPATH,"//button[contains(.,'Confirmar importación')]"))==0,'Acción consumida visible'); shot('03-resultado-final-exitoso')
    logs=[e for e in driver.get_log('browser') if e.get('level')=='SEVERE' and ('localhost/Minimarket' in e.get('message','') or e.get('source')=='javascript')]; check(not logs,'Errores locales/JS: '+json.dumps(logs)); results['status']='PASS'; print(json.dumps(results,ensure_ascii=False,indent=2))
finally:
    driver.quit(); profile.cleanup(); cleanup=subprocess.check_output([PHP,str(FIXTURE),'cleanup',fixture['run']],text=True).strip(); print('BROWSER_CLEANUP='+cleanup); check(cleanup=='PASS','Residuos UI')
