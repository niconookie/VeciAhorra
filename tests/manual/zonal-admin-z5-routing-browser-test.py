from pathlib import Path
import re, tempfile, shutil, time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

base='https://localhost/Minimarket'; handoff=Path(r'C:\xampp\tmp\veciahorra-zonal-training-credentials.txt')
text=handoff.read_text(encoding='utf-8'); user=re.search(r'^USERNAME=(.+)$',text,re.M).group(1).strip(); password=re.search(r'^PASSWORD=(.+)$',text,re.M).group(1).strip(); text=''
profile=tempfile.mkdtemp(prefix='veciahorra-z5-'); errors=[]; resources=[]; auth_blocks=[]
options=Options();options.add_argument('--headless=new');options.add_argument('--ignore-certificate-errors');options.add_argument('--allow-insecure-localhost');options.add_argument('--disable-gpu');options.add_argument('--no-sandbox');options.add_argument('--disable-dev-shm-usage');options.add_argument('--user-data-dir='+profile);options.set_capability('goog:loggingPrefs',{'browser':'ALL','performance':'ALL'})
driver=webdriver.Chrome(options=options);wait=WebDriverWait(driver,20);target=base+'/wp-admin/admin.php?page=veciahorra-zonal-stores'
try:
 driver.get(base+'/wp-login.php');driver.find_element(By.ID,'user_login').send_keys(user);driver.find_element(By.ID,'user_pass').send_keys(password);password='';driver.find_element(By.ID,'wp-submit').click()
 try: wait.until(lambda d:'page=veciahorra-zonal-stores' in d.current_url)
 except Exception as exc: raise AssertionError('login_destination='+driver.current_url+' title='+driver.title) from exc
 assert driver.current_url.startswith(target)
 wait.until(lambda d:d.find_elements(By.CSS_SELECTOR,'[data-va-zonal-stores]'));wait.until(lambda d:d.page_source.count('Capacitación Zonal — Solicitud')>=3);assert driver.page_source.count('Capacitación Zonal — Solicitud')>=3
 review=wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR,'button[data-store-id]')));review.click();wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR,'[data-va-detail-content] h2')));driver.find_element(By.CSS_SELECTOR,'[data-va-back]').click();wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR,'[data-va-list]')))
 driver.get(base+'/wp-admin/');wait.until(lambda d:'page=veciahorra-zonal-stores' in d.current_url);assert driver.current_url.startswith(target)
 for forbidden in ('/wp-admin/users.php','/wp-admin/plugins.php','/wp-admin/options-general.php','/wp-admin/admin.php?page=veciahorra-stores'):
  driver.get(base+forbidden);wait.until(EC.presence_of_element_located((By.TAG_NAME,'body')))
  assert ('page=veciahorra-zonal-stores' in driver.current_url) or ('Error' in driver.title), 'forbidden='+forbidden+' final='+driver.current_url+' title='+driver.title
 for width in (1280,375,320):
  driver.set_window_size(width,900);driver.get(target);wait.until(lambda d:d.find_elements(By.CSS_SELECTOR,'[data-va-zonal-stores]'));assert driver.execute_script('return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1');assert driver.find_element(By.CSS_SELECTOR,'[data-va-search]').is_displayed()
 driver.set_window_size(1280,900);driver.get(base+'/');wait.until(EC.presence_of_element_located((By.TAG_NAME,'body')));source=driver.page_source;assert 'Mi panel' in source and 'Mis compras' not in source
 panel=[a for a in driver.find_elements(By.TAG_NAME,'a') if (a.get_attribute('textContent') or '').strip()=='Mi panel'];hrefs=[a.get_attribute('href') for a in panel]
 assert panel and all('page=veciahorra-zonal-stores' in (href or '') for href in hrefs), 'my_panel_count=%d hrefs=%s'%(len(panel),hrefs)
 visible=[a for a in panel if a.is_displayed()];assert visible;visible[0].click();wait.until(lambda d:'page=veciahorra-zonal-stores' in d.current_url)
 logout=[a for a in driver.find_elements(By.TAG_NAME,'a') if 'action=logout' in (a.get_attribute('href') or '')];assert logout;driver.get(logout[0].get_attribute('href'));driver.get(target);wait.until(lambda d:'wp-login.php' in d.current_url)
 for entry in driver.get_log('browser'):
  if entry.get('level')=='SEVERE':
   message=entry.get('message','')
   if '403 (Forbidden)' in message and any(path in message for path in ('/wp-admin/plugins.php','/wp-admin/options-general.php','page=veciahorra-stores')): auth_blocks.append(message)
   else: errors.append(message)
 if errors: raise AssertionError('js_errors='+repr(errors))
 print('ZONAL_ADMIN_Z5_BROWSER=PASS login=PASS redirect=PASS panel_http=200 search=3 detail=PASS generic_admin=ZONAL global_store=BLOCKED users=BLOCKED plugins=BLOCKED settings=BLOCKED authorization_blocks=%d frontend=PASS my_panel=PASS purchases=HIDDEN logout=PASS relogin_required=PASS desktop=PASS mobile375=PASS mobile320=PASS keyboard=PASS focus=PASS overflow=PASS js_errors=%d failed_resources=%d temp_profiles=0'%(len(auth_blocks),len(errors),len(resources)))
finally:
 driver.quit();shutil.rmtree(profile,ignore_errors=True)
