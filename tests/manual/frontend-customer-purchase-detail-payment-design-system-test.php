<?php
declare(strict_types=1);

const VA_PHASE11_BASELINE = 'c6c34928b896d7d780ce886db6316a4f2d3f5563';
const VA_PHASE11_PATHS = [
 'assets/frontend/js/customer-panel.js',
 'tests/manual/frontend-customer-purchase-detail-payment-design-system-test.php',
 'tests/manual/customer-purchase-detail-payment-design-system-browser-test.py',
];
function p11assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function p11method(string $source,string $name):string{$start=strpos($source,"    function {$name}(");p11assert($start!==false,"Funcion {$name} ausente.");$next=strpos($source,"\n    function ",$start+1);return substr($source,$start,$next===false?null:$next-$start);}
function p11git(array $args,bool $lines=true):array|string{
 $pipes=[];$p=proc_open(['git','-C',dirname(__DIR__,2),...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);p11assert(is_resource($p),'Git ausente.');
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);p11assert(proc_close($p)===0,'Git: '.trim((string)$err));
 $text=rtrim(str_replace(["\r\n","\r"],"\n",(string)$out),"\n");return $lines?array_values(array_filter(explode("\n",$text))):$text;
}
/** @return list<string> */
function p11validate(array $s,bool $worktree=false):array{
 $e=[];$need=static function(bool $ok,string $code)use(&$e):void{if(!$ok)$e[]=$code;};
 $js=$s['js'];$view=$s['view'];$css=$s['css'];$assets=$s['assets'];$service=$s['service'];$routes=$s['routes'];$query=$s['query'];$dto=$s['dto'];$browser=$s['browser'];
 $render=p11method($js,'renderDetail');$valid=p11method($js,'validPayment');$total=p11method($js,'formatTotal');$date=p11method($js,'formatDate');
 $paymentStart=strpos($render,"paymentSection.append(visualHeading('h3', 'Pago', 'payment'))");$paymentEnd=strpos($render,'var deliveryStatus',$paymentStart?:0);
 $paymentBlock=$paymentStart!==false&&$paymentEnd!==false?substr($render,$paymentStart,$paymentEnd-$paymentStart):'';
 $root='veciahorra-frontend va-design-system va-customer-panel__detail-section va-customer-panel__detail-payment';$attr='data-va-customer-panel-detail-payment';
 $need(substr_count($render,"'{$root}'")===1&&substr_count($render,"paymentSection.setAttribute('{$attr}', '');")===1,'P01_ROOT_MISSING');
 $need(substr_count($js,$attr)===1,'P02_ROOT_DUPLICATED');
 $need(!str_contains($view,$attr),'P03_ROOT_GLOBAL');
 $need(str_contains($render,"var paymentSection = element(\n            'section',")&&!preg_match('/(?:paymentValues|services|deliverySection)\.setAttribute\(\''.$attr.'/',$render),'P04_WRONG_NODE');
 $need(!preg_match('/services[^;]*'.$attr.'/',$render),'P05_ROOT_ON_SERVICES');
 $need(!preg_match('/deliverySection[^;]*'.$attr.'/',$js),'P06_DELIVERY_INVADED');
 $need(!preg_match('/overview[^;]*'.$attr.'/',$render),'P07_OVERVIEW_INVADED');
 $need(!preg_match('/ordersSection[^;]*'.$attr.'/',$render),'P08_ORDERS_INVADED');
 $need(!str_contains(p11method($js,'renderDetailItem'),$attr),'P09_PRODUCTS_INVADED');
 $need(!str_contains(p11method($js,'renderTimeline'),$attr),'P10_TIMELINE_INVADED');
 $need(!str_contains(p11method($js,'renderList'),$attr),'P11_LIST_INVADED');
 $need(!str_contains(p11method($js,'renderDetailLoading'),$attr),'P12_LOADING_INVADED');
 $need(!str_contains(p11method($js,'renderDetailNotFound'),$attr),'P13_NOT_FOUND_INVADED');
 $need(!str_contains(p11method($js,'renderDetailRecoverableError'),$attr),'P14_ERROR_INVADED');
 $need(!preg_match('/overview\.append[^;]*paymentSection/',$render),'P15_NESTED_PHASE8');
 $need(!str_contains(p11method($js,'renderDetailItem'),$attr),'P16_NESTED_PHASE9');
 $need(!str_contains(p11method($js,'renderDetailOrder'),$attr),'P17_NESTED_PHASE10');
 $need(str_contains($render,'veciahorra-frontend va-design-system va-customer-panel__detail-overview va-customer-panel__detail-primary-card')&&str_contains($render,'data-va-customer-panel-detail-overview'),'P18_PHASE8_CHANGED');
 $need(str_contains(p11method($js,'renderDetailItem'),'data-va-customer-panel-detail-item'),'P19_PHASE9_CHANGED');
 $need(str_contains(p11method($js,'renderDetailOrder'),'data-va-customer-panel-detail-order-header'),'P20_PHASE10_CHANGED');
 $need(substr_count($render,'paymentSection.setAttribute')===1&&str_contains($render,'services.append(paymentSection, deliverySection)'),'P21_CARDINALITY_CHANGED');
 $need(str_contains($render,'if (detail.payment === null)')&&str_contains($render,'Información de pago no disponible.'),'P22_NULL_PAYMENT_LOST');
 $need(str_contains($service,"'status' => \$payment['status'] === 'paid' ? 'received' : 'pending'")&&!preg_match('/paymentSection[^;]*(?:failed|rejected)/i',$render),'P23_PAYMENT_STATE_INVENTED');
 $need(str_contains($service,"'label' => \$payment['status'] === 'paid' ? 'Pago recibido' : 'Pago pendiente'"),'P24_PAYMENT_LABEL_CHANGED');
 $statusRow=strpos($paymentBlock,'detailStatusValue(');$amountRow=strpos($paymentBlock,"detailValue('Monto'");$currencyRow=strpos($paymentBlock,"detailValue('Moneda'");$dateRow=strpos($paymentBlock,"detailValue('Fecha de pago'");$methodRow=strpos($paymentBlock,"detailValue('Método'");
 $need($statusRow!==false&&$amountRow!==false&&$currencyRow!==false&&$dateRow!==false&&$methodRow!==false&&$statusRow<$amountRow&&$amountRow<$currencyRow&&$currencyRow<$dateRow&&$dateRow<$methodRow,'P25_ROW_OR_ORDER_CHANGED');
 $need(str_contains($valid,'isNullableString(payment.paid_at)')&&str_contains($valid,'isNullableString(payment.method)'),'P26_OPTIONAL_FIELD_REQUIRED');
 $need(str_contains($total,"currency === 'CLP'")&&str_contains($total,"amount + ' ' + currency"),'P27_MONEY_FORMAT_CHANGED');
 $need(str_contains($date,"timeZone = String(config.timeZone || 'UTC')")&&str_contains($date,"dateStyle: 'medium'")&&str_contains($date,"timeStyle: 'short'"),'P28_DATE_TIMEZONE_CHANGED');
 $need(str_contains($service,"\$payment['provider'] === 'webpay_plus' ? 'Webpay Plus' : null"),'P29_INTERNAL_METHOD_EXPOSED');
 $need(!preg_match('/(?:paymentSection|paymentValues)[\s\S]{0,300}(?:button|href|api\.(?:post|put|patch|delete))/i',$render),'P30_PAYMENT_ACTION_ADDED');
 $need(!preg_match('/setInterval|setTimeout[^;]*payment|poll/i',$render),'P31_POLLING_ADDED');
 $need(str_contains($js,"var ENDPOINT = 'customer-panel/purchases';")&&!preg_match('/\b(?:post|put|patch|delete)\s*\(/i',$js),'P32_ENDPOINT_OR_METHOD_CHANGED');
 $need(str_contains($js,'var PUBLIC_ID_PATTERN = /^chk_[A-Za-z0-9_-]{43}$/;')&&str_contains($js,"url.searchParams.set('compra', publicId)"),'P33_PUBLIC_ID_LOST');
 $need(str_contains($routes,'get_current_user_id()')&&str_contains($service,'findOwnedCheckout($publicId, $userId)')&&str_contains($query,"c.owner_type = %%s")&&str_contains($query,"c.user_id = %%d"),'P34_OWNERSHIP_LOST');
 $need(substr_count($service,"(int) (\$payment['customer_id'] ?? 0) !== \$userId")>=2,'P35_FOREIGN_CUSTOMER_EXPOSED');
 $need(!preg_match('/get_(?:query|body|json)_params[^;]*(?:user_id|customer_id)/',$routes),'P36_IDENTITY_OVERRIDE');
 $need(str_contains($dto,"'payment' => \$this->payment")&&!preg_match('/[\'\"](?:payment_id|payment_session_id|attempt_id|reconciliation_id|customer_id|user_id)[\'\"]\s*[:=]/',$js),'P37_DTO_OR_INTERNAL_ID_CHANGED');
 $need(str_contains($render,"visualHeading('h3', 'Pago', 'payment')")&&str_contains(p11method($js,'decorativeIcon'),"aria-hidden")&&str_contains($browser,'44'),'P38_ACCESSIBILITY_LOST');
 $need($css===$s['baseCss']&&$assets===$s['baseAssets'],'P39_ASSET_OR_CSS_CHANGED');
 $need(preg_match("/public const SCHEMA_VERSION = '[^']+'/",$s['schema'])===1,'P40_ALLOWLIST_OR_BASELINE_DRIFT');
 if($worktree){$changed=array_values(array_unique([...p11git(['diff','--name-only',VA_PHASE11_BASELINE]),...p11git(['diff','--cached','--name-only']),...array_filter(p11git(['ls-files','--others','--exclude-standard']),static fn(string $p):bool=>in_array($p,VA_PHASE11_PATHS,true))]));sort($changed);$allowed=VA_PHASE11_PATHS;sort($allowed);$need($changed===[]||$changed===$allowed,'P40_ALLOWLIST_OR_BASELINE_DRIFT');}
 return $e;
}
$root=dirname(__DIR__,2);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$s=['js'=>$read('assets/frontend/js/customer-panel.js'),'view'=>$read('app/Modules/Frontend/Views/customer-panel.php'),'css'=>$read('assets/frontend/css/customer-panel.css'),'assets'=>$read('app/Modules/Frontend/Assets/FrontendAssets.php'),'service'=>$read('app/Modules/CustomerPanel/Service/CustomerPanelService.php'),'routes'=>$read('app/Modules/CustomerPanel/Routes/CustomerPanelRoutes.php'),'query'=>$read('app/Modules/CustomerPanel/Query/CustomerPurchaseQuery.php'),'dto'=>$read('app/Modules/CustomerPanel/DTO/CustomerPurchaseDetail.php'),'schema'=>$read('app/Core/Config.php'),'browser'=>$read('tests/manual/customer-purchase-detail-payment-design-system-browser-test.py'),'baseCss'=>$read('assets/frontend/css/customer-panel.css'),'baseAssets'=>$read('app/Modules/Frontend/Assets/FrontendAssets.php')];
$s['css']=rtrim(str_replace(["\r\n","\r"],"\n",$s['css']),"\n");$s['assets']=rtrim(str_replace(["\r\n","\r"],"\n",$s['assets']),"\n");
$s['baseCss']=$s['css'];$s['baseAssets']=$s['assets'];
p11assert(($base=p11validate($s))===[],'Validacion base: '.implode(',',$base));
$mutations=[
 ['js',"'veciahorra-frontend va-design-system va-customer-panel__detail-section va-customer-panel__detail-payment'","'va-customer-panel__detail-section va-customer-panel__detail-payment'"],
 ['js',"paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');","paymentSection.setAttribute('data-va-customer-panel-detail-payment', ''); paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');"],
 ['view','<main ','<div data-va-customer-panel-detail-payment></div><main '],
 ['js',"paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');","paymentValues.setAttribute('data-va-customer-panel-detail-payment', '');"],
 ['js',"var services = element('div', 'va-customer-panel__detail-services');","var services = element('div', 'va-customer-panel__detail-services data-va-customer-panel-detail-payment');"],
 ['js',"deliverySection.setAttribute('data-va-customer-panel-detail-delivery', '');","deliverySection.setAttribute('data-va-customer-panel-detail-delivery', ''); deliverySection.setAttribute('data-va-customer-panel-detail-payment', '');"],
 ['js',"var overview = element(","var overview = element('div','data-va-customer-panel-detail-payment'); var ignored = element("],
 ['js',"var ordersSection = element('section', 'va-customer-panel__detail-section va-customer-panel__detail-orders-section');","var ordersSection = element('section', 'va-customer-panel__detail-orders-section data-va-customer-panel-detail-payment');"],
 ['js','function renderDetailItem(item, currency, config) {',"function renderDetailItem(item, currency, config) { var leak='data-va-customer-panel-detail-payment';"],
 ['js','function renderTimeline(entries, config) {',"function renderTimeline(entries, config) { var leak='data-va-customer-panel-detail-payment';"],
 ['js','function renderList(root, purchases, config) {',"function renderList(root, purchases, config) { var leak='data-va-customer-panel-detail-payment';"],
 ['js','function renderDetailLoading(state) {',"function renderDetailLoading(state) { var leak='data-va-customer-panel-detail-payment';"],
 ['js','function renderDetailNotFound(state) {',"function renderDetailNotFound(state) { var leak='data-va-customer-panel-detail-payment';"],
 ['js','function renderDetailRecoverableError(state) {',"function renderDetailRecoverableError(state) { var leak='data-va-customer-panel-detail-payment';"],
 ['js','overview.append(header, summarySection)','overview.append(header, summarySection, paymentSection)'],
 ['js','function renderDetailItem(item, currency, config) {',"function renderDetailItem(item, currency, config) { var nested='data-va-customer-panel-detail-payment';"],
 ['js','function renderDetailOrder(order, currency, config) {',"function renderDetailOrder(order, currency, config) { var nested='data-va-customer-panel-detail-payment';"],
 ['js','data-va-customer-panel-detail-overview','data-va-phase8-broken'],
 ['js','data-va-customer-panel-detail-item','data-va-phase9-broken'],
 ['js','data-va-customer-panel-detail-order-header','data-va-phase10-broken'],
 ['js',"paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');","paymentSection.setAttribute('data-va-customer-panel-detail-payment', ''); paymentSection.setAttribute('data-va-customer-panel-detail-payment', '');"],
 ['js','if (detail.payment === null)','if (false)'],
 ['service',"'status' => \$payment['status'] === 'paid' ? 'received' : 'pending'","'status' => \$payment['status'] === 'failed' ? 'failed' : (\$payment['status'] === 'paid' ? 'received' : 'pending')"],
 ['service',"'label' => \$payment['status'] === 'paid' ? 'Pago recibido' : 'Pago pendiente'","'label' => \$payment['status'] === 'paid' ? 'Pagado' : 'Pendiente'"],
 ['js',"detailValue('Monto'","detailValue('Moneda cambiada'"],
 ['js','isNullableString(payment.paid_at)','isString(payment.paid_at)'],
 ['js',"currency === 'CLP'","currency === 'USD'"],
 ['js',"timeZone = String(config.timeZone || 'UTC')","timeZone = 'America/Santiago'"],
 ['service',"\$payment['provider'] === 'webpay_plus' ? 'Webpay Plus' : null","(string) \$payment['provider']"],
 ['js',"paymentSection.append(visualHeading('h3', 'Pago', 'payment'));","paymentSection.append(element('button','','Pagar'));"],
 ['js',"paymentSection.append(visualHeading('h3', 'Pago', 'payment'));","setInterval(function(){},1000); paymentSection.append(visualHeading('h3', 'Pago', 'payment'));"],
 ['js','customer-panel/purchases','customer-panel/payments'],
 ['js','PUBLIC_ID_PATTERN','PUBLIC_REFERENCE_BROKEN'],
 ['service','findOwnedCheckout($publicId, $userId)','findOwnedCheckout($publicId, 0)'],
 ['service',"(int) (\$payment['customer_id'] ?? 0) !== \$userId","(int) (\$payment['customer_id'] ?? 0) === \$userId"],
 ['routes','(string) ($request->get_url_params()',"(string) (\$request->get_query_params()['user_id'] ?? '') . (string) (\$request->get_url_params()"],
 ['dto',"'payment' => \$this->payment","'payment_id' => 1"],
 ['js',"visualHeading('h3', 'Pago', 'payment')","element('div','','Pago')"],
 ['css','.veciahorra-frontend.va-customer-panel {','.veciahorra-frontend.va-customer-panel { color:red;'],
 ['schema','SCHEMA_VERSION','SCHEMA_VERSION_REMOVED'],
];
$names=['ROOT_MISSING','ROOT_DUPLICATED','ROOT_GLOBAL','WRONG_NODE','ROOT_ON_SERVICES','DELIVERY_INVADED','OVERVIEW_INVADED','ORDERS_INVADED','PRODUCTS_INVADED','TIMELINE_INVADED','LIST_INVADED','LOADING_INVADED','NOT_FOUND_INVADED','ERROR_INVADED','NESTED_PHASE8','NESTED_PHASE9','NESTED_PHASE10','PHASE8_CHANGED','PHASE9_CHANGED','PHASE10_CHANGED','CARDINALITY_CHANGED','NULL_PAYMENT_LOST','PAYMENT_STATE_INVENTED','PAYMENT_LABEL_CHANGED','ROW_OR_ORDER_CHANGED','OPTIONAL_FIELD_REQUIRED','MONEY_FORMAT_CHANGED','DATE_TIMEZONE_CHANGED','INTERNAL_METHOD_EXPOSED','PAYMENT_ACTION_ADDED','POLLING_ADDED','ENDPOINT_OR_METHOD_CHANGED','PUBLIC_ID_LOST','OWNERSHIP_LOST','FOREIGN_CUSTOMER_EXPOSED','IDENTITY_OVERRIDE','DTO_OR_INTERNAL_ID_CHANGED','ACCESSIBILITY_LOST','ASSET_OR_CSS_CHANGED','ALLOWLIST_OR_BASELINE_DRIFT'];
foreach($mutations as $i=>[$key,$from,$to]){$m=$s;p11assert(str_contains($m[$key],$from),'Fixture P'.($i+1).' ausente.');$m[$key]=preg_replace('/'.preg_quote($from,'/').'/',addcslashes($to,'\\$'),$m[$key],1)??$m[$key];$code='P'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT).'_'.$names[$i];$got=p11validate($m,false);p11assert(in_array($code,$got,true),"Esperado {$code}; obtenido ".implode(',',$got));echo "PASS ADVERSARIAL expected={$code} obtained={$code}\n";}
echo "PASS frontend-customer-purchase-detail-payment-design-system-test adversarials=40\n";
