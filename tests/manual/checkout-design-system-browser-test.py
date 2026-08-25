"""Phase 5 checkout browser certification with isolated WordPress authentication."""

import base64, hashlib, json, os, subprocess, tempfile
from pathlib import Path
from urllib.parse import urlparse
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("VA_CHECKOUT_TEST_URL", "https://localhost/Minimarket/checkout/")
PHP = os.environ.get("VA_PHP", r"C:\xampp\php\php.exe")
WP_LOAD = str(ROOT.parents[2] / "wp-load.php").replace("\\", "/")
DRIVER = os.environ.get("VA_CHROMEDRIVER", r"C:\Users\UserMSI\.cache\selenium\chromedriver\win64\151.0.7922.77\chromedriver.exe")

def check(value, message):
    if not value: raise AssertionError(message)

def local(value):
    parsed = urlparse(value)
    return parsed.scheme in {"http", "https"} and parsed.hostname in {"localhost", "127.0.0.1", "::1"}

def php(source):
    run = subprocess.run([PHP], input=source, text=True, capture_output=True, timeout=30, check=False)
    check(run.returncode == 0, "PHP local falló: " + run.stderr[-400:])
    return run.stdout.strip()

def create_user():
    source = """<?php
require %s;
if (strtolower((string)DB_HOST)!=='localhost'||parse_url(home_url('/'),PHP_URL_HOST)!=='localhost'){fwrite(STDERR,'not-local');exit(2);}
if(get_role('customer')===null){fwrite(STDERR,'missing-role');exit(3);}
$t=bin2hex(random_bytes(12));$p=bin2hex(random_bytes(24));$l='va_phase5_'.substr($t,0,20);$e=$l.'@example.test';$d='Cliente Fase 5 '.substr($t,0,8);
$id=wp_insert_user(['user_login'=>$l,'user_pass'=>$p,'user_email'=>$e,'display_name'=>$d,'role'=>'customer']);
if(is_wp_error($id)){fwrite(STDERR,$id->get_error_code());exit(4);}
echo wp_json_encode(['id'=>(int)$id,'login'=>$l,'password'=>$p,'email'=>$e,'display'=>$d]);
?>""" % json.dumps(WP_LOAD)
    user = json.loads(php(source))
    check(user["id"] > 0 and user["password"], "Usuario temporal incompleto.")
    return user

def delete_user(user):
    source = """<?php
require %s;require_once ABSPATH.'wp-admin/includes/user.php';
$id=%d;$login=%s;$email=%s;
if($id>0){WP_Session_Tokens::get_instance($id)->destroy_all();wp_delete_user($id);clean_user_cache($id);}
global $wpdb;$meta=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%%d",$id));
$r=['id'=>get_userdata($id)===false,'login'=>get_user_by('login',$login)===false,'email'=>get_user_by('email',$email)===false,'usermeta'=>$meta,'tokens'=>count(WP_Session_Tokens::get_instance($id)->get_all())];
echo wp_json_encode($r);exit(($r['id']&&$r['login']&&$r['email']&&$meta===0&&$r['tokens']===0)?0:5);
?>""" % (json.dumps(WP_LOAD), user["id"], json.dumps(user["login"]), json.dumps(user["email"]))
    residue = json.loads(php(source))
    check(residue == {"id": True, "login": True, "email": True, "usermeta": 0, "tokens": 0}, "Residuo: " + repr(residue))

def browser(profile, width=1440, height=1000):
    options = webdriver.ChromeOptions()
    for flag in ("--headless=new","--ignore-certificate-errors","--allow-insecure-localhost","--disable-gpu","--no-sandbox"):
        options.add_argument(flag)
    options.add_argument(f"--window-size={width},{height}")
    options.add_argument("--user-data-dir=" + profile.name)
    options.set_capability("acceptInsecureCerts", True)
    options.set_capability("goog:loggingPrefs", {"browser":"ALL","performance":"ALL"})
    return webdriver.Chrome(service=Service(DRIVER), options=options)

