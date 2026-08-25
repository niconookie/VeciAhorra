import json,subprocess,tempfile,time
from pathlib import Path
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import Select,WebDriverWait
ROOT=Path(__file__).resolve().parents[2];PHP=r'C:\xampp\php\php.exe';FIX=ROOT/'tests/manual/zonal-sales-dashboard-browser-fixture.php';STATE=ROOT/'tests/manual/zonal-sales-dashboard-browser-state.php';BASE='https://localhost/Minimarket';DRIVER=r'C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe';OUT=ROOT/'artifacts/zonal-sales-dashboard';OUT.mkdir(parents=True,exist_ok=True)
fixture=json.loads(subprocess.check_output([PHP,str(FIX),'setup'],text=True));results={}
def launch(w,h):
 p=tempfile.TemporaryDirectory(prefix='va-zonal-sales-');o=webdriver.ChromeOptions()
 for a in ['--headless=new','--ignore-certificate-errors','--allow-insecure-localhost',f'--window-size={w},{h}','--disable-gpu','--no-sandbox','--user-data-dir='+p.name]:o.add_argument(a)
 o.set_capability('acceptInsecureCerts',True);o.set_capability('goog:loggingPrefs',{'browser':'ALL'});d=webdriver.Chrome(service=Service(DRIVER),options=o);d.get(BASE+'/wp-login.php')
 for c in fixture['cookies']:d.add_cookie({'name':c['name'],'value':c['value'],'path':c['path'],'secure':True})
 return d,p
def opened(d):
 d.get(BASE+'/wp-admin/admin.php?page=veciahorra-zonal-stores');w=WebDriverWait(d,30);w.until(lambda x:len(x.find_elements(By.CSS_SELECTOR,'.va-zonal-sales__card'))==4)
 try:w.until(lambda x:x.find_elements(By.CSS_SELECTOR,'.va-zonal-sales__table') or any(t in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text for t in ['No hay ventas','No fue posible']))
 except Exception:
  print('BROWSER_DEBUG='+json.dumps({'content':d.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text,'scripts':[x.get_attribute('src') for x in d.find_elements(By.TAG_NAME,'script') if 'zonal' in (x.get_attribute('src') or '')],'config':d.execute_script('return window.VeciAhorraZonalStores||null'),'console':d.get_log('browser')},ensure_ascii=False));raise
 return w
def values(d):return [x.text for x in d.find_elements(By.CSS_SELECTOR,'.va-zonal-sales__card strong')]
try:
 d,p=launch(1440,1100)
 try:
  w=opened(d);w.until(lambda x:'Minimarket Plaza' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text);d.save_screenshot(str(OUT/'02-periodo-con-ventas.png'));nonce=d.execute_script('return window.VeciAhorraZonalStores.nonce')
  for period in ['7','30']:
   Select(d.find_element(By.CSS_SELECTOR,'[data-va-sales-period]')).select_by_value(period);d.find_element(By.CSS_SELECTOR,'[data-va-sales-filters] button').click();w.until(lambda x:'Minimarket Plaza' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text)
  Select(d.find_element(By.CSS_SELECTOR,'[data-va-sales-period]')).select_by_value('custom');today=time.strftime('%Y-%m-%d')
  for s in ['[data-va-sales-from]','[data-va-sales-to]']:d.execute_script('arguments[0].value=arguments[1]',d.find_element(By.CSS_SELECTOR,s),today)
  before=d.find_element(By.CSS_SELECTOR,'[data-va-sales-period-label]').text;d.find_element(By.CSS_SELECTOR,'[data-va-sales-filters] button').click();w.until(lambda x:x.find_element(By.CSS_SELECTOR,'[data-va-sales-period-label]').text!=before);d.refresh();w=opened(d)
  d.execute_script("window.VeciAhorraZonalStores.nonce='invalid'");d.find_element(By.CSS_SELECTOR,'[data-va-sales-filters] button').click();w.until(lambda x:'No fue posible' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text);assert d.find_elements(By.CSS_SELECTOR,'[data-va-sales-retry]');d.execute_script('window.VeciAhorraZonalStores.nonce=arguments[0]',nonce);d.find_element(By.CSS_SELECTOR,'[data-va-sales-retry]').click();w.until(lambda x:'Minimarket Plaza' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text)
  d.execute_script('window.__f=window.fetch;window.fetch=function(){return Promise.resolve({ok:true,json:function(){return Promise.reject(new SyntaxError())}})}');d.find_element(By.CSS_SELECTOR,'[data-va-sales-filters] button').click();w.until(lambda x:'No fue posible' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text)
  d.execute_script('window.fetch=function(){return Promise.resolve({ok:false,status:503,json:function(){return Promise.resolve({})}})}');d.find_element(By.CSS_SELECTOR,'[data-va-sales-retry]').click();w.until(lambda x:'No fue posible' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text);d.execute_script('window.fetch=window.__f');d.find_element(By.CSS_SELECTOR,'[data-va-sales-retry]').click();w.until(lambda x:'Minimarket Plaza' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text)
  assert not d.execute_script('return document.documentElement.scrollWidth>document.documentElement.clientWidth');results['sales']=values(d)
 finally:d.quit();p.cleanup()
 d,p=launch(375,900)
 try:w=opened(d);w.until(lambda x:'Minimarket Plaza' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text);d.save_screenshot(str(OUT/'03-vista-movil.png'));assert not d.execute_script('return document.documentElement.scrollWidth>document.documentElement.clientWidth');results['mobile']=values(d)
 finally:d.quit();p.cleanup()
 for state,expected,shot in [('active',['1','0','$0','$0'],None),('empty',['0','0','$0','$0'],'01-periodo-sin-ventas.png')]:
  assert subprocess.check_output([PHP,str(STATE),fixture['run'],state],text=True).strip()=='PASS';d,p=launch(1440,1000)
  try:w=opened(d);w.until(lambda x:'No hay ventas para el periodo seleccionado.' in x.find_element(By.CSS_SELECTOR,'[data-va-sales-content]').text);assert values(d)==expected,(state,values(d));results[state]=values(d);d.save_screenshot(str(OUT/shot)) if shot else None
  finally:d.quit();p.cleanup()
 print(json.dumps({'status':'PASS','results':results,'failures':['nonce','invalid_json','http']},ensure_ascii=False,indent=2))
finally:print('BROWSER_CLEANUP='+subprocess.check_output([PHP,str(FIX),'cleanup',fixture['run']],text=True).strip())
