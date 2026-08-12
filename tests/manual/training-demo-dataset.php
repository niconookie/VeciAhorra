<?php
declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Installer;
use VeciAhorra\Modules\Couriers\Identity\CourierRole;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;
use VeciAhorra\Modules\ServiceProviders\Identity\ServiceProviderRole;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;
Installer::install();
(new MinimarketRole())->register();
CourierRole::register();
ServiceProviderRole::register();
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$nowLocal = current_time('mysql');
$nowUtc = current_time('mysql', true);
$createdCredentials = [];

function demoRut(int $number): string
{
    $sum = 0; $factor = 2; $value = $number;
    while ($value > 0) { $sum += ($value % 10) * $factor; $factor = $factor === 7 ? 2 : $factor + 1; $value = intdiv($value, 10); }
    $dv = 11 - ($sum % 11); $digit = $dv === 11 ? '0' : ($dv === 10 ? 'K' : (string) $dv);
    return number_format($number, 0, '', '.') . '-' . $digit;
}

function demoUser(string $username, string $name, string $email, string $password, string $role, array &$credentials): int
{
    $existing = get_user_by('login', $username);
    if ($existing instanceof WP_User) return (int) $existing->ID;
    $id = wp_create_user($username, $password, $email);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    wp_update_user(['ID'=>$id, 'display_name'=>$name, 'first_name'=>strtok($name, ' '), 'last_name'=>trim(substr($name, strlen((string) strtok($name, ' '))))]);
    (new WP_User($id))->set_role($role);
    $credentials[$username] = $password;
    return (int) $id;
}

function demoFindId(string $table, string $field, string $value): int
{
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE {$field}=%s ORDER BY id ASC LIMIT 1", $value));
}

function demoUpdateIfChanged(string $table, int $id, array $data, array $preserveTimestamps = []): void
{
    global $wpdb;
    $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if (!is_array($current)) throw new RuntimeException("Registro ausente: {$table}#{$id}");
    foreach (array_unique(['created_at', ...$preserveTimestamps]) as $field) {
        if (($current[$field] ?? null) !== null && ($current[$field] ?? '') !== '') unset($data[$field]);
    }
    $updatedAt = $data['updated_at'] ?? null; unset($data['updated_at']); $changes = [];
    foreach ($data as $field=>$value) if (($current[$field] === null ? null : (string)$current[$field]) !== ($value === null ? null : (string)$value)) $changes[$field]=$value;
    $materiallyChanged = $changes !== [];
    if (isset($current['created_at'],$current['updated_at']) && (string)$current['created_at'] > (string)$current['updated_at']) $changes['created_at']=$current['updated_at'];
    if ($changes === []) return;
    if ($updatedAt !== null && $materiallyChanged) $changes['updated_at']=$updatedAt;
    if ($wpdb->update($table,$changes,['id'=>$id])===false) throw new RuntimeException("Update falló: {$table}");
}

function demoUpsertNatural(string $table, string $field, string $value, array $data, array $preserveTimestamps = []): int
{
    global $wpdb;
    $id = demoFindId($table, $field, $value);
    if ($id > 0) { demoUpdateIfChanged($table,$id,$data,$preserveTimestamps); return $id; }
    if ($wpdb->insert($table, [$field=>$value, ...$data]) !== 1) throw new RuntimeException("Insert falló: {$table}");
    return (int) $wpdb->insert_id;
}