INTERCEPT = r"""
(()=>{
 const nativeFetch=window.fetch.bind(window), root='/wp-json/veciahorra/v1';
 window.__vaCalls=[];window.__vaStatusCount=0;window.__vaLoadingResolve=null;
 const response=(p,s=200)=>Promise.resolve(new Response(JSON.stringify(p),{status:s,headers:{'Content-Type':'application/json'}}));
 const items=[
 {id:701,product_id:501,offer_group:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',product_name:'Arroz efímero',quantity:1,unit_price_snapshot:'5000.00',subtotal:'5000.00',valid:true,errors:[]},
 {id:702,product_id:502,offer_group:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',product_name:'Aceite efímero',quantity:1,unit_price_snapshot:'4500.00',subtotal:'4500.00',valid:true,errors:[]}];
 const low=[Object.assign({},items[0],{unit_price_snapshot:'7000.00',subtotal:'7000.00'})];
 const validation=(rows,total,valid=true)=>({success:true,data:{valid,items:rows,errors:valid?[]:[{code:'price_changed',message:'Precio modificado.',cart_item_id:701}],summary:{item_count:rows.length,valid_item_count:valid?rows.length:0,invalid_item_count:valid?0:rows.length,total}}});
 window.fetch=function(input,init){
  const raw=typeof input==='string'?input:input.url,url=new URL(raw,location.href),method=String((init&&init.method)||'GET').toUpperCase();
  const apiOffset=url.pathname.indexOf(root);
  const rel=apiOffset>=0?url.pathname.slice(apiOffset+root.length):null;
  const allowed=(rel==='/cart'&&method==='GET')||(rel==='/checkout/validate'&&method==='POST')||(rel==='/checkout'&&method==='POST')||(rel==='/payments/session'&&method==='POST')||(/^\/checkout\/chk_[A-Za-z0-9_-]{43}\/payment-status$/.test(rel||'')&&method==='GET');
  if(!allowed)return nativeFetch(input,init);
  let body=null;try{body=init&&init.body?JSON.parse(init.body):null;}catch(e){body='INVALID';}
  const headers={};new Headers((init&&init.headers)||{}).forEach((v,k)=>headers[k]=v);
  window.__vaCalls.push({method,path:rel,body,headers});
  const state=(new URL(location.href).hash.match(/^#__va_state=([a-z-]+)$/)||[])[1]||'results';
  if(rel==='/cart'){
   if(state==='loading')return new Promise(resolve=>{window.__vaLoadingResolve=()=>response({success:true,data:items,total:'9500.00'}).then(resolve);});
   if(state==='error')return response({success:false,error:{code:'cart_unavailable',message:'Error controlado.'}},503);
   if(state==='empty')return response({success:true,data:[],total:'0.00'});
   if(state==='low')return response({success:true,data:low,total:'7000.00'});
   return response({success:true,data:items,total:'9500.00'});
  }
  if(rel==='/checkout/validate')return response(state==='invalid'?validation(items,'0.00',false):validation(state==='low'?low:items,state==='low'?'7000.00':'9500.00'));
  if(rel==='/checkout')return response({success:true,data:{valid:true,reservation_created:true,order_created:true,expires_at:'2030-01-01 00:15:00',orders:[{id:1101,status:'reserved'},{id:1102,status:'reserved'}],summary:{total:'9500.00'},checkout:{checkout_id:'chk_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopq'}}},201);
  if(rel==='/payments/session')return response({success:true,data:{payment_session_id:'ps_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqr',checkout_id:'chk_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopq',status:'pending'}});
  window.__vaStatusCount++;
  if(state==='payment-error')return response({success:false,error:{code:'status_unavailable',message:'Estado no disponible.'}},503);
  return response({success:true,data:window.__vaStatusCount===1?{payment_status:'pending',terminal:false,message:'Pago pendiente.',next_action:null,poll_after_ms:2500}:{payment_status:'completed',terminal:true,message:'Pago confirmado.',next_action:'view_order'}});
 };
})();
"""

def open_state(driver, wait, state):
    driver.get(BASE + "#__va_state=" + state)
    wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
    roots = driver.find_elements(By.CSS_SELECTOR, "[data-va-checkout]")
    check(len(roots) == 1, "Raíz checkout ausente o duplicada.")
    root = roots[0]
    check(root.get_attribute("class").split() == ["veciahorra-frontend","va-design-system","va-checkout"], "Raíz opt-in incorrecta.")
    return root

def auth_state(driver, wait, state):
    root = open_state(driver, wait, state)
    driver.execute_script(INTERCEPT)
    driver.execute_script("arguments[0].vaCheckout.load();", root)
    return root

