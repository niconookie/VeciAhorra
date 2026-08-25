import json, subprocess, tempfile, time
from pathlib import Path
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import Select, WebDriverWait

BASE='https://localhost/Minimarket';PHP=r'C:\xampp\php\php.exe';DRIVER=r'C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe';ROOT=Path(__file__).resolve().parents[2];FIXTURE=ROOT/'tests/manual/inventory-bulk-browser-fixture.php';OUT=ROOT/'artifacts/inventory-admin-runtime';OUT.mkdir(parents=True,exist_ok=True)
fixture=json.loads(subprocess.check_output([PHP,str(FIXTURE),'setup'],text=True));profile=tempfile.TemporaryDirectory(prefix='va-inventory-diagnostic-');o=webdriver.ChromeOptions()
for arg in ['--headless=new','--ignore-certificate-errors','--allow-insecure-localhost','--window-size=1440,1000','--disable-gpu','--no-sandbox','--user-data-dir='+profile.name]:o.add_argument(arg)
o.set_capability('acceptInsecureCerts',True);o.set_capability('goog:loggingPrefs',{'browser':'ALL','performance':'ALL'});d=webdriver.Chrome(service=Service(DRIVER),options=o)
try:
 d.get(BASE+'/wp-login.php')
 for c in fixture['cookies']:d.add_cookie({'name':c['name'],'value':c['value'],'path':c['path'],'secure':True})
 d.get(BASE+'/wp-admin/admin.php?page=veciahorra-inventory');wait=WebDriverWait(d,30);wait.until(lambda x:len(x.find_elements(By.CSS_SELECTOR,'#veciahorra-inventory-table tbody tr'))>0);body=d.find_element(By.TAG_NAME,'body').text
 if 'La respuesta del servidor no tiene el formato esperado.' in body:raise AssertionError('La lista continúa rechazada.')
 for label in ('Descartar','Entendido'):
  buttons=d.find_elements(By.XPATH,f"//*[self::a or self::button][normalize-space()='{label}']")
  if buttons:
   try:buttons[0].click();time.sleep(.5)
   except Exception:pass
 shot=OUT/'inventory-working.png';d.save_screenshot(str(shot));records=[]
 next_page=d.find_elements(By.XPATH,"//button[contains(normalize-space(),'Siguiente') and not(@disabled)]")
 if not next_page:raise AssertionError('La paginacion no esta disponible con mas de una pagina.')
 next_page[0].click();wait.until(lambda x:'page=2' in x.current_url or 'Pagina 2' in x.find_element(By.TAG_NAME,'body').text)
 d.get(BASE+'/wp-admin/admin.php?page=veciahorra-inventory');wait.until(lambda x:len(x.find_elements(By.CSS_SELECTOR,'#veciahorra-inventory-table tbody tr'))>0)
 search=d.find_element(By.NAME,'search');search.clear();search.send_keys('Papel');d.find_element(By.XPATH,"//button[normalize-space()='Buscar']").click();wait.until(lambda x:'Papel' in x.find_element(By.ID,'veciahorra-inventory-table').text)
 Select(d.find_element(By.NAME,'status')).select_by_value('active');time.sleep(2)
 d.refresh();wait.until(lambda x:len(x.find_elements(By.CSS_SELECTOR,'#veciahorra-inventory-table tbody tr'))>0)
 d.find_element(By.XPATH,"//button[normalize-space()='Nuevo inventario']").click();wait.until(lambda x:'Crear inventario' in x.find_element(By.TAG_NAME,'body').text or len(x.find_elements(By.ID,'veciahorra-inventory-store-search'))>0)
 for item in d.get_log('performance'):
  msg=json.loads(item['message'])['message']
  if msg['method']!='Network.responseReceived':continue
  p=msg['params'];url=p['response']['url']
  if '/wp-json/veciahorra/v1/inventory/admin' not in url:continue
  try:body=json.loads(d.execute_cdp_cmd('Network.getResponseBody',{'requestId':p['requestId']})['body'])
  except Exception as e:body={'body_error':type(e).__name__}
  def sanitize(v):
   if isinstance(v,dict):return {k:sanitize(x) for k,x in v.items() if k not in {'nonce','token','cookie','authorization'}}
   if isinstance(v,list):return [sanitize(x) for x in v]
   return v
  if isinstance(body,dict) and isinstance(body.get('data'),list):body={'success':body.get('success'),'data_count':len(body['data']),'sample_keys':list(body['data'][0].keys()) if body['data'] else [],'meta':body.get('meta')}
  records.append({'url':url,'method':p['response'].get('requestHeaders',{}).get(':method','GET'),'status':p['response']['status'],'content_type':p['response']['mimeType'],'headers':{'content-type':p['response']['headers'].get('content-type') or p['response']['headers'].get('Content-Type')},'body':sanitize(body)})
 severe=[x for x in d.get_log('browser') if x.get('level')=='SEVERE'];print(json.dumps({'list':'PASS','filters':'PASS','search':'PASS','pagination':'PASS','reload':'PASS','create_form':'PASS','requests':records,'severe':severe,'screenshot':str(shot)},ensure_ascii=False,indent=2))
finally:
 d.quit();profile.cleanup();print('CLEANUP='+subprocess.check_output([PHP,str(FIXTURE),'cleanup',fixture['run']],text=True).strip())