function demoTrainingImage(string $productKey, string $productName, array $manifest): int
{
    $entry = $manifest[$productKey] ?? null;
    if (! is_array($entry)) throw new RuntimeException("Manifest de imagen ausente: {$productKey}");
    $assetKey = (string) ($entry['asset_key'] ?? '');
    $relativePath = (string) ($entry['relative_path'] ?? '');
    $expectedMime = (string) ($entry['mime_type'] ?? '');
    $expectedHash = (string) ($entry['sha256'] ?? '');
    $fixtureRoot = dirname(__DIR__) . '/fixtures/training-demo';
    $source = realpath($fixtureRoot . '/' . $relativePath);
    $root = realpath($fixtureRoot);
    if ($source === false || $root === false || ! str_starts_with($source, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException("Asset training ausente o fuera del fixture: {$productKey}");
    }
    $actualHash = hash_file('sha256', $source);
    $actualMime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
    if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) throw new RuntimeException("SHA-256 inválido: {$productKey}");
    if (! is_string($actualMime) || $actualMime !== $expectedMime || ! str_starts_with($actualMime, 'image/')) throw new RuntimeException("MIME inválido: {$productKey}");

    $existing = get_posts(['post_type'=>'attachment','post_status'=>'inherit','numberposts'=>2,'meta_key'=>'_veciahorra_demo_asset','meta_value'=>$assetKey]);
    if (count($existing) > 1) throw new RuntimeException("Asset key duplicada: {$assetKey}");
    if (isset($existing[0]) && $existing[0] instanceof WP_Post) {
        $attachmentId = (int) $existing[0]->ID;
        $attachedFile = get_attached_file($attachmentId);
        if (! is_string($attachedFile) || ! is_file($attachedFile)) throw new RuntimeException("Attachment sin fichero: {$assetKey}");
        $storedHash = hash_file('sha256', $attachedFile);
        if (! is_string($storedHash) || ! hash_equals($expectedHash, $storedHash)) throw new RuntimeException("Asset key con SHA-256 diferente: {$assetKey}");
        if ((string) get_post_mime_type($attachmentId) !== $expectedMime) throw new RuntimeException("Asset key con MIME diferente: {$assetKey}");
        return $attachmentId;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $temporary = wp_tempnam(basename($source));
    if (! is_string($temporary) || ! copy($source, $temporary)) throw new RuntimeException("No fue posible preparar la imagen: {$productKey}");
    $attachmentId = media_handle_sideload(['name'=>basename($source),'tmp_name'=>$temporary], 0, $productName);
    if (is_wp_error($attachmentId)) { @unlink($temporary); throw new RuntimeException($attachmentId->get_error_message()); }
    update_post_meta((int) $attachmentId, '_veciahorra_demo_asset', $assetKey);
    update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', $productName);
    return (int) $attachmentId;
}

function demoTrainingCategory(string $key, array $manifest): int
{
    $entry = $manifest[$key] ?? null;
    if (! is_array($entry)) throw new RuntimeException("Manifest de categoría ausente: {$key}");
    $taxonomy = (string) ($entry['taxonomy'] ?? '');
    $slug = (string) ($entry['slug'] ?? '');
    $name = (string) ($entry['name'] ?? '');
    if ($taxonomy === '' || $slug === '' || $name === '' || ! taxonomy_exists($taxonomy)) {
        throw new RuntimeException("Autoridad de categoría inválida: {$key}");
    }
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term) {
        if ($existing->name !== $name || (int) $existing->parent !== 0 || (string) $existing->description !== '') {
            throw new RuntimeException("Categoría incompatible: {$taxonomy}:{$slug}");
        }
        return (int) $existing->term_id;
    }
    $created = wp_insert_term($name, $taxonomy, ['slug'=>$slug]);
    if (is_wp_error($created)) throw new RuntimeException($created->get_error_message());
    return (int) $created['term_id'];
}

$users = [];
$users['client'] = demoUser('va_demo_carolina', 'Carolina Soto', 'carolina.soto.demo@veciahorra.test', 'VA-Cliente-2026!', 'customer', $createdCredentials);
foreach ([
    'los_vecinos'=>['va_demo_minimarket_vecinos','Minimarket Los Vecinos','VA-Vecinos-2026!'],
    'central'=>['va_demo_minimarket_central','Minimarket Central','VA-Central-2026!'],
    'plaza_sur'=>['va_demo_minimarket_plaza','Minimarket Plaza Sur','VA-Plaza-2026!'],
] as $key=>$u) $users[$key] = demoUser($u[0], $u[1], $u[0].'@veciahorra.test', $u[2], MinimarketRole::ROLE, $createdCredentials);
$users['courier'] = demoUser('va_demo_diego', 'Diego Morales', 'diego.morales.demo@veciahorra.test', 'VA-Diego-2026!', CourierRole::ROLE, $createdCredentials);

$providerSpecs = [
 'jose'=>['va_demo_jose','José Martínez','featured','veciarregla','gasfiteria','San Miguel',8,'Gasfitería Martínez'],
 'carolina'=>['va_demo_carolina_rojas','Carolina Rojas','local','vecilimpia','aseo-domiciliario','La Cisterna',6,'Limpieza Carolina'],
 'luis'=>['va_demo_luis','Luis González','featured','veciarregla','electricidad','San Joaquín',10,'Electricidad González'],
 'daniela'=>['va_demo_daniela','Daniela Pérez','local','vecibelleza','peluqueria','San Miguel',7,'Belleza Daniela'],
 'pedro'=>['va_demo_pedro','Pedro Rojas','local','vecimueve','mudanzas','Santiago',9,'Mudanzas Pedro'],
 'camila'=>['va_demo_camila','Camila Silva','featured','veciaprende','clases-particulares','San Miguel',5,'Clases Camila'],
];
foreach ($providerSpecs as $key=>$s) $users['provider_'.$key] = demoUser($s[0], $s[1], $s[0].'@veciahorra.test', 'VA-'.ucfirst($key).'-2026!', ServiceProviderRole::ROLE, $createdCredentials);