def visible(root, selector):
    nodes = root.find_elements(By.CSS_SELECTOR, selector)
    return bool(nodes and nodes[0].is_displayed())

def fill(root, delivery=False):
    for name, value in (("phone","+56955550101"),("email","phase5@example.test")):
        field=root.find_element(By.NAME,name);field.clear();field.send_keys(value)
    if delivery:
        root.find_element(By.CSS_SELECTOR,"input[value='delivery']").send_keys(Keys.SPACE)
        for name,value in (("recipient_name","Receptor Editado"),("address","Calle Uno 123"),("commune","Santiago")):
            field=root.find_element(By.NAME,name);field.clear();field.send_keys(value)

def asset_check(driver, logs):
    required={"design":"veciahorra-design-system.css","legacy":"veciahorra-frontend.css","base":"veciahorra-frontend.js","checkout":"veciahorra-checkout.js"}
    found={};fail=[];request_id=None
    for entry in logs:
        msg=json.loads(entry["message"])["message"];method=msg.get("method");params=msg.get("params",{})
        if method=="Network.loadingFailed" and params.get("type") in {"Document","Stylesheet","Script"} and not params.get("canceled",False): fail.append(params.get("errorText"))
        if method!="Network.responseReceived": continue
        response=params.get("response",{});url=response.get("url","");status=int(response.get("status",0))
        if params.get("type") in {"Document","Stylesheet","Script"} and status>=400: fail.append(f"{status}:{url}")
        for label,name in required.items():
            if url.split("?",1)[0].endswith("/"+name) and status==200:
                found[label]=True
                if label=="checkout":request_id=params.get("requestId")
    check(set(found)==set(required) and not fail,"Assets incompletos: "+repr((found,fail)))
    body=driver.execute_cdp_cmd("Network.getResponseBody",{"requestId":request_id})
    remote=base64.b64decode(body["body"]) if body.get("base64Encoded") else body["body"].encode()
    local=(ROOT/"assets/frontend/js/veciahorra-checkout.js").read_bytes()
    check(remote==local,"JS remoto distinto.");return hashlib.sha256(local).hexdigest()

