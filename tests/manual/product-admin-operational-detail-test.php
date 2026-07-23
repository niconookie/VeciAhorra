<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Products\Admin\ProductsPage;
use VeciAhorra\Modules\Products\Controllers\ProductController;
use VeciAhorra\Modules\Products\Services\ProductService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function detailAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function detailRequest(int $id): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request(
        'GET',
        "/veciahorra/v1/products/{$id}/admin-detail"
    ));
}

function detailListRequest(int $id): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/veciahorra/v1/products');
    $request->set_query_params(['term' => (string) $id]);

    return rest_do_request($request);
}

global $wpdb;
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ids']);
detailAssert($admins!==[],'Se requiere administrador.');
wp_set_current_user((int)$admins[0]);
$prefix=$wpdb->prefix.Config::TABLE_PREFIX;
detailAssert($wpdb->query('START TRANSACTION')!==false,'Sin transaccion.');

try {
    $suffix=strtolower(str_replace('.','',uniqid('detail',true)));
    $now=current_time('mysql');
    $terms=[];
    foreach (['product_cat','product_brand','pa_unidad'] as $taxonomy) {
        $term=wp_insert_term("Term {$taxonomy} {$suffix}",$taxonomy);
        detailAssert(!is_wp_error($term),'No se creo termino.');
        $terms[]=(int)$term['term_id'];
    }
    $products=$prefix.'products';
    detailAssert($wpdb->insert($products,[
        'name'=>"Detalle {$suffix}",
        'slug'=>"detalle-{$suffix}",
        'sku'=>"SKU-{$suffix}",
        'description'=>'Descripcion administrativa segura',
        'category_id'=>$terms[0],
        'brand_id'=>$terms[1],
        'unit_id'=>$terms[2],
        'status'=>'active',
        'created_at'=>$now,
        'updated_at'=>$now,
    ])===1,'No se creo Product.');
    $productId=(int)$wpdb->insert_id;
    $stores=$prefix.'stores';
    $storeIds=[];
    foreach (['active','inactive'] as $index=>$status) {
        detailAssert($wpdb->insert($stores,[
            'business_name'=>"Store {$index} {$suffix}",
            'legal_name'=>'Legal','owner_name'=>'Owner',
            'rut'=>"9{$index}.999.999-9",
            'email'=>"detail{$index}{$suffix}@example.test",
            'phone'=>'+5620000000','status'=>$status,
            'onboarding_status'=>'complete',
            'created_at'=>$now,'updated_at'=>$now,
        ])===1,'No se creo Store.');
        $storeIds[]=(int)$wpdb->insert_id;
    }
    $inventory=$prefix.'inventory';
    foreach ([
        [$storeIds[0],1500,4,'active'],
        [$storeIds[1],900,8,'active'],
        [999999999,800,2,'inactive'],
        [999999998,700,2,'unexpected'],
    ] as [$storeId,$price,$stock,$status]) {
        detailAssert($wpdb->insert($inventory,[
            'product_id'=>$productId,'minimarket_id'=>$storeId,
            'price'=>$price,'stock'=>$stock,'status'=>$status,
            'created_at'=>$now,'updated_at'=>$now,
        ])===1,'No se creo oferta.');
    }

    $response=detailRequest($productId);
    $body=$response->get_data();
    $data=$body['data']??[];
    detailAssert($response->get_status()===200,'Detalle no retorno 200.');
    detailAssert(($response->get_headers()['Cache-Control']??null)==='private, no-store','Header privado ausente.');
    detailAssert($data['id']===$productId,'ID inestable.');
    detailAssert($data['description']==='Descripcion administrativa segura','Descripcion ausente.');
    detailAssert($data['image']['status']==='absent','Imagen ausente incorrecta.');
    detailAssert($data['taxonomies']['category']['status']==='valid','Categoria invalida.');
    detailAssert($data['taxonomies']['brand']['slug']!==null,'Slug de marca ausente.');
    detailAssert($data['inventory']['total']===4,'Total ofertas incorrecto.');
    detailAssert($data['inventory']['active']===2,'Activas incorrectas.');
    detailAssert($data['inventory']['inactive']===1,'Inactivas incorrectas.');
    detailAssert($data['inventory']['unknown']===1,'Desconocidas incorrectas.');
    detailAssert($data['inventory']['publicly_available']===1,'Disponibles incorrectas.');
    detailAssert($data['inventory']['minimum_public_price']===1500.0,'Precio minimo publico incorrecto.');
    detailAssert($data['inventory']['public_stock']===4,'Stock publico incorrecto.');
    detailAssert($data['publicly_available']===true,'Product publico incorrecto.');
    detailAssert(count($data['inventory']['offers'])===4,'Ofertas resumidas ausentes.');
    detailAssert($data['inventory']['offers'][1]['availability_reason']==='store_inactive','Store inactive no explicada.');
    detailAssert($data['inventory']['offers'][2]['availability_reason']==='inventory_inactive','Precedencia Inventory incorrecta.');
    detailAssert($data['inventory']['offers'][3]['availability_reason']==='inventory_unknown','Estado desconocido no fue explicado.');
    detailAssert($data['references']['classification']==='inconsistent','Inspector no fue reutilizado.');
    detailAssert($data['lifecycle']['allowed_statuses']===['inactive'],'Lifecycle incorrecto.');
    detailAssert($data['lifecycle']['expected_updated_at']===$now,'Version CAS incorrecta.');
    detailAssert(!isset($data['buyers'],$data['orders'],$data['sql']),'Se expusieron datos sensibles.');
    $listed = detailListRequest($productId)->get_data()['data'][0] ?? [];
    detailAssert(
        ($listed['inventory']['total'] ?? null) === $data['inventory']['total'],
        'Listado y detalle difieren en total.'
    );
    detailAssert(
        ($listed['inventory']['active'] ?? null) === $data['inventory']['active'],
        'Listado y detalle difieren en activas.'
    );
    detailAssert(
        ($listed['inventory']['inactive'] ?? null) === $data['inventory']['inactive'],
        'Listado y detalle difieren en inactivas.'
    );
    detailAssert(
        ($listed['publicly_available'] ?? null) === $data['publicly_available'],
        'Listado y detalle difieren en publicacion.'
    );
    $controller = new ProductController(new ProductService());
    $imageMethod = new ReflectionMethod($controller, 'detailImage');
    $missingImage = $imageMethod->invoke($controller, 999999997);
    detailAssert($missingImage['status']==='missing_attachment','Attachment inexistente incorrecto.');
    $nonImageId = wp_insert_attachment([
        'post_title'=>"Documento {$suffix}",
        'post_status'=>'inherit',
        'post_mime_type'=>'application/pdf',
        'guid'=>"https://example.test/{$suffix}.pdf",
    ]);
    detailAssert(is_int($nonImageId)&&$nonImageId>0,'No se creo attachment no imagen.');
    $unavailableImage = $imageMethod->invoke($controller, $nonImageId);
    detailAssert($unavailableImage['status']==='unavailable','Attachment no imagen no fue diferenciado.');

    $page = new ProductsPage();
    $returnMethod = new ReflectionMethod($page, 'listReturnUrl');
    $originalGet = $_GET;
    $_GET = [
        'term'=>'leche',
        'status'=>'active',
        'category_id'=>'7',
        'brand_id'=>'8',
        'paged'=>'2',
        'order_by'=>'name',
        'direction'=>'desc',
        'action'=>'delete',
        'product_id'=>'999',
        'redirect'=>'https://evil.test/',
        '_wpnonce'=>'secret',
    ];
    $returnUrl = $returnMethod->invoke(
        $page,
        'https://example.test/wp-admin/admin.php?page=veciahorra-products'
    );
    $_GET = $originalGet;
    detailAssert(str_starts_with($returnUrl,'https://example.test/wp-admin/'),'Retorno cambio host o ruta.');
    detailAssert(!str_contains($returnUrl,'evil.test'),'Retorno acepto open redirect.');
    detailAssert(!str_contains($returnUrl,'action=')&&!str_contains($returnUrl,'product_id='),'Retorno conservo ruta de detalle.');
    detailAssert(!str_contains($returnUrl,'nonce'),'Retorno expuso nonce.');
    detailAssert(str_contains($returnUrl,'status=active')&&str_contains($returnUrl,'paged=2'),'Retorno perdio allowlist valida.');

    detailAssert(detailRequest(999999998)->get_status()===404,'Product inexistente no retorno 404.');
    wp_set_current_user(0);
    detailAssert(detailRequest($productId)->get_status()===401,'Anonimo no rechazado.');
    $subscribers=get_users(['role'=>'subscriber','number'=>1,'fields'=>'ids']);
    if($subscribers!==[]){
        wp_set_current_user((int)$subscribers[0]);
        detailAssert(detailRequest($productId)->get_status()===403,'Usuario sin capacidad no rechazado.');
    }

    echo "PASS product-admin-operational-detail-test 43 assertions\n";
} finally {
    wp_set_current_user((int)$admins[0]);
    $wpdb->query('ROLLBACK');
}
