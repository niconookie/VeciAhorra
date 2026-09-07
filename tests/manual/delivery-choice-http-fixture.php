<?php
declare(strict_types=1);
// Included only by a disposable localhost probe. Identity is explicitly simulated.
if(!isset($proofTestConfig)||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
[$wpRoot,$plugin,$run,$database,$secret,$dbUser,$dbPassword]=$proofTestConfig;
if(!preg_match('/^va_proof_test_[a-f0-9]{16}$/D',$database)){http_response_code(404);exit;}
define('ABSPATH',rtrim($wpRoot,'/\\').'/');define('WPINC','wp-includes');define('WP_CONTENT_DIR',$run);
define('WP_DEBUG',false);define('WP_DEBUG_DISPLAY',false);define('WP_MEMORY_LIMIT','128M');define('WP_MAX_MEMORY_LIMIT','256M');
define('DAY_IN_SECONDS',86400);define('KB_IN_BYTES',1024);define('MB_IN_BYTES',1048576);define('GB_IN_BYTES',1073741824);
define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');
function wp_cache_get(...$args){return false;}function wp_cache_set(...$args){return true;}
function is_user_logged_in():bool{return true;}
function get_current_user_id():int{return 2001;}
function current_user_can($cap):bool{return $cap==='veciahorra_manage_deliveries';}
function get_user_meta($id,$key,$single=false){return $key==='_veciahorra_courier_id'?1001:'';}
function wp_salt($scheme='auth'):string{return hash_hmac('sha256',$scheme,$GLOBALS['secret']);}
function content_url($path=''):string{return 'http://127.0.0.1/'.str_replace('C:/xampp/htdocs/','',str_replace('\\','/',WP_CONTENT_DIR)).$path;}
function wp_remote_get($url,$args=[]){
    if(!str_starts_with($url,'http://127.0.0.1/'))throw new RuntimeException('nonlocal_http_forbidden');
    $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_TIMEOUT=>10]);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return ['body'=>$body===false?'':$body,'response'=>['code'=>$status]];
}
function wp_remote_retrieve_response_code($r):int{return (int)$r['response']['code'];}
function wp_remote_retrieve_body($r):string{return $r['body'];}
foreach(['compat.php','plugin.php','load.php','class-wp-error.php','functions.php','formatting.php','shortcodes.php','media.php','class-wpdb.php','class-wp-http-response.php','rest-api/class-wp-rest-response.php','rest-api/class-wp-rest-request.php','rest-api.php'] as $f)require ABSPATH.WPINC.'/'.$f;
spl_autoload_register(static function($class)use($plugin){if(str_starts_with($class,'VeciAhorra\\')){$path=$plugin.'/app/'.str_replace('\\','/',substr($class,11)).'.php';if(is_file($path))require $path;}});
$wpdb=new wpdb($dbUser,$dbPassword,$database,'127.0.0.1:3306');$wpdb->set_prefix('t_');$wpdb->suppress_errors(true);
$routes=new \VeciAhorra\Modules\Couriers\Routes\CourierRoutes();
if($routes->permission()!==true){http_response_code(403);exit;}
$request=new WP_REST_Request('POST','/veciahorra/v1/courier/deliveries/'.(int)($_GET['id']??0).'/delivered');
$request->set_url_params(['id'=>(int)($_GET['id']??0)]);
$request->set_query_params($_GET);$request->set_body_params($_POST);$request->set_file_params($_FILES);
$response=$routes->delivered($request);http_response_code($response->get_status());header('Content-Type: application/json');
echo json_encode($response->get_data());
