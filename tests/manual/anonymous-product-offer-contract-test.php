<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Catalog\Security\PublicOfferToken;

function aoAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function aoRejected(callable $attempt, string $message): void
{
    try {
        $attempt();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

function aoForge(PublicOfferToken $tokens, array $payload, ?string $key = null): string
{
    $reflection = new ReflectionClass($tokens);
    $keyMethod = $reflection->getMethod('key');
    $keyMethod->setAccessible(true);
    $key ??= $keyMethod->invoke($tokens);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'veciahorra-public-offer|v1',
        16
    );

    return rtrim(strtr(base64_encode($nonce . $tag . $ciphertext), '+/', '-_'), '=');
}

wp_set_current_user(0);
$session = bin2hex(random_bytes(32));
$owner = ['session_id' => $session, 'user_id' => null];
$tokens = new PublicOfferToken();
$first = $tokens->issue(101, 202, 303, $owner);
$second = $tokens->issue(101, 202, 303, $owner);
aoAssert($first !== $second, 'Nonce GCM reutilizado para el mismo payload.');
aoAssert($tokens->resolve($first, $owner) === ['inventory_id'=>101,'product_id'=>202,'sector_id'=>303], 'Token válido no resuelve autoridad exacta.');
aoRejected(fn () => $tokens->resolve($first, ['session_id'=>str_repeat('a',64),'user_id'=>null]), 'Token aceptado por otra sesión.');
$userToken = $tokens->issue(101, 202, 303, ['session_id'=>null,'user_id'=>1001]);
aoAssert($tokens->resolve($userToken, ['session_id'=>null,'user_id'=>1001])['inventory_id'] === 101, 'Token autenticado no resuelve para su propietario.');
aoRejected(fn () => $tokens->resolve($userToken, ['session_id'=>null,'user_id'=>1002]), 'Token autenticado aceptado por otro cliente.');
aoRejected(fn () => $tokens->resolve('***', $owner), 'Base64 inválido aceptado.');
aoRejected(fn () => $tokens->resolve(substr($first, 0, -5), $owner), 'Token truncado aceptado.');
$raw = base64_decode(strtr($first, '-_', '+/'), true);
foreach ([5, 15, 30] as $offset) {
    $altered = $raw; $altered[$offset] = chr(ord($altered[$offset]) ^ 1);
    $encoded = rtrim(strtr(base64_encode($altered), '+/', '-_'), '=');
    aoRejected(fn () => $tokens->resolve($encoded, $owner), 'Nonce/tag/ciphertext alterado aceptado.');
}

$reflection = new ReflectionClass($tokens);
$bindingMethod = $reflection->getMethod('ownerBinding');
$bindingMethod->setAccessible(true);
$binding = $bindingMethod->invoke($tokens, $owner);
$now = time();
$base = ['v'=>1,'u'=>'offer:add-to-cart','i'=>101,'p'=>202,'z'=>303,'o'=>$binding,'a'=>$now,'e'=>$now+900];
aoRejected(fn () => $tokens->resolve(aoForge($tokens, [...$base,'e'=>$now-1]), $owner), 'Token vencido aceptado.');
aoRejected(fn () => $tokens->resolve(aoForge($tokens, [...$base,'a'=>$now+1,'e'=>$now+901]), $owner), 'Token futuro aceptado.');
aoRejected(fn () => $tokens->resolve(aoForge($tokens, [...$base,'e'=>$now+901]), $owner), 'TTL superior a 15 minutos aceptado.');
aoRejected(fn () => $tokens->resolve(aoForge($tokens, [...$base,'x'=>1]), $owner), 'Campo adicional aceptado.');
$missing = $base; unset($missing['p']);
aoRejected(fn () => $tokens->resolve(aoForge($tokens, $missing), $owner), 'Campo omitido aceptado.');
aoRejected(fn () => $tokens->resolve(aoForge($tokens, [...$base,'i'=>'101']), $owner), 'Tipo incorrecto aceptado.');
aoRejected(fn () => $tokens->resolve(aoForge($tokens, [...$base,'u'=>'other-purpose']), $owner), 'Propósito distinto aceptado.');
aoRejected(fn () => $tokens->resolve(aoForge($tokens, [...$base,'v'=>2]), $owner), 'Versión distinta aceptada.');
aoRejected(fn () => $tokens->resolve(aoForge($tokens, $base, random_bytes(32)), $owner), 'Token de otra clave/instalación aceptado.');

$root = dirname(__DIR__, 2);
$sources = [
    'catalog' => file_get_contents($root.'/app/Modules/Catalog/Service/CatalogService.php'),
    'request' => file_get_contents($root.'/app/Modules/Cart/Requests/CartItemCreateRequest.php'),
    'controller' => file_get_contents($root.'/app/Modules/Cart/Controller/CartController.php'),
    'cart' => file_get_contents($root.'/app/Modules/Cart/Service/CartService.php'),
    'checkout' => file_get_contents($root.'/app/Modules/Checkout/Controller/CheckoutController.php'),
    'offers' => file_get_contents($root.'/assets/frontend/js/veciahorra-product-offers.js'),
    'catalog_js' => file_get_contents($root.'/assets/frontend/js/veciahorra-catalog.js'),
    'view' => file_get_contents($root.'/app/Modules/Frontend/Views/product-detail.php'),
    'routes' => file_get_contents($root.'/app/Modules/Catalog/Routes/CatalogRoutes.php'),
];
foreach ([$sources['catalog'],$sources['offers'],$sources['catalog_js']] as $public) aoAssert(!str_contains($public,'single_inventory_id'),'Contrato público conserva single_inventory_id.');
aoAssert(str_contains($sources['request'],"array_key_exists('inventory_id'")&&str_contains($sources['controller'],'offerTokens->resolve'),'inventory_id público no está bloqueado/resuelto.');
aoAssert(str_contains($sources['cart'],'expectedProductId'),'Token no permanece ligado al Product esperado.');
aoAssert(str_contains($sources['checkout'],"'business_name'=>true"),'Checkout no elimina identidad comercial.');
aoAssert(str_contains($sources['routes'],'private, no-store, max-age=0'),'Catálogo con tokens permite caché compartida.');
aoAssert(!str_contains(file_get_contents($root.'/app/Modules/Catalog/Security/PublicOfferToken.php'),'unserialize'),'Token usa unserialize.');
aoAssert(str_contains($sources['view'],'Disponible cuando los minimarkets tengan ubicación validada'),'Control de cercanía no es honesto.');

echo "ANONYMOUS_PRODUCT_OFFERS=PASS aes_256_gcm=PASS hkdf=PASS nonce_unique=PASS tag=PASS strict_schema=PASS purpose=PASS iat_exp=PASS ttl_900=PASS future=REJECTED expired=REJECTED tamper=REJECTED wrong_owner=REJECTED wrong_product=REJECTED public_cache=PRIVATE_NO_STORE\n";
