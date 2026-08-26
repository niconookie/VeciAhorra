import json, tempfile, time
from pathlib import Path
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

ROOT = Path(__file__).resolve().parents[2]
BASE = 'https://localhost/Minimarket'
DRIVER = r'C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe'
OUT = ROOT / 'artifacts/prelaunch'
OUT.mkdir(parents=True, exist_ok=True)
profile = tempfile.TemporaryDirectory(prefix='va-prelaunch-')
options = webdriver.ChromeOptions()
for argument in ['--headless=new', '--ignore-certificate-errors', '--allow-insecure-localhost', '--window-size=1440,1100', '--disable-gpu', '--no-sandbox', '--user-data-dir=' + profile.name]:
    options.add_argument(argument)
options.set_capability('acceptInsecureCerts', True)
options.set_capability('goog:loggingPrefs', {'browser': 'ALL', 'performance': 'ALL'})
driver = webdriver.Chrome(service=Service(DRIVER), options=options)
wait = WebDriverWait(driver, 30)
captures = {}

def visit(path, filename, expected, open_menu=False):
    driver.get(BASE + path)
    if open_menu:
        toggle = wait.until(lambda current: current.find_element(By.CSS_SELECTOR, '[data-va-global-header] .va-global-header__menu-toggle'))
        driver.execute_script('arguments[0].click()', toggle)
    wait.until(lambda current: expected in current.find_element(By.TAG_NAME, 'body').text)
    assert driver.execute_script('return document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1')
    target = OUT / filename
    driver.save_screenshot(str(target))
    captures[filename] = str(target)

try:
    visit('/', '01-inicio-desktop.png', 'Disponible desde el 1 de septiembre')
    visit('/registro-cliente/', '02-registro-bloqueado.png', 'El registro estará disponible desde el 1 de septiembre.')
    visit('/catalogo-veciahorra/', '03-catalogo-compra-bloqueada.png', 'Disponible desde el 1 de septiembre')
    driver.set_window_size(390, 844)
    visit('/', '04-inicio-mobile.png', 'Registros disponibles desde el 1 de septiembre', True)
    severe = [entry for entry in driver.get_log('browser') if entry.get('level') == 'SEVERE' and 'ERR_NETWORK_ACCESS_DENIED' not in entry.get('message', '')]
    assert not severe, severe
    print(json.dumps({'status': 'PASS', 'captures': captures, 'js_errors': 0, 'overflow': 0}, ensure_ascii=False, indent=2))
finally:
    driver.quit()
    profile.cleanup()