$stores = [];
$storeSpecs = [
 'los_vecinos'=>['Minimarket Los Vecinos','Av. Vecinal 145','San Miguel','+56225551001',76000001],
 'central'=>['Minimarket Central','Gran Avenida 3280','La Cisterna','+56225551002',76000002],
 'plaza_sur'=>['Minimarket Plaza Sur','Av. Departamental 980','San Joaquín','+56225551003',76000003],
];
foreach ($storeSpecs as $key=>$s) {
 $stores[$key] = demoUpsertNatural($prefix.'stores','business_name',$s[0],['legal_name'=>$s[0].' SpA','owner_name'=>'Encargado Demo','rut'=>demoRut($s[4]),'email'=>str_replace('_','.',"{$key}@veciahorra.test"),'phone'=>$s[3],'mobile'=>null,'address'=>$s[1],'commune'=>$s[2],'city'=>'Santiago','region'=>'Metropolitana','status'=>'active','onboarding_status'=>'complete','approved_at'=>$nowLocal,'created_at'=>$nowLocal,'updated_at'=>$nowLocal],['approved_at']);
 update_user_meta($users[$key], MinimarketRole::STORE_META_KEY, $stores[$key]); (new WP_User($users[$key]))->set_role(MinimarketRole::ROLE);
}

$courierId = demoUpsertNatural($prefix.'couriers','display_name','Diego Morales',['phone'=>'+56955551001','email'=>'diego.morales.demo@veciahorra.test','status'=>'approved','approved_at'=>$nowUtc,'created_at'=>$nowUtc,'updated_at'=>$nowUtc],['approved_at']);
update_user_meta($users['courier'], CourierRole::META_KEY, $courierId); (new WP_User($users['courier']))->set_role(CourierRole::ROLE);

$providerIds = []; $providerIndex = 10000100;
foreach ($providerSpecs as $key=>$s) {
 $uid=$users['provider_'.$key]; $meta=(int)get_user_meta($uid,ServiceProviderRole::META_KEY,true);
 $existing=$meta>0?$meta:demoFindId($prefix.'service_providers','email',$s[0].'@veciahorra.test');
 $data=['full_name'=>$s[1],'rut'=>demoRut($providerIndex++),'email'=>$s[0].'@veciahorra.test','phone'=>'+569'.str_pad((string)$providerIndex,8,'0',STR_PAD_LEFT),'plan'=>$s[2],'status'=>'published','terms_accepted'=>1,'photo_id'=>null,'business_name'=>$s[7],'category_key'=>$s[3],'subcategory_key'=>$s[4],'description'=>'Atención profesional, responsable y cercana para vecinos de '.$s[5].'.','commune'=>$s[5],'coverage'=>wp_json_encode([$s[5],'Comunidades cercanas']),'specialties'=>wp_json_encode(['Atención domiciliaria','Servicio coordinado']),'experience_years'=>$s[6],'schedule'=>'Lunes a sábado, 09:00 a 19:00','emergency_service'=>$key==='jose'||$key==='luis'?1:0,'whatsapp'=>'+569'.str_pad((string)$providerIndex,8,'0',STR_PAD_LEFT),'contact_email'=>'contacto.'.$s[0].'@veciahorra.test','admin_observation'=>null,'submitted_at'=>$nowUtc,'approved_at'=>$nowUtc,'published_at'=>$nowUtc,'created_at'=>$nowUtc,'updated_at'=>$nowUtc];
 if($existing>0){demoUpdateIfChanged($prefix.'service_providers',$existing,$data,['submitted_at','approved_at','published_at']);$providerIds[$key]=$existing;}else{if($wpdb->insert($prefix.'service_providers',$data)!==1)throw new RuntimeException('Insert provider falló.');$providerIds[$key]=(int)$wpdb->insert_id;}
 update_user_meta($uid,ServiceProviderRole::META_KEY,$providerIds[$key]);(new WP_User($uid))->set_role(ServiceProviderRole::ROLE);
}

