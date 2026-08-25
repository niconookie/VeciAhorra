import json, shutil, subprocess, tempfile, zipfile
from pathlib import Path
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE='https://localhost/Minimarket';PHP=r'C:\xampp\php\php.exe';DRIVER=r'C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe';ROOT=Path(__file__).resolve().parents[2];FIXTURE=ROOT/'tests/manual/product-bulk-browser-fixture.php';OUT=ROOT/'artifacts/product-bulk-runtime';OUT.mkdir(parents=True,exist_ok=True)
def check(v,m):
    if not v: raise AssertionError(m)
fixture=json.loads(subprocess.check_output([PHP,str(FIXTURE),'setup'],text=True));work=Path(tempfile.mkdtemp(prefix='va-product-ui-'));profile=tempfile.TemporaryDirectory(prefix='va-product-ui-profile-');options=webdriver.ChromeOptions()
for arg in ['--headless=new','--ignore-certificate-errors','--allow-insecure-localhost','--window-size=1440,1100','--disable-gpu','--no-sandbox','--user-data-dir='+profile.name]:options.add_argument(arg)
options.set_capability('acceptInsecureCerts',True);options.set_capability('goog:loggingPrefs',{'browser':'ALL'});driver=webdriver.Chrome(service=Service(DRIVER),options=options);wait=WebDriverWait(driver,30);result={'screenshots':{},'overflow':{}}
def notices():
    for e in driver.find_elements(By.CSS_SELECTOR,'.notice-dismiss'):
        try:
            if e.is_displayed():e.click()
        except Exception:pass
def shot(name):
    notices();path=OUT/(name+'.png');check(driver.save_screenshot(str(path)) and path.stat().st_size>0,'Captura vacía');overflow=driver.execute_script('return document.documentElement.scrollWidth>document.documentElement.clientWidth');check(not overflow,'Overflow '+name);result['screenshots'][name]=str(path);result['overflow'][name]=False
def csv(path,rows):
    header='sku,nombre,categoria,subcategoria,marca,unidad,descripcion,estado,imagen\n';path.write_text('\ufeff'+header+'\n'.join(rows)+'\n',encoding='utf-8')
def row(sku,name,status='draft',image=''):
    return ','.join([sku,name,fixture['category'],fixture['subcategory'],fixture['brand'],fixture['unit'],'Descripción segura',status,image])
def upload(csv_path,zip_path=None):
    driver.find_element(By.NAME,'product_csv').send_keys(str(csv_path));
    if zip_path:driver.find_element(By.NAME,'product_images').send_keys(str(zip_path))
    driver.find_element(By.XPATH,"//button[contains(.,'Validar y ver vista previa')]").click();wait.until(lambda d:'va_token=' in d.current_url or 'va_message=' in d.current_url);body=driver.find_element(By.TAG_NAME,'body').text;check('Vista previa' in body,'Carga rechazada: '+body[:800]+' URL='+driver.current_url)
try:
    driver.get(BASE+'/wp-login.php')
    for c in fixture['cookies']:driver.add_cookie({'name':c['name'],'value':c['value'],'path':c['path'],'secure':True})
    page=BASE+'/wp-admin/admin.php?page=veciahorra-products-import';driver.get(page);wait.until(lambda d:'Carga masiva del catálogo maestro' in d.find_element(By.TAG_NAME,'body').text);shot('01-pantalla-inicial')
    mixed=work/'mixed.csv';csv(mixed,[row(fixture['sku_a'],'Producto A'),row(fixture['sku_b'],'Producto B','unknown')]);upload(mixed);body=driver.find_element(By.TAG_NAME,'body').text;check('1 rechazadas' in body and 'No se aplicó ningún cambio.' in body,'Preview mixta no bloqueada');check(not driver.find_elements(By.XPATH,"//button[contains(.,'Confirmar importación')]"),'Confirmación visible con errores');shot('02-preview-errores-bloqueada')
    driver.get(page);valid=work/'valid.csv';csv(valid,[row(fixture['sku_a'],'Producto A'),row(fixture['sku_b'],'Producto B','inactive')]);upload(valid);body=driver.find_element(By.TAG_NAME,'body').text;check('2 por crear' in body and '0 rechazadas' in body,'Preview válida incorrecta');shot('03-preview-valida-productos');driver.find_element(By.XPATH,"//button[contains(.,'Confirmar importación')]").click();wait.until(lambda d:'Importación completada.' in d.find_element(By.TAG_NAME,'body').text)
    driver.get(page);image_name=fixture['sku_a']+'.png';image_csv=work/'image.csv';csv(image_csv,[row(fixture['sku_a'],'Producto A','draft',image_name)]);image_zip=work/'images.zip';source=Path(r'C:\xampp\htdocs\Minimarket\wp-content\uploads\2026\06\Agua-mineral.png');check(source.is_file(),'Imagen fixture ausente');
    with zipfile.ZipFile(image_zip,'w',zipfile.ZIP_DEFLATED)as z:z.write(source,image_name)
    upload(image_csv,image_zip);body=driver.find_element(By.TAG_NAME,'body').text;check('Agregar' in body and '1 por actualizar' in body,'Preview imagen incorrecta');shot('04-preview-con-imagenes');driver.find_element(By.XPATH,"//button[contains(.,'Confirmar importación')]").click();wait.until(lambda d:'Importación completada.' in d.page_source);check(not driver.find_elements(By.XPATH,"//button[contains(.,'Confirmar importación')]"),'Confirmación consumida visible');shot('05-resultado-exitoso')
    driver.set_window_size(390,844);driver.get(page);wait.until(lambda d:'Carga masiva del catálogo maestro' in d.find_element(By.TAG_NAME,'body').text);shot('06-pantalla-inicial-mobile')
    severe=[e for e in driver.get_log('browser')if e.get('level')=='SEVERE'and(e.get('source')=='javascript'or'localhost/Minimarket' in e.get('message',''))];check(not severe,'Errores JS/HTTP: '+json.dumps(severe));result['status']='PASS';print(json.dumps(result,ensure_ascii=False,indent=2))
finally:
    driver.quit();profile.cleanup();shutil.rmtree(work,ignore_errors=True);cleanup=subprocess.check_output([PHP,str(FIXTURE),'cleanup',fixture['run']],text=True).strip();print('BROWSER_CLEANUP='+cleanup);check(cleanup=='PASS','Residuos navegador')