check(local(BASE),"URL no local.")
check(Path(PHP).is_file() and Path(DRIVER).is_file(),"Herramienta local ausente; SKIP no permitido.")
compile(Path(__file__).read_text(encoding="utf-8"), __file__, "exec")
vp=tempfile.TemporaryDirectory(prefix="va-phase5-visitor-");ap=tempfile.TemporaryDirectory(prefix="va-phase5-auth-")
visitor=driver=None;user=None;deleted=False
result={"visitor":"PENDING","authenticated":"PENDING","cdp":"PENDING","states":{},"viewports":{},"console":"PENDING","network":"PENDING","cleanup":"PENDING"}
try:
    visitor=browser(vp);vw=WebDriverWait(visitor,25);visitor.execute_cdp_cmd("Network.enable",{})
    root=open_state(visitor,vw,"visitor")
    vw.until(lambda d: visible(root,"[data-va-checkout-empty]") or visible(root,"[data-va-checkout-content]"))
    check(visitor.execute_script("return window.VeciAhorra.currentUser.loggedIn") is False,"Visitante autenticado.")
    check(visitor.execute_script("return window.VeciAhorra.nonce")=="","Nonce visitante inventado.")
    check(root.find_element(By.CSS_SELECTOR,"[data-va-checkout-submit]").get_attribute("disabled") is not None,"Visitante no bloqueado.")
    perf=visitor.get_log("performance")
    check(not any('"method":"POST"' in x["message"] and any(p in x["message"] for p in ("/checkout","/payments/session")) for x in perf),"POST visitante.")
    result["visitor"]="PASS";visitor.quit();visitor=None;vp.cleanup()

    user=create_user();driver=browser(ap);wait=WebDriverWait(driver,25);driver.execute_cdp_cmd("Network.enable",{});driver.execute_cdp_cmd("Runtime.enable",{});driver.execute_cdp_cmd("Page.enable",{})
    login=BASE.split("/checkout/",1)[0]+"/wp-login.php";check(local(login),"Login no local.")
    driver.get(login);driver.find_element(By.ID,"user_login").send_keys(user["login"]);driver.find_element(By.ID,"user_pass").send_keys(user["password"]);driver.find_element(By.ID,"wp-submit").click()
    wait.until(lambda d:any(c["name"].startswith("wordpress_logged_in_") for c in d.get_cookies()))
    intercept_compiled=driver.execute_cdp_cmd("Runtime.compileScript",{"expression":INTERCEPT,"sourceURL":"checkout-phase5-intercept.js","persistScript":False});check("exceptionDetails" not in intercept_compiled,"Interceptor CDP inválido: "+repr(intercept_compiled.get("exceptionDetails")))
    source=(ROOT/"assets/frontend/js/veciahorra-checkout.js").read_text(encoding="utf-8")
    compiled=driver.execute_cdp_cmd("Runtime.compileScript",{"expression":source,"sourceURL":"assets/frontend/js/veciahorra-checkout.js","persistScript":False})
    check("exceptionDetails" not in compiled,"CDP SyntaxError.");result["cdp"]="PASS"

    root=auth_state(driver,wait,"loading");check(visible(root,"[data-va-checkout-loading]"),"Loading ausente.");wait.until(lambda d:d.execute_script("return typeof window.__vaLoadingResolve==='function'"));driver.execute_script("window.__vaLoadingResolve()")
    wait.until(lambda d:visible(root,"[data-va-checkout-content]"));result["states"]["loading"]="PASS"
    check("Mercado Uno" not in root.text and "Mercado Dos" not in root.text and [node.text for node in root.find_elements(By.CSS_SELECTOR,".va-checkout-group h3")]==["Opción A","Opción B"],"Store identity visible in checkout.")
    result["sha256"]=asset_check(driver,driver.get_log("performance"))
    check(driver.execute_script("return window.VeciAhorra.currentUser.loggedIn") is True,"Identidad falsa.")
    check(driver.execute_script("return window.VeciAhorra.currentUser.id")==user["id"],"ID incorrecto.")
    nonce=driver.execute_script("return window.VeciAhorra.nonce");check(isinstance(nonce,str) and len(nonce)>=10,"Nonce ausente.")
    check(root.find_element(By.CSS_SELECTOR,"[data-va-checkout-buyer-name]").text==user["display"],"buyerName incorrecto.")
    root.find_element(By.CSS_SELECTOR,"input[value='delivery']").send_keys(Keys.SPACE)
    recipient=root.find_element(By.NAME,"recipient_name");check(recipient.get_attribute("value")==user["display"],"Receptor inicial.");recipient.clear();recipient.send_keys("Editable");check(recipient.get_attribute("value")=="Editable","Receptor no editable.")
    check(driver.execute_script("return window.VeciAhorra.cart.sessionId")=="","Session invitada presente.");result["authenticated"]="PASS";result["states"]["results"]="PASS"

    root=auth_state(driver,wait,"empty");wait.until(lambda d:visible(root,"[data-va-checkout-empty]"));result["states"]["empty"]="PASS"
    root=auth_state(driver,wait,"error");wait.until(lambda d:visible(root,"[data-va-checkout-error]"));driver.execute_script("history.replaceState(null,'',location.pathname+'#__va_state=results')");root.find_element(By.CSS_SELECTOR,"[data-va-checkout-retry]").click();wait.until(lambda d:visible(root,"[data-va-checkout-content]"));result["states"]["error_retry"]="PASS"
    root=auth_state(driver,wait,"low");wait.until(lambda d:visible(root,"[data-va-checkout-content]"));check(visible(root,"[data-va-delivery-minimum]") and not root.find_elements(By.CSS_SELECTOR,"input[value='delivery']"),"Mínimo delivery.");result["states"]["delivery_low"]="PASS"
    root=auth_state(driver,wait,"invalid");wait.until(lambda d:visible(root,"[data-va-checkout-content]"));fill(root);root.find_element(By.CSS_SELECTOR,"[data-va-checkout-submit]").send_keys(Keys.ENTER);wait.until(lambda d:visible(root,"[data-va-checkout-validation-errors]"));result["states"]["validation_invalid"]="PASS"

    root=auth_state(driver,wait,"results");wait.until(lambda d:visible(root,"[data-va-checkout-content]"));fill(root,True)
    submit=root.find_element(By.CSS_SELECTOR,"[data-va-checkout-submit]");submit.send_keys(Keys.ENTER)
    driver.execute_script("arguments[0].form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));",submit)
    wait.until(lambda d:visible(root,"[data-va-payment-status-panel]"))
    check("Pedido 1" in root.find_element(By.CSS_SELECTOR,"[data-va-checkout-result-details]").get_attribute("textContent"),"Resultado de pedido no generado.")
    observed=driver.execute_script("return window.__vaCalls")
    check(sum(c["method"]=="POST" and c["path"]=="/checkout" for c in observed)==1,"Doble checkout.")
    check(any(c["path"]=="/checkout/validate" for c in observed) and any(c["path"]=="/payments/session" for c in observed),"Secuencia incompleta.")
    for call in observed:
        check(call["headers"].get("x-wp-nonce")==nonce,"Nonce no propagado.")
        check("x-veciahorra-cart-session" not in call["headers"],"Header invitado autenticado.")
    wait.until(lambda d:d.execute_script("return window.__vaStatusCount")>=2)
    check("va-button--secondary" in root.find_element(By.CSS_SELECTOR,"[data-va-payment-status-action]").get_attribute("class"),"Retorno no secundario.")
    result["states"].update({"pickup":"PASS","delivery_valid":"PASS","create":"PASS","double_submit":"PASS","payment_session":"PASS","payment_pending_terminal":"PASS"})

    root=auth_state(driver,wait,"payment-error");wait.until(lambda d:visible(root,"[data-va-checkout-content]"));fill(root,True);root.find_element(By.CSS_SELECTOR,"[data-va-checkout-submit]").send_keys(Keys.ENTER)
    wait.until(lambda d:visible(root,"[data-va-payment-status-panel]") and visible(root,"[data-va-payment-status-refresh]"))
    check(driver.execute_script("return window.__vaStatusCount")>=1,"Payment status error no ejercido.");result["states"]["payment_error"]="PASS"
    for width,height in ((1440,1000),(375,812),(320,800)):
        driver.set_window_size(width,height);root=auth_state(driver,wait,"results");wait.until(lambda d:visible(root,"[data-va-checkout-content]"))
        check(driver.execute_script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"),"Overflow.")
        for selector in ("[data-va-checkout-retry]","[data-va-payment-status-refresh]","[data-va-checkout-submit]"):
            node=root.find_element(By.CSS_SELECTOR,selector)
            metrics=driver.execute_script("const r=arguments[0].getBoundingClientRect(),s=getComputedStyle(arguments[0]);return {w:r.width,h:r.height,minH:parseFloat(s.minHeight)||0};",node);metrics["shown"]=node.is_displayed()
            check(metrics["minH"]>=44 and (not metrics["shown"] or (metrics["w"]>=44 and metrics["h"]>=44)),"Target <44: "+selector+repr(metrics))
        result["viewports"][f"{width}x{height}"]="PASS"
    driver.find_element(By.TAG_NAME,"body").send_keys(Keys.TAB)
    check(driver.execute_script("return document.activeElement && document.activeElement !== document.body"),"Tab no avanzó el foco.")
    severe=[x for x in driver.get_log("browser") if x.get("level") in {"SEVERE","ERROR"} and any(k in x.get("message","") for k in ("SyntaxError","ReferenceError","TypeError"))]
    check(not severe,"Consola: "+repr(severe));result["console"]="PASS";result["network"]="PASS"

    driver.get(login+"?action=logout")
    links=driver.find_elements(By.CSS_SELECTOR,"a[href*='action=logout'][href*='_wpnonce=']");check(links,"Confirmación de logout real ausente.")
    logout=links[0].get_attribute("href");check(local(logout),"Logout externo.");driver.get(logout)
    wait.until(lambda d:not any(c["name"].startswith("wordpress_logged_in_") for c in d.get_cookies()))
    driver.quit();driver=None;delete_user(user);deleted=True;result["cleanup"]="PASS"
finally:
    if visitor:
        try: visitor.quit()
        except Exception: pass
    if driver:
        try: driver.quit()
        except Exception: pass
    if user and not deleted: delete_user(user)
    vp.cleanup();ap.cleanup()

check(result["cleanup"]=="PASS","Cleanup no certificado.")
print(json.dumps({"status":"PASS",**result},ensure_ascii=False,sort_keys=True))