$categoryManifest = require dirname(__DIR__) . '/fixtures/training-demo/category-manifest.php';
$categories=[];foreach(array_keys($categoryManifest) as $categoryKey)$categories[$categoryKey]=demoTrainingCategory($categoryKey,$categoryManifest);
$productsSpec=[
 ['Coca-Cola Original 1,5 L','coca-cola-original-15-l','DEMO-COCA-ORIGINAL','vecised',2190],['Coca-Cola Zero 1,5 L','coca-cola-zero-15-l','DEMO-COCA-ZERO','vecised',2150],['Cachantun sin gas 1,5 L','cachantun-sin-gas-15-l','DEMO-CACHANTUN','vecised',1200],
 ['Leche Colun Entera 1 L','leche-colun-entera-1-l','DEMO-LECHE','vecifrio',1250],['Yogurt Soprole','yogurt-soprole','DEMO-YOGURT','vecifrio',650],['Mantequilla Colun','mantequilla-colun','DEMO-MANTEQUILLA','vecifrio',2450],
 ['Arroz Tucapel 1 kg','arroz-tucapel-1-kg','DEMO-ARROZ','despensa',1750],['Tallarines Carozzi 400 g','tallarines-carozzi-400-g','DEMO-TALLARINES','despensa',1050],['Salsa de tomates Carozzi','salsa-tomates-carozzi','DEMO-SALSA','despensa',750],['Aceite Chef 1 L','aceite-chef-1-l','DEMO-ACEITE','despensa',2650],
 ['Super 8','super-8','DEMO-SUPER8','antojo',500],['Galletas Tritón','galletas-triton','DEMO-TRITON','antojo',950],['Papas fritas Marco Polo','papas-fritas-marco-polo','DEMO-PAPAS','antojo',1350],
 ['Lavalozas Quix','lavalozas-quix','DEMO-QUIX','limpieza',1850],['Detergente Omo','detergente-omo','DEMO-OMO','limpieza',4990],['Papel higiénico Elite','papel-higienico-elite','DEMO-PAPEL','limpieza',3990],
 ['Queso laminado','queso-laminado','DEMO-QUESO','picoteo',2890],['Jamón pierna','jamon-pierna','DEMO-JAMON','picoteo',2790],['Pan de molde Ideal','pan-de-molde-ideal','DEMO-PAN','horno',2390],['Plátano','platano','DEMO-PLATANO','feria',1290],
];
$products=[];$missingImages=[];
$imageManifest = require dirname(__DIR__) . '/fixtures/training-demo/image-manifest.php';
foreach($productsSpec as $spec){[$name,$slug,$sku,$category,$base]=$spec;$image=demoTrainingImage($slug,$name,$imageManifest);$id=demoFindId($prefix.'products','slug',$slug);$data=['woo_product_id'=>null,'name'=>$name,'sku'=>$sku,'description'=>'Producto demo para capacitación VeciAhorra.','category_id'=>$categories[$category],'brand_id'=>null,'unit_id'=>null,'image_id'=>$image,'status'=>'active','updated_at'=>$nowLocal];if($id>0){demoUpdateIfChanged($prefix.'products',$id,$data);}else{if($wpdb->insert($prefix.'products',['slug'=>$slug,...$data,'created_at'=>$nowLocal])!==1)throw new RuntimeException('Insert product falló.');$id=(int)$wpdb->insert_id;}$products[$slug]=['id'=>$id,'name'=>$name,'base'=>$base,'image_id'=>$image];}

$inventory=[];$index=0;
foreach($products as $slug=>$product){foreach($stores as $storeKey=>$storeId){if($storeKey==='central'&&$index>=12)continue;if($storeKey==='plaza_sur'&&$index>=10)continue;$price=$product['base']+($storeKey==='central'?-50:($storeKey==='plaza_sur'?100:0));$stock=10+($index%9);if($slug==='coca-cola-original-15-l'){[$price,$stock]=match($storeKey){'los_vecinos'=>[2190,12],'central'=>[2050,5],default=>[2290,18]};}$existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}inventory WHERE product_id=%d AND minimarket_id=%d LIMIT 1",$product['id'],$storeId));$data=['price'=>number_format($price,2,'.',''),'stock'=>$stock,'status'=>'active','updated_at'=>$nowLocal];if($existing>0){demoUpdateIfChanged($prefix.'inventory',$existing,$data);$iid=$existing;}else{if($wpdb->insert($prefix.'inventory',['product_id'=>$product['id'],'minimarket_id'=>$storeId,...$data,'created_at'=>$nowLocal])!==1)throw new RuntimeException('Insert inventory falló.');$iid=(int)$wpdb->insert_id;}$inventory[$storeKey][$slug]=['id'=>$iid,'product_id'=>$product['id'],'price'=>$price,'stock'=>$stock];}$index++;}

$official=['coca-cola-original-15-l','tallarines-carozzi-400-g','salsa-tomates-carozzi','super-8'];
foreach($official as $slug){$row=$inventory['los_vecinos'][$slug]??null;if($row===null||$row['stock']<4)throw new RuntimeException("Producto oficial no disponible: {$slug}");}

echo wp_json_encode(['database'=>DB_NAME,'users'=>$users,'new_credentials'=>$createdCredentials,'stores'=>$stores,'courier_id'=>$courierId,'providers'=>$providerIds,'products'=>$products,'inventory'=>$inventory,'missing_product_images'=>$missingImages,'missing_provider_images'=>array_column($providerSpecs,1),'official_cart'=>$official],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
